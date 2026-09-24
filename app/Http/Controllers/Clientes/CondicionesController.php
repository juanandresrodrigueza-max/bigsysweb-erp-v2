<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\PrecioPactado;
use App\Models\Product;
use App\Services\Ventas\CondicionesClienteService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Precios pactados y descuentos por rubro de un cliente (Fase 25.1).
class CondicionesController extends Controller
{
    public function __construct(private CondicionesClienteService $svc) {}

    // Lo que usa el formulario de venta al elegir el cliente.
    public function json(int $id)
    {
        return response()->json($this->svc->paraCliente(Contact::customers()->findOrFail($id)));
    }

    // Listado para la ficha del cliente.
    public function index(int $id)
    {
        $c = Contact::customers()->findOrFail($id);
        return response()->json(['condiciones' => PrecioPactado::where('contact_id', $c->id)->with('product:id,name,sku,price,prices,moneda', 'rubro')->orderByRaw('product_id IS NULL')->orderBy('id')->get()->map(fn($p) => [
            'id' => $p->id, 'product_id' => $p->product_id, 'rubro_id' => $p->rubro_id,
            'articulo' => $p->product?->name, 'sku' => $p->product?->sku, 'rubro' => $p->rubro?->nombreCompleto(),
            'precio' => $p->precio !== null ? (float) $p->precio : null, 'descuento' => $p->descuento !== null ? (float) $p->descuento : null,
            'precio_lista' => $p->product?->precioLista((int) ($c->lista_precios ?: 1)),
            'origen' => $p->origen, 'vigente_hasta' => $p->vigente_hasta?->toDateString(), 'vencida' => $p->vigente_hasta && $p->vigente_hasta->isPast(),
        ])]);
    }

    public function guardar(Request $request, int $id)
    {
        $c = Contact::customers()->findOrFail($id);
        $b = $request->user()->business_id;
        $d = $request->validate([
            'product_id' => ['nullable', 'required_without:rubro_id', Rule::exists('products', 'id')->where('business_id', $b)],
            'rubro_id' => ['nullable', 'required_without:product_id', Rule::exists('rubros', 'id')->where('business_id', $b)],
            'precio' => 'nullable|numeric|min:0', 'descuento' => 'nullable|numeric|min:0|max:100', 'vigente_hasta' => 'nullable|date',
        ]);
        if (! empty($d['product_id'])) $d['rubro_id'] = null;
        if (empty($d['product_id'])) $d['precio'] = null; // un rubro solo lleva descuento
        abort_if(($d['precio'] ?? null) === null && ($d['descuento'] ?? null) === null, 422, 'Cargá un precio o un descuento.');
        $p = PrecioPactado::updateOrCreate(
            ['contact_id' => $c->id, 'product_id' => $d['product_id'] ?? null, 'rubro_id' => $d['rubro_id'] ?? null],
            ['business_id' => $b, 'precio' => $d['precio'] ?? null, 'descuento' => $d['descuento'] ?? null, 'vigente_hasta' => $d['vigente_hasta'] ?? null, 'origen' => 'manual', 'user_id' => $request->user()->id],
        );
        $que = $p->product_id ? Product::find($p->product_id)?->name : 'rubro ' . $p->rubro?->nombre;
        AuditLog::registrar('editar', $c, "Condición para {$c->name}: {$que}" . ($p->precio !== null ? ' $ ' . number_format((float) $p->precio, 2, ',', '.') : '') . ($p->descuento !== null ? " {$p->descuento}% dto." : ''));
        return back()->with('success', 'Condición guardada.');
    }

    public function eliminar(int $id, int $condicion)
    {
        $c = Contact::customers()->findOrFail($id);
        PrecioPactado::where('contact_id', $c->id)->findOrFail($condicion)->delete();
        return back()->with('success', 'Condición eliminada.');
    }
}
