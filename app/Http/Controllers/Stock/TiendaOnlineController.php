<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Canal;
use App\Models\Product;
use App\Models\Publicacion;
use App\Models\Rubro;
use App\Models\RubroCanal;
use App\Services\Canales\EmparejarService;
use App\Services\Canales\PublicacionesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 30.2: Stock → Tienda online. Publicar desde el catálogo (por rubro o buscando), revisar antes de que salga, categoría y datos
// de cada rubro, lista por tienda/rubro/artículo, y lo publicado con su estado.
class TiendaOnlineController extends Controller
{
    public function __construct(private PublicacionesService $pub, private EmparejarService $emp) {}

    private function canales() { return Canal::where('activo', true)->whereIn('tipo', ['mercadolibre', 'woocommerce', 'shopify', 'tiendanube'])->orderBy('nombre')->get(); }

    public function index(Request $request)
    {
        $canales = $this->canales();
        $canal = $request->canal ? $canales->firstWhere('id', (int) $request->canal) : $canales->first();
        $rubros = Rubro::with('parent')->orderBy('nombre')->get();
        $rcs = $canal ? RubroCanal::where('canal_id', $canal->id)->get()->keyBy('rubro_id') : collect();
        $pubsPorRubro = $canal ? Publicacion::where('canal_id', $canal->id)->whereNotNull('product_id')->join('products', 'products.id', '=', 'publicaciones.product_id')->selectRaw('products.rubro_id r, COUNT(*) n')->groupBy('products.rubro_id')->pluck('n', 'r') : collect();
        $articulosPorRubro = Product::where('active', true)->whereIn('tipo', ['producto', 'elaborado'])->selectRaw('rubro_id r, COUNT(*) n')->groupBy('rubro_id')->pluck('n', 'r');
        return Inertia::render('Stock/TiendaOnline', [
            'canales' => $canales->map(fn($c) => ['id' => $c->id, 'tipo' => $c->tipo, 'nombre' => $c->nombre, 'label' => Canal::TIPOS[$c->tipo]['label'] ?? $c->tipo, 'cfg' => $c->cfg(), 'publicadas' => $c->publicaciones()->count(), 'con_error' => $c->publicaciones()->where('estado', 'error')->count(), 'ultimo_sync' => $c->ultimo_sync_en?->diffForHumans()]),
            'canal' => $canal?->id,
            'rubros' => $rubros->map(fn($r) => ['id' => $r->id, 'nombre' => $r->nombreCompleto(), 'lista_bigsys' => null, 'articulos' => (int) ($articulosPorRubro[$r->id] ?? 0), 'publicados' => (int) ($pubsPorRubro[$r->id] ?? 0), 'canal' => isset($rcs[$r->id]) ? ['categoria_id' => $rcs[$r->id]->categoria_id, 'categoria_nombre' => $rcs[$r->id]->categoria_nombre, 'lista' => $rcs[$r->id]->lista, 'atributos' => $rcs[$r->id]->atributos] : null])->filter(fn($r) => $r['articulos'] > 0)->values(),
            'publicaciones' => $canal ? Publicacion::where('canal_id', $canal->id)->with('product:id,name,sku,imagen')->orderByDesc('updated_at')->limit(500)->get()->map(fn($p) => ['id' => $p->id, 'product_id' => $p->product_id, 'nombre' => $p->product?->name ?? $p->titulo, 'sku' => $p->product?->sku ?? $p->sku_remoto, 'foto' => $p->product?->imagen ?: $p->foto_url, 'external_id' => $p->external_id, 'precio' => $p->precio_remoto, 'stock' => $p->stock_remoto, 'lista' => $p->lista, 'estado' => $p->estado, 'origen' => $p->origen, 'adoptada' => $p->adoptada, 'con_variantes' => $p->con_variantes, 'permalink' => $p->permalink, 'error' => $p->ultimo_error, 'sync' => $p->sync_at?->diffForHumans()]) : [],
            'listas' => [1, 2, 3, 4, 5, 6],
        ]);
    }

