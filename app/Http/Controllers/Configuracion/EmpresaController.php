<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmpresaController extends Controller
{
    public function index(Request $request)
    {
        $b = $request->user()->business;
        $sub = $b->activeSubscription;

        return Inertia::render('Configuracion/Empresa', [
            'avisos' => app(\App\Services\Ventas\AvisosDuenoService::class)->config($request->user()->business), 'pos' => array_replace(['balanza_prefijo' => '2', 'balanza_modo' => 'peso', 'balanza_decimales' => 3, 'balanza_digitos_plu' => 5, 'imprimir_auto' => false, 'impresora' => 'navegador', 'ancho' => 42], $request->user()->business->pos ?? []), 'resumenTexto' => session('resumen_texto'), 'verticalesExtra' => (array) ($request->user()->business->verticales_extra ?? []), 'whatsappApi' => ! empty($request->user()->business->whatsapp_settings['token']),
            'tarjetas' => app(\App\Services\Pos\CuotasService::class)->planes($b),
            'mercadopago' => ['access_token' => ! empty($b->mercadopago_settings['access_token']) ? '••••' . substr((string) $b->mercadopago_settings['access_token'], -4) : '', 'user_id' => $b->mercadopago_settings['user_id'] ?? '', 'pos_external_id' => $b->mercadopago_settings['pos_external_id'] ?? '', 'point_device_id' => $b->mercadopago_settings['point_device_id'] ?? '', 'tiene_token' => ! empty($b->mercadopago_settings['access_token'])],
            'empresa' => [
                'name' => $b->name, 'razon_social' => $b->razon_social, 'cuit' => $b->cuit, 'email' => $b->email,
                'phone' => $b->phone, 'condicion_iva' => $b->condicion_iva ?? 'Responsable Inscripto',
                'afip_punto_venta' => $b->afip_punto_venta, 'afip_produccion' => $b->afip_produccion, 'logo' => $b->logo,
                'address' => $b->address, 'city' => $b->city, 'province' => $b->province, 'iibb' => $b->iibb, 'inicio_actividades' => $b->inicio_actividades?->toDateString(),
            ],
            'marca' => $b->marca() + ['logo_uri' => $b->logoDataUri()], 'estilos' => \App\Models\Business::ESTILOS,
            'plan' => $sub ? [
                'nombre' => $sub->plan->name, 'precio' => (float) $sub->plan->price_monthly, 'vence' => $sub->ends_at?->format('d/m/Y'),
                'estado' => $sub->status, 'usuarios' => [$b->users()->count(), $sub->plan->max_users], 'sucursales' => [$b->locations()->count(), $sub->plan->max_locations],
                ...app(\App\Services\Suscripciones\LimitesPlanService::class)->uso($b),
            ] : null,
            'modulos' => collect(config('erp.modulos'))->map(fn($m, $k) => ['key' => $k, 'label' => $m['label'], 'activo' => $b->tieneModulo($k), 'core' => $m['core']])->values(),
        ]);
    }

    public function guardar(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'razon_social'     => 'nullable|string|max:255',
            'cuit'             => 'nullable|string|max:20',
            'email'            => 'required|email|max:255',
            'phone'            => 'nullable|string|max:50',
            'condicion_iva'    => 'nullable|string|max:50',
            'afip_punto_venta' => 'nullable|string|max:10',
            'afip_produccion'  => 'boolean',
            'address'          => 'nullable|string|max:255', 'city' => 'nullable|string|max:100', 'province' => 'nullable|string|max:100',
            'iibb'             => 'nullable|string|max:30', 'inicio_actividades' => 'nullable|date',
        ]);

        $b = $request->user()->business;
        $antes = $b->only(array_keys($data));
        $b->update($data);
        AuditLog::registrar('editar', $b, 'Datos de la empresa', $antes, $data);

        return back()->with('success', 'Datos de la empresa guardados.');
    }

    // Identidad en los comprobantes (Fase 25.4): colores, estilo, datos extra, pie y qué mostrar.
    public function guardarMarca(Request $request)
    {
        $d = $request->validate([
            'color_primario' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'color_secundario' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'estilo' => 'required|in:' . implode(',', array_keys(\App\Models\Business::ESTILOS)),
            'datos_extra' => 'nullable|string|max:400', 'pie' => 'nullable|string|max:400', 'validez_presupuesto' => 'required|integer|min:1|max:365',
            'mostrar' => 'array', 'mostrar.*' => 'boolean',
        ], ['color_primario.regex' => 'Elegí un color válido.', 'color_secundario.regex' => 'Elegí un color válido.']);
        $b = $request->user()->business;
        $d['mostrar'] = array_intersect_key(array_map('boolval', $d['mostrar'] ?? []), \App\Models\Business::MARCA['mostrar']);
        $d['datos_extra'] = (string) ($d['datos_extra'] ?? ''); $d['pie'] = (string) ($d['pie'] ?? '');
        $b->update(['marca' => array_replace($b->marca(), $d)]);
        AuditLog::registrar('editar', $b, 'Identidad de los comprobantes');
        return back()->with('success', 'Diseño de comprobantes guardado.');
    }

    public function subirLogo(Request $request)
    {
        $request->validate(['logo' => 'required|file|mimes:png,jpg,jpeg,webp|max:1024'], ['logo.max' => 'El logo puede pesar hasta 1 MB.', 'logo.mimes' => 'Subí el logo en PNG, JPG o WEBP.']);
        $b = $request->user()->business;
        if ($b->logo) \Illuminate\Support\Facades\Storage::disk('local')->delete($b->logo);
        $path = $request->file('logo')->storeAs('logos', "empresa-{$b->id}-" . now()->timestamp . '.' . strtolower($request->file('logo')->getClientOriginalExtension()), 'local');
        $b->update(['logo' => $path]);
        AuditLog::registrar('editar', $b, 'Subió el logo de la empresa');
        return back()->with('success', 'Logo cargado. Ya sale en facturas, recibos, tickets y el catálogo.');
    }

    public function quitarLogo(Request $request)
    {
        $b = $request->user()->business;
        if ($b->logo) \Illuminate\Support\Facades\Storage::disk('local')->delete($b->logo);
        $b->update(['logo' => null]);
        return back()->with('success', 'Logo quitado.');
    }

    // Vista previa con la última factura emitida o, si no hay, con una de ejemplo (no se guarda nada).
    public function muestra(Request $request)
    {
        $b = $request->user()->business;
        $c = \App\Models\Comprobante::ventas()->where('estado', 'emitido')->whereIn('tipo', ['FA', 'FB', 'FC'])->with(['items.product', 'contact', 'impuestos', 'location', 'vendedor', 'origen'])->latest('id')->first();
        if (! $c) {
            $c = new \App\Models\Comprobante(['business_id' => $b->id, 'tipo' => $b->condicion_iva === 'Responsable Inscripto' ? 'FA' : 'FC', 'fecha' => today(), 'fecha_vto' => today()->addDays(30), 'condicion' => 'cta_cte', 'punto_venta' => 1, 'numero' => 123, 'estado' => 'emitido', 'afip_estado' => 'simulado', 'neto' => 10000, 'iva' => 2100, 'total' => 12100, 'direccion' => 'venta']);
            $c->setRelation('items', collect([new \App\Models\ComprobanteItem(['descripcion' => 'Artículo de ejemplo', 'cantidad' => 2, 'unidad' => 'un', 'precio_unit' => 5000, 'descuento' => 0, 'alicuota_iva' => 21, 'neto' => 10000, 'total' => 12100])]));
            $c->setRelation('contact', new \App\Models\Contact(['name' => 'Cliente de ejemplo S.A.', 'cuit' => '30-70012345-6', 'condicion_iva' => 'Responsable Inscripto', 'address' => 'Av. Siempreviva 742', 'city' => 'Córdoba']));
            foreach (['impuestos' => collect(), 'location' => null, 'vendedor' => null, 'origen' => null] as $k => $v) $c->setRelation($k, $v);
        }
        return view('comprobantes.imprimir', ['c' => $c, 'b' => $c->exists ? $c->emisor() : $b]);
    }

    public function guardarAvisos(Request $request, \App\Services\Ventas\AvisosDuenoService $svc)
    {
        $d = $request->validate(['activo' => 'boolean', 'whatsapp' => 'nullable|string|max:40', 'hora' => 'required|date_format:H:i', 'resumen_diario' => 'boolean', 'criticas' => 'boolean']);
        $b = $request->user()->business;
        $b->update(['avisos' => array_replace($svc->config($b), $d)]);
        AuditLog::registrar('editar', $b, 'Configuró los avisos al dueño por WhatsApp');
        return back()->with('success', 'Avisos guardados.');
    }

    public function resumenAhora(Request $request, \App\Services\Ventas\AvisosDuenoService $svc)
    {
        $b = $request->user()->business; $c = $svc->config($b);
        $texto = $svc->resumen($b);
        $r = $c['whatsapp'] ? app(\App\Services\Canales\WhatsappPedidosService::class)->responder($b, $c['whatsapp'], $texto) : ['enviado' => false, 'link' => null];
        return back()->with('resumen_texto', $texto)->with('success', $r['enviado'] ? 'Resumen enviado por WhatsApp.' : 'Este es el resumen de hoy.')->with('abrir', $r['link']);
    }

    public function guardarVerticales(Request $request)
    {
        $v = array_values(array_intersect((array) $request->input('verticales_extra', []), \App\Models\Business::VERTICALES_MODULOS));
        $b = $request->user()->business;
        $b->update(['verticales_extra' => $v]);
        AuditLog::registrar('editar', $b, 'Verticales habilitados: ' . (implode(', ', $v) ?: 'ninguno extra'));
        return back()->with('success', 'Verticales guardados.');
    }

    // Tarjetas y planes de cuotas del punto de venta.
    public function guardarTarjetas(Request $request)
    {
        $d = $request->validate(['tarjetas' => 'present|array|max:20', 'tarjetas.*.nombre' => 'required|string|max:60', 'tarjetas.*.planes' => 'present|array|max:30', 'tarjetas.*.planes.*.cuotas' => 'required|integer|min:1|max:60', 'tarjetas.*.planes.*.recargo' => 'required|numeric|min:-100|max:500']);
        $b = $request->user()->business;
        $b->update(['tarjetas' => array_values($d['tarjetas'])]);
        AuditLog::registrar('editar', $b, 'Planes de cuotas: ' . count($d['tarjetas']) . ' tarjeta/s');
        return back()->with('success', 'Planes de cuotas guardados.');
    }

    // Credenciales de Mercado Pago: links de pago, QR de mostrador y Point. El token se guarda cifrado y no se vuelve a mostrar.
    public function guardarMercadoPago(Request $request)
    {
        $d = $request->validate(['access_token' => 'nullable|string|max:300', 'user_id' => 'nullable|string|max:30', 'pos_external_id' => 'nullable|string|max:60', 'point_device_id' => 'nullable|string|max:80']);
        $b = $request->user()->business;
        $s = $b->mercadopago_settings ?? [];
        if (! empty($d['access_token']) && ! str_starts_with($d['access_token'], '••••')) $s['access_token'] = trim($d['access_token']);
        foreach (['user_id', 'pos_external_id', 'point_device_id'] as $k) $s[$k] = $d[$k] !== null && $d[$k] !== '' ? trim($d[$k]) : null;
        $b->update(['mercadopago_settings' => $s]);
        AuditLog::registrar('editar', $b, 'Configuró Mercado Pago (QR / Point)');
        return back()->with('success', 'Mercado Pago configurado.');
    }

    public function guardarPos(Request $request)
    {
        $d = $request->validate(['balanza_prefijo' => 'nullable|string|max:3', 'balanza_modo' => 'required|in:peso,importe', 'balanza_decimales' => 'required|integer|min:0|max:3', 'balanza_digitos_plu' => 'nullable|integer|min:4|max:6', 'imprimir_auto' => 'boolean', 'impresora' => 'required|in:navegador,serial,ninguna', 'ancho' => 'required|integer|in:32,42,48']);
        $b = $request->user()->business;
        $b->update(['pos' => array_replace($b->pos ?? [], $d)]);
        return back()->with('success', 'Punto de venta configurado.');
    }
}
