<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Suscripciones\AprobacionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 106: Panel → Pendientes. Las empresas nuevas (registradas solas o dadas de alta desde el panel) que esperan la aprobación
// con la clave. Como en el CRM: la clave la tiene el dueño de BigSys; los empleados pueden dar de alta, no aprobar.
class PendientesController extends Controller
{
    public function index(AprobacionService $svc)
    {
        return Inertia::render('Superadmin/Pendientes', ['lista' => $svc->pendientes(), 'horas' => $svc->horas(), 'claveConfigurada' => $svc->claveConfigurada()]);
    }

    public function aprobar(Request $request, int $id, AprobacionService $svc)
    {
        $d = $request->validate(['clave' => 'nullable|string|max:120']);
        $b = Business::withTrashed()->findOrFail($id);
        if ($b->aprobacion !== 'pendiente') return back()->with('error', 'Esta empresa no está pendiente.');
        if ($e = $svc->validarClave($request->user(), $d['clave'] ?? null, $b, 'aprobar')) return back()->withErrors(['clave' => $e]);
        $svc->aprobar($b, $request->user());
        return back()->with('success', "{$b->name} aprobada. Ya entra con normalidad.");
    }

    public function rechazar(Request $request, int $id, AprobacionService $svc)
    {
        $d = $request->validate(['clave' => 'nullable|string|max:120', 'motivo' => 'required|string|max:200']);
        $b = Business::withTrashed()->findOrFail($id);
        if ($b->aprobacion !== 'pendiente') return back()->with('error', 'Esta empresa no está pendiente.');
        if ($e = $svc->validarClave($request->user(), $d['clave'] ?? null, $b, 'rechazar')) return back()->withErrors(['clave' => $e]);
        $svc->rechazar($b, $request->user(), $d['motivo']);
        return back()->with('success', "{$b->name} rechazada: queda suspendida con el motivo.");
    }
}