    // Los artículos de un rubro (o una búsqueda) con su estado en la tienda, para tildar qué publicar.
    public function catalogo(Request $request, int $canal)
    {
        $c = Canal::findOrFail($canal);
        $q = trim((string) $request->q); $like = \App\Support\Sql::like();
        $prods = Product::where('active', true)->whereIn('tipo', ['producto', 'elaborado'])->when($request->rubro, fn($b) => $b->whereIn('rubro_id', Rubro::conDescendientes((int) $request->rubro)))
            ->when($q !== '', fn($b) => $b->where(fn($w) => $w->where('name', $like, "%{$q}%")->orWhere('sku', $like, "%{$q}%")->orWhere('barcode', $like, "%{$q}%")->orWhere('description', $like, "%{$q}%")))
            ->when($request->boolean('sin_publicar'), fn($b) => $b->whereNotIn('id', Publicacion::where('canal_id', $c->id)->whereNotNull('product_id')->select('product_id')))
            ->when($request->boolean('sin_foto'), fn($b) => $b->whereNull('imagen'))
            ->orderBy('name')->limit(300)->get();
        return response()->json($this->pub->revisar($c, $prods->pluck('id')->all()));
    }

    public function revisar(Request $request, int $canal)
    {
        $d = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer', 'listas' => 'nullable|array']);
        return response()->json($this->pub->revisar(Canal::findOrFail($canal), $d['ids'], $d['listas'] ?? []));
    }

    public function publicar(Request $request, int $canal)
    {
        $d = $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer', 'listas' => 'nullable|array', 'categorias' => 'nullable|array']);
        $c = Canal::findOrFail($canal);
        $r = $this->pub->publicar($c, $d['ids'], $d['listas'] ?? [], $d['categorias'] ?? []);
        return response()->json($r);
    }

    // Cómo va el rubro a esta tienda: categoría, lista y los datos que pide la categoría.
    public function rubro(Request $request, int $canal, int $rubro)
    {
        $d = $request->validate(['categoria_id' => 'nullable|string|max:40', 'categoria_nombre' => 'nullable|string|max:150', 'lista' => 'nullable|integer|min:1|max:6', 'atributos' => 'nullable|array']);
        $rc = $this->pub->guardarRubro(Canal::findOrFail($canal), Rubro::findOrFail($rubro), $d);
        return response()->json(['ok' => true, 'rubro' => ['categoria_id' => $rc->categoria_id, 'categoria_nombre' => $rc->categoria_nombre, 'lista' => $rc->lista, 'atributos' => $rc->atributos]]);
    }

    public function categorias(Request $request, int $canal)
    {
        try { return $this->categoriasDe($request, Canal::findOrFail($canal)); }
        catch (\Throwable $e) { return response()->json(['message' => 'No se pudo hablar con la tienda: ' . mb_substr($e->getMessage(), 0, 160) . '. Reintentá o revisá la conexión en Configuración → Tienda y canales.'], 422); }
    }

    private function categoriasDe(Request $request, Canal $c)
    {
        if ($c->tipo !== 'mercadolibre') {
            // Las categorías que ya tiene la web, para unir el rubro a una de ellas.
            $cx = app(\App\Services\Canales\ConexionesService::class);
            $lista = match ($c->tipo) {
                'woocommerce' => collect($cx->woo($c)->get('products/categories', ['per_page' => 100])->json() ?? [])->map(fn($x) => ['id' => (string) $x['id'], 'nombre' => $x['name']]),
                'tiendanube' => collect($cx->tn($c)->get('categories', ['per_page' => 200])->json() ?? [])->map(fn($x) => ['id' => (string) $x['id'], 'nombre' => $x['name']['es'] ?? reset($x['name'])]),
                'shopify' => collect($cx->shopify($c)->get('custom_collections.json', ['limit' => 250])->json('custom_collections') ?? [])->map(fn($x) => ['id' => (string) $x['id'], 'nombre' => $x['title']]),
                default => collect(),
            };
            return response()->json($lista->values()->all());
        }
        $texto = trim((string) $request->q);
        if ($texto === '' && $request->rubro) {
            // Se propone a partir del producto más vendido del rubro.
            $ids = Rubro::conDescendientes((int) $request->rubro);
            $top = \App\Models\ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->join('products', 'products.id', '=', 'comprobante_items.product_id')->whereIn('products.rubro_id', $ids)->where('comprobantes.direccion', 'venta')->selectRaw('products.name n, SUM(comprobante_items.cantidad) c')->groupBy('products.name')->orderByDesc('c')->value('n');
            $texto = $top ?: (Product::whereIn('rubro_id', $ids)->value('name') ?: Rubro::find((int) $request->rubro)?->nombre ?? '');
        }
        return response()->json($this->pub->categoriasSugeridas($c, $texto));
    }

