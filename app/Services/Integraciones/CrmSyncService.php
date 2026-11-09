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
        $t0 = microtime(true);
        try { $r = Http::timeout(30)->withToken($c['api_key'])->acceptJson()->post(CrmService::url($b, $ruta), ['items' => $items]); }
        catch (\Throwable $e) { CrmSaludService::registrar($b->id, 'salida', "sync.{$tipo}", 'error', ['referencia' => count($items) . " {$tipo}", 'detalle' => 'Sin conexión: ' . $e->getMessage()]); throw $e; }
        $ms = (int) ((microtime(true) - $t0) * 1000);
        if (! $r->successful()) { CrmSaludService::registrar($b->id, 'salida', "sync.{$tipo}", 'error', ['referencia' => count($items) . " {$tipo}", 'http' => $r->status(), 'ms' => $ms, 'detalle' => mb_substr($r->body(), 0, 200)]); Log::warning("CRM sync {$tipo}: " . $r->status() . ' ' . mb_substr($r->body(), 0, 300)); throw new \RuntimeException("El CRM respondió {$r->status()} al sincronizar {$tipo}."); }
        CrmSaludService::registrar($b->id, 'salida', "sync.{$tipo}", 'ok', ['referencia' => count($items) . " {$tipo}", 'http' => $r->status(), 'ms' => $ms]); // Fase 68
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
    public static function tareaCobranza(Business $b, Contact $cli, string $titulo, string $texto): ?int { return self::tarea($b, $cli, $titulo, $texto); }

    // Fase 71: cualquier tarea para el equipo del CRM (reclamo de WhatsApp, pedido de hablar con alguien, cobranza). null si no hay CRM.
    public static function tarea(Business $b, ?Contact $cli, string $titulo, string $texto, string $tipo = 'call', string $prioridad = 'high'): ?int
    {
        if (! CrmService::activo($b) || CrmService::config($b)['api_key'] === '') return null;
        $c = CrmService::config($b);
        if ($cli && ! $cli->crm_external_id) { try { self::enviar($b, 'contactos', [$cli->id]); $cli->refresh(); } catch (\Throwable $e) {} }
        $r = Http::timeout(10)->withToken($c['api_key'])->acceptJson()->post(CrmService::url($b, '/api/v1/tasks'), array_filter(['title' => $titulo, 'description' => $texto, 'due_date' => today()->toDateString(), 'priority' => $prioridad, 'task_type' => $tipo, 'contact_id' => $cli?->crm_external_id ? (int) $cli->crm_external_id : null]));
        if (! $r->successful()) throw new \RuntimeException("El CRM respondió {$r->status()} al crear la tarea de cobranza.");
        return (int) ($r->json('data.id') ?? 0) ?: null;
    }

    // Fase 71: las conversaciones del CRM con un cliente (en lectura, caché de 2 minutos). La más reciente trae sus últimos mensajes.
    public static function conversaciones(Business $b, Contact $cli, int $max = 10): array
    {
        if (! CrmService::activo($b) || CrmService::config($b)['api_key'] === '' || ! $cli->crm_external_id) return [];
        return Cache::remember("crm_convs_{$b->id}_{$cli->id}", now()->addMinutes(2), function () use ($b, $cli, $max) {
            $c = CrmService::config($b); $canales = ['whatsapp' => 'WhatsApp', 'email' => 'Mail', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'telegram' => 'Telegram', 'sms' => 'SMS', 'webchat' => 'Web'];
            try {
                $r = Http::timeout(8)->withToken($c['api_key'])->acceptJson()->get(CrmService::url($b, '/api/v1/conversations'), ['contact_id' => (int) $cli->crm_external_id, 'limit' => $max]);
                $out = [];
                foreach ((array) ($r->json('data') ?? []) as $i => $k) {
                    if ((string) ($k['contact_id'] ?? '') !== (string) $cli->crm_external_id) continue; // por si el CRM todavía no filtra
                    $msgs = [];
                    if ($i === 0) { $m = Http::timeout(8)->withToken($c['api_key'])->acceptJson()->get(CrmService::url($b, "/api/v1/conversations/{$k['id']}/messages"), ['limit' => 6]); $msgs = collect((array) ($m->json('data') ?? []))->reverse()->map(fn($x) => ['direccion' => $x['direction'] ?? 'inbound', 'texto' => mb_substr((string) ($x['content'] ?? ('[' . ($x['message_type'] ?? 'adjunto') . ']')), 0, 500), 'cuando' => ! empty($x['created_at']) ? \Carbon\Carbon::parse($x['created_at'])->format('d/m H:i') : ''])->values()->all(); }
                    $out[] = ['id' => $k['id'], 'canal' => $canales[$k['channel_type'] ?? ''] ?? ucfirst((string) ($k['channel_type'] ?? 'canal')), 'estado' => $k['status'] ?? '', 'no_leidos' => (int) ($k['unread_count'] ?? 0), 'ultimo' => ! empty($k['last_message_at']) ? \Carbon\Carbon::parse($k['last_message_at'])->diffForHumans() : '', 'mensajes' => $msgs, 'url' => '/integraciones/crm/ir?a=' . urlencode("/conversations?id={$k['id']}")];
                }
                return $out;
            } catch (\Throwable $e) { Log::warning('CRM conversaciones: ' . $e->getMessage()); return []; }
        });
    }

    // ── CRM → ERP ────────────────────────────────────────────────────────────
    // Fase 75: identidad completa. El alta, el cambio de rol y la baja hechos en el CRM se espejan en el ERP (por mail, dentro de la
    // misma empresa). El dueño del ERP es intocable desde afuera; el CRM no puede nombrar dueños ni pasar el máximo de usuarios del plan.
    public static function recibirUsuario(Business $b, string $evento, array $k): array
    {
        $mail = strtolower(trim((string) ($k['email'] ?? ''))); if ($mail === '') return ['ok' => false, 'error' => 'Falta el email del usuario.'];
        $u = \App\Models\User::withoutGlobalScopes()->where('business_id', $b->id)->whereRaw('lower(email) = ?', [$mail])->first();
        $baja = in_array($evento, ['user.deactivated', 'user.deleted'], true) || (array_key_exists('is_active', $k) && ! $k['is_active']);
        $esDueno = $u && $u->id === $b->owner_id;
        $rolSlug = CrmService::ROLES_DESDE_CRM[strtolower((string) ($k['role'] ?? ''))] ?? null;
        $rol = $rolSlug ? \App\Models\Role::withoutGlobalScopes()->where('business_id', $b->id)->where('slug', $rolSlug)->first() : null;

        if ($baja) {
            if (! $u) return ['ok' => true, 'ignorado' => 'No existe en el ERP.'];
            if ($esDueno) return ['ok' => false, 'error' => 'El dueño de la empresa no se desactiva desde el CRM.'];
            if ($u->status !== 'inactive') { $u->forceFill(['status' => 'inactive'])->save(); AuditLog::registrar('editar', $u, "Usuario {$u->email} dado de baja desde el CRM"); }
            return ['ok' => true, 'user_id' => $u->id, 'accion' => 'baja'];
        }
        if ($u) {
            // El dueño del ERP es intocable desde el CRM: ni el rol ni el estado (el nombre sí). Se rechaza para que el CRM deshaga lo suyo.
            if ($esDueno && ($rolSlug || array_key_exists('is_active', $k) && ! $k['is_active'])) return ['ok' => false, 'error' => 'El dueño de la empresa no se cambia desde el CRM: el rol y el estado del dueño se administran en el ERP.'];
            $cambios = [];
            if (! empty($k['name']) && $k['name'] !== $u->name) $cambios['name'] = mb_substr((string) $k['name'], 0, 120);
            if ($rol && ! $esDueno && $rol->id !== $u->role_id) $cambios['role_id'] = $rol->id;
            if (! $esDueno && $u->status !== 'active' && ($k['is_active'] ?? true)) $cambios['status'] = 'active';
            if ($cambios) {
                $u->forceFill($cambios)->save();
                if (isset($cambios['role_id'])) foreach ($u->locations()->get() as $l) $u->locations()->updateExistingPivot($l->id, ['role_id' => $rol->id]);
                AuditLog::registrar('editar', $u, "Usuario {$u->email} actualizado desde el CRM: " . implode(', ', array_keys($cambios)));
            }
            return ['ok' => true, 'user_id' => $u->id, 'accion' => $cambios ? 'actualizado' : 'sin_cambios'];
        }
        // Alta: con el perfil que diga el CRM (o vendedor), en la sucursal principal, con una clave que nadie conoce (entra por el botón del CRM o la recupera).
        $max = (int) ($b->activeSubscription?->plan?->max_users ?? 1);
        $activos = \App\Models\User::withoutGlobalScopes()->where('business_id', $b->id)->where('status', 'active')->where('is_superadmin', false)->whereDoesntHave('role', fn($q) => $q->where('slug', 'chofer'))->count();
        if ($max > 0 && $activos >= $max) return ['ok' => false, 'error' => "El plan del ERP permite hasta {$max} usuario(s): hay que cambiar de plan en el ERP para agregar a {$mail}."];
        $rol = $rol ?? \App\Models\Role::withoutGlobalScopes()->where('business_id', $b->id)->where('slug', 'vendedor')->first();
        if (! $rol) return ['ok' => false, 'error' => 'La empresa no tiene el perfil para este usuario.'];
        $suc = $b->locations()->where('is_active', true)->orderByDesc('is_default')->first();
        $u = new \App\Models\User(['business_id' => $b->id, 'name' => mb_substr((string) ($k['name'] ?: $mail), 0, 120), 'email' => $mail, 'password' => \Illuminate\Support\Str::random(40), 'status' => 'active', 'role_id' => $rol->id, 'current_location_id' => $suc?->id]);
        $u->save();
        if ($suc) $u->locations()->sync([$suc->id => ['role_id' => $rol->id]]);
        AuditLog::registrar('crear', $u, "Usuario {$u->email} dado de alta desde el CRM ({$rol->nombre})");
        return ['ok' => true, 'user_id' => $u->id, 'accion' => 'alta', 'perfil' => $rol->slug];
    }

    // ── Fase 72: lo que el CRM sabe, para Bigcito del ERP ────────────────────
    // Una lectura GET a la API del CRM; null si no está conectado o no respondió (nunca rompe al que pregunta).
    public static function leer(Business $b, string $path, array $query = []): ?array
    {
        if (! CrmService::activo($b) || CrmService::config($b)['api_key'] === '') return null;
        try {
            $r = Http::timeout(8)->withToken(CrmService::config($b)['api_key'])->acceptJson()->get(CrmService::url($b, $path), $query);
            return $r->successful() ? (array) ($r->json('data') ?? []) : null;
        } catch (\Throwable $e) { Log::warning("CRM leer {$path}: " . $e->getMessage()); return null; }
    }

    // Ficha del CRM de un cliente: quién lo atiende, en qué etapa está, oportunidades abiertas, tareas pendientes y próxima cita.
    public static function fichaCrm(Business $b, Contact $cli): ?array
    {
        if (! $cli->crm_external_id) return null;
        return Cache::remember("crm_ficha_{$b->id}_{$cli->id}", now()->addMinutes(2), function () use ($b, $cli) {
            $ext = (int) $cli->crm_external_id;
            $k = self::leer($b, "/api/v1/contacts/{$ext}"); if ($k === null) return null;
            $deals = collect(self::leer($b, '/api/v1/deals', ['contact_id' => $ext, 'status' => 'open', 'limit' => 20]) ?? [])->filter(fn($d) => (int) ($d['contact_id'] ?? $ext) === $ext);
            $tareas = collect(self::leer($b, '/api/v1/tasks', ['contact_id' => $ext, 'status' => 'pending', 'limit' => 20]) ?? [])->filter(fn($t) => (int) ($t['contact_id'] ?? $ext) === $ext && ($t['status'] ?? '') !== 'completed');
            $citas = collect(self::leer($b, '/api/v1/appointments', ['contact_id' => $ext, 'from' => today()->toDateString(), 'to' => today()->addDays(60)->toDateString(), 'limit' => 20]) ?? [])
                ->filter(fn($c) => (int) ($c['contact_id'] ?? $ext) === $ext && ! in_array($c['status'] ?? '', ['cancelled', 'no_show'], true) && ! empty($c['start_at']))->sortBy('start_at');
            $prox = $citas->first();
            return [
                'vendedor' => $k['owner']['name'] ?? $k['owner_name'] ?? $k['assigned_to_name'] ?? null,
                'etapa' => $k['lifecycle_stage'] ?? null, 'etiquetas' => array_values(array_map(fn($t) => is_array($t) ? ($t['name'] ?? '') : (string) $t, (array) ($k['tags'] ?? []))),
                'ultimo_contacto' => ! empty($k['last_contact_at'] ?? $k['last_interaction_at'] ?? null) ? \Carbon\Carbon::parse($k['last_contact_at'] ?? $k['last_interaction_at'])->format('d/m/Y') : null,
                'oportunidades' => $deals->map(fn($d) => self::dealItem($d))->values()->all(),
                'tareas' => $tareas->map(fn($t) => self::tareaItem($t))->values()->all(),
                'proxima_cita' => $prox ? ['cuando' => \Carbon\Carbon::parse($prox['start_at'])->format('d/m/Y H:i'), 'que' => $prox['service_name_snapshot'] ?? $prox['title'] ?? 'Turno', 'con' => $prox['assigned_to_name'] ?? null] : null,
                'url' => '/integraciones/crm/ir?a=' . urlencode("/contacts/{$ext}"),
            ];
        });
    }

    // Oportunidades abiertas del CRM (el embudo), opcionalmente de un cliente.
    public static function oportunidades(Business $b, ?Contact $cli = null): ?array
    {
        $q = ['status' => 'open', 'limit' => 100]; if ($cli) { if (! $cli->crm_external_id) return []; $q['contact_id'] = (int) $cli->crm_external_id; }
        return Cache::remember("crm_deals_{$b->id}_" . ($cli?->id ?? 'todas'), now()->addMinutes(2), function () use ($b, $q) {
            $d = self::leer($b, '/api/v1/deals', $q); if ($d === null) return null;
            return collect($d)->filter(fn($x) => ! in_array($x['status'] ?? 'open', ['won', 'lost'], true))->map(fn($x) => self::dealItem($x))->values()->all();
        });
    }

    // Tareas del CRM vencidas o de hoy (lo que el equipo de ventas tiene que atender).
    public static function tareasPendientes(Business $b): ?array
    {
        return Cache::remember("crm_tareas_{$b->id}", now()->addMinutes(2), function () use ($b) {
            $t = self::leer($b, '/api/v1/tasks', ['status' => 'pending', 'to' => today()->toDateString(), 'limit' => 100]); if ($t === null) return null;
            return collect($t)->filter(fn($x) => ($x['status'] ?? '') !== 'completed')->map(fn($x) => self::tareaItem($x))->sortBy('vence')->values()->all();
        });
    }

    // Fase 74: el embudo del CRM para Estadísticas → Vendedores: abiertas por etapa, ganadas/perdidas en el período y por vendedor,
    // cruzado con lo vendido en el ERP (por nombre o mail del vendedor). null si el CRM no está o no respondió.
    public static function embudo(Business $b, \Carbon\Carbon $d, \Carbon\Carbon $h, array $vendidoErp = []): ?array
    {
        if (! CrmService::activo($b)) return null;
        $abiertas = self::oportunidades($b); if ($abiertas === null) return null;
        $enPeriodo = fn(array $x) => ($f = $x['won_at'] ?? $x['closed_at'] ?? $x['updated_at'] ?? null) ? \Carbon\Carbon::parse($f)->between($d->copy()->startOfDay(), $h->copy()->endOfDay()) : true;
        $ganadas = collect(Cache::remember("crm_deals_won_{$b->id}_{$d->toDateString()}_{$h->toDateString()}", now()->addMinutes(5), fn() => self::leer($b, '/api/v1/deals', ['status' => 'won', 'from' => $d->toDateString(), 'to' => $h->toDateString(), 'limit' => 200]) ?? []))->filter($enPeriodo)->map(fn($x) => self::dealItem($x));
        $perdidas = collect(Cache::remember("crm_deals_lost_{$b->id}_{$d->toDateString()}_{$h->toDateString()}", now()->addMinutes(5), fn() => self::leer($b, '/api/v1/deals', ['status' => 'lost', 'from' => $d->toDateString(), 'to' => $h->toDateString(), 'limit' => 200]) ?? []))->filter($enPeriodo);
        $tareas = collect(self::tareasPendientes($b) ?? [])->where('vencida', true);
        $ab = collect($abiertas);
        $etapas = $ab->groupBy(fn($x) => $x['etapa'] ?? 'Sin etapa')->map(fn($g, $k) => ['etapa' => $k, 'cantidad' => $g->count(), 'total' => round($g->sum('monto'), 2)])->sortByDesc('total')->values()->all();
        $norm = fn($s) => mb_strtolower(trim((string) $s));
        $erp = []; foreach ($vendidoErp as $v) { if (! empty($v['nombre'])) $erp[$norm($v['nombre'])] = (float) $v['total']; if (! empty($v['email'])) $erp[$norm($v['email'])] = (float) $v['total']; }
        $nombres = $ab->pluck('vendedor')->merge($ganadas->pluck('vendedor'))->merge($tareas->pluck('vendedor'))->filter()->unique()->values();
        $porVendedor = $nombres->map(fn($n) => ['vendedor' => $n, 'abiertas' => $ab->where('vendedor', $n)->count(), 'abiertas_total' => round($ab->where('vendedor', $n)->sum('monto'), 2),
            'ganadas' => $ganadas->where('vendedor', $n)->count(), 'ganadas_total' => round($ganadas->where('vendedor', $n)->sum('monto'), 2), 'tareas_vencidas' => $tareas->where('vendedor', $n)->count(),
            'vendido_erp' => $erp[$norm($n)] ?? null])->sortByDesc('abiertas_total')->values()->all();
        $cerradas = $ganadas->count() + $perdidas->count();
        return ['abiertas' => ['cantidad' => $ab->count(), 'total' => round($ab->sum('monto'), 2)], 'ganadas' => ['cantidad' => $ganadas->count(), 'total' => round($ganadas->sum('monto'), 2)], 'perdidas' => ['cantidad' => $perdidas->count()],
            'tasa_cierre' => $cerradas ? (int) round($ganadas->count() / $cerradas * 100) : null, 'tareas_vencidas' => $tareas->count(), 'etapas' => $etapas, 'por_vendedor' => $porVendedor, 'url' => '/integraciones/crm/ir?a=' . urlencode('/deals')];
    }

    private static function dealItem(array $d): array
    {
        $cli = ! empty($d['contact_id']) ? Contact::withoutGlobalScopes()->where('crm_external_id', (string) $d['contact_id'])->first() : null;
        return ['id' => $d['id'] ?? null, 'titulo' => $d['title'] ?? '', 'monto' => (float) ($d['value'] ?? 0), 'etapa' => $d['stage_name'] ?? $d['stage']['name'] ?? (isset($d['stage_id']) ? "etapa #{$d['stage_id']}" : null),
            'vendedor' => $d['assigned_to_name'] ?? $d['assigned_to']['name'] ?? null, 'prioridad' => $d['priority'] ?? null, 'vence' => ! empty($d['due_date']) ? substr((string) $d['due_date'], 0, 10) : null,
            'cliente' => $cli?->name ?? $d['contact_name'] ?? null, 'cliente_id' => $cli?->id, 'url' => '/integraciones/crm/ir?a=' . urlencode('/deals?id=' . ($d['id'] ?? ''))];
    }

    private static function tareaItem(array $t): array
    {
        $cli = ! empty($t['contact_id']) ? Contact::withoutGlobalScopes()->where('crm_external_id', (string) $t['contact_id'])->first() : null;
        $vence = ! empty($t['due_date']) ? substr((string) $t['due_date'], 0, 10) : null;
        return ['id' => $t['id'] ?? null, 'titulo' => $t['title'] ?? '', 'vence' => $vence, 'vencida' => $vence !== null && $vence < today()->toDateString(), 'prioridad' => $t['priority'] ?? null,
            'vendedor' => $t['assigned_to_name'] ?? $t['assigned_to']['name'] ?? null, 'cliente' => $cli?->name ?? $t['contact_name'] ?? null, 'cliente_id' => $cli?->id,
            'url' => '/integraciones/crm/ir?a=' . urlencode('/tasks?id=' . ($t['id'] ?? ''))];
    }

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
            'task.overdue', 'task.completed' => CrmAvisosService::webhookTarea($b, $evento, (array) ($data['task'] ?? $data)), // Fase 73
            'user.created', 'user.updated', 'user.deactivated', 'user.deleted' => self::recibirUsuario($b, $evento, (array) ($data['user'] ?? $data)), // Fase 75
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
            // Por external_id (es el id del artículo en el ERP, el que el ERP le mandó al CRM al sincronizar) y, si no, por código o barras.
            if (! empty($it['external_id']) && ctype_digit((string) $it['external_id'])) $prod = Product::withoutGlobalScopes()->where('business_id', $b->id)->find((int) $it['external_id']);
            if (! $prod && ! empty($it['code'])) $prod = Product::withoutGlobalScopes()->where('business_id', $b->id)->where(fn($w) => $w->where('sku', $it['code'])->orWhere('barcode', $it['code']))->first();
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
