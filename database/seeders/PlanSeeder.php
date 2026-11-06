<?php
namespace Database\Seeders;

use App\Models\Plan;
use App\Models\SistemaConfig;
use Illuminate\Database\Seeder;

// Planes de BigSysWeb ERP (precios de lista en USD + IVA; se cobran en pesos a la cotización del sistema).
// Prueba de 15 días con todo → al vencer pasa sola al plan Gratis. Sueldos se contrata aparte en cualquier plan.
// Negocio incluye el rubro de la empresa (los demás rubros se suman como extra); Empresa, todos.
// El anual cuesta 10 meses. Los planes viejos (starter, pro, enterprise) quedan inactivos: siguen valiendo para quien ya los tiene.
class PlanSeeder extends Seeder
{
    public const EXTRAS = [
        'usuario' => 6, 'sucursal' => 10, 'comprobantes_1000' => 10, 'rubro' => 15,
        'sueldos_base' => 25, 'sueldos_empleados_incluidos' => 20, 'sueldos_empleado_extra' => 1,
        'empresa_adicional_pct' => 50,
    ];

    public function run(): void
    {
        $core = ['comprobantes', 'clientes', 'proveedores', 'stock', 'fondos', 'estadisticas', 'alertas', 'configuracion', 'dashboard'];
        $rubros = ['retail', 'minimarket', 'gastronomia', 'servicios', 'hoteleria', 'agenda', 'obras', 'produccion'];
        $plans = [
            ['slug' => 'prueba', 'name' => 'Prueba', 'description' => '15 días con todo activado, sin tarjeta. Al terminar seguís gratis o elegís un plan.', 'precio_usd' => 0,
                'max_users' => 5, 'max_locations' => 3, 'max_products' => -1, 'max_facturas_mes' => 500, 'is_free' => false, 'features' => ['*'], 'orden' => 0],
            ['slug' => 'free', 'name' => 'Gratis', 'description' => 'Para siempre: facturá con ARCA y controlá lo básico.', 'precio_usd' => 0,
                'max_users' => 1, 'max_locations' => 1, 'max_products' => 100, 'max_facturas_mes' => 10, 'is_free' => true, 'features' => ['comprobantes', 'clientes', 'stock'], 'orden' => 1],
            ['slug' => 'inicial', 'name' => 'Inicial', 'description' => 'Para el comercio que arranca: vender, facturar, cobrar y controlar el stock.', 'precio_usd' => 39,
                'max_users' => 3, 'max_locations' => 1, 'max_products' => -1, 'max_facturas_mes' => 500, 'is_free' => false, 'features' => array_merge($core, ['retail', 'minimarket']), 'orden' => 2],
            ['slug' => 'negocio', 'name' => 'Negocio', 'description' => 'El más elegido: contabilidad, conciliación, marketplaces, vendedores y reparto.', 'precio_usd' => 99,
                'max_users' => 10, 'max_locations' => 3, 'max_products' => -1, 'max_facturas_mes' => 3000, 'is_free' => false, 'features' => array_merge($core, ['contable'], $rubros), 'orden' => 3, 'recomendado' => true],
            ['slug' => 'empresa', 'name' => 'Empresa', 'description' => 'Para operaciones grandes: todos los rubros, sucursales ilimitadas, API y soporte prioritario.', 'precio_usd' => 199,
                'max_users' => 25, 'max_locations' => -1, 'max_products' => -1, 'max_facturas_mes' => 10000, 'is_free' => false, 'features' => array_merge($core, ['contable'], $rubros), 'orden' => 4],
        ];
        SistemaConfig::get('cotizacion_usd') ?: SistemaConfig::set('cotizacion_usd', 1515);
        $cot = (float) SistemaConfig::get('cotizacion_usd');
        foreach ($plans as $p) {
            $p += ['recomendado' => false];
            $p['precio_usd_anual'] = $p['precio_usd'] * 10;
            $p['price_monthly'] = round($p['precio_usd'] * $cot, -2);
            $p['price_yearly'] = round($p['precio_usd_anual'] * $cot, -2);
            $p['extras'] = $p['precio_usd'] > 0 ? self::EXTRAS : null;
            $p['is_active'] = true;
            Plan::updateOrCreate(['slug' => $p['slug']], $p);
        }
        Plan::whereIn('slug', ['starter', 'pro', 'enterprise'])->update(['is_active' => false]);
    }
}
