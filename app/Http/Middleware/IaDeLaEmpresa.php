<?php

namespace App\Http\Middleware;

use App\Support\Ia;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Cada pedido de un usuario de empresa usa la clave de IA de su empresa; sin clave, la IA queda en modo básico.
// El superadmin sigue con la clave del sistema (solo para pruebas desde el panel).
class IaDeLaEmpresa
{
    public function handle(Request $request, Closure $next)
    {
        $u = Auth::user();
        if ($u && ! $u->is_superadmin) Ia::aplicar($u->business_id ? \App\Models\Business::select("id", "ia_settings")->find($u->business_id) : null);
        return $next($request);
    }
}
