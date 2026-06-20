<?php
namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'           => 'Gratis',
                'slug'           => 'free',
                'description'    => 'Para empezar sin costo.',
                'price_monthly'  => 0,
                'price_yearly'   => 0,
                'max_users'      => 1,
                'max_locations'  => 1,
                'max_products'   => 50,
                'is_free'        => true,
                'is_active'      => true,
                'features'       => ['ventas', 'stock', 'clientes'],
            ],
            [
                'name'           => 'Starter',
                'slug'           => 'starter',
                'description'    => 'Para negocios pequeños.',
                'price_monthly'  => 4999,
                'price_yearly'   => 49999,
                'max_users'      => 3,
                'max_locations'  => 1,
                'max_products'   => 500,
                'is_free'        => false,
                'is_active'      => true,
                'features'       => ['ventas', 'stock', 'clientes', 'compras', 'reportes', 'afip'],
            ],
            [
                'name'           => 'Pro',
                'slug'           => 'pro',
                'description'    => 'Para negocios en crecimiento.',
                'price_monthly'  => 9999,
                'price_yearly'   => 99999,
                'max_users'      => 10,
                'max_locations'  => 3,
                'max_products'   => 5000,
                'is_free'        => false,
                'is_active'      => true,
                'features'       => ['ventas', 'stock', 'clientes', 'compras', 'reportes', 'afip', 'crm', 'mercadopago', 'tiendanube'],
            ],
            [
                'name'           => 'Enterprise',
                'slug'           => 'enterprise',
                'description'    => 'Sin límites para grandes operaciones.',
                'price_monthly'  => 24999,
                'price_yearly'   => 249999,
                'max_users'      => -1,
                'max_locations'  => -1,
                'max_products'   => -1,
                'is_free'        => false,
                'is_active'      => true,
                'features'       => ['ventas', 'stock', 'clientes', 'compras', 'reportes', 'afip', 'crm', 'mercadopago', 'tiendanube', 'manufactura', 'reservas', 'api', 'soporte_prioritario'],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
