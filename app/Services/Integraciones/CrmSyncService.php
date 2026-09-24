<?php

namespace App\Services\Integraciones;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Product;
use App\Support\Cuit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Sincronización de clientes y artículos con el CRM (etapa 2).
//   ERP → CRM: contacts/sync y products/sync (idempotentes por external_id = id del ERP), en cola.
//   CRM → ERP: webhook contact.created / contact.updated → alta o actualización por crm_external_id, sin pisar lo fiscal.
class CrmSyncService
{
    public const LOTE = 500;
    // Mientras se procesa un webhook del CRM no se vuelve a mandar lo mismo al CRM (evita el ping-pong).
    public static bool $silencio = false;

    // ── ERP → CRM ────────────────────────────────────────────────────────────
    public static function encolar(?int $businessId, string $tipo, array $ids): void
    {
        if (self::$silencio || ! $businessId || ! $ids) return;
        $b = Business::withoutGlobalScopes()->find($businessId);
        if (! $b || ! CrmService::activo($b) || CrmService::config($b)['api_key'] === '') return;
        // Un mismo registro tocado varias veces en un minuto se manda una sola vez.
        $ids = array_values(array_filter($ids, fn($id) => Cache::add("crm_sync_{$tipo}_{$businessId}_{$id}", 1, now()->addSeconds(30))));
        if ($ids) \App\Jobs\SincronizarCrmJob::dispatch($businessId, $tipo, $ids);
    }

    public static function contactoItem(Contact $c): array
    {
        return array_filter([
            'external_id' => (string) $c->id, 'name' => $c->name, 'email' => $c->email, 'phone' => $c->mobile ?: $c->phone, 'notes' => $c->notes, 'source' => 'erp',
            'lifecycle_stage' => in_array($c->type, ['customer', 'both'], true) ? 'customer' : null, 'tags' => [in_array($c->type, ['supplier'], true) ? 'Proveedor' : 'Cliente ERP'],
            // Lo fiscal lo administra el ERP: el CRM lo recibe y lo muestra.
            'cuit' => Cuit::formatear($c->cuit), 'condicion_iva' => $c->condicion_iva, 'domicilio_fiscal' => trim(implode(', ', array_filter([$c->address, $c->city, $c->province]))) ?: null,
            'credit_limit' => (float) $c->credit_limit > 0 ? (float) $c->credit_limit : null, 'is_active' => (bool) $c->is_active,
        ], fn($v) => $v !== null && $v !== '');
    }

    public static function usuarioItem(\App\Models\User $u): array
    {
        return ['email' => strtolower($u->email), 'name' => $u->name, 'role' => CrmService::ROLES[$u->rolActual()?->slug ?? ''] ?? ($u->esDueno() ? 'admin' : 'operator'), 'is_active' => $u->status === 'active'];
    }

    public static function productoItem(Product $p, int $lista): array
    {
        $rubro = $p->rubro; $padre = $rubro?->parent;
        return array_filter([
            'external_id' => (string) $p->id, 'name' => $p->name, 'description' => $p->description, 'code' => $p->sku ?: $p->barcode, 'price' => number_format($p->precioLista($lista), 2, '.', ''),
            'category' => $padre?->nombre ?? $rubro?->nombre, 'subcategory' => $padre ? $rubro?->nombre : null,
            'stock_quantity' => $p->controla_stock ? (int) floor((float) $p->stock) : null, 'low_stock_threshold' => (int) $p->stock_min, 'is_active' => (bool) $p->active,
        ], fn($v) => $v !== null && $v !== '');
    }

