<?php

namespace App\Http\Controllers\Proveedores;

use App\Http\Controllers\Controller;
use App\Models\Comprobante;
use App\Models\Recepcion;
use App\Models\ReclamoCompra;
use App\Services\Compras\RecepcionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 31.5: el control de la recepción con la pistola (dentro de la factura) y los reclamos al proveedor (en Compras).
class RecepcionesController extends Controller
{
    public function __construct(private RecepcionService $service) {}

    public function show(Request $request, int $id)
    {
        $c = Comprobante::compras()->with(['items', 'adjuntos', 'contact'])->findOrFail($id);
        $r = $this->service->abrir($c, $request->boolean('nueva'));
        $foto = $c->adjuntos->first(fn($a) => $a->tipo === 'imagen' && $a->archivo);
        return Inertia::render('Proveedores/Recepcion', [
            'recepcion' => $this->service->estado($r),
            'foto' => $foto ? "/proveedores/compras/{$c->id}/adjunto/{$foto->id}" : null,
            'exigeControl' => (bool) ($this->service->config($request->user()->business)['exigir_control'] ?? false),
        ]);
    }

    public function adjunto(int $id, int $aid)
    {
        $c = Comprobante::compras()->findOrFail($id);
        $a = $c->adjuntos()->findOrFail($aid);
        abort_unless($a->archivo && \Illuminate\Support\Facades\Storage::disk('local')->exists($a->archivo), 404);
        return \Illuminate\Support\Facades\Storage::disk('local')->response($a->archivo);
    }

    private function rec(int $id): Recepcion { return Recepcion::with('items')->where('comprobante_id', $id)->orderByDesc('id')->firstOrFail(); }

    public function leer(Request $request, int $id)
    {
        $d = $request->validate(['codigo' => 'required|string|max:200', 'mult' => 'nullable|numeric|min:1|max:999']);
        $r = $this->rec($id);
        $res = $this->service->leer($r, $d['codigo'], (float) ($d['mult'] ?? 1));
        return response()->json($res + ['recepcion' => $this->service->estado($r->fresh('items'))]);
    }

    public function asignar(Request $request, int $id)
    {
        $d = $request->validate(['codigo' => 'required|string|max:200', 'item_id' => 'required|integer', 'cantidad' => 'nullable|numeric|min:0.001', 'aprender' => 'boolean']);
        $r = $this->rec($id);
        $res = $this->service->asignar($r, $d['codigo'], (int) $d['item_id'], (float) ($d['cantidad'] ?? 1), $request->boolean('aprender', true));
        return response()->json($res + ['recepcion' => $this->service->estado($r->fresh('items'))]);
    }

    public function contar(Request $request, int $id)
    {
        $d = $request->validate(['item_id' => 'required|integer', 'contado' => 'required|numeric|min:0']);
        $r = $this->rec($id);
        $this->service->contar($r, (int) $d['item_id'], (float) $d['contado']);
        return response()->json(['ok' => true, 'recepcion' => $this->service->estado($r->fresh('items'))]);
    }

    public function lote(Request $request, int $id)
    {
        $d = $request->validate(['item_id' => 'required|integer', 'lote' => 'nullable|string|max:60', 'vencimiento' => 'nullable|date']);
        $r = $this->rec($id);
        $this->service->lote($r, (int) $d['item_id'], $d['lote'] ?? null, $d['vencimiento'] ?? null);
        return response()->json(['ok' => true, 'recepcion' => $this->service->estado($r->fresh('items'))]);
    }

    public function agregar(Request $request, int $id)
    {
        $d = $request->validate(['product_id' => 'nullable|integer|exists:products,id', 'descripcion' => 'nullable|string|max:255', 'cantidad' => 'required|numeric|min:0.001']);
        $r = $this->rec($id);
        $this->service->agregar($r, $d['product_id'] ?? null, $d['descripcion'] ?? null, (float) $d['cantidad']);
        return response()->json(['ok' => true, 'recepcion' => $this->service->estado($r->fresh('items'))]);
    }

    public function quitar(Request $request, int $id)
    {
        $d = $request->validate(['item_id' => 'required|integer']);
        $r = $this->rec($id);
        $this->service->quitar($r, (int) $d['item_id']);
        return response()->json(['ok' => true, 'recepcion' => $this->service->estado($r->fresh('items'))]);
    }

    public function cerrar(Request $request, int $id)
    {
        $d = $request->validate(['resoluciones' => 'nullable|array', 'resoluciones.*' => 'in:contado,facturado,pendiente', 'todas' => 'nullable|in:contado,facturado,pendiente', 'reclamar' => 'boolean']);
        $r = $this->rec($id);
        $res = $this->service->cerrar($r, array_map('strval', $d['resoluciones'] ?? []), $d['todas'] ?? null, $request->boolean('reclamar', true));
        $msg = $res['reclamo'] ? 'Control terminado: quedó un reclamo al proveedor listo para mandar.' : 'Control terminado: llegó todo lo facturado.';
        return redirect("/proveedores/compras/{$id}")->with('success', $msg)->with('reclamo', $res['reclamo']?->id);
    }

    // Reclamos a proveedores: lo que faltó y se reclamó. Se cierran solos con la nota de crédito o a mano.
    public function reclamos(Request $request)
    {
        return response()->json($this->service->reclamos(! $request->boolean('todos')));
    }

    public function resolverReclamo(Request $request, int $id)
    {
        $d = $request->validate(['como' => 'required|string|max:255']);
        ReclamoCompra::abiertos()->findOrFail($id)->resolver($d['como'], $request->user()->id);
        return response()->json(['ok' => true]);
    }

    public function config(Request $request)
    {
        $d = $request->validate(['exigir_control' => 'required|boolean']);
        $b = $request->user()->business;
        $b->update(['compras_config' => array_replace($b->compras_config ?? [], $d)]);
        return back()->with('success', $d['exigir_control'] ? 'Desde ahora las compras con mercadería se controlan con la pistola antes de registrarse.' : 'El control con la pistola queda opcional.');
    }
}