    public function atributos(int $canal, string $categoria)
    {
        try { return response()->json($this->pub->atributosObligatorios(Canal::findOrFail($canal), $categoria)); }
        catch (\Throwable $e) { return response()->json(['message' => 'MercadoLibre no contestó: ' . mb_substr($e->getMessage(), 0, 160)], 422); }
    }

    // ---- Fase 30.3: las que ya tenés en la tienda ----
    private function filaExistente(Publicacion $p, array $sug, Canal $c, $rcs): array
    {
        $prod = $p->product;
        $lista = $prod ? $this->pub->listaPara($c, $prod, $p, $prod->rubro_id ? ($rcs[$prod->rubro_id] ?? null) : null) : null;
        return ['id' => $p->id, 'external_id' => $p->external_id, 'variante_id' => $p->variante_id, 'titulo' => $p->titulo, 'sku_remoto' => $p->sku_remoto, 'precio_web' => $p->precio_remoto, 'stock_web' => $p->stock_remoto, 'estado' => $p->estado, 'foto' => $p->foto_url, 'permalink' => $p->permalink, 'vendidas' => $p->datos['vendidas'] ?? null, 'categoria' => $p->datos['categoria_nombre'] ?? $p->categoria_id, 'con_variantes' => $p->con_variantes, 'origen' => $p->origen, 'adoptada' => $p->adoptada, 'error' => $p->ultimo_error,
            'articulo' => $prod ? ['id' => $prod->id, 'nombre' => $prod->name, 'sku' => $prod->sku, 'precio_erp' => $this->pub->tienePrecio($prod, $lista) ? $this->pub->precioPara($c, $prod, $lista) : null, 'lista' => $lista, 'stock_erp' => $this->pub->stockPara($c, $prod)] : null,
            'sugerencia' => $sug[$p->id] ?? null];
    }

    public function existentes(Request $request, int $canal)
    {
        $c = Canal::findOrFail($canal);
        if ($request->boolean('leer')) { try { $this->emp->leer($c); } catch (\Throwable $e) { return response()->json(['message' => 'No se pudo leer la tienda: ' . mb_substr($e->getMessage(), 0, 160) . '. Reintentá.'], 422); } }
        $pubs = Publicacion::where('canal_id', $c->id)->where('origen', '!=', 'publicada')->where('estado', '!=', 'cerrada')->with('product:id,name,sku,rubro_id,price,prices,iva,stock,moneda')->orderByDesc('product_id')->orderBy('titulo')->get();
        $sug = $this->emp->sugerencias($c, $pubs->whereNull('product_id'));
        $rcs = RubroCanal::where('canal_id', $c->id)->get()->keyBy('rubro_id');
        return response()->json(['items' => $pubs->map(fn($p) => $this->filaExistente($p, $sug, $c, $rcs))->values()->all(), 'seguras' => collect($sug)->where('tipo', 'segura')->count(), 'atadas_sin_adoptar' => $pubs->filter(fn($p) => $p->product_id && ! $p->adoptada && ! $p->con_variantes)->count(), 'pendientes' => $this->emp->pendientesSinArticulo($c), 'leida' => $c->publicaciones()->max('updated_at')]);
    }

    public function atar(Request $request, int $id)
    {
        $d = $request->validate(['product_id' => 'required|exists:products,id', 'adoptar' => 'boolean']);
        $p = Publicacion::findOrFail($id);
        $p = $this->emp->atar($p, Product::findOrFail($d['product_id']), $request->boolean('adoptar'));
        return response()->json(['ok' => true, 'adoptada' => $p->adoptada, 'error' => $p->ultimo_error]);
    }

    public function desatar(int $id) { $this->emp->desatar(Publicacion::findOrFail($id)); return response()->json(['ok' => true]); }

