<?php

namespace App\Http\Controllers\Proveedores;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

// Fase 83: "Qué reponer", la primera sección de Comprar. Lo que está en el mínimo o por debajo, agrupado por proveedor habitual,
// con la cantidad sugerida (hasta el doble del mínimo) y un botón que arma la orden con ese proveedor.
// Fase 107: el cálculo vive en ReposicionService (lo comparte el agente de reposición que arma las órdenes de noche).
class ReposicionController extends Controller
{
    public function index(\App\Services\Compras\ReposicionService $svc)
    {
        return Inertia::render('Proveedores/Reponer', $svc->sugerencias(auth()->user()->business));
    }
}
