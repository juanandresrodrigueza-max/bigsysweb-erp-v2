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
        // Fase 118.1: lo conectado con el QR de Meta viene firmado con el App Secret; sin firma válida no se procesa.
        if (($ws['modo'] ?? null) === 'qr' && ! \App\Services\Canales\WhatsappConexionService::firmaValida($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response()->json(['ok' => false, 'error' => 'firma inválida'], 403);
        }
        \App\Support\Ia::aplicar($b);
        $mensajes = [];
        foreach ($request->input('entry', []) as $e) foreach ($e['changes'] ?? [] as $ch) foreach ($ch['value']['messages'] ?? [] as $m) {
            if (($m['type'] ?? '') === 'text') $mensajes[] = ['de' => $m['from'] ?? null, 'texto' => $m['text']['body'] ?? ''];
            // Fase 111: los audios sólo se escuchan si vienen de alguien del equipo (Bigcito los transcribe); de un cliente no se usan.
            elseif (($m['type'] ?? '') === 'audio' && ! empty($m['audio']['id'])) $mensajes[] = ['de' => $m['from'] ?? null, 'texto' => '', 'audio_id' => $m['audio']['id'], 'mime' => $m['audio']['mime_type'] ?? 'audio/ogg'];
        }
        // Formato simple para integradores: {"telefono": "...", "texto": "..."}
        if (! $mensajes && $request->filled('texto')) $mensajes[] = ['de' => $request->input('telefono'), 'texto' => $request->input('texto')];
        $creados = []; $atendidos = [];
        // Fase 120.1: Meta avisa cuando un texto no se pudo entregar porque la persona no escribió en las últimas 24 horas
        // (código 131047): se cierra esa ventana y, si hay plantilla aprobada, se reintenta con ella.
        $pl = app(\App\Services\Canales\WhatsappPlantillasService::class);
        foreach ($request->input('entry', []) as $e) foreach ($e['changes'] ?? [] as $ch) foreach ($ch['value']['statuses'] ?? [] as $st) {
            if (($st['status'] ?? '') !== 'failed' || empty($st['recipient_id'])) continue;
            $codigos = array_map(fn($x) => (int) ($x['code'] ?? 0), (array) ($st['errors'] ?? []));
            if (array_intersect($codigos, [131047, 131026])) { if ($pl->rebotado($b, (string) $st['recipient_id'], $st['id'] ?? null)) $atendidos[] = 'reintento_plantilla'; }
        }
        foreach ($mensajes as $m) {
            // Fase 120.1: quien escribe abre su ventana de 24 horas; si tenía algo pendiente de recibir (lo que salió como plantilla), se lo mandamos ahora.
            if (! empty($m['de'])) {
                $pend = $pl->registrarEntrante($b, (string) $m['de']);
                foreach ($pend as $texto) $wa->responder($b, (string) $m['de'], $texto, ['texto_libre' => true]);
                // Si sólo contestó «ver», «ok» o parecido para leer lo pendiente, no hay nada más que atender.
                if ($pend) { $atendidos[] = 'pendientes'; if (empty($m['audio_id']) && preg_match('/^\W*(ver|ok|okey|dale|hola|buenas|listo|mostrar|leer|s[ií])\W*$/iu', trim((string) $m['texto']))) continue; }
            }
            // Fase 111: si escribe (o manda un audio) alguien del equipo, es una orden para Bigcito: propone y con «sí» ejecuta.
            if (\App\Services\IA\BigcitoWhatsappService::activo($b) && ($bg = app(\App\Services\IA\BigcitoWhatsappService::class)->atender($b, $m))) {
                if ($m['de']) $wa->responder($b, $m['de'], $bg['respuesta']);
                $atendidos[] = 'bigcito';
                continue;
            }
            if (trim($m['texto']) === '') continue;
            // Fase 120.5: si un cliente con deuda vencida pide pagar de a poco (o ya venía hablándolo), lo atiende el cobrador.
            if (\App\Services\Ventas\CobradorService::activo($b) && ($co = app(\App\Services\Ventas\CobradorService::class)->atender($b, $m['de'], $m['texto']))) {
                if ($m['de']) $wa->responder($b, $m['de'], $co['respuesta'], ['texto_libre' => true]);
                $atendidos[] = 'cobrador';
                continue;
            }
            // Fase 60: si es una consulta (saldo, pedido, stock, reclamo, hablar con alguien) la atiende Bigcito y no sigue como pedido.
            if (\App\Services\Canales\AtencionClientesService::activo($b) && ($at = app(\App\Services\Canales\AtencionClientesService::class)->atender($b, $m['de'], $m['texto']))) {
                if (($ws['auto_responder'] ?? true) && $m['de']) $wa->responder($b, $m['de'], $at['respuesta']);
                $atendidos[] = $at['intencion'];
                continue;
            }
            // Fase 53.2: en un local gastronómico el pedido entra directo a Big Restó como comanda (delivery o para retirar).
            if (\App\Services\Gastronomia\PedidoRestoService::activo($b)) {
                // Fase 91: "quiero reservar para 4 el sábado a las 21" → reserva confirmada o lista de espera, sin pasar por la carta.
                if ($res = app(\App\Services\Gastronomia\ReservasService::class)->responderWhatsapp($b, $m['de'], $m['texto'])) { if (($ws['auto_responder'] ?? true) && $m['de']) $wa->responder($b, $m['de'], $res['respuesta']); $atendidos[] = 'reserva'; continue; }
                $i = $wa->interpretar($b, $m['texto'], true);
                if (\App\Services\Canales\AtencionClientesService::activo($b) && ($at = app(\App\Services\Canales\AtencionClientesService::class)->atenderInterpretado($b, $m['de'], $m['texto'], $i))) { if (($ws['auto_responder'] ?? true) && $m['de']) $wa->responder($b, $m['de'], $at['respuesta']); $atendidos[] = $at['intencion']; continue; } // Fase 60
                $r = ($ws['auto_pedidos'] ?? true) && $i['items'] ? app(\App\Services\Gastronomia\PedidoRestoService::class)->desdeWhatsapp($b, $i, $m['de']) : null;
                $c = $r['comanda'] ?? null;
                if ($c) $creados[] = $c->numeroFormateado();
                $faltan = $r ? collect($r['faltan'])->map(fn($f) => "¿{$f['plato']}: " . implode(', ', $f['opciones']) . '?')->implode(' ') : '';
                $envio = $c && $c->tipo === 'delivery' && $c->zona ? "\nEnvío a {$c->zona}: $ " . number_format((float) $c->costo_envio, 0, ',', '.') . (($z = \App\Services\Gastronomia\DeliveryService::zona($b, $c->zona)) && ! empty($z['demora']) ? " (unos {$z['demora']} minutos)" : '') : ''; // Fase 91
                if (($ws['auto_responder'] ?? true) && $m['de'] && (! empty($i['respuesta']) || $faltan)) $wa->responder($b, $m['de'], trim(($i['respuesta'] ?? '') . ($faltan ? "\n{$faltan}" : '') . ($c ? "\nTu pedido es el {$c->numeroFormateado()}.{$envio}\nTe avisamos cuando salga." : '')));
                if (! $c && $m['de']) \App\Models\Alerta::emitir(['business_id' => $b->id, 'modulo' => 'gastronomia', 'tipo' => 'whatsapp_sin_pedido', 'severidad' => 'info', 'titulo' => 'Mensaje de WhatsApp sin pedido: ' . $m['de'], 'detalle' => mb_substr($m['texto'], 0, 300), 'url' => '/gastronomia']);
                continue;
            }
            $i = $wa->interpretar($b, $m['texto']);
            if (\App\Services\Canales\AtencionClientesService::activo($b) && ($at = app(\App\Services\Canales\AtencionClientesService::class)->atenderInterpretado($b, $m['de'], $m['texto'], $i))) { if (($ws['auto_responder'] ?? true) && $m['de']) $wa->responder($b, $m['de'], $at['respuesta']); $atendidos[] = $at['intencion']; continue; } // Fase 60
            $p = ($ws['auto_pedidos'] ?? true) && $i['items'] ? $wa->crearDesdeTexto($b, $m['texto'], $m['de'], $i) : null;
            if ($p) $creados[] = $p->numeroFormateado();
            if (($ws['auto_responder'] ?? true) && $m['de'] && ! empty($i['respuesta'])) $wa->responder($b, $m['de'], $i['respuesta'] . ($p ? "\nTu pedido es el {$p->numeroFormateado()}. Seguilo acá: {$p->urlPublica()}" : ''));
            if (! $p && $m['de']) \App\Models\Alerta::emitir(['business_id' => $b->id, 'modulo' => 'comprobantes', 'tipo' => 'whatsapp_sin_pedido', 'severidad' => 'info', 'titulo' => 'Mensaje de WhatsApp sin pedido: ' . $m['de'], 'detalle' => mb_substr($m['texto'], 0, 140), 'url' => '/comprobantes/pedidos']);
        }
        return response()->json(['ok' => true, 'pedidos' => $creados, 'atendidos' => $atendidos]);
    }
}
