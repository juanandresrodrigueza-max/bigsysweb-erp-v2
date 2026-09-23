<?php

namespace App\Services\Ventas;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\OrdenEntrega;
use App\Models\OrdenEntregaItem;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Entregas parciales, pendientes por artículo y hojas de reparto.
class EntregasService
{
    public function __construct(private ComprobanteService $comprobantes) {}

    // Remitos emitidos con cantidades sin facturar.
    public function remitosSinFacturar(): \Illuminate\Support\Collection
    {
        return Comprobante::ventas()->emitidos()->where('tipo', 'REM')->with(['items', 'contact:id,name'])->orderBy('fecha')->get()->filter(fn($c) => $c->pendienteFacturar() > 0)->values();
    }

    // Facturas con entrega pendiente y cantidades sin remitir.
    public function facturasSinEntregar(): \Illuminate\Support\Collection
    {
        return Comprobante::ventas()->emitidos()->facturas()->where('entrega_pendiente', true)->with(['items', 'contact:id,name,address,city'])->orderBy('fecha')->get()->filter(fn($c) => $c->pendienteEntrega() > 0)->values();
    }

    // Convierte con cantidades parciales: [{item_id, cantidad}]. Lo que no se indica no se incluye.
    public function convertirParcial(Comprobante $origen, string $tipoDestino, array $cantidades, array $extra = []): Comprobante
    {
        $items = [];
        foreach ($origen->items as $it) {
            $cant = (float) ($cantidades[$it->id] ?? 0);
            if ($cant <= 0) continue;
            $pend = $tipoDestino === 'REM' ? (float) $it->cantidad - (float) $it->cantidad_entregada : (float) $it->cantidad - (float) $it->cantidad_facturada;
            if ($cant > $pend + 0.0005) throw ValidationException::withMessages(['items' => "{$it->descripcion}: quedan {$pend} pendientes, no {$cant}."]);
            $items[] = ['product_id' => $it->product_id, 'descripcion' => $it->descripcion, 'cantidad' => $cant, 'unidad' => $it->unidad, 'precio_unit' => $it->precio_unit, 'descuento' => $it->descuento, 'alicuota_iva' => $it->alicuota_iva, 'origen_item_id' => $it->id];
        }
        if (! $items) throw ValidationException::withMessages(['items' => 'Indicá al menos una cantidad.']);
        $c = $this->comprobantes->guardarBorrador([
            'contact_id' => $origen->contact_id, 'tipo' => $tipoDestino, 'origen_id' => $origen->id, 'fecha' => today()->toDateString(), 'condicion' => $extra['condicion'] ?? $origen->condicion,
            'notas' => "Generado desde {$origen->nombreTipo()} {$origen->numeroFormateado()}" . ($this->esParcial($origen, $items, $tipoDestino) ? ' (parcial)' : ''), 'items' => $items,
        ]);
        return $this->comprobantes->emitir($c);
    }

    private function esParcial(Comprobante $origen, array $items, string $tipo): bool
    {
        $total = array_sum(array_column($items, 'cantidad'));
        return $total + 0.0005 < ($tipo === 'REM' ? $origen->pendienteEntrega() : $origen->pendienteFacturar());
    }

    // ---- Hojas de reparto ----
    public function crearOrden(array $d): OrdenEntrega
    {
        return DB::transaction(function () use ($d) {
            $user = Auth::user();
            $ids = array_values(array_unique($d['comprobantes'] ?? []));
            if (! $ids) throw ValidationException::withMessages(['comprobantes' => 'Elegí al menos un comprobante para entregar.']);
            $oe = OrdenEntrega::create(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'numero' => ((int) OrdenEntrega::withoutGlobalScopes()->where('business_id', $user->business_id)->max('numero')) + 1, 'fecha' => $d['fecha'] ?? today(), 'repartidor' => $d['repartidor'] ?? null, 'vehiculo' => $d['vehiculo'] ?? null, 'notas' => $d['notas'] ?? null, 'estado' => 'pendiente']);
            foreach ($ids as $i => $id) {
                $c = Comprobante::ventas()->emitidos()->with('contact')->findOrFail($id);
                $oe->items()->create(['comprobante_id' => $c->id, 'direccion' => $d['direcciones'][$id] ?? trim(($c->contact?->address ?? '') . ' ' . ($c->contact?->city ?? '')) ?: null, 'orden' => $i]);
            }
            AuditLog::registrar('crear', $oe, "Hoja de reparto {$oe->numeroFormateado()} con " . count($ids) . ' entregas');
            return $oe->fresh('items');
        });
    }

    public function estadoOrden(OrdenEntrega $oe, string $estado): void
    {
        $oe->update(['estado' => $estado, 'entregada_en' => $estado === 'entregada' ? now() : $oe->entregada_en]);
    }

    // Marca una entrega: si la factura tenía entrega pendiente, genera el remito por lo pendiente (mueve stock).
    public function marcarItem(OrdenEntregaItem $it, string $estado, ?string $obs = null): void
    {
        DB::transaction(function () use ($it, $estado, $obs) {
            $it->update(['estado' => $estado, 'observacion' => $obs, 'entregado_en' => $estado === 'entregado' ? now() : null]);
            $c = $it->comprobante;
            if ($estado === 'entregado' && $c && $c->pendienteEntrega() > 0) {
                $this->convertirParcial($c, 'REM', $c->items->mapWithKeys(fn($i) => [$i->id => (float) $i->cantidad - (float) $i->cantidad_entregada])->all());
            }
            $oe = $it->hoja;
            if ($oe->items()->where('estado', 'pendiente')->doesntExist() && $oe->estado !== 'entregada') $this->estadoOrden($oe, 'entregada');
            elseif ($oe->estado === 'pendiente') $this->estadoOrden($oe, 'en_curso');
        });
    }
}
