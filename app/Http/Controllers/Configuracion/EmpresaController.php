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
            'avisos' => app(\App\Services\Ventas\AvisosDuenoService::class)->config($request->user()->business), 'pos' => array_replace(['balanza_prefijo' => '2', 'balanza_modo' => 'peso', 'balanza_decimales' => 3, 'imprimir_auto' => false, 'impresora' => 'navegador', 'ancho' => 42], $request->user()->business->pos ?? []), 'resumenTexto' => session('resumen_texto'), 'verticalesExtra' => (array) ($request->user()->business->verticales_extra ?? []), 'whatsappApi' => ! empty($request->user()->business->whatsapp_settings['token']),
            'empresa' => [
                'name' => $b->name, 'razon_social' => $b->razon_social, 'cuit' => $b->cuit, 'email' => $b->email,
                'phone' => $b->phone, 'condicion_iva' => $b->condicion_iva ?? 'Responsable Inscripto',
                'afip_punto_venta' => $b->afip_punto_venta, 'afip_produccion' => $b->afip_produccion, 'logo' => $b->logo,
            ],
            'plan' => $sub ? [
                'nombre' => $sub->plan->name, 'precio' => (float) $sub->plan->price_monthly, 'vence' => $sub->ends_at?->format('d/m/Y'),
                'estado' => $sub->status, 'usuarios' => [$b->users()->count(), $sub->plan->max_users], 'sucursales' => [$b->locations()->count(), $sub->plan->max_locations],
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
        ]);

        $b = $request->user()->business;
        $antes = $b->only(array_keys($data));
        $b->update($data);
        AuditLog::registrar('editar', $b, 'Datos de la empresa', $antes, $data);

        return back()->with('success', 'Datos de la empresa guardados.');
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

    public function guardarPos(Request $request)
    {
        $d = $request->validate(['balanza_prefijo' => 'nullable|string|max:3', 'balanza_modo' => 'required|in:peso,importe', 'balanza_decimales' => 'required|integer|min:0|max:3', 'imprimir_auto' => 'boolean', 'impresora' => 'required|in:navegador,serial,ninguna', 'ancho' => 'required|integer|in:32,42,48']);
        $b = $request->user()->business;
        $b->update(['pos' => array_replace($b->pos ?? [], $d)]);
        return back()->with('success', 'Punto de venta configurado.');
    }
}
