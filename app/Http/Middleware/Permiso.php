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
            // Fase 39.1: el mensaje dice qué permiso falta y a quién pedírselo.
            $nombreModulo = config("erp.modulos.{$modulo}.label") ?? ucfirst($modulo);
            $nombreAccion = ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'anular' => 'Anular', 'exportar' => 'Exportar'][$accion] ?? $accion;
            $dueno = $user?->business?->owner?->name;
            $msg = $user && ! $user->business?->tieneModulo($modulo) && ! $user->is_superadmin ? "Tu plan no incluye «{$nombreModulo}»." : "Te falta el permiso «{$nombreAccion}» en «{$nombreModulo}»." . ($dueno ? " Pedíselo a {$dueno}." : '');
            abort(403, $msg);
        }
        return $next($request);
    }
}
