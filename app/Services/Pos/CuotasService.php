<?php

namespace App\Services\Pos;

use App\Models\Business;

// Planes de cuotas con tarjeta: por empresa se configuran las tarjetas y el recargo (o descuento) de cada cantidad de cuotas.
// El recargo se agrega como ítem del ticket para que la factura cierre con lo que paga el cliente.
class CuotasService
{
    public const DEFAULT = [
        ['nombre' => 'Visa / Mastercard', 'planes' => [['cuotas' => 1, 'recargo' => 0], ['cuotas' => 3, 'recargo' => 10], ['cuotas' => 6, 'recargo' => 20], ['cuotas' => 12, 'recargo' => 40]]],
        ['nombre' => 'Naranja', 'planes' => [['cuotas' => 1, 'recargo' => 0], ['cuotas' => 3, 'recargo' => 12], ['cuotas' => 6, 'recargo' => 22]]],
    ];

    public function planes(Business $b): array
    {
        $t = $b->tarjetas;
        if (! is_array($t) || ! $t) return self::DEFAULT;
        return array_values(array_map(fn($x) => ['nombre' => (string) ($x['nombre'] ?? 'Tarjeta'), 'planes' => array_values(array_map(fn($p) => ['cuotas' => max(1, (int) ($p['cuotas'] ?? 1)), 'recargo' => round((float) ($p['recargo'] ?? 0), 2)], $x['planes'] ?? []))], $t));
    }

    // % de recargo (negativo = descuento) para esa tarjeta y cantidad de cuotas.
    public function recargo(Business $b, ?string $tarjeta, int $cuotas): float
    {
        if ($cuotas <= 1 && ! $tarjeta) return 0;
        foreach ($this->planes($b) as $t) {
            if ($tarjeta && mb_strtolower($t['nombre']) !== mb_strtolower($tarjeta)) continue;
            foreach ($t['planes'] as $p) if ((int) $p['cuotas'] === $cuotas) return (float) $p['recargo'];
        }
        return 0;
    }

    // Aplica el recargo a los medios "tarjeta" con cuotas: devuelve [medios ajustados, ítems de recargo a agregar].
    // $monto del medio es lo que paga el cliente (con recargo incluido); el ítem de recargo es la diferencia con la base.
    // El precio del ítem es el importe final (con IVA incluido si la venta es con precios finales): quien lo agrega lo trata como cualquier otro ítem del ticket.
    public function aplicar(Business $b, array $medios): array
    {
        $items = [];
        foreach ($medios as &$m) {
            $d = $m['datos'] ?? [];
            $cuotas = (int) ($d['cuotas'] ?? 1);
            if (($m['medio'] ?? '') !== 'tarjeta' || $cuotas < 1) continue;
            $pct = $this->recargo($b, $d['tarjeta'] ?? null, $cuotas);
            $m['datos'] = ['tarjeta' => $d['tarjeta'] ?? null, 'cuotas' => $cuotas, 'recargo' => $pct];
            if (abs($pct) < 0.0001) continue;
            $monto = (float) $m['monto'];
            $base = round($monto / (1 + $pct / 100), 2);
            $dif = round($monto - $base, 2);
            if (abs($dif) < 0.01) continue;
            $items[] = ['product_id' => null, 'descripcion' => ($pct > 0 ? 'Recargo ' : 'Descuento ') . ($d['tarjeta'] ? $d['tarjeta'] . ' ' : 'tarjeta ') . "{$cuotas} cuota" . ($cuotas > 1 ? 's' : '') . ' (' . rtrim(rtrim(number_format(abs($pct), 2, ',', ''), '0'), ',') . '%)', 'cantidad' => 1, 'precio_unit' => $dif, 'descuento' => 0, 'alicuota_iva' => 21, 'unidad' => 'un'];
        }
        return [array_values($medios), $items];
    }
}
