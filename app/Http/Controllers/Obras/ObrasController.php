<?php

namespace App\Http\Controllers\Obras;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Empleado;
use App\Models\Product;
use App\Models\Proyecto;
use App\Models\ProyectoParte;
use App\Models\User;
use App\Services\Obras\ObrasService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Obras y proyectos con costeo y certificación de avance.
class ObrasController extends Controller
{
    public function index(Request $request, ObrasService $svc)
    {
        $ps = Proyecto::with('contact:id,name', 'responsable:id,name')->when($request->estado, fn($q, $e) => $q->where('estado', $e), fn($q) => $q->whereNotIn('estado', ['terminado', 'cancelado']))->orderByDesc('id')->get();
        $filas = $ps->map(function ($p) use ($svc) { $r = $svc->resumen($p); return ['id' => $p->id, 'codigo' => $p->codigo, 'nombre' => $p->nombre, 'cliente' => $p->contact?->name, 'responsable' => $p->responsable?->name, 'estado' => $p->estado, 'avance' => (float) $p->avance, 'avance_certificado' => (float) $p->avance_certificado, 'presupuesto_venta' => (float) $p->presupuesto_venta, 'presupuesto_costo' => (float) $p->presupuesto_costo, 'costo_real' => $r['costo_real'], 'facturado' => $r['facturado'], 'cobrado' => $r['cobrado'], 'margen_proyectado' => $r['margen_proyectado'], 'desvio' => $r['desvio_costo'], 'fin_prevista' => $p->fecha_fin_prevista?->format('d/m/Y'), 'atrasada' => $p->fecha_fin_prevista && $p->fecha_fin_prevista->isPast() && ! in_array($p->estado, ['terminado', 'cancelado'], true)]; });
        return Inertia::render('Obras/Index', [
            'obras' => $filas, 'estados' => Proyecto::ESTADOS, 'filtros' => $request->only('estado'),
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'usuarios' => User::where('business_id', $request->user()->business_id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'kpis' => ['en_curso' => $ps->where('estado', 'en_curso')->count(), 'cartera' => (float) $ps->sum('presupuesto_venta'), 'por_certificar' => round($filas->sum(fn($f) => max(0, $f['avance'] - $f['avance_certificado']) * $f['presupuesto_venta'] / 100), 2), 'desvio' => round($filas->sum('desvio'), 2)],
        ]);
    }

    public function ver(Request $request, int $id, ObrasService $svc)
    {
        $p = Proyecto::with('contact:id,name,phone', 'responsable:id,name')->findOrFail($id);
        $r = $svc->resumen($p);
        return Inertia::render('Obras/Ver', [
            'obra' => ['id' => $p->id, 'codigo' => $p->codigo, 'nombre' => $p->nombre, 'descripcion' => $p->descripcion, 'direccion' => $p->direccion, 'contact_id' => $p->contact_id, 'cliente' => $p->contact?->name, 'responsable_id' => $p->responsable_id, 'responsable' => $p->responsable?->name, 'estado' => $p->estado, 'fecha_inicio' => $p->fecha_inicio?->toDateString(), 'fecha_fin_prevista' => $p->fecha_fin_prevista?->toDateString(), 'fecha_fin' => $p->fecha_fin?->toDateString(), 'presupuesto_venta' => (float) $p->presupuesto_venta, 'presupuesto_costo' => (float) $p->presupuesto_costo, 'avance' => (float) $p->avance, 'avance_certificado' => (float) $p->avance_certificado, 'notas' => $p->notas],
            'resumen' => $r, 'estados' => Proyecto::ESTADOS, 'tiposParte' => Proyecto::TIPOS_PARTE,
            'partes' => $p->partes()->with('user:id,name', 'empleado:id,nombre')->orderByDesc('fecha')->orderByDesc('id')->get()->map(fn($x) => ['id' => $x->id, 'fecha' => $x->fecha->format('d/m/Y'), 'tipo' => $x->tipo, 'descripcion' => $x->descripcion, 'cantidad' => (float) $x->cantidad, 'unidad' => $x->unidad, 'costo_unit' => (float) $x->costo_unit, 'total' => (float) $x->total, 'usuario' => $x->user?->name, 'empleado' => $x->empleado?->nombre, 'stock' => (bool) $x->stock_movement_id]),
            'productos' => Product::where('active', true)->orderBy('name')->limit(500)->get()->map(fn($x) => ['id' => $x->id, 'name' => $x->name, 'sku' => $x->sku, 'unit' => $x->unit, 'cost' => (float) $x->cost, 'stock' => (float) $x->stock, 'tipo' => $x->tipo]),
            'empleados' => Empleado::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'sueldo_basico']),
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'usuarios' => User::where('business_id', $request->user()->business_id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'comprasSinObra' => Comprobante::where('direccion', 'compra')->where('estado', 'emitido')->whereNull('proyecto_id')->orderByDesc('fecha')->limit(30)->get()->map(fn($c) => ['id' => $c->id, 'label' => $c->nombreTipo() . ' ' . $c->numeroFormateado() . ' · ' . ($c->contact?->name ?? '') . ' · $ ' . number_format((float) $c->total, 0, ',', '.')]),
        ]);
    }

