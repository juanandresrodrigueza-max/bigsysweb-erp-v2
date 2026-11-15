<?php

namespace App\Services\Compras;

use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\OrdenCompra;
use App\Models\User;
use App\Services\Avisos\CelularService;

// Fase 97: circuito de compras con autorización por monto. Desde cierto importe, la orden no sale al proveedor hasta que la
// autorice alguien de la lista (el dueño siempre puede). Quien la pide ve "esperando autorización"; los autorizadores reciben la
// alerta y el aviso al celular; al autorizar queda enviada, al rechazar vuelve a borrador con el motivo.
class AutorizacionComprasService
{
    public function config(Business $b): array
    {
        $c = $b->compras_config ?? [];
        return ['autoriza_desde' => (float) ($c['autoriza_desde'] ?? 0), 'autorizadores' => array_values(array_map('intval', (array) ($c['autorizadores'] ?? [])))];
    }

    public function guardarConfig(Business $b, float $desde, array $autorizadores): void
    {
        $c = $b->compras_config ?? []; $c['autoriza_desde'] = round($desde, 2); $c['autorizadores'] = array_values(array_unique(array_map('intval', $autorizadores)));
        $b->forceFill(['compras_config' => $c])->save();
        AuditLog::registrar('editar', $b, $desde > 0 ? "Órdenes de compra desde $ " . number_format($desde, 0, ',', '.') . ' necesitan autorización' : 'Órdenes de compra sin autorización');
    }

    public function esAutorizador(User $u): bool
    {
        if ($u->esDueno() || $u->is_superadmin) return true;
        return in_array((int) $u->id, $this->config($u->business)['autorizadores'], true);
    }

    public function autorizadores(Business $b): \Illuminate\Support\Collection
    {
        $ids = $this->config($b)['autorizadores'];
        return User::withoutGlobalScopes()->where('business_id', $b->id)->where('status', 'active')->where(fn($q) => $q->whereIn('id', $ids)->orWhere('id', $b->owner_id))->get();
    }

    // ¿Esta orden necesita que la autorice otro? Si la pide un autorizador, no.
    public function requiere(OrdenCompra $oc, User $quien): bool
    {
        $cfg = $this->config($quien->business);
        return $cfg['autoriza_desde'] > 0 && (float) $oc->total >= $cfg['autoriza_desde'] && $oc->autorizacion !== 'autorizada' && ! $this->esAutorizador($quien);
    }

    public function pedir(OrdenCompra $oc, User $quien): OrdenCompra
    {
        $oc->update(['autorizacion' => 'pendiente', 'autorizacion_nota' => null]);
        $b = $quien->business; $monto = '$ ' . number_format((float) $oc->total, 0, ',', '.');
        Alerta::emitir(['business_id' => $b->id, 'business_location_id' => $oc->business_location_id, 'modulo' => 'proveedores', 'tipo' => 'oc_por_autorizar', 'modelo' => 'OrdenCompra', 'modelo_id' => $oc->id, 'severidad' => 'aviso',
            'titulo' => "Orden {$oc->numeroFormateado()} espera autorización", 'detalle' => "{$quien->name} pide {$monto} a {$oc->contact?->name}.", 'url' => "/proveedores/ordenes/{$oc->id}"]);
        try { $cel = app(CelularService::class); foreach ($this->autorizadores($b) as $u) { if ($u->id !== $quien->id) $cel->enviar($u, "Autorizar compra: {$monto}", "{$quien->name} pide {$oc->numeroFormateado()} a {$oc->contact?->name}.", "/proveedores/ordenes/{$oc->id}"); } } catch (\Throwable) {}
        AuditLog::registrar('editar', $oc, "Orden {$oc->numeroFormateado()} enviada a autorizar ({$monto})");
        return $oc->fresh();
    }

    public function autorizar(OrdenCompra $oc, User $quien, ?string $nota = null): OrdenCompra
    {
        abort_unless($this->esAutorizador($quien), 403, 'No estás en la lista de quienes autorizan compras.');
        abort_if($oc->estado !== 'borrador', 422, 'La orden ya no está en borrador.');
        $oc->update(['autorizacion' => 'autorizada', 'autorizada_por' => $quien->id, 'autorizada_en' => now(), 'autorizacion_nota' => $nota, 'estado' => 'enviada', 'enviada_en' => now()]);
        Alerta::withoutGlobalScopes()->where('modelo', 'OrdenCompra')->where('modelo_id', $oc->id)->where('tipo', 'oc_por_autorizar')->update(['resuelta_en' => now()]);
        if ($oc->user && $oc->user_id !== $quien->id) { try { app(CelularService::class)->enviar($oc->user, "Orden {$oc->numeroFormateado()} autorizada", "{$quien->name} la autorizó: ya está enviada a {$oc->contact?->name}.", "/proveedores/ordenes/{$oc->id}"); } catch (\Throwable) {} }
        AuditLog::registrar('editar', $oc, "Orden {$oc->numeroFormateado()} autorizada por {$quien->name}" . ($nota ? ": {$nota}" : ''));
        return $oc->fresh();
    }

    public function rechazar(OrdenCompra $oc, User $quien, string $nota): OrdenCompra
    {
        abort_unless($this->esAutorizador($quien), 403, 'No estás en la lista de quienes autorizan compras.');
        $oc->update(['autorizacion' => 'rechazada', 'autorizada_por' => $quien->id, 'autorizada_en' => now(), 'autorizacion_nota' => $nota]);
        Alerta::withoutGlobalScopes()->where('modelo', 'OrdenCompra')->where('modelo_id', $oc->id)->where('tipo', 'oc_por_autorizar')->update(['resuelta_en' => now()]);
        if ($oc->user && $oc->user_id !== $quien->id) { try { app(CelularService::class)->enviar($oc->user, "Orden {$oc->numeroFormateado()} rechazada", "{$quien->name}: {$nota}", "/proveedores/ordenes/{$oc->id}"); } catch (\Throwable) {} }
        AuditLog::registrar('editar', $oc, "Orden {$oc->numeroFormateado()} rechazada por {$quien->name}: {$nota}");
        return $oc->fresh();
    }

    public function pendientes(Business $b): \Illuminate\Support\Collection
    {
        return OrdenCompra::withoutGlobalScopes()->where('business_id', $b->id)->where('autorizacion', 'pendiente')->where('estado', 'borrador')->with('contact:id,name', 'user:id,name')->orderBy('fecha')->get();
    }
}
