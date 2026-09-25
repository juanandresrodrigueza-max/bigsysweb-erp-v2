<?php

namespace App\Services\Fondos;

use App\Models\Comprobante;
use App\Models\Deposito;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockDeposito;
use App\Models\StockMovement;
use App\Models\TurnoCaja;
use App\Services\Stock\StockService;
use Illuminate\Support\Carbon;

// Cierre de turno con stock final: el cajero cuenta los artículos marcados "controlar en turno" y el sistema calcula
// cuánto salió por conteo (stock final del turno anterior + entradas − otras salidas − final contado), lo valoriza a
// precio de venta y lo compara con lo facturado y con la recaudación declarada por medio de pago.
class StockTurnoService
{
    public function deposito(TurnoCaja $t): ?Deposito
    {
        return Deposito::porDefecto($t->cuenta?->business_location_id);
    }

    // Planilla del turno (abierto): por artículo, inicial, entradas, otras salidas, facturado y el stock que espera el sistema.
    public function planilla(TurnoCaja $t, ?Carbon $hasta = null): array
    {
        $dep = $this->deposito($t);
        $hasta ??= now();
        $prods = Product::where('control_turno', true)->where('active', true)->where('controla_stock', true)->orderBy('name')->get();
        if ($prods->isEmpty() || ! $dep) return [];
        $anterior = TurnoCaja::where('cuenta_fondos_id', $t->cuenta_fondos_id)->whereNotNull('cierre')->where('id', '!=', $t->id)->whereNotNull('stock')->orderByDesc('cierre')->first();
        $finalesAnt = collect($anterior?->stock ?? [])->keyBy('product_id');
        $movs = StockMovement::where('deposito_id', $dep->id)->whereIn('product_id', $prods->pluck('id'))->where('created_at', '>=', $t->apertura)->where('created_at', '<=', $hasta)->get()->groupBy('product_id');
        $actual = StockDeposito::where('deposito_id', $dep->id)->whereIn('product_id', $prods->pluck('id'))->pluck('cantidad', 'product_id');
        $esVenta = Comprobante::whereIn('id', $movs->flatten()->where('movable_type', Comprobante::class)->pluck('movable_id')->unique())->where('direccion', 'venta')->pluck('id')->flip();
        $out = [];
        foreach ($prods as $p) {
            $entradas = 0.0; $otras = 0.0; $facturado = 0.0; $neto = 0.0;
            foreach ($movs[$p->id] ?? [] as $m) {
                $q = (float) $m->quantity * ((float) $m->stock_after >= (float) $m->stock_before ? 1 : -1);
                $neto += $q;
                $venta = $m->movable_type === Sale::class || ($m->movable_type === Comprobante::class && isset($esVenta[$m->movable_id]));
                if ($venta) $facturado -= $q;          // salida por venta (una nota de crédito la devuelve)
                elseif ($q > 0) $entradas += $q;
                else $otras -= $q;
            }
            $sistemaApertura = (float) ($actual[$p->id] ?? 0) - $neto;
            $prev = $finalesAnt->get($p->id);
            $inicial = $prev ? (float) $prev['final'] : $sistemaApertura;
            $out[] = ['product_id' => $p->id, 'nombre' => $p->name, 'unidad' => $p->unit, 'precio' => (float) $p->price, 'inicial' => round($inicial, 3), 'inicial_origen' => $prev ? 'turno anterior' : 'sistema',
                'entradas' => round($entradas, 3), 'otras_salidas' => round($otras, 3), 'facturado' => round($facturado, 3), 'esperado' => round($inicial + $entradas - $otras - $facturado, 3)];
        }
        return $out;
    }

    // Con los conteos arma la planilla final: salida por conteo, diferencia en unidades y en pesos, y la compara con la recaudación.
    public function resultado(array $planilla, array $conteos, float $recaudacion): array
    {
        $filas = []; $importe = 0.0;
        foreach ($planilla as $f) {
            if (! array_key_exists($f['product_id'], $conteos) || $conteos[$f['product_id']] === null || $conteos[$f['product_id']] === '') continue;
            $final = (float) $conteos[$f['product_id']];
            $salio = round($f['inicial'] + $f['entradas'] - $f['otras_salidas'] - $final, 3);
            $dif = round($salio - $f['facturado'], 3); // positivo: salió más de lo que se facturó (faltante)
            $filas[] = $f + ['final' => $final, 'salio' => $salio, 'diferencia' => $dif, 'importe' => round($salio * $f['precio'], 2), 'diferencia_importe' => round($dif * $f['precio'], 2)];
            $importe += $salio * $f['precio'];
        }
        $importe = round($importe, 2);
        $facturado = round(collect($filas)->sum(fn($f) => $f['facturado'] * $f['precio']), 2);
        return ['filas' => $filas, 'importe' => $importe, 'facturado' => $facturado, 'faltante' => round($importe - $facturado, 2), 'recaudacion' => round($recaudacion, 2), 'diferencia' => $filas ? round($recaudacion - $importe, 2) : null];
    }

    // Recaudación del turno por ventas: efectivo cobrado (corregido por la diferencia de caja) + lo declarado en los otros medios.
    public function recaudacion(TurnoCaja $t, float $difEfectivo, array $otros): float
    {
        $cobrado = (float) $t->movimientos()->where('origen', 'cobro')->sum('ingreso');
        return round($cobrado + $difEfectivo + array_sum(array_map('floatval', $otros)), 2);
    }

    // Al cerrar: guarda la planilla y, si se pide, lleva el stock del depósito a lo contado (ajuste con el turno como origen).
    public function cerrar(TurnoCaja $t, array $conteos, float $recaudacion, bool $ajustar = true): array
    {
        $r = $this->resultado($this->planilla($t), $conteos, $recaudacion);
        if (! $r['filas']) return $r;
        $dep = $this->deposito($t);
        if ($ajustar && $dep) {
            foreach ($r['filas'] as $f) if (abs($f['final'] - $f['esperado']) > 0.0005) app(StockService::class)->ajustar(Product::find($f['product_id']), $dep, $f['final'], "Conteo de cierre de turno #{$t->id}", 'ajuste', $t);
        }
        $t->forceFill(['stock' => $r['filas'], 'stock_importe' => $r['importe'], 'stock_diferencia' => $r['diferencia']])->save();
        return $r;
    }
}
