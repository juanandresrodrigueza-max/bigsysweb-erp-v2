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
            // Fase 97: "¿qué órdenes esperan autorización?", "autorizá la orden 12", "¿cómo vienen las importaciones?"
            ['nombre' => 'ordenes_por_autorizar', 'titulo' => 'Órdenes de compra que esperan autorización', 'modulo' => 'proveedores', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Las órdenes de compra que superan el monto configurado y esperan que alguien las autorice: número, proveedor, quién la pidió, importe y desde cuándo. Y desde qué monto se pide autorización.',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    $svc = app(\App\Services\Compras\AutorizacionComprasService::class); $cfg = $svc->config($u->business);
                    $p = $svc->pendientes($u->business)->map(fn($o) => ['id' => $o->id, 'numero' => $o->numeroFormateado(), 'proveedor' => $o->contact?->name, 'pidio' => $o->user?->name, 'total' => (float) $o->total, 'fecha' => $o->fecha->format('d/m/Y'), 'url' => Busca::url("/proveedores/ordenes/{$o->id}")])->values()->all();
                    return ['autoriza_desde' => $cfg['autoriza_desde'], 'podes_autorizar' => $svc->esAutorizador($u), 'pendientes' => $p, 'resumen' => ! $cfg['autoriza_desde'] ? 'Las órdenes salen sin autorización (se configura en Órdenes de compra → engranaje).' : (count($p) ? count($p) . ' orden(es) esperan autorización.' : 'Ninguna orden espera autorización.'), 'url' => Busca::url('/proveedores/ordenes?estado=borrador')];
                }],
            ['nombre' => 'autorizar_orden', 'titulo' => 'Autorizar o rechazar una orden de compra', 'modulo' => 'proveedores', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Autoriza (y manda al proveedor) o rechaza una orden de compra que espera autorización. Sólo quien está en la lista de autorizadores o el dueño.',
                'parametros' => ['orden' => ['type' => 'string', 'description' => 'Número de la orden, por ejemplo 12 u OC-000012'], 'decision' => ['type' => 'string', 'enum' => ['autorizar', 'rechazar']], 'nota' => ['type' => 'string', 'description' => 'Obligatoria si se rechaza']],
                'requeridos' => ['orden'],
                'proponer' => function (User $u, array $a) {
                    $n = (int) preg_replace('/\D/', '', (string) $a['orden']); $oc = \App\Models\OrdenCompra::where('numero', $n)->first();
                    if (! $oc) return ['error' => "No encuentro la orden {$a['orden']}."];
                    if ($oc->autorizacion !== 'pendiente' || $oc->estado !== 'borrador') return ['error' => "La orden {$oc->numeroFormateado()} no está esperando autorización."];
                    if (! app(\App\Services\Compras\AutorizacionComprasService::class)->esAutorizador($u)) return ['error' => 'No estás en la lista de quienes autorizan compras.'];
                    $rech = ($a['decision'] ?? 'autorizar') === 'rechazar'; if ($rech && empty($a['nota'])) return ['error' => 'Para rechazarla decime el motivo.'];
                    return ['titulo' => ($rech ? 'Rechazar ' : 'Autorizar y enviar ') . "la orden {$oc->numeroFormateado()} a {$oc->contact?->name} por $ " . number_format((float) $oc->total, 0, ',', '.'), 'detalle' => ($oc->user?->name ? "La pidió {$oc->user->name}. " : '') . ($a['nota'] ?? ''), 'datos' => ['id' => $oc->id, 'rechazar' => $rech, 'nota' => $a['nota'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $svc = app(\App\Services\Compras\AutorizacionComprasService::class); $oc = \App\Models\OrdenCompra::findOrFail($d['id']);
                    $oc = $d['rechazar'] ? $svc->rechazar($oc, $u, (string) $d['nota']) : $svc->autorizar($oc, $u, $d['nota'] ?? null);
                    return ['texto' => $d['rechazar'] ? "Orden {$oc->numeroFormateado()} rechazada; quien la pidió ya lo sabe." : "Orden {$oc->numeroFormateado()} autorizada y enviada a {$oc->contact?->name}.", 'url' => Busca::url("/proveedores/ordenes/{$oc->id}")];
                }],
            ['nombre' => 'importaciones_en_curso', 'titulo' => 'Importaciones: despachos y costo nacionalizado', 'modulo' => 'proveedores', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Los despachos de importación en curso y los nacionalizados del año, con FOB, gastos y costo nacionalizado. Con "despacho" devuelve el detalle de uno: cada artículo con su costo por unidad.',
                'parametros' => ['despacho' => ['type' => 'string', 'description' => 'Número IMP-00001 o número de despacho de aduana']],
                'ejecutar' => function (User $u, array $a) {
                    $svc = app(\App\Services\Compras\ImportacionesService::class);
                    if (! empty($a['despacho'])) { $n = preg_replace('/\D/', '', (string) $a['despacho']); $n = ($n !== '' && strlen($n) <= 9) ? (int) $n : null; $d = \App\Models\DespachoImportacion::where(fn($q) => $q->where('despacho', $a['despacho'])->when($n, fn($q2) => $q2->orWhere('numero', $n)))->first(); if (! $d) return ['error' => "No encuentro el despacho {$a['despacho']}."]; return $svc->arr($d) + ['url' => Busca::url("/proveedores/importaciones/{$d->id}")]; }
                    $lista = \App\Models\DespachoImportacion::with('contact:id,name')->whereIn('estado', ['borrador', 'nacionalizado'])->orderByDesc('fecha')->limit(20)->get()->map(fn($d) => ['numero' => $d->numeroFormateado(), 'despacho' => $d->despacho, 'proveedor' => $d->contact?->name, 'estado' => \App\Models\DespachoImportacion::ESTADOS[$d->estado], 'fob' => (float) $d->fob_total, 'gastos' => (float) $d->gastos_total, 'costo' => (float) $d->costo_total, 'coeficiente' => (float) $d->fob_total > 0 ? round((float) $d->costo_total / (float) $d->fob_total, 3) : null])->all();
                    return $svc->resumen($u->business) + ['despachos' => $lista, 'url' => Busca::url('/proveedores/importaciones')];
                }],
            // Fase 120.3: "¿llegó alguna factura?", "¿qué facturas tengo para cargar?"
            ['nombre' => 'facturas_por_revisar', 'titulo' => 'Facturas que llegaron a la casilla', 'modulo' => 'proveedores', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Las facturas de compra que llegaron por mail a la casilla de la empresa (o se subieron de a varias) y ya están leídas, esperando que alguien las revise y registre: proveedor, número, total y el link para revisarla. También dice a qué dirección de mail hay que mandarlas. Nunca se registran solas.',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    $r = app(\App\Services\Compras\BandejaFacturasService::class)->resumen($u->business, 20);
                    $p = array_values(array_map(fn($e) => ['proveedor' => $e['proveedor'], 'numero' => $e['numero'], 'total' => $e['total'], 'llego' => $e['cuando'], 'por' => $e['origen'] === 'mail' ? 'mail' : 'subida', 'nota' => $e['detalle'], 'url' => Busca::url("/proveedores/compras/nueva?borrador={$e['borrador_id']}")], array_filter($r['lista'], fn($e) => $e['estado'] === 'lista')));
                    $otros = array_values(array_map(fn($e) => ['archivo' => $e['archivo'], 'estado' => $e['estado_txt'], 'nota' => $e['detalle'], 'llego' => $e['cuando']], array_filter($r['lista'], fn($e) => in_array($e['estado'], ['sin_leer', 'duplicada'], true))));
                    return ['direccion_para_mandar_facturas' => $r['direccion'], 'para_revisar' => $p, 'con_problema' => $otros, 'url' => Busca::url('/proveedores/compras'),
                        'resumen' => count($p) ? count($p) . ' factura(s) esperan revisión en Compras.' : 'No hay facturas esperando revisión.' . ($r['direccion'] ? " Se mandan a {$r['direccion']}." : ' La casilla por mail todavía no está activada; se pueden subir varias juntas desde Compras.')];
                }],
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
            // Fase 62: los mensajes de varios proveedores → qué conviene comprar a cada uno.
            ['nombre' => 'comparar_cotizaciones', 'titulo' => 'Comparar cotizaciones', 'modulo' => 'proveedores', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Compara cotizaciones de varios proveedores a partir de los mensajes o listas que mandaron (texto tal cual, uno por proveedor): quién gana cada artículo, cuánto sale todo en uno solo y cuánto el mixto. Después, con crear_orden_compra se arma una orden por proveedor.',
                'parametros' => ['textos' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Un texto por proveedor, tal cual lo mandó.']], 'requeridos' => ['textos'],
                'ejecutar' => function (User $u, array $a) {
                    $textos = array_values(array_filter(array_map('strval', (array) ($a['textos'] ?? [])), fn($t) => trim($t) !== ''));
                    if (count($textos) < 1) return ['error' => 'Pasame el texto de al menos una cotización.'];
                    $r = app(\App\Services\Compras\CotizacionesService::class)->leer($u->business, array_map(fn($t, $i) => ['texto' => $t, 'nombre' => 'Mensaje ' . ($i + 1)], $textos, array_keys($textos)));
                    if (! $r['ok']) return ['error' => $r['aviso'] ?? 'No pude leer las cotizaciones.', 'avisos' => $r['avisos'] ?? []];
                    $c = $r['comparacion'];
                    return ['proveedores' => count($c['proveedores']), 'resumen' => $c['texto'], 'avisos' => $r['avisos'],
                        'filas' => collect($c['filas'])->map(fn($f) => ['articulo' => $f['descripcion'], 'en_stock' => (bool) $f['product_id'], 'cantidad' => $f['cantidad'], 'precios' => collect($f['precios'])->map(fn($p, $i) => [$c['proveedores'][$i]['proveedor'] => $p])->collapse()->all(), 'gana' => $f['gana'] === null ? null : $c['proveedores'][$f['gana']]['proveedor'], 'diferencia_pct' => $f['diferencia_pct']])->all(),
                        'totales' => collect($c['resumen'])->map(fn($x) => ['proveedor' => $x['proveedor'], 'todo_ahi' => $x['cotiza_todo'] ? $x['total_todo'] : null, 'renglones_que_gana' => $x['renglones_que_gana'], 'condiciones' => $x['condiciones'], 'tiene_ficha' => (bool) $x['proveedor_id']])->all(),
                        'mixto' => $c['mixto'], 'ahorro_mixto' => $c['ahorro_mixto'],
                        'reparto' => collect($c['reparto'])->map(fn($x) => ['proveedor' => $x['proveedor'], 'total' => $x['total'], 'items' => collect($x['items'])->map(fn($i) => "{$i['cantidad']} × {$i['descripcion']} a " . Busca::pesos($i['precio_unit']))->all()])->all(),
                        'nota' => 'Para pedir, usá crear_orden_compra una vez por proveedor con los items del reparto (artículo, cantidad, precio).'];
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
