<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\AuditLog;
use App\Models\Conciliacion;
use App\Models\CuponTarjeta;
use App\Models\CuentaFondos;
use App\Models\Liquidacion;
use App\Models\OrdenEntrega;
use App\Models\OrdenTrabajo;
use App\Models\Rubro;
use App\Models\TransferenciaStock;
use App\Models\User;

// Lo de las fases 34 a 39 para hablarle a Bigcito (por voz o escrito) y para Claude o ChatGPT conectados por MCP:
// datos en masa y deshacer, pases entre sucursales, conciliaciones y gastos del banco, reparto y acopios, servicio técnico,
// firmas de recibos y el estado de las empresas. Las acciones arman una propuesta y recién se hacen cuando el usuario confirma.
class Gestion
{
    private static function ot($t): ?OrdenTrabajo
    {
        $n = (int) preg_replace('/\D/', '', (string) $t);
        return $n ? OrdenTrabajo::where('numero', $n)->first() ?? OrdenTrabajo::find($n) : null;
    }

    private static function pase($t): ?TransferenciaStock
    {
        $n = (int) preg_replace('/\D/', '', (string) $t);
        return $n ? TransferenciaStock::where('numero', $n)->first() : TransferenciaStock::where('estado', 'en_viaje')->latest('id')->first();
    }

    // El grupo de artículos dicho con palabras: rubro, proveedor, marca o texto.
    private static function seleccion(array $a): array|string
    {
        $sel = [];
        if (! empty($a['rubro'])) { $r = Rubro::where('nombre', \App\Support\Sql::like(), '%' . $a['rubro'] . '%')->first(); if (! $r) return "No encuentro el rubro \"{$a['rubro']}\"."; $sel['rubro_id'] = $r->id; }
        if (! empty($a['proveedor'])) { $c = Busca::proveedor($a['proveedor']); if (! $c) return "No encuentro el proveedor \"{$a['proveedor']}\"."; $sel['proveedor_id'] = $c->id; }
        if (! empty($a['marca'])) $sel['marca'] = $a['marca'];
        if (! empty($a['texto'])) $sel['texto'] = $a['texto'];
        return $sel ?: 'Decime a qué artículos: un rubro, un proveedor, una marca o un texto.';
    }

