<?php

namespace App\Services\Ventas;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\PuntoMovimiento;
use Illuminate\Support\Facades\DB;

// Fidelización por puntos: se suman al emitir facturas y se canjean como descuento.
class FidelizacionService
{
    public const DEFAULT = ['activo' => false, 'pesos_por_punto' => 1000, 'valor_punto' => 10, 'minimo_canje' => 50, 'bienvenida' => 0, 'vencen_meses' => 0, 'excluir_cf' => true];

    public function config(Business $b): array { return array_replace(self::DEFAULT, $b->fidelizacion ?? []); }

    // Puntos que suma una factura emitida (no aplica a NC ni a consumidor final si está excluido).
    public function acreditarPorComprobante(Comprobante $c): ?PuntoMovimiento
    {
        $b = $c->business; $cfg = $this->config($b);
        if (! $cfg['activo'] || ! $c->contact_id || ! $c->esFactura() || $c->estado !== 'emitido') return null;
        $contact = $c->contact;
        if ($cfg['excluir_cf'] && $contact->name === 'Consumidor Final') return null;
        if (PuntoMovimiento::withoutGlobalScopes()->where('origen', 'comprobante')->where('origen_id', $c->id)->exists()) return null;
        $puntos = floor((float) $c->total / max(1, (float) $cfg['pesos_por_punto']));
        if ($puntos <= 0) return null;
        return $this->mover($contact, $puntos, "{$c->nombreTipo()} {$c->numeroFormateado()}", 'comprobante', $c->id);
    }

    public function mover(Contact $contact, float $puntos, string $motivo, ?string $origen = null, ?int $origenId = null): PuntoMovimiento
    {
        return DB::transaction(function () use ($contact, $puntos, $motivo, $origen, $origenId) {
            $m = PuntoMovimiento::create(['business_id' => $contact->business_id, 'contact_id' => $contact->id, 'puntos' => $puntos, 'motivo' => $motivo, 'origen' => $origen, 'origen_id' => $origenId]);
            $contact->forceFill(['puntos' => round((float) $contact->puntos + $puntos, 2)])->save();
            return $m;
        });
    }

    // Canje: convierte puntos en pesos de descuento. Devuelve el importe.
    public function canjear(Contact $contact, float $puntos, string $motivo = 'Canje'): float
    {
        $cfg = $this->config($contact->business);
        abort_if(! $cfg['activo'], 422, 'El programa de puntos no está activo.');
        abort_if($puntos < (float) $cfg['minimo_canje'], 422, "El canje mínimo es {$cfg['minimo_canje']} puntos.");
        abort_if($puntos > (float) $contact->puntos, 422, 'El cliente no tiene esos puntos.');
        $this->mover($contact, -$puntos, $motivo, 'canje');
        AuditLog::registrar('editar', $contact, "Canjeó {$puntos} puntos de {$contact->name}");
        return round($puntos * (float) $cfg['valor_punto'], 2);
    }

    public function valorEnPesos(Business $b, float $puntos): float { return round($puntos * (float) $this->config($b)['valor_punto'], 2); }
}