    public function adoptar(Request $request, int $id)
    {
        try { $p = $this->emp->adoptar(Publicacion::findOrFail($id), ! $request->boolean('dejar')); }
        catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['ok' => true, 'adoptada' => $p->adoptada, 'error' => $p->ultimo_error]);
    }

    public function atarSeguras(int $canal) { return response()->json($this->emp->atarSeguras(Canal::findOrFail($canal))); }
    public function adoptarAtadas(int $canal) { return response()->json($this->emp->adoptarAtadas(Canal::findOrFail($canal))); }
    public function traerFotos(int $canal) { return response()->json(['fotos' => $this->emp->traerFotos(Canal::findOrFail($canal))]); }

    // Buscar un artículo para atarlo: nombre, descripción, código o barras, con las palabras en cualquier orden.
    public function buscarArticulo(Request $request)
    {
        $q = trim((string) $request->q); if ($q === '') return response()->json([]);
        $like = \App\Support\Sql::like(); $tk = array_filter(explode(' ', $q), fn($t) => strlen($t) > 1);
        $prods = Product::where('active', true)->where(function ($w) use ($tk, $like, $q) { foreach ($tk as $t) $w->where(fn($x) => $x->where('name', $like, "%{$t}%")->orWhere('description', $like, "%{$t}%")->orWhere('sku', $like, "%{$t}%")->orWhere('barcode', $like, "%{$t}%")); })->orderBy('name')->limit(20)->get(['id', 'name', 'sku', 'barcode', 'description', 'imagen']);
        return response()->json($prods->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'foto' => $p->imagen, 'por' => stripos($p->name, $q) === false && $p->description && stripos($p->description, $tk[array_key_first($tk)] ?? $q) !== false ? '…' . mb_substr($p->description, max(0, stripos($p->description, $tk[array_key_first($tk)] ?? $q) - 20), 60) . '…' : null]));
    }

    // ---- Fase 30.5: cargos de MercadoLibre, por mes y para el contador ----
    public function cargos(Request $request, int $canal, \App\Services\Canales\VentasTiendaService $ventas)
    {
        $c = Canal::findOrFail($canal); $mes = $request->query('mes') ?: today()->format('Y-m');
        if ($request->boolean('csv')) return response($ventas->csvContador($c, $mes), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=cargos-ml-{$mes}.csv"]);
        return response()->json($ventas->resumenMes($c, $mes));
    }

    public function compraCargos(Request $request, int $canal, \App\Services\Canales\VentasTiendaService $ventas)
    {
        $d = $request->validate(['mes' => 'required|date_format:Y-m']);
        $compra = $ventas->compraCargos(Canal::findOrFail($canal), $d['mes']);
        return response()->json(['ok' => true, 'compra_id' => $compra->id]);
    }

    // ---- Fase 30.6: Empretienda por planilla ----
    public function empretienda(Request $request, \App\Services\Canales\ExportacionPedidosService $svc)
    {
        $d = $request->validate(['rubro' => 'nullable|integer', 'colchon' => 'nullable|integer|min:0', 'lista' => 'nullable|integer|min:1|max:6', 'fotos' => 'boolean']);
        if ($request->boolean('fotos')) { $r = $svc->zipFotos($request->user()->business, $d['rubro'] ?? null); return response($r['zip'], 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename=fotos-empretienda.zip']); }
        $r = $svc->empretienda($request->user()->business, $d['rubro'] ?? null, (int) ($d['colchon'] ?? 0), (int) ($d['lista'] ?? 1));
        return response($r['xlsx'], 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename=empretienda-' . today()->toDateString() . '.xlsx', 'X-Cantidad' => $r['cantidad']]);
    }

    public function pausar(Request $request, int $id)
    {
        $p = Publicacion::findOrFail($id);
        $this->pub->pausar($p->canal, $p, ! $request->boolean('activar'));
        return response()->json(['ok' => true, 'estado' => $p->estado]);
    }

    public function sincronizar(int $canal)
    {
        $c = Canal::findOrFail($canal);
        $r = $this->pub->sincronizar($c);
        return back()->with('success', "{$c->nombre}: {$r['actualizadas']} actualizadas" . ($r['errores'] ? ", {$r['errores']} con error" : '') . '.');
    }
}
