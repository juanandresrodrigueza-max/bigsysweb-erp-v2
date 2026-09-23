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
    Route::post('/login', [LoginController::class, 'store'])->name('login.store')->middleware('throttle:login');
    Route::get('/recuperar',               [LoginController::class, 'recuperar'])->name('password.request');
    Route::post('/recuperar',              [LoginController::class, 'enviarRecuperacion'])->middleware('throttle:login');
    Route::get('/restablecer/{token}',     [LoginController::class, 'restablecer'])->name('password.reset');
    Route::post('/restablecer',            [LoginController::class, 'restablecerStore'])->middleware('throttle:login');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/login/verificar',  [LoginController::class, 'verificar'])->middleware('guest');
Route::post('/login/verificar', [LoginController::class, 'verificarStore'])->middleware(['guest', 'throttle:login']);

// Página pública de comprobantes: ver, PDF, aprobar presupuesto, pagar (sin login).
Route::get('/p/{token}',                 [\App\Http\Controllers\PublicoController::class, 'ver'])->middleware('throttle:publico');
Route::get('/p/{token}/pdf',             [\App\Http\Controllers\PublicoController::class, 'pdf'])->middleware('throttle:publico');
Route::post('/p/{token}/responder',      [\App\Http\Controllers\PublicoController::class, 'responder'])->middleware('throttle:publico');
Route::get('/p/{token}/pagar-simulado',  [\App\Http\Controllers\PublicoController::class, 'pagarSimulado'])->middleware('throttle:publico');

// Tienda propia, menú QR, reservas y portal del cliente (públicos, sin login).
Route::get('/t/{slug}',                    [\App\Http\Controllers\TiendaPublicaController::class, 'catalogo'])->middleware('throttle:publico');
Route::post('/t/{slug}/pedir',             [\App\Http\Controllers\TiendaPublicaController::class, 'pedir'])->middleware('throttle:publico');
Route::get('/t/{slug}/pedido/{token}',     [\App\Http\Controllers\TiendaPublicaController::class, 'pedido'])->middleware('throttle:publico');
Route::get('/m/{slug}',                    [\App\Http\Controllers\TiendaPublicaController::class, 'menu'])->middleware('throttle:publico');
Route::post('/m/{slug}/pedir',             [\App\Http\Controllers\TiendaPublicaController::class, 'pedirMesa'])->middleware('throttle:publico');
Route::get('/r/{slug}',                    [\App\Http\Controllers\TiendaPublicaController::class, 'reservar'])->middleware('throttle:publico');
Route::get('/ot/{token}',                  [\App\Http\Controllers\Servicios\OrdenesController::class, 'publico'])->middleware('throttle:publico');
Route::post('/ot/{token}',                 [\App\Http\Controllers\Servicios\OrdenesController::class, 'responder'])->middleware('throttle:publico');
Route::post('/r/{slug}',                   [\App\Http\Controllers\TiendaPublicaController::class, 'reservarStore'])->middleware('throttle:publico');
Route::get('/portal/{token}',              [\App\Http\Controllers\PortalController::class, 'ver'])->middleware('throttle:publico');
Route::get('/portal/{token}/pagar/{id}',   [\App\Http\Controllers\PortalController::class, 'pagar'])->whereNumber('id');

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
    Route::post('/sistema/mantenimiento',             [\App\Http\Controllers\Superadmin\SistemaController::class, 'mantenimiento']);
    Route::get('/soporte',                            [\App\Http\Controllers\SoporteController::class, 'admin']);
    Route::post('/soporte/{id}/responder',            [\App\Http\Controllers\SoporteController::class, 'adminResponder']);
    Route::post('/sistema/revisar',                   [\App\Http\Controllers\Superadmin\SistemaController::class, 'revisarAhora']);
    Route::get('/auditoria',                          [\App\Http\Controllers\Superadmin\SistemaController::class, 'auditoria']);
});

