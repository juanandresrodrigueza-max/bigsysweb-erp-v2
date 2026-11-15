<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\SistemaConfig;
use Inertia\Inertia;

// Fase 108: la página de ventas pública del ERP (erp.bigsysweb.com/conocer, y la raíz para quien no está logueado):
// qué hace, Bigcito, los siete agentes, los rubros, los planes con precio real y el registro gratis.
class ConocerController extends Controller
{
    public function index()
    {
        $planes = Plan::where('is_active', true)->where('slug', '!=', 'prueba')->orderBy('orden')->orderBy('price_monthly')->get()->map(fn($p) => [
            'slug' => $p->slug, 'nombre' => $p->name, 'descripcion' => $p->description, 'mensual' => (float) $p->price_monthly, 'anual' => (float) $p->price_yearly, 'gratis' => (bool) $p->is_free, 'recomendado' => (bool) $p->recomendado,
            'usuarios' => (int) $p->max_users, 'sucursales' => (int) ($p->max_locations ?? 1), 'facturas_mes' => (int) $p->max_facturas_mes, 'articulos' => (int) ($p->max_products ?? -1),
        ])->values();
        return Inertia::render('Publico/Conocer', [
            'planes' => $planes, 'dias' => (int) SistemaConfig::get('dias_prueba'), 'tiendas' => MovilController::tiendas(),
            'soporte' => ['whatsapp' => SistemaConfig::get('soporte_whatsapp'), 'email' => SistemaConfig::get('soporte_email')],
            'rubros' => array_values(\App\Models\Business::VERTICALES),
            // Lo nuevo: las novedades que cada fase publica en la campanita, también acá. Lo que agregamos aparece solo.
            'novedades' => \App\Models\Novedad::whereNotNull('publicada_en')->latest('publicada_en')->limit(8)->get()->map(fn($n) => ['titulo' => $n->titulo, 'texto' => $n->texto, 'fecha' => $n->publicada_en->locale('es')->isoFormat('D [de] MMMM')])->values(),
        ]);
    }
}
