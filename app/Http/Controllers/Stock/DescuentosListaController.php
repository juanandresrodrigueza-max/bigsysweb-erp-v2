<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DescuentoLista;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\Ventas\DescuentosListaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// Descuentos especiales por lista de precios (Fase 26.4).
class DescuentosListaController extends Controller
{
    public function index(Request $request)
    {
        $q = DescuentoLista::with('product:id,name,sku,price,prices,moneda', 'rubro')->when($request->lista, fn($q, $l) => $q->where('lista', $l))->orderBy('lista')->orderByRaw('product_id IS NULL')->orderBy('cantidad_minima')->get();
        return Inertia::render('Stock/DescuentosLista', [
            'reglas' => $q->map(fn($r) => ['id' => $r->id, 'lista' => $r->lista, 'product_id' => $r->product_id, 'rubro_id' => $r->rubro_id, 'articulo' => $r->product?->name, 'sku' => $r->product?->sku, 'rubro' => $r->rubro?->nombreCompleto(),
                'precio_lista' => $r->product?->precioLista($r->lista), 'cantidad_minima' => (float) $r->cantidad_minima, 'precio' => $r->precio !== null ? (float) $r->precio : null, 'descuento' => $r->descuento !== null ? (float) $r->descuento : null,
                'vigente_desde' => $r->vigente_desde?->toDateString(), 'vigente_hasta' => $r->vigente_hasta?->toDateString(), 'vencida' => $r->vigente_hasta && $r->vigente_hasta->lt(today())]),
            'rubros' => Rubro::with('parent')->get()->map(fn($r) => ['id' => $r->id, 'nombre' => $r->nombreCompleto()])->sortBy('nombre')->values(),
            'filtros' => $request->only('lista'),
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $b = $request->user()->business_id;
        $d = $request->validate([
            'lista' => 'required|integer|min:1|max:6',
            'product_id' => ['nullable', 'required_without:rubro_id', Rule::exists('products', 'id')->where('business_id', $b)],
            'rubro_id' => ['nullable', 'required_without:product_id', Rule::exists('rubros', 'id')->where('business_id', $b)],
            'cantidad_minima' => 'nullable|numeric|min:0', 'precio' => 'nullable|numeric|min:0', 'descuento' => 'nullable|numeric|min:-100|max:100',
            'vigente_desde' => 'nullable|date', 'vigente_hasta' => 'nullable|date|after_or_equal:vigente_desde',
        ]);
        if (! empty($d['product_id'])) $d['rubro_id'] = null; else $d['precio'] = null; // un rubro solo lleva %
        abort_if(($d['precio'] ?? null) === null && ($d['descuento'] ?? null) === null, 422, 'Cargá un precio especial o un descuento.');
        $d['cantidad_minima'] = $d['cantidad_minima'] ?? 0;
        $r = $id ? DescuentoLista::findOrFail($id) : new DescuentoLista(['business_id' => $b]);
        $r->fill($d + ['user_id' => $request->user()->id])->save();
        $que = $r->product_id ? Product::find($r->product_id)?->name : 'rubro ' . $r->rubro?->nombre;
        AuditLog::registrar($id ? 'editar' : 'crear', $r, "Descuento lista {$r->lista}: {$que}" . ((float) $r->cantidad_minima > 0 ? " desde {$r->cantidad_minima}" : '') . ($r->precio !== null ? ' $ ' . number_format((float) $r->precio, 2, ',', '.') : '') . ($r->descuento !== null ? " {$r->descuento}%" : ''));
        return back()->with('success', 'Descuento guardado.');
    }

    public function eliminar(int $id)
    {
        $r = DescuentoLista::findOrFail($id);
        AuditLog::registrar('eliminar', $r, "Quitó un descuento de la lista {$r->lista}");
        $r->delete();
        return back()->with('success', 'Descuento quitado.');
    }

    public function json(int $lista, DescuentosListaService $svc)
    {
        return response()->json($svc->paraLista(max(1, min(6, $lista))));
    }
}
