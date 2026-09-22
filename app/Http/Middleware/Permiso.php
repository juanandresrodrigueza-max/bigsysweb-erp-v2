<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Uso en rutas: middleware('permiso:comprobantes,crear'). La acción por defecto es "ver".
class Permiso
{
    public function handle(Request $request, Closure $next, string $modulo, string $accion = 'ver')
    {
        $user = $request->user();
        if (! $user || ! $user->puede($modulo, $accion)) {
            abort(403, 'No tenés permiso para esta acción.');
        }
        return $next($request);
    }
}
