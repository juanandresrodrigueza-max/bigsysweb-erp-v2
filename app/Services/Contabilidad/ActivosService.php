<?php

namespace App\Services\Contabilidad;

use App\Models\ActivoFijo;
use App\Models\AuditLog;
use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Bienes de uso: alta, amortización mensual lineal y baja o venta, con sus asientos.
class ActivosService
{
    public function __construct(private ContabilidadService $conta) {}

    public function alta(array $d, string $origen = 'ninguno', ?int $contactId = null): ActivoFijo
    {
        return DB::transaction(function () use ($d, $origen, $contactId) {
            $u = Auth::user();
            $a = ActivoFijo::create(['business_id' => $u->business_id, 'business_location_id' => $d['business_location_id'] ?? $u->current_location_id] + $d);
            // Alta contable: si vino por factura de compra ya está en gastos; si es aporte, contra capital; si es compra sin factura, contra proveedores.
            if ($origen === 'aporte') $this->conta->asientoPorClaves($a->business_id, $a->business_location_id, $a->fecha_alta, "Alta bien de uso: {$a->nombre} (aporte)", 'activo_alta', $a->id, [['clave' => 'bienes_uso', 'debe' => (float) $a->valor_origen, 'haber' => 0, 'detalle' => $a->nombre], ['clave' => 'capital', 'debe' => 0, 'haber' => (float) $a->valor_origen, 'detalle' => 'Aporte de capital']]);
            elseif ($origen === 'compra') $this->conta->asientoPorClaves($a->business_id, $a->business_location_id, $a->fecha_alta, "Alta bien de uso: {$a->nombre}", 'activo_alta', $a->id, [['clave' => 'bienes_uso', 'debe' => (float) $a->valor_origen, 'haber' => 0, 'detalle' => $a->nombre], ['clave' => 'proveedores', 'debe' => 0, 'haber' => (float) $a->valor_origen, 'detalle' => 'Compra de bien de uso', 'contact_id' => $contactId]]);
            elseif ($origen === 'reclasificar' && $a->comprobante_id) $this->conta->asientoPorClaves($a->business_id, $a->business_location_id, $a->fecha_alta, "Reclasificación a bienes de uso: {$a->nombre}", 'activo_alta', $a->id, [['clave' => 'bienes_uso', 'debe' => (float) $a->valor_origen, 'haber' => 0, 'detalle' => $a->nombre], ['clave' => 'compras_gastos', 'debe' => 0, 'haber' => (float) $a->valor_origen, 'detalle' => 'Sale del gasto']]);
            AuditLog::registrar('crear', $a, "Alta de bien de uso {$a->nombre} por $ " . number_format((float) $a->valor_origen, 2, ',', '.'));
            return $a;
        });
    }

    // Amortiza todos los bienes activos de un período (YYYY-MM). Un solo asiento por período; idempotente por bien.
    public function amortizar(Business $b, string $periodo): array
    {
        $fin = Carbon::parse($periodo . '-01')->endOfMonth();
        return DB::transaction(function () use ($b, $periodo, $fin) {
            $lineas = []; $n = 0; $total = 0; $porBien = [];
            foreach (ActivoFijo::where('estado', 'activo')->where('fecha_alta', '<=', $fin)->get() as $a) {
                if ($a->amortizaciones()->where('periodo', $periodo)->exists()) continue;
                $monto = min($a->cuotaMensual(), $a->amortizable());
                if ($monto <= 0.005) continue;
                $a->amortizaciones()->create(['periodo' => $periodo, 'monto' => $monto]);
                $a->increment('amortizado', $monto);
                $porBien[] = $a; $total += $monto; $n++;
            }
            if (! $n) return ['n' => 0, 'total' => 0];
            $lineas[] = ['clave' => 'amortizaciones', 'debe' => round($total, 2), 'haber' => 0, 'detalle' => "Amortización {$periodo} ({$n} bienes)"];
            $lineas[] = ['clave' => 'amort_acum', 'debe' => 0, 'haber' => round($total, 2), 'detalle' => 'Amortización acumulada'];
            $as = $this->conta->asientoPorClaves($b->id, null, $fin->toDateString(), "Amortización de bienes de uso " . ucfirst($fin->locale('es')->isoFormat('MMMM YYYY')), 'amortizacion', (int) str_replace('-', '', $periodo), $lineas);
            foreach ($porBien as $a) $a->amortizaciones()->where('periodo', $periodo)->update(['asiento_id' => $as?->id]);
            AuditLog::registrar('crear', $as, "Amortizó {$n} bienes por $ " . number_format($total, 2, ',', '.') . " ({$periodo})");
            return ['n' => $n, 'total' => round($total, 2), 'asiento_id' => $as?->id];
        });
    }

    // Baja o venta: saca el bien y su amortización acumulada; la diferencia contra el valor de venta es resultado.
    public function baja(ActivoFijo $a, string $fecha, float $valorVenta = 0, ?string $motivo = null): ActivoFijo
    {
        abort_if($a->estado !== 'activo', 422, 'El bien ya fue dado de baja.');
        return DB::transaction(function () use ($a, $fecha, $valorVenta, $motivo) {
            $residual = $a->valorResidualContable();
            $resultado = round($valorVenta - $residual, 2); // >0 ganancia, <0 pérdida
            $lineas = [['clave' => 'amort_acum', 'debe' => (float) $a->amortizado, 'haber' => 0, 'detalle' => 'Amortización acumulada del bien'], ['clave' => 'bienes_uso', 'debe' => 0, 'haber' => (float) $a->valor_origen, 'detalle' => $a->nombre]];
            if ($valorVenta > 0) $lineas[] = ['clave' => 'deudores', 'debe' => $valorVenta, 'haber' => 0, 'detalle' => 'Venta del bien (facturar aparte)'];
            if ($resultado < 0) $lineas[] = ['clave' => 'resultado_bienes_uso', 'debe' => -$resultado, 'haber' => 0, 'detalle' => 'Pérdida por baja'];
            elseif ($resultado > 0) $lineas[] = ['clave' => 'otros_ingresos', 'debe' => 0, 'haber' => $resultado, 'detalle' => 'Ganancia por venta'];
            $this->conta->asientoPorClaves($a->business_id, $a->business_location_id, $fecha, ($valorVenta > 0 ? 'Venta' : 'Baja') . " de bien de uso: {$a->nombre}", 'activo_baja', $a->id, $lineas);
            $a->update(['estado' => $valorVenta > 0 ? 'vendido' : 'baja', 'fecha_baja' => $fecha, 'valor_baja' => $valorVenta, 'notas' => trim(($a->notas ?? '') . "\n" . ($motivo ?? ''))]);
            AuditLog::registrar('anular', $a, ($valorVenta > 0 ? 'Vendió' : 'Dio de baja') . " el bien {$a->nombre}");
            return $a->fresh();
        });
    }

    public function resumen(Business $b): array
    {
        $act = ActivoFijo::where('estado', 'activo')->get();
        $mes = today()->format('Y-m');
        return ['bienes' => $act->count(), 'valor_origen' => round((float) $act->sum('valor_origen'), 2), 'amortizado' => round((float) $act->sum('amortizado'), 2), 'residual' => round((float) $act->sum(fn($a) => $a->valorResidualContable()), 2), 'cuota_mensual' => round((float) $act->sum(fn($a) => min($a->cuotaMensual(), $a->amortizable())), 2), 'mes_amortizado' => $act->count() > 0 && $act->every(fn($a) => $a->amortizable() <= 0.005 || $a->amortizaciones()->where('periodo', $mes)->exists())];
    }
}
