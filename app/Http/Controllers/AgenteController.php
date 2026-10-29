<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Comprobante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Agente IA flotante: responde dudas del sistema y del negocio con contexto real de la empresa.
class AgenteController extends Controller
{
    public function chat(Request $request)
    {
        $data = $request->validate([
            'mensaje'   => 'required|string|max:4000',
            'historial' => 'nullable|array|max:20',
            'historial.*.rol'   => 'required_with:historial|in:user,assistant',
            'historial.*.texto' => 'required_with:historial|string|max:4000',
            'pantalla'  => 'nullable|string|max:200',
            'imagen'    => 'nullable|string|max:7500000', // Fase 40.3: una foto con la pregunta (data URL)
        ]);

        $user     = $request->user();
        $contexto = $this->contexto($user);
        $apiKey   = config('services.anthropic.api_key');
        $acciones = app(\App\Services\IA\AccionesService::class);
        $cat      = app(\App\Mcp\Catalogo::class);

        if (! $apiKey && ! empty($data['imagen'])) return response()->json(['respuesta' => 'Para mirar fotos hace falta la clave de IA de la empresa (Configuración → Conexiones IA).', 'modo' => 'local']);
        if (! $apiKey) {
            // Sin IA: se entienden las frases más comunes (cobros, gastos, recordatorios, presupuestos, saldos, stock, ventas).
            if ($i = $acciones->interpretarLocal($data['mensaje'])) {
                if (isset($i['consulta'])) return response()->json(['respuesta' => $acciones->consultar($i['consulta'], $i['args']), 'modo' => 'local']);
                $p = $this->proponer($cat, $user, $i['accion'], $i['args']);
                return isset($p['error']) ? response()->json(['respuesta' => $p['error'], 'modo' => 'local']) : response()->json(['respuesta' => "Te propongo esto. ¿Confirmás?", 'propuesta' => $p, 'modo' => 'local']);
            }
            return response()->json(['respuesta' => $this->respuestaLocal($data['mensaje'], $contexto), 'modo' => 'local']);
        }

        $mensajes = collect($data['historial'] ?? [])
            ->map(fn($m) => ['role' => $m['rol'], 'content' => $m['texto']])
            ->push(['role' => 'user', 'content' => ! empty($data['imagen']) && ($img = \App\Services\IA\VozFotoService::bloqueImagen($data['imagen'])) ? [$img, ['type' => 'text', 'text' => $data['mensaje']]] : $data['mensaje']])
            ->values()->all();

        try {
            $tools = $cat->paraAnthropic($user);
            $llamar = fn(array $msgs) => Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 800, 'system' => $this->systemPrompt($user, $contexto, $data['pantalla'] ?? null), 'messages' => $msgs, 'tools' => $tools,
            ]);
            $res = $llamar($mensajes); $adjuntos = [];
            // Hasta 3 rondas: las consultas se resuelven y se le devuelven a la IA; una acción se propone al usuario.
            for ($ronda = 0; $ronda < 3 && $res->successful(); $ronda++) {
                $contenido = $res->json('content', []);
                $usos = collect($contenido)->where('type', 'tool_use');
                $texto = collect($contenido)->where('type', 'text')->pluck('text')->implode("\n");
                if ($usos->isEmpty()) return response()->json($this->armar($texto ?: 'No pude generar una respuesta. Probá de nuevo.', $adjuntos));
                $accion = $usos->first(fn($u) => ($cat->todas()[$u['name']]['tipo'] ?? null) === 'accion');
                if ($accion) {
                    $p = $this->proponer($cat, $user, $accion['name'], (array) $accion['input']);
                    return isset($p['error']) ? response()->json(['respuesta' => trim($texto . "\n" . $p['error']), 'modo' => 'ia']) : response()->json(['respuesta' => trim($texto) ?: 'Te propongo esto. ¿Confirmás?', 'propuesta' => $p, 'modo' => 'ia']);
                }
                $resultados = $usos->map(function ($u) use ($cat, $user, &$adjuntos) {
                    $r = $cat->llamar($user, $u['name'], (array) $u['input'], 'asistente');
                    // Fase 40.1: la tabla (para bajar a Excel) y el gráfico van al panel; al modelo le llega el resto.
                    if (isset($r['_tabla'])) $adjuntos['tabla'] = $r['_tabla'] + ['nombre' => $u['name']];
                    if (isset($r['_grafico'])) $adjuntos['grafico'] = $r['_grafico'];
                    if (! isset($r['_tabla']) && ($lista = collect($r)->first(fn($v) => is_array($v) && array_is_list($v) && isset($v[0]) && is_array($v[0]) && count($v) >= 3))) $adjuntos['tabla'] ??= ['columnas' => array_keys($lista[0]), 'filas' => array_map(fn($f) => array_map(fn($x) => is_array($x) ? implode(', ', array_map('strval', $x)) : $x, array_values($f)), $lista), 'nombre' => $u['name']];
                    unset($r['_tabla'], $r['_grafico']);
                    return ['type' => 'tool_result', 'tool_use_id' => $u['id'], 'content' => json_encode($r, JSON_UNESCAPED_UNICODE)];
                })->values()->all();
                $mensajes[] = ['role' => 'assistant', 'content' => $contenido];
                $mensajes[] = ['role' => 'user', 'content' => $resultados];
                $res = $llamar($mensajes);
            }
            if (! $res->successful()) {
                Log::warning('Agente IA: respuesta no exitosa', ['status' => $res->status(), 'body' => $res->body()]);
                return response()->json(['respuesta' => $this->respuestaLocal($data['mensaje'], $contexto), 'modo' => 'local']);
            }
            $texto = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
            return response()->json($this->armar($texto ?: 'No pude generar una respuesta. Probá de nuevo.', $adjuntos));
        } catch (\Throwable $e) {
            Log::error('Agente IA: error', ['e' => $e->getMessage()]);
            return response()->json(['respuesta' => $this->respuestaLocal($data['mensaje'], $contexto), 'modo' => 'local']);
        }
    }

    // Fase 40.3: un audio grabado se transcribe (OpenAI o Gemini) y vuelve como texto; el panel lo manda como pregunta por voz.
    public function audio(Request $request)
    {
        $request->validate(['audio' => 'required|file|max:12288']);
        $f = $request->file('audio');
        $texto = app(\App\Services\IA\VozFotoService::class)->transcribir($request->user()->business()->first(), $f->getRealPath(), $f->getMimeType() ?: 'audio/webm');
        return response()->json(['texto' => $texto]);
    }

    // El usuario confirmó una propuesta: se ejecuta con sus permisos (vueltos a chequear) y queda auditado. Mismo catálogo que MCP.
    public function ejecutar(Request $request, \App\Mcp\Catalogo $cat)
    {
        $d = $request->validate(['propuesta_id' => 'required|string|max:26', 'cancelar' => 'nullable|boolean']);
        $r = $d['cancelar'] ?? false ? $cat->cancelar($request->user(), $d['propuesta_id']) : $cat->llamar($request->user(), 'confirmar_accion', ['propuesta_id' => $d['propuesta_id']], 'asistente');
        if (isset($r['error'])) return response()->json(['message' => $r['error']], str_contains($r['error'], 'permiso') ? 403 : 422);
        return response()->json($r);
    }

    // Fase 40.1: separa las repreguntas sugeridas ("SUGERENCIAS: a | b | c") del texto y suma la tabla y el gráfico.
    private function armar(string $texto, array $adjuntos): array
    {
        $sug = [];
        if (preg_match('/\n?\s*SUGERENCIAS?:\s*(.+)\s*$/iu', $texto, $m)) { $sug = array_values(array_filter(array_map('trim', explode('|', $m[1])))); $texto = trim(str_replace($m[0], '', $texto)); }
        return ['respuesta' => $texto, 'modo' => 'ia', 'sugerencias' => array_slice($sug, 0, 3)] + $adjuntos;
    }

    // Arma la propuesta en el catálogo y la devuelve con el formato del panel (id, título, detalle).
    private function proponer(\App\Mcp\Catalogo $cat, $user, string $accion, array $args): array
    {
        $p = $cat->llamar($user, $accion, $args, 'asistente');
        if (isset($p['error'])) return $p;
        return ['id' => $p['propuesta_id'], 'accion' => $p['accion'], 'titulo' => $p['titulo'], 'detalle' => $p['detalle'], 'delicada' => $p['delicada']];
    }

    private function contexto($user): array
    {
        $hoy = now();
        return [
            'empresa'        => $user->business?->name,
            'sucursal'       => $user->currentLocation?->name,
            'rol'            => $user->rolActual()?->nombre ?? 'Dueño',
            'ventas_mes'     => (float) Comprobante::ventas()->emitidos()->facturas()->whereMonth('fecha', $hoy->month)->whereYear('fecha', $hoy->year)->sum('total'),
            'ventas_hoy'     => (float) Comprobante::ventas()->emitidos()->facturas()->whereDate('fecha', $hoy)->sum('total'),
            'por_cobrar'     => (float) Contact::customers()->where('balance', '>', 0)->sum('balance'),
            'vencido'        => (float) Comprobante::ventas()->pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->sum('saldo'),
            'presupuestos_abiertos' => Comprobante::ventas()->emitidos()->where('tipo', 'PRE')->whereDoesntHave('derivados')->count(),
            'clientes'       => Contact::customers()->count(),
            'por_pagar'      => (float) Contact::suppliers()->where('balance', '>', 0)->sum('balance'),
            'pagos_vencidos' => (float) Comprobante::compras()->pendientesPago()->where('fecha_vto', '<', today()->toDateString())->sum('saldo'),
            'disponible'     => (float) \App\Models\CuentaFondos::where('activa', true)->sum('saldo'),
            'cheques_cartera' => (float) \App\Models\Cheque::enCartera()->sum('monto'),
            'cheques_propios' => (float) \App\Models\Cheque::propiosPendientes()->sum('monto'),
            'productos'      => Product::where('active', true)->count(),
            'bajo_minimo'    => Product::where('active', true)->where('controla_stock', true)->whereColumn('stock', '<=', 'stock_min')->count(),
            'stock_valorizado' => (float) Product::where('active', true)->where('controla_stock', true)->selectRaw('COALESCE(SUM(stock * cost),0) as v')->value('v'),
            'ordenes_abiertas' => \App\Models\ProductionOrder::whereIn('status', ['pending', 'in_progress'])->count(),
            'resultado_mes'  => $user->business_id ? app(\App\Services\Contabilidad\ContabilidadService::class)->resultado($user->business_id, $hoy->copy()->startOfMonth()->toDateString(), $hoy->copy()->endOfMonth()->toDateString()) : null,
            'alertas'        => Alerta::visiblesPara($user)->activas()->latest()->limit(5)->pluck('titulo')->all(),
            'modulos'        => $user->modulosVisibles(),
        ];
    }

    private function systemPrompt($user, array $c, ?string $pantalla): string
    {
        $modulos = implode(', ', $c['modulos']);
        $alertas = $c['alertas'] ? '- ' . implode("\n- ", $c['alertas']) : 'ninguna';
        $fmt = fn($n) => '$ ' . number_format($n, 0, ',', '.');

        return <<<TXT
Sos el asistente de BigSysWeb ERP, un sistema de gestión para PyMEs argentinas (facturación AFIP, clientes, proveedores, stock, producción, fondos, contabilidad).
Respondés en español rioplatense, claro y breve (máximo 6 líneas salvo que pidan detalle). Usás voseo. Sin emojis.
Cuando te preguntan cómo hacer algo en el sistema, das los pasos concretos: menú, botón, campo. Si el módulo todavía no está disponible, lo decís sin inventar.
Cuando te preguntan por datos del negocio, usás los números de abajo; si el dato no está, decís que todavía no lo tenés y en qué pantalla se vería.
Nunca inventás importes ni comprobantes.
Siempre decí el período que usaste ("este mes", "del 1 al 15 de octubre") y, si hiciste una cuenta o proyección, cómo la hiciste en una frase.
Si la respuesta es una lista o una comparación, la pantalla muestra la tabla (con botón para bajarla a Excel) y el gráfico: no repitas todas las filas, resumí lo importante.
Para consejos usá las herramientas: qué reponer (que_reponer), a quién cobrar primero, qué precio revisar, quién dejó de comprar, resumen del día, proyección de ventas y deudas por antigüedad.
Al final, en una línea aparte, sugerí 2 o 3 preguntas cortas que sigan la charla con el formato exacto: SUGERENCIAS: pregunta 1 | pregunta 2 | pregunta 3
Tenés herramientas: para consultar (saldo de un cliente, stock de un artículo, deudores, ventas de un período) usalas y respondé con el dato; para hacer cosas (registrar un cobro o un gasto, armar un presupuesto, mandar un recordatorio de deuda) llamá a la herramienta con lo que el usuario dijo: el sistema le muestra una propuesta y él la confirma. Si falta un dato clave (importe, cliente) preguntalo antes.

Usuario: {$user->name}, rol {$c['rol']}, empresa {$c['empresa']}, sucursal {$c['sucursal']}.
Pantalla actual: {$pantalla}.
Módulos habilitados: {$modulos}.

Datos del negocio (sucursal y empresa del usuario):
- Ventas de hoy: {$fmt($c['ventas_hoy'])}
- Ventas del mes: {$fmt($c['ventas_mes'])}
- Saldo por cobrar a clientes: {$fmt($c['por_cobrar'])}, de los cuales vencido: {$fmt($c['vencido'])}
- Presupuestos sin respuesta: {$c['presupuestos_abiertos']}
- Deuda con proveedores: {$fmt($c['por_pagar'])}, de la cual vencida: {$fmt($c['pagos_vencidos'])}
- Dinero disponible en cajas, bancos y billeteras: {$fmt($c['disponible'])}
- Cheques de terceros en cartera: {$fmt($c['cheques_cartera'])}; cheques propios entregados a debitar: {$fmt($c['cheques_propios'])}
- Clientes: {$c['clientes']}
- Productos activos: {$c['productos']}, bajo mínimo: {$c['bajo_minimo']}, stock valorizado a costo: {$fmt($c['stock_valorizado'])}
- Órdenes de producción abiertas: {$c['ordenes_abiertas']}
- Resultado contable del mes: ingresos {$fmt($c['resultado_mes']['total_ingresos'] ?? 0)}, egresos {$fmt($c['resultado_mes']['total_egresos'] ?? 0)}, resultado {$fmt($c['resultado_mes']['resultado'] ?? 0)}
Alertas activas:
{$alertas}

Menú del sistema: Inicio (dashboard), Comprobantes, Clientes, Proveedores, Stock, Producción, Fondos, Contable, Estadísticas, Alertas, Configuración (empresa, sucursales, usuarios, roles, puntos de venta y AFIP).
Cómo se hacen las cosas:
- Orden de compra a un proveedor: Proveedores → Órdenes de compra → Nueva orden. "Sugerir pedido" calcula qué pedir según lo vendido en un período o lo que está bajo mínimo. Al recibir la mercadería, "Recibir" arma la factura de compra con lo pendiente.
- Precios por margen: en el artículo activá "Calcular listas por margen": precio de lista del proveedor − descuento = costo, y cada lista = costo + su margen. Al registrar una compra el costo se actualiza y las listas se recalculan solas. Los artículos en dólares se convierten con la cotización del día (botón U\$S en Stock).
- Importar la lista de precios de un proveedor: Stock → Importar lista (Excel o CSV): elegís qué columna es cada dato y se crean o actualizan los artículos.
- Vendedores y comisiones: Clientes → Vendedores. Cada factura y cobro lleva vendedor; la liquidación por período está ahí.
- Cobro con descuento o interés: en Registrar cobro, "Descuento otorgado" cancela deuda sin cobrarse; "Interés cobrado" se suma a lo que paga el cliente. La mora sugerida sale del % mensual del cliente.
- Cierre de turno: en Fondos, al cerrar la caja se declara lo que hay por cada medio (efectivo, tarjeta, MercadoPago, transferencias, cuenta corriente) y se compara con el sistema. La rendición se imprime desde "Últimos turnos".
- Nueva factura/presupuesto/remito: Comprobantes > Nuevo. Elegís tipo, cliente, cargás ítems (o pegás un mensaje de WhatsApp / subís una foto y la IA arma los ítems) y apretás Emitir. La letra A/B/C sale sola según el cliente.
- Nota de crédito: abrís la factura y tocás "Nota de crédito".
- Cobrar: Clientes > ficha del cliente > "Registrar cobro"; podés combinar efectivo, transferencia, cheque, MercadoPago y elegir qué facturas cancela.
- Acopio: al emitir una factura marcás "Es acopio"; el cliente paga todo y retira de a poco desde su ficha > Acopios > "Registrar retiro" (genera remito).
- Facturar varios presupuestos o remitos juntos: Comprobantes > Facturación por lote.
- Cargar una factura de proveedor: Proveedores > Compras > "Cargar factura" (a mano, con foto/PDF leído por IA, o "Importar de AFIP" con el CSV de Mis Comprobantes). Al registrar impacta cuenta corriente y stock.
- Pagar a un proveedor: Proveedores > ficha > "Registrar pago": transferencia, efectivo, cheque propio, endoso de cheque de tercero o retención; se imputa a facturas y se imprime la orden de pago.
- Stock: cada sucursal tiene depósitos; el stock se ve por depósito y total. Desde Stock: nuevo artículo (con listas de precios 1 a 5), ajustar stock desde la ficha del artículo, "Transferir" entre depósitos, "Inventario" para contar un depósito completo, "Actualizar precios" por porcentaje (por rubro o proveedor), y "Movimientos" para el kardex.
- Producción: en Producción > Fórmulas se define qué insumos lleva cada producto elaborado (el costo se calcula solo). Luego "Orden de producción": elegís fórmula y cantidad; al "Terminar" se descuentan los insumos y entra el producto terminado al depósito con su costo actualizado.
- Punto de venta (Comercio / Minimarket): escaneás el código de barras o buscás el artículo, tocás Cobrar (F9), elegís efectivo/tarjeta/MercadoPago (acepta pago mixto y calcula el vuelto) y sale el ticket. Factura y cobro quedan registrados solos.
- Gastronomía: en Salón tocás una mesa para abrirla, cargás lo que piden desde la carta, "Enviar a cocina" lo manda a la pantalla de Cocina (que se refresca sola), la cocina marca "Listo", el mozo entrega, y "Cerrar y cobrar" factura con propina y descuento. También hay mostrador y delivery.
- Contable: los asientos se generan solos con cada operación. En Contable hay Resumen (resultado del período), Asientos (libro diario, con asiento manual), Mayor por cuenta, Libros IVA ventas y compras (exportables a CSV), Balance de sumas y saldos, Flujo de fondos (mes a mes y proyección a 30 días), Conciliación bancaria (se importa el CSV del home banking y se cruza solo) y Plan de cuentas.
- Estadísticas: ventas por día/mes, por sucursal, mejores clientes, artículos más vendidos, rubros, vendedores, medios de cobro, proveedores y horas de venta, con selector de período.
- Fondos: cajas, bancos y billeteras con saldo en tiempo real. Gasto, Ingreso y Transferir desde Fondos; turnos de caja con apertura y cierre; cartera de cheques en Fondos > Cheques (depositar, acreditar, rechazar).
- Sin certificado AFIP las facturas se emiten simuladas (sin CAE). Se carga en Configuración > Puntos de venta y AFIP.
Para cambiar de sucursal: selector arriba a la izquierda del encabezado. Para ver alertas: campana arriba a la derecha.
TXT;
    }

    private function respuestaLocal(string $mensaje, array $c): string
    {
        $m = mb_strtolower($mensaje);
        $fmt = fn($n) => '$ ' . number_format($n, 0, ',', '.');

        return match (true) {
            str_contains($m, 'venta') && str_contains($m, 'hoy') => "Hoy llevás {$fmt($c['ventas_hoy'])} en ventas.",
            str_contains($m, 'venta')                            => "Este mes llevás {$fmt($c['ventas_mes'])} en ventas; hoy {$fmt($c['ventas_hoy'])}.",
            str_contains($m, 'cobrar') || str_contains($m, 'deben') => "Tenés {$fmt($c['por_cobrar'])} por cobrar a clientes; vencido: {$fmt($c['vencido'])}. Lo ves en Clientes con el filtro Deudores.",
            str_contains($m, 'factur') || str_contains($m, 'presupuesto') => 'Comprobantes > Nuevo: elegís tipo y cliente, cargás ítems (o pegás el pedido de WhatsApp y la IA los arma) y apretás Emitir. La letra A/B/C sale sola.',
            str_contains($m, 'acopio') => 'Marcá "Es acopio" al facturar. Después, en la ficha del cliente, pestaña Acopios, registrás cada retiro y sale el remito.',
            str_contains($m, 'stock')                            => "Hay {$c['bajo_minimo']} productos bajo el mínimo de {$c['productos']} activos. Lo ves en Stock.",
            str_contains($m, 'alerta')                           => $c['alertas'] ? "Alertas activas:\n- " . implode("\n- ", $c['alertas']) : 'No tenés alertas activas.',
            str_contains($m, 'sucursal')                         => 'Para cambiar de sucursal usá el selector del encabezado, a la izquierda. Solo ves las sucursales a las que tenés acceso.',
            str_contains($m, 'usuario') || str_contains($m, 'permiso') || str_contains($m, 'rol') => 'Los usuarios y roles se administran en Configuración > Usuarios y Configuración > Roles. Cada rol define qué módulos ve y qué puede hacer.',
            default => "Todavía no está cargada la clave de IA de la empresa (Configuración → Conexiones IA). Mientras tanto puedo responder sobre ventas, cobros, stock, alertas, sucursales y usuarios con los datos actuales de {$c['empresa']}.",
        };
    }
}
