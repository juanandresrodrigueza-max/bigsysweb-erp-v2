<?php

namespace App\Services\Canales;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Canal;
use App\Models\SistemaConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Fase 30.1: conectar una tienda sin copiar claves. MercadoLibre y Tienda Nube con la aplicación de BigSys (OAuth), WooCommerce con
// su propio flujo de autorización, Shopify con una app a medida verificada por firma. Al conectar se crean los avisos de venta.
class ConexionesService
{
    public const ML_API = 'https://api.mercadolibre.com';

    // ---- La URL a la que mandar al usuario para autorizar ----
    public function urlAutorizar(string $tipo, Business $b, array $d = []): string
    {
        $state = $this->estado($tipo, $b);
        return match ($tipo) {
            'mercadolibre' => 'https://auth.mercadolibre.com.ar/authorization?' . http_build_query(['response_type' => 'code', 'client_id' => $this->app('ml_app_id'), 'redirect_uri' => $this->retorno('mercadolibre'), 'state' => $state]),
            'tiendanube' => 'https://www.tiendanube.com/apps/' . $this->app('tn_app_id') . '/authorize?' . http_build_query(['state' => $state]),
            'woocommerce' => rtrim($this->https($d['url'] ?? ''), '/') . '/wc-auth/v1/authorize?' . http_build_query(['app_name' => config('app.name', 'BigSysWeb'), 'scope' => 'read_write', 'user_id' => $state, 'return_url' => $this->retorno('woocommerce') . '?state=' . $state, 'callback_url' => url('/api/canales/woocommerce/claves')]),
            default => throw ValidationException::withMessages(['tipo' => 'Esa tienda no se conecta con un botón.']),
        };
    }

    private function app(string $k): string
    {
        $v = SistemaConfig::get($k);
        if (! $v) throw ValidationException::withMessages(['app' => 'Falta configurar la aplicación de ' . (str_starts_with($k, 'ml') ? 'MercadoLibre' : 'Tienda Nube') . ' en el panel de BigSys. Avisale a soporte: es un dato del sistema, no tuyo.']);
        return (string) $v;
    }

