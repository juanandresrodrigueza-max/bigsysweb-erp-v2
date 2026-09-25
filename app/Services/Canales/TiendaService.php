<?php

namespace App\Services\Canales;

use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Contact;
use App\Models\PedidoWeb;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Ventas\LinkPagoService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Tienda propia y pedidos web: catálogo público, carrito, alta del pedido y su conversión en factura.
class TiendaService
{
    public const DEFAULT = [
        'activa' => false, 'slug' => null, 'nombre' => null, 'descripcion' => null, 'whatsapp' => null, 'color' => '#e4003f',
        'retiro' => true, 'envio' => true, 'costo_envio' => 0, 'envio_gratis_desde' => 0, 'zona_envio' => null,
        'pagos' => ['link' => true, 'transferencia' => true, 'efectivo' => true], 'cbu' => null, 'alias' => null,
        'lista_precios' => 1, 'iva_incluido' => true, 'minimo_pedido' => 0, 'rubros' => [], 'mostrar_stock' => false, 'menu_activo' => false, 'reservas_activas' => false, 'reservas_horario' => '12:00-15:00, 20:00-00:00', 'reservas_capacidad' => 40,
    ];

    public function __construct(private ComprobanteService $comprobantes, private LinkPagoService $links) {}

    public function config(Business $b): array
    {
        $c = array_replace_recursive(self::DEFAULT, $b->tienda ?? []);
        $c['slug'] = $c['slug'] ?: $b->slug; $c['nombre'] = $c['nombre'] ?: $b->name;
        return $c;
    }

    public static function porSlug(string $slug): ?Business
    {
        return Business::where('is_active', true)->get()->first(fn($b) => (($b->tienda['slug'] ?? null) ?: $b->slug) === $slug);
    }

