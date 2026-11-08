<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Ventas\CondicionesClienteService;

// Ventas: cuánto se vendió, buscar y ver comprobantes, presupuestos, facturas (borrador, emisión ante ARCA, envío, anulación).
class Ventas
{
    public const GRUPOS = ['factura' => ['FA', 'FB', 'FC', 'FE'], 'nota_credito' => ['NCA', 'NCB', 'NCC', 'NCE'], 'nota_debito' => ['NDA', 'NDB', 'NDC', 'NDE'], 'presupuesto' => ['PRE'], 'remito' => ['REM']];
    private const ITEMS = ['type' => 'array', 'description' => 'Renglones: artículo (nombre, código o id), cantidad y, opcional, precio unitario neto.', 'items' => ['type' => 'object', 'properties' => ['articulo' => ['type' => 'string'], 'cantidad' => ['type' => 'number'], 'precio' => ['type' => 'number']], 'required' => ['articulo', 'cantidad']]];

    public static function herramientas(): array
    {
        return [
            ['nombre' => 'ventas_periodo', 'titulo' => 'Ventas de un período', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Total vendido en un período (facturas y notas de débito menos notas de crédito), cantidad de facturas, ticket promedio y comparación con el período anterior.',
                'parametros' => Busca::PERIODO,
                'ejecutar' => function (User $u, array $a) {
                    [$d, $h, $l] = Busca::periodo($a);
                    $tot = fn($d, $h) => (float) Comprobante::ventas()->emitidos()->whereIn('tipo', array_merge(self::GRUPOS['factura'], self::GRUPOS['nota_debito'], self::GRUPOS['nota_credito']))->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->selectRaw("COALESCE(SUM(CASE WHEN tipo IN ('NCA','NCB','NCC','NCE') THEN -total ELSE total END),0) s")->value('s');
                    $n = Comprobante::ventas()->emitidos()->facturas()->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->count();
                    $dias = $d->diffInDays($h) + 1; $ant = $tot($d->copy()->subDays($dias), $d->copy()->subDay());
                    $t = $tot($d, $h);
                    return ['periodo' => $l, 'desde' => $d->toDateString(), 'hasta' => $h->toDateString(), 'total' => round($t, 2), 'facturas' => $n, 'ticket_promedio' => $n ? round($t / $n, 2) : 0, 'periodo_anterior' => round($ant, 2), 'variacion_pct' => $ant > 0 ? round(($t - $ant) / $ant * 100, 1) : null];
                }],
            ['nombre' => 'buscar_comprobantes', 'titulo' => 'Buscar comprobantes', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Busca facturas, notas, presupuestos o remitos de venta por cliente, tipo, estado y fechas.',
                'parametros' => ['cliente' => ['type' => 'string'], 'tipo' => ['type' => 'string', 'enum' => array_keys(self::GRUPOS)], 'estado' => ['type' => 'string', 'enum' => ['borrador', 'emitido', 'anulado', 'pendiente_cobro']], 'cantidad' => ['type' => 'integer']] + Busca::PERIODO,
                'ejecutar' => function (User $u, array $a) {
                    $q = Comprobante::ventas()->with('contact:id,name');
                    if (! empty($a['cliente'])) { $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."]; $q->where('contact_id', $c->id); }
                    if (! empty($a['tipo'])) $q->whereIn('tipo', self::GRUPOS[$a['tipo']] ?? []);
                    if (($a['estado'] ?? null) === 'pendiente_cobro') $q->pendientesCobro(); elseif (! empty($a['estado'])) $q->where('estado', $a['estado']);
                    if (! empty($a['periodo']) || ! empty($a['desde'])) { [$d, $h] = Busca::periodo($a); $q->whereBetween('fecha', [$d->toDateString(), $h->toDateString()]); }
                    return ['comprobantes' => $q->orderByDesc('fecha')->orderByDesc('id')->limit(min(100, (int) ($a['cantidad'] ?? 20)))->get()->map(fn($c) => Busca::comp($c))->all()];
                }],
            ['nombre' => 'ver_comprobante', 'titulo' => 'Ver un comprobante', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Detalle de un comprobante de venta: renglones, IVA, percepciones, total, saldo, CAE y link al PDF.',
                'parametros' => ['comprobante' => ['type' => 'string', 'description' => 'Id o número (0001-00001234).']], 'requeridos' => ['comprobante'],
                'ejecutar' => function (User $u, array $a) {
                    $c = Busca::comprobante($a['comprobante']); if (! $c) return ['error' => "No encuentro el comprobante {$a['comprobante']}."];
                    return Busca::comp($c) + ['condicion' => $c->condicion, 'neto' => (float) $c->neto, 'iva' => (float) $c->iva, 'percepciones' => (float) $c->percepciones, 'pdf' => Busca::url("/comprobantes/{$c->id}/imprimir"),
                        'items' => $c->items->map(fn($i) => ['descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'iva' => (float) $i->alicuota_iva, 'total' => (float) $i->total, 'serie' => $i->serie])->all()];
                }],
            ['nombre' => 'presupuestos_abiertos', 'titulo' => 'Presupuestos sin respuesta', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Presupuestos emitidos que todavía no se facturaron, del más viejo al más nuevo.',
                'parametros' => ['cantidad' => ['type' => 'integer']],
                'ejecutar' => fn(User $u, array $a) => ['presupuestos' => Comprobante::ventas()->emitidos()->where('tipo', 'PRE')->whereDoesntHave('derivados')->with('contact:id,name')->orderBy('fecha')->limit(min(100, (int) ($a['cantidad'] ?? 20)))->get()->map(fn($c) => Busca::comp($c) + ['dias' => (int) $c->fecha->diffInDays(today())])->all()]],
            // Fase 72: el embudo y las tareas del CRM, por voz desde el ERP.
            ['nombre' => 'oportunidades_crm', 'titulo' => 'Oportunidades abiertas en el CRM', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'El embudo de ventas del CRM: oportunidades abiertas con monto, etapa, vendedor y vencimiento, y el total. Opcionalmente las de un cliente o de un vendedor. "¿Qué tenemos por cerrar?", "¿qué tiene Juan en el embudo?". Sólo con el CRM conectado.',
                'parametros' => ['cliente' => ['type' => 'string'], 'vendedor' => ['type' => 'string', 'description' => 'Nombre del vendedor para filtrar']],
                'ejecutar' => function (User $u, array $a) {
                    if (! \App\Services\Integraciones\CrmService::activo($u->business)) return ['error' => 'Esta empresa no tiene el CRM conectado (Configuración → CRM).'];
                    $c = null; if (! empty($a['cliente'])) { $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."]; }
                    $o = \App\Services\Integraciones\CrmSyncService::oportunidades($u->business, $c);
                    if ($o === null) return ['error' => 'El CRM no respondió. Probá en un rato (estado_crm dice cómo está la conexión).'];
                    if (! empty($a['vendedor'])) { $v = mb_strtolower($a['vendedor']); $o = array_values(array_filter($o, fn($x) => str_contains(mb_strtolower((string) $x['vendedor']), $v))); }
                    $o = array_map(fn($x) => ['url' => Busca::url($x['url'])] + $x, $o);
                    return ['cantidad' => count($o), 'total' => round(array_sum(array_column($o, 'monto')), 2), 'por_etapa' => collect($o)->groupBy(fn($x) => $x['etapa'] ?? 'sin etapa')->map(fn($g) => ['cantidad' => $g->count(), 'total' => round($g->sum('monto'), 2)])->all(),
                        'oportunidades' => $o, 'nota' => 'Montos como los cargó el vendedor en el CRM. Para cotizar con stock y precio de lista del ERP, el vendedor presupuesta desde el CRM.'];
                }],
            ['nombre' => 'pendientes_crm', 'titulo' => 'Tareas pendientes del CRM', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Tareas del CRM vencidas o para hoy (llamados, seguimientos, cobranzas) con cliente, vendedor y prioridad. "¿Qué tiene pendiente ventas?". Sólo con el CRM conectado.',
                'parametros' => ['vendedor' => ['type' => 'string'], 'solo_vencidas' => ['type' => 'boolean']],
                'ejecutar' => function (User $u, array $a) {
                    if (! \App\Services\Integraciones\CrmService::activo($u->business)) return ['error' => 'Esta empresa no tiene el CRM conectado (Configuración → CRM).'];
                    $t = \App\Services\Integraciones\CrmSyncService::tareasPendientes($u->business);
                    if ($t === null) return ['error' => 'El CRM no respondió. Probá en un rato (estado_crm dice cómo está la conexión).'];
                    if (! empty($a['vendedor'])) { $v = mb_strtolower($a['vendedor']); $t = array_values(array_filter($t, fn($x) => str_contains(mb_strtolower((string) $x['vendedor']), $v))); }
                    if (! empty($a['solo_vencidas'])) $t = array_values(array_filter($t, fn($x) => $x['vencida']));
                    return ['cantidad' => count($t), 'vencidas' => count(array_filter($t, fn($x) => $x['vencida'])), 'tareas' => array_map(fn($x) => ['url' => Busca::url($x['url'])] + $x, $t)];
                }],
            ['nombre' => 'mas_vendidos', 'titulo' => 'Más vendidos', 'modulo' => 'estadisticas', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Ranking de artículos más vendidos de un período, por importe y cantidad.',
                'parametros' => Busca::PERIODO + ['cantidad' => ['type' => 'integer']],
                'ejecutar' => function (User $u, array $a) {
                    [$d, $h, $l] = Busca::periodo($a);
                    $r = ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', $u->business_id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')
                        ->whereIn('comprobantes.tipo', self::GRUPOS['factura'])->whereBetween('comprobantes.fecha', [$d->toDateString(), $h->toDateString()])->whereNotNull('comprobante_items.product_id')
                        ->selectRaw('comprobante_items.product_id, SUM(comprobante_items.cantidad) as cant, SUM(comprobante_items.neto) as neto')->groupBy('comprobante_items.product_id')->orderByDesc('neto')->limit(min(50, (int) ($a['cantidad'] ?? 10)))->get();
                    $p = Product::whereIn('id', $r->pluck('product_id'))->pluck('name', 'id');
                    return ['periodo' => $l, 'articulos' => $r->map(fn($x) => ['articulo' => $p[$x->product_id] ?? '?', 'cantidad' => round((float) $x->cant, 3), 'neto' => round((float) $x->neto, 2)])->all()];
                }],
            // Fase 63: qué ofrecer con lo que el cliente ya lleva.
            ['nombre' => 'que_ofrecer_con', 'titulo' => 'Qué ofrecer con esto', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Venta cruzada: con los artículos que el cliente ya lleva (y el cliente, si se sabe), qué conviene ofrecerle: lo que se suele llevar junto, lo que ese cliente lleva seguido, o lo que complementa según la IA.',
                'parametros' => ['articulos' => ['type' => 'array', 'items' => ['type' => 'string']], 'cliente' => ['type' => 'string']], 'requeridos' => ['articulos'],
                'ejecutar' => function (User $u, array $a) {
                    $ids = []; $noEncontrados = [];
                    foreach ((array) $a['articulos'] as $t) { if ($p = Busca::articulo($t)) $ids[] = $p->id; else $noEncontrados[] = $t; }
                    if (! $ids) return ['error' => 'No encuentro esos artículos: ' . implode(', ', $noEncontrados)];
                    $c = ! empty($a['cliente']) ? Busca::cliente($a['cliente']) : null;
                    $s = app(\App\Services\Ventas\VentaCruzadaService::class)->sugerir($u->business, $ids, $c?->id);
                    return ['sugeridos' => array_map(fn($x) => ['articulo' => $x['nombre'], 'precio' => $x['precio'], 'motivo' => $x['motivo'], 'url' => Busca::url("/stock?buscar=" . urlencode($x['nombre']))], $s), 'no_encontrados' => $noEncontrados,
                        'nota' => $s ? 'Ofrecelo como lo haría un buen vendedor: corto, con el motivo.' : 'Todavía no hay historia de ventas para sugerir algo (y sin clave de IA no se puede adivinar).'];
                }],
            ['nombre' => 'crear_presupuesto', 'titulo' => 'Armar un presupuesto', 'modulo' => 'comprobantes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Arma un presupuesto en borrador para un cliente con los precios que le corresponden (pactado, lista o descuentos).',
                'parametros' => ['cliente' => ['type' => 'string'], 'items' => self::ITEMS, 'notas' => ['type' => 'string']], 'requeridos' => ['cliente', 'items'],
                'proponer' => fn(User $u, array $a) => self::proponerComprobante($u, $a, 'PRE'),
                'confirmar' => fn(User $u, array $d) => self::guardar($u, $d)],
            ['nombre' => 'crear_factura', 'titulo' => 'Preparar una factura', 'modulo' => 'comprobantes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Prepara una factura en borrador (la letra A/B/C sale sola), con renglones o desde un presupuesto. Para mandarla a ARCA después se usa emitir_comprobante.',
                'parametros' => ['cliente' => ['type' => 'string', 'description' => 'Vacío = consumidor final.'], 'items' => self::ITEMS, 'presupuesto' => ['type' => 'string', 'description' => 'Número o id de un presupuesto a facturar.'], 'condicion' => ['type' => 'string', 'enum' => ['contado', 'cta_cte']], 'notas' => ['type' => 'string']],
                'proponer' => fn(User $u, array $a) => self::proponerComprobante($u, $a, 'FX'),
                'confirmar' => fn(User $u, array $d) => self::guardar($u, $d)],
            ['nombre' => 'emitir_comprobante', 'titulo' => 'Emitir ante ARCA', 'modulo' => 'comprobantes', 'permiso' => 'crear', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Emite un comprobante en borrador: le pide el CAE a ARCA, mueve el stock y la cuenta corriente. No se puede deshacer (solo con nota de crédito).',
                'parametros' => ['comprobante' => ['type' => 'string']], 'requeridos' => ['comprobante'],
                'proponer' => function (User $u, array $a) {
                    $c = Busca::comprobante($a['comprobante']); if (! $c) return ['error' => "No encuentro el comprobante {$a['comprobante']}."];
                    if ($c->estado !== 'borrador') return ['error' => "{$c->nombreTipo()} {$c->numeroFormateado()} ya está {$c->estado}."];
                    return ['titulo' => "Emitir {$c->nombreTipo()} a " . ($c->contact?->name ?? 'Consumidor final') . ' por ' . Busca::pesos($c->total), 'detalle' => $c->items->map(fn($i) => (float) $i->cantidad . " × {$i->descripcion}")->implode(', ') . '. Se pide el CAE a ARCA y no se puede deshacer.', 'datos' => ['id' => $c->id]];
                },
                'confirmar' => function (User $u, array $d) {
                    $c = app(ComprobanteService::class)->emitir(Comprobante::findOrFail($d['id']));
                    return ['texto' => "{$c->nombreTipo()} {$c->numeroFormateado()} emitida" . ($c->cae ? " con CAE {$c->cae}" : ($c->afip_estado === 'simulado' ? ' (simulada: falta el certificado de ARCA)' : '')) . '.', 'comprobante' => Busca::comp($c), 'pdf' => Busca::url("/comprobantes/{$c->id}/imprimir")];
                }],
            ['nombre' => 'enviar_comprobante', 'titulo' => 'Enviar un comprobante', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'accion',
                'descripcion' => 'Manda un comprobante con su PDF por mail o WhatsApp. Si no se indica el destino, va al contacto del cliente que recibe comprobantes.',
                'parametros' => ['comprobante' => ['type' => 'string'], 'canal' => ['type' => 'string', 'enum' => ['mail', 'whatsapp']], 'destino' => ['type' => 'string', 'description' => 'Email o teléfono (opcional).'], 'mensaje' => ['type' => 'string']], 'requeridos' => ['comprobante'],
                'proponer' => function (User $u, array $a) {
                    $c = Busca::comprobante($a['comprobante']); if (! $c) return ['error' => "No encuentro el comprobante {$a['comprobante']}."];
                    $canal = ($a['canal'] ?? 'mail') === 'whatsapp' ? 'whatsapp' : 'mail';
                    $p = $c->contact?->personas->firstWhere('recibe_comprobantes', true);
                    $dest = ($a['destino'] ?? null) ?: ($canal === 'mail' ? ($p?->email ?: $c->contact?->email) : ($p?->telefono ?: $c->contact?->mobile ?: $c->contact?->phone));
                    if (! $dest) return ['error' => 'No hay ' . ($canal === 'mail' ? 'email' : 'teléfono') . ' para mandarlo. Decime a dónde.'];
                    return ['titulo' => "Mandar {$c->nombreTipo()} {$c->numeroFormateado()} por {$canal} a {$dest}", 'detalle' => 'Con el PDF adjunto.', 'datos' => ['id' => $c->id, 'canal' => $canal, 'destino' => $dest, 'mensaje' => $a['mensaje'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $e = app(\App\Services\Envios\EnvioService::class)->enviar(Comprobante::findOrFail($d['id']), $d['canal'], $d['destino'], 'comprobante', $d['mensaje'] ?? null);
                    return ['texto' => $e->estado === 'error' ? "No se pudo enviar: {$e->error}" : ($e->link ? 'Mensaje listo: abrí el link para mandarlo por WhatsApp.' : 'Enviado.'), 'url' => $e->link];
                }],
            ['nombre' => 'anular_comprobante', 'titulo' => 'Anular un comprobante', 'modulo' => 'comprobantes', 'permiso' => 'anular', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Anula un comprobante: un presupuesto, remito o borrador se anula directo; una factura emitida genera su nota de crédito.',
                'parametros' => ['comprobante' => ['type' => 'string'], 'motivo' => ['type' => 'string']], 'requeridos' => ['comprobante', 'motivo'],
                'proponer' => function (User $u, array $a) {
                    $c = Busca::comprobante($a['comprobante']); if (! $c) return ['error' => "No encuentro el comprobante {$a['comprobante']}."];
                    if ($c->estado === 'anulado') return ['error' => 'Ya está anulado.'];
                    return ['titulo' => "Anular {$c->nombreTipo()} {$c->numeroFormateado()} de " . ($c->contact?->name ?? 'Consumidor final') . ' por ' . Busca::pesos($c->total), 'detalle' => "Motivo: {$a['motivo']}. Devuelve el stock y ajusta la cuenta corriente.", 'datos' => ['id' => $c->id, 'motivo' => $a['motivo']]];
                },
                'confirmar' => function (User $u, array $d) {
                    $c = app(ComprobanteService::class)->anular(Comprobante::findOrFail($d['id']), $d['motivo']);
                    return ['texto' => "{$c->nombreTipo()} {$c->numeroFormateado()} anulada.", 'comprobante' => Busca::comp($c->fresh())];
                }],
        ];
    }

    // Arma los renglones con el precio que le corresponde al cliente y la propuesta del comprobante.
    private static function proponerComprobante(User $u, array $a, string $tipo): array
    {
        $c = ! empty($a['cliente']) ? Busca::cliente($a['cliente']) : null;
        if (! empty($a['cliente']) && ! $c) return ['error' => "No encuentro un cliente parecido a \"{$a['cliente']}\". Si es nuevo, usá crear_cliente."];
        $origen = null; $items = []; $noEnc = [];
        if (! empty($a['presupuesto'])) {
            $origen = Busca::comprobante($a['presupuesto']);
            if (! $origen || $origen->tipo !== 'PRE') return ['error' => "No encuentro el presupuesto {$a['presupuesto']}."];
            $c ??= $origen->contact;
            foreach ($origen->items as $i) $items[] = ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva];
        } else {
            $cond = $c ? app(CondicionesClienteService::class)->paraCliente($c) : null;
            foreach ((array) ($a['items'] ?? []) as $it) {
                $p = Busca::articulo($it['articulo'] ?? '');
                if (! $p) { $noEnc[] = $it['articulo'] ?? '?'; continue; }
                $k = $c ? app(CondicionesClienteService::class)->para($c, $p, $cond) : ['precio' => $p->precioLista(1), 'descuento' => 0];
                $items[] = ['product_id' => $p->id, 'descripcion' => $p->name, 'cantidad' => (float) ($it['cantidad'] ?? 1), 'precio_unit' => isset($it['precio']) ? (float) $it['precio'] : (float) $k['precio'], 'descuento' => isset($it['precio']) ? 0 : (float) $k['descuento'], 'alicuota_iva' => (float) $p->iva];
            }
        }
        if (! $items) return ['error' => $noEnc ? 'No encontré ninguno de los artículos: ' . implode(', ', $noEnc) : 'Faltan los renglones.'];
        $total = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio_unit'] * (1 - $i['descuento'] / 100) * (1 + $i['alicuota_iva'] / 100), $items));
        $que = $tipo === 'PRE' ? 'Presupuesto' : 'Factura (borrador)';
        return ['titulo' => "{$que} para " . ($c?->name ?? 'Consumidor final') . ' por ' . Busca::pesos($total) . ' (con IVA)',
            'detalle' => collect($items)->map(fn($i) => "{$i['cantidad']} × {$i['descripcion']} a " . Busca::pesos($i['precio_unit']) . ($i['descuento'] ? " −{$i['descuento']}%" : ''))->implode('; ') . ($noEnc ? '. No encontré: ' . implode(', ', $noEnc) : '') . '. Queda en borrador para revisar.',
            'datos' => ['tipo' => $tipo, 'contact_id' => $c?->id, 'origen_id' => $origen?->id, 'condicion' => ($a['condicion'] ?? null) ?: ($c && (int) $c->dias_pago > 0 ? 'cta_cte' : 'contado'), 'notas' => $a['notas'] ?? null, 'items' => $items]];
    }

    private static function guardar(User $u, array $d): array
    {
        $c = app(ComprobanteService::class)->guardarBorrador(['tipo' => $d['tipo'], 'contact_id' => $d['contact_id'], 'origen_id' => $d['origen_id'] ?? null, 'fecha' => today()->toDateString(), 'condicion' => $d['condicion'], 'notas' => $d['notas'] ?? null, 'items' => $d['items']]);
        return ['texto' => "{$c->nombreTipo()} en borrador por " . Busca::pesos($c->total) . ($d['tipo'] === 'PRE' ? '.' : '. Para mandarla a ARCA usá emitir_comprobante.'), 'comprobante' => Busca::comp($c), 'url' => Busca::url("/comprobantes/{$c->id}/editar")];
    }
}
