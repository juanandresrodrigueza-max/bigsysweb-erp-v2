<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Services\Producto\UsoService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Métricas de uso para BigSys: qué empresas usan el sistema, cuáles se enfrían y qué módulos importan.
class UsoController extends Controller
{
    public function index(Request $request, UsoService $uso)
    {
        $dias = in_array((int) $request->dias, [7, 30, 90], true) ? (int) $request->dias : 30;
        return Inertia::render('Superadmin/Uso', $uso->global($dias));
    }
}
