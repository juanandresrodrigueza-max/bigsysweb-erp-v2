<?php

namespace App\Services\Stock;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Deposito;
use App\Models\Lote;
use App\Models\LoteMovimiento;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

// Partidas con lote, vencimiento o serie. Entradas crean o suman partidas; salidas consumen FEFO (o el lote / la serie
// indicada). Cada entrada y salida queda en lote_movimientos para la trazabilidad (de qué proveedor vino, a qué cliente fue).
class LotesService
{
    public const DEFAULT = ['bloquear_vencidos' => true, 'dias_aviso' => 30];

    public function config(?Business $b): array
    {
        return array_replace(self::DEFAULT, (array) ($b?->lotes_config ?? []));
    }

    // Devuelve las partidas creadas o sumadas: [['lote' => Lote, 'cantidad' => +x]].
    public function entrada(Product $p, ?Deposito $dep, float $cantidad, array $d = []): array
    {
        if (! $p->perecedero && ! $p->seriado) return [];
        $base = ['business_id' => $p->business_id, 'product_id' => $p->id, 'deposito_id' => $dep?->id];
        $extra = ['proveedor_id' => $d['proveedor_id'] ?? null, 'ingreso' => $d['ingreso'] ?? today()->toDateString()];
        $series = $p->seriado && ! empty($d['serie']) ? array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', (string) $d['serie'])))) : [];
        if ($series) {
            $out = [];
            foreach ($series as $s) $out[] = ['lote' => Lote::create($base + $extra + ['serie' => $s, 'lote' => $d['lote'] ?? null, 'vencimiento' => $d['vencimiento'] ?? null, 'cantidad' => 1, 'costo_unit' => $d['costo'] ?? $p->cost]), 'cantidad' => 1.0];
            return $out;
        }
        $l = Lote::firstOrNew($base + ['lote' => ($d['lote'] ?? null) ?: null, 'vencimiento' => ($d['vencimiento'] ?? null) ?: null, 'serie' => null]);
        if (! $l->exists) $l->fill($extra);
        $l->cantidad = (float) $l->cantidad + $cantidad; $l->costo_unit = $d['costo'] ?? $p->cost; $l->save();
        return [['lote' => $l, 'cantidad' => $cantidad]];
    }

    // Partidas consumidas [['lote', 'cantidad']]. Nunca toma lotes bloqueados ni retirados; los vencidos solo si se permite
    // (baja de vencidos, ajustes). Con $estricto (ventas) y sin stock vendible suficiente, frena con el motivo.
    public function salida(Product $p, ?Deposito $dep, float $cantidad, ?string $serie = null, array $o = []): array
    {
        if (! $p->perecedero && ! $p->seriado) return [];
        $hoy = today()->toDateString();
        $permitirVencidos = (bool) ($o['permitir_vencidos'] ?? false);
        $q = Lote::where('product_id', $p->id)->where('cantidad', '>', 0)->when($dep, fn($q) => $q->where('deposito_id', $dep->id));
        if (empty($o['incluir_bloqueados'])) $q->where('estado', 'disponible');
        if ($serie) $q->where('serie', $serie);
        if (! empty($o['lote_id'])) $q->where('id', $o['lote_id']);
        if (! $permitirVencidos) $q->where(fn($w) => $w->whereNull('vencimiento')->orWhere('vencimiento', '>=', $hoy));
        // FEFO: primero lo que vence antes; los sin fecha después; (si se permiten) los vencidos primero, para sacarlos.
        $partidas = $q->orderByRaw('CASE WHEN vencimiento IS NULL THEN 1 ELSE 0 END')->orderBy('vencimiento')->orderBy('id')->get();
        $disponible = (float) $partidas->sum('cantidad');
        if (! empty($o['estricto']) && $disponible + 0.0005 < $cantidad) {
            $trabados = Lote::where('product_id', $p->id)->where('cantidad', '>', 0)->when($dep, fn($q) => $q->where('deposito_id', $dep->id))
                ->where(fn($w) => $w->where('estado', '!=', 'disponible')->orWhere('vencimiento', '<', $hoy))->get();
            $detalle = $trabados->map(fn($l) => $l->etiqueta() . ($l->estado !== 'disponible' ? ' (' . Lote::ESTADOS[$l->estado] . ')' : ' (vencido)'))->implode(', ');
            throw ValidationException::withMessages(['items' => "De {$p->name} hay " . rtrim(rtrim(number_format($disponible, 3, ',', '.'), '0'), ',') . " para vender" . (! empty($o['lote_id']) ? ' en el lote elegido' : '') . ($detalle ? ". No se pueden vender: {$detalle}." : '.')]);
        }
        $resto = $cantidad; $out = [];
        foreach ($partidas as $l) {
            if ($resto <= 0.0005) break;
            $usa = min((float) $l->cantidad, $resto);
            $l->cantidad = (float) $l->cantidad - $usa; $l->save();
            $out[] = ['lote' => $l, 'cantidad' => $usa]; $resto -= $usa;
        }
        return $out;
    }

