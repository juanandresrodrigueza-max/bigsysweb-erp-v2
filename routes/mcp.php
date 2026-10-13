<?php

use App\Http\Controllers\Mcp\McpController;
use App\Http\Controllers\Mcp\OAuthController;
use Illuminate\Support\Facades\Route;

// Fase 28: servidor MCP y OAuth para clientes de IA. Sin sesión ni CSRF: se autentica con Bearer.
Route::middleware('throttle:120,1')->group(function () {
    Route::post('/mcp', [McpController::class, 'handle']);
    Route::get('/mcp', [McpController::class, 'get']);
    Route::get('/.well-known/oauth-protected-resource', [OAuthController::class, 'recurso']);
    Route::get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'recurso']);
    Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'servidor']);
    Route::get('/.well-known/oauth-authorization-server/mcp', [OAuthController::class, 'servidor']);
    Route::post('/oauth/register', [OAuthController::class, 'registrar'])->middleware('throttle:20,1');
    Route::post('/oauth/token', [OAuthController::class, 'token'])->middleware('throttle:30,1');
});
