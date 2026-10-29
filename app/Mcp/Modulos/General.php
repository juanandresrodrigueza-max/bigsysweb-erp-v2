<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Alerta;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Product;
use App\Models\User;

// Panorama del negocio, alertas y la ayuda del sistema.
class General
{
    public static function herramientas(): array
    {
        return [
            ['nombre' => 'resumen_negocio', 'titulo' => 'Resumen del negocio', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Panorama del negocio hoy: ventas del día y del mes, por cobrar y vencido, deuda con proveedores, plata disponible, stock bajo mínimo, lotes por vencer y alertas activas.',
                'ejecutar' => function (User $u, array $a) {
                    $hoy = today();
                    return [
                        'empresa' => $u->business?->name, 'usuario' => $u->name, 'sucursal' => $u->currentLocation?->name, 'fecha' => $hoy->toDateString(),
                        'ventas_hoy' => (float) Comprobante::ventas()->emitidos()->facturas()->whereDate('fecha', $hoy)->sum('total'),
                        'ventas_mes' => (float) Comprobante::ventas()->emitidos()->facturas()->whereBetween('fecha', [$hoy->copy()->startOfMonth()->toDateString(), $hoy->toDateString()])->sum('total'),
                        'por_cobrar' => (float) Contact::customers()->where('balance', '>', 0)->sum('balance'),
                        'vencido_clientes' => (float) Comprobante::ventas()->pendientesCobro()->where('fecha_vto', '<', $hoy->toDateString())->sum('saldo'),
                        'por_pagar' => (float) Contact::suppliers()->where('balance', '>', 0)->sum('balance'),
                        'vencido_proveedores' => (float) Comprobante::compras()->pendientesPago()->where('fecha_vto', '<', $hoy->toDateString())->sum('saldo'),
                        'disponible' => (float) CuentaFondos::where('activa', true)->where('moneda', 'ARS')->sum('saldo'),
                        'articulos_bajo_minimo' => Product::where('active', true)->where('controla_stock', true)->whereColumn('stock', '<=', 'stock_min')->where('stock_min', '>', 0)->count(),
                        'lotes_por_vencer_30_dias' => \App\Models\Lote::where('cantidad', '>', 0)->whereNotNull('vencimiento')->whereBetween('vencimiento', [$hoy->toDateString(), $hoy->copy()->addDays(30)->toDateString()])->count(),
                        'alertas' => Alerta::visiblesPara($u)->activas()->latest()->limit(10)->get(['titulo', 'detalle', 'severidad'])->toArray(),
                    ];
                }],
            ['nombre' => 'alertas_activas', 'titulo' => 'Alertas', 'modulo' => 'alertas', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Alertas activas del sistema (stock bajo, facturas vencidas, cheques, lotes por vencer, cajas abiertas…).',
                'parametros' => ['cantidad' => ['type' => 'integer', 'description' => 'Máximo a devolver (20 por defecto).']],
                'ejecutar' => fn(User $u, array $a) => ['alertas' => Alerta::visiblesPara($u)->activas()->latest()->limit(min(100, (int) ($a['cantidad'] ?? 20)))->get()->map(fn($x) => ['titulo' => $x->titulo, 'detalle' => $x->detalle, 'severidad' => $x->severidad, 'modulo' => $x->modulo, 'url' => $x->url ? Busca::url($x->url) : null, 'desde' => $x->created_at?->toDateTimeString()])->all()]],
            ['nombre' => 'ayuda_sistema', 'titulo' => 'Ayuda del sistema', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Busca en las guías de ayuda de BigSysWeb cómo se hace algo en el sistema (pasos, menús, botones).',
                'parametros' => ['pregunta' => ['type' => 'string', 'description' => 'Qué se quiere hacer, en palabras del usuario.']], 'requeridos' => ['pregunta'],
                // Las 3 secciones más cercanas van enteras (pasos completos); el resto, el fragmento. Si no hay nada, lo dice: Bigcito no inventa pantallas.
                'ejecutar' => function (User $u, array $a) {
                    $ay = app(\App\Services\Producto\AyudaService::class); $res = $ay->buscar((string) $a['pregunta'], 5);
                    if (! $res) return ['resultados' => [], 'nota' => 'Las guías no hablan de esto. No inventes pasos: decile que escriba a soporte (Ayuda → Escribir a soporte).'];
                    return ['resultados' => collect($res)->values()->map(function ($r, $i) use ($ay) {
                        $txt = $i < 3 ? (collect($ay->secciones($ay->articulos()[$r['slug']]['texto'] ?? ''))->firstWhere('ancla', $r['ancla'])['texto'] ?? $r['fragmento']) : $r['fragmento'];
                        return ['guia' => $r['titulo'], 'seccion' => $r['seccion'], 'texto' => mb_substr($txt, 0, 2500), 'url' => Busca::url($r['url'])];
                    })->all()];
                }],
        ];
    }
}
