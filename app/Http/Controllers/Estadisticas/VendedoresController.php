<?php

namespace App\Http\Controllers\Estadisticas;

use App\Http\Controllers\Controller;
use App\Models\Relevamiento;
use App\Services\Ventas\EquipoVentasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

// Fase 29.6: el equipo de ventas para el encargado: ranking, cómo viene la venta, cumplimiento de ruta, dónde estuvieron,
// por qué no se vendió y el relevamiento con fotos.
class VendedoresController extends Controller
{
    public function index(Request $request, EquipoVentasService $svc)
    {
        return Inertia::render('Estadisticas/Vendedores', $svc->equipo($request->query('periodo')));
    }

    public function foto(int $id)
    {
        $r = Relevamiento::findOrFail($id);
        abort_unless(Storage::disk('local')->exists($r->foto), 404);
        return Storage::disk('local')->response($r->foto);
    }
}