    // Manda un lote al CRM y devuelve el resumen. Guarda el id del CRM en crm_external_id de cada cliente.
    public static function enviar(Business $b, string $tipo, array $ids): array
    {
        $c = CrmService::config($b);
        $lista = (int) ($c['lista_precios'] ?: 1);
        $items = match ($tipo) {
            'productos' => Product::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('id', $ids)->with('rubro.parent')->get()->map(fn($p) => self::productoItem($p, $lista))->values()->all(),
            'usuarios' => \App\Models\User::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('id', $ids)->where('is_superadmin', false)->get()->map(fn($u) => self::usuarioItem($u))->values()->all(),
            default => Contact::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('id', $ids)->get()->map(fn($x) => self::contactoItem($x))->values()->all(),
        };
        if (! $items) return ['total' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0];
        $ruta = match ($tipo) { 'productos' => '/api/v1/products/sync', 'usuarios' => '/api/v1/users/sync', default => '/api/v1/contacts/sync' };
        $r = Http::timeout(30)->withToken($c['api_key'])->acceptJson()->post(CrmService::url($b, $ruta), ['items' => $items]);
        if (! $r->successful()) { Log::warning("CRM sync {$tipo}: " . $r->status() . ' ' . mb_substr($r->body(), 0, 300)); throw new \RuntimeException("El CRM respondió {$r->status()} al sincronizar {$tipo}."); }
        $d = $r->json('data') ?? $r->json();
        if ($tipo === 'contactos') {
            foreach ($d['results'] ?? [] as $res) {
                if (($res['action'] ?? '') !== 'error' && ! empty($res['id']) && ! empty($res['external_id'])) Contact::withoutGlobalScopes()->where('business_id', $b->id)->where('id', (int) $res['external_id'])->whereNull('crm_external_id')->update(['crm_external_id' => (string) $res['id']]);
            }
        }
        return $d['summary'] ?? ['total' => count($items)];
    }

    // Carga inicial o re-sincronización completa: todos los clientes y artículos, en lotes de 500 (en cola).
    public static function sincronizarTodo(Business $b): array
    {
        $cont = Contact::withoutGlobalScopes()->where('business_id', $b->id)->where('is_active', true)->pluck('id')->all();
        $prod = Product::withoutGlobalScopes()->where('business_id', $b->id)->where('active', true)->pluck('id')->all();
        foreach (array_chunk($cont, self::LOTE) as $ch) \App\Jobs\SincronizarCrmJob::dispatch($b->id, 'contactos', $ch);
        foreach (array_chunk($prod, self::LOTE) as $ch) \App\Jobs\SincronizarCrmJob::dispatch($b->id, 'productos', $ch);
        return ['contactos' => count($cont), 'productos' => count($prod)];
    }

    // Tareas y turnos del CRM para la agenda del ERP (en lectura, caché de 5 minutos). Cada ítem lleva el link que entra al CRM ya logueado.
    public static function agenda(Business $b, string $desde, string $hasta): array
    {
        if (! CrmService::activo($b) || CrmService::config($b)['api_key'] === '') return [];
        return Cache::remember("crm_agenda_{$b->id}_{$desde}_{$hasta}", now()->addMinutes(5), function () use ($b, $desde, $hasta) {
            $c = CrmService::config($b); $out = [];
            try {
                $t = Http::timeout(8)->withToken($c['api_key'])->acceptJson()->get(CrmService::url($b, '/api/v1/tasks'), ['from' => $desde, 'to' => $hasta, 'limit' => 100]);
                foreach ((array) ($t->json('data') ?? []) as $k) { if (($k['status'] ?? '') === 'completed' || empty($k['due_date'])) continue; $f = substr((string) $k['due_date'], 0, 10); $h = strlen((string) $k['due_date']) > 10 ? substr((string) $k['due_date'], 11, 5) : null; $out[] = ['tipo' => 'tarea', 'id' => $k['id'], 'fecha' => $f, 'hora' => $h && $h !== '00:00' ? $h : null, 'titulo' => $k['title'] ?? 'Tarea', 'detalle' => $k['description'] ?? null, 'prioridad' => $k['priority'] ?? null, 'url' => '/integraciones/crm/ir?a=' . urlencode('/tasks')]; }
                $a = Http::timeout(8)->withToken($c['api_key'])->acceptJson()->get(CrmService::url($b, '/api/v1/appointments'), ['from' => $desde, 'to' => $hasta, 'limit' => 100]);
                foreach ((array) ($a->json('data') ?? []) as $k) { if (in_array($k['status'] ?? '', ['cancelled', 'no_show'], true) || empty($k['start_at'])) continue; $ini = \Carbon\Carbon::parse($k['start_at']); $out[] = ['tipo' => 'turno', 'id' => $k['id'], 'fecha' => $ini->toDateString(), 'hora' => $ini->format('H:i'), 'titulo' => $k['service_name_snapshot'] ?? 'Turno', 'detalle' => $k['notes'] ?? null, 'estado' => $k['status'] ?? null, 'url' => '/integraciones/crm/ir?a=' . urlencode('/appointments')]; }
            } catch (\Throwable $e) { Log::warning('CRM agenda: ' . $e->getMessage()); }
            usort($out, fn($x, $y) => [$x['fecha'], $x['hora'] ?? '99'] <=> [$y['fecha'], $y['hora'] ?? '99']);
            return $out;
        });
    }

