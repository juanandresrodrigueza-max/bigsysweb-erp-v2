<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Product;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Primeros pasos: checklist guiado para que una empresa nueva quede operativa en una tarde.
class OnboardingController extends Controller
{
    public static function pasos($b): array
    {
        $manual = $b->onboarding ?? [];
        $pasos = [
            ['key' => 'empresa', 'titulo' => 'Datos de la empresa', 'desc' => 'Razón social, CUIT, condición de IVA y logo: salen en las facturas.', 'url' => '/configuracion', 'hecho' => (bool) ($b->cuit && $b->razon_social), 'ayuda' => 'implementacion'],
            ['key' => 'punto_venta', 'titulo' => 'Punto de venta', 'desc' => 'Al menos uno, con el mismo número que en ARCA.', 'url' => '/configuracion/puntos-venta', 'hecho' => PuntoVenta::withoutGlobalScopes()->where('business_id', $b->id)->exists(), 'ayuda' => 'guia-arca'],
            ['key' => 'afip', 'titulo' => 'Certificado ARCA (AFIP)', 'desc' => 'Sin certificado las facturas salen simuladas. Podés dejarlo para después.', 'url' => '/configuracion/puntos-venta', 'hecho' => (bool) $b->afip_cert_path, 'opcional' => true, 'ayuda' => 'guia-arca'],
            ['key' => 'fondos', 'titulo' => 'Caja y banco', 'desc' => 'Una caja en efectivo y la cuenta bancaria para que los cobros tengan dónde entrar.', 'url' => '/fondos', 'hecho' => CuentaFondos::withoutGlobalScopes()->where('business_id', $b->id)->count() >= 2, 'ayuda' => 'fondos'],
            ['key' => 'articulos', 'titulo' => 'Artículos', 'desc' => 'Cargalos a mano o importá tu lista de Excel / sistema anterior.', 'url' => '/configuracion/importar', 'hecho' => Product::withoutGlobalScopes()->where('business_id', $b->id)->count() >= 3, 'ayuda' => 'stock'],
            ['key' => 'clientes', 'titulo' => 'Clientes y proveedores', 'desc' => 'Importalos con sus saldos para arrancar con la cuenta corriente al día.', 'url' => '/configuracion/importar', 'hecho' => Contact::withoutGlobalScopes()->where('business_id', $b->id)->where('name', '!=', 'Consumidor Final')->count() >= 2, 'ayuda' => 'cobrar'],
            ['key' => 'usuarios', 'titulo' => 'Usuarios del equipo', 'desc' => 'Un usuario por persona, cada uno con su rol.', 'url' => '/configuracion/usuarios', 'hecho' => User::where('business_id', $b->id)->count() >= 2, 'ayuda' => 'configuracion'],
            ['key' => 'primera_factura', 'titulo' => 'Primera factura', 'desc' => 'Hacé una de prueba: se puede anular después.', 'url' => '/comprobantes/nuevo', 'hecho' => \App\Models\Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->exists(), 'ayuda' => 'facturar'],
            // Fase 46: al final de la prueba, elegir plan y pagar con Mercado Pago (o seguir gratis).
            ['key' => 'plan', 'titulo' => 'Elegí tu plan', 'desc' => 'Durante la prueba tenés todo. Al terminar seguís gratis o pagás un plan con Mercado Pago.', 'url' => '/suscripcion', 'hecho' => \App\Models\Subscription::where('business_id', $b->id)->where('status', 'active')->exists(), 'opcional' => true, 'ayuda' => 'primeros-pasos'],
            ['key' => 'seguridad', 'titulo' => 'Seguridad y copias', 'desc' => 'Activá la verificación en dos pasos y dejá la copia automática encendida.', 'url' => '/configuracion/seguridad', 'hecho' => (bool) $b->backup_auto, 'opcional' => true, 'ayuda' => 'configuracion'],
        ];
        // Fase 48: lo que Bigcito dedujo en la entrevista suma pasos a medida (antes del plan y la seguridad, que son opcionales).
        $extra = app(\App\Services\Empresas\InductorService::class)->pasosExtra($b);
        if ($extra) array_splice($pasos, max(0, count($pasos) - 2), 0, $extra);
        foreach ($pasos as &$p) if (! empty($manual[$p['key']])) $p['hecho'] = true;
        return $pasos;
    }

