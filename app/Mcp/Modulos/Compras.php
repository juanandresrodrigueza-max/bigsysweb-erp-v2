<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\User;
use App\Services\Compras\OrdenCompraService;
use App\Services\Compras\PagoService;

// Compras y proveedores: buscar, deuda, compras del período, pedido sugerido; órdenes de compra y pagos.
class Compras
{
    public static function herramientas(): array
    {
        return [
            ['nombre' => 'buscar_proveedores', 'titulo' => 'Buscar proveedores', 'modulo' => 'proveedores', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Busca proveedores por nombre o CUIT. Devuelve saldo (lo que se les debe) y contacto.',
                'parametros' => ['texto' => ['type' => 'string']], 'requeridos' => ['texto'],
                'ejecutar' => function (User $u, array $a) {
                    $t = trim((string) $a['texto']); $like = \App\Support\Sql::like();
                    return ['proveedores' => Contact::suppliers()->where(fn($w) => $w->where('name', $like, "%{$t}%")->orWhere('cuit', $like, "%{$t}%"))->limit(15)->get()
                        ->map(fn($c) => ['id' => $c->id, 'nombre' => $c->name, 'cuit' => $c->cuit, 'saldo' => (float) $c->balance, 'telefono' => $c->phone ?: $c->mobile, 'email' => $c->email])->all()];
                }],
            ['nombre' => 'deuda_proveedores', 'titulo' => 'Deuda con proveedores', 'modulo' => 'proveedores', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Facturas de compra impagas agrupadas por proveedor, con lo que vence en los próximos días.',
                'parametros' => ['dias' => ['type' => 'integer', 'description' => 'Ventana de vencimientos (7 por defecto).']],
                'ejecutar' => function (User $u, array $a) {
                    $pend = Comprobante::with('contact:id,name')->pendientesPago()->get(); $lim = today()->addDays((int) ($a['dias'] ?? 7));
                    return ['proveedores' => $pend->groupBy('contact_id')->map(fn($g) => ['proveedor' => $g->first()->contact?->name, 'deuda' => round((float) $g->sum('saldo'), 2), 'vence_pronto' => round((float) $g->filter(fn($c) => ! $c->fecha_vto || $c->fecha_vto->lte($lim))->sum('saldo'), 2), 'facturas' => $g->count()])->sortByDesc('deuda')->values()->take(50)->all(),
                        'total' => round((float) $pend->sum('saldo'), 2)];
                }],
            ['nombre' => 'compras_periodo', 'titulo' => 'Compras del período', 'modulo' => 'proveedores', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Total comprado en un período y por proveedor.',
                'parametros' => Busca::PERIODO,
                'ejecutar' => function (User $u, array $a) {
                    [$d, $h, $txt] = Busca::periodo($a);
                    $c = Comprobante::with('contact:id,name')->compras()->emitidos()->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->get();
                    return ['periodo' => $txt, 'total' => round((float) $c->sum('total'), 2), 'comprobantes' => $c->count(), 'por_proveedor' => $c->groupBy('contact_id')->map(fn($g) => ['proveedor' => $g->first()->contact?->name, 'total' => round((float) $g->sum('total'), 2)])->sortByDesc('total')->values()->take(20)->all()];
                }],
            ['nombre' => 'sugerir_pedido', 'titulo' => 'Pedido sugerido', 'modulo' => 'proveedores', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Arma el pedido sugerido para un proveedor: lo que está bajo mínimo (modo faltantes) o lo que se vendió el mismo mes del año pasado (modo ventas).',
                'parametros' => ['proveedor' => ['type' => 'string'], 'modo' => ['type' => 'string', 'enum' => ['faltantes', 'ventas']]],
                'ejecutar' => function (User $u, array $a) {
                    $p = ! empty($a['proveedor']) ? Busca::proveedor($a['proveedor']) : null;
                    if (! empty($a['proveedor']) && ! $p) return ['error' => "No encuentro el proveedor \"{$a['proveedor']}\"."];
                    $r = app(OrdenCompraService::class)->sugerir(['contact_id' => $p?->id, 'modo' => $a['modo'] ?? 'faltantes']);
                    return ['proveedor' => $p?->name, 'items' => array_slice($r, 0, 80), 'total' => round(array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio_unit'], $r)), 2)];
                }],
            ['nombre' => 'crear_orden_compra', 'titulo' => 'Crear orden de compra', 'modulo' => 'proveedores', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Arma una orden de compra en borrador a un proveedor. Si no pasás artículos, usa el pedido sugerido de faltantes.',
                'parametros' => ['proveedor' => ['type' => 'string'], 'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['articulo' => ['type' => 'string'], 'cantidad' => ['type' => 'number'], 'precio' => ['type' => 'number']], 'required' => ['articulo', 'cantidad']]], 'notas' => ['type' => 'string']],
                'requeridos' => ['proveedor'],
                'proponer' => function (User $u, array $a) {
                    $p = Busca::proveedor($a['proveedor']); if (! $p) return ['error' => "No encuentro el proveedor \"{$a['proveedor']}\"."];
                    $items = [];
                    if (! empty($a['items'])) {
                        foreach ($a['items'] as $it) {
                            $art = Busca::articulo($it['articulo'] ?? ''); if (! $art) return ['error' => "No encuentro el artículo \"{$it['articulo']}\"."];
                            $items[] = ['product_id' => $art->id, 'descripcion' => $art->name, 'cantidad' => (float) $it['cantidad'], 'precio_unit' => (float) ($it['precio'] ?? ($art->precio_compra ?: $art->cost))];
                        }
                    } else {
                        $items = array_map(fn($i) => array_intersect_key($i, array_flip(['product_id', 'descripcion', 'cantidad', 'precio_unit'])), app(OrdenCompraService::class)->sugerir(['contact_id' => $p->id, 'modo' => 'faltantes']));
                        if (! $items) return ['error' => "No hay faltantes de {$p->name}. Decime qué artículos pedir."];
                    }
                    $total = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio_unit'], $items));
                    return ['titulo' => "Orden de compra a {$p->name} por " . Busca::pesos($total), 'detalle' => collect($items)->take(15)->map(fn($i) => "{$i['cantidad']} × {$i['descripcion']}")->implode("\n") . (count($items) > 15 ? "\n… y " . (count($items) - 15) . ' más' : ''),
                        'datos' => ['contact_id' => $p->id, 'items' => $items, 'notas' => $a['notas'] ?? null, 'origen' => 'ia']];
                },
                'confirmar' => function (User $u, array $d) {
                    $oc = app(OrdenCompraService::class)->guardar($d);
                    return ['texto' => "Orden de compra {$oc->numeroFormateado()} creada en borrador.", 'orden_id' => $oc->id, 'url' => Busca::url("/proveedores/ordenes/{$oc->id}")];
                }],
            ['nombre' => 'registrar_pago_proveedor', 'titulo' => 'Pagar a un proveedor', 'modulo' => 'proveedores', 'permiso' => 'crear', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Registra un pago a un proveedor desde una cuenta (caja, banco) y lo imputa a sus facturas más viejas.',
                'parametros' => ['proveedor' => ['type' => 'string'], 'monto' => ['type' => 'number'], 'medio' => ['type' => 'string', 'enum' => ['efectivo', 'transferencia']], 'cuenta' => ['type' => 'string', 'description' => 'Caja o banco de donde sale.']],
                'requeridos' => ['proveedor', 'monto'],
                'proponer' => function (User $u, array $a) {
                    $p = Busca::proveedor($a['proveedor']); if (! $p) return ['error' => "No encuentro el proveedor \"{$a['proveedor']}\"."];
                    $monto = round((float) $a['monto'], 2); if ($monto <= 0) return ['error' => 'El importe tiene que ser mayor a cero.'];
                    $medio = $a['medio'] ?? 'transferencia';
                    $cta = Busca::cuenta($a['cuenta'] ?? '', $medio === 'efectivo' ? 'caja' : 'banco'); if (! $cta) return ['error' => 'No encuentro la cuenta de donde sale el pago.'];
                    $imp = []; $resto = $monto;
                    foreach (Comprobante::pendientesPago()->where('contact_id', $p->id)->orderBy('fecha')->get() as $c) { if ($resto <= 0) break; $m = min($resto, (float) $c->saldo); $imp[] = ['comprobante_id' => $c->id, 'monto' => round($m, 2), 'numero' => $c->numeroFormateado()]; $resto -= $m; }
                    return ['titulo' => "Pagar " . Busca::pesos($monto) . " a {$p->name} desde {$cta->nombre}", 'detalle' => $imp ? 'Cancela: ' . collect($imp)->map(fn($i) => "{$i['numero']} (" . Busca::pesos($i['monto']) . ')')->implode(', ') . ($resto > 0.005 ? ' · ' . Busca::pesos($resto) . ' a cuenta' : '') : 'Queda a cuenta (no hay facturas pendientes).',
                        'datos' => ['contact_id' => $p->id, 'medios' => [['medio' => $medio, 'monto' => $monto, 'cuenta_fondos_id' => $cta->id]], 'imputaciones' => array_map(fn($i) => ['comprobante_id' => $i['comprobante_id'], 'monto' => $i['monto']], $imp), 'notas' => 'Registrado con IA']];
                },
                'confirmar' => function (User $u, array $d) {
                    $pago = app(PagoService::class)->registrar(Contact::suppliers()->findOrFail($d['contact_id']), $d);
                    return ['texto' => 'Pago registrado (orden de pago ' . $pago->numero . ').', 'pago_id' => $pago->id];
                }],
        ];
    }
}
