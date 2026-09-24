<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\PagoSuscripcion;
use App\Models\Plan;
use App\Models\SistemaConfig;
use App\Models\User;
use App\Services\Suscripciones\SuscripcionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

// Superadmin de BigSys y varias empresas clientes en distintos momentos del ciclo (prueba, activa, vencida, suspendida, baja).
class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $super = User::firstOrCreate(['email' => 'super@bigsys.com.ar'], ['name' => 'Soporte BigSys', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
        SistemaConfig::set('transferencia_cbu', '0170099220000012345678');

        if (Business::withTrashed()->where('slug', 'panaderia-la-espiga')->exists()) {
            return;
        }

        Auth::login($super);
        $svc = app(SuscripcionService::class);
        $starter = Plan::where('slug', 'starter')->first();
        $pro = Plan::where('slug', 'pro')->first();
        $free = Plan::where('slug', 'free')->first();

        // Demo (corralón) ya existe: le damos historial de pagos.
        $demo = Business::where('slug', 'demo')->first();
        if ($demo) {
            foreach ([3, 2, 1] as $m) {
                PagoSuscripcion::create(['business_id' => $demo->id, 'subscription_id' => $demo->subscription?->id, 'plan_id' => $pro->id, 'fecha' => today()->subMonths($m)->subDays(7), 'ciclo' => 'monthly', 'periodo_desde' => today()->subMonths($m)->subDays(7), 'periodo_hasta' => today()->subMonths($m - 1)->subDays(7), 'monto' => $pro->price_monthly, 'medio' => $m === 1 ? 'mercadopago' : 'transferencia', 'estado' => 'aprobado', 'aprobado_en' => today()->subMonths($m)->subDays(7), 'external_id' => $m === 1 ? '118822331' : null, 'user_id' => $m === 1 ? null : $super->id]);
            }
        }

        // En prueba, le quedan 3 días.
        $espiga = $svc->crearEmpresa(['name' => 'Panadería La Espiga', 'cuit' => '27-28111222-4', 'vertical' => 'gastronomia', 'condicion_iva' => 'Monotributista', 'city' => 'Villa María', 'province' => 'Córdoba', 'dueno_nombre' => 'Mariela Sosa', 'dueno_email' => 'mariela@laespiga.com.ar', 'dueno_password' => 'password', 'dueno_mobile' => '353 555-2211', 'plan_id' => $starter->id, 'modo' => 'trial', 'dias_prueba' => 14], $super);
        $espiga->subscription->update(['starts_at' => now()->subDays(11), 'trial_ends_at' => now()->addDays(3)->endOfDay(), 'ends_at' => now()->addDays(3)->endOfDay()]);
        $espiga->forceFill(['created_at' => now()->subDays(11)])->save();
        $this->demoGastronomia($espiga, $pro);

        // Activa, plan anual pago por MercadoPago.
        $norte = $svc->crearEmpresa(['name' => 'Ferretería Norte', 'cuit' => '30-70998877-1', 'vertical' => 'retail', 'city' => 'Rosario', 'province' => 'Santa Fe', 'dueno_nombre' => 'Diego Farías', 'dueno_email' => 'diego@ferreterianorte.com', 'dueno_password' => 'password', 'plan_id' => $pro->id, 'modo' => 'activa', 'ciclo' => 'yearly', 'medio' => 'mercadopago', 'ends_at' => today()->addMonths(9)->toDateString()], $super);
        PagoSuscripcion::create(['business_id' => $norte->id, 'subscription_id' => $norte->subscription->id, 'plan_id' => $pro->id, 'fecha' => today()->subMonths(3), 'ciclo' => 'yearly', 'periodo_desde' => today()->subMonths(3), 'periodo_hasta' => today()->addMonths(9), 'monto' => $pro->price_yearly, 'medio' => 'mercadopago', 'estado' => 'aprobado', 'aprobado_en' => today()->subMonths(3), 'external_id' => '117700221']);
        $norte->forceFill(['created_at' => now()->subMonths(3)])->save();

        // Vencida, en período de gracia (2 días para renovar) y con una transferencia informada pendiente de confirmar.
        $kiosco = $svc->crearEmpresa(['name' => 'Kiosco 24 Horas', 'cuit' => '20-33444555-9', 'vertical' => 'minimarket', 'city' => 'Córdoba', 'province' => 'Córdoba', 'dueno_nombre' => 'Rubén Paz', 'dueno_email' => 'ruben@kiosco24.com.ar', 'dueno_password' => 'password', 'plan_id' => $starter->id, 'modo' => 'activa', 'ciclo' => 'monthly', 'medio' => 'transferencia'], $super);
        $kiosco->subscription->update(['status' => 'grace', 'starts_at' => now()->subMonths(4), 'ends_at' => now()->subDays(5), 'grace_ends_at' => now()->addDays(2)->endOfDay()]);
        $kiosco->forceFill(['created_at' => now()->subMonths(4)])->save();
        PagoSuscripcion::create(['business_id' => $kiosco->id, 'subscription_id' => $kiosco->subscription->id, 'plan_id' => $starter->id, 'fecha' => today()->subDays(1), 'ciclo' => 'monthly', 'monto' => $starter->price_monthly, 'medio' => 'transferencia', 'estado' => 'pendiente', 'referencia' => 'Transf. Banco Macro 22/09', 'user_id' => $kiosco->owner_id]);

        // Suspendida por falta de pago.
        $taller = $svc->crearEmpresa(['name' => 'Taller Ruiz Hnos.', 'cuit' => '30-61222333-7', 'vertical' => 'servicios', 'city' => 'Mendoza', 'province' => 'Mendoza', 'dueno_nombre' => 'Esteban Ruiz', 'dueno_email' => 'esteban@tallerruiz.com', 'dueno_password' => 'password', 'plan_id' => $starter->id, 'modo' => 'activa', 'ciclo' => 'monthly', 'medio' => 'efectivo'], $super);
        $taller->subscription->update(['status' => 'suspended', 'starts_at' => now()->subMonths(6), 'ends_at' => now()->subDays(20), 'grace_ends_at' => now()->subDays(13)]);
        $taller->forceFill(['suspended_at' => now()->subDays(13), 'suspension_motivo' => 'Falta de pago', 'created_at' => now()->subMonths(6)])->save();

        // Plan gratis, activa.
        $free && $svc->crearEmpresa(['name' => 'Estudio Contable Vera', 'vertical' => 'servicios', 'city' => 'Salta', 'province' => 'Salta', 'dueno_nombre' => 'Lucía Vera', 'dueno_email' => 'lucia@estudiovera.com.ar', 'dueno_password' => 'password', 'plan_id' => $free->id, 'modo' => 'activa', 'ciclo' => 'monthly', 'medio' => 'cortesia', 'ends_at' => today()->addYears(5)->toDateString()], $super);

        // Dada de baja.
        $baja = $svc->crearEmpresa(['name' => 'Bar El Faro', 'vertical' => 'gastronomia', 'city' => 'Mar del Plata', 'province' => 'Buenos Aires', 'dueno_nombre' => 'Pablo Ferro', 'dueno_email' => 'pablo@barelfaro.com', 'dueno_password' => 'password', 'plan_id' => $starter->id, 'modo' => 'trial'], $super);
        $svc->darDeBaja($baja, 'Cerró el local');

        Auth::logout();
    }

    // Panadería con salón: carta, mesas, comandas abiertas y una cerrada, para probar gastronomía.
    private function demoGastronomia(Business $b, Plan $pro): void
    {
        $b->subscription->update(['plan_id' => $pro->id]);
        $dueno = $b->owner;
        Auth::login($dueno);
        $b->refresh();
        $loc = $b->locations()->first();
        $rubros = collect(['Cafetería' => '#a42785', 'Panadería' => '#c77d00', 'Sandwiches' => '#4f3089', 'Bebidas' => '#1f9d5b', 'Postres' => '#e4003f'])->mapWithKeys(fn($col, $n) => [$n => \App\Models\Rubro::create(['business_id' => $b->id, 'nombre' => $n, 'color' => $col])]);
        $carta = [
            ['Café con leche', 'CAF01', 1800, 'Cafetería', true, true], ['Cortado', 'CAF02', 1500, 'Cafetería', true, true], ['Café doble', 'CAF03', 2200, 'Cafetería', true, false], ['Té', 'CAF04', 1300, 'Cafetería', true, false],
            ['Medialuna', 'PAN01', 500, 'Panadería', false, true], ['Tostado de jamón y queso', 'SAN01', 3800, 'Sandwiches', true, true], ['Sandwich de milanesa', 'SAN02', 6500, 'Sandwiches', true, false], ['Tarta de verdura', 'SAN03', 4200, 'Sandwiches', true, false],
            ['Agua 500 ml', 'BEB01', 1200, 'Bebidas', false, true], ['Gaseosa 500 ml', 'BEB02', 1800, 'Bebidas', false, true], ['Jugo de naranja', 'BEB03', 2500, 'Bebidas', true, false], ['Cerveza artesanal', 'BEB04', 3500, 'Bebidas', false, false],
            ['Chocotorta', 'POS01', 3900, 'Postres', true, false], ['Flan con dulce de leche', 'POS02', 3200, 'Postres', true, false],
        ];
        $prod = [];
        foreach ($carta as [$n, $sku, $precio, $rubro, $cocina, $fav]) {
            $prod[$sku] = \App\Models\Product::create(['business_id' => $b->id, 'business_location_id' => $loc->id, 'rubro_id' => $rubros[$rubro]->id, 'name' => $n, 'sku' => $sku, 'tipo' => 'servicio', 'price' => $precio, 'cost' => round($precio * 0.4), 'iva' => 21, 'unit' => 'un', 'active' => true, 'controla_stock' => false, 'va_cocina' => $cocina, 'favorito_pos' => $fav]);
        }
        $mesas = [];
        foreach ([['1', 'Salón', 4], ['2', 'Salón', 4], ['3', 'Salón', 2], ['4', 'Salón', 6], ['5', 'Salón', 4], ['6', 'Salón', 2], ['T1', 'Terraza', 4], ['T2', 'Terraza', 4], ['T3', 'Terraza', 6], ['B1', 'Barra', 2], ['B2', 'Barra', 2]] as $i => [$n, $sec, $cap]) {
            $mesas[$n] = \App\Models\Mesa::create(['business_id' => $b->id, 'business_location_id' => $loc->id, 'nombre' => $n, 'sector' => $sec, 'capacidad' => $cap, 'orden' => $i]);
        }
        $svc = app(\App\Services\Gastronomia\ComandaService::class);
        $fondos = app(\App\Services\Fondos\FondosService::class);
        $fondos->abrirTurno(\App\Models\CuentaFondos::where('business_id', $b->id)->where('tipo', 'caja')->first(), 20000);

        // Mesa 2: pidió, se envió a cocina, parte está lista
        $c1 = $svc->abrir(['mesa_id' => $mesas['2']->id, 'cubiertos' => 3]);
        $svc->agregar($c1, ['product_id' => $prod['CAF01']->id, 'cantidad' => 2]); $svc->agregar($c1, ['product_id' => $prod['PAN01']->id, 'cantidad' => 4]); $svc->agregar($c1, ['product_id' => $prod['SAN01']->id, 'cantidad' => 1, 'notas' => 'sin manteca']);
        $svc->enviarCocina($c1); $svc->estadoItem($c1->items()->where('product_id', $prod['CAF01']->id)->first(), 'listo');
        $c1->update(['abierta_en' => now()->subMinutes(25)]);
        // Mesa T1: recién sentados, sin enviar
        $c2 = $svc->abrir(['mesa_id' => $mesas['T1']->id, 'cubiertos' => 2]);
        $svc->agregar($c2, ['product_id' => $prod['SAN02']->id, 'cantidad' => 2]); $svc->agregar($c2, ['product_id' => $prod['BEB02']->id, 'cantidad' => 2]);
        $c2->update(['abierta_en' => now()->subMinutes(6)]);
        // Mesa 4: pidió la cuenta
        $c3 = $svc->abrir(['mesa_id' => $mesas['4']->id, 'cubiertos' => 4]);
        foreach ([['CAF02', 4], ['POS01', 2], ['SAN03', 2], ['BEB01', 2]] as [$sku, $q]) $svc->agregar($c3, ['product_id' => $prod[$sku]->id, 'cantidad' => $q]);
        $svc->enviarCocina($c3); foreach ($c3->items as $it) $svc->estadoItem($it, 'entregado'); $svc->pedirCuenta($c3);
        $c3->update(['abierta_en' => now()->subMinutes(55)]);
        // Delivery en curso
        $c4 = $svc->abrir(['tipo' => 'delivery', 'cliente' => 'Familia Gómez', 'direccion' => 'Mitre 450, 2°B', 'telefono' => '353 555-8899']);
        $svc->agregar($c4, ['product_id' => $prod['SAN02']->id, 'cantidad' => 3]); $svc->agregar($c4, ['product_id' => $prod['BEB02']->id, 'cantidad' => 3]);
        $svc->enviarCocina($c4);
        // Dos comandas cerradas hoy (facturadas y cobradas)
        foreach ([['1', [['CAF01', 2], ['PAN01', 6]], 'efectivo', 500], ['3', [['CAF03', 1], ['SAN01', 1]], 'mercadopago', 0]] as [$m, $items, $medio, $propina]) {
            $c = $svc->abrir(['mesa_id' => $mesas[$m]->id, 'cubiertos' => 2]);
            foreach ($items as [$sku, $q]) $svc->agregar($c, ['product_id' => $prod[$sku]->id, 'cantidad' => $q]);
            $svc->enviarCocina($c);
            $svc->cerrar($c->fresh(), ['medios' => [['medio' => $medio, 'monto' => (float) $c->fresh()->total + $propina]], 'propina' => $propina]);
        }
        Auth::logout();
    }
}
