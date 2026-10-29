<?php

namespace App\Services\Canales;

use App\Models\AliasArticulo;
use App\Models\AuditLog;
use App\Models\Canal;
use App\Models\Product;
use App\Models\Publicacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// Fase 30.3: lo que ya estaba publicado en la tienda se lee, se sugiere a qué artículo corresponde (por código, barras o nombre;
// segura, dudosa o ninguna), se ata con un clic —nunca sola— y, si se adopta, el ERP le manda precio y stock desde ahí.
// Aprende de cada confirmación (alias) y usa la IA sólo de respaldo.
class EmparejarService
{
    public function __construct(private ConexionesService $cx, private PublicacionesService $pub) {}

    // ---- Leer la tienda y reflejarla en publicaciones (las ya atadas conservan su artículo) ----
    public function leer(Canal $c): array
    {
        $remotas = match ($c->tipo) { 'mercadolibre' => $this->remotasMl($c), 'woocommerce' => $this->remotasWoo($c), 'tiendanube' => $this->remotasTn($c), 'shopify' => $this->remotasShopify($c), default => [] };
        $vistas = [];
        foreach ($remotas as $r) {
            $pub = Publicacion::withoutGlobalScopes()->firstOrNew(['canal_id' => $c->id, 'external_id' => (string) $r['id'], 'variante_id' => $r['variante_id'] ?? null], ['business_id' => $c->business_id, 'origen' => 'remota']);
            $pub->fill(['titulo' => $r['titulo'], 'sku_remoto' => $r['sku'] ?? null, 'precio_remoto' => $r['precio'], 'stock_remoto' => $r['stock'], 'estado' => $pub->origen === 'publicada' && $pub->estado === 'error' ? 'error' : ($r['estado'] ?? 'activa'), 'foto_url' => $r['foto'] ?? $pub->foto_url, 'permalink' => $r['permalink'] ?? $pub->permalink, 'con_variantes' => (bool) ($r['con_variantes'] ?? false), 'categoria_id' => $r['categoria'] ?? $pub->categoria_id, 'datos' => array_merge($pub->datos ?? [], $r['datos'] ?? [])]);
            // Si el SKU de la tienda es un código nuestro y todavía no estaba atada, queda como sugerencia (no se ata sola).
            $pub->save(); $vistas[] = $pub->id;
        }
        // Lo que ya no está en la tienda queda cerrado (no se borra: puede tener ventas).
        Publicacion::withoutGlobalScopes()->where('canal_id', $c->id)->whereNotIn('id', $vistas)->where('estado', '!=', 'cerrada')->update(['estado' => 'cerrada']);
        return ['leidas' => count($remotas)];
    }

    private function remotasMl(Canal $c): array
    {
        $ml = $this->cx->ml($c); $uid = $c->external_user_id ?: ($c->credenciales['user_id'] ?? '');
        $ids = []; $scroll = null;
        do {
            $r = $ml->get("/users/{$uid}/items/search", array_filter(['search_type' => 'scan', 'limit' => 100, 'scroll_id' => $scroll]));
            if (! $r->ok()) throw new \RuntimeException('MercadoLibre no contestó (' . $r->status() . ')');
            $ids = array_merge($ids, $r->json('results') ?? []); $scroll = $r->json('scroll_id');
        } while ($scroll && count($r->json('results') ?? []) === 100 && count($ids) < 5000);
        $out = [];
        foreach (array_chunk($ids, 20) as $lote) {
            $r = $ml->get('/items', ['ids' => implode(',', $lote), 'attributes' => 'id,title,price,available_quantity,sold_quantity,status,thumbnail,permalink,category_id,variations,attributes,seller_custom_field']);
            foreach ($r->json() ?? [] as $fila) {
                $b = $fila['body'] ?? $fila; if (empty($b['id'])) continue;
                $sku = collect($b['attributes'] ?? [])->firstWhere('id', 'SELLER_SKU')['value_name'] ?? ($b['seller_custom_field'] ?? null);
                $gtin = collect($b['attributes'] ?? [])->firstWhere('id', 'GTIN')['value_name'] ?? null;
                $out[] = ['id' => $b['id'], 'titulo' => $b['title'] ?? '', 'sku' => $sku, 'precio' => (float) ($b['price'] ?? 0), 'stock' => (int) ($b['available_quantity'] ?? 0), 'estado' => ($b['status'] ?? 'active') === 'active' ? 'activa' : (($b['status'] ?? '') === 'closed' ? 'cerrada' : 'pausada'), 'foto' => $b['thumbnail'] ?? null, 'permalink' => $b['permalink'] ?? null, 'categoria' => $b['category_id'] ?? null, 'con_variantes' => ! empty($b['variations']), 'datos' => ['vendidas' => (int) ($b['sold_quantity'] ?? 0), 'gtin' => $gtin]];
            }
        }
        return $out;
    }

