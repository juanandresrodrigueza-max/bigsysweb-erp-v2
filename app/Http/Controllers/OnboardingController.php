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
            ['key' => 'seguridad', 'titulo' => 'Seguridad y copias', 'desc' => 'Activá la verificación en dos pasos y dejá la copia automática encendida.', 'url' => '/configuracion/seguridad', 'hecho' => (bool) $b->backup_auto, 'opcional' => true, 'ayuda' => 'configuracion'],
        ];
        foreach ($pasos as &$p) if (! empty($manual[$p['key']])) $p['hecho'] = true;
        return $pasos;
    }

    public function index(Request $request)
    {
        $b = $request->user()->business;
        $pasos = self::pasos($b);
        return Inertia::render('Onboarding', ['pasos' => $pasos, 'completado' => (bool) $b->onboarding_completado_en, 'hechos' => count(array_filter($pasos, fn($p) => $p['hecho'])), 'total' => count($pasos), 'soporte' => ['whatsapp' => \App\Models\SistemaConfig::get('soporte_whatsapp'), 'email' => \App\Models\SistemaConfig::get('soporte_email')]]);
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
        $b->update(['onboarding_completado_en' => now()]);
        AuditLog::registrar('editar', $b, 'Completó los primeros pasos');
        return redirect('/dashboard')->with('success', '¡Listo! La empresa quedó configurada. Cualquier cosa, Soporte está en el menú.');
    }
}
