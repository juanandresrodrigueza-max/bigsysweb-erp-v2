<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Cabeceras de seguridad para toda respuesta web: sin embeber en otros sitios, sin adivinar tipos de contenido, referer acotado, HSTS sobre https.
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (! $response instanceof \Symfony\Component\HttpFoundation\Response) return $response;
        $h = $response->headers;
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(), payment=()');
        if ($request->isSecure()) $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        return $response;
    }
}
