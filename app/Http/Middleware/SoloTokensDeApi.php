<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

// La API REST es sólo para los tokens de API (habilidad '*'). Un token creado para el MCP
// (habilidad 'mcp', incluido el de modo «Solo consultas») no puede entrar acá: si no, con un
// token «de solo lectura» se podría escribir por la API. Un token de API ('*') también «puede»
// 'mcp', por eso se mira que NO tenga '*'. La sesión del navegador (TransientToken) no se toca.
class SoloTokensDeApi
{
    public function handle(Request $request, Closure $next)
    {
        $t = $request->user()?->currentAccessToken();
        if ($t instanceof PersonalAccessToken && $t->can('mcp') && ! $t->can('*')) {
            return response()->json(['message' => 'Este token es para la conexión de IA (MCP) y no sirve para la API. Creá un token de API en Seguridad y API → Tokens.'], 403);
        }
        return $next($request);
    }
}
