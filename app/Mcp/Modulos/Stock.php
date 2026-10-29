<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Deposito;
use App\Models\Lote;
use App\Models\Product;
use App\Models\User;
use App\Services\Stock\AlmacenService;
use App\Services\Stock\InformesStockService;
use App\Services\Stock\LotesService;
use App\Services\Stock\StockService;

// Stock: artículos, existencias, reposición, lotes, series y ubicaciones; alta, precios, ajustes y bloqueo de lotes.
class Stock
{
    public static function herramientas(): array
    {
        return [
            ['nombre' => 'buscar_articulos', 'titulo' => 'Buscar artículos', 'modulo' => 'stock', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Busca artículos por nombre, código o código de barras. Devuelve precio, costo, stock y mínimo.',
                'parametros' => ['texto' => ['type' => 'string'], 'cantidad' => ['type' => 'integer']], 'requeridos' => ['texto'],
                'ejecutar' => function (User $u, array $a) {
                    $t = trim((string) $a['texto']); $like = \App\Support\Sql::like();
                    return ['articulos' => Product::where('active', true)->where(fn($w) => $w->where('name', $like, "%{$t}%")->orWhere('sku', $like, "%{$t}%")->orWhere('barcode', $t))
                        ->orderByRaw('LENGTH(name)')->limit(min(50, (int) ($a['cantidad'] ?? 10)))->get()->map(fn($p) => self::fila($p))->all()];
                }],
            ['nombre' => 'stock_articulo', 'titulo' => 'Stock de un artículo', 'modulo' => 'stock', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Stock de un artículo por depósito, sus lotes con vencimiento y en qué ubicaciones del almacén está.',
                'parametros' => ['articulo' => ['type' => 'string', 'description' => 'Nombre, código o id.']], 'requeridos' => ['articulo'],
                'ejecutar' => function (User $u, array $a) {
                    $p = Busca::articulo($a['articulo']); if (! $p) return ['error' => "No encuentro el artículo \"{$a['articulo']}\"."];
                    return self::fila($p) + [
                        'por_deposito' => Deposito::where('activo', true)->get()->map(fn($d) => ['deposito' => $d->nombre, 'stock' => $p->stockEn($d->id)])->filter(fn($x) => $x['stock'] != 0)->values()->all(),
                        'lotes' => Lote::where('product_id', $p->id)->where('cantidad', '>', 0)->orderBy('vencimiento')->limit(30)->get()->map(fn($l) => ['lote' => $l->lote, 'serie' => $l->serie, 'vence' => $l->vencimiento?->toDateString(), 'cantidad' => (float) $l->cantidad, 'estado' => $l->estado])->all(),
                        'ubicaciones' => rescue(fn() => app(AlmacenService::class)->dondeEsta($p), [], false),
                    ];
                }],
            ['nombre' => 'que_reponer', 'titulo' => 'Qué reponer', 'modulo' => 'stock', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Artículos que hay que reponer: bajo mínimo o que no llegan a cubrir los próximos días según lo que se vende. Incluye proveedor, cantidad sugerida y costo.',
                'parametros' => ['dias_cobertura' => ['type' => 'integer', 'description' => 'Días a cubrir (30 por defecto).'], 'proveedor' => ['type' => 'string']],
                'ejecutar' => function (User $u, array $a) {
                    $r = app(InformesStockService::class)->faltantes(max(1, (int) ($a['dias_cobertura'] ?? 30)));
                    $filas = collect($r['filas']);
                    if (! empty($a['proveedor'])) { $pr = Busca::proveedor($a['proveedor']); $filas = $filas->where('proveedor_id', $pr?->id); }
                    return ['articulos' => $filas->take(60)->values()->all(), 'cantidad' => $filas->count(), 'costo_total' => round((float) $filas->sum('costo'), 2)];
                }],
            ['nombre' => 'lotes_por_vencer', 'titulo' => 'Lotes por vencer', 'modulo' => 'stock', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Lotes con stock que vencen en los próximos días (y los ya vencidos).',
                'parametros' => ['dias' => ['type' => 'integer']],
                'ejecutar' => fn(User $u, array $a) => ['lotes' => collect(app(LotesService::class)->porVencer((int) ($a['dias'] ?? 30)))->take(100)->map(fn($l) => ['id' => $l->id, 'articulo' => $l->product?->name, 'lote' => $l->lote, 'vence' => $l->vencimiento?->toDateString(), 'cantidad' => (float) $l->cantidad, 'estado' => $l->estado])->values()->all()]],
            ['nombre' => 'buscar_serie', 'titulo' => 'Buscar número de serie', 'modulo' => 'stock', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Historia de un número de serie: proveedor, a quién se vendió, garantía y services.',
                'parametros' => ['serie' => ['type' => 'string']], 'requeridos' => ['serie'],
                'ejecutar' => function (User $u, array $a) {
                    $l = Lote::where('serie', trim((string) $a['serie']))->first(); if (! $l) return ['error' => 'No encuentro esa serie.'];
                    return ['serie' => $l->serie, 'articulo' => $l->product?->name, 'estado' => $l->estado, 'cliente' => $l->cliente?->name, 'vendido_en' => $l->vendido_en?->toDateString(), 'garantia_hasta' => $l->garantia_hasta?->toDateString(), 'en_garantia' => $l->garantia_hasta?->gte(today()) ?? false, 'url' => Busca::url('/stock/series?buscar=' . urlencode($l->serie))];
                }],
            ['nombre' => 'crear_articulo', 'titulo' => 'Crear un artículo', 'modulo' => 'stock', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Da de alta un artículo nuevo con precio y costo. Si ya hay uno con ese código o nombre, avisa.',
                'parametros' => ['nombre' => ['type' => 'string'], 'codigo' => ['type' => 'string'], 'precio' => ['type' => 'number'], 'costo' => ['type' => 'number'], 'iva' => ['type' => 'number', 'description' => '21, 10.5, 27 o 0'], 'unidad' => ['type' => 'string'], 'stock_minimo' => ['type' => 'number']],
                'requeridos' => ['nombre', 'precio'],
                'proponer' => function (User $u, array $a) {
                    if (($ya = Busca::articulo($a['codigo'] ?? '')) || ($ya = Product::where('name', $a['nombre'])->first())) return ['error' => "Ya existe {$ya->name} (código {$ya->sku})."];
                    app(\App\Services\Suscripciones\LimitesPlanService::class)->verificarArticulo($u->business);
                    $sku = $a['codigo'] ?? ('IA' . str_pad((string) ((int) Product::max('id') + 1), 6, '0', STR_PAD_LEFT));
                    return ['titulo' => "Crear el artículo {$a['nombre']}", 'detalle' => "Código {$sku} · precio " . Busca::pesos($a['precio']) . (isset($a['costo']) ? ' · costo ' . Busca::pesos($a['costo']) : '') . ' · IVA ' . ($a['iva'] ?? 21) . '%',
                        'datos' => ['name' => $a['nombre'], 'sku' => $sku, 'price' => (float) $a['precio'], 'cost' => (float) ($a['costo'] ?? 0), 'iva' => (float) ($a['iva'] ?? 21), 'unit' => $a['unidad'] ?? 'un', 'stock_min' => (float) ($a['stock_minimo'] ?? 0)]];
                },
                'confirmar' => function (User $u, array $d) {
                    $p = Product::create($d + ['business_id' => $u->business_id, 'active' => true, 'controla_stock' => true, 'tipo' => 'producto', 'stock' => 0]);
                    return ['texto' => "Artículo {$p->name} creado.", 'articulo_id' => $p->id, 'url' => Busca::url("/stock?buscar=" . urlencode((string) $p->sku))];
                }],
            ['nombre' => 'cambiar_precio', 'titulo' => 'Cambiar un precio', 'modulo' => 'stock', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Cambia el precio de venta de un artículo (lista 1 por defecto), por importe nuevo o por porcentaje de aumento.',
                'parametros' => ['articulo' => ['type' => 'string'], 'precio' => ['type' => 'number'], 'porcentaje' => ['type' => 'number', 'description' => 'Aumento (o baja si es negativo) en %.'], 'lista' => ['type' => 'integer']],
                'requeridos' => ['articulo'],
                'proponer' => function (User $u, array $a) {
                    $p = Busca::articulo($a['articulo']); if (! $p) return ['error' => "No encuentro el artículo \"{$a['articulo']}\"."];
                    $lista = max(1, min(6, (int) ($a['lista'] ?? 1))); $actual = $p->precioLista($lista);
                    $nuevo = isset($a['precio']) ? (float) $a['precio'] : (isset($a['porcentaje']) ? round($actual * (1 + (float) $a['porcentaje'] / 100), 2) : null);
                    if (! $nuevo || $nuevo <= 0) return ['error' => 'Decime el precio nuevo o el porcentaje.'];
                    return ['titulo' => "{$p->name}: lista {$lista} de " . Busca::pesos($actual) . ' a ' . Busca::pesos($nuevo), 'detalle' => $p->cost > 0 ? 'Margen sobre costo: ' . round(($nuevo / (float) $p->cost - 1) * 100, 1) . '%' : null, 'datos' => ['id' => $p->id, 'lista' => $lista, 'precio' => $nuevo]];
                },
                'confirmar' => function (User $u, array $d) {
                    $p = Product::findOrFail($d['id']);
                    if ($d['lista'] <= 1) $p->price = $d['precio']; else { $pr = $p->prices ?? []; $pr[(string) $d['lista']] = $d['precio']; $p->prices = $pr; }
                    $p->precio_actualizado_en = now(); $p->save();
                    \App\Models\AuditLog::registrar('editar', $p, "Precio lista {$d['lista']} de {$p->name} a " . Busca::pesos($d['precio']) . ' (IA)');
                    return ['texto' => "Precio actualizado: {$p->name} " . Busca::pesos($d['precio']) . '.'];
                }],
            // Fase 34.6: "subime 15% el rubro bebidas" → propone con la cantidad de artículos y una muestra, y aplica con el asistente (queda en el historial, se puede deshacer).
            ['nombre' => 'precios_en_masa', 'titulo' => 'Cambiar precios de un grupo', 'modulo' => 'stock', 'permiso' => 'editar', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Sube o baja un porcentaje (o fija un margen sobre costo) a todos los artículos de un rubro, un proveedor, una marca o los que dicen cierto texto. Todo o nada, con deshacer.',
                'parametros' => ['porcentaje' => ['type' => 'number', 'description' => 'Aumento (negativo para bajar) en %.'], 'margen' => ['type' => 'number', 'description' => 'En vez de porcentaje: margen % sobre el costo.'], 'rubro' => ['type' => 'string'], 'proveedor' => ['type' => 'string'], 'marca' => ['type' => 'string'], 'texto' => ['type' => 'string'], 'lista' => ['type' => 'integer', 'description' => '1 a 6, o 0 para el costo'], 'redondeo' => ['type' => 'integer', 'description' => '0, 1, 10 o 100']],
                'requeridos' => [],
                'proponer' => function (User $u, array $a) {
                    $sel = [];
                    if (! empty($a['rubro'])) { $r = \App\Models\Rubro::where('nombre', \App\Support\Sql::like(), '%' . $a['rubro'] . '%')->first(); if (! $r) return ['error' => "No encuentro el rubro \"{$a['rubro']}\"."]; $sel['rubro_id'] = $r->id; }
                    if (! empty($a['proveedor'])) { $c = \App\Models\Contact::suppliers()->where('name', \App\Support\Sql::like(), '%' . $a['proveedor'] . '%')->first(); if (! $c) return ['error' => "No encuentro el proveedor \"{$a['proveedor']}\"."]; $sel['proveedor_id'] = $c->id; }
                    if (! empty($a['marca'])) $sel['marca'] = $a['marca'];
                    if (! empty($a['texto'])) $sel['texto'] = $a['texto'];
                    if (! $sel) return ['error' => 'Decime a qué grupo: un rubro, un proveedor, una marca o un texto. Para todo el catálogo usá la pantalla Stock → Precios.'];
                    if (! isset($a['porcentaje']) && ! isset($a['margen'])) return ['error' => 'Decime el porcentaje de aumento (o el margen sobre costo).'];
                    $lista = (int) ($a['lista'] ?? 1);
                    $como = ['campo' => $lista <= 0 ? 'cost' : (string) min(6, $lista), 'modo' => isset($a['margen']) ? 'margen' : 'porcentaje', 'valor' => (float) ($a['margen'] ?? $a['porcentaje']), 'redondeo' => (int) ($a['redondeo'] ?? 0)];
                    $prev = app(\App\Services\Stock\PreciosMasivosService::class)->previsualizar($sel, $como, 5);
                    if (! $prev['n']) return ['error' => 'Con eso no cambia ningún artículo.'];
                    $muestra = collect($prev['filas'])->map(fn($f) => "{$f['nombre']}: " . Busca::pesos($f['antes']) . ' → ' . Busca::pesos($f['despues']))->implode(' · ');
                    $aviso = $prev['avisos']['bajo_costo'] ? " ¡Ojo! {$prev['avisos']['bajo_costo']} quedarían bajo el costo." : '';
                    return ['titulo' => ($como['modo'] === 'margen' ? "Poner {$como['valor']}% de margen" : ($como['valor'] >= 0 ? 'Subir ' : 'Bajar ') . abs($como['valor']) . '%') . ' a ' . $prev['n'] . ' artículos (' . ($como['campo'] === 'cost' ? 'costo' : "lista {$como['campo']}") . ')', 'detalle' => "Por ejemplo: {$muestra}.{$aviso} Queda en el historial y se puede deshacer.", 'datos' => ['sel' => $sel, 'como' => $como]];
                },
                'confirmar' => function (User $u, array $d) {
                    $log = app(\App\Services\Stock\PreciosMasivosService::class)->aplicar($d['sel'], $d['como']);
                    return ['texto' => $log->descripcion . '. Si te arrepentís: Stock → Precios → Historial → Deshacer.'];
                }],
            ['nombre' => 'ajustar_stock', 'titulo' => 'Ajustar stock', 'modulo' => 'stock', 'permiso' => 'editar', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Corrige el stock de un artículo a la cantidad contada, en el depósito principal de la sucursal (o el indicado).',
                'parametros' => ['articulo' => ['type' => 'string'], 'cantidad' => ['type' => 'number', 'description' => 'Stock real contado.'], 'motivo' => ['type' => 'string'], 'deposito' => ['type' => 'string']],
                'requeridos' => ['articulo', 'cantidad'],
                'proponer' => function (User $u, array $a) {
                    $p = Busca::articulo($a['articulo']); if (! $p) return ['error' => "No encuentro el artículo \"{$a['articulo']}\"."];
                    $d = ! empty($a['deposito']) ? Deposito::where('nombre', \App\Support\Sql::like(), "%{$a['deposito']}%")->first() : Deposito::porDefecto($u->current_location_id);
                    if (! $d) return ['error' => 'No encuentro el depósito.'];
                    $actual = $p->stockEn($d->id);
                    return ['titulo' => "Ajustar {$p->name} en {$d->nombre}: {$actual} → {$a['cantidad']} {$p->unit}", 'detalle' => 'Motivo: ' . ($a['motivo'] ?? 'Recuento'), 'datos' => ['id' => $p->id, 'deposito_id' => $d->id, 'cantidad' => (float) $a['cantidad'], 'motivo' => $a['motivo'] ?? 'Recuento']];
                },
                'confirmar' => function (User $u, array $d) {
                    $p = Product::findOrFail($d['id']);
                    app(StockService::class)->ajustar($p, Deposito::findOrFail($d['deposito_id']), $d['cantidad'], $d['motivo'] . ' (IA)');
                    return ['texto' => "Stock de {$p->name} ajustado a {$d['cantidad']}."];
                }],
            ['nombre' => 'bloquear_lote', 'titulo' => 'Bloquear un lote', 'modulo' => 'stock', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Bloquea un lote para que no se venda (retiro, control de calidad) o lo vuelve a habilitar.',
                'parametros' => ['articulo' => ['type' => 'string'], 'lote' => ['type' => 'string'], 'motivo' => ['type' => 'string'], 'desbloquear' => ['type' => 'boolean']],
                'requeridos' => ['lote'],
                'proponer' => function (User $u, array $a) {
                    $p = ! empty($a['articulo']) ? Busca::articulo($a['articulo']) : null;
                    $l = Lote::where('lote', trim((string) $a['lote']))->when($p, fn($q) => $q->where('product_id', $p->id))->first();
                    if (! $l) return ['error' => 'No encuentro ese lote.'];
                    $des = ! empty($a['desbloquear']);
                    return ['titulo' => ($des ? 'Habilitar' : 'Bloquear') . " el lote {$l->lote} de {$l->product?->name}", 'detalle' => (float) $l->cantidad . ' unidades' . (! empty($a['motivo']) ? " · {$a['motivo']}" : ''), 'datos' => ['id' => $l->id, 'estado' => $des ? 'disponible' : 'bloqueado', 'motivo' => $a['motivo'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $l = Lote::findOrFail($d['id']); $l->update(['estado' => $d['estado'], 'motivo' => $d['motivo']]);
                    return ['texto' => "Lote {$l->lote} " . ($d['estado'] === 'bloqueado' ? 'bloqueado' : 'habilitado') . '.'];
                }],
        ];
    }

    public static function fila(Product $p): array
    {
        return ['id' => $p->id, 'nombre' => $p->name, 'codigo' => $p->sku, 'precio' => (float) $p->price, 'costo' => (float) $p->cost, 'iva' => (float) $p->iva, 'stock' => (float) $p->stock, 'minimo' => (float) $p->stock_min, 'unidad' => $p->unit, 'url' => Busca::url("/stock?buscar=" . urlencode((string) $p->sku))];
    }
}
