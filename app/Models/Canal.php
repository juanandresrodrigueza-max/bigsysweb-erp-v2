<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// Conexión con un marketplace o app de delivery: credenciales, qué sincroniza y el token del webhook de entrada.
class Canal extends Model
{
    use BelongsToBusiness;

    protected $table = 'canales';
    protected $fillable = ['business_id', 'tipo', 'nombre', 'credenciales', 'activo', 'sync_stock', 'sync_precios', 'importar_pedidos', 'token_entrada', 'ultimo_sync_en', 'ultimo_error', 'pedidos_importados'];
    protected $casts = ['credenciales' => 'array', 'activo' => 'boolean', 'sync_stock' => 'boolean', 'sync_precios' => 'boolean', 'importar_pedidos' => 'boolean', 'ultimo_sync_en' => 'datetime'];

    public const TIPOS = [
        'mercadolibre' => ['label' => 'MercadoLibre', 'campos' => ['access_token' => 'Access token', 'user_id' => 'ID de usuario ML'], 'ayuda' => 'Creá una aplicación en developers.mercadolibre.com.ar y pegá el access token. Los pedidos entran cada vez que sincronizás o por webhook.'],
        'woocommerce' => ['label' => 'WooCommerce', 'campos' => ['url' => 'URL de la tienda', 'consumer_key' => 'Consumer key', 'consumer_secret' => 'Consumer secret'], 'ayuda' => 'WooCommerce → Ajustes → Avanzado → REST API: creá una clave de lectura/escritura.'],
        'shopify' => ['label' => 'Shopify', 'campos' => ['tienda' => 'Dominio (mitienda.myshopify.com)', 'access_token' => 'Admin API access token'], 'ayuda' => 'Apps → Desarrollar apps → crear app con permisos de pedidos e inventario.'],
        'pedidosya' => ['label' => 'PedidosYa', 'campos' => ['vendor_id' => 'ID de local'], 'ayuda' => 'Los pedidos entran por el webhook de entrada (pegá la URL en el panel de PedidosYa o en tu integrador).'],
        'rappi' => ['label' => 'Rappi', 'campos' => ['store_id' => 'ID de tienda'], 'ayuda' => 'Los pedidos entran por el webhook de entrada (pegá la URL en el panel de Rappi o en tu integrador).'],
    ];

    protected static function booted(): void
    {
        static::creating(fn(self $c) => $c->token_entrada ??= Str::random(40));
    }

    public function urlEntrada(): string { return url("/api/canales/{$this->tipo}/{$this->token_entrada}"); }
}
