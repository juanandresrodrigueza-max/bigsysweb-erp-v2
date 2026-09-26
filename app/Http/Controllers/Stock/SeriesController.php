<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Lote;
use App\Models\OrdenTrabajo;
use App\Services\Stock\LotesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 27.2: números de serie. Buscar un equipo, ver su historia (compra, venta, garantía, service) e ingresarlo a servicio técnico.
class SeriesController extends Controller
{
    private function fila(Lote $l): array
    {
        return ['id' => $l->id, 'serie' => $l->serie, 'producto' => $l->product?->name, 'sku' => $l->product?->sku, 'product_id' => $l->product_id, 'estado' => $l->cantidad > 0 ? ($l->estado === 'disponible' ? 'en_stock' : $l->estado) : ($l->cliente_id ? 'vendido' : 'sin_stock'),
            'deposito' => $l->deposito?->nombre, 'proveedor' => $l->proveedor?->name, 'ingreso' => $l->ingreso?->format('d/m/Y'), 'cliente' => $l->cliente?->name, 'cliente_id' => $l->cliente_id,
            'vendido_en' => $l->vendido_en?->format('d/m/Y'), 'comprobante' => $l->comprobanteVenta ? $l->comprobanteVenta->nombreTipo() . ' ' . $l->comprobanteVenta->numeroFormateado() : null, 'comprobante_id' => $l->comprobante_venta_id,
            'garantia_hasta' => $l->garantia_hasta?->format('d/m/Y'), 'en_garantia' => $l->garantia_hasta ? $l->garantia_hasta->gte(today()) : null];
    }

    public function index(Request $request)
    {
        $b = trim((string) $request->buscar);
        $q = Lote::with('product:id,name,sku', 'deposito:id,nombre', 'proveedor:id,name', 'cliente:id,name', 'comprobanteVenta')->whereNotNull('serie')
            ->when($request->estado === 'en_stock', fn($q) => $q->where('cantidad', '>', 0))->when($request->estado === 'vendidos', fn($q) => $q->where('cantidad', '<=', 0)->whereNotNull('cliente_id'))
            ->when($request->estado === 'garantia', fn($q) => $q->whereNotNull('garantia_hasta')->where('garantia_hasta', '>=', today()->toDateString()))
            ->when($b !== '', fn($q) => $q->where(fn($w) => $w->where('serie', 'like', "%{$b}%")->orWhereHas('product', fn($p) => $p->where('name', 'like', "%{$b}%")->orWhere('sku', $b))->orWhereHas('cliente', fn($c) => $c->where('name', 'like', "%{$b}%"))));
        return Inertia::render('Stock/Series', [
            'series' => $q->orderByDesc('id')->limit(300)->get()->map(fn($l) => $this->fila($l)),
            'filtros' => $request->only('buscar', 'estado'),
            'kpis' => ['en_stock' => Lote::whereNotNull('serie')->where('cantidad', '>', 0)->count(), 'vendidos' => Lote::whereNotNull('serie')->where('cantidad', '<=', 0)->whereNotNull('cliente_id')->count(), 'garantia' => Lote::whereNotNull('serie')->whereNotNull('garantia_hasta')->where('garantia_hasta', '>=', today()->toDateString())->count()],
        ]);
    }

    // Historia del equipo: trazabilidad del lote de esa serie y las órdenes de servicio técnico con la misma serie.
    public function ver(int $id, LotesService $svc)
    {
        $l = Lote::with('product:id,name,sku', 'deposito:id,nombre', 'proveedor:id,name', 'cliente:id,name', 'comprobanteVenta')->whereNotNull('serie')->findOrFail($id);
        return response()->json($this->fila($l) + ['movimientos' => $svc->trazabilidad($l)['movimientos'],
            'servicios' => OrdenTrabajo::where('serie', $l->serie)->orderByDesc('id')->get()->map(fn($o) => ['id' => $o->id, 'numero' => $o->numeroFormateado(), 'estado' => $o->estado, 'fecha' => $o->fecha_ingreso?->format('d/m/Y'), 'falla' => $o->falla])]);
    }

    // Ingreso a servicio técnico desde la serie: la orden sale con el cliente, el equipo y si está en garantía.
    public function servicio(Request $request, int $id)
    {
        $d = $request->validate(['falla' => 'required|string|max:2000']);
        $l = Lote::with('product', 'cliente')->whereNotNull('serie')->findOrFail($id);
        $garantia = $l->garantia_hasta && $l->garantia_hasta->gte(today());
        $ot = app(\App\Services\Servicios\OrdenesService::class)->crear(['contact_id' => $l->cliente_id, 'nombre' => $l->cliente?->name, 'telefono' => $l->cliente?->mobile ?: $l->cliente?->phone, 'equipo' => $l->product?->name ?? 'Equipo',
            'serie' => $l->serie, 'falla' => $d['falla'], 'notas' => $l->vendido_en ? "Vendido el {$l->vendido_en->format('d/m/Y')}" . ($l->comprobanteVenta ? " con {$l->comprobanteVenta->nombreTipo()} {$l->comprobanteVenta->numeroFormateado()}" : '') . ($l->garantia_hasta ? ($garantia ? " · EN GARANTÍA hasta {$l->garantia_hasta->format('d/m/Y')}" : " · garantía vencida el {$l->garantia_hasta->format('d/m/Y')}") : '') : null]);
        return redirect("/servicios/{$ot->id}")->with('success', "Orden {$ot->numeroFormateado()} creada" . ($garantia ? ' (en garantía).' : '.'));
    }
}
