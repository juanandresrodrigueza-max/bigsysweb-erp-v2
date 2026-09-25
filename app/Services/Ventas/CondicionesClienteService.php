<?php

namespace App\Services\Ventas;

use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\PrecioPactado;
use App\Models\Product;
use App\Models\Rubro;

// Qué precio y qué descuento le corresponden a un cliente por artículo.
// Orden: pactado del artículo > descuento del rubro (o de un rubro padre) > lista y descuento del cliente.
class CondicionesClienteService
{
    // Todas las condiciones vigentes del cliente, listas para aplicar en el formulario.
    // ['articulos' => [product_id => ['precio', 'descuento', 'origen']], 'rubros' => [rubro_id => descuento], 'lista', 'descuento']
    public function paraCliente(Contact $c): array
    {
        $pactos = PrecioPactado::vigentes()->where('contact_id', $c->id)->get();
        $rubros = [];
        // El descuento de un rubro alcanza a sus subrubros; se procesa de padres a hijos para que el más específico gane.
        $porRubro = $pactos->whereNotNull('rubro_id')->whereNull('product_id')->sortBy(fn($p) => $p->rubro?->nivel() ?? 0);
        foreach ($porRubro as $p) {
            foreach (Rubro::conDescendientes($p->rubro_id) as $id) $rubros[$id] = (float) $p->descuento;
        }

        return [
            'lista' => (int) ($c->lista_precios ?: 1),
            'lista_desc' => app(DescuentosListaService::class)->paraLista((int) ($c->lista_precios ?: 1)),
            'descuento' => (float) $c->descuento,
            'rubros' => $rubros,
            'articulos' => $pactos->whereNotNull('product_id')->mapWithKeys(fn($p) => [$p->product_id => [
                'precio' => $p->precio !== null ? (float) $p->precio : null,
                'descuento' => $p->descuento !== null ? (float) $p->descuento : null,
                'origen' => $p->origen,
            ]])->all(),
        ];
    }

    // Precio y descuento para un artículo puntual. 'origen': pactado | ultimo | rubro | lista.
    public function para(Contact $c, Product $p, ?array $cond = null): array
    {
        $cond ??= $this->paraCliente($c);
        $a = $cond['articulos'][$p->id] ?? null;
        $precio = $a['precio'] ?? null;
        // Un precio pactado es final: solo lleva el descuento cargado en la misma condición, nunca el del rubro ni el general.
        $desc = $a['descuento'] ?? ($precio !== null ? 0.0 : null);
        $origen = $a ? ($a['origen'] === 'ultimo' ? 'ultimo' : 'pactado') : null;
        if ($desc === null && $p->rubro_id && isset($cond['rubros'][$p->rubro_id])) { $desc = $cond['rubros'][$p->rubro_id]; $origen ??= 'rubro'; }
        return [
            'precio' => $precio ?? $p->precioLista($cond['lista']),
            'descuento' => $desc ?? $cond['descuento'],
            'origen' => $origen ?? 'lista',
        ];
    }

    // Cliente con "recordar precio": al emitir una factura, el precio de cada artículo queda como su último precio.
    // Nunca pisa un precio pactado a mano.
    public function recordarUltimos(Comprobante $c): void
    {
        $contact = $c->contact;
        if (! $contact || ! $contact->recordar_precio || ! $c->esFactura()) return;
        foreach ($c->items as $it) {
            if (! $it->product_id || (float) $it->precio_unit <= 0) continue;
            $p = PrecioPactado::firstOrNew(['contact_id' => $contact->id, 'product_id' => $it->product_id, 'rubro_id' => null], ['business_id' => $c->business_id]);
            if ($p->exists && $p->origen === 'manual') continue;
            $p->fill(['precio' => $it->precio_unit, 'descuento' => $it->descuento, 'origen' => 'ultimo', 'user_id' => $c->user_id])->save();
        }
    }
}
