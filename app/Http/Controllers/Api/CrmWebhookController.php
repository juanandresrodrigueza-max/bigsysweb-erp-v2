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
        if (! CrmSyncService::firmaValida($cuerpo, $request->header('X-BigSysweb-Signature'), CrmService::config($b)['webhook_secreto'])) return response()->json(['error' => 'Firma inválida.'], 401);
        $body = json_decode($cuerpo, true);
        if (! is_array($body) || empty($body['event'])) return response()->json(['error' => 'Cuerpo inválido.'], 422);
        // El CRM reintenta si no recibe 2xx: el mismo cuerpo dos veces se procesa una sola vez.
        if (! Cache::add('crm_wh_' . sha1($cuerpo), 1, now()->addMinutes(10))) return response()->json(['ok' => true, 'duplicado' => true]);
        $r = CrmSyncService::procesarWebhook($b, (string) $body['event'], (array) ($body['data'] ?? []));
        return response()->json($r, ($r['ok'] ?? false) ? 200 : 422);
    }
}
