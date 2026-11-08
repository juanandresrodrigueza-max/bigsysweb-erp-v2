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
        $datos = $svc->equipo($request->query('periodo'));
        // Fase 74: el embudo del CRM al lado del ranking, cruzado con lo vendido en el ERP.
        [$d, $h] = $svc->periodo($request->query('periodo'));
        $datos['crm'] = \App\Services\Integraciones\CrmSyncService::embudo($request->user()->business, $d, $h, array_map(fn($v) => ['nombre' => $v['nombre'], 'email' => \App\Models\Vendedor::find($v['id'])?->email, 'total' => $v['total']], $datos['ranking']));
        return Inertia::render('Estadisticas/Vendedores', $datos);
    }

    public function foto(int $id)
    {
        $r = Relevamiento::findOrFail($id);
        abort_unless(Storage::disk('local')->exists($r->foto), 404);
        return Storage::disk('local')->response($r->foto);
    }
}
