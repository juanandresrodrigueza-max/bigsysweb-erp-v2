<?php

namespace App\Services\Obras;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Proyecto;
use App\Models\ProyectoParte;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Obras y proyectos: presupuesto vs costo real (partes diarios, compras y gastos imputados), avance y certificación.
class ObrasService
{
    public function __construct(private StockService $stock, private ComprobanteService $comprobantes) {}

    public function guardar(array $d, ?Proyecto $p = null): Proyecto
    {
        $u = Auth::user();
        $p ??= new Proyecto(['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id, 'codigo' => 'OB-' . str_pad((string) (Proyecto::withoutGlobalScopes()->where('business_id', $u->business_id)->count() + 1), 4, '0', STR_PAD_LEFT)]);
        $p->fill($d)->save();
        AuditLog::registrar($p->wasRecentlyCreated ? 'crear' : 'editar', $p, "Obra {$p->codigo} · {$p->nombre}");
        return $p;
    }

    // Parte diario: material (sale del stock al costo), mano de obra, maquinaria, subcontrato o gasto.
    public function parte(Proyecto $p, array $d): ProyectoParte
    {
        return DB::transaction(function () use ($p, $d) {
            $prod = ! empty($d['product_id']) ? Product::find($d['product_id']) : null;
            $costo = (float) ($d['costo_unit'] ?? 0);
            if ($prod && $costo <= 0) $costo = (float) $prod->cost;
            $parte = $p->partes()->create(['user_id' => Auth::id(), 'product_id' => $prod?->id, 'empleado_id' => $d['empleado_id'] ?? null, 'fecha' => $d['fecha'] ?? today(), 'tipo' => $d['tipo'], 'descripcion' => $d['descripcion'] ?: ($prod?->name ?? 'Parte'), 'cantidad' => $d['cantidad'] ?? 1, 'unidad' => $d['unidad'] ?? $prod?->unit, 'costo_unit' => $costo, 'total' => round((float) ($d['cantidad'] ?? 1) * $costo, 2)]);
            if ($prod && $prod->controla_stock && $d['tipo'] === 'material') {
                $mov = $this->stock->salida($prod, (float) ($d['cantidad'] ?? 1), "Obra {$p->codigo} · {$p->nombre}", $parte, null, $p->business_location_id);
                $parte->update(['stock_movement_id' => $mov?->id]);
            }
            if ($p->estado === 'presupuestado') $p->update(['estado' => 'en_curso', 'fecha_inicio' => $p->fecha_inicio ?? today()]);
            AuditLog::registrar('crear', $parte, "Parte en {$p->codigo}: {$parte->descripcion} $ " . number_format((float) $parte->total, 2, ',', '.'));
            return $parte;
        });
    }

    public function borrarParte(ProyectoParte $parte): void
    {
        DB::transaction(function () use ($parte) {
            if ($parte->stock_movement_id && $parte->product) $this->stock->entrada($parte->product, (float) $parte->cantidad, "Devolución parte de obra", $parte, null, (float) $parte->costo_unit, $parte->proyecto->business_location_id);
            $parte->delete();
        });
    }

    // Costeo: presupuestado vs real por rubro, facturado, cobrado y margen.
    public function resumen(Proyecto $p): array
    {
        $partes = $p->partes()->get();
        $porTipo = [];
        foreach (Proyecto::TIPOS_PARTE as $k => $label) $porTipo[$k] = ['label' => $label, 'real' => round((float) $partes->where('tipo', $k)->sum('total'), 2)];
        $compras = Comprobante::where('direccion', 'compra')->where('estado', 'emitido')->where('proyecto_id', $p->id)->get();
        $gastos = Expense::where('proyecto_id', $p->id)->get();
        $porTipo['compras'] = ['label' => 'Facturas de compra imputadas', 'real' => round((float) $compras->sum('neto'), 2)];
        $porTipo['gastos'] = ['label' => 'Gastos imputados', 'real' => round((float) $gastos->sum('amount'), 2)];
        $costoReal = round(array_sum(array_column($porTipo, 'real')), 2);
        $ventas = Comprobante::where('direccion', 'venta')->where('estado', 'emitido')->where('proyecto_id', $p->id)->whereIn('tipo', ['FA', 'FB', 'FC', 'NCA', 'NCB', 'NCC'])->get();
        $facturado = round((float) $ventas->sum(fn($c) => (float) $c->neto * $c->def()['cc']), 2);
        $cobrado = round((float) $ventas->sum(fn($c) => ((float) $c->total - (float) $c->saldo) * $c->def()['cc']), 2);
        $presupCosto = (float) $p->presupuesto_costo; $presupVenta = (float) $p->presupuesto_venta;
        $avance = (float) $p->avance / 100;
        return [
            'por_tipo' => $porTipo, 'costo_real' => $costoReal, 'presupuesto_costo' => $presupCosto, 'presupuesto_venta' => $presupVenta,
            'desvio_costo' => round($costoReal - $presupCosto * max($avance, 0.0001), 2), // contra lo que debería haberse gastado según el avance
            'costo_esperado_avance' => round($presupCosto * $avance, 2),
            'facturado' => $facturado, 'cobrado' => $cobrado, 'por_certificar' => round($presupVenta * (max(0, (float) $p->avance - (float) $p->avance_certificado)) / 100, 2),
            'margen_previsto' => round($presupVenta - $presupCosto, 2), 'margen_real' => round($facturado - $costoReal, 2),
            'margen_proyectado' => $avance > 0 ? round($presupVenta - $costoReal / $avance, 2) : round($presupVenta - $presupCosto, 2), // si sigue gastando a este ritmo
            'compras' => $compras->map(fn($c) => ['id' => $c->id, 'numero' => $c->nombreTipo() . ' ' . $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m/Y'), 'proveedor' => $c->contact?->name, 'neto' => (float) $c->neto])->values()->all(),
            'ventas' => $ventas->map(fn($c) => ['id' => $c->id, 'numero' => $c->nombreTipo() . ' ' . $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m/Y'), 'total' => (float) $c->total, 'saldo' => (float) $c->saldo])->values()->all(),
        ];
    }

    // Certifica avance: factura la porción del presupuesto de venta entre lo ya certificado y el nuevo avance.
    public function certificar(Proyecto $p, float $avanceNuevo, string $condicion = 'cta_cte'): Comprobante
    {
        abort_if(! $p->contact_id, 422, 'La obra no tiene cliente asignado.');
        abort_if($avanceNuevo <= (float) $p->avance_certificado, 422, 'El avance a certificar tiene que superar lo ya certificado (' . (float) $p->avance_certificado . '%).');
        abort_if((float) $p->presupuesto_venta <= 0, 422, 'Cargá el presupuesto de venta de la obra.');
        return DB::transaction(function () use ($p, $avanceNuevo, $condicion) {
            $pct = round($avanceNuevo - (float) $p->avance_certificado, 2);
            $n = Comprobante::where('proyecto_id', $p->id)->where('direccion', 'venta')->whereIn('tipo', ['FA', 'FB', 'FC'])->count() + 1;
            $ri = (Auth::user()->business->condicion_iva ?? '') === 'Responsable Inscripto';
            $importe = round((float) $p->presupuesto_venta * $pct / 100, 2);
            $c = $this->comprobantes->guardarBorrador(['contact_id' => $p->contact_id, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => $condicion, 'proyecto_id' => $p->id, 'notas' => "Obra {$p->codigo} · {$p->nombre}",
                'items' => [['product_id' => null, 'descripcion' => "Certificado de obra N° {$n} · avance del " . (float) $p->avance_certificado . "% al {$avanceNuevo}% ({$pct}%)", 'cantidad' => 1, 'precio_unit' => $ri ? round($importe / 1.21, 2) : $importe, 'descuento' => 0, 'alicuota_iva' => 21]]]);
            $c = $this->comprobantes->emitir($c);
            $p->update(['avance_certificado' => $avanceNuevo, 'avance' => max((float) $p->avance, $avanceNuevo)]);
            AuditLog::registrar('emitir', $p, "Certificó {$pct}% de {$p->codigo}: {$c->nombreTipo()} {$c->numeroFormateado()}");
            return $c;
        });
    }

    public function vincular(Proyecto $p, Comprobante $c): void
    {
        $c->update(['proyecto_id' => $p->id]);
        AuditLog::registrar('editar', $p, "Imputó {$c->nombreTipo()} {$c->numeroFormateado()} a {$p->codigo}");
    }
}
