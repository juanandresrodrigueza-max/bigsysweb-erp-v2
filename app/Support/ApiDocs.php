<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Documentación de la API pública generada desde las rutas reales (routes/api.php): OpenAPI 3 + página legible.
// Los textos por grupo y por acción salen de acá; lo que no está descripto se documenta igual, con nombre genérico.
class ApiDocs
{
    public const GRUPOS = [
        'auth' => ['Autenticación', 'Registro, login y cierre de sesión. El login devuelve el token Bearer que se usa en todo lo demás.'],
        'exportar' => ['Exportar tablas', 'Mandar las tablas del ERP a otra plataforma (BI, contador, e-commerce, otro sistema): catálogo de tablas y filas paginadas en JSON o CSV, con filtros por fecha y sincronización incremental (actualizado_desde).'],
        'business' => ['Empresa', 'Datos de la empresa del usuario autenticado.'],
        'contacts' => ['Contactos', 'Clientes y proveedores.'],
        'customers' => ['Clientes', 'Alta, consulta y edición de clientes.'],
        'products' => ['Artículos', 'Catálogo con precios, stock e IVA.'],
        'sales' => ['Ventas', 'Comprobantes de venta (facturas, presupuestos, remitos).'],
        'purchases' => ['Compras', 'Comprobantes de compra.'],
        'expenses' => ['Gastos', 'Gastos por categoría.'],
        'stock-movements' => ['Movimientos de stock', 'Entradas, salidas y ajustes.'],
        'cash-registers' => ['Cajas', 'Apertura y cierre de turnos de caja.'],
        'invoices' => ['Factura electrónica', 'Vista previa y emisión con CAE de ARCA; carga del certificado.'],
        'reports' => ['Reportes', 'Resúmenes de ventas, artículos más vendidos, alertas de stock y clientes.'],
        'mercadopago' => ['Mercado Pago', 'Credenciales, preferencias de pago y estado de un pago. El webhook es público.'],
        'tiendanube' => ['Tiendanube', 'Credenciales y sincronización de pedidos y stock.'],
        'crm' => ['CRM', 'Oportunidades, pipeline y actividades.'],
        'recipes' => ['Recetas', 'Fórmulas de producción.'],
        'production-orders' => ['Órdenes de producción', 'Alta, inicio, cierre y cancelación.'],
        'bookings' => ['Reservas', 'Calendario y reservas.'],
        'canales' => ['Canales de venta', 'Entrada de pedidos de marketplaces y delivery por token de canal (público).'],
        'whatsapp' => ['WhatsApp', 'Webhook de WhatsApp Cloud API por empresa (público).'],
        'webhooks' => ['Webhooks entrantes', 'Notificaciones de Mercado Pago (público).'],
    ];

    private const ACCIONES = ['index' => 'Listar', 'store' => 'Crear', 'show' => 'Ver', 'update' => 'Actualizar', 'destroy' => 'Eliminar', 'login' => 'Iniciar sesión', 'logout' => 'Cerrar sesión', 'register' => 'Registrar empresa y usuario', 'me' => 'Usuario autenticado', 'open' => 'Abrir turno', 'close' => 'Cerrar turno', 'preview' => 'Vista previa de la factura', 'issue' => 'Emitir con CAE', 'uploadCertificate' => 'Subir certificado ARCA', 'salesSummary' => 'Resumen de ventas', 'topProducts' => 'Artículos más vendidos', 'stockAlerts' => 'Alertas de stock', 'customerStats' => 'Estadísticas de clientes', 'configure' => 'Guardar credenciales', 'createPreference' => 'Crear preferencia de pago', 'paymentStatus' => 'Estado de un pago', 'webhook' => 'Webhook (notificación entrante)', 'syncOrders' => 'Sincronizar pedidos', 'syncStock' => 'Sincronizar stock', 'pipeline' => 'Pipeline', 'addActivity' => 'Agregar actividad', 'completeActivity' => 'Completar actividad', 'start' => 'Iniciar', 'complete' => 'Completar', 'cancel' => 'Cancelar', 'calendar' => 'Calendario', 'entrada' => 'Recibir pedido', 'tabla' => 'Exportar una tabla (JSON o CSV, paginado, incremental)', 'verificar' => 'Verificación del webhook', 'entrante' => 'Mensaje entrante'];

    public static function endpoints(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            if (! str_starts_with($uri, 'api/') || str_starts_with($uri, 'api/docs') || $uri === 'api/openapi.json') continue;
            $seg = explode('/', substr($uri, 4));
            $grupo = $seg[0] === 'webhooks' ? 'webhooks' : $seg[0];
            $accion = Str::afterLast($r->getActionName(), '@');
            $publico = ! in_array('auth:sanctum', $r->gatherMiddleware(), true);
            preg_match_all('/\{(\w+)\??\}/', $uri, $pm);
            foreach (array_diff($r->methods(), ['HEAD', 'OPTIONS']) as $m) {
                $out[] = ['metodo' => $m, 'ruta' => '/' . $uri, 'grupo' => $grupo, 'grupo_label' => self::GRUPOS[$grupo][0] ?? Str::headline($grupo), 'resumen' => self::ACCIONES[$accion] ?? Str::headline($accion), 'operacion' => $accion, 'publico' => $publico, 'parametros' => $pm[1], 'throttle' => collect($r->gatherMiddleware())->first(fn($mw) => str_starts_with($mw, 'throttle:'))];
            }
        }
        usort($out, fn($a, $b) => [$a['grupo'], $a['ruta'], $a['metodo']] <=> [$b['grupo'], $b['ruta'], $b['metodo']]);
        return $out;
    }

    public static function grupos(): array
    {
        $por = [];
        foreach (self::endpoints() as $e) $por[$e['grupo']]['endpoints'][] = $e;
        foreach ($por as $k => &$g) { $g['key'] = $k; $g['label'] = self::GRUPOS[$k][0] ?? Str::headline($k); $g['descripcion'] = self::GRUPOS[$k][1] ?? ''; }
        return array_values($por);
    }

    public static function openapi(): array
    {
        $paths = [];
        foreach (self::endpoints() as $e) {
            $ruta = preg_replace('/\{(\w+)\?\}/', '{$1}', $e['ruta']);
            $op = ['tags' => [$e['grupo_label']], 'summary' => $e['resumen'], 'operationId' => Str::camel($e['grupo'] . ' ' . $e['operacion'] . ' ' . strtolower($e['metodo'])),
                'parameters' => array_map(fn($p) => ['name' => $p, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], $e['parametros']),
                'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Sin token o token inválido'], '422' => ['description' => 'Datos inválidos (errores por campo en `errors`)']]];
            if (! $e['publico']) $op['security'] = [['bearerAuth' => []]];
            if (in_array($e['metodo'], ['POST', 'PUT', 'PATCH'], true)) $op['requestBody'] = ['required' => false, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]];
            $paths[$ruta][strtolower($e['metodo'])] = $op;
        }
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'BigSysWeb API', 'version' => '2.0', 'description' => 'API del ERP BigSysWeb. Autenticación con token Bearer (Sanctum). Todas las respuestas son JSON; las fechas van en formato YYYY-MM-DD y los importes en pesos con dos decimales.'],
            'servers' => [['url' => url('/api')]],
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
            'tags' => array_map(fn($g) => ['name' => $g[0], 'description' => $g[1]], array_values(self::GRUPOS)),
            'paths' => $paths,
        ];
    }
}