    public function guardar(Request $request, ObrasService $svc, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:150', 'descripcion' => 'nullable|string|max:2000', 'direccion' => 'nullable|string|max:200', 'contact_id' => 'nullable|integer', 'responsable_id' => 'nullable|integer', 'estado' => 'nullable|in:' . implode(',', array_keys(Proyecto::ESTADOS)), 'fecha_inicio' => 'nullable|date', 'fecha_fin_prevista' => 'nullable|date', 'fecha_fin' => 'nullable|date', 'presupuesto_venta' => 'nullable|numeric|min:0', 'presupuesto_costo' => 'nullable|numeric|min:0', 'avance' => 'nullable|numeric|min:0|max:100', 'notas' => 'nullable|string|max:2000']);
        $p = $svc->guardar($d, $id ? Proyecto::findOrFail($id) : null);
        return $id ? back()->with('success', 'Obra actualizada.') : redirect("/obras/{$p->id}")->with('success', "Obra {$p->codigo} creada.");
    }

    public function parte(Request $request, int $id, ObrasService $svc)
    {
        $d = $request->validate(['fecha' => 'nullable|date', 'tipo' => 'required|in:' . implode(',', array_keys(Proyecto::TIPOS_PARTE)), 'descripcion' => 'nullable|string|max:200', 'product_id' => 'nullable|integer', 'empleado_id' => 'nullable|integer', 'cantidad' => 'required|numeric|gt:0', 'unidad' => 'nullable|string|max:10', 'costo_unit' => 'nullable|numeric|min:0']);
        $parte = $svc->parte(Proyecto::findOrFail($id), $d);
        return back()->with('success', 'Parte cargado: ' . $parte->descripcion . ' $ ' . number_format((float) $parte->total, 2, ',', '.') . ($parte->stock_movement_id ? ' (salió del stock).' : '.'));
    }

    public function borrarParte(int $id, int $parte, ObrasService $svc)
    {
        $svc->borrarParte(ProyectoParte::where('proyecto_id', $id)->findOrFail($parte));
        return back()->with('success', 'Parte eliminado.');
    }

    public function certificar(Request $request, int $id, ObrasService $svc)
    {
        $d = $request->validate(['avance' => 'required|numeric|min:0.01|max:100', 'condicion' => 'nullable|in:contado,cta_cte']);
        $c = $svc->certificar(Proyecto::findOrFail($id), (float) $d['avance'], $d['condicion'] ?? 'cta_cte');
        return redirect("/comprobantes/{$c->id}")->with('success', "Certificado emitido: {$c->nombreTipo()} {$c->numeroFormateado()}.");
    }

    public function vincular(Request $request, int $id, ObrasService $svc)
    {
        $d = $request->validate(['comprobante_id' => 'required|integer']);
        $svc->vincular(Proyecto::findOrFail($id), Comprobante::findOrFail($d['comprobante_id']));
        return back()->with('success', 'Comprobante imputado a la obra.');
    }
}
