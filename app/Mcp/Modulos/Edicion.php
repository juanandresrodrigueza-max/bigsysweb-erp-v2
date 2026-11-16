<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Mcp\Catalogo;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Cheque;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Empleado;
use App\Models\OrdenCompra;
use App\Models\Pago;
use App\Models\Product;
use App\Models\Role;
use App\Models\Rubro;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Compras\CompraService;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Fase 109 · Todo por voz: lo que faltaba para que cualquier cosa del día se pueda pedir hablando. Editar, dar de baja, cargar
// compras, notas de crédito y remitos, usuarios, empleados, turnos, cheques y la caja. Siempre con propuesta → confirmación,
// con el permiso del módulo (y las bajas, además, con permiso de anular). Y «¿qué podés hacer?» para que Bigcito lo cuente.
class Edicion
{
    private const CLIENTE = ['nombre' => 'name', 'cuit' => 'cuit', 'condicion_iva' => 'condicion_iva', 'email' => 'email', 'telefono' => 'phone', 'celular' => 'mobile', 'direccion' => 'address', 'ciudad' => 'city', 'provincia' => 'province',
        'lista_precios' => 'lista_precios', 'dias_pago' => 'dias_pago', 'limite_credito' => 'credit_limit', 'descuento' => 'descuento', 'interes_mora' => 'interes_mora', 'comprobante_interno' => 'comprobante_interno', 'activo' => 'is_active'];
    private const PROVEEDOR = ['nombre' => 'name', 'cuit' => 'cuit', 'condicion_iva' => 'condicion_iva', 'email' => 'email', 'telefono' => 'phone', 'direccion' => 'address', 'ciudad' => 'city', 'provincia' => 'province', 'dias_pago' => 'dias_pago', 'notas' => 'notes', 'activo' => 'is_active'];
    private const ARTICULO = ['nombre' => 'name', 'codigo' => 'sku', 'codigo_barras' => 'barcode', 'marca' => 'marca', 'descripcion' => 'description', 'precio' => 'price', 'costo' => 'cost', 'iva' => 'iva', 'unidad' => 'unit', 'stock_minimo' => 'stock_min',
        'margen_objetivo' => 'margen_objetivo', 'activo' => 'active', 'controla_stock' => 'controla_stock', 'rubro' => 'rubro_id', 'proveedor' => 'proveedor_id'];

