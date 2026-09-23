<?php

use App\Http\Controllers\AgenteController;
use App\Http\Controllers\AlertasController;
use App\Http\Controllers\Clientes\AcopiosController;
use App\Http\Controllers\Clientes\ClientesController;
use App\Http\Controllers\Clientes\CobrosController;
use App\Http\Controllers\Comprobantes\ComprobantesController;
use App\Http\Controllers\Comprobantes\PresupuestoIAController;
use App\Http\Controllers\Configuracion\PuntosVentaController;
use App\Http\Controllers\Fondos\ChequesController;
use App\Http\Controllers\Fondos\FondosController;
use App\Http\Controllers\Proveedores\ComprasController;
use App\Http\Controllers\Proveedores\PagosController;
use App\Http\Controllers\Proveedores\ProveedoresController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Configuracion\AuditoriaController;
use App\Http\Controllers\Configuracion\EmpresaController;
use App\Http\Controllers\Configuracion\RolesController;
use App\Http\Controllers\Configuracion\SucursalesController;
use App\Http\Controllers\Configuracion\UsuariosController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\SuscripcionController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login',  [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Suscripción: accesible aunque la empresa esté bloqueada (es donde se renueva).
Route::middleware('auth')->group(function () {
    Route::get('/suscripcion',          [SuscripcionController::class, 'index'])->name('suscripcion');
    Route::post('/suscripcion/pagar',   [SuscripcionController::class, 'pagar']);
    Route::get('/suscripcion/retorno',  [SuscripcionController::class, 'retorno']);
    Route::post('/admin/volver',        [\App\Http\Controllers\Superadmin\EmpresasController::class, 'volver']);
});

// Panel superadmin (BigSys): empresas, planes, cobros, usuarios, sistema.
Route::middleware(['auth', 'superadmin'])->prefix('admin')->group(function () {
    Route::get('/',                                   [\App\Http\Controllers\Superadmin\PanelController::class, 'index']);
    Route::get('/empresas',                           [\App\Http\Controllers\Superadmin\EmpresasController::class, 'index']);
    Route::post('/empresas',                          [\App\Http\Controllers\Superadmin\EmpresasController::class, 'store']);
    Route::get('/empresas/{id}',                      [\App\Http\Controllers\Superadmin\EmpresasController::class, 'show'])->whereNumber('id');
    Route::post('/empresas/{id}',                     [\App\Http\Controllers\Superadmin\EmpresasController::class, 'update']);
    Route::post('/empresas/{id}/suspender',           [\App\Http\Controllers\Superadmin\EmpresasController::class, 'suspender']);
    Route::post('/empresas/{id}/reactivar',           [\App\Http\Controllers\Superadmin\EmpresasController::class, 'reactivar']);
    Route::post('/empresas/{id}/baja',                [\App\Http\Controllers\Superadmin\EmpresasController::class, 'baja']);
    Route::post('/empresas/{id}/restaurar',           [\App\Http\Controllers\Superadmin\EmpresasController::class, 'restaurar']);
    Route::post('/empresas/{id}/plan',                [\App\Http\Controllers\Superadmin\EmpresasController::class, 'cambiarPlan']);
    Route::post('/empresas/{id}/extender-prueba',     [\App\Http\Controllers\Superadmin\EmpresasController::class, 'extenderPrueba']);
    Route::post('/empresas/{id}/pagos',               [\App\Http\Controllers\Superadmin\EmpresasController::class, 'registrarPago']);
    Route::post('/empresas/{id}/pagos/{pago}/aprobar',  [\App\Http\Controllers\Superadmin\EmpresasController::class, 'aprobarPago']);
    Route::post('/empresas/{id}/pagos/{pago}/rechazar', [\App\Http\Controllers\Superadmin\EmpresasController::class, 'rechazarPago']);
    Route::post('/empresas/{id}/entrar',              [\App\Http\Controllers\Superadmin\EmpresasController::class, 'impersonar']);
    Route::post('/empresas/{id}/usuarios/{user}',     [\App\Http\Controllers\Superadmin\EmpresasController::class, 'usuario']);
    Route::get('/planes',                             [\App\Http\Controllers\Superadmin\PlanesController::class, 'index']);
    Route::post('/planes/{id?}',                      [\App\Http\Controllers\Superadmin\PlanesController::class, 'guardar']);
    Route::get('/cobros',                             [\App\Http\Controllers\Superadmin\SistemaController::class, 'cobros']);
    Route::get('/usuarios',                           [\App\Http\Controllers\Superadmin\SistemaController::class, 'usuarios']);
    Route::post('/usuarios',                          [\App\Http\Controllers\Superadmin\SistemaController::class, 'nuevoSuperadmin']);
    Route::post('/usuarios/{id}',                     [\App\Http\Controllers\Superadmin\SistemaController::class, 'usuario']);
    Route::get('/sistema',                            [\App\Http\Controllers\Superadmin\SistemaController::class, 'configuracion']);
    Route::post('/sistema',                           [\App\Http\Controllers\Superadmin\SistemaController::class, 'guardarConfiguracion']);
    Route::post('/sistema/revisar',                   [\App\Http\Controllers\Superadmin\SistemaController::class, 'revisarAhora']);
    Route::get('/auditoria',                          [\App\Http\Controllers\Superadmin\SistemaController::class, 'auditoria']);
});

