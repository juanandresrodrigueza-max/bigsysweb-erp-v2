<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Integraciones\CrmService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

// Configuración → CRM: cómo se conecta esta empresa con el CRM de BigSys.
class CrmController extends Controller
{
    public function index(Request $request)
    {
        $b = $request->user()->business;
        $c = CrmService::config($b);
        return Inertia::render('Configuracion/Crm', [
            'config' => ['activo' => $c['activo'], 'url' => $c['url'], 'secreto_set' => $c['secreto'] !== '', 'api_key_set' => $c['api_key'] !== '', 'webhook_secreto_set' => $c['webhook_secreto'] !== '', 'lista_precios' => $c['lista_precios'], 'presupuesto_como' => $c['presupuesto_como']],
            'cuit' => $b->cuit, 'urlEntrada' => url('/integraciones/crm/entrar'), 'urlWebhook' => url('/api/crm/webhook'), 'listo' => CrmService::activo($b),
            'prueba' => session('crm_prueba'),
        ]);
    }

    public function guardar(Request $request)
    {
        $b = $request->user()->business;
        $d = $request->validate(['activo' => 'boolean', 'url' => 'nullable|url|max:200', 'secreto' => 'nullable|string|min:16|max:200', 'api_key' => 'nullable|string|max:300', 'webhook_secreto' => 'nullable|string|max:200', 'lista_precios' => 'nullable|integer|min:1|max:6', 'presupuesto_como' => 'nullable|in:presupuesto,factura', 'generar_secreto' => 'boolean']);
        $c = CrmService::config($b);
        $c['activo'] = (bool) ($d['activo'] ?? false);
        $c['url'] = rtrim((string) ($d['url'] ?? ''), '/');
        foreach (['secreto', 'api_key', 'webhook_secreto'] as $k) if (! empty($d[$k])) $c[$k] = $d[$k]; // vacío = no tocar
        if ($d['generar_secreto'] ?? false) $c['secreto'] = Str::random(48);
        $c['lista_precios'] = (int) ($d['lista_precios'] ?? 1);
        $c['presupuesto_como'] = $d['presupuesto_como'] ?? 'presupuesto';
        if ($c['activo'] && ! $b->cuit) return back()->withErrors(['activo' => 'Cargá el CUIT de la empresa: es lo que une esta empresa con la del CRM.']);
        if ($c['activo'] && ($c['url'] === '' || $c['secreto'] === '')) return back()->withErrors(['activo' => 'Para activar hace falta la URL del CRM y el secreto compartido.']);
        $b->update(['crm_settings' => $c]);
        AuditLog::registrar('editar', $b, 'Configuró la integración con el CRM' . ($c['activo'] ? ' (activa)' : ' (inactiva)'));
        return back()->with('success', 'Integración con el CRM guardada.' . (($d['generar_secreto'] ?? false) ? ' Copiá el secreto y cargalo en el CRM.' : ''))->with('crm_secreto_nuevo', ($d['generar_secreto'] ?? false) ? $c['secreto'] : null);
    }

    public function probar(Request $request)
    {
        $r = CrmService::probar($request->user()->business);
        AuditLog::registrar('editar', null, 'Probó la conexión con el CRM: ' . ($r['ok'] ? 'OK' : 'error'));
        return back()->with('crm_prueba', $r)->with($r['ok'] ? 'success' : 'error', $r['detalle']);
    }
}
