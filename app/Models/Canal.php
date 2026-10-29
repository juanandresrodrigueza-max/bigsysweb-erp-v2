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
    protected $fillable = ['business_id', 'tipo', 'nombre', 'credenciales', 'activo', 'sync_stock', 'sync_precios', 'importar_pedidos', 'token_entrada', 'ultimo_sync_en', 'ultimo_error', 'pedidos_importados', 'config', 'external_user_id', 'webhooks', 'metodo', 'conectado_en', 'ultima_importacion_en'];
    protected $casts = ['credenciales' => 'encrypted:array', 'activo' => 'boolean', 'sync_stock' => 'boolean', 'sync_precios' => 'boolean', 'importar_pedidos' => 'boolean', 'ultimo_sync_en' => 'datetime', 'config' => 'array', 'webhooks' => 'array', 'conectado_en' => 'datetime', 'ultima_importacion_en' => 'datetime'];

    // Lo que se decide por tienda: a qué cuenta van las ventas, cuántas unidades se guardan, con qué lista se publica, quién factura.
    public const CONFIG = ['contact_id' => null, 'colchon' => 0, 'lista' => null, 'factura_por' => null, 'fecha_corte' => null, 'automatico' => false, 'recargo_pct' => 0, 'campos' => ['precio', 'stock', 'nombre', 'descripcion', 'foto', 'categoria'], 'claves_fiscales' => null, 'pausar_en_cero' => true];
    public function cfg(?string $k = null, $default = null) { $c = array_replace(self::CONFIG, $this->config ?? []); return $k === null ? $c : ($c[$k] ?? $default); }
    public function tienda(): bool { return in_array($this->tipo, ['mercadolibre', 'woocommerce', 'shopify', 'tiendanube'], true); }
    public function publicaciones(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Publicacion::class); }
    public function rubros(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(RubroCanal::class); }

    public const TIPOS = [
        'mercadolibre' => ['label' => 'MercadoLibre', 'oauth' => true, 'campos' => ['access_token' => 'Access token', 'user_id' => 'ID de usuario ML'], 'ayuda' => 'Lo normal es "Conectar con MercadoLibre": entrás con tu cuenta de vendedor y autorizás, sin copiar claves. Si preferís, pegá un access token de tu propia aplicación.'],
        'tiendanube' => ['label' => 'Tienda Nube', 'oauth' => true, 'campos' => ['store_id' => 'Número de tienda', 'access_token' => 'Token de la aplicación a medida', 'secret' => 'Secret (para verificar los avisos de venta)'], 'ayuda' => 'Lo normal es "Conectar con Tienda Nube": entrás a tu tienda, aprobás y listo. Con plan Escala o Evolución podés pegar el número de tienda y el token de una aplicación a medida.'],
        'woocommerce' => ['label' => 'WooCommerce', 'oauth' => true, 'campos' => ['url' => 'Dirección de la tienda (https://…)', 'consumer_key' => 'Consumer key', 'consumer_secret' => 'Consumer secret'], 'ayuda' => 'Escribí la dirección de tu tienda y tocá "Conectar con WooCommerce": tu WordPress te pide aprobar el acceso y manda las claves solo. O pegá una clave de lectura/escritura (WooCommerce → Ajustes → Avanzado → REST API). La tienda tiene que estar en HTTPS.'],
        'shopify' => ['label' => 'Shopify', 'campos' => ['tienda' => 'Dirección .myshopify.com', 'access_token' => 'Token de acceso de la Admin API (shpat_…)', 'secret' => 'Clave secreta de la API'], 'ayuda' => 'Configuración → Apps y canales de venta → Desarrollar apps → Crear una app con lectura y escritura de productos, inventario, pedidos, preparación y ubicaciones. El token se ve una sola vez. La clave secreta es obligatoria: con ella se verifica cada aviso de venta.'],
        'empretienda' => ['label' => 'Empretienda', 'campos' => [], 'ayuda' => 'Empretienda no tiene API: se arma la planilla que su panel importa (Stock → Tienda online) y las fotos en un ZIP. Los precios y el stock se actualizan cada vez que subís la planilla.'],
        'pedidosya' => ['label' => 'PedidosYa', 'campos' => ['vendor_id' => 'ID de local'], 'ayuda' => 'Los pedidos entran por el webhook de entrada (pegá la URL en el panel de PedidosYa o en tu integrador).'],
        'rappi' => ['label' => 'Rappi', 'campos' => ['store_id' => 'ID de tienda'], 'ayuda' => 'Los pedidos entran por el webhook de entrada (pegá la URL en el panel de Rappi o en tu integrador).'],
    ];

    protected static function booted(): void
    {
        static::creating(fn(self $c) => $c->token_entrada ??= Str::random(40));
    }

    public function urlEntrada(): string { return url("/api/canales/{$this->tipo}/{$this->token_entrada}"); }
}
