<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Models\Campana;
use App\Models\Envio;
use App\Services\Ventas\CampanasService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 96: Clientes → Mensajes a clientes: una promoción, un aviso de cierre, un cambio de precios, a todos o a un filtro, por mail o WhatsApp.
class CampanasController extends Controller
{
    public function index(Request $request, CampanasService $svc)
    {
        $b = $request->user()->business;
        return Inertia::render('Clientes/Campanas', ['campanas' => $svc->listado($b), 'variables' => Campana::VARIABLES, 'canales' => Campana::CANALES,
            'whatsappApi' => ! empty($b->whatsapp_settings['token']), 'tipos' => \App\Models\TipoCliente::orderBy('nombre')->get(['id', 'nombre']), 'conteo' => $svc->contar($b, $request->only('estado', 'tipo_cliente_id', 'buscar')), 'maximo' => CampanasService::MAXIMO]);
    }

    public function contar(Request $request, CampanasService $svc)
    {
        return response()->json($svc->contar($request->user()->business, $request->only('estado', 'tipo_cliente_id', 'buscar', 'ids')));
    }

    public function enviar(Request $request, CampanasService $svc)
    {
        $d = $request->validate(['canal' => 'required|in:mail,whatsapp', 'asunto' => 'nullable|string|max:150', 'texto' => 'required|string|max:2000', 'estado' => 'nullable|in:activos,deudores,inactivos', 'tipo_cliente_id' => 'nullable|integer', 'buscar' => 'nullable|string|max:80', 'ids' => 'nullable|array|max:500']);
        if ($d['canal'] === 'mail' && empty($d['asunto'])) return back()->withErrors(['asunto' => 'Para el mail hace falta un asunto.']);
        $c = $svc->crear($request->user(), $d['canal'], $d['texto'], $d['asunto'] ?? null, array_filter($request->only('estado', 'tipo_cliente_id', 'buscar', 'ids')));
        return redirect('/clientes/campanas')->with('success', "Mensaje en camino a {$c->destinatarios} cliente(s)." . ($d['canal'] === 'whatsapp' && empty($request->user()->business->whatsapp_settings['token']) ? ' Sin la API de WhatsApp, abajo quedan los links para mandarlos uno por uno.' : ''));
    }

    public function marcarEnviado(int $id)
    {
        $e = Envio::findOrFail($id);
        $e->update(['estado' => 'enviado', 'enviado_en' => now()]);
        if ($e->campana_id) Campana::find($e->campana_id)?->recontar();
        return back();
    }
}
