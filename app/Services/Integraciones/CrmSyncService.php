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
        $items = $tipo === 'productos'
            ? Product::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('id', $ids)->with('rubro.parent')->get()->map(fn($p) => self::productoItem($p, $lista))->values()->all()
            : Contact::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('id', $ids)->get()->map(fn($x) => self::contactoItem($x))->values()->all();
        if (! $items) return ['total' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0];
        $r = Http::timeout(30)->withToken($c['api_key'])->acceptJson()->post(CrmService::url($b, $tipo === 'productos' ? '/api/v1/products/sync' : '/api/v1/contacts/sync'), ['items' => $items]);
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
            default => ['ok' => true, 'ignorado' => $evento],
        };
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
