<?php

namespace App\Services\Fondos;

use App\Models\AuditLog;
use App\Models\Cobro;
use App\Models\CobroMedio;
use App\Models\CuentaFondos;
use App\Models\CuponTarjeta;
use App\Models\LiquidacionTarjeta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Cupones de tarjeta en cartera y su liquidación: bruto − comisión − IVA − retenciones = neto que entra al banco.
class TarjetasService
{
    public function __construct(private FondosService $fondos) {}

    // Cada cobro con tarjeta deja un cupón en cartera.
    public function cuponDesdeCobro(Cobro $cobro, CobroMedio $medio): CuponTarjeta
    {
        $d = $medio->datos ?? [];
        return CuponTarjeta::create([
            'business_id' => $cobro->business_id, 'cobro_id' => $cobro->id, 'cobro_medio_id' => $medio->id, 'contact_id' => $cobro->contact_id,
            'tarjeta' => $d['tarjeta'] ?? 'Otra', 'numero' => $d['numero'] ?? $medio->referencia, 'lote' => $d['lote'] ?? null, 'cuotas' => (int) ($d['cuotas'] ?? 1),
            'monto' => $medio->monto, 'fecha' => $cobro->fecha, 'estado' => 'cartera',
        ]);
    }

    public function liquidar(array $d): LiquidacionTarjeta
    {
        return DB::transaction(function () use ($d) {
            $user = Auth::user();
            $cupones = CuponTarjeta::enCartera()->whereIn('id', $d['cupones'] ?? [])->lockForUpdate()->get();
            if ($cupones->isEmpty()) throw ValidationException::withMessages(['cupones' => 'Elegí al menos un cupón en cartera.']);
            $banco = CuentaFondos::findOrFail($d['cuenta_fondos_id']);
            $bruto = round($cupones->sum(fn($c) => (float) $c->monto), 2);
            $descuentos = round((float) ($d['comision'] ?? 0) + (float) ($d['iva_comision'] ?? 0) + (float) ($d['ret_iva'] ?? 0) + (float) ($d['ret_iibb'] ?? 0) + (float) ($d['ret_ganancias'] ?? 0) + (float) ($d['otros'] ?? 0), 2);
            $neto = round($bruto - $descuentos, 2);
            if ($neto <= 0) throw ValidationException::withMessages(['comision' => 'Los descuentos superan el bruto de los cupones.']);

            $liq = LiquidacionTarjeta::create([
                'business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id,
                'numero' => $d['numero'] ?? null, 'fecha' => $d['fecha'] ?? today(), 'tarjeta' => $d['tarjeta'] ?? $cupones->first()->tarjeta, 'cuenta_fondos_id' => $banco->id,
                'bruto' => $bruto, 'comision' => $d['comision'] ?? 0, 'iva_comision' => $d['iva_comision'] ?? 0, 'ret_iva' => $d['ret_iva'] ?? 0, 'ret_iibb' => $d['ret_iibb'] ?? 0, 'ret_ganancias' => $d['ret_ganancias'] ?? 0, 'otros' => $d['otros'] ?? 0, 'neto' => $neto, 'notas' => $d['notas'] ?? null,
            ]);
            CuponTarjeta::whereIn('id', $cupones->pluck('id'))->update(['estado' => 'liquidado', 'liquidacion_tarjeta_id' => $liq->id]);
            $this->fondos->registrar($banco, ['fecha' => $liq->fecha, 'origen' => 'liquidacion_tarjeta', 'origen_id' => $liq->id, 'concepto' => "Liquidación {$liq->tarjeta} {$liq->numero}: {$cupones->count()} cupones", 'ingreso' => $neto, 'referencia' => $liq->numero]);
            AuditLog::registrar('crear', $liq, "Liquidación de tarjeta {$liq->numeroFormateado()}: bruto $ " . number_format($bruto, 2, ',', '.') . ", neto $ " . number_format($neto, 2, ',', '.'));
            app(\App\Services\Contabilidad\ContabilidadService::class)->contabilizar($liq->fresh());
            return $liq;
        });
    }

    public function anular(LiquidacionTarjeta $liq, string $motivo = ''): void
    {
        DB::transaction(function () use ($liq, $motivo) {
            abort_if($liq->estado === 'anulada', 422, 'Ya está anulada.');
            CuponTarjeta::where('liquidacion_tarjeta_id', $liq->id)->update(['estado' => 'cartera', 'liquidacion_tarjeta_id' => null]);
            $this->fondos->revertir('liquidacion_tarjeta', $liq->id);
            $liq->update(['estado' => 'anulada', 'notas' => trim(($liq->notas ?? '') . "\nAnulada: {$motivo}")]);
            app(\App\Services\Contabilidad\ContabilidadService::class)->anular('liquidacion_tarjeta', $liq->id, $motivo);
            AuditLog::registrar('anular', $liq, "Anuló liquidación {$liq->numeroFormateado()}: {$motivo}");
        });
    }

    public function rechazar(CuponTarjeta $c, string $motivo = ''): void
    {
        abort_if($c->estado !== 'cartera', 422, 'Solo se rechazan cupones en cartera.');
        $c->update(['estado' => 'rechazado']);
        AuditLog::registrar('editar', $c, "Cupón {$c->numero} rechazado: {$motivo}");
    }
}
