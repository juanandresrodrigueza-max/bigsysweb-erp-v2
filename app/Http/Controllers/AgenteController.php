<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

// Agente IA flotante (Bigcito): la cabeza está en App\Services\IA\BigcitoService; acá sólo entra y sale HTTP.
class AgenteController extends Controller
{
    public function chat(Request $request)
    {
        $data = $request->validate([
            'mensaje'   => 'required|string|max:4000',
            'historial' => 'nullable|array|max:20',
            'historial.*.rol'   => 'required_with:historial|in:user,assistant',
            'historial.*.texto' => 'required_with:historial|string|max:4000',
            'pantalla'  => 'nullable|string|max:200',
            'url'       => 'nullable|string|max:500', // Fase 119.1: la dirección que tiene abierta, para saber qué está viendo
            'imagen'    => 'nullable|string|max:7500000', // Fase 40.3: una foto con la pregunta (data URL)
            'vivo'      => 'nullable|boolean', // Fase 120.4: viene de una conversación en vivo (se contesta corto, para decirlo)
        ]);
        if (! empty($data['vivo'])) $data['extra_sistema'] = trim(($data['extra_sistema'] ?? '') . "\nLa persona está hablando con vos por voz, en vivo: contestá en una o dos frases cortas, sin listas, tablas ni símbolos; los números como se dicen hablando. El detalle queda en la pantalla.");
        unset($data['vivo']);
        return response()->json(app(\App\Services\IA\BigcitoService::class)->responder($request->user(), $data));
    }

    // Fase 120.4: abre una conversación en vivo (devuelve la llave de un solo uso) y anota cuánto duró al cortar.
    public function vivo(Request $request, \App\Services\IA\VozVivoService $svc)
    {
        $r = $svc->abrir($request->user());
        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function vivoFin(Request $request, \App\Services\IA\VozVivoService $svc)
    {
        $d = $request->validate(['segundos' => 'required|integer|min:0|max:3600']);
        $svc->anotar($request->user(), (int) $d['segundos']);
        return response()->json(['ok' => true]);
    }

    // Fase 40.3: un audio grabado se transcribe (OpenAI o Gemini) y vuelve como texto; el panel lo manda como pregunta por voz.
    public function audio(Request $request)
    {
        $request->validate(['audio' => 'required|file|max:12288', 'mime' => 'nullable|string|max:60']);
        $f = $request->file('audio');
        // El navegador sabe mejor qué grabó (Safari graba audio/mp4; por contenido a veces se detecta como video).
        $mime = $request->input('mime') && str_starts_with((string) $request->input('mime'), 'audio/') ? explode(';', $request->input('mime'))[0] : ($f->getMimeType() ?: 'audio/webm');
        $texto = app(\App\Services\IA\VozFotoService::class)->transcribir($request->user()->business()->first(), $f->getRealPath(), $mime);
        return response()->json(['texto' => $texto]);
    }

    // Fase 119.2: Bigcito contesta con voz natural (OpenAI o Gemini). Sin clave de voz devuelve 204 y el panel usa la del navegador.
    public function voz(Request $request)
    {
        $d = $request->validate(['texto' => 'required|string|max:4000']);
        $r = app(\App\Services\IA\VozFotoService::class)->hablar($request->user()->business()->first(), $d['texto']);
        if (! $r) return response()->noContent();
        return response($r['audio'], 200, ['Content-Type' => $r['mime'], 'Cache-Control' => 'private, max-age=600']);
    }

    // El usuario confirmó una propuesta: se ejecuta con sus permisos (vueltos a chequear) y queda auditado. Mismo catálogo que MCP.
    public function ejecutar(Request $request, \App\Mcp\Catalogo $cat)
    {
        $d = $request->validate(['propuesta_id' => 'required|string|max:26', 'cancelar' => 'nullable|boolean']);
        $r = $d['cancelar'] ?? false ? $cat->cancelar($request->user(), $d['propuesta_id']) : $cat->llamar($request->user(), 'confirmar_accion', ['propuesta_id' => $d['propuesta_id']], 'asistente');
        if (isset($r['error'])) return response()->json(['message' => $r['error']], str_contains($r['error'], 'permiso') ? 403 : 422);
        return response()->json($r);
    }

}
