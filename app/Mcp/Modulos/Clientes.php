<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\User;
use App\Services\Comprobantes\CobroService;
use App\Services\Ventas\CobranzasService;

// Clientes: buscar, ficha, cuenta corriente, deudores, alta, cobros y recordatorios de deuda.
class Clientes
{
    public const MEDIOS = ['efectivo', 'transferencia', 'mercadopago', 'tarjeta', 'cheque'];

    public static function herramientas(): array
    {
        return [
            // Fase 47: transferencias de clientes que están en el banco y no tienen recibo.
            ['nombre' => 'transferencias_sin_recibo', 'titulo' => 'Transferencias que entraron sin recibo', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Plata que entró al banco según el último extracto importado y todavía no está en el sistema, con el cliente al que corresponde cuando se reconoce por CUIT, DNI o nombre completo.',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    $t = app(\App\Services\Comprobantes\TransferenciaClienteService::class); $out = [];
                    foreach (\App\Models\CuentaFondos::whereIn('tipo', ['banco', 'billetera'])->where('activa', true)->get() as $cta) {
                        $sug = $t->sugerencias($cta);
                        foreach (\App\Models\ExtractoItem::where('cuenta_fondos_id', $cta->id)->where('estado', 'pendiente')->where('monto', '>', 0)->orderBy('fecha')->limit(50)->get() as $it)
                            $out[] = ['id' => $it->id, 'banco' => $cta->nombre, 'fecha' => $it->fecha->format('d/m/Y'), 'descripcion' => $it->descripcion, 'monto' => (float) $it->monto, 'cliente' => $sug[$it->id]['cliente'] ?? null, 'reconocido_por' => $sug[$it->id]['por'] ?? null];
                    }
                    return ['transferencias' => $out, 'url' => Busca::url('/contable/conciliacion')];
                }],
            ['nombre' => 'recibo_de_transferencia', 'titulo' => 'Hacer el recibo de una transferencia del banco', 'modulo' => 'clientes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Convierte una transferencia del extracto (id de transferencias_sin_recibo) en recibo del cliente, imputado a sus facturas más viejas, y la deja conciliada con el banco.',
                'parametros' => ['transferencia_id' => ['type' => 'integer'], 'cliente' => ['type' => 'string', 'description' => 'Si no lo dice, el reconocido.']], 'requeridos' => ['transferencia_id'],
                'proponer' => function (User $u, array $a) {
                    $it = \App\Models\ExtractoItem::where('estado', 'pendiente')->find($a['transferencia_id']); if (! $it || $it->monto <= 0) return ['error' => 'No encuentro esa transferencia pendiente.'];
                    $c = ! empty($a['cliente']) ? Busca::cliente($a['cliente']) : app(\App\Services\Comprobantes\TransferenciaClienteService::class)->cliente($it->descripcion . ' ' . $it->referencia)['contact'];
                    if (! $c) return ['error' => 'No sé de qué cliente es. Decime el cliente.'];
                    return ['titulo' => "Recibo a {$c->name} por " . Busca::pesos((float) $it->monto), 'detalle' => "Transferencia del {$it->fecha->format('d/m')} · {$it->descripcion}", 'datos' => ['it' => $it->id, 'c' => $c->id]];
                },
                'confirmar' => function (User $u, array $d) {
                    $cobro = app(\App\Services\Comprobantes\TransferenciaClienteService::class)->reciboDesdeExtracto(\App\Models\ExtractoItem::findOrFail($d['it']), Contact::findOrFail($d['c']));
                    return ['texto' => "Recibo {$cobro->numeroFormateado()} hecho y conciliado con el banco.", 'url' => Busca::url("/clientes/{$d['c']}")];
                }],
            // Fase 96: "mandale a todos los clientes que el lunes cambia la lista", "avisale por WhatsApp a los que deben que cerramos el viernes".
            ['nombre' => 'mandar_mensaje_clientes', 'titulo' => 'Mandar un mensaje a muchos clientes', 'modulo' => 'clientes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Manda el mismo mensaje a un grupo de clientes por WhatsApp o mail: a todos los activos, a los que tienen saldo o a un tipo de cliente. Admite {cliente}, {saldo}, {empresa} y {portal}. Sin API de WhatsApp quedan los links para mandar a mano en Clientes → Mensajes a clientes.',
                'parametros' => ['texto' => ['type' => 'string'], 'canal' => ['type' => 'string', 'enum' => ['whatsapp', 'mail'], 'description' => 'whatsapp si no se dice'], 'a' => ['type' => 'string', 'enum' => ['todos', 'deudores', 'inactivos'], 'description' => 'todos si no se dice'], 'tipo_cliente' => ['type' => 'string'], 'asunto' => ['type' => 'string', 'description' => 'sólo para mail']],
                'requeridos' => ['texto'],
                'proponer' => function (User $u, array $a) {
                    $canal = ($a['canal'] ?? 'whatsapp') === 'mail' ? 'mail' : 'whatsapp'; $estado = match ($a['a'] ?? 'todos') { 'deudores' => 'deudores', 'inactivos' => 'inactivos', default => 'activos' };
                    $tipo = ! empty($a['tipo_cliente']) ? \App\Models\TipoCliente::where('nombre', \App\Support\Sql::like(), "%{$a['tipo_cliente']}%")->first() : null;
                    if (! empty($a['tipo_cliente']) && ! $tipo) return ['error' => "No encuentro el tipo de cliente \"{$a['tipo_cliente']}\"."];
                    if ($canal === 'mail' && empty($a['asunto'])) return ['error' => 'Para mandarlo por mail me falta el asunto.'];
                    $filtro = array_filter(['estado' => $estado, 'tipo_cliente_id' => $tipo?->id]);
                    $n = app(\App\Services\Ventas\CampanasService::class)->destinatarios($u->business, $filtro, $canal)->count();
                    if (! $n) return ['error' => 'Ningún cliente de ese grupo tiene ' . ($canal === 'mail' ? 'email' : 'teléfono') . '.'];
                    $quien = ($estado === 'deudores' ? 'los clientes con saldo' : ($estado === 'inactivos' ? 'los clientes inactivos' : 'todos los clientes activos')) . ($tipo ? " del tipo {$tipo->nombre}" : '');
                    return ['titulo' => "Mandar por " . ($canal === 'mail' ? 'mail' : 'WhatsApp') . " a {$n} cliente(s): {$quien}", 'detalle' => mb_substr($a['texto'], 0, 200) . (empty($u->business->whatsapp_settings['token']) && $canal === 'whatsapp' ? ' · Sin API de WhatsApp: quedan los links para mandar a mano.' : ''), 'datos' => ['canal' => $canal, 'texto' => $a['texto'], 'asunto' => $a['asunto'] ?? null, 'filtro' => $filtro]];
                },
                'confirmar' => function (User $u, array $d) {
                    $c = app(\App\Services\Ventas\CampanasService::class)->crear($u, $d['canal'], $d['texto'], $d['asunto'] ?? null, $d['filtro']);
                    return ['texto' => "Listo: mensaje en camino a {$c->destinatarios} cliente(s)." . ($d['canal'] === 'whatsapp' && empty($u->business->whatsapp_settings['token']) ? ' En Clientes → Mensajes a clientes están los links para mandarlos desde tu WhatsApp.' : ''), 'url' => Busca::url('/clientes/campanas')];
                }],
            ['nombre' => 'buscar_clientes', 'titulo' => 'Buscar clientes', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Busca clientes por nombre, CUIT, teléfono o email. Devuelve id, CUIT, condición de IVA, saldo y contacto.',
                'parametros' => ['texto' => ['type' => 'string'], 'cantidad' => ['type' => 'integer']], 'requeridos' => ['texto'],
                'ejecutar' => function (User $u, array $a) {
                    $t = trim((string) $a['texto']); $like = \App\Support\Sql::like();
                    return ['clientes' => Contact::customers()->where(fn($w) => $w->where('name', $like, "%{$t}%")->orWhere('cuit', $like, "%{$t}%")->orWhere('email', $like, "%{$t}%")->orWhere('phone', $like, "%{$t}%")->orWhere('mobile', $like, "%{$t}%"))
                        ->orderByRaw('LENGTH(name)')->limit(min(50, (int) ($a['cantidad'] ?? 10)))->get()->map(fn($c) => self::fila($c))->all()];
                }],
            // Fase 60: lo que los clientes pidieron por WhatsApp y nadie atendió todavía (reclamos y pedidos de hablar con alguien).
            ['nombre' => 'reclamos_whatsapp', 'titulo' => 'Reclamos por WhatsApp', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Reclamos y pedidos de atención que los clientes mandaron por WhatsApp y Bigcito tomó; los que todavía no resolvió nadie.',
                'parametros' => [], 'requeridos' => [],
                'ejecutar' => function (User $u, array $a) {
                    $al = \App\Models\Alerta::activas()->whereIn('tipo', ['reclamo_whatsapp', 'whatsapp_persona'])->latest()->limit(20)->get();
                    return ['pendientes' => $al->count(), 'reclamos' => $al->map(fn($x) => ['cuando' => $x->created_at->format('d/m H:i'), 'tipo' => $x->tipo === 'reclamo_whatsapp' ? 'reclamo' : 'quiere hablar con alguien', 'quien' => $x->titulo, 'mensaje' => $x->detalle, 'url' => Busca::url($x->url ?? '/clientes')])->all(),
                        'nota' => $al->isEmpty() ? 'No hay reclamos de clientes sin atender.' : 'Cuando se resuelva, marcar la alerta como resuelta en Inicio → Avisos.'];
                }],
            // Fase 72: lo que el CRM sabe del cliente (vendedor, etapa, oportunidades, tareas, próxima cita).
            ['nombre' => 'cliente_en_crm', 'titulo' => 'El cliente en el CRM', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Lo que el CRM sabe de un cliente: qué vendedor lo atiende, en qué etapa está, oportunidades abiertas con monto, tareas pendientes y próxima cita. Complementa a ficha_cliente (que trae lo contable). Sólo si la empresa tiene el CRM conectado.',
                'parametros' => ['cliente' => ['type' => 'string']], 'requeridos' => ['cliente'],
                'ejecutar' => function (User $u, array $a) {
                    $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."];
                    if (! \App\Services\Integraciones\CrmService::activo($u->business)) return ['error' => 'Esta empresa no tiene el CRM conectado (Configuración → CRM).'];
                    if (! $c->crm_external_id) return ['cliente' => $c->name, 'en_crm' => false, 'nota' => "{$c->name} todavía no está en el CRM."];
                    $f = \App\Services\Integraciones\CrmSyncService::fichaCrm($u->business, $c);
                    if ($f === null) return ['error' => 'El CRM no respondió. Probá en un rato (estado_crm dice cómo está la conexión).'];
                    $f['url'] = Busca::url($f['url']); foreach (['oportunidades', 'tareas'] as $k) $f[$k] = array_map(fn($x) => ['url' => Busca::url($x['url'])] + $x, $f[$k]);
                    return ['cliente' => $c->name, 'en_crm' => true] + $f + ['nota' => 'Para lo contable (saldo, vencido, facturas) usá ficha_cliente; para lo que hablaron, conversaciones_crm.'];
                }],
            // Fase 71: lo que el CRM habló con el cliente.
            ['nombre' => 'conversaciones_crm', 'titulo' => 'Conversaciones del CRM con un cliente', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Qué habló el equipo de ventas con un cliente en el CRM (WhatsApp, mail, redes): las últimas conversaciones y los últimos mensajes de la más reciente. Sólo si la empresa tiene el CRM conectado.',
                'parametros' => ['cliente' => ['type' => 'string']], 'requeridos' => ['cliente'],
                'ejecutar' => function (User $u, array $a) {
                    $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."];
                    if (! \App\Services\Integraciones\CrmService::activo($u->business)) return ['error' => 'Esta empresa no tiene el CRM conectado (Configuración → CRM).'];
                    if (! $c->crm_external_id) return ['conversaciones' => [], 'nota' => "{$c->name} todavía no está en el CRM."];
                    $cv = \App\Services\Integraciones\CrmSyncService::conversaciones($u->business, $c);
                    return ['cliente' => $c->name, 'conversaciones' => array_map(fn($x) => ['canal' => $x['canal'], 'estado' => $x['estado'], 'sin_leer' => $x['no_leidos'], 'ultimo_mensaje' => $x['ultimo'], 'mensajes' => $x['mensajes'], 'url' => Busca::url($x['url'])], $cv), 'nota' => $cv ? 'Para escribirle, el botón "Escribirle por el CRM" en la ficha del cliente.' : 'El CRM no tiene conversaciones con este cliente.'];
                }],
            // Fase 120.5: lo que Bigcito cobrador acordó con clientes por WhatsApp y espera aprobación.
            ['nombre' => 'acuerdos_por_aprobar', 'titulo' => 'Acuerdos de pago que esperan aprobación', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Los planes de pago que clientes con deuda vencida aceptaron por WhatsApp hablando con Bigcito cobrador y que todavía esperan que la empresa los apruebe: cliente, cuotas, importe de la cuota y primer vencimiento. También dice si el cobrador está activo y con qué límites.',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    $cfg = \App\Services\Ventas\CobradorService::config($u->business);
                    $p = array_map(fn($x) => \Illuminate\Support\Arr::except($x, ['charla']), app(\App\Services\Ventas\CobradorService::class)->pendientes($u->business));
                    return ['cobrador_activo' => $cfg['activo'], 'limites' => ['cuotas_maximo' => $cfg['max_cuotas'], 'interes_pct' => $cfg['interes_pct'], 'cuota_minima' => $cfg['cuota_minima'], 'requiere_aprobacion' => $cfg['aprobar']], 'pendientes' => $p, 'url' => Busca::url('/clientes/cobranzas'),
                        'resumen' => count($p) ? count($p) . ' acuerdo(s) esperan aprobación.' : ($cfg['activo'] ? 'No hay acuerdos esperando aprobación.' : 'El cobrador está apagado: se prende en Cobranzas → Bigcito cobrador.')];
                }],
            ['nombre' => 'resolver_acuerdo_pago', 'titulo' => 'Aprobar o rechazar un acuerdo de pago', 'modulo' => 'clientes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Aprueba (crea el plan de pago y le avisa al cliente por WhatsApp) o rechaza un acuerdo de pago que un cliente aceptó por WhatsApp. Usá acuerdos_por_aprobar para ver cuáles hay.',
                'parametros' => ['cliente' => ['type' => 'string', 'description' => 'Nombre del cliente del acuerdo'], 'decision' => ['type' => 'string', 'enum' => ['aprobar', 'rechazar']], 'motivo' => ['type' => 'string', 'description' => 'Si se rechaza, el motivo que se le dice al cliente']],
                'requeridos' => ['cliente'],
                'proponer' => function (User $u, array $a) {
                    $q = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) ($a['cliente'] ?? '')));
                    $ac = \App\Models\AcuerdoPago::where('estado', 'pendiente')->with('contact:id,name')->get()->filter(fn($x) => $q !== '' && str_contains(\Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) $x->contact?->name)), $q))->values();
                    if ($ac->isEmpty()) return ['error' => 'No encuentro un acuerdo pendiente de ese cliente.'];
                    if ($ac->count() > 1) return ['error' => 'Hay más de un acuerdo que coincide: ' . $ac->map(fn($x) => $x->contact?->name)->implode(', ') . '. Decime cuál.'];
                    $x = $ac->first(); $rech = ($a['decision'] ?? 'aprobar') === 'rechazar';
                    return ['datos' => ['acuerdo_id' => $x->id, 'decision' => $rech ? 'rechazar' : 'aprobar', 'motivo' => $a['motivo'] ?? null],
                        'titulo' => ($rech ? 'Rechazar' : 'Aprobar') . " el acuerdo de {$x->contact?->name}",
                        'detalle' => ($x->cuotas === 1 ? 'Un pago de ' : "{$x->cuotas} cuotas de ") . '$ ' . number_format($x->cuota, 0, ',', '.') . ', la primera el ' . $x->primer_vencimiento->format('d/m') . ($rech ? '. Se le avisa al cliente que no se toma.' : '. Se crea el plan de pago y se le avisa al cliente.')];
                },
                'confirmar' => function (User $u, array $a) {
                    $x = \App\Models\AcuerdoPago::findOrFail((int) $a['acuerdo_id']); $cob = app(\App\Services\Ventas\CobradorService::class);
                    $r = $a['decision'] === 'rechazar' ? $cob->rechazar($x, $u, $a['motivo'] ?? null) : $cob->aprobar($x, $u);
                    if (! $r['ok']) throw new \RuntimeException($r['error']);
                    return ['texto' => $a['decision'] === 'rechazar' ? 'Listo, lo rechacé y le avisé al cliente.' : "Listo: plan {$r['plan']} creado y avisado al cliente.", 'url' => '/clientes/cobranzas'];
                }],
            ['nombre' => 'situacion_bcra', 'titulo' => 'Situación en el Banco Central', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Consulta la Central de Deudores del BCRA (información pública): situación crediticia con cada banco (1 normal a 5 irrecuperable), cuánto debe y cheques rechazados. Usala antes de dar cuenta corriente, subir un límite de crédito o aceptar un cheque: «¿cómo está López en el Banco Central?», «¿le puedo fiar a este?», «fijate el CUIT 30-71234567-8». Acepta un cliente o proveedor (por nombre) o un CUIT suelto (por ejemplo el del firmante de un cheque).',
                'parametros' => ['cliente' => ['type' => 'string', 'description' => 'Nombre, id o CUIT del cliente o proveedor.'], 'cuit' => ['type' => 'string', 'description' => 'Un CUIT/CUIL de 11 números, si no es un cliente cargado.']],
                'ejecutar' => function (User $u, array $a) {
                    $svc = app(\App\Services\Fiscal\BcraService::class);
                    $ref = trim((string) ($a['cliente'] ?? '')); $cuit = \App\Services\Fiscal\BcraService::limpiar($a['cuit'] ?? '');
                    $c = $ref !== '' ? (Busca::cliente($ref) ?? Busca::proveedor($ref)) : null;
                    if (! $c && strlen($cuit) !== 11 && strlen(\App\Services\Fiscal\BcraService::limpiar($ref)) === 11) $cuit = \App\Services\Fiscal\BcraService::limpiar($ref);
                    if ($c && ! $c->cuit && strlen($cuit) !== 11) return ['error' => "{$c->name} no tiene el CUIT cargado. Decime el CUIT o cargáselo en su ficha."];
                    if (! $c && strlen($cuit) !== 11) return ['error' => $ref !== '' ? "No encuentro a «{$ref}». Decime el CUIT de 11 números." : 'Decime el cliente o el CUIT de 11 números.'];
                    $r = $c && $c->cuit ? $svc->consultarContacto($c) : $svc->consultar($cuit, true);
                    if (! $r['ok']) return ['error' => $r['error']];
                    return ['quien' => $c?->name ?: ($r['denominacion'] ?: $r['cuit']), 'cuit' => $r['cuit'], 'semaforo' => $r['semaforo'], 'resumen' => $r['resumen'], 'consejo' => $r['consejo'], 'peor_situacion' => $r['peor'], 'peor_en_24_meses' => $r['peor_24m'], 'deuda_total' => $r['total'], 'periodo' => $r['periodo'],
                        'entidades' => array_map(fn($e) => ['entidad' => $e['entidad'], 'situacion' => $e['situacion'], 'debe' => $e['monto'], 'dias_atraso' => $e['dias_atraso']], array_slice($r['entidades'], 0, 8)), 'cheques_rechazados' => $r['cheques'],
                        'limite_de_credito_actual' => $c ? (float) $c->credit_limit : null, 'saldo_con_nosotros' => $c ? (float) $c->balance : null, 'fuente' => 'Central de Deudores del BCRA (información pública)'];
                }],
            ['nombre' => 'ficha_cliente', 'titulo' => 'Ficha del cliente', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Todo de un cliente: datos, saldo, lo vencido, facturas pendientes, últimas compras, contactos y condiciones (lista de precios, descuento, días de pago).',
                'parametros' => ['cliente' => ['type' => 'string', 'description' => 'Nombre, CUIT o id.']], 'requeridos' => ['cliente'],
                'ejecutar' => function (User $u, array $a) {
                    $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro un cliente parecido a \"{$a['cliente']}\"."];
                    $pend = Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->limit(30)->get();
                    return self::fila($c) + ['direccion' => trim("{$c->address} {$c->city}"), 'lista_precios' => (int) $c->lista_precios, 'descuento' => (float) $c->descuento, 'dias_pago' => (int) $c->dias_pago, 'limite_credito' => (float) $c->credit_limit,
                        'vencido' => round((float) $pend->filter(fn($x) => $x->fecha_vto && $x->fecha_vto->lt(today()))->sum('saldo'), 2),
                        'banco_central' => \App\Services\Fiscal\BcraService::linea($c) ?? 'sin consultar (usá situacion_bcra)',
                        'pendientes' => $pend->map(fn($x) => Busca::comp($x))->all(),
                        'ultimas_facturas' => Comprobante::where('contact_id', $c->id)->ventas()->emitidos()->facturas()->orderByDesc('fecha')->limit(5)->get()->map(fn($x) => Busca::comp($x))->all(),
                        'contactos' => $c->personas->map(fn($p) => $p->only('nombre', 'cargo', 'telefono', 'email'))->all()];
                }],
            ['nombre' => 'cuenta_corriente_cliente', 'titulo' => 'Cuenta corriente', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Movimientos de la cuenta corriente de un cliente (facturas, notas, cobros) con el saldo acumulado.',
                'parametros' => ['cliente' => ['type' => 'string'], 'desde' => ['type' => 'string', 'description' => 'AAAA-MM-DD']], 'requeridos' => ['cliente'],
                'ejecutar' => function (User $u, array $a) {
                    $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."];
                    $saldo = 0; $movs = CuentaCorriente::where('contact_id', $c->id)->orderBy('fecha')->orderBy('id')->get()->map(function ($m) use (&$saldo) { $saldo += (float) $m->debe - (float) $m->haber; return ['fecha' => $m->fecha->toDateString(), 'concepto' => $m->concepto, 'debe' => (float) $m->debe, 'haber' => (float) $m->haber, 'saldo' => round($saldo, 2)]; });
                    if (! empty($a['desde'])) $movs = $movs->filter(fn($m) => $m['fecha'] >= $a['desde']);
                    return ['cliente' => $c->name, 'saldo' => (float) $c->balance, 'movimientos' => $movs->take(-100)->values()->all()];
                }],
            ['nombre' => 'deudores', 'titulo' => 'Deudores', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Los clientes que más deben, con lo vencido, los días de atraso, la mora estimada y el contacto.',
                'parametros' => ['cantidad' => ['type' => 'integer'], 'solo_vencidos' => ['type' => 'boolean']],
                'ejecutar' => function (User $u, array $a) {
                    $d = app(CobranzasService::class)->deudores(400);
                    if (! empty($a['solo_vencidos'])) $d = $d->filter(fn($x) => $x['vencido'] > 0);
                    return ['deudores' => $d->take(min(100, (int) ($a['cantidad'] ?? 10)))->map(fn($x) => array_intersect_key($x, array_flip(['id', 'nombre', 'saldo', 'vencido', 'dias', 'mora', 'telefono', 'email'])))->values()->all(), 'total_por_cobrar' => round((float) $d->sum('saldo'), 2), 'total_vencido' => round((float) $d->sum('vencido'), 2)];
                }],
            ['nombre' => 'crear_cliente', 'titulo' => 'Dar de alta un cliente', 'modulo' => 'clientes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Da de alta un cliente nuevo. Si ya existe uno con ese CUIT o nombre, avisa en vez de duplicar.',
                'parametros' => ['nombre' => ['type' => 'string'], 'cuit' => ['type' => 'string', 'description' => 'CUIT o DNI'], 'condicion_iva' => ['type' => 'string', 'enum' => Contact::CONDICIONES_IVA], 'email' => ['type' => 'string'], 'telefono' => ['type' => 'string'], 'direccion' => ['type' => 'string'], 'localidad' => ['type' => 'string'], 'lista_precios' => ['type' => 'integer']],
                'requeridos' => ['nombre'],
                'proponer' => function (User $u, array $a) {
                    $doc = preg_replace('/\D/', '', (string) ($a['cuit'] ?? ''));
                    if (strlen($doc) === 11 && ! \App\Support\Cuit::valido($doc)) return ['error' => 'El CUIT no es válido (revisá el dígito verificador).'];
                    if ($ya = app(\App\Services\Ventas\ClienteDesdeFacturaService::class)->buscar(['nombre' => $a['nombre'], 'documento' => $doc])) return ['error' => "Ya existe el cliente {$ya->name} (id {$ya->id}). Usá ese."];
                    // Fase 107: con CUIT y certificado de ARCA, el padrón completa nombre, condición y domicilio solo.
                    $pad = null; if (strlen($doc) === 11 && app(\App\Services\Comprobantes\AfipEmisor::class)->padronDisponible($u->business)) { try { $pad = \App\Services\Afip\AfipService::forBusiness($u->business)->padron($doc); } catch (\Throwable) { $pad = null; } }
                    if ($pad) { $a['nombre'] = $a['nombre'] ?: $pad['nombre']; $a['condicion_iva'] = $a['condicion_iva'] ?? $pad['condicion_iva']; $a['direccion'] = $a['direccion'] ?? ($pad['direccion'] ?: null); $a['localidad'] = $a['localidad'] ?? ($pad['localidad'] ?: null); }
                    $iva = $a['condicion_iva'] ?? (strlen($doc) === 11 ? 'Responsable Inscripto' : 'Consumidor Final');
                    if (in_array($iva, ['Responsable Inscripto', 'Monotributista'], true) && strlen($doc) !== 11) return ['error' => "Un {$iva} necesita CUIT."];
                    return ['titulo' => "Dar de alta a {$a['nombre']}" . ($pad ? ' (datos del padrón de ARCA)' : ''), 'detalle' => trim("{$iva}" . ($doc ? " · " . (strlen($doc) === 11 ? 'CUIT' : 'DNI') . " {$doc}" : '') . (! empty($a['email']) ? " · {$a['email']}" : '') . (! empty($a['telefono']) ? " · {$a['telefono']}" : '')),
                        'datos' => ['nombre' => $a['nombre'], 'documento' => $doc, 'condicion_iva' => $iva, 'email' => $a['email'] ?? null, 'phone' => $a['telefono'] ?? null, 'address' => $a['direccion'] ?? null, 'city' => $a['localidad'] ?? null, 'lista' => (int) ($a['lista_precios'] ?? 1)]];
                },
                'confirmar' => function (User $u, array $d) {
                    $c = app(\App\Services\Ventas\ClienteDesdeFacturaService::class)->contacto($d, $u->business_id, true);
                    if (($d['lista'] ?? 1) > 1) $c->update(['lista_precios' => min(6, (int) $d['lista'])]);
                    return ['texto' => "Cliente {$c->name} dado de alta (id {$c->id}).", 'cliente_id' => $c->id, 'url' => Busca::url("/clientes/{$c->id}")];
                }],
            ['nombre' => 'registrar_cobro', 'titulo' => 'Registrar un cobro', 'modulo' => 'clientes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Registra un cobro a un cliente y lo imputa a sus facturas pendientes más viejas (o a la factura indicada). Medios: efectivo, transferencia, mercadopago, tarjeta, cheque.',
                'parametros' => ['cliente' => ['type' => 'string'], 'monto' => ['type' => 'number'], 'medio' => ['type' => 'string', 'enum' => self::MEDIOS], 'factura' => ['type' => 'string', 'description' => 'Número de la factura a cancelar (opcional).']],
                'requeridos' => ['cliente', 'monto'],
                'proponer' => function (User $u, array $a) {
                    $c = Busca::cliente($a['cliente']); $monto = round((float) $a['monto'], 2); $medio = in_array($a['medio'] ?? '', self::MEDIOS, true) ? $a['medio'] : 'efectivo';
                    if (! $c) return ['error' => "No encuentro un cliente parecido a \"{$a['cliente']}\"."];
                    if ($monto <= 0) return ['error' => 'El importe tiene que ser mayor a cero.'];
                    $pend = Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->get();
                    if (! empty($a['factura']) && ($f = Busca::comprobante($a['factura']))) $pend = $pend->sortBy(fn($x) => $x->id === $f->id ? 0 : 1)->values();
                    $imput = []; $resto = $monto;
                    foreach ($pend as $p) { if ($resto <= 0) break; $m = min($resto, (float) $p->saldo); $imput[] = ['comprobante_id' => $p->id, 'monto' => round($m, 2), 'numero' => $p->numeroFormateado()]; $resto = round($resto - $m, 2); }
                    return ['titulo' => 'Cobrar ' . Busca::pesos($monto) . " a {$c->name} en {$medio}", 'detalle' => ($imput ? 'Se imputa a ' . collect($imput)->map(fn($i) => "{$i['numero']} (" . Busca::pesos($i['monto']) . ')')->implode(', ') : 'Queda a cuenta') . ($resto > 0 && $imput ? ' y ' . Busca::pesos($resto) . ' a cuenta' : '') . '. Saldo actual ' . Busca::pesos($c->balance) . '.',
                        'datos' => ['contact_id' => $c->id, 'monto' => $monto, 'medio' => $medio, 'imputaciones' => array_map(fn($i) => ['comprobante_id' => $i['comprobante_id'], 'monto' => $i['monto']], $imput)]];
                },
                'confirmar' => function (User $u, array $d) {
                    $c = Contact::findOrFail($d['contact_id']);
                    $cobro = app(CobroService::class)->registrar($c, ['fecha' => today()->toDateString(), 'medios' => [['medio' => $d['medio'], 'monto' => $d['monto']]], 'imputaciones' => $d['imputaciones'] ?? [], 'notas' => 'Registrado por IA']);
                    return ['texto' => "Recibo {$cobro->numeroFormateado()} por " . Busca::pesos($d['monto']) . " a {$c->name}. Saldo ahora " . Busca::pesos($c->fresh()->balance) . '.', 'url' => Busca::url("/clientes/cobros/{$cobro->id}/imprimir")];
                }],
            // Fase 57: riesgo de mora y plan de pago propuesto.
            ['nombre' => 'riesgo_de_mora', 'titulo' => 'Riesgo de mora de los clientes', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Puntaje de riesgo de mora (0 a 100) con los motivos: cómo pagó siempre, lo vencido hoy, planes incumplidos. Con un cliente, su detalle; sin cliente, los deudores de mayor riesgo y los que conviene llamar antes de que venza.',
                'parametros' => ['cliente' => ['type' => 'string']],
                'ejecutar' => function (User $u, array $a) {
                    $r = app(\App\Services\Ventas\RiesgoMoraService::class);
                    if (! empty($a['cliente'])) { $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."]; return ['cliente' => $c->name] + $r->de($c); }
                    $deud = app(CobranzasService::class)->deudores(60); $ev = $deud->isNotEmpty() ? $r->evaluar(Contact::whereIn('id', $deud->pluck('id'))->get(), $deud->keyBy('id')->map(fn($d) => ['saldo' => $d['saldo'], 'vencido' => $d['vencido'], 'dias' => $d['dias']])->all()) : [];
                    return ['mayor_riesgo' => $deud->map(fn($d) => ['cliente' => $d['nombre'], 'saldo' => $d['saldo'], 'vencido' => $d['vencido']] + ($ev[$d['id']] ?? []))->sortByDesc('puntaje')->take(15)->values()->all(), 'llamar_antes' => $r->paraLlamarAntes($u->business), 'url' => Busca::url('/clientes/cobranzas')];
                }],
            ['nombre' => 'proponer_plan_pago', 'titulo' => 'Proponerle un plan de pago a un cliente', 'modulo' => 'clientes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Arma el plan de pago que el cliente puede pagar (cuotas según lo que compra por mes y cómo venía pagando) y se lo manda por WhatsApp o mail para que lo acepte. No crea el plan todavía: eso se hace cuando el cliente dice que sí.',
                'parametros' => ['cliente' => ['type' => 'string'], 'canal' => ['type' => 'string', 'enum' => ['whatsapp', 'mail']]], 'requeridos' => ['cliente'],
                'proponer' => function (User $u, array $a) {
                    $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."];
                    $s = app(\App\Services\Ventas\PlanSugeridoService::class)->sugerir($u->business, $c); if (! $s['ok']) return ['error' => $s['aviso']];
                    $canal = ($a['canal'] ?? 'whatsapp') === 'mail' ? 'mail' : 'whatsapp'; $dest = $canal === 'mail' ? $c->email : ($c->mobile ?: $c->phone);
                    if (! $dest) return ['error' => "{$c->name} no tiene " . ($canal === 'mail' ? 'email' : 'teléfono') . ' cargado.'];
                    return ['titulo' => "Proponerle a {$c->name} " . Busca::pesos($s['deuda']) . " en {$s['cuotas']} cuotas de " . Busca::pesos($s['cuota']), 'detalle' => $s['texto'], 'datos' => ['c' => $c->id, 'canal' => $canal, 'texto' => $s['texto']]];
                },
                'confirmar' => function (User $u, array $d) {
                    $c = Contact::findOrFail($d['c']); $f = Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->firstOrFail();
                    $e = app(\App\Services\Envios\EnvioService::class)->enviar($f, $d['canal'], $d['canal'] === 'mail' ? $c->email : ($c->mobile ?: $c->phone), 'recordatorio', $d['texto'], false);
                    return ['texto' => $e->estado === 'error' ? "No se pudo mandar: {$e->error}" : ($e->estado === 'pendiente' ? 'La propuesta quedó lista para mandar por WhatsApp.' : "Propuesta enviada a {$e->destino}."), 'url' => $e->link ?: Busca::url('/clientes/cobranzas')];
                }],
            ['nombre' => 'recordar_deuda', 'titulo' => 'Recordar una deuda', 'modulo' => 'clientes', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Manda a un cliente el recordatorio de su factura vencida más vieja, por WhatsApp o mail, con el texto configurado en Cobranzas.',
                'parametros' => ['cliente' => ['type' => 'string'], 'canal' => ['type' => 'string', 'enum' => ['whatsapp', 'mail']]], 'requeridos' => ['cliente'],
                'proponer' => function (User $u, array $a) {
                    $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."];
                    $v = Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->first();
                    if (! $v) return ['error' => "{$c->name} no tiene facturas pendientes."];
                    $canal = ($a['canal'] ?? 'whatsapp') === 'mail' ? 'mail' : 'whatsapp'; $dest = $canal === 'mail' ? $c->email : ($c->mobile ?: $c->phone);
                    if (! $dest) return ['error' => "{$c->name} no tiene " . ($canal === 'mail' ? 'email' : 'teléfono') . ' cargado.'];
                    return ['titulo' => "Recordar a {$c->name} la {$v->nombreTipo()} {$v->numeroFormateado()} (" . Busca::pesos($v->saldo) . ") por {$canal}", 'detalle' => "A {$dest}.", 'datos' => ['comprobante_id' => $v->id, 'canal' => $canal]];
                },
                'confirmar' => function (User $u, array $d) {
                    $e = app(CobranzasService::class)->recordar(Comprobante::findOrFail($d['comprobante_id']), $d['canal']);
                    return ['texto' => $e->estado === 'error' ? "No se pudo enviar: {$e->error}" : ($e->link ? 'Mensaje listo: abrí el link para mandarlo por WhatsApp.' : 'Recordatorio enviado.'), 'url' => $e->link];
                }],
        ];
    }

    public static function fila(Contact $c): array
    {
        return ['id' => $c->id, 'nombre' => $c->name, 'cuit' => $c->cuit ?: $c->document, 'condicion_iva' => $c->condicion_iva, 'saldo' => (float) $c->balance, 'email' => $c->email, 'telefono' => $c->mobile ?: $c->phone, 'url' => Busca::url("/clientes/{$c->id}")];
    }
}
