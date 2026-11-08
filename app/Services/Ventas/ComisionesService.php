<?php

namespace App\Services\Ventas;

use App\Models\Business;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Vendedor;
use Illuminate\Support\Carbon;

// Fase 74: la base de comisiones de cada vendedor con lo REAL del ERP (facturado menos notas de crédito, y cobrado), con el mismo criterio
// que el reporte del vendedor. La usan el ERP (Bigcito, estadísticas) y el CRM (su módulo de comisiones liquida sobre esto, no sobre el
// valor que el vendedor cargó en la oportunidad).
class ComisionesService
{
    private const FACTURAS = ['FA', 'FB', 'FC', 'FE'];
    private const CON_NC = ['FA', 'FB', 'FC', 'FE', 'NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC'];

    public function base(Business $b, Carbon $d, Carbon $h, bool $detalle = true): array
    {
        $vendedores = Vendedor::withoutGlobalScopes()->where('business_id', $b->id)->where('activo', true)->orderBy('nombre')->get();
        $ventas = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->ventas()->emitidos()->whereIn('tipo', self::CON_NC)->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->whereNotNull('vendedor_id')->with('contact:id,name,crm_external_id')->get();
        $cobros = Cobro::withoutGlobalScopes()->where('business_id', $b->id)->where('estado', '!=', 'anulado')->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->with('contact:id,name,crm_external_id')->get();
        $out = [];
        foreach ($vendedores as $v) {
            $mias = $ventas->where('vendedor_id', $v->id);
            $cob = $cobros->filter(fn($c) => $c->cobrador_id ? $c->cobrador_id === $v->id : $c->vendedor_id === $v->id);
            $facturado = round($mias->filter(fn($c) => in_array($c->tipo, self::FACTURAS, true) || str_starts_with($c->tipo, 'ND'))->sum('total'), 2);
            $notas = round($mias->filter(fn($c) => $c->esNotaCredito())->sum('total'), 2);
            $neto = round($facturado - $notas, 2); $cobrado = round($cob->sum('total'), 2);
            $porVentas = round($neto * (float) $v->comision_venta / 100, 2); $porCobros = round($cobrado * (float) $v->comision_cobro / 100, 2);
            $fila = ['vendedor_id' => $v->id, 'nombre' => $v->nombre, 'email' => $v->email ?: $v->user?->email, 'comision_venta_pct' => (float) $v->comision_venta, 'comision_cobro_pct' => (float) $v->comision_cobro,
                'facturado' => $facturado, 'notas_credito' => $notas, 'vendido' => $neto, 'comprobantes' => $mias->count(), 'cobrado' => $cobrado, 'cobros' => $cob->count(),
                'comision_por_ventas' => $porVentas, 'comision_por_cobros' => $porCobros, 'comision_total' => round($porVentas + $porCobros, 2)];
            if ($detalle) {
                $fila['detalle_comprobantes'] = $mias->sortBy('fecha')->values()->map(fn($c) => ['id' => $c->id, 'tipo' => $c->tipo, 'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha?->toDateString(), 'total' => ($c->esNotaCredito() ? -1 : 1) * (float) $c->total, 'saldo' => (float) $c->saldo, 'crm_quote_id' => $c->crm_quote_id,
                    'cliente' => $c->contact ? ['id' => $c->contact->id, 'nombre' => $c->contact->name, 'crm_contact_id' => $c->contact->crm_external_id ? (int) $c->contact->crm_external_id : null] : null])->all();
                $fila['detalle_cobros'] = $cob->sortBy('fecha')->values()->map(fn($c) => ['id' => $c->id, 'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha?->toDateString(), 'total' => (float) $c->total,
                    'cliente' => $c->contact ? ['id' => $c->contact->id, 'nombre' => $c->contact->name, 'crm_contact_id' => $c->contact->crm_external_id ? (int) $c->contact->crm_external_id : null] : null])->all();
            }
            $out[] = $fila;
        }
        $sinVendedor = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->ventas()->emitidos()->whereIn('tipo', self::FACTURAS)->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->whereNull('vendedor_id')->count();
        return ['desde' => $d->toDateString(), 'hasta' => $h->toDateString(), 'criterio' => 'Comisión por ventas sobre lo facturado menos notas de crédito (con IVA) de los comprobantes del vendedor; por cobros sobre los recibos que cobró (o de sus clientes, si no hay cobrador). Mismo criterio que el reporte del vendedor en el ERP.',
            'vendedores' => $out, 'total_comisiones' => round(array_sum(array_column($out, 'comision_total')), 2), 'facturas_sin_vendedor' => $sinVendedor];
    }
}
