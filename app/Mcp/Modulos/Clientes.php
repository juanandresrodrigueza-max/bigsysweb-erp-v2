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
            ['nombre' => 'buscar_clientes', 'titulo' => 'Buscar clientes', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Busca clientes por nombre, CUIT, teléfono o email. Devuelve id, CUIT, condición de IVA, saldo y contacto.',
                'parametros' => ['texto' => ['type' => 'string'], 'cantidad' => ['type' => 'integer']], 'requeridos' => ['texto'],
                'ejecutar' => function (User $u, array $a) {
                    $t = trim((string) $a['texto']); $like = \App\Support\Sql::like();
                    return ['clientes' => Contact::customers()->where(fn($w) => $w->where('name', $like, "%{$t}%")->orWhere('cuit', $like, "%{$t}%")->orWhere('email', $like, "%{$t}%")->orWhere('phone', $like, "%{$t}%")->orWhere('mobile', $like, "%{$t}%"))
                        ->orderByRaw('LENGTH(name)')->limit(min(50, (int) ($a['cantidad'] ?? 10)))->get()->map(fn($c) => self::fila($c))->all()];
                }],
            ['nombre' => 'ficha_cliente', 'titulo' => 'Ficha del cliente', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Todo de un cliente: datos, saldo, lo vencido, facturas pendientes, últimas compras, contactos y condiciones (lista de precios, descuento, días de pago).',
                'parametros' => ['cliente' => ['type' => 'string', 'description' => 'Nombre, CUIT o id.']], 'requeridos' => ['cliente'],
                'ejecutar' => function (User $u, array $a) {
                    $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro un cliente parecido a \"{$a['cliente']}\"."];
                    $pend = Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->limit(30)->get();
                    return self::fila($c) + ['direccion' => trim("{$c->address} {$c->city}"), 'lista_precios' => (int) $c->lista_precios, 'descuento' => (float) $c->descuento, 'dias_pago' => (int) $c->dias_pago, 'limite_credito' => (float) $c->credit_limit,
                        'vencido' => round((float) $pend->filter(fn($x) => $x->fecha_vto && $x->fecha_vto->lt(today()))->sum('saldo'), 2),
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
                    $iva = $a['condicion_iva'] ?? (strlen($doc) === 11 ? 'Responsable Inscripto' : 'Consumidor Final');
                    if (in_array($iva, ['Responsable Inscripto', 'Monotributista'], true) && strlen($doc) !== 11) return ['error' => "Un {$iva} necesita CUIT."];
                    return ['titulo' => "Dar de alta a {$a['nombre']}", 'detalle' => trim("{$iva}" . ($doc ? " · " . (strlen($doc) === 11 ? 'CUIT' : 'DNI') . " {$doc}" : '') . (! empty($a['email']) ? " · {$a['email']}" : '') . (! empty($a['telefono']) ? " · {$a['telefono']}" : '')),
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
