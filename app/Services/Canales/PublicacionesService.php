<?php

namespace App\Services\Canales;

use App\Models\AuditLog;
use App\Models\Canal;
use App\Models\Product;
use App\Models\Publicacion;
use App\Models\Rubro;
use App\Models\RubroCanal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

// Fase 30.2: publicar desde el catálogo y mantener solas las publicaciones (precio y stock). Lista de precios por tienda, rubro o
// artículo; colchón de stock; pausa en cero; categoría y atributos obligatorios por rubro; recargo por tienda; qué campos se mandan.
class PublicacionesService
{
    public function __construct(private ConexionesService $cx) {}

    // ---- Precio y stock que salen a la tienda ----
    // La lista: la del artículo publicado, después la del rubro en esa tienda, después la de la tienda, y si no hay ninguna, la 1. Siempre con IVA.
    public function listaPara(Canal $c, Product $p, ?Publicacion $pub = null, ?RubroCanal $rc = null): int
    {
        if ($pub?->lista) return (int) $pub->lista;
        $rc ??= $p->rubro_id ? RubroCanal::withoutGlobalScopes()->where('canal_id', $c->id)->where('rubro_id', $p->rubro_id)->first() : null;
        return (int) ($rc?->lista ?: ($c->cfg('lista') ?: 1));
    }

    public function tienePrecio(Product $p, int $lista): bool
    {
        if ($lista <= 1) return (float) $p->price > 0;
        $v = $p->prices[(string) $lista] ?? $p->prices[$lista] ?? null;
        return $v !== null && $v !== '' && (float) $v > 0;
    }

    public function precioPara(Canal $c, Product $p, int $lista): float
    {
        $precio = $p->precioLista($lista) * (1 + (float) $p->iva / 100);
        $precio *= 1 + (float) $c->cfg('recargo_pct', 0) / 100;
        return round($precio, 2);
    }

    public function stockPara(Canal $c, Product $p): int
    {
        return max(0, (int) floor((float) $p->stock) - (int) $c->cfg('colchon', 0));
    }

