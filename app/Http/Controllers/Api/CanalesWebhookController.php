<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Canal;
use App\Services\Canales\CanalesService;
use App\Services\Canales\ConexionesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Entrada de pedidos desde marketplaces, tiendas y delivery. Cada canal tiene su URL con token; se verifica la firma cuando la
// tienda la manda (WooCommerce, Shopify, Tienda Nube) y se acepta el JSON nativo de la plataforma o el formato genérico.
class CanalesWebhookController extends Controller
{
    public function __construct(private CanalesService $svc, private ConexionesService $cx) {}

    public function entrada(string $tipo, string $token, Request $request)
    {
        $c = Canal::withoutGlobalScopes()->where('tipo', $tipo)->where('token_entrada', $token)->where('activo', true)->first();
        if (! $c) return response()->json(['error' => 'Canal no encontrado'], 404);
        if (! $this->cx->firmaValida($c, $request)) return response()->json(['error' => 'Firma inválida'], 401);
        $raw = $request->all();
        $evento = (string) ($request->header('X-Shopify-Topic') ?: $request->header('X-WC-Webhook-Topic') ?: ($raw['event'] ?? ''));
        // La tienda desinstaló la app: se borran las claves y los avisos; lo publicado y los pedidos quedan.
        if (in_array($evento, ['app/uninstalled'], true)) { $this->cx->desconectar($c); return response()->json(['ok' => true, 'desconectado' => true]); }
        // Tienda Nube avisa con el id del pedido: hay que ir a buscarlo.
        if ($tipo === 'tiendanube' && isset($raw['id']) && ! isset($raw['products'])) {
            if (! in_array($evento, ['order/created', 'order/paid', 'order/updated', ''], true)) return response()->json(['ok' => true, 'ignorado' => $evento]);
            $r = $this->cx->tn($c)->get("orders/{$raw['id']}");
            if (! $r->ok()) return response()->json(['ok' => false, 'error' => 'No se pudo leer el pedido en Tienda Nube'], 202);
            $raw = $r->json();
        }
        // MercadoLibre manda solo una notificación con el recurso: hay que ir a buscar el pedido.
        if ($tipo === 'mercadolibre' && isset($raw['resource']) && ! isset($raw['order_items'])) {
            if (! str_starts_with((string) $raw['resource'], '/orders/')) return response()->json(['ok' => true, 'ignorado' => $raw['topic'] ?? null]);
            $r = $this->cx->ml($c)->get($raw['resource']);
            if (! $r->ok()) return response()->json(['ok' => false, 'error' => 'No se pudo leer el pedido en ML'], 202);
            $raw = $r->json();
        }
        return response()->json($this->procesar($c, $raw, $tipo));
    }

    private function procesar(Canal $c, array $raw, string $tipo): array
    {
        $pedidos = isset($raw[0]) && is_array($raw[0]) ? $raw : [$raw];
        $n = 0; $ids = [];
        foreach ($pedidos as $p) {
            try {
                $norm = $this->svc->normalizar($tipo, $p);
                // Una compra sin pagar espera y entra cuando se paga; una cancelada devuelve el stock si ya había entrado.
                if (! empty($norm['cancelado'])) { $this->svc->cancelado($c, $norm['external_id']); continue; }
                if (! empty($norm['sin_pagar'])) continue;
                if ($norm['items']) { if ($pw = $this->svc->alta($c->business, $c, $norm)) { $n++; $ids[] = $pw->numeroFormateado(); } }
            } catch (\Throwable $e) { $c->forceFill(['ultimo_error' => mb_substr($e->getMessage(), 0, 300)])->save(); }
        }
        $c->forceFill(['ultimo_sync_en' => now(), 'pedidos_importados' => $c->pedidos_importados + $n])->save();
        return ['ok' => true, 'pedidos' => $n, 'numeros' => $ids];
    }

    // MercadoLibre manda todas las notificaciones a la URL de la aplicación: se busca el canal por el user_id del vendedor.
    public function mercadolibre(Request $request)
    {
        $uid = (string) $request->input('user_id');
        $c = Canal::withoutGlobalScopes()->where('tipo', 'mercadolibre')->where('external_user_id', $uid)->where('activo', true)->first();
        if (! $c) return response()->json(['ok' => true, 'ignorado' => 'vendedor desconocido']);
        $topic = (string) $request->input('topic'); $resource = (string) $request->input('resource');
        if (! in_array($topic, ['orders_v2', 'orders'], true) || ! str_starts_with($resource, '/orders/')) return response()->json(['ok' => true, 'ignorado' => $topic]);
        $r = $this->cx->ml($c)->get($resource);
        if (! $r->ok()) return response()->json(['ok' => false, 'error' => 'No se pudo leer el pedido en ML'], 202);
        return response()->json($this->procesar($c, $r->json(), 'mercadolibre'));
    }

    // WooCommerce manda las claves acá (callback_url) cuando el usuario aprueba el acceso; después lo trae de vuelta al panel.
    public function clavesWoo(Request $request)
    {
        $this->cx->recibirClavesWoo($request);
        return response()->json(['ok' => true]);
    }
}
