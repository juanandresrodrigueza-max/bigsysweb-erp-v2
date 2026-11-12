<?php

namespace App\Mcp;

use App\Models\User;

// Fase 28.4: prompts listos (atajos de tareas frecuentes) y recursos de solo lectura que ofrece el servidor MCP.
class Recursos
{
    public static function instrucciones(User $u): string
    {
        $b = $u->business;
        return "Estás conectado al ERP BigSys de {$b?->name} como {$u->name}. Moneda: pesos argentinos. Usá las herramientas de consulta libremente. "
            . 'Las acciones NO se ejecutan solas: devuelven una propuesta con propuesta_id; mostrale al usuario el título y el detalle y llamá a confirmar_accion solo si dice que sí. '
            . 'Si una herramienta devuelve error, explicáselo al usuario en palabras simples. Respondé en español rioplatense. '
            . 'El menú del ERP va en el orden del día: Vender (antes Comprobantes), Cobrar, Entregar, Clientes, Comprar (antes Proveedores), Stock, Plata (antes Fondos), Contable (con Sueldos adentro), Estadísticas, Mi rubro y Configuración; si el usuario usa el nombre viejo, entendelo igual. Configuración va en cuatro grupos: Mi empresa, Ventas y fiscal, Integraciones (tienda, CRM, IA, seguridad) y Datos y cuenta (importar, copias, auditoría, suscripción).'
            . ' Vender tiene el Mostrador y los canales (Pedidos web, Tienda online); Comprar arranca por Qué reponer (lo que está en el mínimo, por proveedor) y termina en Pagos; lo del rubro de la empresa es una sola entrada del menú con sus pantallas adentro.'
            . ' En Plata: Cajas y bancos, Cerrar caja (antes Cierres), Cheques y valores (una sola sección), Tarjetas, Conciliar, Dólares y Vencimientos previstos (antes Previsiones). En Stock, Pesables y cortes (antes Carnicería y pesables) y Simulador de precios (antes Consultor) aparecen sólo para los rubros que los usan. La campana de alertas es de todos.'
            . ' Adentro de cada módulo las secciones van en una columna a la izquierda (en el celular, chips arriba) y cada pantalla tiene una acción principal y un botón Más con el resto; los botones dicen Nuevo/a, Registrar o Guardar.'
            . (\App\Services\Integraciones\CrmService::activo($b) ? ' Esta empresa también usa el CRM BigSys: para lo comercial (qué habló el vendedor, oportunidades, tareas, etapa) usá cliente_en_crm, conversaciones_crm, oportunidades_crm y pendientes_crm; lo contable (saldo, vencido, stock, facturas) está en el ERP.' : '');
    }

    public static function prompts(): array
    {
        return [
            ['name' => 'cierre_del_dia', 'title' => 'Cierre del día', 'description' => 'Resumen de lo que pasó hoy: ventas, cobros, cajas y alertas.', 'arguments' => [],
                'texto' => fn() => 'Haceme el cierre del día: usá resumen_negocio, ventas_periodo (hoy), saldos_cuentas y alertas_activas. Decime cuánto se vendió, cuánto se cobró, cómo quedan las cajas y qué tengo que atender mañana. Corto y en viñetas.'],
            ['name' => 'que_cobrar', 'title' => 'Qué cobrar esta semana', 'description' => 'Clientes a llamar, ordenados por lo vencido.', 'arguments' => [['name' => 'cantidad', 'description' => 'Cuántos clientes (10 por defecto)', 'required' => false]],
                'texto' => fn($a) => 'Usá deudores (solo_vencidos) y armame la lista de los ' . ((int) ($a['cantidad'] ?? 10) ?: 10) . ' clientes que tengo que cobrar esta semana, con monto vencido, días de atraso y teléfono. Si querés, ofrecé mandarles el recordatorio con recordar_deuda (es una acción: pedime confirmación).'],
            ['name' => 'que_reponer', 'title' => 'Qué reponer', 'description' => 'Faltantes y pedido sugerido por proveedor.', 'arguments' => [['name' => 'proveedor', 'description' => 'Un proveedor (opcional)', 'required' => false]],
                'texto' => fn($a) => 'Usá que_reponer' . (! empty($a['proveedor']) ? " para el proveedor {$a['proveedor']}" : '') . ' y agrupá por proveedor lo que hay que comprar, con cantidades y costo. Después ofrecé armar las órdenes de compra con crear_orden_compra (pedime confirmación de cada una).'],
            ['name' => 'resumen_contador', 'title' => 'Resumen para el contador', 'description' => 'Ventas, compras, IVA y resultado del mes anterior.', 'arguments' => [['name' => 'periodo', 'description' => 'mes o mes_anterior (por defecto)', 'required' => false]],
                'texto' => fn($a) => 'Armame un resumen para mandarle al contador del período ' . ($a['periodo'] ?? 'mes_anterior') . ': usá iva_periodo, resultado_periodo, compras_periodo y ventas_periodo. Presentalo prolijo, con totales y una nota de lo que llame la atención.'],
        ];
    }

    public static function prompt(string $nombre, array $args): array
    {
        $p = collect(self::prompts())->firstWhere('name', $nombre);
        if (! $p) throw new \InvalidArgumentException("No existe el prompt {$nombre}.");
        return ['description' => $p['description'], 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => ($p['texto'])($args)]]]];
    }

    public static function recursos(User $u): array
    {
        return [
            ['uri' => 'erp://negocio', 'name' => 'negocio', 'title' => 'Datos del negocio', 'description' => 'Razón social, CUIT, sucursales y módulos activos.', 'mimeType' => 'application/json'],
            ['uri' => 'erp://guia', 'name' => 'guia', 'title' => 'Cómo trabajar con este ERP', 'description' => 'Reglas para la IA: confirmaciones, permisos y modos.', 'mimeType' => 'text/markdown'],
        ];
    }

    public static function leer(string $uri, User $u): array
    {
        $b = $u->business;
        $txt = match ($uri) {
            'erp://negocio' => json_encode(['nombre' => $b?->name, 'cuit' => $b?->cuit, 'condicion_iva' => $b?->condicion_iva, 'sucursales' => $b?->locations()->pluck('name')->all(), 'usuario' => $u->name, 'modulos' => $u->modulosVisibles()], JSON_UNESCAPED_UNICODE),
            'erp://guia' => "# BigSys ERP por MCP\n\n- Las consultas responden al instante.\n- Las acciones devuelven una **propuesta** (propuesta_id, título y detalle) que vence en " . Catalogo::VENCE_MINUTOS . " minutos. Solo se ejecutan con `confirmar_accion` después de que el usuario diga que sí.\n- Cada conexión tiene un modo: solo consultas, acciones, o también acciones delicadas (emitir en ARCA, anular, pagar, transferir). El dueño lo cambia en Configuración → Conexiones IA.\n- Todo queda registrado en la auditoría del sistema.\n",
            default => throw new \InvalidArgumentException("No existe el recurso {$uri}."),
        };
        return ['contents' => [['uri' => $uri, 'mimeType' => $uri === 'erp://guia' ? 'text/markdown' : 'application/json', 'text' => $txt]]];
    }
}
