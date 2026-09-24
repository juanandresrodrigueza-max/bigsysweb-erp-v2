<?php

namespace App\Services\Fondos;

use App\Models\Asiento;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Cotizacion;
use App\Models\CuentaFondos;
use App\Services\Contabilidad\ContabilidadService;
use Illuminate\Support\Facades\DB;

// Moneda extranjera: cuentas en dólares, cobros y pagos en dólares, facturas en dólares y revaluación de la tenencia (diferencia de cambio).
class MonedaService
{
    public function __construct(private ContabilidadService $conta) {}

    public function cotizaciones(Business $b): array
    {
        $out = [];
        foreach (Cotizacion::TIPOS as $k => $label) { $c = Cotizacion::actual($b->id, $k); $out[$k] = ['label' => $label, 'compra' => (float) ($c?->compra ?? 0), 'venta' => (float) ($c?->venta ?? 0), 'fecha' => $c?->fecha?->format('d/m/Y'), 'fuente' => $c?->fuente, 'propia' => $c?->business_id === $b->id]; }
        return $out;
    }

    public function cuentasUsd(Business $b)
    {
        return CuentaFondos::where('activa', true)->where('moneda', 'USD')->orderBy('tipo')->orderBy('nombre')->get();
    }

    // Posición: tenencia en dólares valuada a la cotización de hoy, y lo que hay por cobrar y pagar en dólares.
    public function posicion(Business $b): array
    {
        $cot = Cotizacion::valor($b->id);
        $cuentas = $this->cuentasUsd($b)->map(fn($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'tipo' => $c->tipo, 'saldo_usd' => (float) $c->saldo, 'cotizacion_cierre' => (float) ($c->cotizacion_cierre ?: 0), 'valuacion' => round((float) $c->saldo * $cot, 2), 'dif_pendiente' => $c->cotizacion_cierre ? round((float) $c->saldo * ($cot - (float) $c->cotizacion_cierre), 2) : 0]);
        $porCobrar = Comprobante::where('direccion', 'venta')->where('estado', 'emitido')->where('moneda', '!=', 'ARS')->where('saldo', '>', 0.005)->get();
        $porPagar = Comprobante::where('direccion', 'compra')->where('estado', 'emitido')->where('moneda', '!=', 'ARS')->where('saldo', '>', 0.005)->get();
        $usd = fn($c) => (float) $c->cotizacion > 0 ? round((float) $c->saldo / (float) $c->cotizacion, 2) : 0;
        return [
            'cotizacion' => $cot, 'cuentas' => $cuentas->values()->all(), 'tenencia_usd' => round($cuentas->sum('saldo_usd'), 2), 'tenencia_ars' => round($cuentas->sum('valuacion'), 2), 'dif_pendiente' => round($cuentas->sum('dif_pendiente'), 2),
            'por_cobrar' => $porCobrar->map(fn($c) => ['id' => $c->id, 'numero' => $c->nombreTipo() . ' ' . $c->numeroFormateado(), 'cliente' => $c->contact?->name, 'fecha' => $c->fecha->format('d/m/Y'), 'cotizacion' => (float) $c->cotizacion, 'total_me' => (float) $c->total_me, 'saldo_me' => $usd($c), 'saldo' => (float) $c->saldo])->values()->all(),
            'por_pagar' => $porPagar->map(fn($c) => ['id' => $c->id, 'numero' => $c->nombreTipo() . ' ' . $c->numeroFormateado(), 'proveedor' => $c->contact?->name, 'fecha' => $c->fecha->format('d/m/Y'), 'cotizacion' => (float) $c->cotizacion, 'total_me' => (float) $c->total_me, 'saldo_me' => $usd($c), 'saldo' => (float) $c->saldo])->values()->all(),
            'por_cobrar_usd' => round($porCobrar->sum($usd), 2), 'por_pagar_usd' => round($porPagar->sum($usd), 2),
            'ultimas' => Asiento::where('origen', 'dif_cambio')->orderByDesc('fecha')->orderByDesc('id')->limit(8)->get()->map(fn($a) => ['id' => $a->id, 'fecha' => $a->fecha->format('d/m/Y'), 'concepto' => $a->concepto, 'total' => (float) $a->total])->all(),
        ];
    }

    // Revalúa la tenencia en dólares a la cotización dada: la diferencia contra la última valuación es diferencia de cambio (positiva o negativa).
    public function revaluar(Business $b, float $cotizacion, ?string $fecha = null): array
    {
        $fecha ??= today()->toDateString();
        return DB::transaction(function () use ($b, $cotizacion, $fecha) {
            $total = 0; $n = 0;
            foreach ($this->cuentasUsd($b) as $c) {
                $anterior = (float) ($c->cotizacion_cierre ?: 0);
                if ($anterior <= 0) { $c->update(['cotizacion_cierre' => $cotizacion]); continue; } // primera valuación: fija la base
                $dif = round((float) $c->saldo * ($cotizacion - $anterior), 2);
                $c->update(['cotizacion_cierre' => $cotizacion]);
                if (abs($dif) < 0.005) continue;
                $clave = $this->conta->claveDeCuentaFondos($c->id);
                $lineas = $dif > 0 ? [['clave' => $clave, 'debe' => $dif, 'haber' => 0, 'detalle' => "{$c->nombre} · " . number_format((float) $c->saldo, 2, ',', '.') . " USD"], ['clave' => 'dif_cambio', 'debe' => 0, 'haber' => $dif, 'detalle' => "Cotización {$anterior} → {$cotizacion}"]]
                    : [['clave' => 'dif_cambio_neg', 'debe' => -$dif, 'haber' => 0, 'detalle' => "Cotización {$anterior} → {$cotizacion}"], ['clave' => $clave, 'debe' => 0, 'haber' => -$dif, 'detalle' => "{$c->nombre} · " . number_format((float) $c->saldo, 2, ',', '.') . " USD"]];
                $this->conta->asientoPorClaves($b->id, $c->business_location_id, $fecha, "Diferencia de cambio · {$c->nombre}", 'dif_cambio', null, $lineas);
                $total += $dif; $n++;
            }
            AuditLog::registrar('editar', null, "Revaluó la tenencia en dólares a {$cotizacion}: diferencia de cambio $ " . number_format($total, 2, ',', '.'));
            return ['n' => $n, 'total' => round($total, 2)];
        });
    }
}
