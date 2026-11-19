<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Cheque;
use App\Models\Comanda;
use App\Models\Cobro;
use App\Models\CuentaFondos;
use App\Models\Estadia;
use App\Models\Habitacion;
use App\Models\Mesa;
use App\Models\OrdenTrabajo;
use App\Models\PedidoWeb;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Sql;
use Illuminate\Support\Str;

// Fase 119.6: lo que todavía había que hacer con el mouse. Pedidos web, estado de las órdenes del taller, hotel (reserva,
// check-in, check-out), cobrar una mesa, acreditar o rechazar cheques, órdenes de producción e ingresos de plata.
// Cada herramienta llama al mismo servicio que usa la pantalla, así que valen las mismas reglas.
class Operaciones
{
    private static function pedido($t): ?PedidoWeb
    {
        $t = trim((string) $t); $n = (int) preg_replace('/\D/', '', $t);
        if ($n && ($p = PedidoWeb::where('numero', $n)->first() ?? PedidoWeb::find($n))) return $p;
        // El cliente del pedido es un dato compuesto (nombre, teléfono, dirección): se busca por nombre entre los abiertos.
        return $t !== '' ? PedidoWeb::whereIn('estado', ['nuevo', 'confirmado', 'preparando', 'enviado'])->latest('id')->limit(200)->get()->first(fn($p) => Busca::parecido(self::cli($p), $t)) : null;
    }

    private static function cli(PedidoWeb $p): string
    {
        $c = $p->cliente;
        return is_array($c) ? (string) ($c['nombre'] ?? $c['name'] ?? 'Sin nombre') : ((string) $c ?: 'Sin nombre');
    }

    private static function ot($t): ?OrdenTrabajo
    {
        $t = trim((string) $t); $n = (int) preg_replace('/\D/', '', $t);
        if ($n && ($o = OrdenTrabajo::where('numero', $n)->first() ?? OrdenTrabajo::find($n))) return $o;
        return $t !== '' ? OrdenTrabajo::whereNotIn('estado', ['entregado', 'cancelado'])->where(fn($w) => $w->where('nombre', Sql::like(), "%{$t}%")->orWhere('equipo', Sql::like(), "%{$t}%"))->latest('id')->first() : null;
    }

    // Estadía por huésped o por habitación, entre las que están en alguno de esos estados.
    private static function estadia($t, array $estados): ?Estadia
    {
        $t = trim((string) $t); if ($t === '') return null;
        $q = fn() => Estadia::with('habitacion')->whereIn('estado', $estados);
        if (ctype_digit($t) && ($e = $q()->find((int) $t))) return $e;
        return $q()->where('nombre', Sql::like(), "%{$t}%")->orderBy('desde')->first()
            ?? $q()->whereHas('habitacion', fn($h) => $h->where('nombre', Sql::like(), "%{$t}%"))->orderBy('desde')->first()
            ?? $q()->get()->first(fn($e) => Busca::parecido((string) $e->nombre, $t));
    }

    private static function comanda($t): ?Comanda
    {
        $t = trim((string) $t); if ($t === '') return null;
        $q = fn() => Comanda::with('mesa')->whereIn('estado', ['abierta', 'cuenta']);
        $nombre = trim(preg_replace('/^(la\s+)?mesa\s+/iu', '', $t));
        if ($m = Mesa::where('nombre', $nombre)->first() ?? Mesa::where('nombre', Sql::like(), "%{$nombre}%")->orderByRaw('LENGTH(nombre)')->first()) { if ($c = $q()->where('mesa_id', $m->id)->latest('id')->first()) return $c; }
        $n = (int) preg_replace('/\D/', '', $t);
        return ($n ? $q()->where('numero', $n)->first() : null) ?? $q()->where('cliente', Sql::like(), "%{$t}%")->latest('id')->first();
    }

    private static function pendienteDe(Comanda $c): float
    {
        return round($c->items()->where('estado', '!=', 'anulado')->get()->sum(fn($i) => ((float) $i->cantidad - (float) $i->pagado) * (float) $i->precio_unit) - (! $c->pagos ? (float) $c->descuento : 0), 2);
    }

    private static function cheque($t, array $estados): ?Cheque
    {
        $num = trim((string) $t); if ($num === '') return null;
        return Cheque::where('tipo', 'tercero')->whereIn('estado', $estados)->where('numero', Sql::like(), "%{$num}%")->orderByDesc('id')->first();
    }

