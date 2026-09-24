<?php

namespace App\Services\Comprobantes;

use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Vendedor;

// Liquidación de comisiones: % sobre lo facturado (neto, menos notas de crédito) y % sobre lo cobrado, por período.
class ComisionesService
{
    public function liquidar(string $desde, string $hasta, ?int $vendedorId = null): array
    {
        $vendedores = Vendedor::when($vendedorId, fn($q, $v) => $q->where('id', $v))->orderBy('nombre')->get();
        $out = [];
        foreach ($vendedores as $v) {
            $ventas = Comprobante::ventas()->emitidos()->where('vendedor_id', $v->id)->whereBetween('fecha', [$desde, $hasta])->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC'])->get();
            $facturado = round($ventas->sum(fn($c) => $c->def()['cc'] * (float) $c->neto), 2);
            // Comisión por cobranza: al cobrador del recibo; si no tiene cobrador, al vendedor.
            $cobros = Cobro::where(fn($q) => $q->where('cobrador_id', $v->id)->orWhere(fn($w) => $w->whereNull('cobrador_id')->where('vendedor_id', $v->id)))->where('estado', '!=', 'anulado')->whereBetween('fecha', [$desde, $hasta])->get();
            $cobrado = round($cobros->sum(fn($c) => (float) $c->total), 2);
            $comVenta = round($facturado * (float) $v->comision_venta / 100, 2);
            $comCobro = round($cobrado * (float) $v->comision_cobro / 100, 2);
            $out[] = [
                'id' => $v->id, 'nombre' => $v->nombre, 'comision_venta' => (float) $v->comision_venta, 'comision_cobro' => (float) $v->comision_cobro,
                'facturado' => $facturado, 'comprobantes' => $ventas->count(), 'cobrado' => $cobrado, 'cobros' => $cobros->count(),
                'com_venta' => $comVenta, 'com_cobro' => $comCobro, 'total' => round($comVenta + $comCobro, 2),
                'detalle_cobros' => $cobros->sortBy('fecha')->values()->map(fn($c) => ['fecha' => $c->fecha->format('d/m/Y'), 'numero' => $c->numeroFormateado(), 'cliente' => $c->contact?->name, 'total' => (float) $c->total, 'como' => $c->cobrador_id === $v->id ? 'cobrador' : 'vendedor', 'comision' => round((float) $c->total * (float) $v->comision_cobro / 100, 2)])->all(),
                'detalle' => $ventas->sortBy('fecha')->values()->map(fn($c) => ['fecha' => $c->fecha->format('d/m/Y'), 'tipo' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'cliente' => $c->contact?->name, 'neto' => $c->def()['cc'] * (float) $c->neto, 'comision' => round($c->def()['cc'] * (float) $c->neto * (float) $v->comision_venta / 100, 2)])->all(),
            ];
        }
        return $out;
    }
}
