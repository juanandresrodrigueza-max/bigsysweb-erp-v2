<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alerta;
use App\Models\Contact;
use App\Models\PedidoWeb;
use App\Models\Product;
use App\Services\Canales\TiendaService;
use App\Services\Integraciones\CrmSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Fase 116 · Pedidos de tiendas (Woo, Tienda Nube, Shopify, Mercado Libre, Empretienda) que entran por el CRM: con el ERP conectado,
// la venta se hace acá. El pedido nace como pedido web del canal, se confirma al toque como comprobante interno (descuenta stock,
// numera aparte) y queda para pasar a fiscal con «Facturar» (fase 104). Idempotente por crm_order_id.
class CrmPedidosController extends Controller
{
    public const CANAL = ['woo' => 'woocommerce', 'woocommerce' => 'woocommerce', 'tiendanube' => 'tiendanube', 'shopify' => 'shopify', 'mercadolibre' => 'mercadolibre', 'ml' => 'mercadolibre', 'empretienda' => 'empretienda', 'tienda' => 'tienda', 'otro' => 'tienda'];

    public function recibir(Request $r, TiendaService $tienda)
    {
        $d = $r->validate([
            'crm_order_id' => 'required|string|max:60', 'origen' => 'required|in:' . implode(',', array_keys(self::CANAL)), 'numero' => 'nullable|string|max:60', 'fecha' => 'nullable|date',
            'cliente' => 'required|array', 'cliente.nombre' => 'required|string|max:160', 'cliente.crm_contact_id' => 'nullable', 'cliente.cuit' => 'nullable|string|max:20', 'cliente.email' => 'nullable|email|max:150',
            'cliente.telefono' => 'nullable|string|max:40', 'cliente.direccion' => 'nullable|string|max:255', 'cliente.condicion_iva' => 'nullable|string|max:40',
            'items' => 'required|array|min:1|max:200', 'items.*.descripcion' => 'required|string|max:255', 'items.*.cantidad' => 'required|numeric|min:0.0001', 'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.sku' => 'nullable|string|max:80', 'items.*.code' => 'nullable|string|max:80', 'items.*.external_id' => 'nullable',
            'total' => 'nullable|numeric|min:0', 'envio' => 'nullable|numeric|min:0', 'pagado' => 'nullable|boolean', 'medio_pago' => 'nullable|string|max:60', 'notas' => 'nullable|string|max:500',
            'entrega' => 'nullable|in:envio,retiro', 'confirmar' => 'nullable|boolean',
        ]);
        $b = $r->user()->business; $canal = self::CANAL[$d['origen']]; $crmId = (string) $d['crm_order_id'];
        // Las tiendas avisan dos veces: el mismo pedido no entra dos veces.
        if ($ya = PedidoWeb::withoutGlobalScopes()->where('business_id', $b->id)->where('crm_order_id', $crmId)->first()) return response()->json(['data' => $this->salida($ya) + ['duplicado' => true]], 200);

        $contact = CrmSyncService::contactoPara($b, $d['cliente']);
        // Ítems: por external_id (id del artículo en el ERP), por SKU o barras; sin artículo, renglón libre y aviso (el pedido no se rechaza).
        $items = []; $sinArticulo = [];
        foreach ($d['items'] as $it) {
            $prod = null;
            if (! empty($it['external_id']) && ctype_digit((string) $it['external_id'])) $prod = Product::withoutGlobalScopes()->where('business_id', $b->id)->find((int) $it['external_id']);
            foreach (array_filter([$it['sku'] ?? null, $it['code'] ?? null]) as $cod) { if ($prod) break; $prod = Product::withoutGlobalScopes()->where('business_id', $b->id)->where(fn($w) => $w->where('sku', $cod)->orWhere('barcode', $cod))->first(); }
            if (! $prod) $sinArticulo[] = $it['descripcion'] . (($it['sku'] ?? $it['code'] ?? null) ? ' (' . ($it['sku'] ?? $it['code']) . ')' : '');
            $items[] = ['product_id' => $prod?->id, 'descripcion' => $it['descripcion'], 'cantidad' => (float) $it['cantidad'], 'precio_unit' => (float) $it['precio_unitario'], 'external_item' => $it['external_id'] ?? null];
        }
        $cli = $d['cliente'];
        $pedido = $tienda->crearPedido($b, [
            'items' => $items, 'precio_del_canal' => true, 'entrega' => $d['entrega'] ?? 'envio', 'envio' => (float) ($d['envio'] ?? 0),
            'pago' => ! empty($d['pagado']) ? 'pagado_externo' : 'a_convenir', 'external_id' => $d['numero'] ?? $crmId,
            'cliente' => ['nombre' => $cli['nombre'], 'cuit' => $cli['cuit'] ?? $contact->cuit, 'condicion_iva' => $cli['condicion_iva'] ?? null, 'email' => $cli['email'] ?? $contact->email, 'telefono' => $cli['telefono'] ?? $contact->mobile, 'direccion' => $cli['direccion'] ?? null, 'notas' => $d['notas'] ?? null],
            'notas' => trim('Pedido ' . ($d['numero'] ?? $crmId) . ' de ' . (PedidoWeb::CANALES[$canal] ?? $canal) . ' vía CRM' . (! empty($d['medio_pago']) ? " · pago: {$d['medio_pago']}" : '') . (! empty($d['notas']) ? " · {$d['notas']}" : '')),
        ], $canal, $contact);
        if (isset($d['total']) && abs((float) $d['total'] - (float) $pedido->total) > 0.01) $pedido->forceFill(['descuento' => round((float) $pedido->subtotal + (float) $pedido->envio - (float) $d['total'], 2), 'total' => (float) $d['total']])->save();
        $pedido->forceFill(['crm_order_id' => $crmId])->save();
        if ($sinArticulo) Alerta::emitir(['business_id' => $b->id, 'business_location_id' => $pedido->business_location_id, 'modulo' => 'comprobantes', 'tipo' => 'crm_pedido_sin_articulo', 'modelo' => 'PedidoWeb', 'modelo_id' => $pedido->id, 'severidad' => 'aviso',
            'titulo' => "Pedido {$pedido->numeroFormateado()} (CRM): " . count($sinArticulo) . ' renglón(es) sin artículo', 'detalle' => 'Entraron como renglón libre, sin mover stock: ' . implode(', ', array_slice($sinArticulo, 0, 8)) . '. Emparejá el SKU en Stock o corregí el comprobante.', 'url' => "/comprobantes/pedidos?abrir={$pedido->id}"]);

        // La venta: comprobante interno emitido (descuenta stock, numera aparte) para pasar a fiscal con «Facturar».
        $aviso = null;
        if ($d['confirmar'] ?? true) {
            try { $pedido = $tienda->confirmar($pedido, ['tipo' => 'FX', 'sin_arca' => true, 'emitir' => true, 'contact_id' => $contact->id]); }
            catch (\Throwable $e) { $aviso = 'El pedido entró pero no se pudo confirmar solo: ' . mb_substr($e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage(), 0, 200) . ' Queda en Vender → Pedidos web para confirmarlo a mano.'; Log::warning('CRM pedido sin confirmar', ['pedido' => $pedido->id, 'e' => $e->getMessage()]); }
        }
        return response()->json(['data' => $this->salida($pedido->fresh()) + ['duplicado' => false, 'items_sin_articulo' => $sinArticulo, 'aviso' => $aviso]], 201);
    }

    private function salida(PedidoWeb $p): array
    {
        $c = $p->comprobante_id ? \App\Models\Comprobante::withoutGlobalScopes()->find($p->comprobante_id) : null;
        return ['id' => $p->id, 'numero' => $p->numeroFormateado(), 'crm_order_id' => $p->crm_order_id, 'canal' => $p->canal, 'estado' => $p->estado, 'total' => (float) $p->total,
            'cliente' => $p->contact_id ? ['id' => $p->contact_id, 'nombre' => Contact::withoutGlobalScopes()->find($p->contact_id)?->name] : null,
            'comprobante' => $c ? ['id' => $c->id, 'tipo' => $c->tipo, 'numero' => $c->numeroFormateado(), 'interno' => (bool) $c->sin_arca, 'estado' => $c->estado, 'total' => (float) $c->total, 'url' => url("/comprobantes/{$c->id}")] : null];
    }
}