    // Recordatorio de cobranza como tarea del CRM (el vendedor lo ve en su día y lo manda por la conversación del cliente).
    public static function tareaCobranza(Business $b, Contact $cli, string $titulo, string $texto): ?int
    {
        if (! CrmService::activo($b) || CrmService::config($b)['api_key'] === '') return null;
        $c = CrmService::config($b);
        if (! $cli->crm_external_id) { try { self::enviar($b, 'contactos', [$cli->id]); $cli->refresh(); } catch (\Throwable $e) {} }
        $r = Http::timeout(10)->withToken($c['api_key'])->acceptJson()->post(CrmService::url($b, '/api/v1/tasks'), array_filter(['title' => $titulo, 'description' => $texto, 'due_date' => today()->toDateString(), 'priority' => 'high', 'task_type' => 'call', 'contact_id' => $cli->crm_external_id ? (int) $cli->crm_external_id : null]));
        if (! $r->successful()) throw new \RuntimeException("El CRM respondió {$r->status()} al crear la tarea de cobranza.");
        return (int) ($r->json('data.id') ?? 0) ?: null;
    }

    // ── CRM → ERP ────────────────────────────────────────────────────────────
    public static function firmaValida(string $cuerpo, ?string $firma, string $secreto): bool
    {
        if (! $firma || $secreto === '') return false;
        $hex = str_starts_with($firma, 'sha256=') ? substr($firma, 7) : $firma;
        return hash_equals(hash_hmac('sha256', $cuerpo, $secreto), $hex);
    }

    public static function procesarWebhook(Business $b, string $evento, array $data): array
    {
        return match ($evento) {
            'contact.created', 'contact.updated' => self::recibirContacto($b, (array) ($data['contact'] ?? $data)),
            'quote.accepted' => self::recibirPresupuesto($b, (array) ($data['quote'] ?? []), is_array($data['contact'] ?? null) ? $data['contact'] : null),
            'deal.won' => self::avisarVentaGanada($b, (array) ($data['deal'] ?? [])),
            default => ['ok' => true, 'ignorado' => $evento],
        };
    }

