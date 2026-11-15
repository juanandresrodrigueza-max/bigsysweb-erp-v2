<?php

namespace App\Services\Agentes;

use App\Models\Business;
use App\Models\OrdenCompra;
use App\Models\Propuesta;
use App\Models\User;
use App\Services\Compras\OrdenCompraService;
use App\Services\Compras\ReposicionService;

// Fase 107 · Reposición de noche: una orden de compra sugerida por proveedor con lo que está bajo mínimo o sin stock, con la plata que
// hace falta. Aprobar crea la orden en borrador (se revisa y se manda desde Comprar → Órdenes). Lo que no tiene proveedor va como aviso.
class ReposicionAgente implements Agente
{
    public function __construct(private ReposicionService $repo, private OrdenCompraService $ordenes) {}

    public function nombre(): string { return 'reposicion'; }

    public function proponer(Business $b): array
    {
        $s = $this->repo->sugerencias($b); $out = [];
        $abiertas = OrdenCompra::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('estado', ['borrador', 'enviada'])->pluck('contact_id')->flip();
        foreach ($s['grupos'] as $g) {
            $items = array_values(array_filter($g['items'], fn($i) => ($i['sugerido'] ?? 0) > 0));
            if (! $items) continue;
            if (! $g['proveedor_id']) {
                $out[] = ['clave' => 'sin-proveedor', 'prioridad' => 3, 'titulo' => count($items) . ' artículo(s) para reponer sin proveedor habitual', 'detalle' => 'Cargales el proveedor en la ficha y la próxima noche entran en una orden.', 'datos' => ['items' => $items], 'accion' => null];
                continue;
            }
            if (isset($abiertas[$g['proveedor_id']])) continue; // ya hay una orden abierta con ese proveedor: no la pisa
            $sinStock = count(array_filter($items, fn($i) => $i['stock'] <= 0));
            $out[] = ['clave' => "reponer-{$g['proveedor_id']}", 'importe' => $g['estimado'], 'prioridad' => $sinStock ? 1 : 2,
                'titulo' => "Pedirle a {$g['proveedor']}: " . count($items) . ' artículo(s) por ' . AgentesService::pesos($g['estimado']),
                'detalle' => ($sinStock ? "{$sinStock} sin stock. " : '') . 'Cantidades hasta el doble del mínimo' . (array_filter($items, fn($i) => $i['por_historia']) ? ' o según lo que se vendía por mes' : '') . '. Aprobar deja la orden en borrador para revisarla y mandarla.',
                'datos' => ['proveedor_id' => $g['proveedor_id'], 'proveedor' => $g['proveedor'], 'items' => $items],
                'accion' => ['tipo' => 'orden_compra', 'contact_id' => $g['proveedor_id'], 'items' => array_map(fn($i) => ['product_id' => $i['id'], 'cantidad' => $i['sugerido'], 'precio_unit' => $i['costo']], $items)]];
        }
        return $out;
    }

    public function ejecutar(Propuesta $p, User $u): string
    {
        $oc = $this->ordenes->guardar(['contact_id' => $p->accion['contact_id'], 'items' => $p->accion['items'], 'origen' => 'bigcito', 'notas' => 'Propuesta de Bigcito (reposición de noche)']);
        return "Orden de compra {$oc->numeroFormateado()} a {$oc->contact->name} en borrador por " . AgentesService::pesos($oc->total) . '. Revisala en Comprar → Órdenes y mandala.';
    }
}
