<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Integraciones\CrmService;
use App\Services\Integraciones\CrmSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Webhooks que manda el CRM (público, firmado con HMAC-SHA256 en X-BigSysweb-Signature). Idempotente por cuerpo.
class CrmWebhookController extends Controller
{
    public function recibir(Request $request, int $business)
    {
        $b = Business::withoutGlobalScopes()->find($business);
        if (! $b || ! CrmService::activo($b)) return response()->json(['error' => 'Integración con el CRM inactiva para esta empresa.'], 404);
        $cuerpo = $request->getContent();
        if (! CrmSyncService::firmaValida($cuerpo, $request->header('X-BigSysweb-Signature'), CrmService::config($b)['webhook_secreto'])) { \App\Services\Integraciones\CrmSaludService::registrar($b->id, 'entrada', (string) ($request->header('X-BigSysweb-Event') ?: 'webhook'), 'rechazado', ['http' => 401, 'detalle' => 'Firma inválida: el secreto del webhook es distinto en los dos sistemas']); return response()->json(['error' => 'Firma inválida.'], 401); }
        $body = json_decode($cuerpo, true);
        if (! is_array($body) || empty($body['event'])) { \App\Services\Integraciones\CrmSaludService::registrar($b->id, 'entrada', 'webhook', 'rechazado', ['http' => 422, 'detalle' => 'Cuerpo inválido']); return response()->json(['error' => 'Cuerpo inválido.'], 422); }
        // El CRM reintenta si no recibe 2xx: el mismo cuerpo dos veces se procesa una sola vez.
        if (! Cache::add('crm_wh_' . sha1($cuerpo), 1, now()->addMinutes(10))) return response()->json(['ok' => true, 'duplicado' => true]);
        $t = microtime(true); $r = CrmSyncService::procesarWebhook($b, (string) $body['event'], (array) ($body['data'] ?? []));
        $d = (array) ($body['data'] ?? []); $ref = $d['quote']['id'] ?? $d['contact']['id'] ?? $d['deal']['id'] ?? $d['id'] ?? null;
        \App\Services\Integraciones\CrmSaludService::registrar($b->id, 'entrada', (string) $body['event'], isset($r['ignorado']) ? 'ignorado' : (($r['ok'] ?? false) ? 'ok' : 'error'), ['referencia' => $ref !== null ? "crm #{$ref}" : null, 'http' => ($r['ok'] ?? false) ? 200 : 422, 'ms' => (int) ((microtime(true) - $t) * 1000), 'detalle' => $r['error'] ?? ($r['numero'] ?? null)]); // Fase 68
        return response()->json($r, ($r['ok'] ?? false) ? 200 : 422);
    }
}
