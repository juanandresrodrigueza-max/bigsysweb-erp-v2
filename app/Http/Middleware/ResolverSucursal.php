<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Garantiza que el usuario tenga una sucursal activa válida antes de entrar a la app.
class ResolverSucursal
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->business_id) {
            $accesibles = $user->sucursalesAccesibles();
            if ($accesibles->isNotEmpty() && ! $accesibles->contains('id', $user->current_location_id)) {
                $user->forceFill(['current_location_id' => $accesibles->first()->id])->save();
            }
        }
        return $next($request);
    }
}
