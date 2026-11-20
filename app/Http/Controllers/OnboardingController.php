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
    // Fase 122: los pasos van en etapas, en el orden en que conviene hacerlos. Cada uno dice qué es, para qué sirve y cuánto
    // lleva, y la pantalla muestra siempre «el siguiente»: el primero obligatorio que falta.
    public const ETAPAS = [
        'empresa' => ['titulo' => 'Tu empresa', 'texto' => 'Los datos que salen en cada factura y el permiso para facturar en ARCA.'],
        'datos' => ['titulo' => 'Tus datos', 'texto' => 'Artículos, clientes, proveedores y saldos: si venís de BigSys de escritorio, se traen solos.'],
        'plata' => ['titulo' => 'La plata y el equipo', 'texto' => 'Dónde entra cada cobro, quién usa el sistema y el WhatsApp de la empresa.'],
        'arranque' => ['titulo' => 'A vender', 'texto' => 'La primera factura de verdad: con esto ya estás trabajando.'],
        'negocio' => ['titulo' => 'Para tu negocio', 'texto' => 'Lo que Bigcito sumó según cómo trabajás.'],
        'tranquilo' => ['titulo' => 'Para quedarte tranquilo', 'texto' => 'Seguridad, copias y el plan.'],
    ];

    public static function pasos($b): array
    {
        $manual = $b->onboarding ?? [];
        $origen = $b->induccion['respuestas']['origen'] ?? null;
        $migro = ! empty(($b->bigconnect ?? [])['ultima']);
        $wa = ! empty(($b->whatsapp_settings ?? [])['token']) && ! empty(($b->whatsapp_settings ?? [])['phone_id']);
        $pasos = [
            ['key' => 'empresa', 'etapa' => 'empresa', 'titulo' => 'Datos de la empresa', 'desc' => 'Razón social, CUIT, condición de IVA, domicilio, Ingresos Brutos y logo.', 'porque' => 'Salen impresos en cada factura y ARCA los exige.', 'minutos' => 5, 'url' => '/configuracion', 'hecho' => (bool) ($b->cuit && $b->razon_social), 'ayuda' => 'implementacion'],
            ['key' => 'punto_venta', 'etapa' => 'empresa', 'titulo' => 'Punto de venta', 'desc' => 'Al menos uno, con el mismo número que tenés dado de alta en ARCA para web services.', 'porque' => 'Cada factura sale numerada en un punto de venta.', 'minutos' => 2, 'url' => '/configuracion/puntos-venta', 'hecho' => PuntoVenta::withoutGlobalScopes()->where('business_id', $b->id)->exists(), 'ayuda' => 'guia-arca'],
            ['key' => 'afip', 'etapa' => 'empresa', 'titulo' => 'Factura electrónica (ARCA)', 'desc' => 'Con tu clave fiscal le das permiso a BigSys en ARCA (sin certificados) y acá lo activás: el sistema verifica que esté bien hecho.', 'porque' => 'Hasta entonces las facturas salen simuladas, sin CAE: sirven para probar. Podés dejarlo para después.', 'minutos' => 10, 'url' => '/configuracion/puntos-venta', 'hecho' => (bool) $b->afip_cert_path || (bool) $b->arca_bigsys, 'opcional' => true, 'ayuda' => 'guia-arca'],
            ['key' => 'migrar', 'etapa' => 'datos', 'titulo' => 'Traé tus datos de BigSys', 'desc' => 'Si usás BigSys de escritorio, conectalo con BIG Connect y se traen clientes, proveedores, artículos con sus 6 listas, saldos, cajas, cheques y vendedores. Primero lo probás sin guardar.', 'porque' => 'Arrancás con todo al día sin cargar nada a mano, y el escritorio sigue andando hasta el día del corte.', 'minutos' => 20, 'url' => '/configuracion/importar', 'hecho' => $migro, 'opcional' => $origen !== 'bigsys', 'ayuda' => 'configuracion'],
            ['key' => 'articulos', 'etapa' => 'datos', 'titulo' => 'Artículos', 'desc' => $migro ? 'Ya los trajiste de BigSys: revisá precios y rubros.' : 'Cargalos a mano o importá tu lista de Excel o de otro sistema.', 'porque' => 'Sin artículos no se puede facturar ni controlar stock.', 'minutos' => 15, 'url' => $migro ? '/stock' : '/configuracion/importar', 'hecho' => Product::withoutGlobalScopes()->where('business_id', $b->id)->count() >= 3, 'ayuda' => 'stock'],
            ['key' => 'clientes', 'etapa' => 'datos', 'titulo' => 'Clientes y proveedores', 'desc' => $migro ? 'Ya los trajiste de BigSys con sus saldos: compará un par con el escritorio.' : 'Importalos con sus saldos para arrancar con la cuenta corriente al día.', 'porque' => 'El CUIT y la condición de IVA definen si sale factura A o B.', 'minutos' => 10, 'url' => $migro ? '/clientes' : '/configuracion/importar', 'hecho' => Contact::withoutGlobalScopes()->where('business_id', $b->id)->where('name', '!=', 'Consumidor Final')->count() >= 2, 'ayuda' => 'cobrar'],
            ['key' => 'fondos', 'etapa' => 'plata', 'titulo' => 'Caja y banco', 'desc' => 'Una caja en efectivo y la cuenta bancaria, con su saldo al día del corte.', 'porque' => 'Cada cobro y cada pago entra o sale de una de ellas.', 'minutos' => 3, 'url' => '/fondos', 'hecho' => CuentaFondos::withoutGlobalScopes()->where('business_id', $b->id)->count() >= 2, 'ayuda' => 'fondos'],
            ['key' => 'usuarios', 'etapa' => 'plata', 'titulo' => 'Usuarios del equipo', 'desc' => 'Un usuario por persona, cada uno con su rol (vendedor, cajero, depósito, contador).', 'porque' => 'Cada uno ve sólo lo suyo y queda registrado quién hizo cada cosa.', 'minutos' => 5, 'url' => '/configuracion/usuarios', 'hecho' => User::where('business_id', $b->id)->count() >= 2, 'ayuda' => 'configuracion'],
            ['key' => 'whatsapp', 'etapa' => 'plata', 'titulo' => 'WhatsApp de la empresa', 'desc' => 'Escaneás un QR con WhatsApp Business y listo: el número sigue en tu celular y los mensajes, pedidos y recordatorios pasan por el sistema.', 'porque' => 'Mandás facturas y recordatorios de deuda desde el sistema, y Bigcito atiende consultas.', 'minutos' => 5, 'url' => '/configuracion/tienda', 'hecho' => $wa, 'opcional' => true, 'ayuda' => 'tienda-y-canales'],
            ['key' => 'primera_factura', 'etapa' => 'arranque', 'titulo' => 'Primera factura', 'desc' => 'Hacé una de prueba a Consumidor Final: se puede anular con nota de crédito.', 'porque' => 'Ves cómo se mueven solos el stock, la cuenta corriente, la caja y la contabilidad.', 'minutos' => 2, 'url' => '/comprobantes/nuevo', 'hecho' => \App\Models\Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->exists(), 'ayuda' => 'facturar'],
            // Fase 46: al final de la prueba, elegir plan y pagar con Mercado Pago (o seguir gratis).
            ['key' => 'plan', 'etapa' => 'tranquilo', 'titulo' => 'Elegí tu plan', 'desc' => 'Durante la prueba tenés todo. Al terminar seguís gratis o pagás un plan con Mercado Pago.', 'porque' => 'Así no se corta nada cuando termina la prueba.', 'minutos' => 2, 'url' => '/suscripcion', 'hecho' => \App\Models\Subscription::where('business_id', $b->id)->where('status', 'active')->exists(), 'opcional' => true, 'ayuda' => 'primeros-pasos'],
            ['key' => 'seguridad', 'etapa' => 'tranquilo', 'titulo' => 'Seguridad y copias', 'desc' => 'Activá la verificación en dos pasos y dejá la copia automática encendida.', 'porque' => 'Si alguien consigue tu contraseña, igual no entra.', 'minutos' => 3, 'url' => '/configuracion/seguridad', 'hecho' => (bool) $b->backup_auto, 'opcional' => true, 'ayuda' => 'configuracion'],
        ];
        // Si dijo que viene de otro sistema o que arranca de cero, el paso de BigSys no aplica.
        if (in_array($origen, ['otro', 'cero'], true)) $pasos = array_values(array_filter($pasos, fn($p) => $p['key'] !== 'migrar'));
        // Fase 48: lo que Bigcito dedujo en la entrevista suma pasos a medida (antes del plan y la seguridad, que son opcionales).
        $extra = array_map(fn($p) => $p + ['etapa' => 'negocio', 'minutos' => 10], app(\App\Services\Empresas\InductorService::class)->pasosExtra($b));
        if ($extra) array_splice($pasos, max(0, count($pasos) - 2), 0, $extra);
        foreach ($pasos as &$p) if (! empty($manual[$p['key']])) $p['hecho'] = true;
        return $pasos;
    }

    // El siguiente paso: el primero obligatorio que falta; si están todos, el primero opcional que falta.
    public static function siguiente(array $pasos): ?array
    {
        return collect($pasos)->first(fn($p) => ! $p['hecho'] && empty($p['opcional'])) ?? collect($pasos)->first(fn($p) => ! $p['hecho']);
    }

    // Lo que muestra la barra de arriba en todas las pantallas.
    public static function resumen($b): array
    {
        $p = self::pasos($b); $s = self::siguiente($p);
        return ['hechos' => count(array_filter($p, fn($x) => $x['hecho'])), 'total' => count($p), 'siguiente' => $s ? ['titulo' => $s['titulo'], 'url' => $s['url']] : null];
    }

    public function index(Request $request)
    {
        $b = $request->user()->business;
        \Illuminate\Support\Facades\Cache::forget('onboarding_resumen_' . $b->id); // al volver acá, la barra de arriba se pone al día
        $pasos = self::pasos($b);
        $ind = app(\App\Services\Empresas\InductorService::class);
        $mods = config('erp.modulos');
        return Inertia::render('Onboarding', ['induccion' => ['hecha' => ! empty($b->induccion['respuestas']), 'respuestas' => $b->induccion['respuestas'] ?? ($b->vertical && $b->vertical !== 'otro' ? ['rubro' => $b->vertical] : (object) []), 'preguntas' => $ind::preguntas(), 'seguimiento' => $ind->seguimiento($b),
                'ocultos' => collect($b->modulos_ocultos ?? [])->map(fn($m) => ['key' => $m, 'label' => $mods[$m]['label'] ?? $m])->values(), 'ia' => \App\Support\Ia::tiene($b), 'puede' => $request->user()->puede('configuracion', 'editar')],
            'pasos' => $pasos, 'etapas' => self::ETAPAS, 'siguiente' => self::siguiente($pasos)['key'] ?? null, 'completado' => (bool) $b->onboarding_completado_en, 'hechos' => count(array_filter($pasos, fn($p) => $p['hecho'])), 'total' => count($pasos), 'soporte' => ['whatsapp' => \App\Models\SistemaConfig::get('soporte_whatsapp'), 'email' => \App\Models\SistemaConfig::get('soporte_email')]]);
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
