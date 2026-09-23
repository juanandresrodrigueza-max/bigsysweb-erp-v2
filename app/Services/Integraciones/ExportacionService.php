<?php

namespace App\Services\Integraciones;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Exportación de tablas para otras plataformas (BI, contador, e-commerce, otro ERP): filas planas, paginadas,
// filtrables por fecha y por "actualizado desde" para sincronizaciones incrementales. Siempre acotado a la empresa del token.
class ExportacionService
{
    // clave => [tabla SQL, etiqueta, descripción, columna de fecha (para desde/hasta), cómo se acota a la empresa]
    public const TABLAS = [
        'clientes'            => ['contacts', 'Clientes', 'Clientes con CUIT, condición de IVA, saldo de cuenta corriente y datos de contacto.', 'created_at', ['business', 'type', 'customer']],
        'proveedores'         => ['contacts', 'Proveedores', 'Proveedores con CUIT y saldo.', 'created_at', ['business', 'type', 'supplier']],
        'articulos'           => ['products', 'Artículos', 'Catálogo con precio, costo, stock, IVA, rubro y código de barras.', 'created_at', ['business']],
        'rubros'              => ['rubros', 'Rubros', 'Rubros y subrubros de artículos.', 'created_at', ['business']],
        'comprobantes_venta'  => ['comprobantes', 'Comprobantes de venta', 'Facturas, notas de crédito/débito, remitos y presupuestos emitidos, con CAE, totales y saldo.', 'fecha', ['business', 'direccion', 'venta']],
        'comprobantes_compra' => ['comprobantes', 'Comprobantes de compra', 'Facturas de proveedores registradas.', 'fecha', ['business', 'direccion', 'compra']],
        'comprobante_items'   => ['comprobante_items', 'Ítems de comprobantes', 'Renglones de cada comprobante (artículo, cantidad, precio, IVA). Se une por comprobante_id.', null, ['padre', 'comprobantes', 'comprobante_id']],
        'cobros'              => ['cobros', 'Cobros (recibos)', 'Recibos de clientes con total, a cuenta y cotización.', 'fecha', ['business']],
        'cobro_medios'        => ['cobro_medios', 'Medios de cobro', 'Efectivo, transferencia, cheque, tarjeta… de cada recibo. Se une por cobro_id.', null, ['padre', 'cobros', 'cobro_id']],
        'cobro_imputaciones'  => ['cobro_imputaciones', 'Imputaciones de cobros', 'Qué factura canceló cada recibo y la diferencia de cambio. Se une por cobro_id y comprobante_id.', null, ['padre', 'cobros', 'cobro_id']],
        'pagos'               => ['pagos', 'Pagos (órdenes de pago)', 'Órdenes de pago a proveedores.', 'fecha', ['business']],
        'pago_medios'         => ['pago_medios', 'Medios de pago', 'Medios de cada orden de pago. Se une por pago_id.', null, ['padre', 'pagos', 'pago_id']],
        'pago_imputaciones'   => ['pago_imputaciones', 'Imputaciones de pagos', 'Qué factura de compra canceló cada pago.', null, ['padre', 'pagos', 'pago_id']],
        'cuenta_corriente'    => ['cuenta_corriente', 'Cuenta corriente', 'Movimientos de cuenta corriente de clientes y proveedores (debe/haber).', 'fecha', ['business']],
        'gastos'              => ['expenses', 'Gastos', 'Gastos por categoría.', 'expense_date', ['business']],
        'categorias_gastos'   => ['expense_categories', 'Categorías de gastos', 'Categorías con tipo de costo.', 'created_at', ['business']],
        'cuentas_fondos'      => ['cuentas_fondos', 'Cuentas de fondos', 'Cajas, bancos y billeteras con saldo y moneda.', 'created_at', ['business']],
        'movimientos_fondos'  => ['movimientos_fondos', 'Movimientos de fondos', 'Ingresos y egresos de cada caja/banco con su origen.', 'fecha', ['business']],
        'movimientos_stock'   => ['stock_movements', 'Movimientos de stock', 'Entradas, salidas y ajustes por artículo y depósito.', 'created_at', ['business']],
        'depositos'           => ['depositos', 'Depósitos', 'Depósitos por sucursal.', 'created_at', ['business']],
        'sucursales'          => ['business_locations', 'Sucursales', 'Sucursales de la empresa.', 'created_at', ['business']],
        'plan_cuentas'        => ['cuentas_contables', 'Plan de cuentas', 'Cuentas contables con código, tipo y clave del sistema.', 'created_at', ['business']],
        'asientos'            => ['asientos', 'Asientos contables', 'Asientos con origen (venta, compra, cobro, pago, fondos…).', 'fecha', ['business']],
        'asiento_lineas'      => ['asiento_lineas', 'Líneas de asientos', 'Debe y haber por cuenta de cada asiento. Se une por asiento_id.', null, ['padre', 'asientos', 'asiento_id']],
        'cotizaciones'        => ['cotizaciones', 'Cotizaciones', 'Cotización del dólar por día (oficial, MEP, blue…).', 'fecha', ['business_o_global']],
    ];

