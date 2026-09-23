<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\Importacion;
use App\Services\Migracion\ImportadorService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Importador de datos: clientes, proveedores y artículos desde Excel/CSV (o el export del sistema viejo).
class ImportarController extends Controller
{
    public function index()
    {
        return Inertia::render('Configuracion/Importar', [
            'entidades' => collect(ImportadorService::ENTIDADES)->map(fn($e, $k) => ['key' => $k, 'label' => $e['label'], 'campos' => $e['campos']])->values(),
            'historial' => Importacion::with('user:id,name')->latest()->limit(10)->get()->map(fn($i) => ['id' => $i->id, 'fecha' => $i->created_at->format('d/m/Y H:i'), 'entidad' => ImportadorService::ENTIDADES[$i->entidad]['label'] ?? $i->entidad, 'archivo' => $i->archivo, 'leidas' => $i->leidas, 'creadas' => $i->creadas, 'actualizadas' => $i->actualizadas, 'errores' => $i->errores, 'detalle' => $i->detalle, 'usuario' => $i->user?->name]),
        ]);
    }

    public function previsualizar(Request $request, ImportadorService $svc)
    {
        $d = $request->validate(['archivo' => 'required|file|max:20480', 'entidad' => 'required|in:' . implode(',', array_keys(ImportadorService::ENTIDADES))]);
        $f = $request->file('archivo');
        $filas = $svc->leer($f->getRealPath(), $f->getClientOriginalName());
        if (count($filas) < 2) return back()->withErrors(['archivo' => 'El archivo no tiene datos (necesita encabezado y al menos una fila).']);
        $enc = $filas[0];
        return back()->with('preview', ['entidad' => $d['entidad'], 'archivo' => $f->getClientOriginalName(), 'encabezado' => $enc, 'mapeo' => $svc->sugerirMapeo($d['entidad'], $enc), 'muestra' => array_slice($filas, 1, 8), 'total' => count($filas) - 1, 'filas' => array_slice($filas, 1)]);
    }

    public function aplicar(Request $request, ImportadorService $svc)
    {
        $d = $request->validate(['entidad' => 'required|in:' . implode(',', array_keys(ImportadorService::ENTIDADES)), 'archivo' => 'nullable|string', 'filas' => 'required|array|min:1', 'mapeo' => 'required|array', 'stock_inicial' => 'boolean', 'saldos' => 'boolean', 'fecha_saldos' => 'nullable|date']);
        $mapeo = collect($d['mapeo'])->filter(fn($v) => $v !== null && $v !== '')->all();
        if (! $mapeo) return back()->withErrors(['mapeo' => 'Asigná al menos la columna de nombre o descripción.']);
        $imp = $svc->aplicar($d['entidad'], $d['filas'], $mapeo, ['archivo' => $d['archivo'] ?? null, 'stock_inicial' => $d['stock_inicial'] ?? true, 'saldos' => $d['saldos'] ?? true, 'fecha_saldos' => $d['fecha_saldos'] ?? null]);
        return back()->with('success', "Importación lista: {$imp->creadas} nuevos, {$imp->actualizadas} actualizados" . ($imp->errores ? ", {$imp->errores} con error (mirá el detalle abajo)" : '') . '.');
    }
}
