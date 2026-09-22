<?php

use App\Http\Controllers\AgenteController;
use App\Http\Controllers\AlertasController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Configuracion\AuditoriaController;
use App\Http\Controllers\Configuracion\EmpresaController;
use App\Http\Controllers\Configuracion\RolesController;
use App\Http\Controllers\Configuracion\SucursalesController;
use App\Http\Controllers\Configuracion\UsuariosController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SucursalController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login',  [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn() => redirect('/dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('permiso:dashboard')->name('dashboard');

    Route::post('/sucursal/{id}', [SucursalController::class, 'cambiar'])->name('sucursal.cambiar');

    Route::get('/alertas',                  [AlertasController::class, 'index'])->middleware('permiso:alertas')->name('alertas.index');
    Route::post('/alertas/leer-todas',      [AlertasController::class, 'leerTodas'])->name('alertas.leerTodas');
    Route::post('/alertas/{id}/leer',       [AlertasController::class, 'leer'])->name('alertas.leer');
    Route::post('/alertas/{id}/resolver',   [AlertasController::class, 'resolver'])->name('alertas.resolver');

    Route::post('/agente/chat', [AgenteController::class, 'chat'])->name('agente.chat');

    Route::prefix('configuracion')->middleware('permiso:configuracion')->name('configuracion.')->group(function () {
        Route::get('/',            [EmpresaController::class, 'index'])->name('empresa');
        Route::post('/empresa',    [EmpresaController::class, 'guardar'])->middleware('permiso:configuracion,editar')->name('empresa.guardar');

        Route::get('/sucursales',            [SucursalesController::class, 'index'])->name('sucursales');
        Route::post('/sucursales/{id?}',     [SucursalesController::class, 'guardar'])->middleware('permiso:configuracion,editar')->name('sucursales.guardar');

        Route::get('/usuarios',              [UsuariosController::class, 'index'])->name('usuarios');
        Route::post('/usuarios/{id?}',       [UsuariosController::class, 'guardar'])->middleware('permiso:configuracion,editar')->name('usuarios.guardar');

        Route::get('/roles',                 [RolesController::class, 'index'])->name('roles');
        Route::post('/roles/{id?}',          [RolesController::class, 'guardar'])->middleware('permiso:configuracion,editar')->name('roles.guardar');
        Route::delete('/roles/{id}',         [RolesController::class, 'eliminar'])->middleware('permiso:configuracion,anular')->name('roles.eliminar');

        Route::get('/auditoria',             [AuditoriaController::class, 'index'])->name('auditoria');
    });

    // Módulos en construcción: pantalla "próximamente" para que el menú no rompa.
    foreach (config('erp.modulos') as $key => $m) {
        if (! $m['disponible']) {
            Route::get($m['ruta'], fn() => inertia('Proximamente', ['modulo' => $m]))->middleware("permiso:{$key}");
        }
    }
});
