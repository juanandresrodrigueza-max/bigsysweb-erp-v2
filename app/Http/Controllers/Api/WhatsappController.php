<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Canales\WhatsappPedidosService;
use Illuminate\Http\Request;

// Webhook de WhatsApp Cloud API (Meta): verifica la suscripción y convierte los mensajes entrantes en pedidos.
class WhatsappController extends Controller
{
    public function verificar(int $business, Request $request)
    {
        $b = Business::find($business);
        $vt = $b?->whatsapp_settings['verify_token'] ?? null;
        if ($request->query('hub_mode') === 'subscribe' && $vt && $request->query('hub_verify_token') === $vt) return response($request->query('hub_challenge'), 200);
        return response('Token inválido', 403);
    }

    public function entrante(int $business, Request $request, WhatsappPedidosService $wa)
    {
        $b = Business::find($business);
        if (! $b) return response()->json(['ok' => false], 404);
        $ws = $b->whatsapp_settings ?? [];
        $mensajes = [];
        foreach ($request->input('entry', []) as $e) foreach ($e['changes'] ?? [] as $ch) foreach ($ch['value']['messages'] ?? [] as $m) if (($m['type'] ?? '') === 'text') $mensajes[] = ['de' => $m['from'] ?? null, 'texto' => $m['text']['body'] ?? ''];
        // Formato simple para integradores: {"telefono": "...", "texto": "..."}
        if (! $mensajes && $request->filled('texto')) $mensajes[] = ['de' => $request->input('telefono'), 'texto' => $request->input('texto')];
        $creados = [];
        foreach ($mensajes as $m) {
            if (trim($m['texto']) === '') continue;
            $i = $wa->interpretar($b, $m['texto']);
            $p = ($ws['auto_pedidos'] ?? true) && $i['items'] ? $wa->crearDesdeTexto($b, $m['texto'], $m['de'], $i) : null;
            if ($p) $creados[] = $p->numeroFormateado();
            if (($ws['auto_responder'] ?? true) && $m['de'] && ! empty($i['respuesta'])) $wa->responder($b, $m['de'], $i['respuesta'] . ($p ? "\nTu pedido es el {$p->numeroFormateado()}. Seguilo acá: {$p->urlPublica()}" : ''));
            if (! $p && $m['de']) \App\Models\Alerta::emitir(['business_id' => $b->id, 'modulo' => 'comprobantes', 'tipo' => 'whatsapp_sin_pedido', 'severidad' => 'info', 'titulo' => 'Mensaje de WhatsApp sin pedido: ' . $m['de'], 'detalle' => mb_substr($m['texto'], 0, 140), 'url' => '/comprobantes/pedidos']);
        }
        return response()->json(['ok' => true, 'pedidos' => $creados]);
    }
}
