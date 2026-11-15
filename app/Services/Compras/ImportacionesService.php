<?php

namespace App\Services\Compras;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Deposito;
use App\Models\DespachoImportacion;
use App\Models\Product;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Fase 97: despachos de importación. FOB en moneda extranjera × cotización + gastos de nacionalizar prorrateados por valor FOB
// = costo nacionalizado por unidad. Al nacionalizar entra al stock con ese costo y actualiza el costo del artículo.
class ImportacionesService
{
    public function __construct(private StockService $stock) {}

    public function guardar(array $d, ?DespachoImportacion $desp = null): DespachoImportacion
    {
        return DB::transaction(function () use ($d, $desp) {
            $u = Auth::user(); $b = $u->business;
            abort_if($desp && $desp->estado !== 'borrador', 422, 'El despacho ya fue nacionalizado.');
            $desp ??= new DespachoImportacion(['business_id' => $b->id, 'business_location_id' => $u->current_location_id, 'user_id' => $u->id, 'numero' => ((int) DespachoImportacion::withoutGlobalScopes()->where('business_id', $b->id)->max('numero')) + 1]);
            $desp->fill(['contact_id' => $d['contact_id'] ?? null, 'comprobante_id' => $d['comprobante_id'] ?? null, 'deposito_id' => $d['deposito_id'] ?? null, 'despacho' => $d['despacho'] ?? null, 'fecha' => $d['fecha'] ?? today(), 'moneda' => strtoupper($d['moneda'] ?? 'USD'), 'cotizacion' => (float) ($d['cotizacion'] ?? 1), 'notas' => $d['notas'] ?? null])->save();
            $desp->items()->delete(); $desp->gastos()->delete();
            foreach (array_values($d['items'] ?? []) as $i => $it) {
                if ((float) ($it['cantidad'] ?? 0) <= 0) continue;
                $p = ! empty($it['product_id']) ? Product::find($it['product_id']) : null;
                $desp->items()->create(['product_id' => $p?->id, 'descripcion' => ($it['descripcion'] ?? null) ?: ($p?->name ?? 'Ítem'), 'cantidad' => $it['cantidad'], 'fob_unit' => (float) ($it['fob_unit'] ?? 0), 'orden' => $i]);
            }
            foreach ($d['gastos'] ?? [] as $g) {
                if ((float) ($g['monto'] ?? 0) <= 0) continue;
                $tipo = array_key_exists($g['tipo'] ?? '', DespachoImportacion::GASTOS) ? $g['tipo'] : 'otros';
                $desp->gastos()->create(['tipo' => $tipo, 'concepto' => $g['concepto'] ?? null, 'monto' => (float) $g['monto'], 'al_costo' => DespachoImportacion::GASTOS[$tipo]['al_costo']]);
            }
            $this->calcular($desp);
            AuditLog::registrar($desp->wasRecentlyCreated ? 'crear' : 'editar', $desp, "Despacho {$desp->numeroFormateado()}" . ($desp->despacho ? " ({$desp->despacho})" : ''));
            return $desp->fresh(['items', 'gastos', 'contact']);
        });
    }

    // Prorrateo por valor FOB: cada ítem carga la parte de los gastos proporcional a lo que vale.
    public function calcular(DespachoImportacion $d): void
    {
        $items = $d->items()->get(); $cot = (float) $d->cotizacion ?: 1;
        $fobTotal = $items->sum(fn($i) => (float) $i->cantidad * (float) $i->fob_unit * $cot);
        $gastos = (float) $d->gastos()->where('al_costo', true)->sum('monto'); $credito = (float) $d->gastos()->where('al_costo', false)->sum('monto');
        foreach ($items as $i) {
            $fob = round((float) $i->cantidad * (float) $i->fob_unit * $cot, 2);
            $parte = $fobTotal > 0 ? round($gastos * $fob / $fobTotal, 2) : 0;
            $i->update(['fob_total_ars' => $fob, 'gastos_ars' => $parte, 'costo_unit_ars' => (float) $i->cantidad > 0 ? round(($fob + $parte) / (float) $i->cantidad, 4) : 0]);
        }
        $d->forceFill(['fob_total' => round($fobTotal, 2), 'gastos_total' => round($gastos, 2), 'credito_fiscal' => round($credito, 2), 'costo_total' => round($fobTotal + $gastos, 2)])->save();
    }

