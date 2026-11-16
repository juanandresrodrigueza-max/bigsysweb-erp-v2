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
            'imagen'    => 'nullable|string|max:7500000', // Fase 40.3: una foto con la pregunta (data URL)
        ]);
        return response()->json(app(\App\Services\IA\BigcitoService::class)->responder($request->user(), $data));
    }

    // Fase 40.3: un audio grabado se transcribe (OpenAI o Gemini) y vuelve como texto; el panel lo manda como pregunta por voz.
    public function audio(Request $request)
    {
        $request->validate(['audio' => 'required|file|max:12288']);
        $f = $request->file('audio');
        $texto = app(\App\Services\IA\VozFotoService::class)->transcribir($request->user()->business()->first(), $f->getRealPath(), $f->getMimeType() ?: 'audio/webm');
        return response()->json(['texto' => $texto]);
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
