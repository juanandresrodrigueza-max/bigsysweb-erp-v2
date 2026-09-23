<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\PuntoMovimiento;
use App\Services\Ventas\FidelizacionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Programa de puntos: ranking de clientes, movimientos y canjes manuales.
class FidelizacionController extends Controller
{
    public function index(Request $request, FidelizacionService $fid)
    {
        $b = $request->user()->business;
        $cfg = $fid->config($b);
        return Inertia::render('Clientes/Fidelizacion', [
            'config' => $cfg,
            'ranking' => Contact::customers()->where('puntos', '>', 0)->orderByDesc('puntos')->limit(50)->get()->map(fn($c) => ['id' => $c->id, 'nombre' => $c->name, 'puntos' => (float) $c->puntos, 'pesos' => $fid->valorEnPesos($b, (float) $c->puntos), 'telefono' => $c->phone]),
            'movimientos' => PuntoMovimiento::with('contact:id,name')->latest()->limit(60)->get()->map(fn($m) => ['id' => $m->id, 'fecha' => $m->created_at->format('d/m/Y H:i'), 'cliente' => $m->contact?->name, 'contact_id' => $m->contact_id, 'puntos' => (float) $m->puntos, 'motivo' => $m->motivo, 'origen' => $m->origen]),
            'kpis' => ['clientes' => Contact::customers()->where('puntos', '>', 0)->count(), 'en_circulacion' => (float) Contact::customers()->sum('puntos'), 'canjeados' => (float) abs(PuntoMovimiento::where('puntos', '<', 0)->sum('puntos')), 'otorgados_mes' => (float) PuntoMovimiento::where('puntos', '>', 0)->where('created_at', '>=', now()->startOfMonth())->sum('puntos')],
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'puntos']),
        ]);
    }

    public function ajustar(Request $request, FidelizacionService $fid)
    {
        $d = $request->validate(['contact_id' => 'required|integer', 'puntos' => 'required|numeric', 'motivo' => 'required|string|max:150']);
        $c = Contact::findOrFail($d['contact_id']);
        if ($d['puntos'] < 0) { $pesos = $fid->canjear($c, abs((float) $d['puntos']), $d['motivo']); return back()->with('success', 'Canje registrado: ' . abs((float) $d['puntos']) . ' puntos = $ ' . number_format($pesos, 2, ',', '.') . ' de descuento para ' . $c->name . '.'); }
        $fid->mover($c, (float) $d['puntos'], $d['motivo'], 'ajuste');
        return back()->with('success', "{$d['puntos']} puntos sumados a {$c->name}.");
    }

    // Consulta rápida desde la factura: cuántos puntos tiene y cuánto descuento valen.
    public function consultar(Request $request, int $id, FidelizacionService $fid)
    {
        $c = Contact::findOrFail($id); $cfg = $fid->config($request->user()->business);
        return response()->json(['activo' => $cfg['activo'], 'puntos' => (float) $c->puntos, 'pesos' => $fid->valorEnPesos($request->user()->business, (float) $c->puntos), 'minimo' => (float) $cfg['minimo_canje'], 'valor_punto' => (float) $cfg['valor_punto']]);
    }
}
