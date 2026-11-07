<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Fase 44.3: el Tablero por partes. Un perfil puede ver Estadísticas pero sólo algunas solapas (Ventas, Plata, Stock y compras…).
class ParteEstadisticas
{
    public function handle(Request $request, Closure $next, string $parte)
    {
        abort_unless($request->user()?->veParteEstadisticas($parte), 403, 'Tu perfil no tiene habilitada esta parte del tablero (' . (\App\Models\Role::PARTES_ESTADISTICAS[$parte] ?? $parte) . ').');
        return $next($request);
    }
}