    // Lo que se va a mandar, para revisar antes de publicar: cada producto con su precio en cada lista, si tiene foto y si falta categoría.
    public function revisar(Canal $c, array $productIds, array $listas = []): array
    {
        $rcs = RubroCanal::withoutGlobalScopes()->where('canal_id', $c->id)->get()->keyBy('rubro_id');
        $pubs = Publicacion::withoutGlobalScopes()->where('canal_id', $c->id)->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        return Product::whereIn('id', $productIds)->with('rubro')->orderBy('name')->get()->map(function (Product $p) use ($c, $rcs, $pubs, $listas) {
            $rc = $p->rubro_id ? ($rcs[$p->rubro_id] ?? null) : null;
            $lista = (int) ($listas[$p->id] ?? $this->listaPara($c, $p, $pubs[$p->id] ?? null, $rc));
            $motivos = [];
            if (! $this->tienePrecio($p, $lista)) $motivos[] = "sin precio en la lista {$lista}";
            if ($c->tipo === 'mercadolibre' && ! $p->imagen) $motivos[] = 'sin foto (MercadoLibre la exige)';
            if ($c->tipo === 'mercadolibre' && ! $rc?->categoria_id) $motivos[] = 'el rubro no tiene categoría de MercadoLibre';
            return ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'rubro' => $p->rubro?->nombre, 'foto' => $p->imagen, 'stock' => $this->stockPara($c, $p), 'lista' => $lista,
                'precio' => $this->tienePrecio($p, $lista) ? $this->precioPara($c, $p, $lista) : null,
                'precios' => collect([1, 2, 3, 4, 5, 6])->mapWithKeys(fn($l) => [$l => $this->tienePrecio($p, $l) ? $this->precioPara($c, $p, $l) : null])->all(),
                'publicada' => isset($pubs[$p->id]), 'motivos' => $motivos];
        })->values()->all();
    }

    // ---- Publicar: crea o actualiza en la tienda, por SKU. Lo que falla queda con su motivo para corregir y reintentar ----
    public function publicar(Canal $c, array $productIds, array $listas = [], array $categorias = []): array
    {
        $ok = []; $errores = [];
        foreach (Product::whereIn('id', $productIds)->with('rubro')->get() as $p) {
            try {
                $rev = $this->revisar($c, [$p->id], $listas)[0];
                if ($rev['motivos']) throw new \RuntimeException(implode('; ', $rev['motivos']));
                $pub = $this->subir($c, $p, (int) $rev['lista'], $categorias[$p->id] ?? null);
                $ok[] = ['id' => $p->id, 'nombre' => $p->name, 'external_id' => $pub->external_id, 'permalink' => $pub->permalink];
            } catch (\Throwable $e) {
                $errores[] = ['id' => $p->id, 'nombre' => $p->name, 'error' => $this->enCastellano($e->getMessage())];
            }
        }
        AuditLog::registrar('publicar', $c, count($ok) . ' publicados en ' . $c->nombre . ($errores ? ', ' . count($errores) . ' con error' : ''));
        return ['ok' => $ok, 'errores' => $errores];
    }

    private function subir(Canal $c, Product $p, int $lista, ?string $categoria): Publicacion
    {
        $rc = $p->rubro_id ? RubroCanal::withoutGlobalScopes()->where('canal_id', $c->id)->where('rubro_id', $p->rubro_id)->first() : null;
        $precio = $this->precioPara($c, $p, $lista); $stock = $this->stockPara($c, $p);
        $campos = $c->cfg('campos');
        $pub = Publicacion::withoutGlobalScopes()->where('canal_id', $c->id)->where('product_id', $p->id)->first();
        $titulo = mb_substr($p->descripcion_tienda ?: $p->name, 0, 60);
        $desc = $p->description ?: $p->name;
        $r = match ($c->tipo) {
            'mercadolibre' => $this->subirMl($c, $p, $pub, $rc, $titulo, $desc, $precio, $stock),
            'woocommerce' => $this->subirWoo($c, $p, $pub, $rc, $titulo, $desc, $precio, $stock, $campos, $categoria),
            'tiendanube' => $this->subirTn($c, $p, $pub, $rc, $titulo, $desc, $precio, $stock, $campos, $categoria),
            'shopify' => $this->subirShopify($c, $p, $pub, $rc, $titulo, $desc, $precio, $stock, $campos),
            default => throw new \RuntimeException('Esta tienda no publica por API.'),
        };
        $pub ??= new Publicacion(['business_id' => $c->business_id, 'canal_id' => $c->id, 'product_id' => $p->id, 'origen' => 'publicada']);
        $pub->fill(['external_id' => (string) $r['id'], 'variante_id' => $r['variante_id'] ?? null, 'titulo' => $titulo, 'sku_remoto' => $p->sku, 'precio_remoto' => $precio, 'stock_remoto' => $stock, 'lista' => $lista, 'categoria_id' => $r['categoria'] ?? $rc?->categoria_id, 'estado' => $stock > 0 ? 'activa' : 'pausada', 'permalink' => $r['permalink'] ?? null, 'ultimo_error' => null, 'sync_at' => now(), 'datos' => $r['datos'] ?? null])->save();
        return $pub;
    }

    private function subirMl(Canal $c, Product $p, ?Publicacion $pub, ?RubroCanal $rc, string $titulo, string $desc, float $precio, int $stock): array
    {
        $at = $rc->atributos ?? [];
        $attrs = [];
        foreach (['BRAND' => $at['marca'] ?? $p->marca, 'MODEL' => $at['modelo'] ?? null, 'GTIN' => $p->barcode ?: null, 'SELLER_SKU' => $p->sku] as $id => $v) if ($v) $attrs[] = ['id' => $id, 'value_name' => (string) $v];
        foreach (($at['extra'] ?? []) as $id => $v) if ($v !== null && $v !== '') $attrs[] = ['id' => $id, 'value_name' => (string) $v];
        $body = ['title' => $titulo, 'price' => $precio, 'available_quantity' => $stock, 'currency_id' => 'ARS', 'condition' => 'new', 'listing_type_id' => $at['listing_type'] ?? 'gold_special', 'attributes' => $attrs];
        if ($stock <= 0) $body['status'] = 'paused';
        if ($pub) {
            $r = $this->cx->ml($c)->put("/items/{$pub->external_id}", array_intersect_key($body, array_flip(['price', 'available_quantity', 'title', 'attributes'])));
        } else {
            $body += ['category_id' => $rc->categoria_id, 'pictures' => $p->imagen ? [['source' => $p->imagen]] : [], 'sale_terms' => [], 'shipping' => ['mode' => 'me2', 'local_pick_up' => false, 'free_shipping' => false],
                'sale_terms' => isset($at['garantia']) ? [['id' => 'WARRANTY_TYPE', 'value_name' => 'Garantía del vendedor'], ['id' => 'WARRANTY_TIME', 'value_name' => $at['garantia']]] : []];
            foreach (['alto' => 'PACKAGE_HEIGHT', 'ancho' => 'PACKAGE_WIDTH', 'largo' => 'PACKAGE_LENGTH', 'peso' => 'PACKAGE_WEIGHT'] as $k => $id) if (! empty($at[$k])) $body['attributes'][] = ['id' => $id, 'value_name' => $at[$k] . ($k === 'peso' ? ' g' : ' cm')];
            $r = $this->cx->ml($c)->post('/items', $body);
            if ($r->successful() && $desc) { try { $this->cx->ml($c)->post("/items/{$r->json('id')}/description", ['plain_text' => mb_substr($desc, 0, 4000)]); } catch (\Throwable) {} }
        }
        if (! $r->successful()) throw new \RuntimeException($this->errorMl($r));
        return ['id' => $r->json('id') ?? $pub?->external_id, 'permalink' => $r->json('permalink') ?? $pub?->permalink, 'categoria' => $rc?->categoria_id];
    }

    private function subirWoo(Canal $c, Product $p, ?Publicacion $pub, ?RubroCanal $rc, string $titulo, string $desc, float $precio, int $stock, array $campos, ?string $categoria): array
    {
        $w = $this->cx->woo($c);
        $id = $pub?->external_id ?: ($w->get('products', ['sku' => $p->sku])->json()[0]['id'] ?? null);
        $cat = $categoria ?: $rc?->categoria_id ?: $this->categoriaWoo($c, $p->rubro);
        $body = array_filter([
            'name' => in_array('nombre', $campos, true) || ! $id ? $titulo : null, 'sku' => $p->sku, 'type' => 'simple',
            'regular_price' => in_array('precio', $campos, true) ? number_format($precio, 2, '.', '') : null,
            'manage_stock' => true, 'stock_quantity' => in_array('stock', $campos, true) ? $stock : null, 'stock_status' => $stock > 0 ? 'instock' : 'outofstock',
            'description' => in_array('descripcion', $campos, true) ? $desc : null,
            'categories' => $cat && in_array('categoria', $campos, true) ? [['id' => (int) $cat]] : null,
            'images' => $p->imagen && in_array('foto', $campos, true) && ! $id ? [['src' => $p->imagen]] : null,
        ], fn($v) => $v !== null);
        $r = $id ? $w->put("products/{$id}", $body) : $w->post('products', $body);
        if (! $r->successful()) throw new \RuntimeException('WooCommerce: ' . ($r->json('message') ?? "HTTP {$r->status()}"));
        return ['id' => $r->json('id'), 'permalink' => $r->json('permalink'), 'categoria' => $cat];
    }

    private function subirTn(Canal $c, Product $p, ?Publicacion $pub, ?RubroCanal $rc, string $titulo, string $desc, float $precio, int $stock, array $campos, ?string $categoria): array
    {
        $t = $this->cx->tn($c);
        $id = $pub?->external_id; $varId = $pub?->variante_id;
        if (! $id) { $ex = $t->get('products/sku/' . rawurlencode($p->sku)); if ($ex->ok() && $ex->json('id')) { $id = $ex->json('id'); $varId = $ex->json('variants.0.id'); } }
        $cat = $categoria ?: $rc?->categoria_id ?: $this->categoriaTn($c, $p->rubro);
        $variante = array_filter(['sku' => $p->sku, 'price' => in_array('precio', $campos, true) ? number_format($precio, 2, '.', '') : null, 'stock' => in_array('stock', $campos, true) ? $stock : null], fn($v) => $v !== null);
        $body = array_filter(['name' => in_array('nombre', $campos, true) || ! $id ? ['es' => $titulo] : null, 'description' => in_array('descripcion', $campos, true) ? ['es' => $desc] : null, 'categories' => $cat && in_array('categoria', $campos, true) ? [(int) $cat] : null, 'published' => true], fn($v) => $v !== null);
        if ($id) {
            $r = $t->put("products/{$id}", $body); if (! $r->successful()) throw new \RuntimeException('Tienda Nube: ' . mb_substr($r->body(), 0, 200));
            if ($varId) { $rv = $t->put("products/{$id}/variants/{$varId}", $variante); if (! $rv->successful()) throw new \RuntimeException('Tienda Nube (variante): ' . mb_substr($rv->body(), 0, 200)); }
        } else {
            $body['variants'] = [$variante + ['price' => number_format($precio, 2, '.', ''), 'stock' => $stock]];
            if ($p->imagen && in_array('foto', $campos, true)) $body['images'] = [['src' => $p->imagen]];
            $r = $t->post('products', $body); if (! $r->successful()) throw new \RuntimeException('Tienda Nube: ' . mb_substr($r->body(), 0, 200));
            $id = $r->json('id'); $varId = $r->json('variants.0.id');
        }
        return ['id' => $id, 'variante_id' => $varId, 'permalink' => $r->json('canonical_url') ?? $pub?->permalink, 'categoria' => $cat];
    }

    private function subirShopify(Canal $c, Product $p, ?Publicacion $pub, ?RubroCanal $rc, string $titulo, string $desc, float $precio, int $stock, array $campos): array
    {
        $s = $this->cx->shopify($c); $cr = $c->credenciales ?? [];
        $id = $pub?->external_id; $varId = $pub?->variante_id; $invId = $pub?->datos['inventory_item_id'] ?? null;
        if (! $id) { $ex = $s->get('variants.json', ['sku' => $p->sku])->json('variants.0') ?? null; if ($ex) { $id = $ex['product_id']; $varId = $ex['id']; $invId = $ex['inventory_item_id']; } }
        $variant = array_filter(['sku' => $p->sku, 'price' => in_array('precio', $campos, true) ? number_format($precio, 2, '.', '') : null, 'inventory_management' => 'shopify'], fn($v) => $v !== null);
        $body = ['product' => array_filter(['title' => in_array('nombre', $campos, true) || ! $id ? $titulo : null, 'body_html' => in_array('descripcion', $campos, true) ? nl2br(e($desc)) : null, 'product_type' => $p->rubro?->nombre, 'status' => 'active', 'variants' => [$variant + ($varId ? ['id' => (int) $varId] : [])], 'images' => $p->imagen && ! $id && in_array('foto', $campos, true) ? [['src' => $p->imagen]] : null], fn($v) => $v !== null)];
        $r = $id ? $s->put("products/{$id}.json", $body) : $s->post('products.json', $body);
        if (! $r->successful()) throw new \RuntimeException('Shopify: ' . mb_substr($r->body(), 0, 200));
        $id = $r->json('product.id'); $varId = $r->json('product.variants.0.id'); $invId = $r->json('product.variants.0.inventory_item_id') ?? $invId;
        if (in_array('stock', $campos, true) && ! empty($cr['location_id']) && $invId) $s->post('inventory_levels/set.json', ['location_id' => $cr['location_id'], 'inventory_item_id' => $invId, 'available' => $stock]);
        // Un producto creado por API no se ve en la tienda hasta publicarlo en Online Store.
        if (! $pub && ! empty($cr['publication_id'])) { try { $s->post('graphql.json', ['query' => 'mutation { publishablePublish(id: "gid://shopify/Product/' . $id . '", input: {publicationId: "gid://shopify/Publication/' . $cr['publication_id'] . '"}) { userErrors { message } } }']); } catch (\Throwable) {} }
        if ($p->rubro && ! $pub) { try { $col = $rc?->categoria_id ?: $this->coleccionShopify($c, $p->rubro); if ($col) $s->post('collects.json', ['collect' => ['product_id' => $id, 'collection_id' => (int) $col]]); } catch (\Throwable) {} }
        return ['id' => $id, 'variante_id' => $varId, 'permalink' => 'https://' . ($cr['tienda'] ?? '') . '/products/' . ($r->json('product.handle') ?? ''), 'datos' => ['inventory_item_id' => $invId], 'categoria' => $rc?->categoria_id];
    }

    // Categorías de la web: si el rubro no tiene una, se crea con su nombre y queda guardada en el rubro.
    private function categoriaWoo(Canal $c, ?Rubro $r): ?string
    {
        if (! $r) return null;
        $w = $this->cx->woo($c);
        $id = collect($w->get('products/categories', ['search' => $r->nombre, 'per_page' => 50])->json() ?? [])->firstWhere('name', $r->nombre)['id'] ?? $w->post('products/categories', ['name' => $r->nombre])->json('id');
        if ($id) $this->guardarRubro($c, $r, ['categoria_id' => (string) $id, 'categoria_nombre' => $r->nombre]);
        return $id ? (string) $id : null;
    }
    private function categoriaTn(Canal $c, ?Rubro $r): ?string
    {
        if (! $r) return null;
        $t = $this->cx->tn($c);
        $id = collect($t->get('categories', ['per_page' => 200])->json() ?? [])->first(fn($x) => mb_strtolower($x['name']['es'] ?? '') === mb_strtolower($r->nombre))['id'] ?? $t->post('categories', ['name' => ['es' => $r->nombre]])->json('id');
        if ($id) $this->guardarRubro($c, $r, ['categoria_id' => (string) $id, 'categoria_nombre' => $r->nombre]);
        return $id ? (string) $id : null;
    }
    private function coleccionShopify(Canal $c, Rubro $r): ?string
    {
        $s = $this->cx->shopify($c);
        $id = collect($s->get('custom_collections.json', ['title' => $r->nombre])->json('custom_collections') ?? [])->first()['id'] ?? $s->post('custom_collections.json', ['custom_collection' => ['title' => $r->nombre]])->json('custom_collection.id');
        if ($id) $this->guardarRubro($c, $r, ['categoria_id' => (string) $id, 'categoria_nombre' => $r->nombre]);
        return $id ? (string) $id : null;
    }

    public function guardarRubro(Canal $c, Rubro $r, array $d): RubroCanal
    {
        $rc = RubroCanal::withoutGlobalScopes()->firstOrNew(['canal_id' => $c->id, 'rubro_id' => $r->id], ['business_id' => $c->business_id]);
        $rc->fill(array_intersect_key($d, array_flip(['categoria_id', 'categoria_nombre', 'lista', 'atributos'])))->save();
        return $rc;
    }

    // ---- MercadoLibre: la categoría la propone ML a partir del producto más vendido del rubro, o de lo que escribas ----
    public function categoriasSugeridas(Canal $c, string $texto): array
    {
        $r = $this->cx->ml($c)->get('/sites/MLA/domain_discovery/search', ['q' => mb_substr($texto, 0, 120), 'limit' => 5]);
        if (! $r->ok()) throw new \RuntimeException($this->errorMl($r));
        return collect($r->json() ?? [])->map(fn($x) => ['id' => $x['category_id'], 'nombre' => $x['category_name'], 'dominio' => $x['domain_name'] ?? null])->unique('id')->values()->all();
    }

    // Lo que esa categoría pide sí o sí (marca, modelo, lo que sea) y lo que exige Mercado Envíos (medidas y peso).
    public function atributosObligatorios(Canal $c, string $categoriaId): array
    {
        $r = $this->cx->ml($c)->get("/categories/{$categoriaId}/attributes");
        if (! $r->ok()) throw new \RuntimeException($this->errorMl($r));
        $req = collect($r->json() ?? [])->filter(fn($a) => ! empty($a['tags']['required']) || ! empty($a['tags']['catalog_required']))->filter(fn($a) => ! in_array($a['id'], ['SELLER_SKU', 'GTIN', 'ITEM_CONDITION'], true))
            ->map(fn($a) => ['id' => $a['id'], 'nombre' => $a['name'], 'motivo' => 'MercadoLibre lo pide en esta categoría', 'valores' => collect($a['values'] ?? [])->take(30)->pluck('name')->all()])->values()->all();
        $envio = [['id' => 'alto', 'nombre' => 'Alto del paquete (cm)', 'motivo' => 'lo exige Mercado Envíos'], ['id' => 'ancho', 'nombre' => 'Ancho (cm)', 'motivo' => 'lo exige Mercado Envíos'], ['id' => 'largo', 'nombre' => 'Largo (cm)', 'motivo' => 'lo exige Mercado Envíos'], ['id' => 'peso', 'nombre' => 'Peso (g)', 'motivo' => 'lo exige Mercado Envíos']];
        $cat = $this->cx->ml($c)->get("/categories/{$categoriaId}")->json();
        return ['requeridos' => $req, 'envio' => $envio, 'admite_envios' => ! in_array('me2', (array) ($cat['settings']['shipping_modes'] ?? ['me2']), true) ? false : true, 'precio_minimo' => $cat['settings']['minimum_price'] ?? null, 'nombre' => $cat['name'] ?? $categoriaId];
    }

    // ---- Mantener solas: compara precio y stock con lo publicado y corrige lo que cambió. Se queda sin stock → se pausa; vuelve → se activa ----
    public function sincronizar(Canal $c, ?Collection $pubs = null): array
    {
        $n = 0; $errores = 0;
        $pubs ??= Publicacion::withoutGlobalScopes()->where('canal_id', $c->id)->whereNotNull('product_id')->with('product')->get();
        $rcs = RubroCanal::withoutGlobalScopes()->where('canal_id', $c->id)->get()->keyBy('rubro_id');
        foreach ($pubs as $pub) {
            if (! $pub->sincronizable() || ! $pub->product) continue;
            $p = $pub->product;
            $lista = $this->listaPara($c, $p, $pub, $p->rubro_id ? ($rcs[$p->rubro_id] ?? null) : null);
            $precio = $this->tienePrecio($p, $lista) ? $this->precioPara($c, $p, $lista) : (float) $pub->precio_remoto;
            $stock = $this->stockPara($c, $p);
            if (abs($precio - (float) $pub->precio_remoto) < 0.005 && $stock === (int) $pub->stock_remoto && $pub->estado !== 'error') continue;
            try {
                $this->empujar($c, $pub, $precio, $stock);
                $pub->forceFill(['precio_remoto' => $precio, 'stock_remoto' => $stock, 'estado' => $stock > 0 ? 'activa' : 'pausada', 'ultimo_error' => null, 'sync_at' => now()])->save(); $n++;
            } catch (\Throwable $e) { $pub->forceFill(['estado' => 'error', 'ultimo_error' => $this->enCastellano($e->getMessage()), 'sync_at' => now()])->save(); $errores++; }
        }
        $c->forceFill(['ultimo_sync_en' => now()])->save();
        return ['actualizadas' => $n, 'errores' => $errores];
    }

    public function empujar(Canal $c, Publicacion $pub, float $precio, int $stock): void
    {
        $pausar = (bool) $c->cfg('pausar_en_cero', true);
        switch ($c->tipo) {
            case 'mercadolibre':
                $body = ['price' => $precio, 'available_quantity' => $stock];
                if ($pausar) $body['status'] = $stock > 0 ? 'active' : 'paused';
                $r = $this->cx->ml($c)->put("/items/{$pub->external_id}", $body); if (! $r->successful()) throw new \RuntimeException($this->errorMl($r)); break;
            case 'woocommerce':
                $r = $this->cx->woo($c)->put('products/' . $pub->external_id . ($pub->variante_id ? "/variations/{$pub->variante_id}" : ''), ['regular_price' => number_format($precio, 2, '.', ''), 'manage_stock' => true, 'stock_quantity' => $stock, 'stock_status' => $stock > 0 ? 'instock' : 'outofstock']); if (! $r->successful()) throw new \RuntimeException('WooCommerce: ' . ($r->json('message') ?? "HTTP {$r->status()}")); break;
            case 'tiendanube':
                $r = $this->cx->tn($c)->put("products/{$pub->external_id}/variants/{$pub->variante_id}", ['price' => number_format($precio, 2, '.', ''), 'stock' => $stock]); if (! $r->successful()) throw new \RuntimeException('Tienda Nube: ' . mb_substr($r->body(), 0, 200)); break;
            case 'shopify':
                $s = $this->cx->shopify($c); $cr = $c->credenciales ?? [];
                $r = $s->put("variants/{$pub->variante_id}.json", ['variant' => ['id' => (int) $pub->variante_id, 'price' => number_format($precio, 2, '.', '')]]); if (! $r->successful()) throw new \RuntimeException('Shopify: ' . mb_substr($r->body(), 0, 200));
                if (! empty($cr['location_id']) && ! empty($pub->datos['inventory_item_id'])) $s->post('inventory_levels/set.json', ['location_id' => $cr['location_id'], 'inventory_item_id' => $pub->datos['inventory_item_id'], 'available' => $stock]);
                break;
        }
    }

    public function pausar(Canal $c, Publicacion $pub, bool $pausar = true): void
    {
        match ($c->tipo) {
            'mercadolibre' => $this->cx->ml($c)->put("/items/{$pub->external_id}", ['status' => $pausar ? 'paused' : 'active']),
            'woocommerce' => $this->cx->woo($c)->put("products/{$pub->external_id}", ['status' => $pausar ? 'draft' : 'publish']),
            'tiendanube' => $this->cx->tn($c)->put("products/{$pub->external_id}", ['published' => ! $pausar]),
            'shopify' => $this->cx->shopify($c)->put("products/{$pub->external_id}.json", ['product' => ['id' => (int) $pub->external_id, 'status' => $pausar ? 'draft' : 'active']]),
            default => null,
        };
        $pub->forceFill(['estado' => $pausar ? 'pausada' : 'activa'])->save();
    }

    // Los errores de MercadoLibre, en castellano y con el paso a seguir. Todos los motivos juntos, no uno por vez.
    private function errorMl($r): string
    {
        $j = $r->json() ?? [];
        $causas = collect($j['cause'] ?? [])->map(fn($x) => is_array($x) ? ($x['message'] ?? $x['code'] ?? '') : (string) $x)->filter()->all();
        $msgs = $causas ?: [$j['message'] ?? "HTTP {$r->status()}"];
        return 'MercadoLibre: ' . implode(' · ', array_map([$this, 'enCastellano'], $msgs));
    }

    public function enCastellano(string $m): string
    {
        $m = trim($m);
        $mapa = [
            'User has not mode me1' => 'esa categoría no admite Mercado Envíos (casi nunca es tu cuenta: probá un producto de otro rubro; si falla en todos, mirá Central de vendedores → Configuración → Envíos)',
            'pictures' => 'falta la foto del producto (se carga en el artículo)',
            'minimum price' => 'el precio está por debajo del mínimo que pide la categoría',
            'free_shipping' => 'arriba de cierto precio el envío gratis es obligatorio y lo paga el vendedor',
            'invalid_token' => 'la conexión venció: volvé a conectar la cuenta',
            'attributes' => 'faltan datos que la categoría pide (marca, modelo…): se cargan una vez por rubro',
            'category_id' => 'la categoría no es válida para publicar: elegí una más específica',
        ];
        foreach ($mapa as $k => $v) if (stripos($m, $k) !== false) return $v;
        return $m;
    }
}
