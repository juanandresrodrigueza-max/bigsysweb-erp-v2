<?php

namespace App\Services\Stock;

use App\Models\AuditLog;
use App\Models\Deposito;
use App\Models\Merma;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Fase 89: registrar una merma baja el stock y deja el motivo y el costo; el reporte junta por motivo y por artículo.
class MermasService
{
    public function __construct(private StockService $stock) {}

    public function registrar(User $u, Product $p, float $cantidad, string $motivo, ?Deposito $deposito = null, ?string $notas = null, ?int $comandaItemId = null, bool $moverStock = true): Merma
    {
        abort_unless(array_key_exists($motivo, Merma::MOTIVOS), 422, 'Motivo de merma desconocido.');
        return DB::transaction(function () use ($u, $p, $cantidad, $motivo, $deposito, $notas, $comandaItemId, $moverStock) {
            $deposito ??= Deposito::porDefecto($u->current_location_id);
            $m = Merma::create(['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id, 'product_id' => $p->id, 'deposito_id' => $deposito?->id, 'user_id' => $u->id,
                'comanda_item_id' => $comandaItemId, 'fecha' => today(), 'cantidad' => $cantidad, 'costo_unit' => (float) $p->cost, 'motivo' => $motivo, 'notas' => $notas]);
            if ($moverStock) $this->stock->salida($p, $cantidad, 'Merma: ' . Merma::MOTIVOS[$motivo] . ($notas ? " ($notas)" : ''), $m, $deposito, $u->current_location_id);
            AuditLog::registrar('merma', $m, "{$u->name} registró merma de {$cantidad} {$p->unit} de {$p->name} ({$m->motivo})");
            return $m;
        });
    }

    // Período: filas, totales por motivo y los artículos que más se pierden.
    public function resumen(string $desde, string $hasta): array
    {
        $q = Merma::with('product:id,name,sku,unit')->whereBetween('fecha', [$desde . ' 00:00:00', $hasta . ' 23:59:59']);
        $todas = (clone $q)->get();
        $porMotivo = collect(Merma::MOTIVOS)->map(fn($label, $k) => ['motivo' => $k, 'label' => $label, 'cantidad' => $todas->where('motivo', $k)->count(), 'importe' => round($todas->where('motivo', $k)->sum(fn($m) => $m->importe()), 2)])->filter(fn($r) => $r['cantidad'] > 0)->values()->all();
        $porArticulo = $todas->groupBy('product_id')->map(fn($g) => ['nombre' => $g->first()->product?->name, 'unidad' => $g->first()->product?->unit, 'cantidad' => round($g->sum('cantidad'), 3), 'importe' => round($g->sum(fn($m) => $m->importe()), 2), 'veces' => $g->count()])->sortByDesc('importe')->take(10)->values()->all();
        return ['total' => round($todas->sum(fn($m) => $m->importe()), 2), 'veces' => $todas->count(), 'por_motivo' => $porMotivo, 'por_articulo' => $porArticulo];
    }
}