    public function index(Request $request)
    {
        $b = $request->user()->business;
        $pasos = self::pasos($b);
        $ind = app(\App\Services\Empresas\InductorService::class);
        $mods = config('erp.modulos');
        return Inertia::render('Onboarding', ['induccion' => ['hecha' => ! empty($b->induccion['respuestas']), 'respuestas' => $b->induccion['respuestas'] ?? ($b->vertical && $b->vertical !== 'otro' ? ['rubro' => $b->vertical] : (object) []), 'preguntas' => $ind::preguntas(), 'seguimiento' => $ind->seguimiento($b),
                'ocultos' => collect($b->modulos_ocultos ?? [])->map(fn($m) => ['key' => $m, 'label' => $mods[$m]['label'] ?? $m])->values(), 'ia' => \App\Support\Ia::tiene($b), 'puede' => $request->user()->puede('configuracion', 'editar')],
            'pasos' => $pasos, 'completado' => (bool) $b->onboarding_completado_en, 'hechos' => count(array_filter($pasos, fn($p) => $p['hecho'])), 'total' => count($pasos), 'soporte' => ['whatsapp' => \App\Models\SistemaConfig::get('soporte_whatsapp'), 'email' => \App\Models\SistemaConfig::get('soporte_email')]]);
    }

    public function marcar(Request $request)
    {
        $d = $request->validate(['paso' => 'required|string|max:30', 'hecho' => 'boolean']);
        $b = $request->user()->business;
        $o = $b->onboarding ?? []; $o[$d['paso']] = $d['hecho'] ?? true;
        $b->update(['onboarding' => $o]);
        return back();
    }

    public function completar(Request $request)
    {
        $b = $request->user()->business;
        $b->update(['onboarding_completado_en' => now()]); \Illuminate\Support\Facades\Cache::forget('onboarding_resumen_' . $b->id);
        AuditLog::registrar('editar', $b, 'Completó los primeros pasos');
        return redirect('/dashboard')->with('success', '¡Listo! La empresa quedó configurada. Cualquier cosa, Soporte está en el menú.');
    }

    // Fase 48: el inductor de Bigcito. Guarda las respuestas y deja el sistema a medida.
    public function induccion(Request $request, \App\Services\Empresas\InductorService $ind)
    {
        abort_unless($request->user()->puede('configuracion', 'editar'), 403, 'Sólo el dueño o un administrador configura la empresa.');
        $d = $request->validate(['respuestas' => 'required|array', 'respuestas.*' => 'string|max:30']);
        $p = $ind->aplicar($request->user()->business, $d['respuestas']);
        return back()->with('success', 'Listo, te dejé el sistema a medida' . ($p['ocultos'] ? ': saqué del menú lo que no vas a usar (lo volvés a mostrar cuando quieras).' : '.') . ' Abajo tenés los pasos para tu negocio.');
    }

    // "Contame tu negocio" en tus palabras: la IA lo pasa a respuestas; lo que no queda claro, Bigcito lo pregunta.
    public function interpretar(Request $request, \App\Services\Empresas\InductorService $ind)
    {
        $d = $request->validate(['texto' => 'required|string|max:2000']);
        return response()->json(['respuestas' => (object) $ind->interpretar($d['texto'])]);
    }

    // Volver a mostrar un módulo que se ocultó.
    public function mostrarModulo(Request $request)
    {
        abort_unless($request->user()->puede('configuracion', 'editar'), 403);
        $d = $request->validate(['modulo' => 'required|string|max:30']);
        $b = $request->user()->business; $b->forceFill(['modulos_ocultos' => array_values(array_diff($b->modulos_ocultos ?? [], [$d['modulo']]))])->save();
        return back()->with('success', 'Listo, ya aparece en el menú.');
    }
}