    // La mercadería entra al stock con el costo nacionalizado; el artículo queda con ese costo.
    public function nacionalizar(DespachoImportacion $d): DespachoImportacion
    {
        abort_if($d->estado !== 'borrador', 422, 'El despacho ya fue nacionalizado.');
        abort_if($d->items()->whereNotNull('product_id')->doesntExist(), 422, 'El despacho no tiene artículos del catálogo para ingresar al stock.');
        return DB::transaction(function () use ($d) {
            $this->calcular($d);
            $dep = $d->deposito_id ? Deposito::find($d->deposito_id) : Deposito::porDefecto($d->business_location_id ?? Auth::user()?->current_location_id);
            foreach ($d->items()->whereNotNull('product_id')->get() as $i) {
                $p = Product::find($i->product_id); if (! $p) continue;
                $this->stock->entrada($p, (float) $i->cantidad, "Importación {$d->numeroFormateado()}" . ($d->despacho ? " · despacho {$d->despacho}" : ''), $d, $dep, (float) $i->costo_unit_ars, $d->business_location_id);
                $p->forceFill(['cost' => round((float) $i->costo_unit_ars, 2), 'precio_compra' => round((float) $i->costo_unit_ars, 2)])->save();
            }
            $d->update(['estado' => 'nacionalizado', 'nacionalizado_en' => now()]);
            AuditLog::registrar('editar', $d, "Despacho {$d->numeroFormateado()} nacionalizado: $ " . number_format((float) $d->costo_total, 2, ',', '.') . ' al stock');
            return $d->fresh(['items', 'gastos']);
        });
    }

    public function anular(DespachoImportacion $d): void
    {
        abort_if($d->estado !== 'borrador', 422, 'Un despacho nacionalizado no se anula: ajustá el stock si hace falta.');
        $d->update(['estado' => 'anulado']);
    }

    public function arr(DespachoImportacion $d): array
    {
        $d->loadMissing('items.product:id,name,sku,unit,stock,cost', 'gastos', 'contact:id,name', 'user:id,name', 'deposito:id,nombre');
        return ['id' => $d->id, 'numero' => $d->numeroFormateado(), 'despacho' => $d->despacho, 'fecha' => $d->fecha->format('d/m/Y'), 'fecha_iso' => $d->fecha->toDateString(), 'proveedor' => $d->contact?->name, 'contact_id' => $d->contact_id, 'comprobante_id' => $d->comprobante_id, 'deposito_id' => $d->deposito_id, 'deposito' => $d->deposito?->nombre,
            'moneda' => $d->moneda, 'cotizacion' => (float) $d->cotizacion, 'estado' => $d->estado, 'estado_label' => DespachoImportacion::ESTADOS[$d->estado] ?? $d->estado, 'fob_total' => (float) $d->fob_total, 'gastos_total' => (float) $d->gastos_total, 'credito_fiscal' => (float) $d->credito_fiscal, 'costo_total' => (float) $d->costo_total,
            'coeficiente' => (float) $d->fob_total > 0 ? round((float) $d->costo_total / (float) $d->fob_total, 4) : null, 'notas' => $d->notas, 'usuario' => $d->user?->name, 'nacionalizado' => $d->nacionalizado_en?->format('d/m/Y H:i'),
            'items' => $d->items->map(fn($i) => ['id' => $i->id, 'product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'sku' => $i->product?->sku, 'unit' => $i->product?->unit, 'cantidad' => (float) $i->cantidad, 'fob_unit' => (float) $i->fob_unit, 'fob_total_ars' => (float) $i->fob_total_ars, 'gastos_ars' => (float) $i->gastos_ars, 'costo_unit_ars' => (float) $i->costo_unit_ars, 'costo_actual' => (float) ($i->product?->cost ?? 0)])->values()->all(),
            'gastos' => $d->gastos->map(fn($g) => ['id' => $g->id, 'tipo' => $g->tipo, 'label' => DespachoImportacion::GASTOS[$g->tipo]['label'] ?? $g->tipo, 'concepto' => $g->concepto, 'monto' => (float) $g->monto, 'al_costo' => (bool) $g->al_costo])->values()->all()];
    }

    public function resumen(Business $b): array
    {
        $q = DespachoImportacion::withoutGlobalScopes()->where('business_id', $b->id);
        return ['en_curso' => (clone $q)->where('estado', 'borrador')->count(), 'fob_en_curso' => (float) (clone $q)->where('estado', 'borrador')->sum('fob_total'), 'nacionalizados_anio' => (clone $q)->where('estado', 'nacionalizado')->whereYear('nacionalizado_en', now()->year)->count(), 'costo_anio' => (float) (clone $q)->where('estado', 'nacionalizado')->whereYear('nacionalizado_en', now()->year)->sum('costo_total')];
    }
}
