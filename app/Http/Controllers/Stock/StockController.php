<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Deposito;
use App\Models\Inventario;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\StockDeposito;
use App\Models\StockMovement;
use App\Models\TransferenciaStock;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// Artículos, stock por depósito, kardex, ajustes, transferencias, inventarios, rubros y depósitos.
class StockController extends Controller
{
    private function depositos()
    {
        return Deposito::with('location:id,name')->orderByDesc('activo')->orderBy('business_location_id')->orderByDesc('es_default')->get()
            ->map(fn($d) => ['id' => $d->id, 'nombre' => $d->nombre, 'sucursal' => $d->location?->name, 'business_location_id' => $d->business_location_id, 'es_default' => $d->es_default, 'activo' => $d->activo, 'direccion' => $d->direccion]);
    }

    private function rubros()
    {
        return Rubro::arbol()->map(fn($r) => ['id' => $r->id, 'nombre' => $r->nombre, 'parent_id' => $r->parent_id, 'nivel' => $r->nivel, 'completo' => $r->nombreCompleto(), 'color' => $r->color])->values();
    }

    public function index(Request $request)
    {
        $depositos = $this->depositos();
        $q = Product::with('rubro:id,nombre,parent_id,color', 'stocks')
            ->when($request->buscar, fn($q, $b) => $q->where(fn($w) => $w->where('name', 'like', "%$b%")->orWhere('sku', 'like', "%$b%")->orWhere('barcode', $b)->orWhere('marca', 'like', "%$b%")))
            ->when($request->rubro, fn($q, $r) => $q->whereIn('rubro_id', Rubro::conDescendientes((int) $r)))
            ->when($request->tipo, fn($q, $t) => $q->where('tipo', $t))
            ->when($request->estado === 'bajo_minimo', fn($q) => $q->where('controla_stock', true)->whereColumn('stock', '<=', 'stock_min'))
            ->when($request->estado === 'sin_stock', fn($q) => $q->where('controla_stock', true)->where('stock', '<=', 0))
            ->when($request->estado === 'inactivos', fn($q) => $q->where('active', false), fn($q) => $q->when($request->estado !== 'todos', fn($q) => $q->where('active', true)))
            ->orderBy('name');

        $lista = $q->paginate(40)->withQueryString()->through(fn($p) => [
            'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'tipo' => $p->tipo, 'unit' => $p->unit, 'rubro' => $p->rubro?->nombre, 'rubro_color' => $p->rubro?->color, 'marca' => $p->marca,
            'stock' => (float) $p->stock, 'stock_min' => (float) $p->stock_min, 'bajo' => $p->bajoMinimo(), 'controla' => $p->controla_stock, 'active' => $p->active,
            'price' => (float) $p->price, 'cost' => (float) $p->cost, 'valor' => round((float) $p->stock * (float) $p->cost, 2), 'moneda' => $p->moneda, 'precio_pesos' => $p->precioLista(1),
            'precio_compra' => (float) $p->precio_compra, 'descuento_proveedor' => (float) $p->descuento_proveedor, 'margenes' => $p->margenes, 'desc_cant_min' => (float) $p->desc_cant_min, 'desc_cant_pct' => (float) $p->desc_cant_pct, 'desc_cant2_min' => (float) $p->desc_cant2_min, 'desc_cant2_pct' => (float) $p->desc_cant2_pct, 'perecedero' => $p->perecedero, 'seriado' => $p->seriado, 'en_tienda' => $p->en_tienda, 'descripcion_tienda' => $p->descripcion_tienda,
            'por_deposito' => $p->stocks->mapWithKeys(fn($s) => [$s->deposito_id => (float) $s->cantidad]),
        ]);

        $activos = Product::where('active', true);
        return Inertia::render('Stock/Index', [
            'lista' => $lista, 'filtros' => $request->only('buscar', 'rubro', 'tipo', 'estado'), 'depositos' => $depositos, 'rubros' => $this->rubros(),
            'tipos' => Product::TIPOS, 'unidades' => Product::UNIDADES,
            'cotizacion' => ($cot = \App\Models\Cotizacion::actual($request->user()->business_id)) ? ['venta' => (float) $cot->venta, 'fecha' => $cot->fecha->format('d/m/Y'), 'manual' => $cot->business_id !== null] : null,
            'kpis' => [
                'articulos' => (clone $activos)->count(),
                'valorizado' => (float) (clone $activos)->where('controla_stock', true)->selectRaw('COALESCE(SUM(stock * cost),0) as v')->value('v'),
                'bajo_minimo' => (clone $activos)->where('controla_stock', true)->whereColumn('stock', '<=', 'stock_min')->count(),
                'sin_stock' => (clone $activos)->where('controla_stock', true)->where('stock', '<=', 0)->count(),
            ],
            'proveedores' => Contact::suppliers()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'ultimosInventarios' => Inventario::with('deposito:id,nombre')->latest('fecha')->limit(3)->get()->map(fn($i) => ['id' => $i->id, 'numero' => $i->numeroFormateado(), 'fecha' => $i->fecha->format('d/m/Y'), 'deposito' => $i->deposito?->nombre, 'difs' => $i->items_con_diferencia, 'valor' => (float) $i->diferencia_valorizada]),
        ]);
    }

