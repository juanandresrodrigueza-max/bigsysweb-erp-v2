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
        // Fase 106: alta pendiente con el plazo provisional vencido: sólo la página de espera (se destraba al aprobarla).
        if ($empresa && $empresa->aprobacion === 'pendiente' && app(\App\Services\Suscripciones\AprobacionService::class)->vencida($empresa) && ! $request->is('esperando-aprobacion', 'logout', 'agente/*', 'webhooks/*')) {
            return redirect('/esperando-aprobacion');
        }
        if ($empresa && $empresa->bloqueada() && ! $request->is('suscripcion*', 'logout', 'agente/*', 'webhooks/*')) {
            return redirect('/suscripcion');
        }
        return $next($request);
    }
}