    private function remotasWoo(Canal $c): array
    {
        $w = $this->cx->woo($c); $out = []; $page = 1;
        do {
            $r = $w->get('products', ['per_page' => 100, 'page' => $page, 'status' => 'publish,draft,pending']); if (! $r->ok()) throw new \RuntimeException('WooCommerce no contestó (' . $r->status() . ')');
            $prods = $r->json() ?? [];
            foreach ($prods as $p) {
                if (($p['type'] ?? 'simple') === 'variable') {
                    foreach ($w->get("products/{$p['id']}/variations", ['per_page' => 100])->json() ?? [] as $v) $out[] = ['id' => $p['id'], 'variante_id' => (string) $v['id'], 'titulo' => trim(($p['name'] ?? '') . ' · ' . collect($v['attributes'] ?? [])->pluck('option')->implode(' ')), 'sku' => $v['sku'] ?? null, 'precio' => (float) ($v['price'] ?? 0), 'stock' => (int) ($v['stock_quantity'] ?? 0), 'estado' => ($v['status'] ?? 'publish') === 'publish' ? 'activa' : 'pausada', 'foto' => $v['image']['src'] ?? ($p['images'][0]['src'] ?? null), 'permalink' => $p['permalink'] ?? null, 'categoria' => (string) ($p['categories'][0]['id'] ?? ''), 'con_variantes' => false, 'datos' => ['categoria_nombre' => $p['categories'][0]['name'] ?? null, 'vendidas' => (int) ($p['total_sales'] ?? 0)]];
                } else {
                    $out[] = ['id' => $p['id'], 'titulo' => $p['name'] ?? '', 'sku' => $p['sku'] ?? null, 'precio' => (float) ($p['price'] ?? 0), 'stock' => (int) ($p['stock_quantity'] ?? 0), 'estado' => ($p['status'] ?? 'publish') === 'publish' ? 'activa' : 'pausada', 'foto' => $p['images'][0]['src'] ?? null, 'permalink' => $p['permalink'] ?? null, 'categoria' => (string) ($p['categories'][0]['id'] ?? ''), 'datos' => ['categoria_nombre' => $p['categories'][0]['name'] ?? null, 'vendidas' => (int) ($p['total_sales'] ?? 0)]];
                }
            }
            $page++;
        } while (count($prods) === 100 && $page < 60);
        return $out;
    }

    private function remotasTn(Canal $c): array
    {
        $t = $this->cx->tn($c); $out = []; $page = 1;
        do {
            $r = $t->get('products', ['per_page' => 200, 'page' => $page]); if (! $r->ok() && $r->status() !== 404) throw new \RuntimeException('Tienda Nube no contestó (' . $r->status() . ')');
            $prods = $r->ok() ? ($r->json() ?? []) : [];
            foreach ($prods as $p) {
                $vars = $p['variants'] ?? []; $multi = count($vars) > 1;
                foreach ($vars as $v) $out[] = ['id' => $p['id'], 'variante_id' => (string) $v['id'], 'titulo' => trim(($p['name']['es'] ?? reset($p['name'])) . ($multi ? ' · ' . collect($v['values'] ?? [])->map(fn($x) => $x['es'] ?? reset($x))->implode(' ') : '')), 'sku' => $v['sku'] ?? null, 'precio' => (float) ($v['price'] ?? 0), 'stock' => (int) ($v['stock'] ?? 0), 'estado' => ! empty($p['published']) ? 'activa' : 'pausada', 'foto' => $p['images'][0]['src'] ?? null, 'permalink' => $p['canonical_url'] ?? null, 'categoria' => (string) ($p['categories'][0]['id'] ?? ''), 'con_variantes' => false, 'datos' => ['categoria_nombre' => $p['categories'][0]['name']['es'] ?? null, 'gtin' => $v['barcode'] ?? null]];
            }
            $page++;
        } while (count($prods) === 200 && $page < 60);
        return $out;
    }

