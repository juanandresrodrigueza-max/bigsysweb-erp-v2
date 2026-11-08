<?php

namespace App\Services\Gastronomia;

use App\Models\Business;
use App\Models\Product;
use Carbon\Carbon;

// Fase 52: happy hour (descuento por franja, días y rubros) y platos con horario (menú del día, ejecutivo al mediodía).
class HorariosService
{
    // ¿La franja {dias:[1..7], desde, hasta} vale ahora? Días ISO (1 = lunes). Una franja que cruza la medianoche (22 a 2) también vale.
    public static function enFranja(array $f, ?Carbon $ahora = null): bool
    {
        $ahora = $ahora ?? now();
        $dias = array_map('intval', $f['dias'] ?? []);
        $h = $ahora->format('H:i'); $d = $f['desde'] ?? '00:00'; $a = $f['hasta'] ?? '23:59';
        $dentro = $d <= $a ? ($h >= $d && $h < $a) : ($h >= $d || $h < $a);
        $dia = $d > $a && $h < $a ? $ahora->copy()->subDay()->isoWeekday() : $ahora->isoWeekday();
        return $dentro && (! $dias || in_array($dia, $dias, true));
    }

    // El plato está disponible ahora (sin horario cargado, siempre).
    public static function disponible(Product $p, ?Carbon $ahora = null): bool
    {
        return empty($p->horario) || self::enFranja($p->horario, $ahora);
    }

    // Happy hour que aplica a este plato ahora: ['nombre' => ..., 'pct' => ...] o null. Si hay varias, la de mayor descuento.
    public static function happyHour(Business $b, Product $p, ?Carbon $ahora = null): ?array
    {
        $hh = collect($b->gastronomia_config['happy_hours'] ?? [])->filter(fn($r) => ! empty($r['activo']) && self::enFranja($r, $ahora) && (empty($r['rubros']) || in_array($p->rubro_id, array_map('intval', $r['rubros']), true)));
        $r = $hh->sortByDesc('pct')->first();
        return $r ? ['nombre' => $r['nombre'], 'pct' => (float) $r['pct']] : null;
    }

    public static function aplicar(float $precio, ?array $hh): float
    {
        return $hh ? round($precio * (1 - $hh['pct'] / 100), 2) : $precio;
    }
}
