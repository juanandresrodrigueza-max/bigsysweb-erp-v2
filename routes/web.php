<?php

use App\Http\Controllers\AgenteController;
use App\Http\Controllers\AlertasController;
use App\Http\Controllers\Clientes\AcopiosController;
use App\Http\Controllers\Clientes\ClientesController;
use App\Http\Controllers\Clientes\CobrosController;
use App\Http\Controllers\Comprobantes\ComprobantesController;
use App\Http\Controllers\Comprobantes\PresupuestoIAController;
use App\Http\Controllers\Configuracion\PuntosVentaController;
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

    // Comprobantes
    Route::prefix('comprobantes')->middleware('permiso:comprobantes')->group(function () {
        Route::get('/',                    [ComprobantesController::class, 'index']);
        Route::get('/nuevo',               [ComprobantesController::class, 'create'])->middleware('permiso:comprobantes,crear');
        Route::post('/',                   [ComprobantesController::class, 'store'])->middleware('permiso:comprobantes,crear');
        Route::get('/lote',                [ComprobantesController::class, 'lote'])->middleware('permiso:comprobantes,crear');
        Route::post('/lote',               [ComprobantesController::class, 'facturarLote'])->middleware('permiso:comprobantes,crear');
        Route::post('/ia/interpretar',     [PresupuestoIAController::class, 'interpretar'])->middleware('permiso:comprobantes,crear');
        Route::get('/{id}',                [ComprobantesController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/editar',         [ComprobantesController::class, 'edit'])->middleware('permiso:comprobantes,editar');
        Route::post('/{id}',               [ComprobantesController::class, 'store'])->middleware('permiso:comprobantes,editar');
        Route::post('/{id}/emitir',        [ComprobantesController::class, 'emitir'])->middleware('permiso:comprobantes,crear');
        Route::post('/{id}/anular',        [ComprobantesController::class, 'anular'])->middleware('permiso:comprobantes,anular');
        Route::post('/{id}/convertir',     [ComprobantesController::class, 'convertir'])->middleware('permiso:comprobantes,crear');
        Route::get('/{id}/imprimir',       [ComprobantesController::class, 'imprimir']);
    });

    // Clientes
    Route::prefix('clientes')->middleware('permiso:clientes')->group(function () {
        Route::get('/',                        [ClientesController::class, 'index']);
        Route::post('/',                       [ClientesController::class, 'guardar'])->middleware('permiso:clientes,crear');
        Route::post('/tipos/{id?}',            [ClientesController::class, 'guardarTipo'])->middleware('permiso:clientes,editar');
        Route::delete('/tipos/{id}',           [ClientesController::class, 'eliminarTipo'])->middleware('permiso:clientes,anular');
        Route::get('/{id}',                    [ClientesController::class, 'show'])->whereNumber('id');
        Route::post('/{id}',                   [ClientesController::class, 'guardar'])->middleware('permiso:clientes,editar');
        Route::post('/{id}/cobros',            [CobrosController::class, 'store'])->middleware('permiso:clientes,crear');
        Route::post('/cobros/{id}/anular',     [CobrosController::class, 'anular'])->middleware('permiso:clientes,anular');
        Route::get('/cobros/{id}/imprimir',    [CobrosController::class, 'imprimir']);
        Route::post('/acopios/{id}/retiros',   [AcopiosController::class, 'retirar'])->middleware('permiso:clientes,crear');
    });

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

        Route::get('/puntos-venta',          [PuntosVentaController::class, 'index'])->name('puntos');
        Route::post('/puntos-venta/{id?}',   [PuntosVentaController::class, 'guardar'])->middleware('permiso:configuracion,editar')->name('puntos.guardar');
        Route::post('/afip/certificados',    [PuntosVentaController::class, 'certificados'])->middleware('permiso:configuracion,editar')->name('afip.certificados');
    });

    // Módulos en construcción: pantalla "próximamente" para que el menú no rompa.
    foreach (config('erp.modulos') as $key => $m) {
        if (! $m['disponible']) {
            Route::get($m['ruta'], fn() => inertia('Proximamente', ['modulo' => $m]))->middleware("permiso:{$key}");
        }
    }
});
