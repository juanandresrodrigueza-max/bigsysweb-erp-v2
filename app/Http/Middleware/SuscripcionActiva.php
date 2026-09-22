<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Si la empresa está suspendida, dada de baja o sin suscripción vigente, solo se puede entrar a /suscripcion para renovar.
class SuscripcionActiva
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || $user->is_superadmin || $request->session()->has('impersonando_desde')) {
            return $next($request);
        }
        $empresa = $user->business;
        if ($empresa && $empresa->bloqueada() && ! $request->is('suscripcion*', 'logout', 'agente/*', 'webhooks/*')) {
            return redirect('/suscripcion');
        }
        return $next($request);
    }
}
