<?php

namespace App\Services\Agentes;

use App\Models\Business;
use App\Models\PrecioHistorial;
use App\Models\Product;
use App\Models\Propuesta;
use App\Models\User;
use App\Services\Estadisticas\VigilanciaService;

// Fase 107 · Precios con criterio: cuando sube el costo de un artículo (o quedó por debajo de su margen mínimo), propone el precio
// nuevo respetando el margen que corresponde: el objetivo del artículo, si no el margen que tenía antes de la suba, si no el mínimo
// (artículo → rubro → general). Los artículos con cadena de márgenes cargada ya se recalculan solos: acá entran los demás.
class PreciosAgente implements Agente
{
    public const MAX = 60;

    public function __construct(private VigilanciaService $vigilancia) {}

    public function nombre(): string { return 'precios'; }

    public static function redondear(float $p): float
    {
        $paso = $p < 1000 ? 10 : ($p < 10000 ? 50 : 100);
        return (float) (ceil($p / $paso) * $paso);
    }

    public function proponer(Business $b): array
    {
        $general = $this->vigilancia->minimoGeneral($b);
        // 1. Subió el costo en los últimos 3 días y el precio no se tocó.
        $subas = PrecioHistorial::withoutGlobalScopes()->where('business_id', $b->id)->where('fecha', '>=', today()->subDays(3)->toDateString())->whereColumn('costo', '>', 'costo_antes')->orderBy('id')->get()->groupBy('product_id');
        $filas = []; $vistos = [];
        foreach ($subas as $pid => $hist) {
            $p = Product::withoutGlobalScopes()->with('rubro:id,nombre,margen_minimo')->find($pid);
            if (! $p || ! $p->active || $p->moneda === 'USD' || collect($p->margenes ?? [])->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty()) continue;
            $primero = $hist->first(); $costoAntes = (float) $primero->costo_antes; $precioAntes = (float) $primero->precio_antes;
            $costo = $p->costoPesos(); $precio = $p->precioLista(1);
            if ($costo <= 0 || $precio > $precioAntes * 1.005) continue; // ya lo actualizaron
            $margen = $p->margen_objetivo !== null ? (float) $p->margen_objetivo : ($costoAntes > 0 && $precioAntes > 0 ? PrecioHistorial::margen($precioAntes, $costoAntes) : $p->margenMinimo($general));
            if ($margen === null) continue;
            $nuevo = self::redondear($costo * (1 + $margen / 100));
            if ($nuevo <= $precio) continue;
            $vistos[$pid] = true;
            $filas[] = ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'costo_antes' => $costoAntes, 'costo' => $costo, 'precio' => $precio, 'precio_nuevo' => $nuevo, 'margen' => round($margen, 1), 'margen_hoy' => $p->margenSobreCosto(1), 'motivo' => 'subió el costo ' . round(($costo - $costoAntes) / max($costoAntes, 0.01) * 100) . '%'];
        }
        $out = [];
        if ($filas) {
            $filas = array_slice($filas, 0, self::MAX);
            $out[] = ['clave' => 'costos-' . today()->toDateString(), 'prioridad' => 1, 'importe' => round(array_sum(array_map(fn($f) => $f['precio_nuevo'] - $f['precio'], $filas)), 2),
                'titulo' => count($filas) . ' precio(s) para actualizar porque subió el costo', 'detalle' => 'Respetando el margen de cada uno (objetivo del artículo, el que tenía, o el mínimo) y redondeado. Aprobar cambia la lista 1; las demás listas siguen como estén.',
                'datos' => ['items' => $filas], 'accion' => ['tipo' => 'precios', 'items' => array_map(fn($f) => ['id' => $f['id'], 'precio' => $f['precio_nuevo']], $filas)]];
        }
        // 2. Por debajo del margen mínimo (semáforo rojo de Rentabilidad), sin suba reciente: propone llevarlo al mínimo.
        $rojos = array_filter($this->vigilancia->margenes($b, 1, 'rojo')['items'] ?? [], fn($i) => ! isset($vistos[$i['id']]));
        $bajo = [];
        foreach (array_slice($rojos, 0, self::MAX) as $i) {
            $nuevo = self::redondear($i['costo'] * (1 + $i['minimo'] / 100)); if ($nuevo <= $i['precio']) continue;
            $bajo[] = ['id' => $i['id'], 'nombre' => $i['nombre'], 'sku' => $i['sku'], 'costo' => $i['costo'], 'precio' => $i['precio'], 'precio_nuevo' => $nuevo, 'margen' => $i['minimo'], 'margen_hoy' => $i['margen'], 'motivo' => "margen {$i['margen']}% bajo el mínimo {$i['minimo']}%"];
        }
        if ($bajo) $out[] = ['clave' => 'bajo-minimo', 'prioridad' => 2, 'importe' => round(array_sum(array_map(fn($f) => $f['precio_nuevo'] - $f['precio'], $bajo)), 2),
            'titulo' => count($bajo) . ' artículo(s) por debajo de su margen mínimo', 'detalle' => 'Aprobar los lleva al mínimo que definiste en Estadísticas → Rentabilidad. Si preferís otro precio, rechazá y cambialo a mano.',
            'datos' => ['items' => $bajo], 'accion' => ['tipo' => 'precios', 'items' => array_map(fn($f) => ['id' => $f['id'], 'precio' => $f['precio_nuevo']], $bajo)]];
        return $out;
    }

    public function ejecutar(Propuesta $p, User $u): string
    {
        $n = 0;
        foreach ($p->accion['items'] as $i) {
            $prod = Product::find($i['id']); if (! $prod) continue;
            $prod->price = $i['precio']; $prod->precio_actualizado_en = now(); $prod->save(); $n++;
        }
        return "{$n} precio(s) actualizados en la lista 1. Cada cambio quedó en el historial de precios.";
    }
}
