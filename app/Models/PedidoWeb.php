<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

// Pedido que entra por un canal externo: tienda propia, portal, WhatsApp, marketplace, delivery o menú QR.
class PedidoWeb extends Model
{
    use BelongsToBusiness;

    protected $table = 'pedidos_web';
    protected $fillable = ['business_id', 'business_location_id', 'numero', 'canal', 'contact_id', 'cliente', 'items', 'subtotal', 'envio', 'descuento', 'total', 'entrega', 'pago', 'estado', 'comprobante_id', 'comanda_id', 'external_id', 'token', 'texto_original', 'notas', 'envio_datos', 'confirmado_en', 'entregado_en'];
    protected $casts = ['cliente' => 'array', 'items' => 'array', 'envio_datos' => 'array', 'subtotal' => 'decimal:2', 'envio' => 'decimal:2', 'descuento' => 'decimal:2', 'total' => 'decimal:2', 'confirmado_en' => 'datetime', 'entregado_en' => 'datetime'];

    public const CANALES = ['tienda' => 'Tienda online', 'portal' => 'Portal del cliente', 'whatsapp' => 'WhatsApp', 'mercadolibre' => 'MercadoLibre', 'woocommerce' => 'WooCommerce', 'shopify' => 'Shopify', 'pedidosya' => 'PedidosYa', 'rappi' => 'Rappi', 'menu_qr' => 'Menú QR'];
    public const ESTADOS = ['nuevo' => 'Nuevo', 'confirmado' => 'Confirmado', 'preparando' => 'Preparando', 'enviado' => 'En camino', 'entregado' => 'Entregado', 'cancelado' => 'Cancelado'];

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            $p->token ??= Str::random(40);
            $p->numero ??= (static::withoutGlobalScopes()->where('business_id', $p->business_id)->max('numero') ?? 0) + 1;
        });
    }

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
    public function comanda(): BelongsTo { return $this->belongsTo(Comanda::class); }
    public function numeroFormateado(): string { return 'PW-' . str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT); }
    public function urlPublica(): string { return url('/t/' . ($this->business->tienda['slug'] ?? $this->business->slug) . '/pedido/' . $this->token); }
}