    // Vuelve la mercadería a los mismos lotes de los que salió con $origen (anulación, nota de crédito). Lo que no se
    // encuentra entra como partida sin lote.
    public function devolver(Product $p, ?Deposito $dep, float $cantidad, Model $origen): array
    {
        if (! $p->perecedero && ! $p->seriado) return [];
        $salidas = LoteMovimiento::where('product_id', $p->id)->where('movable_type', get_class($origen))->where('movable_id', $origen->getKey())->where('cantidad', '<', 0)->orderByDesc('id')->get();
        $yaDevuelto = (float) LoteMovimiento::where('product_id', $p->id)->where('motivo', 'like', 'Devolución%')->where('movable_type', get_class($origen))->where('movable_id', $origen->getKey())->sum('cantidad');
        $resto = $cantidad; $out = [];
        foreach ($salidas as $m) {
            if ($resto <= 0.0005) break;
            $puede = -(float) $m->cantidad;
            if ($yaDevuelto > 0) { $d = min($puede, $yaDevuelto); $puede -= $d; $yaDevuelto -= $d; }
            if ($puede <= 0.0005 || ! ($l = Lote::find($m->lote_id))) continue;
            $usa = min($puede, $resto);
            $l->cantidad = (float) $l->cantidad + $usa; $l->save();
            $out[] = ['lote' => $l, 'cantidad' => $usa, 'devolucion' => true]; $resto -= $usa;
        }
        if ($resto > 0.0005) $out = array_merge($out, $this->entrada($p, $dep, $resto));
        return $out;
    }

    // Registra la trazabilidad de un movimiento de stock.
    public function registrar(array $partidas, int $signo, ?\App\Models\StockMovement $mov, ?Model $origen, string $motivo): void
    {
        $contactId = $origen && isset($origen->contact_id) ? $origen->contact_id : null;
        foreach ($partidas as $pa) {
            LoteMovimiento::create(['business_id' => $pa['lote']->business_id, 'lote_id' => $pa['lote']->id, 'product_id' => $pa['lote']->product_id, 'stock_movement_id' => $mov?->id,
                'cantidad' => $signo * $pa['cantidad'], 'movable_type' => $origen ? get_class($origen) : null, 'movable_id' => $origen?->getKey(), 'contact_id' => $contactId,
                'motivo' => ! empty($pa['devolucion']) ? "Devolución · {$motivo}" : $motivo]);
        }
    }

    // Partidas que vencen en $dias días o ya vencieron.
    public function porVencer(int $dias = 30)
    {
        return Lote::with('product:id,name,sku,unit', 'deposito:id,nombre')->where('cantidad', '>', 0)->whereNotNull('vencimiento')->whereDate('vencimiento', '<=', today()->addDays($dias))->orderBy('vencimiento')->get();
    }

    // Trazabilidad completa de un lote: de dónde vino y a quién fue.
    public function trazabilidad(Lote $l): array
    {
        $movs = LoteMovimiento::with('contact:id,name,email,phone,mobile')->where('lote_id', $l->id)->orderBy('id')->get();
        $fila = function ($m) {
            $doc = $m->movable; $esC = $doc instanceof Comprobante;
            return ['id' => $m->id, 'fecha' => ($esC && $doc->fecha ? $doc->fecha : $m->created_at)->format('d/m/Y'), 'cantidad' => (float) $m->cantidad, 'motivo' => $m->motivo,
                'comprobante' => $esC ? $doc->nombreTipo() . ' ' . $doc->numeroFormateado() : null, 'comprobante_id' => $esC ? $doc->id : null, 'direccion' => $esC ? $doc->direccion : null,
                'contacto' => $m->contact?->name, 'contact_id' => $m->contact_id];
        };
        $salidas = $movs->filter(fn($m) => (float) $m->cantidad < 0 && $m->contact_id && ($m->movable instanceof Comprobante) && $m->movable->direccion === 'venta');
        $clientes = $salidas->groupBy('contact_id')->map(function ($g) use ($movs) {
            $c = $g->first()->contact;
            $devuelto = (float) $movs->where('contact_id', $c?->id)->filter(fn($m) => (float) $m->cantidad > 0)->sum('cantidad');
            return ['id' => $c?->id, 'nombre' => $c?->name, 'email' => $c?->email, 'telefono' => $c?->mobile ?: $c?->phone, 'cantidad' => round(-(float) $g->sum('cantidad') - $devuelto, 3), 'comprobantes' => $g->count()];
        })->filter(fn($c) => $c['cantidad'] > 0.0005)->values();
        return ['movimientos' => $movs->map($fila)->values(), 'clientes' => $clientes,
            'entrado' => round((float) $movs->where('cantidad', '>', 0)->sum('cantidad'), 3), 'salido' => round(abs((float) $movs->where('cantidad', '<', 0)->sum('cantidad')), 3)];
    }
}
