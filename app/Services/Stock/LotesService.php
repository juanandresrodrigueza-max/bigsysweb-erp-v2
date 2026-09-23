<?php

namespace App\Services\Stock;

use App\Models\Deposito;
use App\Models\Lote;
use App\Models\Product;

// Partidas con lote, vencimiento o serie. Entradas crean o suman partidas; salidas consumen FEFO (o la serie indicada).
class LotesService
{
    public function entrada(Product $p, ?Deposito $dep, float $cantidad, array $d = []): ?Lote
    {
        if (! $p->perecedero && ! $p->seriado) return null;
        $series = $p->seriado && ! empty($d['serie']) ? array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', (string) $d['serie'])))) : [];
        if ($series) {
            $ultimo = null;
            foreach ($series as $s) $ultimo = Lote::create(['business_id' => $p->business_id, 'product_id' => $p->id, 'deposito_id' => $dep?->id, 'serie' => $s, 'lote' => $d['lote'] ?? null, 'vencimiento' => $d['vencimiento'] ?? null, 'cantidad' => 1, 'costo_unit' => $d['costo'] ?? $p->cost]);
            return $ultimo;
        }
        $l = Lote::firstOrNew(['business_id' => $p->business_id, 'product_id' => $p->id, 'deposito_id' => $dep?->id, 'lote' => $d['lote'] ?? null, 'vencimiento' => $d['vencimiento'] ?? null, 'serie' => null]);
        $l->cantidad = (float) $l->cantidad + $cantidad; $l->costo_unit = $d['costo'] ?? $p->cost; $l->save();
        return $l;
    }

    // Devuelve las partidas consumidas [{lote, cantidad}]. Si falta stock en partidas, consume igual (queda registrado sin lote).
    public function salida(Product $p, ?Deposito $dep, float $cantidad, ?string $serie = null): array
    {
        if (! $p->perecedero && ! $p->seriado) return [];
        $q = Lote::where('product_id', $p->id)->where('cantidad', '>', 0)->when($dep, fn($q) => $q->where('deposito_id', $dep->id));
        if ($serie) $q->where('serie', $serie);
        // FEFO entre las vigentes; las vencidas quedan para el final (no se venden salvo que no haya otra cosa) y las sin fecha en el medio.
        $hoy = today()->toDateString();
        $partidas = $q->orderByRaw("CASE WHEN vencimiento IS NOT NULL AND vencimiento < ? THEN 2 WHEN vencimiento IS NULL THEN 1 ELSE 0 END", [$hoy])->orderBy('vencimiento')->orderBy('id')->get();
        $resto = $cantidad; $out = [];
        foreach ($partidas as $l) {
            if ($resto <= 0.0005) break;
            $usa = min((float) $l->cantidad, $resto);
            $l->cantidad = (float) $l->cantidad - $usa; $l->save();
            $out[] = ['lote' => $l, 'cantidad' => $usa]; $resto -= $usa;
        }
        return $out;
    }

    // Partidas que vencen en $dias días o ya vencieron.
    public function porVencer(int $dias = 30)
    {
        return Lote::with('product:id,name,sku,unit', 'deposito:id,nombre')->where('cantidad', '>', 0)->whereNotNull('vencimiento')->whereDate('vencimiento', '<=', today()->addDays($dias))->orderBy('vencimiento')->get();
    }
}
