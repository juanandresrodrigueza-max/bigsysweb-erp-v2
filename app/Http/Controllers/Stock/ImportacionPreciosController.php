<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ImportacionPrecios;
use App\Models\Rubro;
use App\Services\Stock\ImportacionPreciosService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Importar la lista de precios de un proveedor: subir → mapear columnas → aplicar.
class ImportacionPreciosController extends Controller
{
    public function __construct(private ImportacionPreciosService $service) {}

    public function index()
    {
        return Inertia::render('Stock/Importar', [
            'proveedores' => Contact::suppliers()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre']),
            'campos' => ImportacionPreciosService::CAMPOS,
            'articulos' => \App\Models\Product::where('active', true)->orderBy('name')->limit(3000)->get(['id', 'name', 'sku']),
            'ultimas' => ImportacionPrecios::with(['contact:id,name', 'user:id,name'])->latest()->limit(10)->get()->map(fn($i) => ['id' => $i->id, 'fecha' => $i->created_at->format('d/m/Y H:i'), 'archivo' => $i->archivo, 'proveedor' => $i->contact?->name, 'usuario' => $i->user?->name, 'leidos' => $i->leidos, 'creados' => $i->creados, 'actualizados' => $i->actualizados, 'errores' => $i->errores, 'detalle' => $i->detalle]),
        ]);
    }

    // Lee el archivo y devuelve una muestra con el mapeo sugerido. El archivo queda guardado para el paso 2.
    public function previsualizar(Request $request)
    {
        $request->validate(['archivo' => 'required|file|max:10240|mimes:csv,txt,xlsx']);
        $f = $request->file('archivo');
        $filas = $this->service->leer($f->getRealPath(), $f->getClientOriginalName());
        abort_if(count($filas) < 1, 422, 'El archivo está vacío.');
        $path = $f->storeAs('importaciones/' . $request->user()->business_id, uniqid() . '.' . $f->getClientOriginalExtension());
        $mapeo = $this->service->sugerirMapeo($filas[0]); $ia = false;
        // Si el encabezado no dice qué es cada columna, la IA lo deduce mirando los datos.
        if ((! in_array('descripcion', $mapeo, true) || ! array_intersect(['precio_compra', 'costo', 'precio_venta'], $mapeo)) && ($m2 = $this->service->mapeoConIA(array_slice($filas, 0, 4)))) { $mapeo = $m2 + $mapeo; $ia = true; }
        return response()->json(['path' => $path, 'nombre' => $f->getClientOriginalName(), 'total' => count($filas), 'columnas' => count($filas[0]), 'muestra' => array_slice($filas, 0, 8), 'mapeo' => $mapeo, 'mapeo_ia' => $ia]);
    }

    // Paso de revisión: cruza la lista con el catálogo (código, barras, nombre parecido y, si hay clave, la IA) y muestra qué pasaría.
    public function analizar(Request $request)
    {
        $d = $request->validate(['path' => 'required|string', 'nombre' => 'required|string|max:150', 'mapeo' => 'required|array', 'encabezado' => 'boolean', 'contact_id' => 'nullable|integer', 'solo_proveedor' => 'boolean']);
        $full = storage_path('app/private/' . $d['path']); if (! file_exists($full)) $full = storage_path('app/' . $d['path']);
        abort_unless(file_exists($full) && str_starts_with($d['path'], 'importaciones/' . $request->user()->business_id . '/'), 422, 'El archivo ya no está; volvé a subirlo.');
        return response()->json($this->service->analizar($this->service->leer($full, $d['nombre']), array_filter($d['mapeo']), $d));
    }

    public function aplicar(Request $request)
    {
        $d = $request->validate(['path' => 'required|string', 'nombre' => 'required|string|max:150', 'mapeo' => 'required|array', 'encabezado' => 'boolean', 'contact_id' => 'nullable|exists:contacts,id', 'rubro_id' => 'nullable|exists:rubros,id', 'iva' => 'nullable|numeric', 'margen' => 'nullable|numeric|min:0|max:1000', 'decisiones' => 'nullable|array', 'nombres' => 'nullable|array', 'rubros_fila' => 'nullable|array', 'moneda' => 'nullable|in:ARS,USD', 'crear' => 'boolean', 'solo_proveedor' => 'boolean']);
        $full = storage_path('app/private/' . $d['path']);
        if (! file_exists($full)) $full = storage_path('app/' . $d['path']);
        abort_unless(file_exists($full) && str_starts_with($d['path'], 'importaciones/' . $request->user()->business_id . '/'), 422, 'El archivo ya no está; volvé a subirlo.');
        $filas = $this->service->leer($full, $d['nombre']);
        $imp = $this->service->aplicar($filas, array_filter($d['mapeo']), $d + ['archivo' => $d['nombre']]);
        @unlink($full);
        return redirect('/stock/importar')->with('success', "Lista importada: {$imp->leidos} filas, {$imp->creados} artículos nuevos, {$imp->actualizados} actualizados" . ($imp->errores ? ", {$imp->errores} con error." : '.'));
    }
}
