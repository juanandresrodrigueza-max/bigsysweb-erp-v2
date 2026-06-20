<?php
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StockMovementController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login',    [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',      [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('business', [BusinessController::class, 'show']);
    Route::put('business', [BusinessController::class, 'update']);

    Route::apiResource('contacts',         ContactController::class);
    Route::apiResource('customers',        CustomerController::class);
    Route::apiResource('products',         ProductController::class);
    Route::apiResource('sales',            SaleController::class);
    Route::apiResource('purchases',        PurchaseController::class);
    Route::apiResource('expenses',         ExpenseController::class);
    Route::apiResource('stock-movements',  StockMovementController::class)->only(['index', 'store']);

    Route::prefix('cash-registers')->group(function () {
        Route::get('/',                     [CashRegisterController::class, 'index']);
        Route::post('{cashRegister}/open',  [CashRegisterController::class, 'open']);
        Route::post('{cashRegister}/close', [CashRegisterController::class, 'close']);
    });

    Route::prefix('invoices')->group(function () {
        Route::get('{sale}/preview',      [InvoiceController::class, 'preview']);
        Route::post('{sale}/issue',       [InvoiceController::class, 'issue']);
        Route::post('upload-certificate', [InvoiceController::class, 'uploadCertificate']);
    });

    Route::prefix('reports')->group(function () {
        Route::get('sales',        [ReportController::class, 'salesSummary']);
        Route::get('top-products', [ReportController::class, 'topProducts']);
        Route::get('stock-alerts', [ReportController::class, 'stockAlerts']);
        Route::get('customers',    [ReportController::class, 'customerStats']);
    });
});

    // MercadoPago
    Route::prefix('mercadopago')->group(function () {
        Route::post('configure',                    [App\Http\Controllers\Api\MercadoPagoController::class, 'configure']);
        Route::post('sales/{sale}/preference',      [App\Http\Controllers\Api\MercadoPagoController::class, 'createPreference']);
        Route::get('payments/{paymentId}/status',   [App\Http\Controllers\Api\MercadoPagoController::class, 'paymentStatus']);
    });

Route::post('mercadopago/webhook', [App\Http\Controllers\Api\MercadoPagoController::class, 'webhook']);

    // Tiendanube
    Route::prefix('tiendanube')->group(function () {
        Route::post('configure',   [App\Http\Controllers\Api\TiendanubeController::class, 'configure']);
        Route::post('sync-orders', [App\Http\Controllers\Api\TiendanubeController::class, 'syncOrders']);
        Route::post('sync-stock',  [App\Http\Controllers\Api\TiendanubeController::class, 'syncStock']);
    });

Route::post('tiendanube/webhook', [App\Http\Controllers\Api\TiendanubeController::class, 'webhook']);
