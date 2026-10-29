<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Canal;
use App\Models\Product;
use App\Models\Publicacion;
use App\Models\Rubro;
use App\Models\RubroCanal;
use App\Services\Canales\PublicacionesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 30.2: Stock → Tienda online. Publicar desde el catálogo (por rubro o buscando), revisar antes de que salga, categoría y datos
// de cada rubro, lista por tienda/rubro/artículo, y lo publicado con su estado.
class TiendaOnlineController extends Controller
{
    public function __construct(private PublicacionesService $pub) {}

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