    public static function herramientas(): array
    {
        return [
            // ---- ¿Qué podés hacer? ------------------------------------------------------------------------------------------
            ['nombre' => 'que_podes_hacer', 'titulo' => 'Qué puede hacer Bigcito por vos', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Lista las acciones que este usuario puede pedirle a Bigcito (según sus permisos), agrupadas por módulo, con ejemplos de cómo pedirlas. Usala cuando pregunten «¿qué podés hacer?», «¿podés cargar un cliente?».',
                'parametros' => ['modulo' => ['type' => 'string', 'description' => 'Sólo un módulo (ventas, clientes, stock, compras, plata, contable, sueldos…)']],
                'ejecutar' => function (User $u, array $a) {
                    $cat = app(Catalogo::class); $todas = collect($cat->disponibles($u))->where('tipo', 'accion');
                    $mods = config('erp.modulos'); $filtro = mb_strtolower(trim((string) ($a['modulo'] ?? '')));
                    $grupos = $todas->groupBy(fn($h) => $h['modulo'] ?? 'general')->map(fn($g, $m) => ['modulo' => $mods[$m]['label'] ?? ucfirst($m), 'acciones' => $g->map(fn($h) => ['accion' => $h['nombre'], 'que_hace' => $h['titulo'] ?? $h['nombre'], 'delicada' => (bool) $h['delicada']])->values()->all()])
                        ->filter(fn($g) => $filtro === '' || str_contains(mb_strtolower($g['modulo']), $filtro))->values();
                    return ['total_acciones' => $grupos->sum(fn($g) => count($g['acciones'])), 'regla' => 'Toda acción se propone primero y se ejecuta sólo cuando el usuario confirma. Lo que el usuario no puede hacer en pantalla tampoco lo puede pedir por acá.', 'grupos' => $grupos->all(),
                        'ejemplos' => ['«facturale a Pérez dos bolsas de cemento»', '«asentá el pago de 50.000 a Distribuidora Norte por transferencia»', '«cargá el cliente Juan López, monotributista, CUIT 20-…»', '«cambiale el teléfono a López»', '«dá de baja el artículo Tornillo 3 mm»', '«hacé la nota de crédito de la factura 27 por 10.000»', '«agendá a Gómez el martes a las 10»', '«cerrá la caja con 85.300»']];
                }],

            // ---- Editar --------------------------------------------------------------------------------------------------------
            ['nombre' => 'editar_cliente', 'titulo' => 'Cambiar datos de un cliente', 'modulo' => 'clientes', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Cambia uno o varios datos de un cliente: nombre, CUIT, condición IVA, email, teléfono, celular, dirección, ciudad, provincia, lista de precios, días de pago, límite de crédito, descuento, interés de mora, comprobante interno por defecto, activo.',
                'parametros' => ['cliente' => ['type' => 'string'], 'cambios' => ['type' => 'object', 'description' => 'Campo → valor nuevo. Campos: ' . implode(', ', array_keys(self::CLIENTE))]], 'requeridos' => ['cliente', 'cambios'],
                'proponer' => function (User $u, array $a) { $c = Busca::cliente($a['cliente']); if (! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."]; return self::proponerCambios($c, (array) $a['cambios'], self::CLIENTE, "cliente {$c->name}"); },
                'confirmar' => fn(User $u, array $d) => self::aplicarCambios(Contact::customers()->findOrFail($d['id']), $d['cambios'], 'Cliente')],
            ['nombre' => 'editar_proveedor', 'titulo' => 'Cambiar datos de un proveedor', 'modulo' => 'proveedores', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Cambia datos de un proveedor: nombre, CUIT, condición IVA, email, teléfono, dirección, ciudad, provincia, días de pago, notas, activo.',
                'parametros' => ['proveedor' => ['type' => 'string'], 'cambios' => ['type' => 'object', 'description' => 'Campo → valor nuevo. Campos: ' . implode(', ', array_keys(self::PROVEEDOR))]], 'requeridos' => ['proveedor', 'cambios'],
                'proponer' => function (User $u, array $a) { $c = Busca::proveedor($a['proveedor']); if (! $c) return ['error' => "No encuentro el proveedor \"{$a['proveedor']}\"."]; return self::proponerCambios($c, (array) $a['cambios'], self::PROVEEDOR, "proveedor {$c->name}"); },
                'confirmar' => fn(User $u, array $d) => self::aplicarCambios(Contact::suppliers()->findOrFail($d['id']), $d['cambios'], 'Proveedor')],
            ['nombre' => 'editar_articulo', 'titulo' => 'Cambiar datos de un artículo', 'modulo' => 'stock', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Cambia datos de un artículo: nombre, código, código de barras, marca, descripción, precio (lista 1), costo, IVA, unidad, stock mínimo, margen objetivo, rubro, proveedor habitual, activo, controla stock. Para varios precios usá cambiar_precio o precios_en_masa.',
                'parametros' => ['articulo' => ['type' => 'string'], 'cambios' => ['type' => 'object', 'description' => 'Campo → valor nuevo. Campos: ' . implode(', ', array_keys(self::ARTICULO)) . '. rubro y proveedor por nombre.']], 'requeridos' => ['articulo', 'cambios'],
                'proponer' => function (User $u, array $a) {
                    $p = Busca::articulo($a['articulo']); if (! $p) return ['error' => "No encuentro el artículo \"{$a['articulo']}\"."];
                    $cambios = (array) $a['cambios'];
                    if (array_key_exists('rubro', $cambios)) { $r = Rubro::where('nombre', \App\Support\Sql::like(), '%' . $cambios['rubro'] . '%')->first(); if (! $r) return ['error' => "No encuentro el rubro \"{$cambios['rubro']}\"."]; $cambios['rubro'] = $r->id; }
                    if (array_key_exists('proveedor', $cambios)) { $pr = Busca::proveedor($cambios['proveedor']); if (! $pr) return ['error' => "No encuentro el proveedor \"{$cambios['proveedor']}\"."]; $cambios['proveedor'] = $pr->id; }
                    return self::proponerCambios($p, $cambios, self::ARTICULO, "artículo {$p->name}");
                },
                'confirmar' => function (User $u, array $d) { $p = Product::findOrFail($d['id']); if (array_key_exists('price', $d['cambios'])) $d['cambios']['precio_actualizado_en'] = now(); return self::aplicarCambios($p, $d['cambios'], 'Artículo'); }],
            ['nombre' => 'editar_usuario', 'titulo' => 'Cambiar rol o estado de un usuario', 'modulo' => 'configuracion', 'permiso' => 'editar', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Cambia el rol de un usuario de la empresa, lo activa o lo desactiva (no puede entrar más). El dueño no se desactiva.',
                'parametros' => ['usuario' => ['type' => 'string', 'description' => 'Nombre o email'], 'rol' => ['type' => 'string'], 'activo' => ['type' => 'boolean']], 'requeridos' => ['usuario'],
                'proponer' => function (User $u, array $a) {
                    $x = self::usuario($u, $a['usuario']); if (! $x) return ['error' => "No encuentro el usuario \"{$a['usuario']}\"."];
                    if ($x->id === $u->business->owner_id && array_key_exists('activo', $a) && ! $a['activo']) return ['error' => 'El dueño de la empresa no se puede desactivar.'];
                    $rol = ! empty($a['rol']) ? self::rol($a['rol']) : null; if (! empty($a['rol']) && ! $rol) return ['error' => "No encuentro el rol \"{$a['rol']}\". Roles: " . Role::pluck('nombre')->implode(', ') . '.'];
                    $que = array_filter([$rol ? "rol {$x->role?->nombre} → {$rol->nombre}" : null, array_key_exists('activo', $a) ? ($a['activo'] ? 'activar' : 'desactivar (no entra más)') : null]);
                    if (! $que) return ['error' => '¿Qué le cambio: el rol o si está activo?'];
                    return ['titulo' => "Usuario {$x->name}: " . implode(', ', $que), 'detalle' => $x->email, 'datos' => ['id' => $x->id, 'role_id' => $rol?->id, 'activo' => $a['activo'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $x = User::where('business_id', $u->business_id)->findOrFail($d['id']);
                    if ($d['role_id']) $x->role_id = $d['role_id']; if ($d['activo'] !== null) $x->status = $d['activo'] ? 'active' : 'inactive'; $x->save();
                    AuditLog::registrar('editar', $x, "Usuario {$x->email} (por Bigcito)"); \App\Services\Integraciones\CrmSyncService::encolar($u->business_id, 'usuarios', [$x->id]);
                    return ['texto' => "Listo: {$x->name} " . ($x->status === 'active' ? 'activo' : 'desactivado') . ", rol {$x->fresh()->role?->nombre}."];
                }],

            // ---- Dar de baja ---------------------------------------------------------------------------------------------------
            ['nombre' => 'dar_de_baja', 'titulo' => 'Dar de baja, borrar o anular', 'modulo' => null, 'permiso' => null, 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Da de baja lo que se pida, con la regla de cada cosa: un cliente, proveedor o artículo con movimientos se desactiva (no se borra); sin movimientos se borra. Un presupuesto o borrador se borra. Un cobro, un pago a proveedor, un gasto o una orden de compra se anulan (con motivo). Las facturas emitidas se anulan con anular_comprobante o con nota de crédito. Pide el permiso de anular del módulo que corresponda.',
                'parametros' => ['tipo' => ['type' => 'string', 'enum' => ['cliente', 'proveedor', 'articulo', 'presupuesto', 'borrador', 'cobro', 'pago', 'gasto', 'orden_compra', 'turno']], 'cual' => ['type' => 'string', 'description' => 'Nombre, código o número'], 'motivo' => ['type' => 'string']], 'requeridos' => ['tipo', 'cual'],
                'proponer' => fn(User $u, array $a) => self::proponerBaja($u, $a),
                'confirmar' => fn(User $u, array $d) => self::ejecutarBaja($u, $d)],

            // ---- Altas que faltaban --------------------------------------------------------------------------------------------
            ['nombre' => 'crear_proveedor', 'titulo' => 'Dar de alta un proveedor', 'modulo' => 'proveedores', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Da de alta un proveedor. Con CUIT y certificado de ARCA, el padrón completa nombre, condición y domicilio.',
                'parametros' => ['nombre' => ['type' => 'string'], 'cuit' => ['type' => 'string'], 'condicion_iva' => ['type' => 'string', 'enum' => Contact::CONDICIONES_IVA], 'email' => ['type' => 'string'], 'telefono' => ['type' => 'string'], 'direccion' => ['type' => 'string'], 'dias_pago' => ['type' => 'integer']], 'requeridos' => ['nombre'],
                'proponer' => function (User $u, array $a) {
                    $doc = preg_replace('/\D/', '', (string) ($a['cuit'] ?? ''));
                    if ($doc !== '' && strlen($doc) === 11 && ! \App\Support\Cuit::valido($doc)) return ['error' => 'El CUIT no es válido.'];
                    if (($ya = Busca::proveedor($a['nombre'])) && mb_strtolower($ya->name) === mb_strtolower($a['nombre'])) return ['error' => "Ya existe el proveedor {$ya->name}."];
                    if ($doc && ($ya = Contact::suppliers()->where('cuit', \App\Support\Cuit::formatear($doc))->first())) return ['error' => "Ese CUIT ya es de {$ya->name}."];
                    $pad = null; if (strlen($doc) === 11 && app(\App\Services\Comprobantes\AfipEmisor::class)->configurado($u->business)) { try { $pad = \App\Services\Afip\AfipService::forBusiness($u->business)->padron($doc); } catch (\Throwable) {} }
                    $datos = ['name' => $a['nombre'] ?: ($pad['nombre'] ?? ''), 'cuit' => $doc ? \App\Support\Cuit::formatear($doc) : null, 'condicion_iva' => $a['condicion_iva'] ?? ($pad['condicion_iva'] ?? (strlen($doc) === 11 ? 'Responsable Inscripto' : 'Consumidor Final')),
                        'email' => $a['email'] ?? null, 'phone' => $a['telefono'] ?? null, 'address' => $a['direccion'] ?? ($pad['direccion'] ?? null), 'city' => $pad['localidad'] ?? null, 'province' => $pad['provincia'] ?? null, 'dias_pago' => (int) ($a['dias_pago'] ?? 0), 'is_active' => true];
                    return ['titulo' => "Dar de alta al proveedor {$datos['name']}" . ($pad ? ' (datos del padrón)' : ''), 'detalle' => trim("{$datos['condicion_iva']}" . ($datos['cuit'] ? " · {$datos['cuit']}" : '') . ($datos['dias_pago'] ? " · paga a {$datos['dias_pago']} días" : '')), 'datos' => $datos];
                },
                'confirmar' => function (User $u, array $d) { $c = Contact::create($d + ['business_id' => $u->business_id, 'type' => 'supplier']); AuditLog::registrar('crear', $c, "Proveedor {$c->name} (por Bigcito)"); return ['texto' => "Proveedor {$c->name} dado de alta.", 'proveedor_id' => $c->id, 'url' => Busca::url("/proveedores/{$c->id}")]; }],
            ['nombre' => 'cargar_compra', 'titulo' => 'Cargar una factura de proveedor', 'modulo' => 'proveedores', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Carga y registra una factura de compra: proveedor, tipo (A, B o C), número (punto de venta-número), fecha, y los renglones (artículo o descripción, cantidad, precio sin IVA, alícuota) o directamente el total con IVA incluido. Mueve stock, costo y cuenta corriente del proveedor. Para leerla de una foto, mandá la foto a Bigcito.',
                'parametros' => ['proveedor' => ['type' => 'string'], 'tipo' => ['type' => 'string', 'enum' => ['A', 'B', 'C']], 'numero' => ['type' => 'string', 'description' => 'Ej. 0003-00012345'], 'fecha' => ['type' => 'string'], 'items' => Ventas::ITEMS, 'total' => ['type' => 'number', 'description' => 'Total con IVA si no hay renglones'], 'concepto' => ['type' => 'string'], 'condicion' => ['type' => 'string', 'enum' => ['cta_cte', 'contado']]], 'requeridos' => ['proveedor'],
                'proponer' => function (User $u, array $a) {
                    $pr = Busca::proveedor($a['proveedor']); if (! $pr) return ['error' => "No encuentro el proveedor \"{$a['proveedor']}\"."];
                    $items = [];
                    foreach ((array) ($a['items'] ?? []) as $it) {
                        $p = ! empty($it['articulo']) ? Busca::articulo($it['articulo']) : null;
                        if (! empty($it['articulo']) && ! $p && empty($it['descripcion'])) return ['error' => "No encuentro el artículo \"{$it['articulo']}\"."];
                        $items[] = ['product_id' => $p?->id, 'descripcion' => $it['descripcion'] ?? $p?->name, 'cantidad' => (float) ($it['cantidad'] ?? 1), 'precio_unit' => (float) ($it['precio'] ?? $it['precio_unit'] ?? ($p?->precio_compra ?: $p?->cost ?? 0)), 'alicuota_iva' => (float) ($it['iva'] ?? $it['alicuota_iva'] ?? $p?->iva ?? 21), 'unidad' => $p?->unit ?? 'un'];
                    }
                    if (! $items && ($a['total'] ?? 0) > 0) $items[] = ['product_id' => null, 'descripcion' => $a['concepto'] ?? 'Compra', 'cantidad' => 1, 'precio_unit' => round((float) $a['total'] / 1.21, 2), 'alicuota_iva' => 21, 'unidad' => 'un'];
                    if (! $items) return ['error' => 'Decime los renglones o el total de la factura.'];
                    $tipo = 'F' . strtoupper($a['tipo'] ?? ($pr->condicion_iva === 'Responsable Inscripto' ? 'A' : ($pr->condicion_iva === 'Monotributista' ? 'C' : 'B')));
                    $total = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio_unit'] * (1 + $i['alicuota_iva'] / 100), $items));
                    $datos = ['contact_id' => $pr->id, 'tipo' => $tipo, 'numero_proveedor' => $a['numero'] ?? null, 'fecha' => $a['fecha'] ?? today()->toDateString(), 'condicion' => $a['condicion'] ?? 'cta_cte', 'items' => $items, 'origen_carga' => 'bigcito'];
                    return ['titulo' => "Cargar factura {$tipo} " . ($a['numero'] ?? 's/n') . " de {$pr->name} por " . Busca::pesos($total), 'detalle' => collect($items)->map(fn($i) => "{$i['cantidad']} × {$i['descripcion']} a " . Busca::pesos($i['precio_unit']) . " + IVA {$i['alicuota_iva']}%")->implode("\n") . "\nSe registra: mueve stock, costo y cuenta corriente.", 'datos' => $datos];
                },
                'confirmar' => function (User $u, array $d) { $s = app(CompraService::class); $c = $s->registrar($s->guardarBorrador($d)); return ['texto' => "Compra {$c->nombreTipo()} " . ($c->numero_proveedor ?: '') . " de {$c->contact->name} registrada por " . Busca::pesos($c->total) . '.', 'compra_id' => $c->id, 'url' => Busca::url("/proveedores/compras/{$c->id}")]; }],
            ['nombre' => 'nota_de_credito', 'titulo' => 'Hacer una nota de crédito', 'modulo' => 'comprobantes', 'permiso' => 'crear', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Emite una nota de crédito sobre una factura emitida: total (la anula comercialmente y devuelve el stock) o parcial, por un importe (descuento, bonificación) o por renglones devueltos. Pide CAE a ARCA como la factura. No se puede deshacer.',
                'parametros' => ['factura' => ['type' => 'string'], 'importe' => ['type' => 'number', 'description' => 'Importe con IVA de la nota parcial; vacío = total'], 'motivo' => ['type' => 'string'], 'items' => Ventas::ITEMS], 'requeridos' => ['factura'],
                'proponer' => function (User $u, array $a) {
                    $f = Busca::comprobante($a['factura']); if (! $f) return ['error' => "No encuentro la factura {$a['factura']}."];
                    if ($f->estado !== 'emitido' || ! $f->esFactura()) return ['error' => "{$f->nombreTipo()} {$f->numeroFormateado()} no es una factura emitida."];
                    $imp = (float) ($a['importe'] ?? 0); $modo = ! empty($a['items']) ? 'items' : ($imp > 0 ? 'importe' : 'total');
                    if ($modo === 'importe' && $imp > (float) $f->total + 0.01) return ['error' => 'El importe supera el total de la factura (' . Busca::pesos($f->total) . ').'];
                    $items = null;
                    if ($modo === 'items') { $items = []; foreach ((array) $a['items'] as $it) { $p = ! empty($it['articulo']) ? Busca::articulo($it['articulo']) : null; $orig = $p ? $f->items->firstWhere('product_id', $p->id) : null; if (! $orig) return ['error' => 'La factura no tiene el artículo "' . ($it['articulo'] ?? '') . '".']; $items[] = ['product_id' => $p->id, 'descripcion' => $orig->descripcion, 'cantidad' => (float) ($it['cantidad'] ?? $orig->cantidad), 'unidad' => $orig->unidad, 'precio_unit' => (float) $orig->precio_unit, 'descuento' => (float) $orig->descuento, 'alicuota_iva' => (float) $orig->alicuota_iva]; } }
                    $que = $modo === 'total' ? 'por el total ' . Busca::pesos($f->total) . ' (devuelve el stock)' : ($modo === 'importe' ? 'parcial por ' . Busca::pesos($imp) : 'por ' . count($items) . ' renglón(es) devueltos');
                    return ['titulo' => "Nota de crédito {$que} sobre {$f->nombreTipo()} {$f->numeroFormateado()} de " . ($f->contact?->name ?? 'Consumidor final'), 'detalle' => ($a['motivo'] ?? 'Sin motivo indicado') . '. Se emite con CAE y queda a favor del cliente en su cuenta corriente.', 'datos' => ['id' => $f->id, 'modo' => $modo, 'importe' => $imp, 'items' => $items, 'motivo' => $a['motivo'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $f = Comprobante::findOrFail($d['id']); $svc = app(ComprobanteService::class);
                    if ($d['modo'] === 'total') $nc = $svc->convertir($f, 'NCX');
                    else {
                        $items = $d['modo'] === 'items' ? $d['items'] : [['product_id' => null, 'descripcion' => 'Nota de crédito ' . ($d['motivo'] ?: 'sobre ' . $f->nombreTipo() . ' ' . $f->numeroFormateado()), 'cantidad' => 1, 'unidad' => 'un', 'precio_unit' => round($d['importe'] / 1.21, 2), 'alicuota_iva' => 21]];
                        $nc = $svc->guardarBorrador(['contact_id' => $f->contact_id, 'tipo' => 'NCX', 'origen_id' => $f->id, 'fecha' => today()->toDateString(), 'condicion' => $f->condicion, 'notas' => 'Generado desde ' . $f->nombreTipo() . ' ' . $f->numeroFormateado() . ($d['motivo'] ? ": {$d['motivo']}" : ''), 'items' => $items]);
                    }
                    $nc = $svc->emitir($nc);
                    return ['texto' => "{$nc->nombreTipo()} {$nc->numeroFormateado()} emitida por " . Busca::pesos($nc->total) . ($nc->cae ? " con CAE {$nc->cae}" : ($nc->afip_estado === 'simulado' ? ' (simulada: falta el certificado)' : '')) . '.', 'comprobante_id' => $nc->id, 'url' => Busca::url("/comprobantes/{$nc->id}")];
                }],
            ['nombre' => 'crear_remito', 'titulo' => 'Hacer un remito', 'modulo' => 'comprobantes', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Emite un remito de entrega: desde una factura emitida (con todos sus renglones) o con renglones sueltos para un cliente. Mueve el stock.',
                'parametros' => ['factura' => ['type' => 'string'], 'cliente' => ['type' => 'string'], 'items' => Ventas::ITEMS], 'requeridos' => [],
                'proponer' => function (User $u, array $a) {
                    if (! empty($a['factura'])) { $f = Busca::comprobante($a['factura']); if (! $f || $f->estado !== 'emitido') return ['error' => "No encuentro la factura emitida {$a['factura']}."]; return ['titulo' => "Remito desde {$f->nombreTipo()} {$f->numeroFormateado()} a " . ($f->contact?->name ?? 'Consumidor final'), 'detalle' => $f->items->map(fn($i) => (float) $i->cantidad . " × {$i->descripcion}")->implode("\n"), 'datos' => ['desde' => $f->id]]; }
                    $c = ! empty($a['cliente']) ? Busca::cliente($a['cliente']) : null; if (! empty($a['cliente']) && ! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."];
                    $items = []; foreach ((array) ($a['items'] ?? []) as $it) { $p = ! empty($it['articulo']) ? Busca::articulo($it['articulo']) : null; if (! $p) return ['error' => 'No encuentro el artículo "' . ($it['articulo'] ?? '') . '".']; $items[] = ['product_id' => $p->id, 'descripcion' => $p->name, 'cantidad' => (float) ($it['cantidad'] ?? 1), 'unidad' => $p->unit, 'precio_unit' => (float) $p->precioLista($c?->lista_precios ?? 1), 'alicuota_iva' => (float) $p->iva]; }
                    if (! $items) return ['error' => 'Decime la factura o los renglones del remito.'];
                    return ['titulo' => 'Remito a ' . ($c?->name ?? 'Consumidor final') . ' con ' . count($items) . ' renglón(es)', 'detalle' => collect($items)->map(fn($i) => "{$i['cantidad']} × {$i['descripcion']}")->implode("\n") . "\nMueve el stock.", 'datos' => ['contact_id' => $c?->id, 'items' => $items]];
                },
                'confirmar' => function (User $u, array $d) {
                    $svc = app(ComprobanteService::class);
                    $r = ! empty($d['desde']) ? $svc->convertir(Comprobante::findOrFail($d['desde']), 'REM') : $svc->guardarBorrador(['contact_id' => $d['contact_id'], 'tipo' => 'REM', 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'items' => $d['items']]);
                    $r = $svc->emitir($r);
                    return ['texto' => "Remito {$r->numeroFormateado()} emitido.", 'comprobante_id' => $r->id, 'url' => Busca::url("/comprobantes/{$r->id}")];
                }],
            ['nombre' => 'crear_usuario', 'titulo' => 'Dar de alta un usuario', 'modulo' => 'configuracion', 'permiso' => 'editar', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Crea un usuario de la empresa con un rol (vendedor, cajero, depósito, administrativo, contador…) y una contraseña provisoria que se le pasa para que la cambie al entrar. Respeta el límite de usuarios del plan.',
                'parametros' => ['nombre' => ['type' => 'string'], 'email' => ['type' => 'string'], 'rol' => ['type' => 'string']], 'requeridos' => ['nombre', 'email', 'rol'],
                'proponer' => function (User $u, array $a) {
                    if (User::where('email', $a['email'])->exists()) return ['error' => "Ya hay un usuario con el email {$a['email']}."];
                    $rol = self::rol($a['rol']); if (! $rol) return ['error' => "No encuentro el rol \"{$a['rol']}\". Roles: " . Role::pluck('nombre')->implode(', ') . '.'];
                    if ($rol->slug === 'dueno') return ['error' => 'El rol Dueño no se asigna por acá.'];
                    $max = $u->business->activeSubscription?->plan?->max_users ?? 1;
                    if ($max > 0 && $rol->slug !== 'chofer' && User::where('business_id', $u->business_id)->whereDoesntHave('role', fn($q) => $q->where('slug', 'chofer'))->count() >= $max) return ['error' => "Tu plan permite hasta {$max} usuario(s). Cambiá de plan para agregar más."];
                    return ['titulo' => "Crear el usuario {$a['nombre']} ({$rol->nombre})", 'detalle' => "{$a['email']} · sucursal " . ($u->currentLocation?->name ?? 'actual') . '. Le paso una contraseña provisoria para que la cambie al entrar.', 'datos' => ['name' => $a['nombre'], 'email' => $a['email'], 'role_id' => $rol->id]];
                },
                'confirmar' => function (User $u, array $d) {
                    $clave = Str::random(10);
                    $x = User::create(['business_id' => $u->business_id, 'name' => $d['name'], 'email' => $d['email'], 'password' => $clave, 'status' => 'active', 'role_id' => $d['role_id'], 'current_location_id' => $u->current_location_id]);
                    $x->locations()->sync([$u->current_location_id => ['role_id' => $d['role_id']]]);
                    AuditLog::registrar('crear', $x, "Usuario {$x->email} (por Bigcito)"); \App\Services\Integraciones\CrmSyncService::encolar($u->business_id, 'usuarios', [$x->id]);
                    return ['texto' => "Usuario {$x->name} creado. Contraseña provisoria: {$clave} (que la cambie al entrar, en Mi perfil).", 'usuario_id' => $x->id];
                }],
            ['nombre' => 'cargar_empleado', 'titulo' => 'Dar de alta un empleado', 'modulo' => 'sueldos', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Da de alta un empleado en Sueldos: nombre, CUIL, fecha de ingreso, sueldo básico, puesto y categoría. El legajo sale solo.',
                'parametros' => ['nombre' => ['type' => 'string'], 'cuil' => ['type' => 'string'], 'fecha_ingreso' => ['type' => 'string'], 'sueldo_basico' => ['type' => 'number'], 'puesto' => ['type' => 'string'], 'categoria' => ['type' => 'string'], 'email' => ['type' => 'string'], 'telefono' => ['type' => 'string']], 'requeridos' => ['nombre'],
                'proponer' => function (User $u, array $a) {
                    if (Empleado::where('nombre', $a['nombre'])->where('activo', true)->exists()) return ['error' => "Ya hay un empleado activo llamado {$a['nombre']}."];
                    $cuil = preg_replace('/\D/', '', (string) ($a['cuil'] ?? '')); if ($cuil !== '' && ! \App\Support\Cuit::valido($cuil)) return ['error' => 'El CUIL no es válido.'];
                    $legajo = (int) Empleado::max('legajo') + 1;
                    $datos = ['legajo' => $legajo, 'nombre' => $a['nombre'], 'cuil' => $cuil ? \App\Support\Cuit::formatear($cuil) : null, 'fecha_ingreso' => $a['fecha_ingreso'] ?? today()->toDateString(), 'sueldo_basico' => (float) ($a['sueldo_basico'] ?? 0), 'puesto' => $a['puesto'] ?? null, 'categoria' => $a['categoria'] ?? null, 'email' => $a['email'] ?? null, 'telefono' => $a['telefono'] ?? null, 'activo' => true];
                    return ['titulo' => "Dar de alta al empleado {$a['nombre']} (legajo {$legajo})", 'detalle' => trim(($datos['puesto'] ? "{$datos['puesto']} · " : '') . ($datos['categoria'] ? "{$datos['categoria']} · " : '') . 'ingreso ' . \Carbon\Carbon::parse($datos['fecha_ingreso'])->format('d/m/Y') . ($datos['sueldo_basico'] ? ' · básico ' . Busca::pesos($datos['sueldo_basico']) : '') . ($datos['cuil'] ? " · CUIL {$datos['cuil']}" : ' · falta el CUIL (hace falta para el Libro de Sueldos)')), 'datos' => $datos];
                },
                'confirmar' => function (User $u, array $d) { $e = Empleado::create($d + ['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id]); AuditLog::registrar('crear', $e, "Empleado {$e->nombre} (por Bigcito)"); return ['texto' => "Empleado {$e->nombre} dado de alta con legajo {$e->legajo}.", 'empleado_id' => $e->id, 'url' => Busca::url('/sueldos/empleados')]; }],
            ['nombre' => 'agendar_turno', 'titulo' => 'Agendar un turno', 'modulo' => 'agenda', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Agenda un turno en la Agenda: cliente (o nombre y teléfono), fecha, hora, duración en minutos, servicio y profesional (opcional). Avisa si el profesional ya tiene un turno a esa hora.',
                'parametros' => ['cliente' => ['type' => 'string'], 'nombre' => ['type' => 'string'], 'telefono' => ['type' => 'string'], 'fecha' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'hora' => ['type' => 'string', 'description' => 'HH:MM'], 'minutos' => ['type' => 'integer'], 'servicio' => ['type' => 'string'], 'profesional' => ['type' => 'string'], 'notas' => ['type' => 'string']], 'requeridos' => ['fecha', 'hora'],
                'proponer' => function (User $u, array $a) {
                    $c = ! empty($a['cliente']) ? Busca::cliente($a['cliente']) : null; if (! empty($a['cliente']) && ! $c) return ['error' => "No encuentro el cliente \"{$a['cliente']}\"."];
                    if (! $c && empty($a['nombre'])) return ['error' => '¿Para quién es el turno?'];
                    $srv = ! empty($a['servicio']) ? Busca::articulo($a['servicio']) : null; $prof = ! empty($a['profesional']) ? self::usuario($u, $a['profesional']) : null;
                    try { $inicio = \Carbon\Carbon::parse($a['fecha'] . ' ' . $a['hora']); } catch (\Throwable) { return ['error' => 'No entendí la fecha u hora.']; }
                    $min = (int) ($a['minutos'] ?? 30); $fin = $inicio->copy()->addMinutes($min);
                    if ($prof && ($ch = Booking::where('assigned_to', $prof->id)->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '<', $fin)->where('ends_at', '>', $inicio)->first())) return ['error' => "{$prof->name} ya tiene un turno a las {$ch->starts_at->format('H:i')} (" . ($ch->contact?->name ?? $ch->nombre) . ').'];
                    return ['titulo' => 'Turno para ' . ($c?->name ?? $a['nombre']) . ' el ' . $inicio->locale('es')->isoFormat('dddd D/M [a las] HH:mm') . " ({$min} min)", 'detalle' => trim(($srv ? "{$srv->name} · " : '') . ($prof ? "con {$prof->name} · " : '') . ($a['notas'] ?? '')), 'datos' => ['contact_id' => $c?->id, 'nombre' => $a['nombre'] ?? null, 'telefono' => $a['telefono'] ?? ($c?->mobile ?: $c?->phone), 'service_id' => $srv?->id, 'assigned_to' => $prof?->id, 'inicio' => $inicio->toDateTimeString(), 'fin' => $fin->toDateTimeString(), 'precio' => $srv ? (float) $srv->price : 0, 'notas' => $a['notas'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $t = Booking::create(['business_id' => $u->business_id, 'location_id' => $u->current_location_id, 'origen' => 'bigcito', 'token' => Str::random(40), 'status' => 'confirmed', 'contact_id' => $d['contact_id'], 'nombre' => $d['nombre'], 'telefono' => $d['telefono'], 'service_id' => $d['service_id'], 'assigned_to' => $d['assigned_to'], 'starts_at' => $d['inicio'], 'ends_at' => $d['fin'], 'price' => $d['precio'], 'notes' => $d['notas']]);
                    AuditLog::registrar('crear', $t, 'Turno ' . $t->starts_at->format('d/m H:i') . ' (por Bigcito)');
                    return ['texto' => 'Turno agendado para el ' . $t->starts_at->format('d/m') . ' a las ' . $t->starts_at->format('H:i') . '.', 'turno_id' => $t->id, 'url' => Busca::url('/agenda')];
                }],
            ['nombre' => 'depositar_cheque', 'titulo' => 'Depositar un cheque', 'modulo' => 'fondos', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Deposita un cheque de terceros que está en cartera en una cuenta bancaria.',
                'parametros' => ['cheque' => ['type' => 'string', 'description' => 'Número del cheque'], 'banco' => ['type' => 'string', 'description' => 'Cuenta bancaria donde se deposita']], 'requeridos' => ['cheque'],
                'proponer' => function (User $u, array $a) {
                    $num = trim((string) $a['cheque']); $ch = Cheque::where('tipo', 'tercero')->where('estado', 'cartera')->where('numero', \App\Support\Sql::like(), "%{$num}%")->first();
                    if (! $ch) return ['error' => "No encuentro un cheque de terceros en cartera con número {$num}."];
                    $banco = ! empty($a['banco']) ? Busca::cuenta($a['banco'], 'banco') : CuentaFondos::where('activa', true)->where('tipo', 'banco')->orderBy('id')->first();
                    if (! $banco || $banco->tipo !== 'banco') return ['error' => 'Decime en qué cuenta bancaria lo deposito.'];
                    return ['titulo' => "Depositar el cheque {$ch->numero} de {$ch->banco} por " . Busca::pesos($ch->monto) . " en {$banco->nombre}", 'detalle' => 'Emisor ' . ($ch->emisor ?: '—') . ' · cobro ' . ($ch->fecha_pago?->format('d/m/Y') ?? '—'), 'datos' => ['id' => $ch->id, 'banco_id' => $banco->id]];
                },
                'confirmar' => fn(User $u, array $d) => ['texto' => 'Cheque ' . app(\App\Services\Fondos\ChequeService::class)->depositar(Cheque::findOrFail($d['id']), CuentaFondos::findOrFail($d['banco_id']))->numero . ' depositado.', 'url' => Busca::url('/fondos/cheques')]],
            ['nombre' => 'abrir_caja', 'titulo' => 'Abrir la caja', 'modulo' => 'fondos', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Abre el turno de caja de la sucursal actual con el efectivo inicial.',
                'parametros' => ['saldo_inicial' => ['type' => 'number'], 'caja' => ['type' => 'string']], 'requeridos' => ['saldo_inicial'],
                'proponer' => function (User $u, array $a) {
                    $caja = ! empty($a['caja']) ? Busca::cuenta($a['caja'], 'caja') : CuentaFondos::where('activa', true)->where('tipo', 'caja')->where(fn($w) => $w->where('business_location_id', $u->current_location_id)->orWhereNull('business_location_id'))->orderByDesc('es_default')->first();
                    if (! $caja) return ['error' => 'No encuentro la caja.'];
                    if ($caja->turnoAbierto) return ['error' => "La caja {$caja->nombre} ya está abierta desde las {$caja->turnoAbierto->apertura->format('H:i')}."];
                    return ['titulo' => "Abrir la caja {$caja->nombre} con " . Busca::pesos($a['saldo_inicial']), 'detalle' => 'Turno a nombre de ' . $u->name . '.', 'datos' => ['caja_id' => $caja->id, 'saldo' => (float) $a['saldo_inicial']]];
                },
                'confirmar' => fn(User $u, array $d) => ['texto' => 'Caja abierta con ' . Busca::pesos(app(FondosService::class)->abrirTurno(CuentaFondos::findOrFail($d['caja_id']), $d['saldo'])->saldo_inicial) . '.', 'url' => Busca::url('/fondos/cierres')]],
            ['nombre' => 'cerrar_caja', 'titulo' => 'Cerrar la caja', 'modulo' => 'fondos', 'permiso' => 'crear', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Cierra el turno de caja abierto con el efectivo contado; informa la diferencia contra lo esperado.',
                'parametros' => ['contado' => ['type' => 'number'], 'caja' => ['type' => 'string'], 'notas' => ['type' => 'string']], 'requeridos' => ['contado'],
                'proponer' => function (User $u, array $a) {
                    $turno = TurnoCaja::whereNull('cierre')->when(! empty($a['caja']), fn($q) => $q->where('cuenta_fondos_id', Busca::cuenta($a['caja'], 'caja')?->id ?? 0))->when(empty($a['caja']), fn($q) => $q->where(fn($w) => $w->where('user_id', $u->id)->orWhereHas('cuenta', fn($c) => $c->where('business_location_id', $u->current_location_id))))->latest('apertura')->first();
                    if (! $turno) return ['error' => 'No hay una caja abierta para cerrar.'];
                    $esp = (float) ($turno->saldo_esperado ?? $turno->cuenta?->saldo ?? 0); $dif = round((float) $a['contado'] - $esp, 2);
                    return ['titulo' => "Cerrar la caja {$turno->cuenta?->nombre} con " . Busca::pesos($a['contado']) . ' contados', 'detalle' => 'Esperado ' . Busca::pesos($esp) . ' → ' . ($dif == 0 ? 'cuadra' : ($dif < 0 ? 'faltan ' : 'sobran ') . Busca::pesos(abs($dif))) . '. Abierta por ' . ($turno->user?->name ?? '—') . ' a las ' . $turno->apertura->format('H:i') . '.', 'datos' => ['turno_id' => $turno->id, 'contado' => (float) $a['contado'], 'notas' => $a['notas'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) { $t = app(FondosService::class)->cerrarTurno(TurnoCaja::findOrFail($d['turno_id']), $d['contado'], $d['notas']); $dif = (float) $t->diferencia; return ['texto' => 'Caja cerrada. ' . ($dif == 0 ? 'Cuadró justo.' : ($dif < 0 ? 'Faltante ' : 'Sobrante ') . Busca::pesos(abs($dif)) . '.'), 'url' => Busca::url('/fondos/cierres')]; }],
        ];
    }

    // ---- Ayudas --------------------------------------------------------------------------------------------------------------

    private static function proponerCambios($modelo, array $cambios, array $mapa, string $que): array
    {
        $aplicar = []; $lineas = [];
        foreach ($cambios as $k => $v) {
            $k = mb_strtolower(trim((string) $k)); if (! isset($mapa[$k])) return ['error' => "No sé cambiar \"{$k}\". Puedo cambiar: " . implode(', ', array_keys($mapa)) . '.'];
            $col = $mapa[$k];
            if ($col === 'cuit' && $v) { $d = preg_replace('/\D/', '', (string) $v); if (strlen($d) === 11 && ! \App\Support\Cuit::valido($d)) return ['error' => 'El CUIT no es válido.']; $v = strlen($d) === 11 ? \App\Support\Cuit::formatear($d) : $v; }
            if ($col === 'condicion_iva' && ! in_array($v, Contact::CONDICIONES_IVA, true)) return ['error' => "Condición IVA inválida. Opciones: " . implode(', ', Contact::CONDICIONES_IVA) . '.'];
            if (in_array($col, ['is_active', 'active', 'comprobante_interno', 'controla_stock'], true)) $v = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            if (in_array($col, ['price', 'cost', 'iva', 'stock_min', 'credit_limit', 'descuento', 'interes_mora', 'margen_objetivo', 'dias_pago', 'lista_precios'], true)) { if (! is_numeric($v)) return ['error' => "\"{$k}\" tiene que ser un número."]; $v = (float) $v; }
            $antes = $modelo->{$col};
            if (in_array($col, ['rubro_id', 'proveedor_id'], true)) { $antes = $col === 'rubro_id' ? $modelo->rubro?->nombre : $modelo->proveedor?->name; $despues = $col === 'rubro_id' ? Rubro::find($v)?->nombre : Contact::find($v)?->name; }
            else $despues = is_bool($v) ? ($v ? 'sí' : 'no') : $v;
            $aplicar[$col] = $v; $lineas[] = "{$k}: " . (is_bool($antes) ? ($antes ? 'sí' : 'no') : ($antes ?? '—')) . " → {$despues}";
        }
        if (! $aplicar) return ['error' => '¿Qué le cambio?'];
        return ['titulo' => 'Cambiar ' . count($aplicar) . ' dato(s) del ' . $que, 'detalle' => implode("\n", $lineas), 'datos' => ['id' => $modelo->id, 'cambios' => $aplicar, 'resumen' => implode('; ', $lineas)]];
    }

    private static function aplicarCambios($modelo, array $cambios, string $etiqueta): array
    {
        $antes = collect($cambios)->keys()->mapWithKeys(fn($k) => [$k => $modelo->{$k}])->all();
        $modelo->forceFill($cambios)->save();
        AuditLog::registrar('editar', $modelo, "{$etiqueta} " . ($modelo->name ?? $modelo->nombre ?? $modelo->id) . ' (por Bigcito)', $antes, $cambios);
        return ['texto' => "Listo: " . ($modelo->name ?? $modelo->nombre) . ' actualizado.', 'id' => $modelo->id];
    }

    private static function usuario(User $u, string $texto): ?User
    {
        $t = trim($texto); $like = \App\Support\Sql::like();
        return User::where('business_id', $u->business_id)->where(fn($q) => $q->where('email', $t)->orWhere('name', $like, "%{$t}%"))->orderByRaw('CASE WHEN email = ? THEN 0 ELSE 1 END', [$t])->first();
    }

    private static function rol(string $texto): ?Role
    {
        $t = mb_strtolower(trim($texto)); $like = \App\Support\Sql::like();
        return Role::where('slug', $t)->first() ?? Role::where('nombre', $like, "%{$t}%")->first();
    }

    private static function proponerBaja(User $u, array $a): array
    {
        $tipo = $a['tipo']; $q = trim((string) $a['cual']); $motivo = trim((string) ($a['motivo'] ?? ''));
        $permiso = ['cliente' => ['clientes', 'anular'], 'proveedor' => ['proveedores', 'anular'], 'articulo' => ['stock', 'anular'], 'presupuesto' => ['comprobantes', 'anular'], 'borrador' => ['comprobantes', 'anular'], 'cobro' => ['clientes', 'anular'], 'pago' => ['proveedores', 'anular'], 'gasto' => ['fondos', 'anular'], 'orden_compra' => ['proveedores', 'anular'], 'turno' => ['agenda', 'editar']][$tipo] ?? null;
        if (! $permiso) return ['error' => 'No sé dar de baja eso.'];
        if (! $u->puede($permiso[0], $permiso[1])) return ['error' => "Tu usuario no tiene permiso para dar de baja {$tipo}s (hace falta {$permiso[1]} en {$permiso[0]})."];
        switch ($tipo) {
            case 'cliente': case 'proveedor':
                $c = $tipo === 'cliente' ? Busca::cliente($q) : Busca::proveedor($q); if (! $c) return ['error' => "No encuentro el {$tipo} \"{$q}\"."];
                $mov = $c->comprobantes()->count() + Cobro::where('contact_id', $c->id)->count() + Pago::where('contact_id', $c->id)->count();
                $borra = $mov === 0 && abs((float) $c->balance) < 0.01;
                return ['titulo' => ($borra ? 'Borrar el ' : 'Desactivar el ') . "{$tipo} {$c->name}", 'detalle' => $borra ? 'No tiene movimientos: se borra.' : "Tiene {$mov} movimiento(s)" . (abs((float) $c->balance) >= 0.01 ? ' y saldo ' . Busca::pesos($c->balance) : '') . ': queda desactivado (no se puede elegir en ventas ni compras, pero su historia se conserva).', 'datos' => ['tipo' => $tipo, 'id' => $c->id, 'borrar' => $borra, 'motivo' => $motivo]];
            case 'articulo':
                $p = Busca::articulo($q); if (! $p) return ['error' => "No encuentro el artículo \"{$q}\"."];
                $mov = $p->stockMovements()->count() + \App\Models\ComprobanteItem::where('product_id', $p->id)->count();
                $borra = $mov === 0;
                return ['titulo' => ($borra ? 'Borrar el artículo ' : 'Desactivar el artículo ') . $p->name, 'detalle' => $borra ? 'Nunca se movió: se borra.' : "Tiene {$mov} movimiento(s): queda inactivo (no aparece para vender ni comprar; su historia se conserva).", 'datos' => ['tipo' => $tipo, 'id' => $p->id, 'borrar' => $borra, 'motivo' => $motivo]];
            case 'presupuesto': case 'borrador':
                $c = Busca::comprobante($q); if (! $c) return ['error' => "No encuentro el comprobante {$q}."];
                if ($c->estado === 'borrador' || ($c->tipo === 'PRE' && $c->estado !== 'anulado')) return ['titulo' => ($c->estado === 'borrador' ? 'Borrar el borrador ' : 'Anular el presupuesto ') . "{$c->nombreTipo()} " . ($c->numero ? $c->numeroFormateado() : "#{$c->id}") . ' de ' . ($c->contact?->name ?? 'Consumidor final') . ' por ' . Busca::pesos($c->total), 'detalle' => $motivo ?: 'Sin motivo.', 'datos' => ['tipo' => $c->estado === 'borrador' ? 'borrador' : 'presupuesto', 'id' => $c->id, 'motivo' => $motivo]];
                return ['error' => "{$c->nombreTipo()} {$c->numeroFormateado()} está {$c->estado}: las facturas emitidas se anulan con anular_comprobante o con nota_de_credito."];
            case 'cobro':
                $n = (int) preg_replace('/\D/', '', $q); $co = Cobro::where('numero', $n)->first() ?? Cobro::find($n); // primero por número de recibo, después por id if (! $co || $co->estado === 'anulado') return ['error' => "No encuentro un cobro vigente \"{$q}\"."];
                if ($motivo === '') return ['error' => '¿Por qué se anula el cobro? Decime el motivo.'];
                return ['titulo' => "Anular el recibo {$co->numeroFormateado()} de " . ($co->contact?->name ?? '—') . ' por ' . Busca::pesos($co->total), 'detalle' => "{$motivo}. Las facturas vuelven a quedar pendientes y la plata sale de la caja/banco.", 'datos' => ['tipo' => 'cobro', 'id' => $co->id, 'motivo' => $motivo]];
            case 'pago':
                $n = (int) preg_replace('/\D/', '', $q); $pg = Pago::where('numero', $n)->first() ?? Pago::find($n); if (! $pg || $pg->estado === 'anulado') return ['error' => "No encuentro una orden de pago vigente \"{$q}\"."];
                if ($motivo === '') return ['error' => '¿Por qué se anula el pago? Decime el motivo.'];
                return ['titulo' => "Anular la orden de pago {$pg->numeroFormateado()} a " . ($pg->contact?->name ?? '—') . ' por ' . Busca::pesos($pg->total), 'detalle' => "{$motivo}. Las facturas del proveedor vuelven a quedar pendientes.", 'datos' => ['tipo' => 'pago', 'id' => $pg->id, 'motivo' => $motivo]];
            case 'gasto':
                $m = is_numeric($q) ? \App\Models\MovimientoFondos::where('origen', 'gasto')->find((int) $q) : \App\Models\MovimientoFondos::where('origen', 'gasto')->where('concepto', \App\Support\Sql::like(), "%{$q}%")->latest('fecha')->latest('id')->first();
                if (! $m) return ['error' => "No encuentro el gasto \"{$q}\"."];
                if ($m->conciliado) return ['error' => 'Ese gasto ya está conciliado con el banco: no se borra.'];
                return ['titulo' => "Borrar el gasto «{$m->concepto}» de " . Busca::pesos($m->egreso) . ' del ' . $m->fecha->format('d/m/Y'), 'detalle' => ($motivo ?: 'Sin motivo.') . ' La plata vuelve a ' . ($m->cuenta?->nombre ?? 'la cuenta') . ' y se anula su asiento.', 'datos' => ['tipo' => 'gasto', 'id' => $m->id, 'motivo' => $motivo]];
            case 'orden_compra':
                $oc = OrdenCompra::where('numero', (int) preg_replace('/\D/', '', $q))->first() ?? (is_numeric($q) ? OrdenCompra::find((int) $q) : null); if (! $oc || ! $oc->abierta()) return ['error' => "No encuentro una orden de compra abierta \"{$q}\"."];
                return ['titulo' => "Cancelar la orden de compra {$oc->numeroFormateado()} a " . ($oc->contact?->name ?? '—') . ' por ' . Busca::pesos($oc->total), 'detalle' => $motivo ?: 'Sin motivo.', 'datos' => ['tipo' => 'orden_compra', 'id' => $oc->id, 'motivo' => $motivo]];
            case 'turno':
                $t = Booking::whereIn('status', ['pending', 'confirmed'])->where(fn($w) => $w->where('nombre', \App\Support\Sql::like(), "%{$q}%")->orWhereHas('contact', fn($c) => $c->where('name', \App\Support\Sql::like(), "%{$q}%")))->where('starts_at', '>=', now()->startOfDay())->orderBy('starts_at')->first();
                if (! $t) return ['error' => "No encuentro un turno próximo de \"{$q}\"."];
                return ['titulo' => 'Cancelar el turno de ' . ($t->contact?->name ?? $t->nombre) . ' del ' . $t->starts_at->format('d/m H:i'), 'detalle' => $motivo ?: 'Sin motivo.', 'datos' => ['tipo' => 'turno', 'id' => $t->id, 'motivo' => $motivo]];
        }
        return ['error' => 'No sé dar de baja eso.'];
    }

    private static function ejecutarBaja(User $u, array $d): array
    {
        $m = $d['motivo'] ?: 'Baja por Bigcito';
        switch ($d['tipo']) {
            case 'cliente': case 'proveedor':
                $c = Contact::findOrFail($d['id']);
                if ($d['borrar']) { AuditLog::registrar('eliminar', $c, ucfirst($d['tipo']) . " {$c->name} borrado (por Bigcito)"); $c->delete(); return ['texto' => ucfirst($d['tipo']) . " {$c->name} borrado."]; }
                $c->update(['is_active' => false]); AuditLog::registrar('editar', $c, ucfirst($d['tipo']) . " {$c->name} desactivado (por Bigcito): {$m}"); return ['texto' => ucfirst($d['tipo']) . " {$c->name} desactivado."];
            case 'articulo':
                $p = Product::findOrFail($d['id']);
                if ($d['borrar']) { AuditLog::registrar('eliminar', $p, "Artículo {$p->name} borrado (por Bigcito)"); $p->delete(); return ['texto' => "Artículo {$p->name} borrado."]; }
                $p->update(['active' => false]); AuditLog::registrar('editar', $p, "Artículo {$p->name} desactivado (por Bigcito): {$m}"); return ['texto' => "Artículo {$p->name} desactivado."];
            case 'borrador':
                $c = Comprobante::findOrFail($d['id']); abort_if($c->estado !== 'borrador', 422, 'Ya no es un borrador.'); $n = $c->nombreTipo(); $c->items()->delete(); $c->delete(); AuditLog::registrar('eliminar', null, "Borrador de {$n} borrado (por Bigcito)"); return ['texto' => "Borrador de {$n} borrado."];
            case 'presupuesto':
                $c = app(ComprobanteService::class)->anular(Comprobante::findOrFail($d['id']), $m); return ['texto' => "Presupuesto {$c->numeroFormateado()} anulado."];
            case 'cobro':
                $co = Cobro::findOrFail($d['id']); app(\App\Services\Comprobantes\CobroService::class)->anular($co, $m); return ['texto' => "Recibo {$co->numeroFormateado()} anulado."];
            case 'pago':
                $pg = Pago::findOrFail($d['id']); app(\App\Services\Compras\PagoService::class)->anular($pg, $m); return ['texto' => "Orden de pago {$pg->numeroFormateado()} anulada."];
            case 'gasto':
                $mv = \App\Models\MovimientoFondos::where('origen', 'gasto')->findOrFail($d['id']); $cuenta = $mv->cuenta; $concepto = $mv->concepto;
                app(\App\Services\Contabilidad\ContabilidadService::class)->anular('fondos', $mv->id, $m); $mv->delete(); $cuenta?->recalcularSaldo();
                AuditLog::registrar('eliminar', null, "Gasto «{$concepto}» borrado (por Bigcito): {$m}"); return ['texto' => "Gasto «{$concepto}» borrado."];
            case 'orden_compra':
                $oc = app(\App\Services\Compras\OrdenCompraService::class)->cancelar(OrdenCompra::findOrFail($d['id']), $m); return ['texto' => "Orden {$oc->numeroFormateado()} cancelada."];
            case 'turno':
                $t = Booking::findOrFail($d['id']); $t->update(['status' => 'cancelled']); AuditLog::registrar('editar', $t, 'Turno cancelado (por Bigcito): ' . $m); return ['texto' => 'Turno cancelado.'];
        }
        return ['texto' => 'Nada que hacer.'];
    }
}
