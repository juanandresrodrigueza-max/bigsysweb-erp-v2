<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Catálogos para los formularios (artículos, clientes, proveedores). Con pocos registros van completos en la página;
// con muchos (más de LIMITE) van solo los que el formulario ya usa y el resto se busca por /buscar/... a medida que se escribe.
class Catalogo
{
    public const LIMITE = 1500;

    public static function producto(Product $p, string $forma): array
    {
        $base = ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit, 'stock' => (float) $p->stock];
        return $base + match ($forma) {
            'venta' => ['iva' => (float) $p->iva, 'precios' => [1 => (float) $p->price, 2 => $p->precioLista(2), 3 => $p->precioLista(3), 4 => $p->precioLista(4), 5 => $p->precioLista(5), 6 => $p->precioLista(6)]],
            'compra' => ['iva' => (float) $p->iva, 'cost' => (float) $p->cost, 'perecedero' => (bool) $p->perecedero, 'seriado' => (bool) $p->seriado],
            'orden' => ['precio_compra' => (float) ($p->precio_compra ?: $p->cost), 'stock_min' => (float) $p->stock_min, 'proveedor_id' => $p->proveedor_id],
            'produccion' => ['tipo' => $p->tipo, 'cost' => (float) $p->cost],
            default => [],
        };
    }

    public static function contacto(Contact $c, string $forma): array
    {
        $base = ['id' => $c->id, 'name' => $c->name, 'cuit' => $c->cuit, 'condicion_iva' => $c->condicion_iva];
        return $base + match ($forma) {
            'cliente' => ['lista_precios' => $c->lista_precios, 'dias_pago' => $c->dias_pago, 'descuento' => (float) $c->descuento, 'balance' => (float) $c->balance, 'credit_limit' => (float) $c->credit_limit, 'tipo' => $c->tipoCliente?->nombre, 'address' => $c->address, 'city' => $c->city, 'percepcion_iibb' => (bool) $c->percepcion_iibb, 'percepcion_iva' => (bool) $c->percepcion_iva, 'percepcion_ganancias' => (bool) $c->percepcion_ganancias, 'pais_codigo' => $c->pais_codigo],
            'proveedor' => ['dias_pago' => $c->dias_pago, 'balance' => (float) $c->balance, 'email' => $c->email],
            default => [],
        };
    }

    public static function sugeridos(string $entidad, string $forma, ?int $contactId = null): Collection
    {
        $desde = now()->subDays(90)->toDateString();
        if ($entidad === 'articulos') {
            $ventas = fn() => DB::table('comprobante_items')->join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', Auth::user()?->business_id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereNotNull('comprobante_items.product_id');
            $ids = [];
            if ($contactId) $ids = $ventas()->where('comprobantes.contact_id', $contactId)->selectRaw('comprobante_items.product_id, MAX(comprobantes.fecha) as f')->groupBy('comprobante_items.product_id')->orderByDesc('f')->limit(12)->pluck('product_id')->all();
            $top = $ventas()->where('comprobantes.fecha', '>=', $desde)->selectRaw('comprobante_items.product_id, SUM(comprobante_items.cantidad) as c')->groupBy('comprobante_items.product_id')->orderByDesc('c')->limit(20)->pluck('product_id')->all();
            $orden = array_values(array_unique(array_merge($ids, $top)));
            $rows = self::queryProductos($forma)->when($orden, fn($b) => $b->whereIn('id', $orden), fn($b) => $b->orderBy('name')->limit(20))->get()->map(fn($p) => self::producto($p, $forma) + ['sugerido' => in_array($p->id, $ids, true) ? 'cliente' : 'top']);
            $pos = array_flip($orden);
            return $rows->sortBy(fn($r) => $pos[$r['id']] ?? 999)->values();
        }
        $recientes = DB::table('comprobantes')->where('business_id', Auth::user()?->business_id)->where('direccion', $forma === 'cliente' ? 'venta' : 'compra')->where('estado', 'emitido')->whereNotNull('contact_id')->selectRaw('contact_id, MAX(fecha) as f, MAX(id) as ult')->groupBy('contact_id')->orderByDesc('f')->orderByDesc('ult')->limit(15)->pluck('contact_id')->all();
        $rows = self::queryContactos($forma)->when($recientes, fn($b) => $b->whereIn('id', $recientes), fn($b) => $b->orderBy('name')->limit(20))->get()->map(fn($c) => self::contacto($c, $forma) + ['sugerido' => 'reciente']);
        $pos = array_flip($recientes);
        return $rows->sortBy(fn($r) => $pos[$r['id']] ?? 999)->values();
    }

    // Buscador global (Ctrl+K): clientes, proveedores, artículos y comprobantes, hasta 5 de cada uno.
    public static function global(string $q): array
    {
        $like = Sql::like(); $t = '%' . trim($q) . '%'; $num = (int) preg_replace('/\D/', '', $q);
        if (trim($q) === '') return ['clientes' => [], 'proveedores' => [], 'articulos' => [], 'comprobantes' => []];
        return [
            'clientes' => Contact::customers()->where('is_active', true)->where(fn($w) => $w->where('name', $like, $t)->orWhere('cuit', $like, $t))->orderBy('name')->limit(5)->get()->map(fn($c) => ['id' => $c->id, 'titulo' => $c->name, 'sub' => trim(($c->cuit ?? '') . ' · ' . $c->condicion_iva, ' ·'), 'url' => "/clientes/{$c->id}"])->values(),
            'proveedores' => Contact::suppliers()->where('is_active', true)->where(fn($w) => $w->where('name', $like, $t)->orWhere('cuit', $like, $t))->orderBy('name')->limit(5)->get()->map(fn($c) => ['id' => $c->id, 'titulo' => $c->name, 'sub' => $c->cuit, 'url' => "/proveedores/{$c->id}"])->values(),
            'articulos' => Product::where('active', true)->where(fn($w) => $w->where('name', $like, $t)->orWhere('sku', $like, $t)->orWhere('barcode', $like, $t))->orderBy('name')->limit(5)->get()->map(fn($p) => ['id' => $p->id, 'titulo' => $p->name, 'sub' => $p->sku . ' · stock ' . rtrim(rtrim(number_format((float) $p->stock, 3, ',', '.'), '0'), ',') . ' · $ ' . number_format((float) $p->price, 2, ',', '.'), 'url' => "/stock/{$p->id}"])->values(),
            'comprobantes' => \App\Models\Comprobante::ventas()->where('estado', '!=', 'borrador')->when($num > 0, fn($b) => $b->where('numero', $num), fn($b) => $b->whereHas('contact', fn($c) => $c->where('name', $like, $t)))->with('contact:id,name')->orderByDesc('fecha')->orderByDesc('id')->limit(5)->get()->map(fn($c) => ['id' => $c->id, 'titulo' => $c->nombreTipo() . ' ' . ($c->numeroFormateado() ?? '(pendiente)'), 'sub' => ($c->contact?->name ?? 'Consumidor final') . ' · ' . $c->fecha->format('d/m/Y') . ' · $ ' . number_format((float) $c->total, 2, ',', '.'), 'url' => "/comprobantes/{$c->id}"])->values(),
        ];
    }

    private static function queryProductos(string $forma): Builder
    {
        return Product::where('active', true)->when($forma === 'orden', fn($q) => $q->whereIn('tipo', ['producto', 'insumo']));
    }

    private static function queryContactos(string $forma): Builder
    {
        return ($forma === 'cliente' ? Contact::customers()->with('tipoCliente:id,nombre') : Contact::suppliers())->where('is_active', true);
    }

    // Lista para la página: completa si es chica; si no, solo los ids que el formulario ya usa. Devuelve [filas, parcial].
    public static function productos(string $forma, array $idsEnUso = []): array
    {
        $q = self::queryProductos($forma);
        $parcial = $q->clone()->count() > self::LIMITE;
        $rows = ($parcial ? $q->whereIn('id', array_filter($idsEnUso)) : $q)->orderBy('name')->get()->map(fn($p) => self::producto($p, $forma))->values();
        return [$rows, $parcial];
    }

    public static function contactos(string $forma, array $idsEnUso = []): array
    {
        $q = self::queryContactos($forma);
        $parcial = $q->clone()->count() > self::LIMITE;
        $rows = ($parcial ? $q->whereIn('id', array_filter($idsEnUso)) : $q)->orderBy('name')->get()->map(fn($c) => self::contacto($c, $forma))->values();
        return [$rows, $parcial];
    }

    // Búsqueda incremental (hasta 30 filas) por nombre, código, barras o CUIT. Sin texto devuelve sugeridos:
    // artículos que el cliente compró últimamente y los más vendidos; clientes facturados recientemente.
    public static function buscar(string $entidad, string $forma, string $q, array $ids = [], ?int $contactId = null): Collection
    {
        $like = Sql::like(); $t = '%' . trim($q) . '%';
        if (trim($q) === '' && ! $ids) return self::sugeridos($entidad, $forma, $contactId);
        if ($entidad === 'articulos') {
            return self::queryProductos($forma)->when($ids, fn($b) => $b->whereIn('id', $ids))
                ->when(trim($q) !== '', fn($b) => $b->where(fn($w) => $w->where('name', $like, $t)->orWhere('sku', $like, $t)->orWhere('barcode', $like, $t)))
                ->orderBy('name')->limit(30)->get()->map(fn($p) => self::producto($p, $forma))->values();
        }
        return self::queryContactos($forma)->when($ids, fn($b) => $b->whereIn('id', $ids))
            ->when(trim($q) !== '', fn($b) => $b->where(fn($w) => $w->where('name', $like, $t)->orWhere('cuit', $like, $t)))
            ->orderBy('name')->limit(30)->get()->map(fn($c) => self::contacto($c, $forma))->values();
    }
}
