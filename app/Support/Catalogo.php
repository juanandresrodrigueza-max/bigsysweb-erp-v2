<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
            'cliente' => ['lista_precios' => $c->lista_precios, 'dias_pago' => $c->dias_pago, 'descuento' => (float) $c->descuento, 'balance' => (float) $c->balance, 'credit_limit' => (float) $c->credit_limit, 'tipo' => $c->tipoCliente?->nombre],
            'proveedor' => ['dias_pago' => $c->dias_pago, 'balance' => (float) $c->balance, 'email' => $c->email],
            default => [],
        };
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

    // Búsqueda incremental (hasta 30 filas) por nombre, código, barras o CUIT.
    public static function buscar(string $entidad, string $forma, string $q, array $ids = []): Collection
    {
        $like = Sql::like(); $t = '%' . trim($q) . '%';
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