    // Catálogo público: artículos activos marcados "en tienda", precio final según lista e IVA incluido.
    public function catalogo(Business $b, ?int $lista = null): array
    {
        $cfg = $this->config($b);
        $lista = $lista ?: (int) $cfg['lista_precios'];
        $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $q = Product::withoutGlobalScopes()->where('business_id', $b->id)->where('active', true)->where('en_tienda', true)->whereIn('tipo', ['producto', 'elaborado', 'servicio'])->with('rubro:id,nombre,color,orden,imagen')
            ->when($cfg['rubros'], fn($q) => $q->whereIn('rubro_id', $cfg['rubros']))->orderBy('name');
        $items = $q->get()->map(function ($p) use ($lista, $ri, $cfg) {
            $precio = $p->precioLista($lista);
            if ($cfg['iva_incluido'] && $ri) $precio = round($precio * (1 + (float) $p->iva / 100), 2);
            return ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'descripcion' => $p->descripcion_tienda ?: $p->description, 'precio' => $precio, 'unit' => $p->unit, 'rubro' => $p->rubro?->nombre ?? 'Otros', 'rubro_orden' => $p->rubro?->orden ?? 999, 'rubro_imagen' => $p->rubro?->imagen, 'imagen' => $p->imagen, 'stock' => (float) $p->stock, 'sin_stock' => $p->controla_stock && (float) $p->stock <= 0, 'favorito' => $p->favorito_pos, 'desc_cant_min' => (float) $p->desc_cant_min, 'desc_cant_pct' => (float) $p->desc_cant_pct, 'desc_cant2_min' => (float) $p->desc_cant2_min, 'desc_cant2_pct' => (float) $p->desc_cant2_pct];
        });
        return ['config' => $cfg, 'rubros' => $items->groupBy('rubro')->map(fn($g, $r) => ['nombre' => $r, 'orden' => $g->first()['rubro_orden'], 'imagen' => $g->first()['rubro_imagen'], 'items' => $g->values()->all()])->sortBy('orden')->values()->all(), 'total' => $items->count()];
    }

    // Crea el pedido desde el carrito público (o desde WhatsApp / marketplace ya normalizado).
    public function crearPedido(Business $b, array $d, string $canal = 'tienda', ?Contact $contact = null): PedidoWeb
    {
        $cfg = $this->config($b);
        $lista = $contact?->lista_precios ?: (int) $cfg['lista_precios'];
        $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $items = []; $subtotal = 0;
        foreach ($d['items'] ?? [] as $it) {
            $cant = (float) ($it['cantidad'] ?? 0); if ($cant <= 0) continue;
            $p = ! empty($it['product_id']) ? Product::withoutGlobalScopes()->where('business_id', $b->id)->find($it['product_id']) : null;
            if ($p) {
                $precio = $p->precioLista($lista); if ($cfg['iva_incluido'] && $ri) $precio = round($precio * (1 + (float) $p->iva / 100), 2);
                if (($dq = $p->descuentoPorCantidad($cant)) > 0) $precio = round($precio * (1 - $dq / 100), 2);
            } else { $precio = (float) ($it['precio_unit'] ?? 0); }
            if (isset($it['precio_unit']) && in_array($canal, ['mercadolibre', 'woocommerce', 'shopify', 'pedidosya', 'rappi'], true)) $precio = (float) $it['precio_unit']; // el marketplace manda el precio real cobrado
            $items[] = ['product_id' => $p?->id, 'descripcion' => $p?->name ?? ($it['descripcion'] ?? 'Ítem'), 'cantidad' => $cant, 'precio_unit' => $precio, 'total' => round($cant * $precio, 2), 'unit' => $p?->unit];
            $subtotal += round($cant * $precio, 2);
        }
        if (! $items) throw ValidationException::withMessages(['items' => 'El pedido no tiene artículos.']);
        if ($canal === 'tienda' && $subtotal < (float) $cfg['minimo_pedido']) throw ValidationException::withMessages(['items' => 'El pedido mínimo es $ ' . number_format((float) $cfg['minimo_pedido'], 0, ',', '.') . '.']);
        $entrega = $d['entrega'] ?? 'retiro';
        $envio = $entrega === 'envio' ? (($cfg['envio_gratis_desde'] > 0 && $subtotal >= $cfg['envio_gratis_desde']) ? 0 : (float) ($d['envio'] ?? $cfg['costo_envio'])) : 0;
        $cli = $d['cliente'] ?? [];
        $contact ??= $this->buscarContacto($b, $cli);
        $pedido = PedidoWeb::create([
            'business_id' => $b->id, 'business_location_id' => $b->locations()->where('is_default', true)->value('id') ?? $b->locations()->value('id'), 'canal' => $canal, 'contact_id' => $contact?->id,
            'cliente' => ['nombre' => $cli['nombre'] ?? $contact?->name ?? 'Cliente web', 'telefono' => $cli['telefono'] ?? $contact?->phone, 'email' => $cli['email'] ?? $contact?->email, 'direccion' => $cli['direccion'] ?? $contact?->address, 'notas' => $cli['notas'] ?? null],
            'items' => $items, 'subtotal' => $subtotal, 'envio' => $envio, 'descuento' => (float) ($d['descuento'] ?? 0), 'total' => round($subtotal + $envio - (float) ($d['descuento'] ?? 0), 2),
            'entrega' => $entrega, 'pago' => $d['pago'] ?? 'a_convenir', 'estado' => $d['estado'] ?? 'nuevo', 'external_id' => $d['external_id'] ?? null, 'texto_original' => $d['texto_original'] ?? null, 'notas' => $d['notas'] ?? null,
        ]);
        Alerta::emitir(['business_id' => $b->id, 'modulo' => 'comprobantes', 'tipo' => 'pedido_web', 'severidad' => 'info', 'titulo' => 'Pedido nuevo por ' . (PedidoWeb::CANALES[$canal] ?? $canal) . ': ' . $pedido->cliente['nombre'], 'detalle' => count($items) . ' ítem/s por $ ' . number_format((float) $pedido->total, 0, ',', '.') . '. Confirmalo para facturar y preparar.', 'url' => "/comprobantes/pedidos?abrir={$pedido->id}"]);
        app(\App\Services\Integraciones\WebhookService::class)->disparar($b->id, 'pedido.nuevo', ['id' => $pedido->id, 'numero' => $pedido->numeroFormateado(), 'canal' => $canal, 'cliente' => $pedido->cliente, 'total' => (float) $pedido->total, 'items' => $items]);
        return $pedido;
    }

    private function buscarContacto(Business $b, array $cli): ?Contact
    {
        $q = Contact::withoutGlobalScopes()->where('business_id', $b->id)->where('type', 'customer');
        if (! empty($cli['email'])) { if ($c = (clone $q)->where('email', $cli['email'])->first()) return $c; }
        if (! empty($cli['telefono'])) { $tel = preg_replace('/\D/', '', $cli['telefono']); if (strlen($tel) >= 8 && ($c = (clone $q)->whereRaw("replace(replace(replace(coalesce(phone,''),'-',''),' ',''),'+','') like ?", ['%' . substr($tel, -8)])->first())) return $c; }
        return null;
    }

    // Confirmar: crea (o toma) el cliente, arma la factura o el presupuesto y deja el pedido listo para preparar.
    public function confirmar(PedidoWeb $p, array $opt = []): PedidoWeb
    {
        return DB::transaction(function () use ($p, $opt) {
            abort_if($p->estado !== 'nuevo', 422, 'El pedido ya fue procesado.');
            $b = $p->business;
            $contact = $p->contact ?? Contact::create(['business_id' => $b->id, 'type' => 'customer', 'name' => $p->cliente['nombre'] ?: 'Cliente web', 'email' => $p->cliente['email'] ?? null, 'phone' => $p->cliente['telefono'] ?? null, 'address' => $p->cliente['direccion'] ?? null, 'condicion_iva' => 'Consumidor Final', 'is_active' => true, 'lista_precios' => (int) $this->config($b)['lista_precios'], 'credit_limit' => 0]);
            $p->contact_id = $contact->id;
            $cfg = $this->config($b);
            $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
            $items = collect($p->items)->map(function ($it) use ($cfg, $ri) {
                $al = $it['product_id'] ? (float) (Product::withoutGlobalScopes()->find($it['product_id'])?->iva ?? 21) : 21;
                $neto = $cfg['iva_incluido'] && $ri ? round($it['precio_unit'] / (1 + $al / 100), 4) : $it['precio_unit'];
                return ['product_id' => $it['product_id'], 'descripcion' => $it['descripcion'], 'cantidad' => $it['cantidad'], 'precio_unit' => $neto, 'descuento' => 0, 'alicuota_iva' => $al];
            })->all();
            if ((float) $p->envio > 0) $items[] = ['descripcion' => 'Envío a domicilio', 'cantidad' => 1, 'precio_unit' => $ri ? round((float) $p->envio / 1.21, 4) : (float) $p->envio, 'descuento' => 0, 'alicuota_iva' => 21];
            $tipo = $opt['tipo'] ?? 'FX';
            $c = $this->comprobantes->guardarBorrador(['contact_id' => $contact->id, 'tipo' => $tipo, 'fecha' => today()->toDateString(), 'condicion' => in_array($p->pago, ['pagado_externo', 'efectivo'], true) ? 'contado' : 'cta_cte', 'notas' => "Pedido {$p->numeroFormateado()} por " . (PedidoWeb::CANALES[$p->canal] ?? $p->canal) . ($p->cliente['direccion'] ? ' · Entrega: ' . $p->cliente['direccion'] : '') . ($p->cliente['notas'] ? ' · ' . $p->cliente['notas'] : ''), 'entrega_pendiente' => $p->entrega === 'envio' && ($opt['entrega_pendiente'] ?? false), 'items' => $items]);
            if ($opt['emitir'] ?? true) $c = $this->comprobantes->emitir($c);
            if ($p->pago === 'link' && $c->estado === 'emitido' && $c->esFactura()) { try { $this->links->crear($c); } catch (\Throwable $e) {} }
            $p->forceFill(['comprobante_id' => $c->id, 'estado' => 'confirmado', 'confirmado_en' => now()])->save();
            AuditLog::registrar('editar', $p, "Confirmó el pedido {$p->numeroFormateado()} → {$c->nombreTipo()} {$c->numeroFormateado()}");
            Alerta::where('tipo', 'pedido_web')->where('url', 'like', "%abrir={$p->id}")->update(['resuelta_en' => now()]);
            return $p->fresh();
        });
    }

    public function cambiarEstado(PedidoWeb $p, string $estado): void
    {
        abort_if(! isset(PedidoWeb::ESTADOS[$estado]), 422, 'Estado inválido.');
        $p->forceFill(['estado' => $estado, 'entregado_en' => $estado === 'entregado' ? now() : $p->entregado_en])->save();
        if ($estado === 'cancelado') Alerta::where('tipo', 'pedido_web')->where('url', 'like', "%abrir={$p->id}")->update(['resuelta_en' => now()]);
        app(\App\Services\Integraciones\WebhookService::class)->disparar($p->business_id, 'pedido.estado', ['id' => $p->id, 'numero' => $p->numeroFormateado(), 'estado' => $estado]);
    }

    public function rubrosDisponibles(Business $b) { return Rubro::withoutGlobalScopes()->where('business_id', $b->id)->orderBy('nombre')->get(['id', 'nombre']); }
}