    // Columnas que nunca se exportan (secretos, rutas internas, blobs).
    private const OCULTAS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'portal_token', 'public_token', 'afip_respuesta', 'pdf_path', 'deleted_at', 'datos', 'prices', 'margenes', 'imagen'];

    public const MAX_POR_PAGINA = 500;

    public static function catalogo(): array
    {
        $out = [];
        foreach (self::TABLAS as $k => [$tabla, $label, $desc, $fecha, $scope]) {
            $out[] = ['tabla' => $k, 'nombre' => $label, 'descripcion' => $desc, 'columnas' => self::columnas($tabla), 'filtro_fecha' => $fecha, 'incremental' => Schema::hasColumn($tabla, 'updated_at') ? 'actualizado_desde' : ($fecha ? 'desde/hasta' : 'id_desde'),
                'url' => url("/api/exportar/{$k}")];
        }
        return $out;
    }

    public static function columnas(string $tabla): array
    {
        return array_values(array_diff(Schema::getColumnListing($tabla), self::OCULTAS));
    }

    // Consulta acotada a la empresa con los filtros pedidos. Devuelve el builder ya ordenado por id.
    public static function consulta(string $clave, int $businessId, array $f = []): Builder
    {
        abort_unless(isset(self::TABLAS[$clave]), 404, "La tabla '{$clave}' no existe. Consultá GET /api/exportar para ver las disponibles.");
        [$tabla, , , $fecha, $scope] = self::TABLAS[$clave];
        $q = DB::table($tabla);
        match ($scope[0]) {
            'business' => $q->where("{$tabla}.business_id", $businessId)->when(isset($scope[1]), fn($q) => $q->where("{$tabla}.{$scope[1]}", $scope[2])),
            'business_o_global' => $q->where(fn($w) => $w->where("{$tabla}.business_id", $businessId)->orWhereNull("{$tabla}.business_id")),
            'padre' => $q->whereIn("{$tabla}.{$scope[2]}", DB::table($scope[1])->where('business_id', $businessId)->select('id')),
        };
        if (Schema::hasColumn($tabla, 'deleted_at')) $q->whereNull("{$tabla}.deleted_at");
        if ($fecha && ! empty($f['desde'])) $q->whereDate("{$tabla}.{$fecha}", '>=', $f['desde']);
        if ($fecha && ! empty($f['hasta'])) $q->whereDate("{$tabla}.{$fecha}", '<=', $f['hasta']);
        if (! empty($f['actualizado_desde']) && Schema::hasColumn($tabla, 'updated_at')) $q->where("{$tabla}.updated_at", '>=', $f['actualizado_desde']);
        if (! empty($f['id_desde'])) $q->where("{$tabla}.id", '>', (int) $f['id_desde']);
        if (! empty($f['sucursal_id']) && Schema::hasColumn($tabla, 'business_location_id')) $q->where("{$tabla}.business_location_id", (int) $f['sucursal_id']);
        return $q->select(array_map(fn($c) => "{$tabla}.{$c}", self::columnas($tabla)))->orderBy("{$tabla}.id");
    }

    public static function csv(iterable $filas, array $columnas): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $columnas, ';');
        foreach ($filas as $r) fputcsv($h, array_map(fn($v) => is_bool($v) ? (int) $v : $v, (array) $r), ';');
        rewind($h);
        return "\xEF\xBB\xBF" . stream_get_contents($h);
    }
}
