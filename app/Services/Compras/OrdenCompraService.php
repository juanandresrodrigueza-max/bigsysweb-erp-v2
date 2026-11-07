<?php

namespace App\Services\Compras;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Contact;
use App\Models\OrdenCompra;
use App\Models\Product;
use App\Models\Rubro;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Órdenes de compra: se arman a mano o sugeridas por el sistema, se envían al proveedor y al recibir generan la factura de compra.
class OrdenCompraService
{
    public function guardar(array $d, ?OrdenCompra $oc = null): OrdenCompra
    {
        return DB::transaction(function () use ($d, $oc) {
            $user = Auth::user();
            $prov = Contact::suppliers()->findOrFail($d['contact_id']);
            $oc ??= new OrdenCompra(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'numero' => ((int) OrdenCompra::withoutGlobalScopes()->where('business_id', $user->business_id)->max('numero')) + 1, 'estado' => 'borrador']);
            abort_if($oc->exists && ! in_array($oc->estado, ['borrador', 'enviada'], true), 422, 'La orden ya fue recibida o cancelada.');
            $items = collect($d['items'] ?? [])->filter(fn($i) => (float) ($i['cantidad'] ?? 0) > 0)->values();
            if ($items->isEmpty()) throw ValidationException::withMessages(['items' => 'Agregá al menos un artículo.']);

            $oc->fill(['contact_id' => $prov->id, 'fecha' => $d['fecha'] ?? today(), 'fecha_entrega' => $d['fecha_entrega'] ?? null, 'notas' => $d['notas'] ?? null, 'origen' => $d['origen'] ?? $oc->origen ?? 'manual'])->save();
            $recibidos = $oc->items()->get()->keyBy('product_id');
            $oc->items()->delete();
            foreach ($items as $i => $it) {
                $p = ! empty($it['product_id']) ? Product::find($it['product_id']) : null;
                $oc->items()->create(['product_id' => $p?->id, 'descripcion' => ($it['descripcion'] ?? null) ?: ($p?->name ?? 'Ítem'), 'cantidad' => $it['cantidad'], 'precio_unit' => $it['precio_unit'] ?? ($p?->precio_compra ?: $p?->cost ?? 0), 'recibido' => $p ? (float) ($recibidos[$p->id]->recibido ?? 0) : 0, 'notas' => $it['notas'] ?? null, 'orden' => $i]);
            }
            $oc->recalcular();
            AuditLog::registrar($oc->wasRecentlyCreated ? 'crear' : 'editar', $oc, "Orden de compra {$oc->numeroFormateado()} a {$prov->name}");
            return $oc->fresh(['items', 'contact']);
        });
    }

    // Sugerencia de pedido: cubre las ventas de un período de referencia (o los faltantes bajo mínimo), descontando el stock actual.
    public function sugerir(array $f): array
    {
        $modo = $f['modo'] ?? 'ventas';
        $q = Product::where('active', true)->where('controla_stock', true)->whereIn('tipo', ['producto', 'insumo'])
            ->when($f['contact_id'] ?? null, fn($q, $c) => $q->where('proveedor_id', $c))
            ->when($f['rubro_id'] ?? null, fn($q, $r) => $q->where(fn($w) => $w->where('rubro_id', $r)->orWhereIn('rubro_id', Rubro::where('parent_id', $r)->pluck('id'))));
        $productos = $q->get();
        if ($productos->isEmpty()) return [];

        $vendido = collect();
        if ($modo === 'ventas') {
            $desde = $f['desde'] ?? today()->subYear()->startOfMonth()->toDateString();
            $hasta = $f['hasta'] ?? today()->subYear()->endOfMonth()->toDateString();
            $vendido = ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')
                ->where('comprobantes.business_id', Auth::user()->business_id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')
                ->whereIn('comprobantes.tipo', ['FA', 'FB', 'FC', 'FE', 'REM'])->whereBetween('comprobantes.fecha', [$desde, $hasta])
                ->whereIn('comprobante_items.product_id', $productos->pluck('id'))
                ->selectRaw('comprobante_items.product_id, SUM(comprobante_items.cantidad) as cant')->groupBy('comprobante_items.product_id')->pluck('cant', 'product_id');
        }
        $dias = max(1, (int) ($f['dias_cobertura'] ?? 30));
        $out = [];
        foreach ($productos as $p) {
            $stock = (float) $p->stock;
            if ($modo === 'ventas') {
                $base = (float) ($vendido[$p->id] ?? 0);
                if ($base <= 0) continue;
                $objetivo = $base;
            } else {
                $objetivo = (float) $p->stock_min * 2; // faltantes: llevar a 2 veces el mínimo
                if ($stock > (float) $p->stock_min) continue;
            }
            $pedir = round(max(0, $objetivo - $stock), 3);
            if ($pedir <= 0) continue;
            $out[] = ['product_id' => $p->id, 'descripcion' => $p->name, 'sku' => $p->sku, 'stock' => $stock, 'vendido' => (float) ($vendido[$p->id] ?? 0), 'minimo' => (float) $p->stock_min, 'cantidad' => $pedir, 'precio_unit' => (float) ($p->precio_compra ?: $p->cost), 'proveedor' => $p->proveedor_id];
        }
        usort($out, fn($a, $b) => $b['cantidad'] * $b['precio_unit'] <=> $a['cantidad'] * $a['precio_unit']);
        return $out;
    }

    public function enviar(OrdenCompra $oc): OrdenCompra
    {
        abort_if($oc->estado !== 'borrador', 422, 'Solo se envían órdenes en borrador.');
        $oc->update(['estado' => 'enviada', 'enviada_en' => now()]);
        AuditLog::registrar('editar', $oc, "Orden de compra {$oc->numeroFormateado()} enviada a {$oc->contact?->name}");
        return $oc;
    }

    public function cancelar(OrdenCompra $oc, string $motivo = ''): OrdenCompra
    {
        abort_if(! $oc->abierta(), 422, 'La orden ya está cerrada.');
        $oc->update(['estado' => 'cancelada', 'notas' => trim(($oc->notas ?? '') . "\nCancelada: {$motivo}")]);
        AuditLog::registrar('anular', $oc, "Orden de compra {$oc->numeroFormateado()} cancelada: {$motivo}");
        return $oc;
    }

    // Arma el borrador de factura de compra con lo pendiente de la OC (el usuario ajusta cantidades y número al registrarla).
    public function datosCompra(OrdenCompra $oc): array
    {
        abort_if(! $oc->abierta(), 422, 'La orden no tiene pendientes.');
        $items = $oc->items->filter(fn($i) => $i->pendiente() > 0)->values()->map(fn($i) => [
            'product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => $i->pendiente(), 'precio_unit' => (float) $i->precio_unit, 'descuento' => 0,
            'alicuota_iva' => (float) ($i->product?->iva ?? 21), 'unidad' => $i->product?->unit,
        ])->all();
        return ['contact_id' => $oc->contact_id, 'orden_compra_id' => $oc->id, 'tipo' => 'FA', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'items' => $items, 'notas' => "Según {$oc->numeroFormateado()}"];
    }

    // Fase 42.4: una factura contra varias órdenes del mismo proveedor. Los renglones se juntan por artículo,
    // al precio de cada orden (como «Recibir» en BigSys: el descuento de la orden ya está en ese precio).
    public function datosCompraVarias(\Illuminate\Support\Collection $ocs): array
    {
        abort_if($ocs->isEmpty(), 404);
        abort_if($ocs->pluck('contact_id')->unique()->count() > 1, 422, 'Las órdenes son de proveedores distintos.');
        $items = []; $numeros = [];
        foreach ($ocs as $oc) { $d = $this->datosCompra($oc); $numeros[] = $oc->numeroFormateado(); foreach ($d['items'] as $i) $items[] = $i + ['orden' => $oc->numeroFormateado()]; }
        $base = $this->datosCompra($ocs->first());
        return array_replace($base, ['items' => $items, 'ordenes_compra' => $ocs->pluck('id')->values()->all(), 'notas' => 'Según ' . implode(', ', $numeros)]);
    }

    // Al registrar una compra vinculada, suma lo recibido por artículo y cierra la OC si no queda nada pendiente.
    // Con varias órdenes, lo recibido de cada artículo va llenando lo pendiente de una orden tras otra (la más vieja primero).
    public function marcarRecibido(Comprobante $compra): void
    {
        $ids = $compra->ordenes_compra ?: array_filter([$compra->orden_compra_id]);
        $ocs = OrdenCompra::whereIn('id', $ids)->orderBy('id')->get()->filter(fn($o) => $o->abierta());
        if ($ocs->isEmpty()) return;
        $resta = [];
        foreach ($compra->items as $it) if ($it->product_id) $resta[$it->product_id] = ($resta[$it->product_id] ?? 0) + (float) $it->cantidad;
        foreach ($ocs as $oc) {
            foreach ($oc->items()->get() as $ocItem) {
                $r = $resta[$ocItem->product_id] ?? 0; if ($r <= 0) continue;
                $toma = $ocs->count() > 1 ? min($r, max(0, $ocItem->pendiente())) : $r;
                $ocItem->update(['recibido' => round((float) $ocItem->recibido + $toma, 3)]);
                $resta[$ocItem->product_id] = round($r - $toma, 3);
            }
            $pendiente = $oc->items()->get()->sum(fn($i) => $i->pendiente());
            $oc->update(['estado' => $pendiente <= 0 ? 'recibida' : 'parcial']);
        }
    }
}
