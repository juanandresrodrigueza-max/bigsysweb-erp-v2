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
                'descripcion' => 'Los pases de mercadería entre sucursales: los que están en viaje, los que llegaron con diferencias, los pedidos de las sucursales sin armar y lo que está en un proveedor por cambio o devolución.',
                'parametros' => [],
                'ejecutar' => fn(User $u, array $a) => ['pases' => TransferenciaStock::with('origen', 'destino', 'items.product')->whereIn('estado', ['en_viaje', 'con_diferencias', 'pedido', 'en_proveedor'])->latest('id')->limit(20)->get()->map(fn($t) => app(\App\Services\Stock\PasesService::class)->resumen($t) + ['url' => Busca::url("/stock/pases/{$t->id}")])->map(fn($r) => collect($r)->except('token')->all())->all()]],
            // Fase 53.2: el mozo le dicta a Bigcito ("mesa 4, dos bifes jugosos con papas y una coca").
            ['nombre' => 'cargar_comanda', 'titulo' => 'Cargar una comanda de palabra', 'modulo' => 'gastronomia', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Carga platos en la comanda de una mesa (o un pedido de mostrador / delivery) con las opciones de cada plato (punto, guarnición, extras, "sin cebolla") y los manda a cocina. Si la mesa ya está abierta, agrega a esa comanda.',
                'parametros' => ['mesa' => ['type' => 'string', 'description' => 'Nombre o número de la mesa; vacío = mostrador'], 'tipo' => ['type' => 'string', 'enum' => ['mesa', 'mostrador', 'delivery']], 'cliente' => ['type' => 'string'], 'direccion' => ['type' => 'string'], 'telefono' => ['type' => 'string'],
                    'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['plato' => ['type' => 'string'], 'cantidad' => ['type' => 'number'], 'opciones' => ['type' => 'array', 'items' => ['type' => 'string']], 'nota' => ['type' => 'string']]]]], 'requeridos' => ['items'],
                'proponer' => function (User $u, array $a) {
                    $mesa = null;
                    if (! empty($a['mesa'])) { $t = trim(preg_replace('/^mesa\s*/iu', '', $a['mesa'])); $mesa = \App\Models\Mesa::where('activa', true)->where(fn($w) => $w->where('nombre', $t)->orWhere('nombre', 'Mesa ' . $t))->first(); if (! $mesa) return ['error' => "No encontré la mesa \"{$a['mesa']}\"."]; }
                    $items = []; $det = [];
                    foreach ($a['items'] as $x) {
                        $p = Busca::articulo($x['plato'] ?? ''); if (! $p) return ['error' => 'No encontré "' . ($x['plato'] ?? '') . '" en la carta.'];
                        [$el, $sobra] = \App\Services\Gastronomia\PedidoRestoService::opciones($p, (array) ($x['opciones'] ?? []));
                        foreach ($p->modificadores ?? [] as $g) if (! empty($g['obligatorio']) && empty($el[$g['nombre']])) return ['error' => "¿{$p->name}: " . implode(', ', array_column($g['opciones'] ?? [], 'nombre')) . '?'];
                        $items[] = ['product_id' => $p->id, 'cantidad' => (float) ($x['cantidad'] ?? 1), 'opciones' => $x['opciones'] ?? [], 'nota' => $x['nota'] ?? null];
                        $det[] = Busca::cant($x['cantidad'] ?? 1) . ' ' . $p->name . ($el ? ' (' . implode(', ', array_merge(...array_values($el))) . ')' : '') . ($sobra ? ' · ' . implode(', ', $sobra) : '');
                    }
                    $titulo = $mesa ? "Mesa {$mesa->nombre}" : (($a['tipo'] ?? 'mostrador') === 'delivery' ? 'Delivery' : 'Mostrador') . (! empty($a['cliente']) ? " · {$a['cliente']}" : '');
                    return ['titulo' => "Cargar en {$titulo} y mandar a cocina", 'detalle' => implode(', ', $det), 'datos' => ['mesa_id' => $mesa?->id, 'tipo' => $a['tipo'] ?? 'mostrador', 'cliente' => $a['cliente'] ?? null, 'direccion' => $a['direccion'] ?? null, 'telefono' => $a['telefono'] ?? null, 'items' => $items]];
                },
                'confirmar' => function (User $u, array $d) {
                    $r = app(\App\Services\Gastronomia\PedidoRestoService::class)->crear($u->business, $d + ['user_id' => $u->id, 'enviar' => true]);
                    return ['texto' => "Listo: {$r['comanda']->titulo()} con " . count($r['cargados']) . ' plato' . (count($r['cargados']) > 1 ? 's' : '') . ' en cocina.', 'url' => Busca::url("/gastronomia/comandas/{$r['comanda']->id}")];
                }],
            // Fase 56: control del salón, opiniones de los clientes e ingeniería de menú.
            // Fase 90: "¿qué mesas rinden más?", "¿cuáles se sientan poco?", "¿a qué hora vendo más?"
            // Fase 91: "reservá para cuatro el sábado a las nueve a nombre de Juan" y "¿cómo viene el delivery?".
            ['nombre' => 'reservar_mesa', 'titulo' => 'Reservar una mesa', 'modulo' => 'gastronomia', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Carga una reserva del salón: si hay lugar queda confirmada, si no queda en lista de espera y se avisa solo cuando se libera. Podés pasar el pedido tal como lo dijo el cliente en "texto".',
                'parametros' => ['texto' => ['type' => 'string', 'description' => 'La reserva en palabras: "para 4 el sábado a las 21, Juan"'], 'nombre' => ['type' => 'string'], 'personas' => ['type' => 'integer'], 'fecha' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'hora' => ['type' => 'string', 'description' => 'HH:MM'], 'telefono' => ['type' => 'string'], 'notas' => ['type' => 'string']],
                'requeridos' => [],
                'proponer' => function (User $u, array $a) {
                    $i = ! empty($a['texto']) ? \App\Services\Gastronomia\ReservasService::interpretar($a['texto']) : ['personas' => null, 'inicio' => null, 'nombre' => null];
                    $personas = (int) ($a['personas'] ?? $i['personas'] ?? 0); $nombre = $a['nombre'] ?? $i['nombre'] ?? null;
                    $inicio = ! empty($a['fecha']) ? \Carbon\Carbon::parse($a['fecha'] . ' ' . ($a['hora'] ?? '21:00')) : $i['inicio'];
                    if (! $personas || ! $inicio) return ['error' => 'Me falta ' . (! $personas ? 'para cuántos' : 'el día y la hora') . '.'];
                    $svc = app(\App\Services\Gastronomia\ReservasService::class); $lugar = $svc->hayLugar($u->business, $inicio, $personas);
                    return ['titulo' => "Reserva para {$personas} el " . $svc->cuando($inicio) . ($nombre ? " a nombre de {$nombre}" : ''), 'detalle' => $lugar ? 'Hay lugar: queda confirmada.' : 'No hay lugar a esa hora: queda en lista de espera y se avisa si se libera.',
                        'datos' => ['personas' => $personas, 'inicio' => $inicio->toDateTimeString(), 'nombre' => $nombre, 'telefono' => $a['telefono'] ?? null, 'notas' => $a['notas'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $r = app(\App\Services\Gastronomia\ReservasService::class)->reservar($u->business, $d + ['origen' => 'bigcito', 'location_id' => $u->current_location_id]);
                    return ['texto' => ($r->status === 'confirmed' ? 'Reserva confirmada' : 'Anotada en lista de espera') . ": {$r->nombre}, {$r->personas} personas, " . $r->starts_at->format('d/m H:i') . '.', 'url' => Busca::url('/gastronomia/reservas')];
                }],
            ['nombre' => 'delivery_en_curso', 'titulo' => 'Cómo viene el delivery', 'modulo' => 'gastronomia', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'El reparto propio ahora: pedidos preparándose, listos para salir, en camino (con quién y hace cuánto) y entregados hoy, con la demora promedio y lo cobrado de envíos.',
                'parametros' => [],
                'ejecutar' => fn(User $u, array $a) => app(\App\Services\Gastronomia\DeliveryService::class)->tablero($u->current_location_id) + ['url' => Busca::url('/gastronomia/delivery')]],
            ['nombre' => 'mapa_de_calor_mesas', 'titulo' => 'Qué mesas rinden más', 'modulo' => 'gastronomia', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Mapa de calor del salón: venta, comandas, cubiertos, ticket promedio y minutos por mesa en los últimos días, las mesas que más rinden, las frías y la venta por franja horaria (desayuno, almuerzo, merienda, cena).',
                'parametros' => ['dias' => ['type' => 'integer', 'description' => 'Cuántos días para atrás (30 si no se dice)']],
                'ejecutar' => fn(User $u, array $a) => app(\App\Services\Gastronomia\CalorSalonService::class)->datos($u->current_location_id, max(1, min(365, (int) ($a['dias'] ?? 30)))) + ['url' => Busca::url('/gastronomia')]],
            ['nombre' => 'control_salon', 'titulo' => 'Control del salón por mozo', 'modulo' => 'gastronomia', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Por mozo: comandas, venta, platos anulados después de salir de cocina, descuentos y propinas, con lo que se aparta de lo normal del equipo.',
                'parametros' => ['desde' => ['type' => 'string'], 'hasta' => ['type' => 'string']],
                'ejecutar' => fn(User $u, array $a) => app(\App\Services\Gastronomia\ControlSalonService::class)->datos($u->business, \Carbon\Carbon::parse($a['desde'] ?? now()->startOfMonth()), \Carbon\Carbon::parse($a['hasta'] ?? now())) + ['url' => Busca::url('/estadisticas/gastronomia')]],
            ['nombre' => 'opiniones_clientes', 'titulo' => 'Qué dicen los clientes', 'modulo' => 'gastronomia', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Las opiniones de la encuesta al pagar (último mes): promedio, por mozo, cuándo se concentran las malas, los comentarios y el resumen.',
                'parametros' => ['dias' => ['type' => 'integer']],
                'ejecutar' => fn(User $u, array $a) => app(\App\Services\Gastronomia\OpinionesService::class)->resumen($u->business, max(1, min(365, (int) ($a['dias'] ?? 30)))) + ['url' => Busca::url('/estadisticas/gastronomia')]],
            ['nombre' => 'ingenieria_de_menu', 'titulo' => 'Ingeniería de menú', 'modulo' => 'gastronomia', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Cada plato por popularidad y margen: estrellas, caballitos, rompecabezas y perros, con qué hacer con cada uno y tres acciones concretas para la semana.',
                'parametros' => ['dias' => ['type' => 'integer']],
                'ejecutar' => function (User $u, array $a) { $s = app(\App\Services\Gastronomia\IngenieriaMenuService::class); $m = $s->matriz($u->business, max(7, min(365, (int) ($a['dias'] ?? 30)))); return $m + ['acciones' => $s->acciones($u->business, $m), 'url' => Busca::url('/estadisticas/gastronomia')]; }],
            // Fase 55: compras y cocina que se adelantan.
            ['nombre' => 'que_comprar_resto', 'titulo' => 'Qué comprar para la semana (Big Restó)', 'modulo' => 'gastronomia', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Lo que se espera vender cada día de la semana que viene (promedio por día de la semana) y los insumos que faltan para cubrirlo según las recetas, agrupados por proveedor con el costo estimado.',
                'parametros' => ['dias' => ['type' => 'integer', 'description' => 'Días a cubrir, 7 por defecto']],
                'ejecutar' => function (User $u, array $a) { $p = app(\App\Services\Gastronomia\PronosticoRestoService::class); return ['semana' => $p->semana($u->business), 'comprar' => $p->queComprar($u->business, max(1, min(30, (int) ($a['dias'] ?? 7)))), 'url' => Busca::url('/estadisticas/gastronomia')]; }],
            ['nombre' => 'menu_del_dia_sugerido', 'titulo' => 'Menú del día con lo que está por vencer', 'modulo' => 'gastronomia', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Insumos que conviene usar primero (por vencer o quietos) y tres menús del día propuestos con ellos, con texto para la pizarra.',
                'parametros' => [],
                'ejecutar' => fn(User $u, array $a) => app(\App\Services\Gastronomia\MenuDelDiaIaService::class)->sugerir($u->business) + ['url' => Busca::url('/gastronomia/carta')]],
            ['nombre' => 'cocina_proxima_hora', 'titulo' => 'Cómo viene la cocina la próxima hora', 'modulo' => 'gastronomia', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Platos esperados por estación en la próxima hora, contra lo normal de esa estación, para saber si se viene un pico.',
                'parametros' => [],
                'ejecutar' => fn(User $u, array $a) => ['estaciones' => app(\App\Services\Gastronomia\PronosticoRestoService::class)->proximaHora($u->business), 'url' => Busca::url('/gastronomia/cocina')]],
            // Fase 52: el salón en números, de palabra ("¿cómo vino el mozo Juan?", "¿qué plato sale más?", "¿cuánto tarda la parrilla?").
            ['nombre' => 'resumen_gastronomia', 'titulo' => 'Salón y cocina', 'modulo' => 'gastronomia', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Ventas del salón por mozo y por hora, ticket promedio, cubiertos, propinas, platos más pedidos y tiempos de cocina por estación, en un período.',
                'parametros' => ['desde' => ['type' => 'string', 'description' => 'YYYY-MM-DD; por defecto el 1 del mes'], 'hasta' => ['type' => 'string']],
                'ejecutar' => fn(User $u, array $a) => app(\App\Services\Gastronomia\ReporteService::class)->datos(\Carbon\Carbon::parse($a['desde'] ?? now()->startOfMonth()), \Carbon\Carbon::parse($a['hasta'] ?? now())) + ['url' => Busca::url('/estadisticas/gastronomia')]],
            // Fase 48: el inductor. Bigcito sabe cómo trabaja la empresa, qué dijo que iba a usar y qué no arrancó.
            ['nombre' => 'como_trabaja_la_empresa', 'titulo' => 'Cómo trabaja la empresa y qué le falta arrancar', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Lo que la empresa contestó en la entrevista de bienvenida (rubro, cómo vende, stock, reparto, tienda, sueldos...), lo que dijo que iba a usar y todavía no usa, y las preguntas pendientes. Usala con usuarios nuevos para guiarlos paso a paso.',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    $i = app(\App\Services\Empresas\InductorService::class); $b = $u->business; $r = $b->induccion['respuestas'] ?? [];
                    $pend = collect($i->paraRubro($r['rubro'] ?? $b->vertical))->except(array_keys($r))->map(fn($p, $k) => ['clave' => $k, 'pregunta' => $p['texto'], 'opciones' => $p['opciones']])->values()->all();
                    return ['hizo_la_entrevista' => (bool) $r, 'como_trabaja' => $i->resumen($b), 'sin_arrancar' => $i->seguimiento($b), 'preguntas_pendientes' => $pend, 'ocultos_del_menu' => $b->modulos_ocultos ?? [], 'url' => Busca::url('/primeros-pasos')];
                }],
            ['nombre' => 'dejar_a_medida', 'titulo' => 'Dejar el sistema a medida del negocio', 'modulo' => 'configuracion', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Guarda las respuestas de la entrevista de bienvenida (claves y valores de como_trabaja_la_empresa) y deja el sistema a medida: rubro, módulos que no usa fuera del menú y primeros pasos de lo que sí usa. Hacé las preguntas de a una, en charla, antes de llamarla.',
                'parametros' => ['respuestas' => ['type' => 'object', 'description' => 'Ej. {"rubro":"corralon","reparto":"si","tienda":"no"}']], 'requeridos' => ['respuestas'],
                'proponer' => function (User $u, array $a) {
                    $p = \App\Services\Empresas\InductorService::preguntas(); $r = array_filter((array) $a['respuestas'], fn($v, $k) => isset($p[$k]['opciones'][$v]), ARRAY_FILTER_USE_BOTH);
                    if (! $r) return ['error' => 'No entendí las respuestas. Usá las claves y valores de como_trabaja_la_empresa.'];
                    $r = array_merge($u->business->induccion['respuestas'] ?? [], $r); $perf = app(\App\Services\Empresas\InductorService::class)->perfil($r);
                    return ['titulo' => 'Dejar el sistema a medida', 'detalle' => 'Usa: ' . (implode(', ', $perf['usos']) ?: 'lo básico') . ($perf['ocultos'] ? ' · Saco del menú: ' . implode(', ', $perf['ocultos']) : ''), 'datos' => ['r' => $r]];
                },
                'confirmar' => function (User $u, array $d) { app(\App\Services\Empresas\InductorService::class)->aplicar($u->business, $d['r']); return ['texto' => 'Listo, quedó a medida. En Primeros pasos tenés lo que sigue para tu negocio.', 'url' => Busca::url('/primeros-pasos')]; }],
            // Fase 44.2: cuántas unidades salieron de cada artículo, sin plata.
            ['nombre' => 'lo_que_salio', 'titulo' => 'Lo que salió de cada artículo', 'modulo' => 'estadisticas', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Unidades que salieron de cada artículo (ventas, pases, consumos, ajustes) en los últimos días, sin importes. Sirve para "cuánto salió de tal cosa este mes".',
                'parametros' => ['dias' => ['type' => 'integer', 'description' => 'Días hacia atrás, por defecto 30.'], 'articulo' => ['type' => 'string']],
                'ejecutar' => function (User $u, array $a) {
                    $r = app(\App\Services\Estadisticas\StockComprasService::class)->loQueSalio($u->business, (int) ($a['dias'] ?? 30));
                    $items = collect($r['items']); if (! empty($a['articulo'])) { $t = mb_strtolower($a['articulo']); $items = $items->filter(fn($i) => str_contains(mb_strtolower($i['nombre'] . ' ' . $i['sku']), $t)); }
                    return ['dias' => $r['dias'], 'articulos' => $items->take(40)->values()->all(), 'url' => Busca::url('/estadisticas/stock')];
                }],
            // Fase 42: pedir al depósito y mandar a un proveedor, de palabra.
            ['nombre' => 'pedir_al_deposito', 'titulo' => 'Pedirle mercadería al depósito', 'modulo' => 'stock', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'La sucursal le pide artículos al depósito ("pedile al depósito 10 bolsas de cemento"). No mueve stock: el depósito arma el pase desde el pedido.',
                'parametros' => ['articulos' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['articulo' => ['type' => 'string'], 'cantidad' => ['type' => 'number']]]], 'deposito' => ['type' => 'string', 'description' => 'A qué depósito le pide; si no lo dice, el principal.'], 'para' => ['type' => 'string', 'description' => 'Qué sucursal pide; si no lo dice, la del usuario.']], 'requeridos' => ['articulos'],
                'proponer' => function (User $u, array $a) {
                    $deps = \App\Models\Deposito::where('activo', true)->get();
                    $buscar = fn($t) => $t ? $deps->first(fn($d) => str_contains(mb_strtolower($d->nombre), mb_strtolower($t))) : null;
                    $dep = $buscar($a['deposito'] ?? null) ?? $deps->firstWhere('es_default', true) ?? $deps->first();
                    $para = $buscar($a['para'] ?? null) ?? $deps->first(fn($d) => $d->business_location_id === $u->current_location_id && $d->id !== $dep?->id) ?? $deps->first(fn($d) => $d->id !== $dep?->id);
                    if (! $dep || ! $para || $dep->id === $para->id) return ['error' => 'Necesito dos depósitos distintos: el que manda y la sucursal que pide.'];
                    $items = []; foreach ($a['articulos'] as $x) { $p = Busca::articulo($x['articulo'] ?? ''); if (! $p) return ['error' => 'No encontré el artículo "' . ($x['articulo'] ?? '') . '".']; $items[] = ['product_id' => $p->id, 'cantidad' => (float) ($x['cantidad'] ?? 1), 'nombre' => $p->name]; }
                    return ['titulo' => "{$para->nombre} le pide a {$dep->nombre}", 'detalle' => collect($items)->map(fn($i) => Busca::cant($i['cantidad']) . ' ' . $i['nombre'])->implode(', '), 'datos' => ['dep' => $dep->id, 'para' => $para->id, 'items' => $items]];
                },
                'confirmar' => function (User $u, array $d) { $t = app(\App\Services\Stock\PasesService::class)->pedir(\App\Models\Deposito::findOrFail($d['dep']), \App\Models\Deposito::findOrFail($d['para']), $d['items']); return ['texto' => "Pedido {$t->numeroFormateado()} hecho: el depósito lo ve en Pases para armarlo.", 'url' => Busca::url("/stock/pases/{$t->id}")]; }],
            ['nombre' => 'mandar_a_proveedor', 'titulo' => 'Mandar mercadería al proveedor (cambio o devolución)', 'modulo' => 'stock', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Saca del stock lo que va al proveedor por cambio o devolución, con el proveedor en el remito; queda "en el proveedor" hasta que vuelve.',
                'parametros' => ['proveedor' => ['type' => 'string'], 'articulos' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['articulo' => ['type' => 'string'], 'cantidad' => ['type' => 'number']]]], 'motivo' => ['type' => 'string']], 'requeridos' => ['proveedor', 'articulos'],
                'proponer' => function (User $u, array $a) {
                    $prov = Busca::proveedor($a['proveedor']); if (! $prov) return ['error' => 'No encontré al proveedor "' . $a['proveedor'] . '".'];
                    $dep = \App\Models\Deposito::where('activo', true)->where('es_default', true)->first() ?? \App\Models\Deposito::where('activo', true)->first();
                    $items = []; foreach ($a['articulos'] as $x) { $p = Busca::articulo($x['articulo'] ?? ''); if (! $p) return ['error' => 'No encontré el artículo "' . ($x['articulo'] ?? '') . '".']; $items[] = ['product_id' => $p->id, 'cantidad' => (float) ($x['cantidad'] ?? 1), 'nombre' => $p->name]; }
                    return ['titulo' => "Mandar a {$prov->name} desde {$dep->nombre}", 'detalle' => collect($items)->map(fn($i) => Busca::cant($i['cantidad']) . ' ' . $i['nombre'])->implode(', ') . (($a['motivo'] ?? '') ? " · {$a['motivo']}" : ''), 'datos' => ['dep' => $dep->id, 'prov' => $prov->id, 'items' => $items, 'motivo' => $a['motivo'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) { $t = app(\App\Services\Stock\PasesService::class)->aProveedor(\App\Models\Deposito::findOrFail($d['dep']), \App\Models\Contact::findOrFail($d['prov']), $d['items'], $d['motivo']); return ['texto' => "{$t->numeroFormateado()}: salió del stock a la cuenta de cambios del proveedor. Cuando vuelva, se recibe en Stock → Pases.", 'url' => Busca::url("/stock/pases/{$t->id}")]; }],
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
            // Fase 41: migrar a un cliente de BigSys desde BIG Connect.
            ['nombre' => 'migrar_desde_bigconnect', 'titulo' => 'Traer los datos desde BIG Connect', 'modulo' => 'configuracion', 'permiso' => 'editar', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Trae proveedores, clientes, artículos, stock y saldos impagos desde BIG Connect (BigSys). Con prueba=true sólo dice qué pasaría, sin guardar. Requiere la conexión cargada en Configuración → Importar.',
                'parametros' => ['prueba' => ['type' => 'boolean', 'description' => 'true = simular sin guardar (recomendado primero)']],
                'proponer' => function (User $u, array $a) {
                    $c = app(\App\Services\Migracion\BigConnectService::class)->config($u->business()->first());
                    if (! $c['conectado']) return ['error' => 'Todavía no está conectado BIG Connect: cargá la dirección y la clave en Configuración → Importar.', 'url' => Busca::url('/configuracion/importar')];
                    $p = (bool) ($a['prueba'] ?? true);
                    return ['titulo' => $p ? 'Probar la migración desde BIG Connect (no guarda nada)' : 'Migrar de verdad desde BIG Connect', 'detalle' => 'Proveedores, clientes, artículos con sus listas y stock, y saldos por comprobante. Lo que ya existe se actualiza, no se duplica.', 'datos' => ['prueba' => $p]];
                },
                'confirmar' => function (User $u, array $d) {
                    $r = app(\App\Services\Migracion\BigConnectService::class)->migrar($u->business()->first(), $d['prueba']);
                    return ['texto' => ($r['prueba'] ? 'Prueba (sin guardar): ' : 'Migrado: ') . collect($r['pasos'])->map(fn($p) => "{$p['que']} {$p['en_bigsys']} ({$p['creadas']} nuevos, {$p['actualizadas']} actualizados" . ($p['errores'] ? ", {$p['errores']} con error" : '') . ')')->implode('; ') . '.',
                        '_tabla' => ['columnas' => ['Qué', 'En BigSys', 'Nuevos', 'Actualizados', 'Con error'], 'filas' => array_map(fn($p) => [$p['que'], $p['en_bigsys'], $p['creadas'], $p['actualizadas'], $p['errores']], $r['pasos'])]];
                }],
            // Fase 40.5: avisos al celular, novedades, parte de la mañana y mejoras.
            ['nombre' => 'novedades_bigsys', 'titulo' => 'Novedades de BigSys', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Lo nuevo que trae el sistema (la campanita de novedades), sin las que la persona pospuso.',
                'ejecutar' => fn(User $u, array $a) => ['novedades' => app(\App\Services\Avisos\CelularService::class)->novedades($u)]],
            ['nombre' => 'posponer_novedad', 'titulo' => 'Posponer o marcar leída una novedad', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'accion',
                'descripcion' => 'Marca una novedad como leída o la esconde por 7 o 30 días.',
                'parametros' => ['id' => ['type' => 'integer'], 'dias' => ['type' => 'integer', 'enum' => [0, 7, 30], 'description' => '0 = marcar leída.']], 'requeridos' => ['id'],
                'proponer' => function (User $u, array $a) { $n = \App\Models\Novedad::find((int) $a['id']); if (! $n) return ['error' => 'No encontré esa novedad.']; $d = in_array((int) ($a['dias'] ?? 0), [7, 30], true) ? (int) $a['dias'] : 0; return ['titulo' => ($d ? "Posponer {$d} días" : 'Marcar leída') . ": {$n->titulo}", 'datos' => ['id' => $n->id, 'dias' => $d]]; },
                'confirmar' => function (User $u, array $d) { app(\App\Services\Avisos\CelularService::class)->marcar($u, $d['id'], $d['dias'] ?: null); return ['texto' => $d['dias'] ? "Listo, te la vuelvo a mostrar en {$d['dias']} días." : 'Listo, marcada como leída.']; }],
            ['nombre' => 'parte_de_la_manana', 'titulo' => 'Parte de la mañana', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Cómo cerró ayer (ventas, cobros, caja y banco, vencido) y qué vence hoy para pagar y cobrar. También dice a qué hora llega solo al celular.',
                'ejecutar' => function (User $u, array $a) { $s = app(\App\Services\Avisos\CelularService::class); $b = $u->business()->first(); return ['parte' => $s->parte($b)['texto'], 'configuracion' => collect($s->configManana($b))->except('ultimo')->all()]; }],
            ['nombre' => 'configurar_parte_manana', 'titulo' => 'Configurar el parte de la mañana', 'modulo' => 'configuracion', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Prende o apaga el parte de la mañana y elige la hora y por dónde llega (aviso al celular y/o WhatsApp). Sólo el dueño.',
                'parametros' => ['activo' => ['type' => 'boolean'], 'hora' => ['type' => 'string', 'description' => 'HH:MM, ej. 07:30'], 'push' => ['type' => 'boolean'], 'whatsapp' => ['type' => 'boolean']],
                'proponer' => function (User $u, array $a) {
                    if (! $u->esDueno()) return ['error' => 'El parte de la mañana lo configura el dueño.'];
                    if (isset($a['hora']) && ! preg_match('/^\d{2}:\d{2}$/', $a['hora'])) return ['error' => 'La hora va como HH:MM, por ejemplo 07:30.'];
                    $c = array_replace(app(\App\Services\Avisos\CelularService::class)->configManana($u->business()->first()), ['activo' => true], array_intersect_key($a, array_flip(['activo', 'hora', 'push', 'whatsapp'])));
                    return ['titulo' => $c['activo'] ? "Parte de la mañana a las {$c['hora']}" : 'Apagar el parte de la mañana', 'detalle' => 'Por ' . implode(' y ', array_filter([$c['push'] ? 'aviso al celular' : null, $c['whatsapp'] ? 'WhatsApp' : null])) ?: 'ningún canal', 'datos' => $c];
                },
                'confirmar' => function (User $u, array $d) { $c = app(\App\Services\Avisos\CelularService::class)->guardarManana($u->business()->first(), $d); return ['texto' => $c['activo'] ? "Listo: todos los días a las {$c['hora']} te llega el parte." : 'Listo, apagado.']; }],
            ['nombre' => 'probar_aviso_celular', 'titulo' => 'Mandar un aviso de prueba al celular', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'accion',
                'descripcion' => 'Manda un aviso de prueba a los dispositivos donde la persona activó los avisos.',
                'proponer' => fn(User $u, array $a) => \App\Models\PushSuscripcion::where('user_id', $u->id)->exists() ? ['titulo' => 'Mandar un aviso de prueba', 'datos' => []] : ['error' => 'Todavía no activaste los avisos en ningún dispositivo: entrá a Avisos al celular y tocá "Activar en este dispositivo".', 'url' => Busca::url('/avisos-celular')],
                'confirmar' => fn(User $u, array $d) => ['texto' => 'Mandado a ' . app(\App\Services\Avisos\CelularService::class)->enviar($u, 'Hola desde Bigcito', 'Así te van a llegar los avisos.', '/avisos-celular') . ' dispositivo/s.']],
            ['nombre' => 'mejoras_disponibles', 'titulo' => 'Módulos que no tengo', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Los módulos del sistema que la empresa no tiene contratados, qué hace cada uno y si ya pidió información.',
                'ejecutar' => fn(User $u, array $a) => ['mejoras' => app(\App\Services\Avisos\CelularService::class)->mejoras($u->business()->first()), 'url' => Busca::url('/mejoras')]],
            ['nombre' => 'me_interesa_modulo', 'titulo' => 'Pedir información de un módulo', 'modulo' => 'configuracion', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Avisa a BigSys que a la empresa le interesa un módulo que no tiene, para que la contacten.',
                'parametros' => ['modulo' => ['type' => 'string', 'description' => 'Clave o nombre del módulo, ej. sueldos, Contable.'], 'comentario' => ['type' => 'string']], 'requeridos' => ['modulo'],
                'proponer' => function (User $u, array $a) {
                    $m = collect(app(\App\Services\Avisos\CelularService::class)->mejoras($u->business()->first()))->first(fn($x) => $x['modulo'] === mb_strtolower($a['modulo']) || mb_strtolower($x['nombre']) === mb_strtolower($a['modulo']));
                    if (! $m) return ['error' => 'Ese módulo no existe o ya lo tenés.'];
                    return ['titulo' => "Avisar a BigSys que te interesa {$m['nombre']}", 'detalle' => $a['comentario'] ?? null, 'datos' => ['modulo' => $m['modulo'], 'comentario' => $a['comentario'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) { app(\App\Services\Avisos\CelularService::class)->meInteresa($u, $d['modulo'], $d['comentario']); return ['texto' => 'Listo, BigSys te va a escribir para contarte cómo sumarlo.']; }],
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
