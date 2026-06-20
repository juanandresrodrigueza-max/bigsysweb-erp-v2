<?php
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CrmLeadController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MercadoPagoController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductionOrderController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\TiendanubeController;
use Illuminate\Support\Facades\Route;

// Auth (público)
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login',    [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',      [AuthController::class, 'me']);
    });
});

// Webhooks (público)
Route::post('mercadopago/webhook', [MercadoPagoController::class, 'webhook']);
Route::post('tiendanube/webhook',  [TiendanubeController::class, 'webhook']);

// Todo lo demás requiere autenticación
Route::middleware('auth:sanctum')->group(function () {

    Route::get('business', [BusinessController::class, 'show']);
    Route::put('business', [BusinessController::class, 'update']);

    Route::apiResource('contacts',        ContactController::class);
    Route::apiResource('customers',       CustomerController::class);
    Route::apiResource('products',        ProductController::class);
    Route::apiResource('sales',           SaleController::class);
    Route::apiResource('purchases',       PurchaseController::class);
    Route::apiResource('expenses',        ExpenseController::class);
    Route::apiResource('stock-movements', StockMovementController::class)->only(['index', 'store']);

    // Caja
    Route::prefix('cash-registers')->group(function () {
        Route::get('/',                     [CashRegisterController::class, 'index']);
        Route::post('{cashRegister}/open',  [CashRegisterController::class, 'open']);
        Route::post('{cashRegister}/close', [CashRegisterController::class, 'close']);
    });

    // Facturación AFIP
    Route::prefix('invoices')->group(function () {
        Route::get('{sale}/preview',      [InvoiceController::class, 'preview']);
        Route::post('{sale}/issue',       [InvoiceController::class, 'issue']);
        Route::post('upload-certificate', [InvoiceController::class, 'uploadCertificate']);
    });

    // Reportes
    Route::prefix('reports')->group(function () {
        Route::get('sales',        [ReportController::class, 'salesSummary']);
        Route::get('top-products', [ReportController::class, 'topProducts']);
        Route::get('stock-alerts', [ReportController::class, 'stockAlerts']);
        Route::get('customers',    [ReportController::class, 'customerStats']);
    });

    // MercadoPago
    Route::prefix('mercadopago')->group(function () {
        Route::post('configure',               [MercadoPagoController::class, 'configure']);
        Route::post('sales/{sale}/preference', [MercadoPagoController::class, 'createPreference']);
        Route::get('payments/{id}/status',     [MercadoPagoController::class, 'paymentStatus']);
    });

    // Tiendanube
    Route::prefix('tiendanube')->group(function () {
        Route::post('configure',   [TiendanubeController::class, 'configure']);
        Route::post('sync-orders', [TiendanubeController::class, 'syncOrders']);
        Route::post('sync-stock',  [TiendanubeController::class, 'syncStock']);
    });

    // CRM
    Route::prefix('crm')->group(function () {
        Route::get('pipeline', [CrmLeadController::class, 'pipeline']);
        Route::apiResource('leads', CrmLeadController::class);
        Route::post('leads/{crmLead}/activities',          [CrmLeadController::class, 'addActivity']);
        Route::post('activities/{crmActivity}/complete',   [CrmLeadController::class, 'completeActivity']);
    });

    // Manufactura
    Route::apiResource('recipes', RecipeController::class);
    Route::get('production-orders',                          [ProductionOrderController::class, 'index']);
    Route::post('production-orders',                         [ProductionOrderController::class, 'store']);
    Route::get('production-orders/{productionOrder}',        [ProductionOrderController::class, 'show']);
    Route::post('production-orders/{productionOrder}/start',    [ProductionOrderController::class, 'start']);
    Route::post('production-orders/{productionOrder}/complete', [ProductionOrderController::class, 'complete']);
    Route::post('production-orders/{productionOrder}/cancel',   [ProductionOrderController::class, 'cancel']);

    // Reservas
    Route::get('bookings/calendar', [BookingController::class, 'calendar']);
    Route::apiResource('bookings',  BookingController::class);
});
