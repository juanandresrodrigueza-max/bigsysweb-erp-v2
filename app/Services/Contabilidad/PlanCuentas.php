<?php

namespace App\Services\Contabilidad;

use App\Models\Business;
use App\Models\CuentaContable;
use App\Models\ExpenseCategory;

// Plan de cuentas base para una PyME argentina. Se crea al dar de alta la empresa; el contador lo puede ampliar.
class PlanCuentas
{
    // [codigo, nombre, tipo, clave, imputable]
    public const BASE = [
        ['1', 'ACTIVO', 'activo', null, false],
        ['1.1', 'Caja y bancos', 'activo', null, false],
        ['1.1.01', 'Caja', 'activo', 'caja', true],
        ['1.1.02', 'Bancos cuenta corriente', 'activo', 'banco', true],
        ['1.1.03', 'Billeteras virtuales', 'activo', 'billetera', true],
        ['1.1.04', 'Cheques de terceros en cartera', 'activo', 'cheques_cartera', true],
        ['1.1.05', 'Fondos en tránsito', 'activo', 'transito', true],
        ['1.2', 'Créditos por ventas', 'activo', null, false],
        ['1.2.01', 'Deudores por ventas', 'activo', 'deudores', true],
        ['1.2.02', 'Tarjetas y billeteras a cobrar', 'activo', 'tarjetas_cobrar', true],
        ['1.3', 'Créditos fiscales', 'activo', null, false],
        ['1.3.01', 'IVA crédito fiscal', 'activo', 'iva_cf', true],
        ['1.3.02', 'Retenciones y percepciones sufridas', 'activo', 'ret_sufridas', true],
        ['1.4', 'Bienes de cambio', 'activo', null, false],
        ['1.4.01', 'Mercaderías', 'activo', 'mercaderias', true],
        ['2', 'PASIVO', 'pasivo', null, false],
        ['2.1', 'Deudas comerciales', 'pasivo', null, false],
        ['2.1.01', 'Proveedores', 'pasivo', 'proveedores', true],
        ['2.1.02', 'Cheques propios a pagar', 'pasivo', 'cheques_propios', true],
        ['2.1.03', 'Anticipos de clientes', 'pasivo', 'anticipos_clientes', true],
        ['2.2', 'Deudas fiscales', 'pasivo', null, false],
        ['2.2.01', 'IVA débito fiscal', 'pasivo', 'iva_df', true],
        ['2.2.02', 'Percepciones cobradas a depositar', 'pasivo', 'percepciones_cobradas', true],
        ['2.2.03', 'Retenciones practicadas a depositar', 'pasivo', 'ret_practicadas', true],
        ['2.3', 'Deudas sociales y otras', 'pasivo', null, false],
        ['2.3.01', 'Sueldos y cargas sociales a pagar', 'pasivo', 'sueldos_pagar', true],
        ['3', 'PATRIMONIO NETO', 'patrimonio', null, false],
        ['3.1.01', 'Capital', 'patrimonio', 'capital', true],
        ['3.1.02', 'Resultados acumulados', 'patrimonio', 'resultados', true],
        ['4', 'INGRESOS', 'ingreso', null, false],
        ['4.1', 'Ingresos por ventas', 'ingreso', null, false],
        ['4.1.01', 'Ventas de mercaderías', 'ingreso', 'ventas', true],
        ['4.1.02', 'Ventas de servicios', 'ingreso', 'ventas_servicios', true],
        ['4.2', 'Otros ingresos', 'ingreso', null, false],
        ['4.2.01', 'Otros ingresos', 'ingreso', 'otros_ingresos', true],
        ['4.2.02', 'Sobrantes de caja', 'ingreso', 'sobrante_caja', true],
        ['5', 'EGRESOS', 'egreso', null, false],
        ['5.1', 'Costo de ventas', 'egreso', null, false],
        ['5.1.01', 'Costo de mercaderías vendidas', 'egreso', 'cmv', true],
        ['5.2', 'Gastos de operación', 'egreso', null, false],
        ['5.2.01', 'Gastos generales', 'egreso', 'gastos', true],
        ['5.2.02', 'Gastos y comisiones bancarias', 'egreso', 'gastos_bancarios', true],
        ['5.2.03', 'Faltantes de caja', 'egreso', 'faltante_caja', true],
        ['5.2.04', 'Impuestos y tasas', 'egreso', 'impuestos', true],
        ['5.2.05', 'Compras no inventariables', 'egreso', 'compras_gastos', true],
    ];

    // Crea el plan para una empresa (no duplica si ya existe) y una subcuenta por categoría de gasto.
    public static function crear(Business $b): void
    {
        $ids = [];
        foreach (self::BASE as [$codigo, $nombre, $tipo, $clave, $imputable]) {
            $parentCodigo = str_contains($codigo, '.') ? substr($codigo, 0, strrpos($codigo, '.')) : null;
            $c = CuentaContable::withoutGlobalScopes()->firstOrCreate(
                ['business_id' => $b->id, 'codigo' => $codigo],
                ['nombre' => $nombre, 'tipo' => $tipo, 'clave' => $clave, 'imputable' => $imputable, 'parent_id' => $parentCodigo ? ($ids[$parentCodigo] ?? null) : null]
            );
            $ids[$codigo] = $c->id;
        }
        foreach (ExpenseCategory::withoutGlobalScopes()->where('business_id', $b->id)->get() as $cat) {
            self::cuentaParaCategoria($b->id, $cat, $ids['5.2'] ?? null);
        }
    }

    // Subcuenta de gasto para una categoría (5.2.1xx). Se crea al vuelo si no existe.
    public static function cuentaParaCategoria(int $businessId, ExpenseCategory $cat, ?int $parentId = null): CuentaContable
    {
        $clave = "gasto_cat_{$cat->id}";
        $existente = CuentaContable::withoutGlobalScopes()->where('business_id', $businessId)->where('clave', $clave)->first();
        if ($existente) {
            return $existente;
        }
        $parentId ??= CuentaContable::withoutGlobalScopes()->where('business_id', $businessId)->where('codigo', '5.2')->value('id');
        $n = CuentaContable::withoutGlobalScopes()->where('business_id', $businessId)->where('codigo', 'like', '5.2.1%')->count() + 1;
        return CuentaContable::withoutGlobalScopes()->create(['business_id' => $businessId, 'parent_id' => $parentId, 'codigo' => sprintf('5.2.1%02d', $n), 'nombre' => "Gastos: {$cat->name}", 'tipo' => 'egreso', 'clave' => $clave, 'imputable' => true]);
    }
}
