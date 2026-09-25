<?php

namespace App\Services\Ventas;

use App\Models\DescuentoLista;
use App\Models\Product;
use App\Models\Rubro;

// Descuentos especiales por lista: el del artículo gana al del rubro; entre los que alcanzan la cantidad, el de mayor mínimo.
class DescuentosListaService
{
    // Reglas vigentes de una lista en el formato que usa el formulario de venta: por artículo y por rubro (con sus subrubros).
    public function paraLista(int $lista, ?string $fecha = null): array
    {
        $reglas = DescuentoLista::vigentes($fecha)->where('lista', $lista)->orderBy('cantidad_minima')->get();
        $fila = fn($r) => ['min' => (float) $r->cantidad_minima, 'precio' => $r->precio !== null ? (float) $r->precio : null, 'descuento' => $r->descuento !== null ? (float) $r->descuento : null];
        $rubros = [];
        foreach ($reglas->whereNotNull('rubro_id')->whereNull('product_id')->sortBy(fn($r) => $r->rubro?->nivel() ?? 0) as $r)
            foreach (Rubro::conDescendientes($r->rubro_id) as $id) $rubros[$id][] = $fila($r);
        return [
            'articulos' => $reglas->whereNotNull('product_id')->groupBy('product_id')->map(fn($g) => $g->map($fila)->values()->all())->all(),
            'rubros' => $rubros,
        ];
    }

    // La regla que corresponde a un artículo y una cantidad, o null.
    public function mejor(int $lista, Product $p, float $cantidad, ?array $reglas = null): ?array
    {
        $reglas ??= $this->paraLista($lista);
        foreach ([$reglas['articulos'][$p->id] ?? [], $p->rubro_id ? ($reglas['rubros'][$p->rubro_id] ?? []) : []] as $grupo) {
            $ok = collect($grupo)->filter(fn($r) => $cantidad + 1e-9 >= $r['min'])->sortByDesc('min')->first();
            if ($ok) return $ok;
        }
        return null;
    }
}