Route::middleware(['auth', 'suscripcion'])->group(function () {
    Route::get('/', fn() => redirect(request()->user()?->is_superadmin && ! request()->user()->business_id ? '/admin' : '/dashboard'));
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

    // Proveedores y compras
    Route::prefix('proveedores')->middleware('permiso:proveedores')->group(function () {
        Route::get('/',                         [ProveedoresController::class, 'index']);
        Route::post('/',                        [ProveedoresController::class, 'guardar'])->middleware('permiso:proveedores,crear');
        Route::get('/compras',                  [ComprasController::class, 'index']);
        Route::get('/compras/nueva',            [ComprasController::class, 'create'])->middleware('permiso:proveedores,crear');
        Route::post('/compras',                 [ComprasController::class, 'store'])->middleware('permiso:proveedores,crear');
        Route::post('/compras/ocr',             [ComprasController::class, 'ocr'])->middleware('permiso:proveedores,crear');
        Route::post('/compras/importar-afip',   [ComprasController::class, 'importarAfip'])->middleware('permiso:proveedores,crear');
        Route::get('/compras/{id}',             [ComprasController::class, 'show'])->whereNumber('id');
        Route::get('/compras/{id}/editar',      [ComprasController::class, 'edit'])->middleware('permiso:proveedores,editar');
        Route::post('/compras/{id}',            [ComprasController::class, 'store'])->middleware('permiso:proveedores,editar');
        Route::post('/compras/{id}/registrar',  [ComprasController::class, 'registrar'])->middleware('permiso:proveedores,crear');
        Route::post('/compras/{id}/anular',     [ComprasController::class, 'anular'])->middleware('permiso:proveedores,anular');
        Route::post('/compras/{id}/nota-credito', [ComprasController::class, 'notaCredito'])->middleware('permiso:proveedores,crear');
        Route::post('/pagos/{id}/anular',       [PagosController::class, 'anular'])->middleware('permiso:proveedores,anular');
        Route::get('/pagos/{id}/imprimir',      [PagosController::class, 'imprimir']);
        Route::get('/{id}',                     [ProveedoresController::class, 'show'])->whereNumber('id');
        Route::post('/{id}',                    [ProveedoresController::class, 'guardar'])->middleware('permiso:proveedores,editar');
        Route::post('/{id}/pagos',              [PagosController::class, 'store'])->middleware('permiso:proveedores,crear');
    });

    // Fondos
    Route::prefix('fondos')->middleware('permiso:fondos')->group(function () {
        Route::get('/',                        [FondosController::class, 'index']);
        Route::post('/cuentas/{id?}',          [FondosController::class, 'guardarCuenta'])->middleware('permiso:fondos,editar');
        Route::post('/movimiento',             [FondosController::class, 'movimiento'])->middleware('permiso:fondos,crear');
        Route::post('/transferir',             [FondosController::class, 'transferir'])->middleware('permiso:fondos,crear');
        Route::post('/categorias',             [FondosController::class, 'guardarCategoria'])->middleware('permiso:fondos,crear');
        Route::post('/cuentas/{id}/abrir-turno', [FondosController::class, 'abrirTurno'])->middleware('permiso:fondos,crear');
        Route::post('/turnos/{id}/cerrar',     [FondosController::class, 'cerrarTurno'])->middleware('permiso:fondos,crear');
        Route::get('/cheques',                 [ChequesController::class, 'index']);
        Route::post('/cheques/{id}/depositar', [ChequesController::class, 'depositar'])->middleware('permiso:fondos,crear');
        Route::post('/cheques/{id}/rechazar',  [ChequesController::class, 'rechazar'])->middleware('permiso:fondos,editar');
        Route::post('/cheques/{id}/cobrado',   [ChequesController::class, 'cobrado'])->middleware('permiso:fondos,editar');
        Route::post('/cheques/{id}/debitar',   [ChequesController::class, 'debitar'])->middleware('permiso:fondos,editar');
    });

    // Stock
    Route::prefix('stock')->middleware('permiso:stock')->group(function () {
        Route::get('/',                          [\App\Http\Controllers\Stock\StockController::class, 'index']);
        Route::post('/articulos/{id?}',          [\App\Http\Controllers\Stock\StockController::class, 'guardar'])->middleware('permiso:stock,editar');
        Route::get('/movimientos',               [\App\Http\Controllers\Stock\StockController::class, 'movimientos']);
        Route::post('/ajustar',                  [\App\Http\Controllers\Stock\StockController::class, 'ajustar'])->middleware('permiso:stock,editar');
        Route::post('/transferir',               [\App\Http\Controllers\Stock\StockController::class, 'transferir'])->middleware('permiso:stock,crear');
        Route::post('/transferencias/{id}/anular', [\App\Http\Controllers\Stock\StockController::class, 'anularTransferencia'])->middleware('permiso:stock,anular');
        Route::get('/inventario',                [\App\Http\Controllers\Stock\StockController::class, 'inventario'])->middleware('permiso:stock,editar');
        Route::post('/inventario',               [\App\Http\Controllers\Stock\StockController::class, 'cerrarInventario'])->middleware('permiso:stock,editar');
        Route::get('/inventario/{id}',           [\App\Http\Controllers\Stock\StockController::class, 'verInventario']);
        Route::post('/depositos/{id?}',          [\App\Http\Controllers\Stock\StockController::class, 'guardarDeposito'])->middleware('permiso:stock,editar');
        Route::post('/rubros/{id?}',             [\App\Http\Controllers\Stock\StockController::class, 'guardarRubro'])->middleware('permiso:stock,editar');
        Route::delete('/rubros/{id}',            [\App\Http\Controllers\Stock\StockController::class, 'eliminarRubro'])->middleware('permiso:stock,editar');
        Route::post('/precios',                  [\App\Http\Controllers\Stock\StockController::class, 'actualizarPrecios'])->middleware('permiso:stock,editar');
        Route::get('/{id}',                      [\App\Http\Controllers\Stock\StockController::class, 'show'])->whereNumber('id');
    });

    // Producción
    Route::prefix('produccion')->middleware('permiso:produccion')->group(function () {
        Route::get('/',                          [\App\Http\Controllers\Produccion\ProduccionController::class, 'index']);
        Route::post('/ordenes',                  [\App\Http\Controllers\Produccion\ProduccionController::class, 'crear'])->middleware('permiso:produccion,crear');
        Route::post('/ordenes/{id}/iniciar',     [\App\Http\Controllers\Produccion\ProduccionController::class, 'iniciar'])->middleware('permiso:produccion,editar');
        Route::post('/ordenes/{id}/terminar',    [\App\Http\Controllers\Produccion\ProduccionController::class, 'terminar'])->middleware('permiso:produccion,editar');
        Route::post('/ordenes/{id}/cancelar',    [\App\Http\Controllers\Produccion\ProduccionController::class, 'cancelar'])->middleware('permiso:produccion,anular');
        Route::get('/formulas',                  [\App\Http\Controllers\Produccion\ProduccionController::class, 'formulas']);
        Route::post('/formulas/{id?}',           [\App\Http\Controllers\Produccion\ProduccionController::class, 'guardarFormula'])->middleware('permiso:produccion,editar');
    });

    // Contable
    Route::prefix('contable')->middleware('permiso:contable')->group(function () {
        Route::get('/',                            [\App\Http\Controllers\Contable\ContableController::class, 'index']);
        Route::post('/sincronizar',                [\App\Http\Controllers\Contable\ContableController::class, 'sincronizar'])->middleware('permiso:contable,crear');
        Route::get('/asientos',                    [\App\Http\Controllers\Contable\ContableController::class, 'asientos']);
        Route::post('/asientos',                   [\App\Http\Controllers\Contable\ContableController::class, 'guardarAsiento'])->middleware('permiso:contable,crear');
        Route::post('/asientos/{id}/anular',       [\App\Http\Controllers\Contable\ContableController::class, 'anularAsiento'])->middleware('permiso:contable,anular');
        Route::get('/plan',                        [\App\Http\Controllers\Contable\ContableController::class, 'plan']);
        Route::post('/plan/{id?}',                 [\App\Http\Controllers\Contable\ContableController::class, 'guardarCuenta'])->middleware('permiso:contable,editar');
        Route::get('/mayor',                       [\App\Http\Controllers\Contable\ContableController::class, 'mayor']);
        Route::get('/iva',                         [\App\Http\Controllers\Contable\ContableController::class, 'iva']);
        Route::get('/balance',                     [\App\Http\Controllers\Contable\ContableController::class, 'balance']);
        Route::get('/flujo',                       [\App\Http\Controllers\Contable\ContableController::class, 'flujo']);
        Route::get('/conciliacion',                [\App\Http\Controllers\Contable\ConciliacionController::class, 'index']);
        Route::post('/conciliacion/importar',      [\App\Http\Controllers\Contable\ConciliacionController::class, 'importar'])->middleware('permiso:contable,crear');
        Route::post('/conciliacion/automatica',    [\App\Http\Controllers\Contable\ConciliacionController::class, 'automatica'])->middleware('permiso:contable,crear');
        Route::post('/conciliacion/{id}/vincular', [\App\Http\Controllers\Contable\ConciliacionController::class, 'vincular'])->middleware('permiso:contable,crear');
        Route::post('/conciliacion/{id}/desvincular', [\App\Http\Controllers\Contable\ConciliacionController::class, 'desvincular'])->middleware('permiso:contable,crear');
        Route::post('/conciliacion/{id}/ignorar',  [\App\Http\Controllers\Contable\ConciliacionController::class, 'ignorar'])->middleware('permiso:contable,crear');
        Route::post('/conciliacion/{id}/registrar', [\App\Http\Controllers\Contable\ConciliacionController::class, 'registrar'])->middleware('permiso:contable,crear');
    });

    Route::get('/estadisticas', [\App\Http\Controllers\Estadisticas\EstadisticasController::class, 'index'])->middleware('permiso:estadisticas');

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