    public static function herramientas(): array
    {
        $grupo = ['rubro' => ['type' => 'string'], 'proveedor' => ['type' => 'string'], 'marca' => ['type' => 'string'], 'texto' => ['type' => 'string', 'description' => 'Parte del nombre o código.']];
        return [
            // ---- Fase 34: datos en masa y deshacer ----
            ['nombre' => 'datos_en_masa', 'titulo' => 'Cambiar datos de un grupo de artículos', 'modulo' => 'stock', 'permiso' => 'editar', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Cambia de una vez un dato (rubro, ubicación, IVA, stock mínimo, marca, activo) a todos los artículos de un rubro, proveedor, marca o que dicen un texto. Ej.: "poneles ubicación góndola 3 a todos los de Ayudín".',
                'parametros' => ['dato' => ['type' => 'string', 'enum' => ['rubro', 'ubicacion', 'iva', 'stock_minimo', 'marca', 'activo']], 'valor' => ['type' => 'string', 'description' => 'El valor nuevo (para rubro, el nombre; para activo, si/no).']] + $grupo,
                'requeridos' => ['dato', 'valor'],
                'proponer' => function (User $u, array $a) {
                    $sel = self::seleccion($a); if (is_string($sel)) return ['error' => $sel];
                    $campo = ['rubro' => 'rubro_id', 'ubicacion' => 'ubicacion', 'iva' => 'iva', 'stock_minimo' => 'stock_min', 'marca' => 'marca', 'activo' => 'active'][$a['dato']] ?? null;
                    $valor = $a['valor'];
                    if ($campo === 'rubro_id') { $r = Rubro::where('nombre', \App\Support\Sql::like(), '%' . $valor . '%')->first(); if (! $r) return ['error' => "No encuentro el rubro \"{$valor}\"."]; $valor = $r->id; }
                    if ($campo === 'active') $valor = in_array(mb_strtolower($valor), ['si', 'sí', '1', 'true', 'activo'], true) ? '1' : '0';
                    $prev = app(\App\Services\Stock\PreciosMasivosService::class)->previsualizarDatos($sel, [$campo => $valor], 5);
                    if (! $prev['n']) return ['error' => 'Ningún artículo cambia con eso.'];
                    return ['titulo' => "Cambiar {$a['dato']} a «{$a['valor']}» en {$prev['n']} artículos", 'detalle' => 'Por ejemplo: ' . collect($prev['filas'])->pluck('nombre')->implode(', ') . '. Se puede deshacer.', 'datos' => ['sel' => $sel, 'cambios' => [$campo => $valor]]];
                },
                'confirmar' => fn(User $u, array $d) => ['texto' => app(\App\Services\Stock\PreciosMasivosService::class)->aplicarDatos($d['sel'], $d['cambios'])->descripcion . '. Si te arrepentís, decime "deshacé el último cambio masivo".']],
            ['nombre' => 'deshacer_cambio_masivo', 'titulo' => 'Deshacer el último cambio masivo', 'modulo' => 'stock', 'permiso' => 'editar', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Vuelve los artículos a como estaban antes del último cambio masivo de precios o datos (hasta 30 días).',
                'parametros' => [],
                'proponer' => function (User $u, array $a) {
                    $log = AuditLog::whereIn('accion', ['precios_masivo', 'datos_masivo'])->whereNotNull('antes')->where('created_at', '>=', now()->subDays(30))->latest('id')->first();
                    if (! $log) return ['error' => 'No hay cambios masivos recientes para deshacer.'];
                    return ['titulo' => "Deshacer: {$log->descripcion}", 'detalle' => 'Hecho el ' . $log->created_at->format('d/m H:i') . '. Los artículos vuelven a los valores de antes.', 'datos' => ['id' => $log->id]];
                },
                'confirmar' => fn(User $u, array $d) => ['texto' => 'Listo: ' . app(\App\Services\Stock\PreciosMasivosService::class)->deshacer((int) $d['id']) . ' artículos volvieron a como estaban.']],

            // ---- Fase 37.4: pases entre sucursales ----
            ['nombre' => 'pases_entre_sucursales', 'titulo' => 'Pases entre sucursales', 'modulo' => 'stock', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Los pases de mercadería entre sucursales: los que están en viaje y los que llegaron con diferencias.',
                'parametros' => [],
                'ejecutar' => fn(User $u, array $a) => ['pases' => TransferenciaStock::with('origen', 'destino', 'items.product')->whereIn('estado', ['en_viaje', 'con_diferencias'])->latest('id')->limit(20)->get()->map(fn($t) => app(\App\Services\Stock\PasesService::class)->resumen($t) + ['url' => Busca::url("/stock/pases/{$t->id}")])->map(fn($r) => collect($r)->except('token')->all())->all()]],
            ['nombre' => 'recibir_pase', 'titulo' => 'Recibir un pase completo', 'modulo' => 'stock', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Da por recibido un pase en viaje cuando llegó todo lo que salió ("llegó el pase 12, está todo"). Si faltó algo, hay que contarlo en la pantalla del pase.',
                'parametros' => ['pase' => ['type' => 'string', 'description' => 'Número del pase; si no lo dice, el último en viaje.']],
                'proponer' => function (User $u, array $a) {
                    $t = self::pase($a['pase'] ?? null); if (! $t || $t->estado !== 'en_viaje') return ['error' => 'No encuentro ese pase en viaje.'];
                    $t->load('items.product', 'origen', 'destino');
                    return ['titulo' => "Recibir {$t->numeroFormateado()} de {$t->origen->nombre} en {$t->destino->nombre}, completo", 'detalle' => $t->items->map(fn($i) => Busca::cant($i->cantidad) . " {$i->product?->name}")->implode(' · '), 'datos' => ['id' => $t->id]];
                },
                'confirmar' => function (User $u, array $d) {
                    $t = TransferenciaStock::with('items')->findOrFail($d['id']);
                    app(\App\Services\Stock\PasesService::class)->recibir($t, $t->items->mapWithKeys(fn($i) => [$i->id => (float) $i->cantidad])->all(), 'Recibido completo (por el asistente)');
                    return ['texto' => "Pase {$t->numeroFormateado()} recibido: ya está en el stock de destino."];
                }],

            // ---- Fase 35: conciliaciones ----
            ['nombre' => 'estado_conciliaciones', 'titulo' => 'Cómo están las conciliaciones', 'modulo' => 'fondos', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Cupones de tarjeta en cartera, las últimas liquidaciones cruzadas (qué cerró, qué falta, qué revisar) y lo que el banco tiene que no está cargado.',
                'parametros' => ['banco' => ['type' => 'string']],
                'ejecutar' => function (User $u, array $a) {
                    $banco = Busca::cuenta($a['banco'] ?? 'banco', 'banco');
                    $caj = $banco ? app(\App\Services\Contabilidad\BancoCajonesService::class)->cajones($banco) : null;
                    return ['cupones_en_cartera' => ['cantidad' => CuponTarjeta::enCartera()->count(), 'monto' => (float) CuponTarjeta::enCartera()->sum('monto')],
                        'lotes' => Conciliacion::latest('id')->limit(5)->get()->map(fn($c) => ['id' => $c->id, 'origen' => Conciliacion::ORIGENES[$c->origen] ?? $c->origen, 'estado' => $c->estado, 'cerro' => $c->resumen['cerro'] ?? null, 'no_pagaron' => $c->resumen['no_pagaron'] ?? null, 'a_revisar' => $c->resumen['revisar_n'] ?? null, 'url' => Busca::url("/fondos/conciliar/{$c->id}")])->all(),
                        'banco' => $caj ? ['cuenta' => $banco->nombre, 'para_cargar' => $caj['solo_banco']['para_cargar'], 'grupos' => collect($caj['solo_banco']['grupos'])->map(fn($g) => ['grupo' => $g['nombre'], 'cantidad' => $g['cantidad'], 'monto' => $g['monto']])->all(), 'puente' => $caj['puente']['texto']] : null];
                }],
            ['nombre' => 'registrar_gastos_banco', 'titulo' => 'Cargar los gastos del banco', 'modulo' => 'contable', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Registra de una vez todos los renglones del extracto que son impuestos, comisiones, IVA o intereses y no estaban cargados ("cargá los impuestos del banco").',
                'parametros' => ['grupo' => ['type' => 'string', 'enum' => ['impuesto', 'comision', 'iva', 'interes']], 'banco' => ['type' => 'string']],
                'requeridos' => ['grupo'],
                'proponer' => function (User $u, array $a) {
                    $b = Busca::cuenta($a['banco'] ?? 'banco', 'banco'); if (! $b) return ['error' => 'No encuentro la cuenta del banco.'];
                    $g = collect(app(\App\Services\Contabilidad\BancoCajonesService::class)->cajones($b)['solo_banco']['grupos'])->firstWhere('clase', $a['grupo']);
                    if (! $g) return ['error' => 'No hay renglones pendientes de ese grupo en el extracto.'];
                    return ['titulo' => "Registrar {$g['cantidad']} renglones de «{$g['nombre']}» del extracto de {$b->nombre}", 'detalle' => 'Total ' . Busca::pesos($g['monto']) . '. Quedan cargados en Fondos y conciliados.', 'datos' => ['cuenta' => $b->id, 'clase' => $a['grupo']]];
                },
                'confirmar' => fn(User $u, array $d) => ['texto' => 'Listo: ' . app(\App\Services\Contabilidad\BancoCajonesService::class)->registrarGrupo(CuentaFondos::findOrFail($d['cuenta']), $d['clase'], null) . ' renglones registrados y conciliados.']],

            // ---- Fase 37 y 35.6: reparto y acopios ----
            ['nombre' => 'viajes_reparto', 'titulo' => 'Viajes de reparto', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Los viajes abiertos: chofer, paradas entregadas y pendientes, lo que no se pudo y por dónde va el camión.',
                'parametros' => [],
                'ejecutar' => fn(User $u, array $a) => ['viajes' => OrdenEntrega::with('items.contact', 'items.comprobante.contact')->whereIn('estado', ['pendiente', 'en_curso'])->orderByDesc('fecha')->limit(10)->get()->map(fn($o) => ['numero' => $o->numeroFormateado(), 'chofer' => $o->repartidor, 'estado' => $o->estado, 'entregadas' => $o->items->where('estado', 'entregado')->count(), 'pendientes' => $o->items->where('estado', 'pendiente')->map(fn($i) => $i->comprobante?->contact?->name ?? $i->contact?->name ?? $i->nombre)->values()->all(), 'no_se_pudo' => $o->items->where('estado', 'no_entregado')->map(fn($i) => ($i->comprobante?->contact?->name ?? $i->contact?->name ?? $i->nombre) . ': ' . $i->motivo)->values()->all(), 'posicion' => $o->ultima_lat ? "https://www.google.com/maps?q={$o->ultima_lat},{$o->ultima_lng}" : null, 'actualizada' => $o->ultima_pos_en?->diffForHumans(), 'url' => Busca::url("/comprobantes/entregas/{$o->id}")])->all()]],
            ['nombre' => 'pendiente_de_entregar', 'titulo' => 'Qué hay para entregar', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Lo pendiente de entregar por cliente (facturas, remitos y retiros de acopio), para armar el viaje.',
                'parametros' => [],
                'ejecutar' => fn(User $u, array $a) => ['clientes' => collect(app(\App\Services\Ventas\RepartoService::class)->pendientes())->map(fn($c) => ['cliente' => $c['cliente'], 'direccion' => $c['direccion'], 'documentos' => collect($c['documentos'])->map(fn($d) => $d['label'] . ': ' . collect($d['items'])->map(fn($i) => "{$i['pendiente']} {$i['descripcion']}")->implode(', '))->all()])->all()]],
            ['nombre' => 'armar_viaje', 'titulo' => 'Armar un viaje de reparto', 'modulo' => 'comprobantes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Arma el viaje con todo lo pendiente de los clientes nombrados (o de todos), lo ordena por el camino más corto y devuelve el link para el chofer.',
                'parametros' => ['clientes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Nombres de clientes; vacío = todo lo pendiente.'], 'chofer' => ['type' => 'string'], 'vehiculo' => ['type' => 'string']],
                'proponer' => function (User $u, array $a) {
                    $pend = collect(app(\App\Services\Ventas\RepartoService::class)->pendientes());
                    if (! empty($a['clientes'])) { $ids = collect($a['clientes'])->map(fn($n) => Busca::cliente($n)?->id)->filter()->all(); $pend = $pend->whereIn('contact_id', $ids); }
                    if ($pend->isEmpty()) return ['error' => 'No hay nada pendiente de entregar para esos clientes.'];
                    $paradas = $pend->flatMap(fn($c) => collect($c['documentos'])->map(fn($d) => ['tipo' => $d['tipo'], 'id' => $d['id']]))->values()->all();
                    return ['titulo' => 'Viaje con ' . count($paradas) . ' entregas a ' . $pend->count() . ' clientes' . (! empty($a['chofer']) ? " · chofer {$a['chofer']}" : ''), 'detalle' => $pend->pluck('cliente')->implode(', ') . '. Se ordena por el camino más corto.', 'datos' => ['paradas' => $paradas, 'repartidor' => $a['chofer'] ?? null, 'vehiculo' => $a['vehiculo'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $oe = app(\App\Services\Ventas\RepartoService::class)->armar($d + ['fecha' => today()->toDateString(), 'ordenar' => true]);
                    return ['texto' => "Viaje {$oe->numeroFormateado()} armado con {$oe->items->count()} paradas. Link para el chofer: " . url("/reparto/{$oe->token_chofer}"), 'url' => Busca::url("/comprobantes/entregas/{$oe->id}")];
                }],
            ['nombre' => 'acopios', 'titulo' => 'Acopios', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Lo que cada cliente tiene para retirar (en plata y por artículo), lo que debe de verdad, el stock comprometido y los avisos.',
                'parametros' => ['cliente' => ['type' => 'string']],
                'ejecutar' => function (User $u, array $a) {
                    $r = app(\App\Services\Comprobantes\AcopiosInformeService::class)->informe();
                    $c = ! empty($a['cliente']) ? Busca::cliente($a['cliente']) : null;
                    return ['totales' => $r['totales'], 'clientes' => collect($r['clientes'])->when($c, fn($x) => $x->where('id', $c->id))->take(15)->map(fn($x) => ['cliente' => $x['cliente'], 'para_retirar' => $x['pendiente'], 'debe' => $x['debe'], 'articulos' => collect($x['acopios'])->flatMap(fn($ac) => collect($ac['items'])->where('pendiente', '>', 0)->map(fn($i) => "{$i['pendiente']} {$i['unidad']} {$i['articulo']}"))->all()])->values()->all(), 'sin_stock_para_entregar' => collect($r['articulos'])->where('falta', true)->pluck('articulo')->all(), 'avisos' => collect($r['avisos'])->pluck('texto')->take(10)->all()];
                }],

            // ---- Fase 36: servicio técnico ----
            ['nombre' => 'taller_que_sigue', 'titulo' => 'Qué sigue en el taller', 'modulo' => 'servicios', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Cada orden abierta con su próximo paso, las que están esperando y lo que hay que revisar (listas sin retirar, presupuestos sin respuesta, entregadas sin factura).',
                'parametros' => ['mias' => ['type' => 'boolean', 'description' => 'Sólo las del técnico que pregunta.']],
                'ejecutar' => function (User $u, array $a) {
                    $t = app(\App\Services\Servicios\TallerService::class);
                    $ots = ! empty($a['mias']) ? collect($t->misOrdenes($u)) : OrdenTrabajo::with('items', 'tareas')->whereNotIn('estado', ['entregado', 'cancelado'])->orderBy('fecha_prometida')->limit(40)->get()->map(fn($o) => ['numero' => $o->numeroFormateado(), 'cliente' => $o->clienteNombre(), 'equipo' => $o->equipo, 'estado' => $o->estado, 'que_sigue' => $t->queSigue($o)['paso']]);
                    return ['ordenes' => $ots->values()->all(), 'para_revisar' => app(\App\Services\Servicios\TallerHistoriaService::class)->paraRevisar()];
                }],
            ['nombre' => 'dejar_esperando', 'titulo' => 'Dejar una orden esperando', 'modulo' => 'servicios', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Pasa una orden del taller a "Esperando" con el motivo ("la 15 queda esperando el repuesto").',
                'parametros' => ['orden' => ['type' => 'string'], 'motivo' => ['type' => 'string']],
                'requeridos' => ['orden', 'motivo'],
                'proponer' => function (User $u, array $a) {
                    $o = self::ot($a['orden']); if (! $o) return ['error' => "No encuentro la orden {$a['orden']}."];
                    return ['titulo' => "{$o->numeroFormateado()} ({$o->equipo} de {$o->clienteNombre()}) queda esperando", 'detalle' => "Motivo: {$a['motivo']}", 'datos' => ['id' => $o->id, 'motivo' => $a['motivo']]];
                },
                'confirmar' => function (User $u, array $d) { $o = OrdenTrabajo::findOrFail($d['id']); app(\App\Services\Servicios\TallerService::class)->esperar($o, $d['motivo']); return ['texto' => "{$o->numeroFormateado()} queda esperando: {$d['motivo']}."]; }],
            ['nombre' => 'que_vuelvan', 'titulo' => 'A quién le toca volver al taller', 'modulo' => 'servicios', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Los equipos a los que les toca el próximo service (por fecha, por km u horas estimados o por pieza), con el WhatsApp para avisarles.',
                'parametros' => ['dias' => ['type' => 'integer', 'description' => 'Cuántos días para adelante (30 por defecto).']],
                'ejecutar' => fn(User $u, array $a) => ['equipos' => collect(app(\App\Services\Servicios\TallerHistoriaService::class)->queVuelvan((int) ($a['dias'] ?? 30)))->map(fn($x) => collect($x)->only(['cliente', 'equipo', 'serie', 'motivos', 'whatsapp', 'recordado'])->all())->all()]],
            ['nombre' => 'pago_tecnicos', 'titulo' => 'Qué pagarle a cada técnico', 'modulo' => 'servicios', 'permiso' => 'editar', 'tipo' => 'consulta',
                'descripcion' => 'La parte de la mano de obra de cada técnico en lo entregado del período, y cuánto pagarle.',
                'parametros' => Busca::PERIODO,
                'ejecutar' => function (User $u, array $a) { [$d, $h, $txt] = Busca::periodo($a); $p = app(\App\Services\Servicios\TallerService::class)->pagoTecnicos($d->toDateString(), $h->toDateString()); return ['periodo' => $txt, 'porcentaje' => $p['pct'], 'tecnicos' => collect($p['tecnicos'])->map(fn($t) => collect($t)->only(['tecnico', 'ordenes', 'mano_obra', 'a_pagar'])->all())->all()]; }],

            // ---- Fase 38: recibos de sueldo ----
            ['nombre' => 'firmas_recibos', 'titulo' => 'Quién firmó el recibo', 'modulo' => 'sueldos', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'El estado de los recibos de sueldo de un período: enviado, visto, firmado (con fecha e IP) o en disconformidad.',
                'parametros' => ['periodo' => ['type' => 'string', 'description' => 'AAAA-MM; por defecto el último liquidado.']],
                'ejecutar' => function (User $u, array $a) {
                    $p = $a['periodo'] ?? Liquidacion::orderByDesc('periodo')->value('periodo');
                    $h = collect(app(\App\Services\Sueldos\RecibosFirmaService::class)->historial($p));
                    return ['periodo' => $p, 'firmados' => $h->whereNotNull('firmado')->count(), 'faltan' => $h->whereNull('firmado')->pluck('empleado')->values()->all(), 'disconformes' => $h->where('conformidad', 'disconforme')->map(fn($x) => "{$x['empleado']}: {$x['observacion']}")->values()->all()];
                }],
            ['nombre' => 'enviar_recibos', 'titulo' => 'Mandar los recibos para firmar', 'modulo' => 'sueldos', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Manda a cada empleado el link de su recibo para firmar (todos, o sólo a los que faltan).',
                'parametros' => ['periodo' => ['type' => 'string'], 'solo_faltan' => ['type' => 'boolean']],
                'proponer' => function (User $u, array $a) {
                    $l = Liquidacion::where('estado', '!=', 'borrador')->when($a['periodo'] ?? null, fn($q, $p) => $q->where('periodo', $p))->orderByDesc('periodo')->first();
                    if (! $l) return ['error' => 'No hay una liquidación confirmada para mandar.'];
                    $n = $l->items()->when(! empty($a['solo_faltan']), fn($q) => $q->whereNull('firmado_en'))->count();
                    if (! $n) return ['error' => 'Ya firmaron todos.'];
                    return ['titulo' => "Mandar {$n} recibos de {$l->periodoLabel()} para firmar", 'detalle' => 'Les llega el link por mail y WhatsApp.', 'datos' => ['id' => $l->id, 'faltan' => ! empty($a['solo_faltan'])]];
                },
                'confirmar' => function (User $u, array $d) { $r = app(\App\Services\Sueldos\RecibosFirmaService::class)->enviarLiquidacion(Liquidacion::findOrFail($d['id']), $u->business()->first(), (bool) $d['faltan']); return ['texto' => count($r) . ' recibos enviados.' . (collect($r)->where('sin_contacto', true)->count() ? ' Sin mail ni teléfono: ' . collect($r)->where('sin_contacto', true)->pluck('empleado')->implode(', ') . '.' : '')]; }],

            // ---- Fase 39: varias empresas ----
            ['nombre' => 'estado_mis_empresas', 'titulo' => 'Cómo están mis empresas', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Para quien maneja varias empresas o es estudio contable: el semáforo de qué falta en cada una y los próximos vencimientos de IVA.',
                'parametros' => ['mes' => ['type' => 'string', 'description' => 'AAAA-MM; por defecto el mes pasado.']],
                'ejecutar' => function (User $u, array $a) {
                    $mes = $a['mes'] ?? now()->subMonth()->format('Y-m'); $e = app(\App\Services\Empresas\EstudioService::class); $emp = $u->empresasAccesibles();
                    return ['mes' => $mes, 'empresas' => $emp->map(fn($b) => ['empresa' => $b->name] + $e->semaforo($b, $mes))->sortBy(fn($x) => ['rojo' => 0, 'amarillo' => 1, 'verde' => 2][$x['nivel']])->values()->all(), 'vencimientos' => $e->vencimientos($emp, $mes, $e->config($u->business)['vencimientos'])];
                }],
        ];
    }
}
