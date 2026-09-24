<?php

namespace App\Http\Middleware;

use App\Services\Producto\UsoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Cuenta una vista (GET) o una acción (POST/PUT/DELETE) por módulo y día para cada usuario. Después de responder, nunca antes.
class RegistrarUso
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();
        if (! $user || ! $user->business_id || $response->getStatusCode() >= 400 || $request->ajax() && $request->isMethod('GET') && ! $request->header('X-Inertia')) return;
        try { app(UsoService::class)->registrar($user, $request->path(), ! $request->isMethod('GET')); } catch (\Throwable $e) {}
    }
}