Route::middleware(['auth', 'suscripcion'])->group(function () {
    Route::get('/', fn() => redirect(request()->user()?->is_superadmin && ! request()->user()->business_id ? '/admin' : '/dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('permiso:dashboard')->name('dashboard');
    Route::get('/primeros-pasos',            [\App\Http\Controllers\OnboardingController::class, 'index']);
    Route::post('/primeros-pasos/marcar',    [\App\Http\Controllers\OnboardingController::class, 'marcar']);
    Route::post('/primeros-pasos/completar', [\App\Http\Controllers\OnboardingController::class, 'completar']);
    Route::get('/soporte',                   [\App\Http\Controllers\SoporteController::class, 'index']);
    Route::post('/soporte',                  [\App\Http\Controllers\SoporteController::class, 'crear']);
    Route::post('/soporte/{id}/responder',   [\App\Http\Controllers\SoporteController::class, 'responder']);
    Route::post('/soporte/{id}/cerrar',      [\App\Http\Controllers\SoporteController::class, 'cerrar']);

    Route::post('/sucursal/{id}', [SucursalController::class, 'cambiar'])->name('sucursal.cambiar');

    Route::get('/alertas',                  [AlertasController::class, 'index'])->middleware('permiso:alertas')->name('alertas.index');
    Route::post('/alertas/leer-todas',      [AlertasController::class, 'leerTodas'])->name('alertas.leerTodas');
    Route::post('/alertas/{id}/leer',       [AlertasController::class, 'leer'])->name('alertas.leer');
    Route::post('/alertas/{id}/resolver',   [AlertasController::class, 'resolver'])->name('alertas.resolver');

    Route::post('/agente/chat', [AgenteController::class, 'chat'])->name('agente.chat');

    // Comprobantes
    Route::prefix('comprobantes')->middleware('permiso:comprobantes')->group(function () {
        Route::get('/',                    [ComprobantesController::class, 'index']);
        Route::get('/pendientes',              [\App\Http\Controllers\Comprobantes\EntregasController::class, 'pendientes']);
        Route::post('/{id}/parcial',           [\App\Http\Controllers\Comprobantes\EntregasController::class, 'parcial'])->middleware('permiso:comprobantes,crear')->whereNumber('id');
        Route::get('/entregas',                [\App\Http\Controllers\Comprobantes\EntregasController::class, 'ordenes']);
        Route::post('/entregas',               [\App\Http\Controllers\Comprobantes\EntregasController::class, 'crearOrden'])->middleware('permiso:comprobantes,crear');
        Route::get('/entregas/{id}',           [\App\Http\Controllers\Comprobantes\EntregasController::class, 'verOrden'])->whereNumber('id');
        Route::get('/entregas/{id}/imprimir',  [\App\Http\Controllers\Comprobantes\EntregasController::class, 'imprimirOrden']);
        Route::post('/entregas/{id}/estado',   [\App\Http\Controllers\Comprobantes\EntregasController::class, 'estadoOrden'])->middleware('permiso:comprobantes,editar');
        Route::post('/entregas/items/{id}',    [\App\Http\Controllers\Comprobantes\EntregasController::class, 'marcarItem'])->middleware('permiso:comprobantes,editar');
        Route::get('/abonos',                  [\App\Http\Controllers\Comprobantes\AbonosController::class, 'index']);
        Route::post('/abonos/emitir-vencidos', [\App\Http\Controllers\Comprobantes\AbonosController::class, 'emitirVencidos'])->middleware('permiso:comprobantes,crear');
        Route::post('/abonos/{id}/emitir',     [\App\Http\Controllers\Comprobantes\AbonosController::class, 'emitir'])->middleware('permiso:comprobantes,crear');
        Route::post('/abonos/{id?}',           [\App\Http\Controllers\Comprobantes\AbonosController::class, 'guardar'])->middleware('permiso:comprobantes,crear');
        Route::post('/{id}/link-pago',         [ComprobantesController::class, 'linkPago'])->middleware('permiso:comprobantes,crear')->whereNumber('id');
        Route::get('/nuevo',               [ComprobantesController::class, 'create'])->middleware('permiso:comprobantes,crear');
        Route::post('/',                   [ComprobantesController::class, 'store'])->middleware('permiso:comprobantes,crear');
        Route::get('/lote',                [ComprobantesController::class, 'lote'])->middleware('permiso:comprobantes,crear');
        Route::post('/lote',               [ComprobantesController::class, 'facturarLote'])->middleware('permiso:comprobantes,crear');
        Route::post('/ia/interpretar',     [PresupuestoIAController::class, 'interpretar'])->middleware('permiso:comprobantes,crear');
        Route::get('/novedades',           [\App\Http\Controllers\Comprobantes\NovedadesController::class, 'index']);
        Route::get('/pedidos',             [\App\Http\Controllers\Comprobantes\PedidosController::class, 'index']);
        Route::post('/pedidos/interpretar', [\App\Http\Controllers\Comprobantes\PedidosController::class, 'interpretar'])->middleware('permiso:comprobantes,crear');
        Route::post('/pedidos/whatsapp',   [\App\Http\Controllers\Comprobantes\PedidosController::class, 'crearWhatsapp'])->middleware('permiso:comprobantes,crear');
        Route::post('/pedidos/{id}/confirmar', [\App\Http\Controllers\Comprobantes\PedidosController::class, 'confirmar'])->middleware('permiso:comprobantes,crear');
        Route::post('/pedidos/{id}/estado',    [\App\Http\Controllers\Comprobantes\PedidosController::class, 'estado'])->middleware('permiso:comprobantes,editar');
        Route::post('/pedidos/{id}/responder', [\App\Http\Controllers\Comprobantes\PedidosController::class, 'responder'])->middleware('permiso:comprobantes,editar');
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
        Route::get('/cobranzas',               [\App\Http\Controllers\Clientes\CobranzasController::class, 'index']);
        Route::post('/cobranzas/configurar',   [\App\Http\Controllers\Clientes\CobranzasController::class, 'configurar'])->middleware('permiso:clientes,editar');
        Route::post('/cobranzas/correr',       [\App\Http\Controllers\Clientes\CobranzasController::class, 'correr'])->middleware('permiso:clientes,crear');
        Route::post('/cobranzas/{id}/recordar',    [\App\Http\Controllers\Clientes\CobranzasController::class, 'recordar'])->middleware('permiso:clientes,crear');
        Route::post('/cobranzas/{id}/refinanciar', [\App\Http\Controllers\Clientes\CobranzasController::class, 'refinanciar'])->middleware('permiso:clientes,crear');
        Route::get('/vendedores',              [\App\Http\Controllers\Clientes\VendedoresController::class, 'index']);
        Route::post('/vendedores/{id?}',       [\App\Http\Controllers\Clientes\VendedoresController::class, 'guardar'])->middleware('permiso:clientes,editar');
        Route::post('/tipos/{id?}',            [ClientesController::class, 'guardarTipo'])->middleware('permiso:clientes,editar');
        Route::delete('/tipos/{id}',           [ClientesController::class, 'eliminarTipo'])->middleware('permiso:clientes,anular');
        Route::get('/fidelizacion',            [\App\Http\Controllers\Clientes\FidelizacionController::class, 'index']);
        Route::post('/fidelizacion/ajustar',   [\App\Http\Controllers\Clientes\FidelizacionController::class, 'ajustar'])->middleware('permiso:clientes,editar');
        Route::get('/{id}/puntos',             [\App\Http\Controllers\Clientes\FidelizacionController::class, 'consultar'])->whereNumber('id');
        Route::get('/{id}',                    [ClientesController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/pendientes',         [ClientesController::class, 'pendientesJson'])->whereNumber('id');
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
        Route::get('/ordenes',                  [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'index']);
        Route::get('/ordenes/nueva',            [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'create'])->middleware('permiso:proveedores,crear');
        Route::post('/ordenes',                 [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'store'])->middleware('permiso:proveedores,crear');
        Route::post('/ordenes/sugerir',         [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'sugerir'])->middleware('permiso:proveedores,crear');
        Route::get('/ordenes/{id}',             [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'show'])->whereNumber('id');
        Route::get('/ordenes/{id}/editar',      [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'edit'])->middleware('permiso:proveedores,editar');
        Route::get('/ordenes/{id}/imprimir',    [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'imprimir']);
        Route::post('/ordenes/{id}',            [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'store'])->middleware('permiso:proveedores,editar');
        Route::post('/ordenes/{id}/enviar',     [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'enviar'])->middleware('permiso:proveedores,crear');
        Route::post('/ordenes/{id}/cancelar',   [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'cancelar'])->middleware('permiso:proveedores,anular');
        Route::get('/ordenes/{id}/recibir',     [\App\Http\Controllers\Proveedores\OrdenesCompraController::class, 'recibir'])->middleware('permiso:proveedores,crear');
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
        Route::post('/{id}/retencion-sugerida', [PagosController::class, 'retencionSugerida'])->whereNumber('id');
        Route::get('/{id}',                     [ProveedoresController::class, 'show'])->whereNumber('id');
        Route::post('/{id}',                    [ProveedoresController::class, 'guardar'])->middleware('permiso:proveedores,editar');
        Route::post('/{id}/pagos',              [PagosController::class, 'store'])->middleware('permiso:proveedores,crear');
    });

    // Fondos
    Route::prefix('fondos')->middleware('permiso:fondos')->group(function () {
        Route::get('/moneda',              [\App\Http\Controllers\Fondos\MonedaController::class, 'index']);
        Route::post('/moneda/fijar',       [\App\Http\Controllers\Fondos\MonedaController::class, 'fijar'])->middleware('permiso:fondos,editar');
        Route::post('/moneda/actualizar',  [\App\Http\Controllers\Fondos\MonedaController::class, 'actualizar'])->middleware('permiso:fondos,editar');
        Route::post('/moneda/revaluar',    [\App\Http\Controllers\Fondos\MonedaController::class, 'revaluar'])->middleware('permiso:fondos,crear');
        Route::get('/',                        [FondosController::class, 'index']);
        Route::post('/cuentas/{id?}',          [FondosController::class, 'guardarCuenta'])->middleware('permiso:fondos,editar');
        Route::post('/movimiento',             [FondosController::class, 'movimiento'])->middleware('permiso:fondos,crear');
        Route::post('/transferir',             [FondosController::class, 'transferir'])->middleware('permiso:fondos,crear');
        Route::post('/categorias',             [FondosController::class, 'guardarCategoria'])->middleware('permiso:fondos,crear');
        Route::post('/cuentas/{id}/abrir-turno', [FondosController::class, 'abrirTurno'])->middleware('permiso:fondos,crear');
        Route::post('/turnos/{id}/cerrar',     [FondosController::class, 'cerrarTurno'])->middleware('permiso:fondos,crear');
        Route::get('/turnos/{id}/rendicion',   [FondosController::class, 'rendicion']);
        Route::get('/cierres',                 [\App\Http\Controllers\Fondos\CierresController::class, 'index']);
        Route::post('/turnos/{id}/arqueo',     [\App\Http\Controllers\Fondos\CierresController::class, 'arqueo'])->middleware('permiso:fondos,crear');
        Route::post('/turnos/{id}/retiro',     [\App\Http\Controllers\Fondos\CierresController::class, 'retiro'])->middleware('permiso:fondos,crear');
        Route::get('/valores',                 [\App\Http\Controllers\Fondos\ValoresController::class, 'index']);
        Route::get('/tarjetas',                [\App\Http\Controllers\Fondos\TarjetasController::class, 'index']);
        Route::post('/tarjetas/liquidar',      [\App\Http\Controllers\Fondos\TarjetasController::class, 'liquidar'])->middleware('permiso:fondos,crear');
        Route::post('/tarjetas/liquidaciones/{id}/anular', [\App\Http\Controllers\Fondos\TarjetasController::class, 'anular'])->middleware('permiso:fondos,anular');
        Route::post('/tarjetas/cupones/{id}/rechazar',     [\App\Http\Controllers\Fondos\TarjetasController::class, 'rechazar'])->middleware('permiso:fondos,editar');
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
        Route::post('/precios/previsualizar', [\App\Http\Controllers\Stock\StockController::class, 'previsualizarPrecios'])->middleware('permiso:stock,editar');
        Route::post('/precios/deshacer', [\App\Http\Controllers\Stock\StockController::class, 'deshacerPrecios'])->middleware('permiso:stock,editar');
        Route::post('/precios',                  [\App\Http\Controllers\Stock\StockController::class, 'actualizarPrecios'])->middleware('permiso:stock,editar');
        Route::post('/cotizacion',               [\App\Http\Controllers\Stock\StockController::class, 'cotizacion'])->middleware('permiso:stock,editar');
        Route::get('/importar',                  [\App\Http\Controllers\Stock\ImportacionPreciosController::class, 'index'])->middleware('permiso:stock,editar');
        Route::post('/importar/previsualizar',   [\App\Http\Controllers\Stock\ImportacionPreciosController::class, 'previsualizar'])->middleware('permiso:stock,editar');
        Route::post('/importar/aplicar',         [\App\Http\Controllers\Stock\ImportacionPreciosController::class, 'aplicar'])->middleware('permiso:stock,editar');
        Route::post('/importar/analizar',        [\App\Http\Controllers\Stock\ImportacionPreciosController::class, 'analizar'])->middleware('permiso:stock,editar');
        Route::get('/informes',                  [\App\Http\Controllers\Stock\InformesController::class, 'index']);
        Route::post('/informes/minimos',         [\App\Http\Controllers\Stock\InformesController::class, 'aplicarMinimos'])->middleware('permiso:stock,editar');
        Route::get('/etiquetas',                 [\App\Http\Controllers\Stock\InformesController::class, 'etiquetas']);
        Route::get('/verificador',               [\App\Http\Controllers\Stock\InformesController::class, 'verificador']);
        Route::get('/verificar',                 [\App\Http\Controllers\Stock\InformesController::class, 'verificar']);
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
        Route::get('/fiscal',                      [\App\Http\Controllers\Contable\FiscalController::class, 'index']);
        Route::get('/fiscal/exportar',             [\App\Http\Controllers\Contable\FiscalController::class, 'exportar'])->middleware('permiso:contable,exportar');
        Route::get('/fiscal/libro-digital',        [\App\Http\Controllers\Contable\FiscalController::class, 'libroDigital'])->middleware('permiso:contable,exportar');
        Route::post('/fiscal/arca/analizar',       [\App\Http\Controllers\Contable\FiscalController::class, 'arcaAnalizar'])->middleware('permiso:contable,crear');
        Route::post('/fiscal/arca/registrar',      [\App\Http\Controllers\Contable\FiscalController::class, 'arcaRegistrar'])->middleware('permiso:contable,crear');
        Route::get('/fiscal/retenciones/{id}/certificado', [\App\Http\Controllers\Contable\FiscalController::class, 'certificado']);
        Route::get('/ejercicio',                   [\App\Http\Controllers\Contable\EjercicioController::class, 'index']);
        Route::post('/ejercicio/cerrar',           [\App\Http\Controllers\Contable\EjercicioController::class, 'cerrar'])->middleware('permiso:contable,crear');
        Route::post('/ejercicio/{id}/reabrir',     [\App\Http\Controllers\Contable\EjercicioController::class, 'reabrir'])->middleware('permiso:contable,anular');
        Route::post('/ejercicio/ajuste',           [\App\Http\Controllers\Contable\EjercicioController::class, 'ajuste'])->middleware('permiso:contable,crear');
        Route::get('/diario',                      [\App\Http\Controllers\Contable\EjercicioController::class, 'diario']);
        Route::get('/contador',                    [\App\Http\Controllers\Contable\ContadorController::class, 'index']);
        Route::get('/cashflow',                    [\App\Http\Controllers\Contable\CashFlowController::class, 'index']);
        Route::get('/activos',                     [\App\Http\Controllers\Contable\ActivosController::class, 'index']);
        Route::post('/activos/amortizar',          [\App\Http\Controllers\Contable\ActivosController::class, 'amortizar'])->middleware('permiso:contable,crear');
        Route::post('/activos/{id?}',              [\App\Http\Controllers\Contable\ActivosController::class, 'guardar'])->middleware('permiso:contable,crear');
        Route::post('/activos/{id}/baja',          [\App\Http\Controllers\Contable\ActivosController::class, 'baja'])->middleware('permiso:contable,anular');
        Route::get('/contador/exportar',           [\App\Http\Controllers\Contable\ContadorController::class, 'exportar'])->middleware('permiso:contable,exportar');
        Route::post('/contador/invitar',           [\App\Http\Controllers\Contable\ContadorController::class, 'invitar'])->middleware('permiso:configuracion,editar');
    });

    Route::get('/estadisticas', [\App\Http\Controllers\Estadisticas\EstadisticasController::class, 'index'])->middleware('permiso:estadisticas');
    Route::get('/estadisticas/analista', [\App\Http\Controllers\Estadisticas\AnalistaController::class, 'index'])->middleware('permiso:estadisticas');
    Route::get('/buscar/{entidad}/{forma}', \App\Http\Controllers\BuscarController::class);
    Route::get('/estadisticas/rentabilidad', [\App\Http\Controllers\Estadisticas\RentabilidadController::class, 'index'])->middleware('permiso:estadisticas');
    Route::get('/estadisticas/rentabilidad/dim', [\App\Http\Controllers\Estadisticas\RentabilidadController::class, 'dimension'])->middleware('permiso:estadisticas');
    Route::post('/estadisticas/rentabilidad/categorias', [\App\Http\Controllers\Estadisticas\RentabilidadController::class, 'clasificar'])->middleware('permiso:fondos,editar');
    Route::post('/estadisticas/rentabilidad/config', [\App\Http\Controllers\Estadisticas\RentabilidadController::class, 'configurar'])->middleware('permiso:configuracion,editar');

    // Agenda de turnos
    Route::prefix('agenda')->middleware('permiso:agenda')->group(function () {
        Route::get('/',                 [\App\Http\Controllers\AgendaController::class, 'index']);
        Route::post('/turnos/{id?}',    [\App\Http\Controllers\AgendaController::class, 'guardar'])->middleware('permiso:agenda,crear');
        Route::post('/turnos/{id}/estado',   [\App\Http\Controllers\AgendaController::class, 'estado'])->middleware('permiso:agenda,editar');
        Route::post('/turnos/{id}/recordar', [\App\Http\Controllers\AgendaController::class, 'recordar'])->middleware('permiso:agenda,editar');
        Route::post('/turnos/{id}/cobrar',   [\App\Http\Controllers\AgendaController::class, 'cobrar'])->middleware('permiso:agenda,crear');
    });

    // Sueldos
    Route::prefix('sueldos')->middleware('permiso:sueldos')->group(function () {
        $c = \App\Http\Controllers\Sueldos\SueldosController::class;
        Route::get('/', [$c, 'index']);
        Route::post('/empleados/{id?}', [$c, 'guardarEmpleado'])->middleware('permiso:sueldos,crear');
        Route::post('/empleados/{id}/anticipo', [$c, 'anticipo'])->middleware('permiso:sueldos,crear');
        Route::post('/conceptos/{id?}', [$c, 'guardarConcepto'])->middleware('permiso:sueldos,editar');
        Route::post('/conceptos/{id}/borrar', [$c, 'borrarConcepto'])->middleware('permiso:sueldos,editar');
        Route::post('/config', [$c, 'guardarConfig'])->middleware('permiso:sueldos,editar');
        Route::post('/liquidar', [$c, 'liquidar'])->middleware('permiso:sueldos,crear');
        Route::post('/importar', [$c, 'importar'])->middleware('permiso:sueldos,crear');
        Route::post('/{id}/confirmar', [$c, 'confirmar'])->middleware('permiso:sueldos,crear');
        Route::post('/{id}/reabrir', [$c, 'reabrir'])->middleware('permiso:sueldos,anular');
        Route::post('/{id}/pagar', [$c, 'pagar'])->middleware('permiso:sueldos,crear');
        Route::post('/{id}/pagar-cargas', [$c, 'pagarCargas'])->middleware('permiso:sueldos,crear');
        Route::get('/{id}/recibo/{item?}', [$c, 'recibo']);
        Route::get('/{id}/libro', [$c, 'libro'])->middleware('permiso:sueldos,exportar');
    });

    // Obras y proyectos
    Route::prefix('obras')->middleware('permiso:obras')->group(function () {
        $c = \App\Http\Controllers\Obras\ObrasController::class;
        Route::get('/', [$c, 'index']);
        Route::post('/', [$c, 'guardar'])->middleware('permiso:obras,crear');
        Route::get('/{id}', [$c, 'ver']);
        Route::post('/{id}', [$c, 'guardar'])->middleware('permiso:obras,editar');
        Route::post('/{id}/partes', [$c, 'parte'])->middleware('permiso:obras,crear');
        Route::post('/{id}/partes/{parte}/borrar', [$c, 'borrarParte'])->middleware('permiso:obras,anular');
        Route::post('/{id}/certificar', [$c, 'certificar'])->middleware('permiso:obras,crear');
        Route::post('/{id}/vincular', [$c, 'vincular'])->middleware('permiso:obras,editar');
    });

    // Servicio técnico
    Route::prefix('servicios')->middleware('permiso:servicios')->group(function () {
        $c = \App\Http\Controllers\Servicios\OrdenesController::class;
        Route::get('/', [$c, 'index']);
        Route::post('/', [$c, 'crear'])->middleware('permiso:servicios,crear');
        Route::get('/{id}', [$c, 'ver']);
        Route::post('/{id}', [$c, 'actualizar'])->middleware('permiso:servicios,editar');
        Route::post('/{id}/estado', [$c, 'estado'])->middleware('permiso:servicios,editar');
        Route::post('/{id}/items', [$c, 'item'])->middleware('permiso:servicios,editar');
        Route::post('/{id}/items/{item}/borrar', [$c, 'borrarItem'])->middleware('permiso:servicios,editar');
        Route::post('/{id}/tareas', [$c, 'tarea'])->middleware('permiso:servicios,editar');
        Route::post('/{id}/tareas/{tarea}/hecha', [$c, 'tareaHecha'])->middleware('permiso:servicios,editar');
        Route::post('/{id}/firmar', [$c, 'firmar'])->middleware('permiso:servicios,editar');
        Route::post('/{id}/facturar', [$c, 'facturar'])->middleware('permiso:servicios,crear');
    });

    // Hotelería
    Route::prefix('hoteleria')->middleware('permiso:hoteleria')->group(function () {
        $c = \App\Http\Controllers\Hoteleria\HoteleriaController::class;
        Route::get('/', [$c, 'index']);
        Route::post('/habitaciones/{id?}', [$c, 'guardarHabitacion'])->middleware('permiso:hoteleria,editar');
        Route::post('/habitaciones/{id}/estado', [$c, 'estadoHabitacion'])->middleware('permiso:hoteleria,editar');
        Route::post('/reservas/{id?}', [$c, 'reservar'])->middleware('permiso:hoteleria,crear');
        Route::get('/estadias/{id}', [$c, 'estadia']);
        Route::post('/estadias/{id}/checkin', [$c, 'checkin'])->middleware('permiso:hoteleria,editar');
        Route::post('/estadias/{id}/consumos', [$c, 'consumo'])->middleware('permiso:hoteleria,crear');
        Route::post('/estadias/{id}/consumos/{consumo}/borrar', [$c, 'borrarConsumo'])->middleware('permiso:hoteleria,editar');
        Route::post('/estadias/{id}/senia', [$c, 'senia'])->middleware('permiso:hoteleria,crear');
        Route::post('/estadias/{id}/checkout', [$c, 'checkout'])->middleware('permiso:hoteleria,crear');
        Route::post('/estadias/{id}/cancelar', [$c, 'cancelar'])->middleware('permiso:hoteleria,anular');
    });

    // Envíos por mail / WhatsApp desde cualquier pantalla
    Route::post('/envios',              [\App\Http\Controllers\EnviosController::class, 'enviar']);
    Route::get('/envios/borrador',      [\App\Http\Controllers\EnviosController::class, 'borrador']);
    Route::post('/envios/{id}/marcar',  [\App\Http\Controllers\EnviosController::class, 'marcar']);

    // Punto de venta (comercio y minimarket comparten pantalla)
    foreach (['retail', 'minimarket'] as $v) {
        Route::prefix($v)->middleware("permiso:{$v}")->group(function () use ($v) {
            Route::get('/',            [\App\Http\Controllers\Pos\PosController::class, 'index']);
            Route::get('/buscar',      [\App\Http\Controllers\Pos\PosController::class, 'buscar']);
            Route::post('/vender',     [\App\Http\Controllers\Pos\PosController::class, 'vender'])->middleware("permiso:{$v},crear");
            Route::get('/ticket/{id}', [\App\Http\Controllers\Pos\PosController::class, 'ticket']);
            Route::get('/ticket/{id}/escpos', [\App\Http\Controllers\Pos\PosController::class, 'escpos']);
        });
    }

    // Gastronomía
    Route::prefix('gastronomia')->middleware('permiso:gastronomia')->group(function () {
        Route::get('/',                               [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'mesas']);
        Route::post('/mesas/{id?}',                   [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'guardarMesa'])->middleware('permiso:gastronomia,editar');
        Route::delete('/mesas/{id}',                  [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'eliminarMesa'])->middleware('permiso:gastronomia,editar');
        Route::post('/comandas',                      [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'abrir'])->middleware('permiso:gastronomia,crear');
        Route::get('/comandas/{id}',                  [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'comanda']);
        Route::post('/comandas/{id}/items',           [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'agregar'])->middleware('permiso:gastronomia,crear');
        Route::delete('/comandas/{id}/items/{item}',  [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'quitar'])->middleware('permiso:gastronomia,crear');
        Route::post('/comandas/{id}/enviar',          [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'enviar'])->middleware('permiso:gastronomia,crear');
        Route::post('/comandas/{id}/cuenta',          [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'cuenta'])->middleware('permiso:gastronomia,crear');
        Route::post('/comandas/{id}/mover',           [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'mover'])->middleware('permiso:gastronomia,crear');
        Route::post('/comandas/{id}/cerrar',          [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'cerrar'])->middleware('permiso:gastronomia,crear');
        Route::post('/comandas/{id}/anular',          [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'anular'])->middleware('permiso:gastronomia,editar');
        Route::post('/items/{item}/estado',           [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'itemEstado'])->middleware('permiso:gastronomia,editar');
        Route::get('/cocina',                         [\App\Http\Controllers\Gastronomia\GastronomiaController::class, 'cocina']);
        Route::get('/reservas',                       [\App\Http\Controllers\Gastronomia\ReservasController::class, 'index']);
        Route::post('/reservas',                      [\App\Http\Controllers\Gastronomia\ReservasController::class, 'guardar'])->middleware('permiso:gastronomia,crear');
        Route::post('/reservas/{id}/estado',          [\App\Http\Controllers\Gastronomia\ReservasController::class, 'estado'])->middleware('permiso:gastronomia,editar');
    });

    Route::prefix('configuracion')->middleware('permiso:configuracion')->name('configuracion.')->group(function () {
        Route::get('/',            [EmpresaController::class, 'index'])->name('empresa');
        Route::post('/empresa',    [EmpresaController::class, 'guardar'])->middleware('permiso:configuracion,editar')->name('empresa.guardar');
        Route::post('/empresa/avisos', [EmpresaController::class, 'guardarAvisos'])->middleware('permiso:configuracion,editar');
        Route::post('/empresa/avisos/resumen', [EmpresaController::class, 'resumenAhora'])->middleware('permiso:configuracion,editar');
        Route::post('/empresa/pos', [EmpresaController::class, 'guardarPos'])->middleware('permiso:configuracion,editar');
        Route::post('/empresa/verticales', [EmpresaController::class, 'guardarVerticales'])->middleware('permiso:configuracion,editar');

        Route::get('/sucursales',            [SucursalesController::class, 'index'])->name('sucursales');
        Route::post('/sucursales/{id?}',     [SucursalesController::class, 'guardar'])->middleware('permiso:configuracion,editar')->name('sucursales.guardar');

        Route::get('/usuarios',              [UsuariosController::class, 'index'])->name('usuarios');
        Route::post('/usuarios/{id?}',       [UsuariosController::class, 'guardar'])->middleware('permiso:configuracion,editar')->name('usuarios.guardar');

        Route::get('/roles',                 [RolesController::class, 'index'])->name('roles');
        Route::post('/roles/{id?}',          [RolesController::class, 'guardar'])->middleware('permiso:configuracion,editar')->name('roles.guardar');
        Route::delete('/roles/{id}',         [RolesController::class, 'eliminar'])->middleware('permiso:configuracion,anular')->name('roles.eliminar');

        Route::get('/auditoria',             [AuditoriaController::class, 'index'])->name('auditoria');
        Route::get('/importar',              [\App\Http\Controllers\Configuracion\ImportarController::class, 'index'])->middleware('permiso:configuracion,editar');
        Route::post('/importar/previsualizar', [\App\Http\Controllers\Configuracion\ImportarController::class, 'previsualizar'])->middleware('permiso:configuracion,editar');
        Route::post('/importar/aplicar',     [\App\Http\Controllers\Configuracion\ImportarController::class, 'aplicar'])->middleware('permiso:configuracion,editar');
        Route::get('/importar/plantilla/{entidad}', [\App\Http\Controllers\Configuracion\ImportarController::class, 'plantilla']);
        Route::get('/importar/exportar/{entidad}',  [\App\Http\Controllers\Configuracion\ImportarController::class, 'exportar'])->middleware('permiso:configuracion,exportar');
        Route::get('/datos',                 [\App\Http\Controllers\Configuracion\DatosController::class, 'index']);
        Route::post('/datos/backup',         [\App\Http\Controllers\Configuracion\DatosController::class, 'crear'])->middleware('permiso:configuracion,editar');
        Route::get('/datos/backups/{id}',    [\App\Http\Controllers\Configuracion\DatosController::class, 'descargar'])->middleware('permiso:configuracion,editar');
        Route::delete('/datos/backups/{id}', [\App\Http\Controllers\Configuracion\DatosController::class, 'eliminar'])->middleware('permiso:configuracion,editar');
        Route::post('/datos/restaurar',      [\App\Http\Controllers\Configuracion\DatosController::class, 'restaurar'])->middleware('permiso:configuracion,editar');
        Route::post('/datos/backup-auto',    [\App\Http\Controllers\Configuracion\DatosController::class, 'backupAuto'])->middleware('permiso:configuracion,editar');
        Route::get('/seguridad',             [\App\Http\Controllers\Configuracion\SeguridadController::class, 'index']);
        Route::post('/seguridad/2fa/iniciar',   [\App\Http\Controllers\Configuracion\SeguridadController::class, 'iniciar2fa']);
        Route::post('/seguridad/2fa/confirmar', [\App\Http\Controllers\Configuracion\SeguridadController::class, 'confirmar2fa']);
        Route::post('/seguridad/2fa/desactivar', [\App\Http\Controllers\Configuracion\SeguridadController::class, 'desactivar2fa']);
        Route::post('/seguridad/password',   [\App\Http\Controllers\Configuracion\SeguridadController::class, 'cambiarPassword']);
        Route::post('/seguridad/sesiones/cerrar', [\App\Http\Controllers\Configuracion\SeguridadController::class, 'cerrarSesiones']);
        Route::delete('/seguridad/sesiones/{id}', [\App\Http\Controllers\Configuracion\SeguridadController::class, 'cerrarSesion']);
        Route::post('/seguridad/tokens',     [\App\Http\Controllers\Configuracion\SeguridadController::class, 'crearToken'])->middleware('permiso:configuracion,editar');
        Route::delete('/seguridad/tokens/{id}', [\App\Http\Controllers\Configuracion\SeguridadController::class, 'borrarToken'])->middleware('permiso:configuracion,editar');
        Route::post('/seguridad/webhooks/{id?}', [\App\Http\Controllers\Configuracion\SeguridadController::class, 'guardarWebhook'])->middleware('permiso:configuracion,editar');
        Route::delete('/seguridad/webhooks/{id}', [\App\Http\Controllers\Configuracion\SeguridadController::class, 'borrarWebhook'])->middleware('permiso:configuracion,editar');
        Route::post('/seguridad/webhooks/{id}/probar', [\App\Http\Controllers\Configuracion\SeguridadController::class, 'probarWebhook'])->middleware('permiso:configuracion,editar');
        Route::get('/tienda',                [\App\Http\Controllers\Configuracion\TiendaController::class, 'index']);
        Route::post('/tienda',               [\App\Http\Controllers\Configuracion\TiendaController::class, 'guardar'])->middleware('permiso:configuracion,editar');
        Route::post('/tienda/fidelizacion',  [\App\Http\Controllers\Configuracion\TiendaController::class, 'guardarFidelizacion'])->middleware('permiso:configuracion,editar');
        Route::post('/tienda/articulos',     [\App\Http\Controllers\Configuracion\TiendaController::class, 'articulos'])->middleware('permiso:configuracion,editar');
        Route::post('/tienda/whatsapp',      [\App\Http\Controllers\Configuracion\TiendaController::class, 'guardarWhatsapp'])->middleware('permiso:configuracion,editar');
        Route::post('/tienda/canales/{id?}', [\App\Http\Controllers\Configuracion\TiendaController::class, 'guardarCanal'])->middleware('permiso:configuracion,editar');
        Route::delete('/tienda/canales/{id}', [\App\Http\Controllers\Configuracion\TiendaController::class, 'borrarCanal'])->middleware('permiso:configuracion,editar');
        Route::post('/tienda/canales/{id}/sincronizar', [\App\Http\Controllers\Configuracion\TiendaController::class, 'sincronizar'])->middleware('permiso:configuracion,editar');
        Route::get('/impuestos',             [\App\Http\Controllers\Configuracion\ImpuestosController::class, 'index'])->name('impuestos');
        Route::post('/impuestos',            [\App\Http\Controllers\Configuracion\ImpuestosController::class, 'guardar'])->middleware('permiso:configuracion,editar');
        Route::post('/impuestos/padron',     [\App\Http\Controllers\Configuracion\ImpuestosController::class, 'importarPadron'])->middleware('permiso:configuracion,editar');
        Route::post('/impuestos/ipc',        [\App\Http\Controllers\Configuracion\ImpuestosController::class, 'guardarIpc'])->middleware('permiso:configuracion,editar');
        Route::post('/impuestos/ipc/actualizar', [\App\Http\Controllers\Configuracion\ImpuestosController::class, 'actualizarIpc'])->middleware('permiso:configuracion,editar');

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
