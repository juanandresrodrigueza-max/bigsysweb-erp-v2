<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Canal;
use App\Services\Canales\CanalesService;
use Illuminate\Http\Request;

// Entrada de pedidos desde marketplaces y delivery. Cada canal tiene su URL con token; acepta el JSON nativo de la plataforma o el formato genérico.
class CanalesWebhookController extends Controller
{
    public function entrada(string $tipo, string $token, Request $request, CanalesService $svc)
    {
        $c = Canal::withoutGlobalScopes()->where('tipo', $tipo)->where('token_entrada', $token)->where('activo', true)->first();
        if (! $c) return response()->json(['error' => 'Canal no encontrado'], 404);
        $raw = $request->all();
        // MercadoLibre manda solo una notificación con el recurso: hay que ir a buscar el pedido.
        if ($tipo === 'mercadolibre' && isset($raw['resource']) && ! isset($raw['order_items'])) {
            $r = \Illuminate\Support\Facades\Http::withToken($c->credenciales['access_token'] ?? '')->timeout(10)->get('https://api.mercadolibre.com' . $raw['resource']);
            if (! $r->ok()) return response()->json(['ok' => false, 'error' => 'No se pudo leer el pedido en ML'], 202);
            $raw = $r->json();
        }
        $pedidos = isset($raw[0]) && is_array($raw[0]) ? $raw : [$raw];
        $n = 0; $ids = [];
        foreach ($pedidos as $p) {
            try { $norm = $svc->normalizar($tipo, $p); if ($norm['items']) { if ($pw = $svc->alta($c->business, $c, $norm)) { $n++; $ids[] = $pw->numeroFormateado(); } } }
            catch (\Throwable $e) { $c->forceFill(['ultimo_error' => mb_substr($e->getMessage(), 0, 300)])->save(); }
        }
        $c->forceFill(['ultimo_sync_en' => now(), 'pedidos_importados' => $c->pedidos_importados + $n])->save();
        return response()->json(['ok' => true, 'pedidos' => $n, 'numeros' => $ids]);
    }
}
