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
        ]);

        $user     = $request->user();
        $contexto = $this->contexto($user);
        $apiKey   = config('services.anthropic.api_key');

        if (! $apiKey) {
            return response()->json(['respuesta' => $this->respuestaLocal($data['mensaje'], $contexto), 'modo' => 'local']);
        }

        $mensajes = collect($data['historial'] ?? [])
            ->map(fn($m) => ['role' => $m['rol'], 'content' => $m['texto']])
            ->push(['role' => 'user', 'content' => $data['mensaje']])
            ->values()->all();

        try {
            $res = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
                'model'      => config('services.anthropic.model'),
                'max_tokens' => 800,
                'system'     => $this->systemPrompt($user, $contexto, $data['pantalla'] ?? null),
                'messages'   => $mensajes,
            ]);

            if (! $res->successful()) {
                Log::warning('Agente IA: respuesta no exitosa', ['status' => $res->status(), 'body' => $res->body()]);
                return response()->json(['respuesta' => $this->respuestaLocal($data['mensaje'], $contexto), 'modo' => 'local']);
            }

            $texto = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
            return response()->json(['respuesta' => $texto ?: 'No pude generar una respuesta. Probá de nuevo.', 'modo' => 'ia']);
        } catch (\Throwable $e) {
            Log::error('Agente IA: error', ['e' => $e->getMessage()]);
            return response()->json(['respuesta' => $this->respuestaLocal($data['mensaje'], $contexto), 'modo' => 'local']);
        }
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
            'vencido'        => (float) Comprobante::ventas()->pendientesCobro()->whereDate('fecha_vto', '<', today())->sum('saldo'),
            'presupuestos_abiertos' => Comprobante::ventas()->emitidos()->where('tipo', 'PRE')->whereDoesntHave('derivados')->count(),
            'clientes'       => Contact::customers()->count(),
            'por_pagar'      => (float) Contact::suppliers()->where('balance', '>', 0)->sum('balance'),
            'pagos_vencidos' => (float) Comprobante::compras()->pendientesPago()->whereDate('fecha_vto', '<', today())->sum('saldo'),
            'disponible'     => (float) \App\Models\CuentaFondos::where('activa', true)->sum('saldo'),
            'cheques_cartera' => (float) \App\Models\Cheque::enCartera()->sum('monto'),
            'cheques_propios' => (float) \App\Models\Cheque::propiosPendientes()->sum('monto'),
            'productos'      => Product::where('active', true)->count(),
            'bajo_minimo'    => Product::where('active', true)->where('controla_stock', true)->whereColumn('stock', '<=', 'stock_min')->count(),
            'stock_valorizado' => (float) Product::where('active', true)->where('controla_stock', true)->selectRaw('COALESCE(SUM(stock * cost),0) as v')->value('v'),
            'ordenes_abiertas' => \App\Models\ProductionOrder::whereIn('status', ['pending', 'in_progress'])->count(),
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
Alertas activas:
{$alertas}

Menú del sistema: Inicio (dashboard), Comprobantes, Clientes, Proveedores, Stock, Producción, Fondos, Contable, Estadísticas, Alertas, Configuración (empresa, sucursales, usuarios, roles, puntos de venta y AFIP).
Cómo se hacen las cosas:
- Nueva factura/presupuesto/remito: Comprobantes > Nuevo. Elegís tipo, cliente, cargás ítems (o pegás un mensaje de WhatsApp / subís una foto y la IA arma los ítems) y apretás Emitir. La letra A/B/C sale sola según el cliente.
- Nota de crédito: abrís la factura y tocás "Nota de crédito".
- Cobrar: Clientes > ficha del cliente > "Registrar cobro"; podés combinar efectivo, transferencia, cheque, MercadoPago y elegir qué facturas cancela.
- Acopio: al emitir una factura marcás "Es acopio"; el cliente paga todo y retira de a poco desde su ficha > Acopios > "Registrar retiro" (genera remito).
- Facturar varios presupuestos o remitos juntos: Comprobantes > Facturación por lote.
- Cargar una factura de proveedor: Proveedores > Compras > "Cargar factura" (a mano, con foto/PDF leído por IA, o "Importar de AFIP" con el CSV de Mis Comprobantes). Al registrar impacta cuenta corriente y stock.
- Pagar a un proveedor: Proveedores > ficha > "Registrar pago": transferencia, efectivo, cheque propio, endoso de cheque de tercero o retención; se imputa a facturas y se imprime la orden de pago.
- Stock: cada sucursal tiene depósitos; el stock se ve por depósito y total. Desde Stock: nuevo artículo (con listas de precios 1 a 5), ajustar stock desde la ficha del artículo, "Transferir" entre depósitos, "Inventario" para contar un depósito completo, "Actualizar precios" por porcentaje (por rubro o proveedor), y "Movimientos" para el kardex.
- Producción: en Producción > Fórmulas se define qué insumos lleva cada producto elaborado (el costo se calcula solo). Luego "Orden de producción": elegís fórmula y cantidad; al "Terminar" se descuentan los insumos y entra el producto terminado al depósito con su costo actualizado.
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
            default => "Todavía no tengo conectada la IA (falta ANTHROPIC_API_KEY). Puedo responder sobre ventas, cobros, stock, alertas, sucursales y usuarios con los datos actuales de {$c['empresa']}.",
        };
    }
}