    private function remotasShopify(Canal $c): array
    {
        $s = $this->cx->shopify($c); $out = []; $url = 'products.json?limit=250';
        for ($i = 0; $i < 40 && $url; $i++) {
            $r = $s->get($url); if (! $r->ok()) throw new \RuntimeException('Shopify no contestó (' . $r->status() . ')');
            foreach ($r->json('products') ?? [] as $p) {
                $vars = $p['variants'] ?? []; $multi = count($vars) > 1;
                foreach ($vars as $v) $out[] = ['id' => $p['id'], 'variante_id' => (string) $v['id'], 'titulo' => trim(($p['title'] ?? '') . ($multi ? ' · ' . ($v['title'] ?? '') : '')), 'sku' => $v['sku'] ?? null, 'precio' => (float) ($v['price'] ?? 0), 'stock' => (int) ($v['inventory_quantity'] ?? 0), 'estado' => ($p['status'] ?? 'active') === 'active' ? 'activa' : 'pausada', 'foto' => $p['image']['src'] ?? null, 'permalink' => 'https://' . ($c->credenciales['tienda'] ?? '') . '/products/' . ($p['handle'] ?? ''), 'con_variantes' => false, 'datos' => ['inventory_item_id' => $v['inventory_item_id'] ?? null, 'categoria_nombre' => $p['product_type'] ?? null, 'gtin' => $v['barcode'] ?? null]];
            }
            // Paginación por Link header.
            $link = $r->header('Link'); $url = null;
            if ($link && preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m)) $url = $m[1];
        }
        return $out;
    }

    // ---- Sugerencias: por código, por barras, por memoria, por nombre. Segura, dudosa o ninguna. Nunca ata sola ----
    public function sugerencias(Canal $c, Collection $pubs): array
    {
        $prods = Product::where('active', true)->get(['id', 'name', 'sku', 'barcode', 'rubro_id']);
        $porSku = $prods->filter(fn($p) => $p->sku)->keyBy(fn($p) => mb_strtolower(trim($p->sku)));
        $porBar = $prods->filter(fn($p) => $p->barcode)->keyBy(fn($p) => trim($p->barcode));
        $alias = AliasArticulo::pluck('product_id', 'texto');
        $norm = fn($t) => AliasArticulo::normalizar($t);
        $tokens = fn($t) => array_values(array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/', ' ', $norm($t))), fn($x) => strlen($x) > 1));
        $nums = fn($t) => array_values(array_unique(array_map(fn($n) => (float) str_replace(',', '.', $n), preg_grep('/^\d+([.,]\d+)?$/', $tokens($t)) ?: [])));
        $vocab = []; foreach ($prods as $p) foreach ($tokens($p->name) as $tk) $vocab[$tk] = true;
        $out = [];
        foreach ($pubs as $pub) {
            if ($pub->product_id) continue;
            $t = $pub->titulo ?? '';
            $res = null;
            if (($a = $alias[$norm($t)] ?? null) && $prods->firstWhere('id', $a)) $res = ['tipo' => 'segura', 'product_id' => $a, 'por' => 'ya lo ataste antes'];
            elseif ($pub->sku_remoto && ($p = $porSku[mb_strtolower(trim($pub->sku_remoto))] ?? null)) $res = ['tipo' => 'segura', 'product_id' => $p->id, 'por' => "código {$p->sku}"];
            elseif (($g = $pub->datos['gtin'] ?? null) && ($p = $porBar[trim($g)] ?? null)) $res = ['tipo' => 'segura', 'product_id' => $p->id, 'por' => 'código de barras'];
            elseif ($pub->sku_remoto && ($p = $porBar[trim($pub->sku_remoto)] ?? null)) $res = ['tipo' => 'segura', 'product_id' => $p->id, 'por' => 'código de barras'];
            else {
                [$cands, $nota] = $this->porNombre($t, $prods, $tokens, $vocab);
                if ($cands) {
                    $mejor = $cands[0];
                    // Una cantidad distinta ("25 KG" vs "5 kg") baja la sugerencia a dudosa aunque el nombre se parezca.
                    $nt = $nums($t); $np = $nums($mejor['p']->name); $cantDistinta = $nt && $np && ! array_intersect($nt, $np);
                    if ($mejor['score'] >= 72 && (count($cands) === 1 || $cands[1]['score'] < $mejor['score'] - 15) && ! $cantDistinta && ! $nota) $res = ['tipo' => 'segura', 'product_id' => $mejor['p']->id, 'por' => 'nombre'];
                    elseif ($mejor['score'] >= 40) $res = ['tipo' => 'dudosa', 'candidatos' => array_map(fn($x) => ['id' => $x['p']->id, 'nombre' => $x['p']->name, 'sku' => $x['p']->sku, 'score' => (int) $x['score']], array_slice($cands, 0, 3)), 'por' => $nota ? "sin contar «{$nota}»" : ($cantDistinta ? 'la cantidad no coincide' : 'parecido')];
                }
            }
            if ($res && ($pid = $res['product_id'] ?? null)) { $p = $prods->firstWhere('id', $pid); $res += ['nombre' => $p->name, 'sku' => $p->sku]; }
            $out[$pub->id] = $res ?? ['tipo' => 'ninguna'];
        }
        return $out;
    }

    private function porNombre(string $titulo, Collection $prods, \Closure $tokens, array $vocab): array
    {
        $buscar = function (array $tk) use ($prods, $tokens) {
            if (! $tk) return [];
            $nd = implode(' ', $tk); $cands = [];
            foreach ($prods as $p) {
                $tc = $tokens($p->name); $comunes = count(array_intersect($tk, $tc)); if (! $comunes) continue;
                similar_text($nd, implode(' ', $tc), $pct); $score = $pct * 0.5 + ($comunes / max(1, count($tk))) * 35 + ($comunes / max(1, count($tc))) * 15;
                $cands[] = ['p' => $p, 'score' => $score];
            }
            usort($cands, fn($a, $b) => $b['score'] <=> $a['score']);
            return $cands;
        };
        $tk = $tokens($titulo);
        $c = $buscar($tk);
        if ($c && $c[0]['score'] >= 40) return [$c, null];
        // Las palabras que no existen en ningún artículo nuestro ("alimento", "old prince") se sacan y se vuelve a probar: queda como dudosa.
        $raras = array_values(array_filter($tk, fn($x) => empty($vocab[$x]) && ! is_numeric($x)));
        if (! $raras || count($raras) === count($tk)) return [$c, null];
        $c2 = $buscar(array_values(array_diff($tk, $raras)));
        return [$c2, implode(' ', $raras)];
    }

    // ---- Atar, adoptar, desatar ----
    public function atar(Publicacion $pub, Product $p, bool $adoptar = false): Publicacion
    {
        $c = $pub->canal;
        $pub->fill(['product_id' => $p->id, 'origen' => $pub->origen === 'publicada' ? 'publicada' : 'atada', 'adoptada' => $adoptar && ! $pub->con_variantes, 'ultimo_error' => null])->save();
        // Aprende: la próxima publicación con el mismo título sale sugerida sola. Sirve también a los pedidos por WhatsApp y a las compras.
        if ($pub->titulo) AliasArticulo::updateOrCreate(['business_id' => $pub->business_id, 'texto' => AliasArticulo::normalizar($pub->titulo)], ['product_id' => $p->id, 'origen' => 'tienda']);
        // La foto de la tienda pasa al catálogo si el artículo no tiene; nunca pisa una que ya está.
        if (! $p->imagen && $pub->foto_url) $p->forceFill(['imagen' => $pub->foto_url])->saveQuietly();
        // Las ventas pendientes de esa publicación quedan con el artículo.
        $this->arreglarPedidos($pub, $p);
        if ($pub->adoptada) { try { $this->pub->sincronizar($c, collect([$pub->setRelation('product', $p)])); } catch (\Throwable) {} }
        AuditLog::registrar('editar', $pub, "Ató «{$pub->titulo}» a {$p->name}" . ($pub->adoptada ? ' y la adoptó' : ''));
        return $pub->fresh();
    }

    public function adoptar(Publicacion $pub, bool $si = true): Publicacion
    {
        if ($si && $pub->con_variantes) throw new \RuntimeException('Una publicación con variantes se ata pero no se adopta: el stock y el precio viven en cada variante.');
        $pub->forceFill(['adoptada' => $si && $pub->product_id])->save();
        if ($pub->adoptada) { try { $this->pub->sincronizar($pub->canal, collect([$pub->load('product')])); } catch (\Throwable) {} }
        return $pub->fresh();
    }

    public function desatar(Publicacion $pub): void
    {
        if ($pub->titulo) AliasArticulo::where('texto', AliasArticulo::normalizar($pub->titulo))->delete();
        $pub->forceFill(['product_id' => null, 'adoptada' => false, 'origen' => 'remota'])->save();
        AuditLog::registrar('editar', $pub, "Desató «{$pub->titulo}»");
    }

    private function arreglarPedidos(Publicacion $pub, Product $p): void
    {
        foreach (\App\Models\PedidoWeb::withoutGlobalScopes()->where('business_id', $pub->business_id)->where('canal', $pub->canal->tipo)->where('estado', 'nuevo')->get() as $pw) {
            $cambio = false; $items = $pw->items;
            foreach ($items as &$it) { if (empty($it['product_id']) && (($it['external_item'] ?? null) == $pub->external_id) && (! $pub->variante_id || ($it['variante'] ?? null) == $pub->variante_id)) { $it['product_id'] = $p->id; $it['descripcion'] = $p->name; $cambio = true; } }
            if ($cambio) $pw->forceFill(['items' => $items])->save();
        }
    }

    // Las seguras, de un clic. Emparejar es adoptar: desde ahí precio y stock los manda el ERP (salvo variantes).
    public function atarSeguras(Canal $c, int $max = 100): array
    {
        $pubs = Publicacion::where('canal_id', $c->id)->whereNull('product_id')->where('estado', '!=', 'cerrada')->get();
        $sug = $this->sugerencias($c, $pubs); $n = 0;
        foreach ($pubs as $pub) { if ($n >= $max) break; $s = $sug[$pub->id] ?? null; if ($s && $s['tipo'] === 'segura') { $this->atar($pub, Product::findOrFail($s['product_id']), true); $n++; } }
        return ['atadas' => $n];
    }

    public function adoptarAtadas(Canal $c, int $max = 100): array
    {
        $n = 0;
        foreach (Publicacion::where('canal_id', $c->id)->whereNotNull('product_id')->where('adoptada', false)->where('con_variantes', false)->where('origen', 'atada')->limit($max)->get() as $pub) { $this->adoptar($pub, true); $n++; }
        return ['adoptadas' => $n];
    }

    public function traerFotos(Canal $c): int
    {
        $n = 0;
        foreach (Publicacion::where('canal_id', $c->id)->whereNotNull('product_id')->whereNotNull('foto_url')->with('product')->get() as $pub) { if ($pub->product && ! $pub->product->imagen) { $pub->product->forceFill(['imagen' => $pub->foto_url])->saveQuietly(); $n++; } }
        return $n;
    }

    // Cuántas ventas esperan por publicaciones sin artículo (para el cartel de Pedidos).
    public function pendientesSinArticulo(Canal $c): array
    {
        $pw = \App\Models\PedidoWeb::where('canal', $c->tipo)->where('estado', 'nuevo')->get();
        $items = $pw->flatMap(fn($p) => collect($p->items)->filter(fn($i) => empty($i['product_id']) && ! empty($i['external_item']))->map(fn($i) => $i['external_item']));
        return ['ventas' => $pw->filter(fn($p) => collect($p->items)->contains(fn($i) => empty($i['product_id'])))->count(), 'publicaciones' => $items->unique()->count()];
    }
}
