<?php

namespace App\Services\Fondos;

use App\Models\AuditLog;
use App\Models\CuentaFondos;
use App\Models\MovimientoFondos;
use App\Models\TurnoCaja;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FondosService
{
    public function registrar(CuentaFondos $cuenta, array $d): MovimientoFondos
    {
        $user = Auth::user();
        $turno = $cuenta->tipo === 'caja' ? $cuenta->turnoAbierto : null;
        $m = MovimientoFondos::create([
            'business_id' => $cuenta->business_id, 'cuenta_fondos_id' => $cuenta->id, 'user_id' => $user?->id, 'turno_caja_id' => $turno?->id,
            'fecha' => $d['fecha'] ?? today(), 'origen' => $d['origen'], 'origen_id' => $d['origen_id'] ?? null, 'expense_category_id' => $d['expense_category_id'] ?? null,
            'concepto' => $d['concepto'], 'ingreso' => round((float) ($d['ingreso'] ?? 0), 2), 'egreso' => round((float) ($d['egreso'] ?? 0), 2), 'referencia' => $d['referencia'] ?? null,
        ]);
        $cuenta->recalcularSaldo();
        if (! in_array($m->origen, ['cobro', 'pago', 'liquidacion_tarjeta'], true)) {
            app(\App\Services\Contabilidad\ContabilidadService::class)->contabilizar($m);
        }
        return $m;
    }

    public function revertir(string $origen, int $origenId): void
    {
        $movs = MovimientoFondos::where('origen', $origen)->where('origen_id', $origenId)->get();
        $cuentas = $movs->pluck('cuenta_fondos_id')->unique();
        foreach ($movs as $mv) { app(\App\Services\Contabilidad\ContabilidadService::class)->anular('fondos', $mv->id); }
        MovimientoFondos::whereIn('id', $movs->pluck('id'))->delete();
        CuentaFondos::whereIn('id', $cuentas)->get()->each->recalcularSaldo();
    }

    public function transferir(CuentaFondos $desde, CuentaFondos $hasta, float $monto, ?string $fecha = null, ?string $referencia = null): void
    {
        if ($desde->id === $hasta->id) {
            throw ValidationException::withMessages(['hasta' => 'Elegí una cuenta distinta.']);
        }
        if ($monto <= 0) {
            throw ValidationException::withMessages(['monto' => 'El importe tiene que ser mayor a cero.']);
        }
        DB::transaction(function () use ($desde, $hasta, $monto, $fecha, $referencia) {
            $id = (int) (MovimientoFondos::withoutGlobalScopes()->max('id')) + 1;
            $this->registrar($desde, ['fecha' => $fecha, 'origen' => 'transferencia', 'origen_id' => $id, 'concepto' => "Transferencia a {$hasta->nombre}", 'egreso' => $monto, 'referencia' => $referencia]);
            $this->registrar($hasta, ['fecha' => $fecha, 'origen' => 'transferencia', 'origen_id' => $id, 'concepto' => "Transferencia desde {$desde->nombre}", 'ingreso' => $monto, 'referencia' => $referencia]);
            AuditLog::registrar('crear', $desde, "Transferencia $ " . number_format($monto, 2, ',', '.') . " de {$desde->nombre} a {$hasta->nombre}");
        });
    }

    // Cuenta por defecto según medio: efectivo -> caja de la sucursal; el resto -> banco/billetera default.
    public function cuentaPara(string $medio, $user, ?int $cuentaId = null): ?CuentaFondos
    {
        if ($cuentaId) {
            return CuentaFondos::find($cuentaId);
        }
        $tipo = match ($medio) {
            'efectivo' => 'caja',
            'billetera', 'mercadopago' => 'billetera',
            'tarjeta' => 'tarjeta',
            default => 'banco',
        };
        $q = CuentaFondos::where('activa', true)->where('tipo', $tipo)->orderByDesc('es_default');
        return (clone $q)->where('business_location_id', $user->current_location_id)->first()
            ?? (clone $q)->whereNull('business_location_id')->first()
            ?? $q->first()
            ?? ($tipo === 'billetera' || $tipo === 'tarjeta' ? $this->cuentaPara('transferencia', $user) : null);
    }

    public function abrirTurno(CuentaFondos $caja, float $saldoInicial): TurnoCaja
    {
        abort_if($caja->tipo !== 'caja', 422, 'Solo las cajas tienen turnos.');
        abort_if($caja->turnoAbierto, 422, 'La caja ya tiene un turno abierto.');
        $t = TurnoCaja::create(['business_id' => $caja->business_id, 'cuenta_fondos_id' => $caja->id, 'user_id' => Auth::id(), 'apertura' => now(), 'saldo_inicial' => $saldoInicial]);
        $dif = round($saldoInicial - (float) $caja->fresh()->saldo, 2);
        if (abs($dif) > 0.005) {
            $this->registrar($caja, ['origen' => 'apertura', 'origen_id' => $t->id, 'concepto' => 'Ajuste de apertura de turno', 'ingreso' => max(0, $dif), 'egreso' => max(0, -$dif)]);
        }
        AuditLog::registrar('crear', $t, "Abrió turno en {$caja->nombre} con $ " . number_format($saldoInicial, 2, ',', '.'));
        return $t;
    }

    // Lo que el sistema espera por cada medio durante el turno: efectivo = saldo de la caja; el resto, cobros de la sucursal desde la apertura.
    public function esperadoPorMedio(TurnoCaja $turno): array
    {
        $caja = $turno->cuenta->fresh();
        $out = ['efectivo' => (float) $caja->saldo];
        $q = \App\Models\CobroMedio::join('cobros', 'cobros.id', '=', 'cobro_medios.cobro_id')
            ->where('cobros.business_id', $turno->business_id)->where('cobros.estado', '!=', 'anulado')
            ->where('cobros.created_at', '>=', $turno->apertura)->where('cobro_medios.medio', '!=', 'efectivo')
            ->when($caja->business_location_id, fn($q, $l) => $q->where('cobros.business_location_id', $l))
            ->selectRaw('cobro_medios.medio, SUM(cobro_medios.monto) as monto')->groupBy('cobro_medios.medio')->pluck('monto', 'medio');
        foreach ($q as $medio => $monto) $out[$medio] = round((float) $monto, 2);
        // Ventas en cuenta corriente del turno (no son cobros, pero el cajero las rinde).
        $cc = \App\Models\Comprobante::ventas()->emitidos()->facturas()->where('condicion', 'cta_cte')->where('emitido_en', '>=', $turno->apertura)
            ->when($caja->business_location_id, fn($q, $l) => $q->where('business_location_id', $l))->sum('total');
        if ((float) $cc > 0) $out['cta_cte'] = round((float) $cc, 2);
        return $out;
    }

    public function cerrarTurno(TurnoCaja $turno, float $contado, ?string $notas = null, array $rendicion = []): TurnoCaja
    {
        abort_if($turno->cierre, 422, 'El turno ya está cerrado.');
        $esperadoMedios = $this->esperadoPorMedio($turno);
        $esperado = (float) $turno->cuenta->fresh()->saldo;
        $rend = ['efectivo' => $contado];
        foreach ($rendicion as $medio => $monto) { if ($medio !== 'efectivo' && $monto !== null && $monto !== '') $rend[$medio] = round((float) $monto, 2); }
        $turno->update(['cierre' => now(), 'saldo_esperado' => $esperado, 'saldo_contado' => $contado, 'diferencia' => round($contado - $esperado, 2), 'notas' => $notas, 'esperado_medios' => $esperadoMedios, 'rendicion' => $rend]);
        if (abs($contado - $esperado) > 0.005) {
            $this->registrar($turno->cuenta, ['origen' => 'ajuste', 'origen_id' => $turno->id, 'concepto' => 'Diferencia de cierre de turno', 'ingreso' => max(0, $contado - $esperado), 'egreso' => max(0, $esperado - $contado)]);
        }
        AuditLog::registrar('editar', $turno, "Cerró turno en {$turno->cuenta->nombre}: esperado $ " . number_format($esperado, 2, ',', '.') . ", contado $ " . number_format($contado, 2, ',', '.'));
        return $turno->fresh();
    }
}