    // Presupuesto aceptado en el CRM: nace como presupuesto (o factura en borrador, según la configuración) en el ERP y devuelve su número.
    public static function recibirPresupuesto(Business $b, array $q, ?array $contacto = null): array
    {
        if (empty($q['id'])) return ['ok' => false, 'motivo' => 'Presupuesto sin id.'];
        $ya = \App\Models\Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('crm_quote_id', (int) $q['id'])->where('estado', '!=', 'anulado')->first();
        if ($ya) return ['ok' => true, 'accion' => 'existente', 'id' => $ya->id, 'numero' => $ya->numeroFormateado()];
        // Cliente: por id del CRM, por external_id (id del ERP), por CUIT o email; si no existe, se crea.
        $cq = Contact::withoutGlobalScopes()->where('business_id', $b->id);
        $cli = ! empty($q['contact_id']) ? (clone $cq)->where('crm_external_id', (string) $q['contact_id'])->first() : null;
        if (! $cli && $contacto) { $r = self::recibirContacto($b, $contacto + ['id' => $q['contact_id'] ?? null, 'name' => $contacto['name'] ?? ($q['bill_to_name'] ?? 'Cliente del CRM')]); $cli = ! empty($r['id']) ? Contact::withoutGlobalScopes()->find($r['id']) : null; }
        if (! $cli) return ['ok' => false, 'motivo' => 'El cliente del presupuesto no existe en el ERP y el CRM no mandó sus datos. Sincronizá contactos primero.'];
        $user = \App\Models\User::withoutGlobalScopes()->where('business_id', $b->id)->whereNotNull('role_id')->orderBy('id')->first();
        if (! $user) return ['ok' => false, 'motivo' => 'La empresa no tiene usuarios activos en el ERP.'];
        $iva = (float) ($q['tax_rate'] ?? 21); if (! in_array($iva, [0, 2.5, 5, 10.5, 21, 27], true)) $iva = 21.0;
        $items = [];
        foreach ((array) ($q['items'] ?? []) as $it) {
            $prod = null;
            if (! empty($it['code'])) $prod = Product::withoutGlobalScopes()->where('business_id', $b->id)->where(fn($w) => $w->where('sku', $it['code'])->orWhere('barcode', $it['code']))->first();
            $items[] = ['product_id' => $prod?->id, 'descripcion' => (string) ($it['description'] ?? $prod?->name ?? 'Ítem'), 'cantidad' => (float) ($it['quantity'] ?? 1), 'precio_unit' => (float) ($it['unit_price'] ?? 0), 'descuento' => 0, 'alicuota_iva' => $prod ? (float) $prod->iva : $iva];
        }
        if (! $items) return ['ok' => false, 'motivo' => 'El presupuesto no tiene ítems.'];
        $modo = CrmService::config($b)['presupuesto_como'] ?? 'presupuesto';
        $prev = \Illuminate\Support\Facades\Auth::user(); \Illuminate\Support\Facades\Auth::setUser($user);
        self::$silencio = true;
        try {
            $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
            $c = $svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => $modo === 'factura' ? 'FX' : 'PRE', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'items' => $items,
                'notas' => trim('Presupuesto ' . ($q['quote_number'] ?? $q['id']) . ' del CRM' . (! empty($q['title']) ? ' · ' . $q['title'] : '') . (! empty($q['notes']) ? "\n" . $q['notes'] : ''))]);
            $c->forceFill(['crm_quote_id' => (int) $q['id']])->save();
            if ($modo !== 'factura') $c = $svc->emitir($c); // el presupuesto se numera; la factura queda en borrador para revisar y emitir
            AuditLog::registrar('crear', $c, "{$c->nombreTipo()} " . ($c->numeroFormateado() ?? 'borrador') . " creado desde el presupuesto " . ($q['quote_number'] ?? $q['id']) . ' del CRM');
            \App\Models\Alerta::create(['business_id' => $b->id, 'business_location_id' => $c->business_location_id, 'modulo' => 'comprobantes', 'tipo' => 'crm_presupuesto', 'severidad' => 'aviso', 'modelo' => 'Comprobante', 'modelo_id' => $c->id,
                'titulo' => ($modo === 'factura' ? 'Factura en borrador desde el CRM · ' : 'Presupuesto aceptado en el CRM · ') . $cli->name, 'detalle' => ($q['quote_number'] ?? '') . ' por $ ' . number_format((float) $c->total, 0, ',', '.') . ($modo === 'factura' ? '. Revisala y emitila.' : '. Facturalo cuando corresponda.'), 'url' => "/comprobantes/{$c->id}"]);
            return ['ok' => true, 'accion' => 'creado', 'id' => $c->id, 'numero' => $c->numeroFormateado() ?? ('borrador #' . $c->id), 'tipo' => $c->tipo];
        } finally { self::$silencio = false; if ($prev) \Illuminate\Support\Facades\Auth::setUser($prev); else \Illuminate\Support\Facades\Auth::logout(); }
    }

    // Venta ganada en el CRM sin presupuesto: aviso en la campana para armar la venta.
    public static function avisarVentaGanada(Business $b, array $d): array
    {
        if (empty($d['id'])) return ['ok' => false, 'motivo' => 'Deal sin id.'];
        $cli = ! empty($d['contact_id']) ? Contact::withoutGlobalScopes()->where('business_id', $b->id)->where('crm_external_id', (string) $d['contact_id'])->first() : null;
        \App\Models\Alerta::withoutGlobalScopes()->updateOrCreate(['business_id' => $b->id, 'tipo' => 'crm_venta', 'modelo' => 'CrmDeal', 'modelo_id' => (int) $d['id']],
            ['modulo' => 'comprobantes', 'severidad' => 'aviso', 'titulo' => 'Venta ganada en el CRM' . ($cli ? ' · ' . $cli->name : '') . (! empty($d['title']) ? ' · ' . $d['title'] : ''), 'detalle' => (isset($d['value']) ? 'Valor $ ' . number_format((float) $d['value'], 0, ',', '.') . '. ' : '') . 'Si no salió de un presupuesto, armá la venta en el ERP.', 'url' => $cli ? "/clientes/{$cli->id}" : '/comprobantes', 'resuelta_en' => null]);
        return ['ok' => true, 'accion' => 'avisado'];
    }

    // Alta o actualización de un cliente que nació o cambió en el CRM. Lo fiscal (CUIT, condición IVA, domicilio) es del ERP:
    // se toma del CRM solo cuando el cliente no lo tiene todavía.
    public static function recibirContacto(Business $b, array $k): array
    {
        if (empty($k['id']) || empty($k['name'])) return ['ok' => false, 'motivo' => 'Contacto sin id o sin nombre.'];
        $crmId = (string) $k['id'];
        $q = Contact::withoutGlobalScopes()->where('business_id', $b->id);
        $c = (clone $q)->where('crm_external_id', $crmId)->first()
            ?? (! empty($k['external_id']) && ctype_digit((string) $k['external_id']) ? (clone $q)->where('id', (int) $k['external_id'])->first() : null)
            ?? (! empty($k['cuit']) && strlen(Cuit::limpiar($k['cuit'])) === 11 ? (clone $q)->get()->first(fn($x) => Cuit::limpiar($x->cuit) === Cuit::limpiar($k['cuit'])) : null)
            ?? (! empty($k['email']) ? (clone $q)->where('email', strtolower((string) $k['email']))->first() : null);
        self::$silencio = true;
        try {
            $telefono = $k['phone'] ?? null;
            if ($c) {
                $c->fill(['name' => $k['name'], 'email' => $k['email'] ?? $c->email, 'mobile' => $telefono ?: $c->mobile, 'notes' => $k['notes'] ?? $c->notes, 'crm_external_id' => $crmId]);
                if (! $c->cuit && ! empty($k['cuit']) && Cuit::valido($k['cuit'])) $c->cuit = Cuit::formatear($k['cuit']);
                if (! $c->address && ! empty($k['domicilio_fiscal'])) $c->address = mb_substr((string) $k['domicilio_fiscal'], 0, 255);
                if (array_key_exists('is_active', $k) && $k['is_active'] === false) $c->is_active = false;
                $c->save();
                return ['ok' => true, 'accion' => 'actualizado', 'id' => $c->id];
            }
            $cond = in_array($k['condicion_iva'] ?? '', Contact::CONDICIONES_IVA, true) ? $k['condicion_iva'] : 'Consumidor Final';
            $c = Contact::create(['business_id' => $b->id, 'type' => 'customer', 'name' => $k['name'], 'email' => $k['email'] ?? null, 'mobile' => $telefono, 'cuit' => ! empty($k['cuit']) && Cuit::valido($k['cuit']) ? Cuit::formatear($k['cuit']) : null,
                'condicion_iva' => $cond, 'address' => isset($k['domicilio_fiscal']) ? mb_substr((string) $k['domicilio_fiscal'], 0, 255) : null, 'notes' => $k['notes'] ?? null, 'is_active' => true, 'lista_precios' => 1, 'crm_external_id' => $crmId]);
            AuditLog::registrar('crear', $c, "Cliente {$c->name} creado desde el CRM");
            return ['ok' => true, 'accion' => 'creado', 'id' => $c->id];
        } finally { self::$silencio = false; }
    }
}
