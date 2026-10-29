<?php

namespace App\Services\Comprobantes;

use App\Models\Acopio;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Product;

// Fase 35.6: separar lo que el cliente tiene para retirar (acopio: mercadería pagada o facturada que todavía está acá) de lo que debe.
// Por cliente: lo acopiado, lo retirado, lo pendiente en plata y en kilos por artículo, y la deuda de verdad. El stock comprometido
// por artículo, y los avisos: acopio en negativo, plata a favor que no es acopio, más de 6 meses sin retirar.
class AcopiosInformeService
{
    private const DEUDA = ['FA', 'FB', 'FC', 'FE', 'FX', 'NDA', 'NDB', 'NDC'];

    public function informe(): array
    {
        $acopios = Acopio::with(['contact:id,name,balance,mobile,phone', 'items.product:id,name,unit,stock', 'retiros', 'comprobante:id,tipo,punto_venta,numero,total,saldo,fecha'])->get();
        $clientes = []; $porArticulo = [];
        foreach ($acopios as $a) {
            $cid = $a->contact_id; $acop = 0; $ret = 0; $items = [];
            foreach ($a->items as $it) {
                $fac = (float) $it->cantidad_facturada; $r = (float) $it->cantidad_retirada; $pu = (float) $it->precio_congelado;
                $acop += $fac * $pu; $ret += $r * $pu;
                $items[] = ['articulo' => $it->descripcion, 'unidad' => $it->product?->unit ?? 'un', 'acopiado' => $fac, 'retirado' => $r, 'pendiente' => round($fac - $r, 3), 'negativo' => $r > $fac + 0.0005];
                if ($it->product_id && $a->estado !== 'cerrado') { $k = $it->product_id; $porArticulo[$k] ??= ['id' => $k, 'articulo' => $it->product?->name ?? $it->descripcion, 'unidad' => $it->product?->unit ?? 'un', 'stock' => (float) ($it->product?->stock ?? 0), 'comprometido' => 0, 'retirado' => 0]; $porArticulo[$k]['comprometido'] += max(0, $fac - $r); $porArticulo[$k]['retirado'] += $r; }
            }
            $ultimo = $a->retiros->max('fecha') ?? $a->fecha; $meses = \Carbon\Carbon::parse($ultimo)->diffInMonths(today());
            $clientes[$cid] ??= ['id' => $cid, 'cliente' => $a->contact?->name, 'telefono' => $a->contact?->mobile ?: $a->contact?->phone, 'acopios' => [], 'acopiado' => 0, 'retirado' => 0, 'pendiente' => 0];
            $clientes[$cid]['acopios'][] = ['id' => $a->id, 'fecha' => $a->fecha?->format('d/m/Y'), 'factura' => $a->comprobante?->numeroFormateado(), 'estado' => $a->estado, 'acopiado' => round($acop, 2), 'retirado' => round($ret, 2), 'pendiente' => round($acop - $ret, 2), 'items' => $items, 'meses_sin_retirar' => $a->estado === 'cerrado' ? 0 : $meses, 'ultimo_retiro' => $a->retiros->max('fecha')?->format('d/m/Y')];
            $clientes[$cid]['acopiado'] += $acop; $clientes[$cid]['retirado'] += $ret; $clientes[$cid]['pendiente'] += $acop - $ret;
        }
        // Lo que debe de verdad: el saldo de sus facturas que NO son acopio (la factura del acopio ya se cobró o se debe aparte).
        $ids = array_keys($clientes);
        $deudaReal = Comprobante::ventas()->emitidos()->whereIn('tipo', self::DEUDA)->where('saldo', '>', 0.005)->whereIn('contact_id', $ids)->where('es_acopio', false)->selectRaw('contact_id, SUM(saldo) as s')->groupBy('contact_id')->pluck('s', 'contact_id');
        $deudaAcopio = Comprobante::ventas()->emitidos()->whereIn('tipo', self::DEUDA)->where('saldo', '>', 0.005)->whereIn('contact_id', $ids)->where('es_acopio', true)->selectRaw('contact_id, SUM(saldo) as s')->groupBy('contact_id')->pluck('s', 'contact_id');
        $avisos = [];
        foreach ($clientes as $cid => &$c) {
            $c['acopiado'] = round($c['acopiado'], 2); $c['retirado'] = round($c['retirado'], 2); $c['pendiente'] = round($c['pendiente'], 2);
            $c['debe'] = round((float) ($deudaReal[$cid] ?? 0), 2); $c['debe_acopio'] = round((float) ($deudaAcopio[$cid] ?? 0), 2);
            foreach ($c['acopios'] as $a) {
                if (collect($a['items'])->contains('negativo', true)) $avisos[] = ['nivel' => 'rojo', 'cliente' => $c['cliente'], 'contact_id' => $cid, 'texto' => "Acopio #{$a['id']} en negativo: se retiró más de lo acopiado."];
                if ($a['meses_sin_retirar'] >= 6) $avisos[] = ['nivel' => 'amarillo', 'cliente' => $c['cliente'], 'contact_id' => $cid, 'texto' => "Acopio #{$a['id']}: {$a['meses_sin_retirar']} meses sin retirar (" . number_format($a['pendiente'], 2, ',', '.') . ' pendientes).'];
            }
        }
        unset($c);
        // Plata a favor que no es acopio: clientes con saldo a favor en la cuenta corriente y sin acopio abierto (pagado sin facturar o recibo en la cuenta equivocada).
        $conAcopioAbierto = collect($clientes)->filter(fn($c) => $c['pendiente'] > 0.01)->keys()->all();
        foreach (Contact::customers()->where('balance', '<', -0.01)->whereNotIn('id', $conAcopioAbierto)->orderBy('balance')->limit(50)->get(['id', 'name', 'balance']) as $x)
            $avisos[] = ['nivel' => 'naranja', 'cliente' => $x->name, 'contact_id' => $x->id, 'texto' => 'Tiene $ ' . number_format(-(float) $x->balance, 2, ',', '.') . ' a favor y no es acopio: ¿pagó sin facturar o el recibo fue a la cuenta equivocada?'];
        $articulos = collect($porArticulo)->filter(fn($a) => $a['comprometido'] > 0.0005)->map(fn($a) => $a + ['disponible' => round($a['stock'] - $a['comprometido'], 3), 'falta' => $a['stock'] < $a['comprometido']])->sortByDesc('comprometido')->values()->all();
        $lista = collect($clientes)->sortByDesc('pendiente')->values()->all();
        return ['clientes' => $lista, 'articulos' => $articulos, 'avisos' => collect($avisos)->sortBy(fn($a) => ['rojo' => 0, 'naranja' => 1, 'amarillo' => 2][$a['nivel']])->values()->all(),
            'totales' => ['pendiente' => round(collect($lista)->sum('pendiente'), 2), 'clientes' => count(array_filter($lista, fn($c) => $c['pendiente'] > 0.01)), 'debe' => round(collect($lista)->sum('debe'), 2), 'articulos_con_faltante' => count(array_filter($articulos, fn($a) => $a['falta']))]];
    }
}