    private function https(string $url): string
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https://#i', $url)) throw ValidationException::withMessages(['url' => 'La tienda tiene que estar en HTTPS (https://mitienda.com).']);
        return $url;
    }

    public function retorno(string $tipo): string { return url("/conectar/{$tipo}/volver"); }

    // El "state" ata la vuelta a la empresa que pidió conectar; vale 20 minutos.
    private function estado(string $tipo, Business $b): string
    {
        $s = Str::random(32); Cache::put("conectar:{$s}", ['tipo' => $tipo, 'business_id' => $b->id], now()->addMinutes(20)); return $s;
    }

    public function empresaDelEstado(?string $state, string $tipo): Business
    {
        $d = $state ? Cache::get("conectar:{$state}") : null;
        if (! $d || $d['tipo'] !== $tipo) throw ValidationException::withMessages(['state' => 'El pedido de conexión venció o no es de esta empresa. Volvé a tocar "Conectar".']);
        return Business::findOrFail($d['business_id']);
    }

    // ---- La vuelta: canjear el código por el token y dejar el canal conectado ----
    public function volver(string $tipo, Request $r): Canal
    {
        $b = $this->empresaDelEstado($r->query('state') ?: $r->query('user_id'), $tipo);
        return match ($tipo) {
            'mercadolibre' => $this->volverMl($b, (string) $r->query('code')),
            'tiendanube' => $this->volverTn($b, (string) $r->query('code')),
            'woocommerce' => $this->volverWoo($b, (string) $r->query('state'), $r->boolean('success', true)),
            default => throw ValidationException::withMessages(['tipo' => 'Tienda desconocida.']),
        };
    }

    private function volverMl(Business $b, string $code): Canal
    {
        $r = Http::asForm()->timeout(15)->post(self::ML_API . '/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $this->app('ml_app_id'), 'client_secret' => $this->app('ml_app_secret'), 'code' => $code, 'redirect_uri' => $this->retorno('mercadolibre')]);
        if (! $r->ok()) throw ValidationException::withMessages(['code' => 'MercadoLibre no aceptó la autorización: ' . mb_substr($r->body(), 0, 200)]);
        $t = $r->json();
        $c = $this->canal($b, 'mercadolibre', 'MercadoLibre', (string) $t['user_id'], ['access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'] ?? null, 'expires_at' => now()->addSeconds((int) ($t['expires_in'] ?? 21600))->toIso8601String(), 'user_id' => (string) $t['user_id']], 'oauth');
        try { $nick = Http::withToken($t['access_token'])->timeout(8)->get(self::ML_API . '/users/me')->json('nickname'); if ($nick) $c->update(['nombre' => "MercadoLibre · {$nick}"]); } catch (\Throwable) {}
        return $c;
    }

    private function volverTn(Business $b, string $code): Canal
    {
        $r = Http::asForm()->timeout(15)->post('https://www.tiendanube.com/apps/authorize/token', ['client_id' => $this->app('tn_app_id'), 'client_secret' => $this->app('tn_app_secret'), 'grant_type' => 'authorization_code', 'code' => $code]);
        if (! $r->ok() || ! $r->json('access_token')) throw ValidationException::withMessages(['code' => 'Tienda Nube no aceptó la autorización: ' . mb_substr($r->body(), 0, 200)]);
        $t = $r->json();
        $c = $this->canal($b, 'tiendanube', 'Tienda Nube', (string) $t['user_id'], ['access_token' => $t['access_token'], 'store_id' => (string) $t['user_id'], 'secret' => $this->app('tn_app_secret')], 'oauth');
        $this->registrarWebhooks($c);
        return $c;
    }

    // WooCommerce manda las claves por POST a callback_url (ver recibirClavesWoo) y después trae al usuario de vuelta.
    private function volverWoo(Business $b, string $state, bool $ok): Canal
    {
        $claves = Cache::pull("conectar-woo:{$state}");
        if (! $ok || ! $claves) throw ValidationException::withMessages(['code' => 'WooCommerce no mandó las claves. Fijate que la tienda esté en HTTPS y que hayas aprobado el acceso.']);
        $c = $this->canal($b, 'woocommerce', 'WooCommerce', parse_url($claves['url'], PHP_URL_HOST) ?: 'woo', ['url' => $claves['url'], 'consumer_key' => $claves['consumer_key'], 'consumer_secret' => $claves['consumer_secret']], 'oauth');
        $this->registrarWebhooks($c);
        return $c;
    }

    public function recibirClavesWoo(Request $r): void
    {
        $d = $r->all();
        $state = (string) ($d['user_id'] ?? '');
        $pedido = Cache::get("conectar:{$state}");
        if (! $pedido || empty($d['consumer_key']) || empty($d['consumer_secret'])) abort(400, 'Pedido de conexión desconocido.');
        $url = Cache::get("conectar-woo-url:{$state}");
        Cache::put("conectar-woo:{$state}", ['url' => $url, 'consumer_key' => $d['consumer_key'], 'consumer_secret' => $d['consumer_secret']], now()->addMinutes(20));
    }

    public function recordarUrlWoo(string $state, string $url): void { Cache::put("conectar-woo-url:{$state}", $this->https($url), now()->addMinutes(20)); }

    // ---- Con claves pegadas (Shopify siempre; las demás como alternativa): se prueba antes de guardar ----
    public function conectarConClaves(Business $b, string $tipo, array $cred): Canal
    {
        $cred = array_filter($cred, fn($v) => $v !== null && $v !== '');
        $ext = match ($tipo) {
            'shopify' => preg_replace('#^https?://#', '', rtrim((string) ($cred['tienda'] ?? ''), '/')),
            'woocommerce' => parse_url($this->https($cred['url'] ?? ''), PHP_URL_HOST),
            'tiendanube' => (string) ($cred['store_id'] ?? ''),
            'mercadolibre' => (string) ($cred['user_id'] ?? ''),
            default => null,
        };
        if ($tipo === 'shopify') {
            if (! str_ends_with((string) $ext, '.myshopify.com')) throw ValidationException::withMessages(['tienda' => 'La dirección tiene que ser la .myshopify.com (la del administrador), no la vidriera.']);
            if (empty($cred['secret'])) throw ValidationException::withMessages(['secret' => 'La clave secreta es obligatoria: con ella se verifica cada aviso de venta.']);
            $cred['tienda'] = $ext;
        }
        $tmp = new Canal(['business_id' => $b->id, 'tipo' => $tipo, 'nombre' => Canal::TIPOS[$tipo]['label'], 'credenciales' => $cred, 'external_user_id' => $ext]);
        $prueba = $this->probar($tmp);
        if (! $prueba['ok']) throw ValidationException::withMessages(['credenciales' => $prueba['mensaje']]);
        $c = $this->canal($b, $tipo, Canal::TIPOS[$tipo]['label'] . ($prueba['nombre'] ? " · {$prueba['nombre']}" : ''), (string) $ext, $cred, 'claves');
        if ($tipo !== 'mercadolibre') $this->registrarWebhooks($c);
        return $c;
    }

    private function canal(Business $b, string $tipo, string $nombre, string $ext, array $cred, string $metodo): Canal
    {
        $c = Canal::withoutGlobalScopes()->where('business_id', $b->id)->where('tipo', $tipo)->where(fn($q) => $q->where('external_user_id', $ext)->orWhereNull('external_user_id'))->first() ?? new Canal(['business_id' => $b->id, 'tipo' => $tipo, 'nombre' => $nombre]);
        $c->fill(['nombre' => $c->exists && $c->nombre ? $c->nombre : $nombre, 'credenciales' => array_merge($c->credenciales ?? [], $cred), 'external_user_id' => $ext, 'metodo' => $metodo, 'activo' => true, 'conectado_en' => now(), 'ultimo_error' => null])->save();
        AuditLog::registrar($c->wasRecentlyCreated ? 'crear' : 'editar', $c, "Conectó {$c->nombre}" . ($metodo === 'oauth' ? ' con un botón' : ' con claves'));
        return $c;
    }

    // ---- Probar: habla con la tienda con las claves que hay ----
    public function probar(Canal $c): array
    {
        try {
            [$ok, $nombre, $msg] = match ($c->tipo) {
                'mercadolibre' => ($r = $this->ml($c)->get('/users/me'))->ok() ? [true, $r->json('nickname'), null] : [false, null, "MercadoLibre contestó {$r->status()}"],
                'woocommerce' => ($r = $this->woo($c)->get('system_status'))->ok() ? [true, $r->json('environment.site_url') ?? $r->json('settings.title'), null] : [false, null, "WooCommerce contestó {$r->status()}: revisá las claves y que la dirección sea la de la tienda"],
                'shopify' => ($r = $this->shopify($c)->get('shop.json'))->ok() ? [true, $r->json('shop.name'), null] : [false, null, "Shopify contestó {$r->status()}: revisá el token y la dirección .myshopify.com"],
                'tiendanube' => ($r = $this->tn($c)->get('store'))->ok() ? [true, $r->json('name.es') ?? $r->json('name'), null] : [false, null, "Tienda Nube contestó {$r->status()}: revisá el número de tienda y el token"],
                default => [true, null, null],
            };
        } catch (\Throwable $e) { [$ok, $nombre, $msg] = [false, null, 'No se pudo hablar con la tienda: ' . mb_substr($e->getMessage(), 0, 150)]; }
        if ($c->exists) $c->forceFill(['ultimo_error' => $ok ? null : $msg])->save();
        return ['ok' => $ok, 'nombre' => is_array($nombre) ? reset($nombre) : $nombre, 'mensaje' => $msg];
    }

    // ---- Avisos de venta creados en la tienda, con una clave propia para verificar que vengan de ahí ----
    public function registrarWebhooks(Canal $c): array
    {
        $creados = []; $err = null;
        try {
            $url = $c->urlEntrada();
            switch ($c->tipo) {
                case 'woocommerce':
                    $secret = $c->credenciales['webhook_secret'] ?? Str::random(32);
                    $c->credenciales = array_merge($c->credenciales ?? [], ['webhook_secret' => $secret]);
                    foreach (['order.created', 'order.updated'] as $topic) { $r = $this->woo($c)->post('webhooks', ['name' => "BigSysWeb {$topic}", 'topic' => $topic, 'delivery_url' => $url, 'secret' => $secret, 'status' => 'active']); if ($r->successful()) $creados[] = ['id' => $r->json('id'), 'topic' => $topic]; else $err = "WooCommerce {$r->status()}"; }
                    break;
                case 'tiendanube':
                    foreach (['order/created', 'order/paid', 'order/cancelled', 'app/uninstalled'] as $ev) { $r = $this->tn($c)->post('webhooks', ['event' => $ev, 'url' => $url]); if ($r->successful()) $creados[] = ['id' => $r->json('id'), 'topic' => $ev]; elseif ($r->status() !== 422) $err = "Tienda Nube {$r->status()}"; }
                    break;
                case 'shopify':
                    foreach (['orders/create', 'orders/paid', 'orders/cancelled', 'app/uninstalled'] as $ev) { $r = $this->shopify($c)->post('webhooks.json', ['webhook' => ['topic' => $ev, 'address' => $url, 'format' => 'json']]); if ($r->successful()) $creados[] = ['id' => $r->json('webhook.id'), 'topic' => $ev]; elseif ($r->status() !== 422) $err = "Shopify {$r->status()}"; }
                    // De dónde sale el stock y en qué canal se publica.
                    $loc = $this->shopify($c)->get('locations.json')->json('locations.0.id'); $pub = collect($this->shopify($c)->get('publications.json')->json('publications') ?? [])->firstWhere('name', 'Online Store')['id'] ?? null;
                    $c->credenciales = array_merge($c->credenciales ?? [], array_filter(['location_id' => $loc, 'publication_id' => $pub]));
                    break;
            }
        } catch (\Throwable $e) { $err = mb_substr($e->getMessage(), 0, 200); }
        $c->forceFill(['webhooks' => $creados ?: $c->webhooks, 'ultimo_error' => $err ? "Los avisos de venta no se pudieron crear ({$err}). La tienda quedó conectada; \"Probar la conexión\" lo vuelve a intentar." : null])->save();
        return ['creados' => $creados, 'error' => $err];
    }

    public function desconectar(Canal $c): void
    {
        try {
            foreach ($c->webhooks ?? [] as $w) {
                match ($c->tipo) { 'woocommerce' => $this->woo($c)->delete("webhooks/{$w['id']}", ['force' => true]), 'tiendanube' => $this->tn($c)->delete("webhooks/{$w['id']}"), 'shopify' => $this->shopify($c)->delete("webhooks/{$w['id']}.json"), default => null };
            }
        } catch (\Throwable) {}
        // Se borran las claves; los ajustes (cuenta, colchón, lista) quedan para cuando vuelva a conectar.
        $c->forceFill(['credenciales' => null, 'webhooks' => null, 'activo' => false, 'conectado_en' => null, 'metodo' => null])->save();
        AuditLog::registrar('editar', $c, "Desconectó {$c->nombre}");
    }

    // ---- Verificar que un aviso venga de la tienda y no de un tercero ----
    public function firmaValida(Canal $c, Request $r): bool
    {
        $cuerpo = $r->getContent();
        return match ($c->tipo) {
            'woocommerce' => ($s = $c->credenciales['webhook_secret'] ?? null) ? hash_equals(base64_encode(hash_hmac('sha256', $cuerpo, $s, true)), (string) $r->header('X-WC-Webhook-Signature')) : true,
            'shopify' => ($s = $c->credenciales['secret'] ?? null) ? hash_equals(base64_encode(hash_hmac('sha256', $cuerpo, $s, true)), (string) $r->header('X-Shopify-Hmac-Sha256')) : false,
            'tiendanube' => ($s = $c->credenciales['secret'] ?? null) ? hash_equals(hash_hmac('sha256', $cuerpo, $s), (string) $r->header('X-Linkedstore-Hmac-Sha256')) : true,
            default => true,
        };
    }

    // ---- Clientes HTTP de cada tienda ----
    public function ml(Canal $c)
    {
        $cr = $c->credenciales ?? [];
        // El token de MercadoLibre dura 6 horas: se renueva solo con el refresh token.
        if (! empty($cr['refresh_token']) && ! empty($cr['expires_at']) && now()->gte(\Carbon\Carbon::parse($cr['expires_at'])->subMinutes(10))) {
            $r = Http::asForm()->timeout(15)->post(self::ML_API . '/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $this->app('ml_app_id'), 'client_secret' => $this->app('ml_app_secret'), 'refresh_token' => $cr['refresh_token']]);
            if ($r->ok()) { $t = $r->json(); $cr = array_merge($cr, ['access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'] ?? $cr['refresh_token'], 'expires_at' => now()->addSeconds((int) ($t['expires_in'] ?? 21600))->toIso8601String()]); $c->forceFill(['credenciales' => $cr])->save(); }
        }
        return Http::withToken($cr['access_token'] ?? '')->acceptJson()->timeout(15)->baseUrl(self::ML_API);
    }
    public function woo(Canal $c) { $cr = $c->credenciales ?? []; return Http::withBasicAuth($cr['consumer_key'] ?? '', $cr['consumer_secret'] ?? '')->acceptJson()->timeout(15)->baseUrl(rtrim($cr['url'] ?? '', '/') . '/wp-json/wc/v3/'); }
    public function shopify(Canal $c) { $cr = $c->credenciales ?? []; return Http::withHeaders(['X-Shopify-Access-Token' => $cr['access_token'] ?? ''])->acceptJson()->timeout(15)->baseUrl('https://' . ($cr['tienda'] ?? '') . '/admin/api/2024-10/'); }
    public function tn(Canal $c) { $cr = $c->credenciales ?? []; return Http::withHeaders(['Authentication' => 'bearer ' . ($cr['access_token'] ?? ''), 'User-Agent' => 'BigSysWeb (soporte@bigsys.com.ar)'])->acceptJson()->timeout(15)->baseUrl('https://api.tiendanube.com/v1/' . ($cr['store_id'] ?? '') . '/'); }
    public function cliente(Canal $c) { return match ($c->tipo) { 'mercadolibre' => $this->ml($c), 'woocommerce' => $this->woo($c), 'shopify' => $this->shopify($c), 'tiendanube' => $this->tn($c), default => Http::timeout(10) }; }
}
