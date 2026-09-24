<?php

namespace App\Http\Controllers\Comprobantes;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\Contact;
use App\Models\Product;
use App\Services\Ventas\AbonosService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AbonosController extends Controller
{
    public function __construct(private AbonosService $service) {}

    public function index()
    {
        return Inertia::render('Comprobantes/Abonos', [
            'abonos' => Abono::with('contact:id,name')->withCount('comprobantes')->orderByDesc('activo')->orderBy('proximo')->get()->map(fn($a) => ['id' => $a->id, 'contact_id' => $a->contact_id, 'cliente' => $a->contact?->name, 'descripcion' => $a->descripcion, 'items' => $a->items, 'condicion' => $a->condicion, 'frecuencia' => $a->frecuencia, 'dia_emision' => $a->dia_emision, 'desde' => $a->desde->toDateString(), 'hasta' => $a->hasta?->toDateString(), 'meses_excluidos' => $a->meses_excluidos ?? [], 'emitir_auto' => $a->emitir_auto, 'activo' => $a->activo, 'cuota_actual' => $a->cuota_actual, 'proximo' => $a->proximo?->format('d/m/Y'), 'vence' => $a->activo && $a->proximo && $a->proximo->lte(today()), 'importe' => $a->importe(), 'emitidos' => $a->comprobantes_count, 'notas' => $a->notas]),
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'productos' => Product::where('active', true)->orderBy('name')->get(['id', 'name', 'price', 'iva', 'unit']),
            'frecuencias' => array_keys(Abono::FRECUENCIAS),
            'mrr' => (float) Abono::where('activo', true)->get()->sum(fn($a) => $a->importe() / (Abono::FRECUENCIAS[$a->frecuencia] ?? 1)),
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $d = $request->validate(['contact_id' => 'required|exists:contacts,id', 'descripcion' => 'required|string|max:120', 'items' => 'required|array|min:1', 'items.*.product_id' => 'nullable|integer', 'items.*.descripcion' => 'nullable|string|max:150', 'items.*.cantidad' => 'required|numeric|min:0', 'items.*.precio_unit' => 'required|numeric|min:0', 'items.*.alicuota_iva' => 'nullable|numeric',
            'condicion' => 'nullable|in:contado,cta_cte', 'frecuencia' => 'required|in:' . implode(',', array_keys(Abono::FRECUENCIAS)), 'dia_emision' => 'required|integer|min:1|max:28', 'desde' => 'required|date', 'hasta' => 'nullable|date', 'meses_excluidos' => 'nullable|array', 'emitir_auto' => 'boolean', 'activo' => 'boolean', 'notas' => 'nullable|string|max:500']);
        $a = $this->service->guardar($d, $id ? Abono::findOrFail($id) : null);
        return back()->with('success', "Abono \"{$a->descripcion}\" guardado. Próxima emisión: " . ($a->proximo?->format('d/m/Y') ?? 'sin fechas pendientes') . '.');
    }

    public function emitir(int $id)
    {
        $c = $this->service->emitir(Abono::findOrFail($id), true);
        return back()->with('success', $c ? "Generado {$c->nombreTipo()} " . ($c->numeroFormateado() ?? '(borrador)') . '.' : 'El abono está inactivo.');
    }

    public function emitirVencidos()
    {
        $n = $this->service->emitirVencidos();
        return back()->with('success', "Se generaron {$n} facturas de abonos vencidos.");
    }
}
