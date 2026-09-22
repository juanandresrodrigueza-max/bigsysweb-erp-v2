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
}