    public function show(int $id, Request $request)
    {
        $p = Product::with('rubro', 'proveedor:id,name', 'stocks.deposito.location')->findOrFail($id);
        $movs = StockMovement::where('product_id', $p->id)->with('user:id,name', 'deposito:id,nombre')
            ->when($request->deposito, fn($q, $d) => $q->where('deposito_id', $d))
            ->latest('created_at')->latest('id')->paginate(30)->withQueryString()
            ->through(fn($m) => ['id' => $m->id, 'fecha' => $m->created_at->format('d/m/Y H:i'), 'tipo' => $m->type, 'tipo_label' => StockMovement::TIPOS[$m->type] ?? $m->type, 'entrada' => in_array($m->type, ['in'], true) || ((float) $m->stock_after > (float) $m->stock_before), 'cantidad' => (float) $m->quantity, 'antes' => (float) $m->stock_before, 'despues' => (float) $m->stock_after, 'motivo' => $m->reason, 'deposito' => $m->deposito?->nombre, 'usuario' => $m->user?->name, 'url' => $this->urlOrigen($m)]);

        $hace30 = today()->subDays(30);
        $vendido30 = (float) \App\Models\ComprobanteItem::where('product_id', $p->id)->whereHas('comprobante', fn($q) => $q->ventas()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'REM'])->where('fecha', '>=', $hace30))->sum('cantidad');
        $comprado30 = (float) \App\Models\ComprobanteItem::where('product_id', $p->id)->whereHas('comprobante', fn($q) => $q->compras()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC'])->where('fecha', '>=', $hace30))->sum('cantidad');

        return Inertia::render('Stock/Ver', [
            'p' => $p->only('id', 'name', 'sku', 'tipo', 'barcode', 'marca', 'description', 'unit', 'active', 'controla_stock', 'rubro_id', 'proveedor_id', 'stock_min', 'iva', 'perecedero', 'seriado') + [
                'lotes' => $p->lotes()->with('deposito:id,nombre')->get()->map(fn($l) => ['id' => $l->id, 'etiqueta' => $l->etiqueta(), 'lote' => $l->lote, 'serie' => $l->serie, 'vencimiento' => $l->vencimiento?->format('d/m/Y'), 'vencido' => $l->vencimiento?->isPast() ?? false, 'por_vencer' => $l->vencimiento && ! $l->vencimiento->isPast() && $l->vencimiento->lte(today()->addDays(30)), 'cantidad' => (float) $l->cantidad, 'deposito' => $l->deposito?->nombre]),
                'rubro' => $p->rubro?->nombreCompleto(), 'proveedor' => $p->proveedor?->name, 'stock' => (float) $p->stock, 'price' => (float) $p->price, 'cost' => (float) $p->cost, 'prices' => $p->prices ?? [], 'precio_pesos' => $p->precioLista(1),
                'precio_compra' => (float) $p->precio_compra, 'descuento_proveedor' => (float) $p->descuento_proveedor, 'margenes' => $p->margenes, 'moneda' => $p->moneda, 'desc_cant_min' => (float) $p->desc_cant_min, 'desc_cant_pct' => (float) $p->desc_cant_pct, 'desc_cant2_min' => (float) $p->desc_cant2_min, 'desc_cant2_pct' => (float) $p->desc_cant2_pct,
                'valor' => round((float) $p->stock * (float) $p->cost, 2), 'margen' => (float) $p->cost > 0 ? round(((float) $p->price - (float) $p->cost) / (float) $p->cost * 100, 1) : null,
                'precio_actualizado' => $p->precio_actualizado_en?->format('d/m/Y'), 'bajo' => $p->bajoMinimo(), 'tipo_label' => Product::TIPOS[$p->tipo] ?? $p->tipo,
                'stocks' => $p->stocks->map(fn($s) => ['deposito_id' => $s->deposito_id, 'deposito' => $s->deposito?->nombre, 'sucursal' => $s->deposito?->location?->name, 'cantidad' => (float) $s->cantidad, 'ubicacion' => $s->ubicacion]),
                'vendido_30' => $vendido30, 'comprado_30' => $comprado30, 'dias_stock' => $vendido30 > 0 ? round((float) $p->stock / ($vendido30 / 30)) : null,
            ],
            'movimientos' => $movs, 'filtros' => $request->only('deposito'), 'depositos' => $this->depositos(), 'rubros' => $this->rubros(), 'tipos' => Product::TIPOS, 'unidades' => Product::UNIDADES,
            'proveedores' => Contact::suppliers()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'formula' => $p->receta ? ['id' => $p->receta->id, 'name' => $p->receta->name] : null,
        ]);
    }

    private function urlOrigen(StockMovement $m): ?string
    {
        return match ($m->movable_type) {
            \App\Models\Comprobante::class => \App\Models\Comprobante::withoutGlobalScopes()->where('id', $m->movable_id)->value('direccion') === 'compra' ? "/proveedores/compras/{$m->movable_id}" : "/comprobantes/{$m->movable_id}",
            \App\Models\ProductionOrder::class => '/produccion?orden=' . $m->movable_id,
            default => null,
        };
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:150', 'sku' => ['nullable', 'string', 'max:40', Rule::unique('products', 'sku')->ignore($id)->whereNull('deleted_at')], 'tipo' => ['required', Rule::in(array_keys(Product::TIPOS))],
            'barcode' => 'nullable|string|max:40', 'marca' => 'nullable|string|max:60', 'rubro_id' => 'nullable|exists:rubros,id', 'proveedor_id' => 'nullable|exists:contacts,id', 'description' => 'nullable|string|max:500',
            'unit' => 'required|string|max:10', 'iva' => 'required|numeric|min:0|max:27', 'price' => 'required|numeric|min:0', 'cost' => 'required|numeric|min:0', 'prices' => 'nullable|array',
            'stock_min' => 'nullable|numeric|min:0', 'active' => 'boolean', 'controla_stock' => 'boolean', 'stock_inicial' => 'nullable|numeric|min:0', 'deposito_id' => 'nullable|exists:depositos,id',
            'precio_compra' => 'nullable|numeric|min:0', 'descuento_proveedor' => 'nullable|numeric|min:0|max:100', 'margenes' => 'nullable|array', 'moneda' => 'nullable|in:ARS,USD', 'desc_cant_min' => 'nullable|numeric|min:0', 'desc_cant_pct' => 'nullable|numeric|min:0|max:100', 'desc_cant2_min' => 'nullable|numeric|min:0', 'desc_cant2_pct' => 'nullable|numeric|min:0|max:100', 'usar_margenes' => 'boolean', 'perecedero' => 'boolean', 'seriado' => 'boolean', 'en_tienda' => 'boolean', 'descripcion_tienda' => 'nullable|string|max:500',
        ]);
        $d['margenes'] = ($d['usar_margenes'] ?? false) ? collect($d['margenes'] ?? [])->filter(fn($v) => $v !== null && $v !== '')->all() ?: null : null;
        $d['moneda'] = $d['moneda'] ?? 'ARS';
        $p = $id ? Product::findOrFail($id) : new Product(['business_id' => $request->user()->business_id, 'business_location_id' => $request->user()->current_location_id]);
        $antes = $p->exists ? ['price' => (float) $p->price, 'cost' => (float) $p->cost] : null;
        $d['sku'] = $d['sku'] ?: strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($d['name'])), 0, 6)) . '-' . str_pad((string) (Product::withTrashed()->count() + 1), 4, '0', STR_PAD_LEFT);
        $d['controla_stock'] = $d['tipo'] === 'servicio' ? false : ($d['controla_stock'] ?? true);
        if ($antes && ((float) $d['price'] !== $antes['price'])) $d['precio_actualizado_en'] = now();
        $p->fill(collect($d)->except('stock_inicial', 'deposito_id', 'usar_margenes')->all());
        if ($d['margenes'] || (float) ($d['precio_compra'] ?? 0) > 0) $p->recalcularDesdeCosto();
        $p->save();
        if (! $id && ! empty($d['stock_inicial']) && $p->controla_stock) {
            app(StockService::class)->entrada($p, (float) $d['stock_inicial'], 'Stock inicial', null, $d['deposito_id'] ? Deposito::find($d['deposito_id']) : null, (float) $d['cost']);
        }
        AuditLog::registrar($id ? 'editar' : 'crear', $p, "Artículo {$p->name}", $antes, ['price' => (float) $d['price'], 'cost' => (float) $d['cost']]);
        app(\App\Services\Integraciones\WebhookService::class)->disparar($p->business_id, 'articulo.actualizado', ['id' => $p->id, 'sku' => $p->sku, 'nombre' => $p->name, 'precio' => (float) $p->price, 'costo' => (float) $p->cost, 'stock' => (float) $p->stock, 'nuevo' => ! $id]);
        return back()->with('success', $id ? 'Artículo actualizado.' : "Artículo {$p->name} creado.");
    }

    public function ajustar(Request $request, StockService $stock)
    {
        $d = $request->validate(['product_id' => 'required|exists:products,id', 'deposito_id' => 'required|exists:depositos,id', 'nuevo' => 'required|numeric|min:0', 'motivo' => 'required|string|max:150']);
        $p = Product::findOrFail($d['product_id']);
        $m = $stock->ajustar($p, Deposito::findOrFail($d['deposito_id']), (float) $d['nuevo'], $d['motivo']);
        return back()->with('success', $m ? "Stock de {$p->name} ajustado a {$d['nuevo']} {$p->unit}." : 'No había diferencia que ajustar.');
    }

    public function transferir(Request $request, StockService $stock)
    {
        $d = $request->validate(['origen_id' => 'required|exists:depositos,id', 'destino_id' => 'required|exists:depositos,id|different:origen_id', 'fecha' => 'required|date', 'notas' => 'nullable|string|max:200', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.cantidad' => 'required|numeric|min:0.001']);
        $t = $stock->transferir(Deposito::findOrFail($d['origen_id']), Deposito::findOrFail($d['destino_id']), $d['items'], $d['fecha'], $d['notas'] ?? null);
        return back()->with('success', "Transferencia {$t->numeroFormateado()} registrada.");
    }

    public function movimientos(Request $request)
    {
        $movs = StockMovement::with('product:id,name,sku,unit', 'user:id,name', 'deposito:id,nombre')
            ->when($request->deposito, fn($q, $d) => $q->where('deposito_id', $d))
            ->when($request->tipo, fn($q, $t) => $q->where('type', $t))
            ->when($request->buscar, fn($q, $b) => $q->whereHas('product', fn($w) => $w->where('name', 'like', "%$b%")->orWhere('sku', 'like', "%$b%")))
            ->when($request->desde, fn($q, $d) => $q->whereDate('created_at', '>=', $d))->when($request->hasta, fn($q, $h) => $q->whereDate('created_at', '<=', $h))
            ->latest('created_at')->latest('id')->paginate(50)->withQueryString()
            ->through(fn($m) => ['id' => $m->id, 'fecha' => $m->created_at->format('d/m/Y H:i'), 'product_id' => $m->product_id, 'articulo' => $m->product?->name, 'sku' => $m->product?->sku, 'unit' => $m->product?->unit, 'tipo' => $m->type, 'tipo_label' => StockMovement::TIPOS[$m->type] ?? $m->type, 'entrada' => (float) $m->stock_after > (float) $m->stock_before, 'cantidad' => (float) $m->quantity, 'despues' => (float) $m->stock_after, 'motivo' => $m->reason, 'deposito' => $m->deposito?->nombre, 'usuario' => $m->user?->name, 'url' => $this->urlOrigen($m)]);

        return Inertia::render('Stock/Movimientos', [
            'movimientos' => $movs, 'filtros' => $request->only('deposito', 'tipo', 'buscar', 'desde', 'hasta'), 'depositos' => $this->depositos(), 'tipos' => StockMovement::TIPOS,
            'transferencias' => TransferenciaStock::with('origen:id,nombre', 'destino:id,nombre', 'user:id,name', 'items.product:id,name,unit')->latest('fecha')->latest('id')->limit(10)->get()->map(fn($t) => ['id' => $t->id, 'numero' => $t->numeroFormateado(), 'fecha' => $t->fecha->format('d/m/Y'), 'origen' => $t->origen?->nombre, 'destino' => $t->destino?->nombre, 'estado' => $t->estado, 'usuario' => $t->user?->name, 'items' => $t->items->map(fn($i) => "{$i->product?->name} × " . rtrim(rtrim(number_format((float) $i->cantidad, 3, ',', '.'), '0'), ','))->implode(', '), 'notas' => $t->notas]),
        ]);
    }

    public function anularTransferencia(Request $request, int $id, StockService $stock)
    {
        $d = $request->validate(['motivo' => 'required|string|max:200']);
        $stock->anularTransferencia(TransferenciaStock::findOrFail($id), $d['motivo']);
        return back()->with('success', 'Transferencia anulada y stock devuelto.');
    }

    public function inventario(Request $request)
    {
        $depositos = $this->depositos();
        $depId = (int) ($request->deposito ?: ($depositos->firstWhere('business_location_id', $request->user()->current_location_id)['id'] ?? $depositos->first()['id'] ?? 0));
        $items = Product::where('active', true)->where('controla_stock', true)->with('rubro:id,nombre')
            ->when($request->rubro, fn($q, $r) => $q->whereIn('rubro_id', Rubro::conDescendientes((int) $r)))
            ->orderBy('name')->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit, 'rubro' => $p->rubro?->nombre, 'cost' => (float) $p->cost, 'sistema' => $p->stockEn($depId)]);

        return Inertia::render('Stock/Inventario', [
            'items' => $items, 'depositoId' => $depId, 'depositos' => $depositos, 'rubros' => $this->rubros(), 'filtros' => $request->only('deposito', 'rubro'),
            'historial' => Inventario::with('deposito:id,nombre', 'user:id,name')->latest('fecha')->latest('id')->limit(8)->get()->map(fn($i) => ['id' => $i->id, 'numero' => $i->numeroFormateado(), 'fecha' => $i->fecha->format('d/m/Y'), 'deposito' => $i->deposito?->nombre, 'usuario' => $i->user?->name, 'contados' => $i->items_contados, 'difs' => $i->items_con_diferencia, 'valor' => (float) $i->diferencia_valorizada]),
        ]);
    }

    public function cerrarInventario(Request $request, StockService $stock)
    {
        $d = $request->validate(['deposito_id' => 'required|exists:depositos,id', 'fecha' => 'required|date', 'notas' => 'nullable|string|max:200', 'conteos' => 'required|array']);
        $conteos = array_filter($d['conteos'], fn($v) => $v !== null && $v !== '');
        abort_if(! $conteos, 422, 'No cargaste ningún conteo.');
        $inv = $stock->cerrarInventario(Deposito::findOrFail($d['deposito_id']), $conteos, $d['fecha'], $d['notas'] ?? null);
        return back()->with('success', "Inventario {$inv->numeroFormateado()} cerrado: {$inv->items_contados} artículos contados, {$inv->items_con_diferencia} ajustados.");
    }

    public function verInventario(int $id)
    {
        $i = Inventario::with('deposito:id,nombre', 'user:id,name', 'items.product:id,name,sku,unit')->findOrFail($id);
        return response()->json(['numero' => $i->numeroFormateado(), 'fecha' => $i->fecha->format('d/m/Y'), 'deposito' => $i->deposito?->nombre, 'usuario' => $i->user?->name, 'notas' => $i->notas, 'valor' => (float) $i->diferencia_valorizada,
            'items' => $i->items->map(fn($it) => ['articulo' => $it->product?->name, 'sku' => $it->product?->sku, 'unit' => $it->product?->unit, 'sistema' => (float) $it->sistema, 'contado' => (float) $it->contado, 'diferencia' => (float) $it->diferencia, 'valor' => round((float) $it->diferencia * (float) $it->costo_unit, 2)])]);
    }

    public function guardarDeposito(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:80', 'business_location_id' => 'required|exists:business_locations,id', 'direccion' => 'nullable|string|max:150', 'es_default' => 'boolean', 'activo' => 'boolean']);
        $dep = $id ? Deposito::findOrFail($id) : new Deposito(['business_id' => $request->user()->business_id]);
        $dep->fill($d)->save();
        if ($d['es_default'] ?? false) Deposito::where('business_location_id', $d['business_location_id'])->where('id', '!=', $dep->id)->update(['es_default' => false]);
        AuditLog::registrar($id ? 'editar' : 'crear', $dep, "Depósito {$dep->nombre}");
        return back()->with('success', 'Depósito guardado.');
    }

    public function guardarRubro(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:80', 'parent_id' => 'nullable|exists:rubros,id', 'color' => 'nullable|string|max:10']);
        abort_if($id && ! empty($d['parent_id']) && in_array((int) $d['parent_id'], Rubro::conDescendientes($id), true), 422, 'Una categoría no puede colgar de una de sus subcategorías.');
        $r = $id ? Rubro::findOrFail($id) : new Rubro(['business_id' => $request->user()->business_id]);
        $r->fill($d)->save();
        return back()->with('success', 'Rubro guardado.');
    }

    public function eliminarRubro(int $id)
    {
        $r = Rubro::findOrFail($id);
        Product::where('rubro_id', $r->id)->update(['rubro_id' => $r->parent_id]);
        Rubro::where('parent_id', $r->id)->update(['parent_id' => $r->parent_id]);
        $r->delete();
        return back()->with('success', 'Rubro eliminado.');
    }

    // Actualización masiva de precios (inflación): % sobre lista 1 y las demás, o sobre costo.
    public function cotizacion(Request $request, \App\Services\Fondos\CotizacionService $s)
    {
        $d = $request->validate(['venta' => 'nullable|numeric|min:0', 'automatica' => 'boolean']);
        if ($d['automatica'] ?? false) {
            \App\Models\Cotizacion::where('business_id', $request->user()->business_id)->delete();
            $r = $s->actualizar();
            return back()->with(isset($r['error']) ? 'error' : 'success', isset($r['error']) ? 'No se pudo bajar la cotización: ' . $r['error'] : 'Cotización automática activada: dólar oficial $ ' . number_format($r['oficial'] ?? 0, 2, ',', '.'));
        }
        $s->fijarManual($request->user()->business_id, (float) $d['venta']);
        return back()->with('success', 'Cotización fijada en $ ' . number_format((float) $d['venta'], 2, ',', '.') . '.');
    }

    public function actualizarPrecios(Request $request)
    {
        $d = $request->validate(['porcentaje' => 'required|numeric|min:-90|max:500', 'campo' => 'required|in:price,cost,ambos', 'rubro_id' => 'nullable|exists:rubros,id', 'proveedor_id' => 'nullable|exists:contacts,id', 'redondeo' => 'nullable|in:0,1,10,100']);
        $factor = 1 + (float) $d['porcentaje'] / 100;
        $red = (int) ($d['redondeo'] ?? 0);
        $r = fn($v) => $red ? round($v / $red) * $red : round($v, 2);
        $q = Product::where('active', true)->when($d['rubro_id'] ?? null, fn($q, $x) => $q->whereIn('rubro_id', Rubro::conDescendientes((int) $x)))->when($d['proveedor_id'] ?? null, fn($q, $x) => $q->where('proveedor_id', $x));
        $n = 0;
        foreach ($q->get() as $p) {
            $upd = [];
            if (in_array($d['campo'], ['price', 'ambos'], true)) {
                $upd['price'] = $r((float) $p->price * $factor);
                $upd['prices'] = collect($p->prices ?? [])->map(fn($v) => $v !== null && $v !== '' ? $r((float) $v * $factor) : $v)->all() ?: null;
                $upd['precio_actualizado_en'] = now();
            }
            if (in_array($d['campo'], ['cost', 'ambos'], true)) $upd['cost'] = $r((float) $p->cost * $factor);
            $p->forceFill($upd)->save();
            $n++;
        }
        AuditLog::registrar('editar', null, "Actualización de precios {$d['porcentaje']}% ({$d['campo']}) sobre {$n} artículos");
        return back()->with('success', "Precios actualizados en {$n} artículos ({$d['porcentaje']}%).");
    }
}