    public static function herramientas(): array
    {
        return [
            // ── Pedidos web ─────────────────────────────────────────────────────────────────────────────────────────────
            ['nombre' => 'pedidos_web_pendientes', 'titulo' => 'Pedidos web por atender', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Pedidos que entraron por la tienda online, el portal, WhatsApp, MercadoLibre, Tienda Nube y demás canales y todavía no se entregaron: número, canal, cliente, total y estado.',
                'parametros' => ['estado' => ['type' => 'string', 'enum' => array_keys(PedidoWeb::ESTADOS), 'description' => 'Opcional. Por defecto, todos los que no están entregados ni cancelados.']],
                'ejecutar' => fn(User $u, array $a) => ['pedidos' => PedidoWeb::when(! empty($a['estado']), fn($q) => $q->where('estado', $a['estado']), fn($q) => $q->whereIn('estado', ['nuevo', 'confirmado', 'preparando', 'enviado']))->latest('id')->limit(40)->get()
                    ->map(fn($p) => ['numero' => $p->numeroFormateado(), 'canal' => PedidoWeb::CANALES[$p->canal] ?? $p->canal, 'cliente' => self::cli($p), 'total' => (float) $p->total, 'estado' => PedidoWeb::ESTADOS[$p->estado] ?? $p->estado, 'fecha' => $p->created_at?->format('d/m H:i')])->all(), 'pantalla' => Busca::url('/comprobantes/pedidos')]],
            ['nombre' => 'confirmar_pedido_web', 'titulo' => 'Confirmar un pedido web', 'modulo' => 'comprobantes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Confirma un pedido web nuevo: arma la factura (o el presupuesto) y reserva el stock, igual que el botón Confirmar de Vender → Pedidos web.',
                'parametros' => ['pedido' => ['type' => 'string', 'description' => 'Número del pedido (PW-00012, 12) o nombre del cliente.'], 'tipo' => ['type' => 'string', 'enum' => ['factura', 'presupuesto'], 'description' => 'Por defecto factura.'], 'entrega_pendiente' => ['type' => 'boolean', 'description' => 'true si se factura ahora y se entrega después.']],
                'requeridos' => ['pedido'],
                'proponer' => function (User $u, array $a) {
                    $p = self::pedido($a['pedido']); if (! $p) return ['error' => "No encuentro el pedido «{$a['pedido']}»."];
                    if ($p->estado !== 'nuevo') return ['error' => "El pedido {$p->numeroFormateado()} ya está " . Str::lower(PedidoWeb::ESTADOS[$p->estado] ?? $p->estado) . '.'];
                    $pre = ($a['tipo'] ?? 'factura') === 'presupuesto';
                    return ['titulo' => 'Confirmar el pedido ' . $p->numeroFormateado() . ' de ' . self::cli($p) . ' por ' . Busca::pesos($p->total), 'detalle' => ($pre ? 'Sale como presupuesto' : 'Se factura') . ' · ' . (PedidoWeb::CANALES[$p->canal] ?? $p->canal) . ' · ' . count((array) $p->items) . ' ítems',
                        'datos' => ['id' => $p->id, 'tipo' => $pre ? 'PRE' : 'FX', 'entrega_pendiente' => (bool) ($a['entrega_pendiente'] ?? false)]];
                },
                'confirmar' => function (User $u, array $d) {
                    $p = app(\App\Services\Canales\VentasTiendaService::class)->confirmar(PedidoWeb::findOrFail($d['id']), ['tipo' => $d['tipo'], 'emitir' => true, 'entrega_pendiente' => $d['entrega_pendiente']]);
                    return ['texto' => "Listo: pedido {$p->numeroFormateado()} confirmado, " . $p->comprobante->nombreTipo() . ' ' . $p->comprobante->numeroFormateado() . '.', 'url' => Busca::url("/comprobantes/{$p->comprobante_id}")];
                }],
            ['nombre' => 'cambiar_estado_pedido_web', 'titulo' => 'Avanzar o cancelar un pedido web', 'modulo' => 'comprobantes', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Cambia el estado de un pedido web: preparando, enviado (con número de seguimiento, avisa a la tienda), entregado o cancelado. Para confirmarlo usá confirmar_pedido_web.',
                'parametros' => ['pedido' => ['type' => 'string'], 'estado' => ['type' => 'string', 'enum' => ['preparando', 'enviado', 'entregado', 'cancelado']], 'tracking' => ['type' => 'string', 'description' => 'Número de seguimiento, si se despacha.']],
                'requeridos' => ['pedido', 'estado'],
                'proponer' => function (User $u, array $a) {
                    $p = self::pedido($a['pedido']); if (! $p) return ['error' => "No encuentro el pedido «{$a['pedido']}»."];
                    if (! in_array($a['estado'], ['preparando', 'enviado', 'entregado', 'cancelado'], true)) return ['error' => 'El estado puede ser preparando, enviado, entregado o cancelado.'];
                    if (in_array($p->estado, ['entregado', 'cancelado'], true)) return ['error' => "El pedido {$p->numeroFormateado()} ya está " . Str::lower(PedidoWeb::ESTADOS[$p->estado]) . '.'];
                    return ['titulo' => "Pedido {$p->numeroFormateado()} de " . self::cli($p) . ': ' . PedidoWeb::ESTADOS[$a['estado']], 'detalle' => 'Estaba ' . Str::lower(PedidoWeb::ESTADOS[$p->estado] ?? $p->estado) . (! empty($a['tracking']) ? " · seguimiento {$a['tracking']}" : '') . ($a['estado'] === 'cancelado' ? ' · se libera lo reservado' : ''),
                        'datos' => ['id' => $p->id, 'estado' => $a['estado'], 'tracking' => $a['tracking'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $p = PedidoWeb::findOrFail($d['id']);
                    app(\App\Services\Canales\TiendaService::class)->cambiarEstado($p, $d['estado']);
                    if ($d['estado'] === 'enviado') app(\App\Services\Canales\VentasTiendaService::class)->marcarEnviado($p, $d['tracking']);
                    app(\App\Services\Canales\DeliveryApiService::class)->estadoCambiado($p, $d['estado']);
                    return ['texto' => "Listo: {$p->numeroFormateado()} quedó " . Str::lower(PedidoWeb::ESTADOS[$d['estado']]) . '.', 'url' => Busca::url('/comprobantes/pedidos')];
                }],

            // ── Servicio técnico ────────────────────────────────────────────────────────────────────────────────────────
            ['nombre' => 'cambiar_estado_orden_servicio', 'titulo' => 'Avanzar una orden del taller', 'modulo' => 'servicios', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Pasa una orden de servicio a otro estado: diagnostico, presupuestado, aprobado, en_curso (en reparación), listo (listo para retirar: avisa al cliente por WhatsApp si se puede), entregado o cancelado. Para dejarla esperando un repuesto usá dejar_esperando.',
                'parametros' => ['orden' => ['type' => 'string', 'description' => 'Número de la orden (OT-00015, 15) o nombre del cliente o equipo.'], 'estado' => ['type' => 'string', 'enum' => ['recibido', 'diagnostico', 'presupuestado', 'aprobado', 'en_curso', 'listo', 'entregado', 'cancelado']]],
                'requeridos' => ['orden', 'estado'],
                'proponer' => function (User $u, array $a) {
                    $o = self::ot($a['orden']); if (! $o) return ['error' => "No encuentro la orden «{$a['orden']}»."];
                    if (! isset(OrdenTrabajo::ESTADOS[$a['estado']]) || $a['estado'] === 'esperando') return ['error' => 'Ese estado no existe. Probá con: diagnóstico, presupuestado, aprobado, en reparación, listo, entregado o cancelado.'];
                    if ($o->estado === $a['estado']) return ['error' => "La {$o->numeroFormateado()} ya está " . Str::lower(OrdenTrabajo::ESTADOS[$o->estado]) . '.'];
                    return ['titulo' => "{$o->numeroFormateado()} ({$o->equipo} de {$o->clienteNombre()}): " . OrdenTrabajo::ESTADOS[$a['estado']], 'detalle' => 'Estaba ' . Str::lower(OrdenTrabajo::ESTADOS[$o->estado] ?? $o->estado) . ($a['estado'] === 'listo' ? ' · se le avisa al cliente' : ''), 'datos' => ['id' => $o->id, 'estado' => $a['estado']]];
                },
                'confirmar' => function (User $u, array $d) {
                    $o = OrdenTrabajo::findOrFail($d['id']); $r = app(\App\Services\Servicios\OrdenesService::class)->estado($o, $d['estado']);
                    $aviso = ! empty($r['aviso']) ? (! empty($r['aviso']['enviado']) ? ' Le avisé al cliente por WhatsApp.' : ' Para avisarle al cliente, abrí la orden y tocá WhatsApp.') : '';
                    return ['texto' => "Listo: {$o->numeroFormateado()} quedó " . Str::lower(OrdenTrabajo::ESTADOS[$d['estado']]) . '.' . $aviso, 'url' => Busca::url("/servicios/{$o->id}")];
                }],

            // ── Hotelería ───────────────────────────────────────────────────────────────────────────────────────────────
            ['nombre' => 'ocupacion_hotel', 'titulo' => 'Cómo está el hotel hoy', 'modulo' => 'hoteleria', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Quiénes llegan hoy, quiénes están alojados, quiénes se van hoy y qué habitaciones están libres para un rango de fechas.',
                'parametros' => ['desde' => ['type' => 'string', 'description' => 'YYYY-MM-DD para ver disponibilidad (por defecto hoy).'], 'hasta' => ['type' => 'string', 'description' => 'YYYY-MM-DD (por defecto mañana).']],
                'ejecutar' => function (User $u, array $a) {
                    $hoy = today()->toDateString(); $desde = $a['desde'] ?? $hoy; $hasta = $a['hasta'] ?? today()->addDay()->toDateString();
                    $f = fn($e) => ['id' => $e->id, 'huesped' => $e->nombre, 'habitacion' => $e->habitacion?->nombre, 'desde' => \Carbon\Carbon::parse($e->desde)->format('d/m'), 'hasta' => \Carbon\Carbon::parse($e->hasta)->format('d/m'), 'personas' => $e->personas];
                    return ['llegan_hoy' => Estadia::with('habitacion')->where('estado', 'reservada')->whereDate('desde', $hoy)->get()->map($f)->all(),
                        'alojados' => Estadia::with('habitacion')->where('estado', 'checkin')->get()->map($f)->all(),
                        'se_van_hoy' => Estadia::with('habitacion')->where('estado', 'checkin')->whereDate('hasta', '<=', $hoy)->get()->map($f)->all(),
                        'libres_del_' . str_replace('-', '', $desde) . '_al_' . str_replace('-', '', $hasta) => collect(app(\App\Services\Hoteleria\HoteleriaService::class)->disponibles($desde, $hasta))->map(fn($h) => ['habitacion' => $h->nombre, 'tipo' => $h->tipo, 'capacidad' => $h->capacidad, 'tarifa' => (float) $h->tarifa])->values()->all()];
                }],
            ['nombre' => 'reservar_habitacion', 'titulo' => 'Reservar una habitación', 'modulo' => 'hoteleria', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Arma una reserva de hotel: habitación, huésped, fechas y cantidad de personas. Si no dice la habitación, se elige una libre con lugar.',
                'parametros' => ['huesped' => ['type' => 'string'], 'desde' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'hasta' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'personas' => ['type' => 'integer'], 'habitacion' => ['type' => 'string'], 'telefono' => ['type' => 'string'], 'notas' => ['type' => 'string']],
                'requeridos' => ['huesped', 'desde', 'hasta'],
                'proponer' => function (User $u, array $a) {
                    try { $desde = \Carbon\Carbon::parse($a['desde'])->toDateString(); $hasta = \Carbon\Carbon::parse($a['hasta'])->toDateString(); } catch (\Throwable $e) { return ['error' => 'No entendí las fechas. Decímelas como «del 20 al 23 de noviembre».']; }
                    if ($hasta <= $desde) return ['error' => 'La salida tiene que ser después de la entrada.'];
                    $pers = max(1, (int) ($a['personas'] ?? 2));
                    $libres = collect(app(\App\Services\Hoteleria\HoteleriaService::class)->disponibles($desde, $hasta));
                    $hab = ! empty($a['habitacion']) ? $libres->first(fn($h) => Str::lower($h->nombre) === Str::lower(trim($a['habitacion']))) ?? $libres->first(fn($h) => str_contains(Str::lower($h->nombre), Str::lower(trim($a['habitacion'])))) : $libres->filter(fn($h) => (int) $h->capacidad >= $pers)->sortBy('capacidad')->first();
                    if (! $hab) return ['error' => ! empty($a['habitacion']) ? "La habitación «{$a['habitacion']}» no está libre en esas fechas." : 'No hay habitaciones libres con lugar para esas fechas.'];
                    $noches = \Carbon\Carbon::parse($desde)->diffInDays(\Carbon\Carbon::parse($hasta));
                    return ['titulo' => "Reservar {$hab->nombre} para {$a['huesped']}, del " . \Carbon\Carbon::parse($desde)->format('d/m') . ' al ' . \Carbon\Carbon::parse($hasta)->format('d/m'), 'detalle' => "{$noches} " . ($noches === 1 ? 'noche' : 'noches') . " · {$pers} " . ($pers === 1 ? 'persona' : 'personas') . ' · ' . Busca::pesos((float) $hab->tarifa) . ' por noche',
                        'datos' => ['habitacion_id' => $hab->id, 'nombre' => trim((string) $a['huesped']), 'telefono' => $a['telefono'] ?? null, 'personas' => $pers, 'desde' => $desde, 'hasta' => $hasta, 'notas' => $a['notas'] ?? null, 'origen' => 'bigcito']];
                },
                'confirmar' => function (User $u, array $d) {
                    $e = app(\App\Services\Hoteleria\HoteleriaService::class)->reservar($d);
                    return ['texto' => "Listo: reserva de {$e->nombre} en {$e->habitacion->nombre}, del " . \Carbon\Carbon::parse($e->desde)->format('d/m') . ' al ' . \Carbon\Carbon::parse($e->hasta)->format('d/m') . '.', 'url' => Busca::url("/hoteleria/estadias/{$e->id}")];
                }],
            ['nombre' => 'checkin_huesped', 'titulo' => 'Hacer el check-in', 'modulo' => 'hoteleria', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Hace el check-in de una reserva de hotel (por nombre del huésped o habitación).',
                'parametros' => ['huesped' => ['type' => 'string', 'description' => 'Nombre del huésped, habitación o id de la estadía.']], 'requeridos' => ['huesped'],
                'proponer' => function (User $u, array $a) {
                    $e = self::estadia($a['huesped'], ['reservada']); if (! $e) return ['error' => "No encuentro una reserva pendiente de check-in para «{$a['huesped']}»."];
                    return ['titulo' => "Check-in de {$e->nombre} en {$e->habitacion?->nombre}", 'detalle' => 'Del ' . \Carbon\Carbon::parse($e->desde)->format('d/m') . ' al ' . \Carbon\Carbon::parse($e->hasta)->format('d/m') . " · {$e->personas} personas", 'datos' => ['id' => $e->id]];
                },
                'confirmar' => function (User $u, array $d) { $e = Estadia::with('habitacion')->findOrFail($d['id']); app(\App\Services\Hoteleria\HoteleriaService::class)->checkin($e); return ['texto' => "Listo: {$e->nombre} ya está alojado en {$e->habitacion?->nombre}.", 'url' => Busca::url("/hoteleria/estadias/{$e->id}")]; }],
            ['nombre' => 'checkout_huesped', 'titulo' => 'Hacer el check-out', 'modulo' => 'hoteleria', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Hace el check-out de un huésped alojado: factura las noches y los consumos. Queda la factura para cobrar (contado) o en cuenta corriente.',
                'parametros' => ['huesped' => ['type' => 'string', 'description' => 'Nombre del huésped, habitación o id de la estadía.'], 'condicion' => ['type' => 'string', 'enum' => ['contado', 'cta_cte']]], 'requeridos' => ['huesped'],
                'proponer' => function (User $u, array $a) {
                    $e = self::estadia($a['huesped'], ['checkin']); if (! $e) return ['error' => "No encuentro un huésped alojado para «{$a['huesped']}»."];
                    $cond = ($a['condicion'] ?? 'contado') === 'cta_cte' ? 'cta_cte' : 'contado';
                    return ['titulo' => "Check-out de {$e->nombre} ({$e->habitacion?->nombre})", 'detalle' => 'Se factura la estadía con sus consumos, ' . ($cond === 'cta_cte' ? 'a cuenta corriente' : 'de contado') . '.', 'datos' => ['id' => $e->id, 'condicion' => $cond]];
                },
                'confirmar' => function (User $u, array $d) {
                    $c = app(\App\Services\Hoteleria\HoteleriaService::class)->checkout(Estadia::findOrFail($d['id']), $d['condicion']);
                    return ['texto' => "Listo: check-out hecho, {$c->nombreTipo()} {$c->numeroFormateado()} por " . Busca::pesos($c->total) . '.' . ($d['condicion'] === 'contado' ? ' Falta cobrarla.' : ''), 'url' => Busca::url("/comprobantes/{$c->id}")];
                }],

            // ── Big Restó ───────────────────────────────────────────────────────────────────────────────────────────────
            ['nombre' => 'cobrar_mesa', 'titulo' => 'Cerrar y cobrar una mesa', 'modulo' => 'gastronomia', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Cierra una mesa (o un pedido de mostrador o delivery) y la cobra entera con un medio de pago: factura, cobro y mesa libre. Para dividir la cuenta hay que hacerlo desde el salón.',
                'parametros' => ['mesa' => ['type' => 'string', 'description' => 'Nombre o número de la mesa («mesa 4», «terraza 2»), número de comanda o nombre del cliente.'], 'medio' => ['type' => 'string', 'enum' => ['efectivo', 'tarjeta', 'transferencia', 'mercadopago', 'billetera'], 'description' => 'Por defecto efectivo.'], 'propina' => ['type' => 'number'], 'descuento' => ['type' => 'number', 'description' => 'Descuento en pesos.']],
                'requeridos' => ['mesa'],
                'proponer' => function (User $u, array $a) {
                    $c = self::comanda($a['mesa']); if (! $c) return ['error' => "No encuentro una mesa abierta que sea «{$a['mesa']}»."];
                    $medio = in_array($a['medio'] ?? 'efectivo', ['efectivo', 'tarjeta', 'transferencia', 'mercadopago', 'billetera'], true) ? ($a['medio'] ?? 'efectivo') : 'efectivo';
                    $desc = max(0, round((float) ($a['descuento'] ?? 0), 2)); $prop = max(0, round((float) ($a['propina'] ?? 0), 2));
                    if ($desc > 0 && $c->pagos) return ['error' => 'Esta mesa ya tiene un pago parcial: el descuento hay que aplicarlo desde el salón.'];
                    $pend = round(self::pendienteDe($c) - ($desc > 0 ? $desc - (float) $c->descuento : 0), 2);
                    if ($pend <= 0) return ['error' => "{$c->titulo()} no tiene nada pendiente de cobrar."];
                    return ['titulo' => "Cerrar {$c->titulo()} y cobrar " . Busca::pesos($pend + $prop) . ' en ' . Str::lower(Cobro::MEDIOS[$medio]), 'detalle' => 'Consumo ' . Busca::pesos($pend) . ($prop > 0 ? ' + propina ' . Busca::pesos($prop) : '') . ($desc > 0 ? ' (con descuento de ' . Busca::pesos($desc) . ')' : ''),
                        'datos' => ['id' => $c->id, 'medio' => $medio, 'monto' => round($pend + $prop, 2), 'propina' => $prop, 'descuento' => $desc > 0 ? $desc : null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $c = Comanda::findOrFail($d['id']);
                    $r = app(\App\Services\Gastronomia\ComandaService::class)->cobrar($c, array_filter(['descuento' => $d['descuento'], 'propina' => $d['propina'] ?: null, 'medios' => [['medio' => $d['medio'], 'monto' => $d['monto']]]], fn($v) => $v !== null), null);
                    $f = $r['comprobante'];
                    return ['texto' => "Listo: {$r['comanda']->titulo()} cerrada, {$f->nombreTipo()} {$f->numeroFormateado()} por " . Busca::pesos($f->total) . '.', 'url' => Busca::url("/comprobantes/{$f->id}")];
                }],

            // ── Cheques ─────────────────────────────────────────────────────────────────────────────────────────────────
            ['nombre' => 'acreditar_cheque', 'titulo' => 'Marcar un cheque como acreditado', 'modulo' => 'fondos', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Marca como acreditado (cobrado) un cheque de terceros que ya estaba depositado en el banco.',
                'parametros' => ['cheque' => ['type' => 'string', 'description' => 'Número del cheque']], 'requeridos' => ['cheque'],
                'proponer' => function (User $u, array $a) {
                    $ch = self::cheque($a['cheque'], ['depositado']); if (! $ch) return ['error' => "No encuentro un cheque depositado con número {$a['cheque']}. Si está en cartera, primero hay que depositarlo."];
                    return ['titulo' => "Acreditar el cheque {$ch->numero} de {$ch->banco} por " . Busca::pesos($ch->monto), 'detalle' => 'Emisor ' . ($ch->emisor ?: '—'), 'datos' => ['id' => $ch->id]];
                },
                'confirmar' => function (User $u, array $d) { $ch = app(\App\Services\Fondos\ChequeService::class)->marcarCobrado(Cheque::findOrFail($d['id'])); return ['texto' => "Listo: el cheque {$ch->numero} quedó acreditado.", 'url' => Busca::url('/fondos/cheques')]; }],
            ['nombre' => 'rechazar_cheque', 'titulo' => 'Registrar un cheque rechazado', 'modulo' => 'fondos', 'permiso' => 'editar', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Registra que un cheque de terceros vino rechazado: si estaba depositado saca la plata del banco y la deuda vuelve a la cuenta del cliente.',
                'parametros' => ['cheque' => ['type' => 'string', 'description' => 'Número del cheque'], 'motivo' => ['type' => 'string', 'description' => 'Sin fondos, firma, etc.']], 'requeridos' => ['cheque', 'motivo'],
                'proponer' => function (User $u, array $a) {
                    $ch = self::cheque($a['cheque'], ['cartera', 'depositado']); if (! $ch) return ['error' => "No encuentro un cheque en cartera o depositado con número {$a['cheque']}."];
                    return ['titulo' => "Rechazo del cheque {$ch->numero} de {$ch->banco} por " . Busca::pesos($ch->monto), 'detalle' => 'Motivo: ' . trim((string) $a['motivo']) . ' · emisor ' . ($ch->emisor ?: '—') . ($ch->estado === 'depositado' ? ' · sale del banco' : ''), 'datos' => ['id' => $ch->id, 'motivo' => mb_substr(trim((string) $a['motivo']), 0, 255)]];
                },
                'confirmar' => function (User $u, array $d) { $ch = app(\App\Services\Fondos\ChequeService::class)->rechazar(Cheque::findOrFail($d['id']), $d['motivo']); return ['texto' => "Listo: el cheque {$ch->numero} quedó como rechazado.", 'url' => Busca::url('/fondos/cheques')]; }],

            // ── Producción ──────────────────────────────────────────────────────────────────────────────────────────────
            ['nombre' => 'ordenes_produccion', 'titulo' => 'Órdenes de producción abiertas', 'modulo' => 'produccion', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Órdenes de producción pendientes y en curso: número, producto, cantidad y estado. También lista las fórmulas (recetas) activas.',
                'ejecutar' => fn(User $u, array $a) => ['abiertas' => ProductionOrder::with('product:id,name,unit')->whereIn('status', ['pending', 'in_progress'])->orderBy('id')->limit(40)->get()->map(fn($o) => ['numero' => $o->numeroFormateado(), 'producto' => $o->product?->name, 'cantidad' => Busca::cant($o->quantity), 'unidad' => $o->product?->unit, 'estado' => $o->status === 'pending' ? 'pendiente' : 'en curso'])->all(),
                    'formulas' => Recipe::where('is_active', true)->orderBy('name')->limit(60)->get()->map(fn($r) => ['formula' => $r->name, 'rinde' => Busca::cant($r->yield_quantity) . ' ' . $r->yield_unit])->all()]],
            ['nombre' => 'crear_orden_produccion', 'titulo' => 'Crear una orden de producción', 'modulo' => 'produccion', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Crea una orden de producción para fabricar un producto con su fórmula: «hacé 50 kilos de pan».',
                'parametros' => ['producto' => ['type' => 'string', 'description' => 'Nombre del producto elaborado o de la fórmula.'], 'cantidad' => ['type' => 'number'], 'notas' => ['type' => 'string']], 'requeridos' => ['producto', 'cantidad'],
                'proponer' => function (User $u, array $a) {
                    $t = trim((string) $a['producto']); $cant = round((float) $a['cantidad'], 3); if ($cant <= 0) return ['error' => '¿Cuánto hay que producir?'];
                    $r = Recipe::where('is_active', true)->where('name', Sql::like(), "%{$t}%")->orderByRaw('LENGTH(name)')->first() ?? (($p = Busca::articulo($t)) ? Recipe::where('is_active', true)->where('product_id', $p->id)->first() : null)
                        ?? Recipe::where('is_active', true)->get()->first(fn($x) => Busca::parecido($x->name, $t));
                    if (! $r) return ['error' => "No encuentro una fórmula para «{$t}». Las fórmulas se cargan en Producción → Fórmulas."];
                    return ['titulo' => 'Orden de producción: ' . Busca::cant($cant) . " {$r->yield_unit} de {$r->name}", 'detalle' => 'Al terminarla se descuentan los insumos y entra el producto al depósito.', 'datos' => ['recipe_id' => $r->id, 'quantity' => $cant, 'notes' => $a['notas'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) { $o = app(\App\Services\Produccion\ProduccionService::class)->crearOrden($d); return ['texto' => "Listo: {$o->numeroFormateado()} creada.", 'url' => Busca::url('/produccion')]; }],
            ['nombre' => 'terminar_orden_produccion', 'titulo' => 'Terminar una orden de producción', 'modulo' => 'produccion', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Termina una orden de producción: descuenta los insumos, ingresa el producto terminado al depósito y actualiza su costo. Si salió otra cantidad, pasala en producida.',
                'parametros' => ['orden' => ['type' => 'string', 'description' => 'Número de la orden (OP 000012, 12) o nombre del producto.'], 'producida' => ['type' => 'number', 'description' => 'Cantidad que salió de verdad, si no fue la pedida.']], 'requeridos' => ['orden'],
                'proponer' => function (User $u, array $a) {
                    $t = trim((string) $a['orden']); $n = (int) preg_replace('/\D/', '', $t); $q = fn() => ProductionOrder::with('product:id,name,unit')->whereIn('status', ['pending', 'in_progress']);
                    $o = ($n ? $q()->where('numero', $n)->first() ?? $q()->find($n) : null) ?? $q()->whereHas('product', fn($p) => $p->where('name', Sql::like(), "%{$t}%"))->orderBy('id')->first();
                    if (! $o) return ['error' => "No encuentro una orden de producción abierta que sea «{$t}»."];
                    $prod = isset($a['producida']) ? round((float) $a['producida'], 3) : null;
                    return ['titulo' => "Terminar {$o->numeroFormateado()}: " . Busca::cant($prod ?? $o->quantity) . " {$o->product?->unit} de {$o->product?->name}", 'detalle' => 'Descuenta los insumos e ingresa el producto al depósito.' . ($prod !== null ? ' Se pidió ' . Busca::cant($o->quantity) . '.' : ''), 'datos' => ['id' => $o->id, 'producida' => $prod]];
                },
                'confirmar' => function (User $u, array $d) {
                    $o = app(\App\Services\Produccion\ProduccionService::class)->terminar(ProductionOrder::findOrFail($d['id']), $d['producida']);
                    return ['texto' => "Listo: {$o->numeroFormateado()} terminada, entraron " . Busca::cant($o->cantidad_producida) . " {$o->product?->unit} de {$o->product?->name} al depósito.", 'url' => Busca::url('/produccion')];
                }],

            // ── Plata ───────────────────────────────────────────────────────────────────────────────────────────────────
            ['nombre' => 'registrar_ingreso', 'titulo' => 'Registrar un ingreso de plata', 'modulo' => 'fondos', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Registra plata que entra a una caja o banco y no es un cobro a un cliente: aporte del dueño, préstamo, reintegro, venta de un bien. Para lo que paga un cliente usá registrar_cobro.',
                'parametros' => ['concepto' => ['type' => 'string'], 'monto' => ['type' => 'number'], 'cuenta' => ['type' => 'string', 'description' => 'caja, banco o el nombre de la cuenta. Por defecto, la caja.'], 'fecha' => ['type' => 'string']],
                'requeridos' => ['concepto', 'monto'],
                'proponer' => function (User $u, array $a) {
                    $monto = round((float) $a['monto'], 2); if ($monto <= 0) return ['error' => '¿De cuánto es el ingreso?'];
                    $concepto = trim((string) $a['concepto']) ?: 'Ingreso';
                    $c = in_array($a['cuenta'] ?? 'caja', ['caja', 'banco', ''], true)
                        ? (CuentaFondos::where('activa', true)->where('tipo', ($a['cuenta'] ?? 'caja') === 'banco' ? 'banco' : 'caja')->where(fn($w) => $w->where('business_location_id', $u->current_location_id)->orWhereNull('business_location_id'))->orderByDesc('es_default')->first() ?? Busca::cuenta($a['cuenta'] ?? 'caja'))
                        : Busca::cuenta($a['cuenta'], null);
                    if (! $c) return ['error' => 'No encuentro la cuenta a la que entra la plata.'];
                    return ['titulo' => 'Ingreso de ' . Busca::pesos($monto) . ": {$concepto}", 'detalle' => "Entra a {$c->nombre}.", 'datos' => ['cuenta_id' => $c->id, 'concepto' => $concepto, 'ingreso' => $monto, 'fecha' => $a['fecha'] ?? today()->toDateString()]];
                },
                'confirmar' => function (User $u, array $d) {
                    $c = CuentaFondos::findOrFail($d['cuenta_id']);
                    app(\App\Services\Fondos\FondosService::class)->registrar($c, ['origen' => 'ingreso', 'concepto' => $d['concepto'], 'ingreso' => $d['ingreso'], 'fecha' => $d['fecha']]);
                    return ['texto' => 'Listo: ingreso de ' . Busca::pesos($d['ingreso']) . " por {$d['concepto']} en {$c->nombre}. Saldo " . Busca::pesos($c->fresh()->saldo) . '.', 'url' => Busca::url("/fondos?cuenta={$c->id}")];
                }],
        ];
    }
}
