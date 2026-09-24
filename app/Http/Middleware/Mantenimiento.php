<?php

namespace App\Http\Middleware;

use App\Models\SistemaConfig;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Modo mantenimiento: el superadmin lo activa y todos los demás ven una pantalla con el aviso. El superadmin sigue entrando.
class Mantenimiento
{
    public function handle(Request $request, Closure $next)
    {
        $m = SistemaConfig::get('mantenimiento');
        if (! is_array($m) || empty($m['activo'])) return $next($request);
        $u = $request->user();
        if ($u?->is_superadmin || $request->is('login') || $request->is('logout') || $request->is('admin*') || $request->is('api/*') || $request->is('p/*')) return $next($request);
        return Inertia::render('Mantenimiento', ['mensaje' => $m['mensaje'] ?? 'Estamos haciendo mejoras en el sistema. Volvemos en unos minutos.', 'hasta' => $m['hasta'] ?? null])->toResponse($request)->setStatusCode(503);
    }
}
