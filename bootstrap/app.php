<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\Mantenimiento::class,
            \App\Http\Middleware\ResolverSucursal::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\RegistrarUso::class,
        ]);
        $middleware->alias([
            'permiso'    => \App\Http\Middleware\Permiso::class,
            'superadmin' => \App\Http\Middleware\SuperAdmin::class,
            'suscripcion' => \App\Http\Middleware\SuscripcionActiva::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Monitoreo: cada error real se agrupa en el panel Salud y, con DSN, se manda a Sentry o compatible.
        $exceptions->report(function (\Throwable $e) {
            try { app(\App\Services\Monitoreo\ErrorReporter::class)->reportar($e); } catch (\Throwable $x) {}
        });
    })->create();
