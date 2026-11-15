<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Support\Facades\Auth;
use Tests\ErpTestCase;

// Fase 108: la página de ventas pública del ERP.
class Fase108Test extends ErpTestCase
{
    public function test_la_raiz_sin_sesion_muestra_la_pagina_de_ventas_con_los_planes_y_con_sesion_redirige(): void
    {
        Plan::where('slug', '!=', 'prueba')->update(['is_active' => false]);
        Plan::updateOrCreate(['slug' => 'negocio'], ['name' => 'Negocio', 'price_monthly' => 150000, 'price_yearly' => 1499900, 'is_active' => true, 'recomendado' => true, 'max_users' => 10, 'max_facturas_mes' => 3000]);
        Plan::firstOrCreate(['slug' => 'prueba'], ['name' => 'Prueba', 'price_monthly' => 0, 'price_yearly' => 0, 'is_active' => true]);
        $this->get('/')->assertRedirect('/dashboard');
        Auth::logout();
        $this->get('/')->assertOk()->assertInertia(fn($p) => $p->component('Publico/Conocer', false)->where('planes.0.slug', 'negocio')->where('planes.0.recomendado', true)->where('dias', 15)->missing('planes.1'));
        \App\Models\Novedad::create(['titulo' => 'Algo nuevo', 'texto' => 'Detalle', 'modulo' => 'dashboard', 'url' => '/ayuda/facturar', 'publicada_en' => now()]);
        $this->get('/conocer')->assertOk()->assertInertia(fn($p) => $p->where('novedades.0.titulo', 'Algo nuevo'));
        $this->get('/privacidad')->assertOk(); $this->get('/terminos')->assertOk();
    }
}
