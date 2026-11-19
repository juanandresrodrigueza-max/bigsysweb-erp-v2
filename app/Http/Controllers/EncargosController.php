<?php

namespace App\Http\Controllers;

use App\Models\Encargo;
use App\Models\User;
use App\Services\Equipo\EncargosService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 120.6: encargos entre personas del equipo.
class EncargosController extends Controller
{
    public function __construct(private EncargosService $svc) {}

    public function index(Request $request)
    {
        $u = $request->user();
        $base = fn() => Encargo::where('business_id', $u->business_id)->with(['de:id,name', 'para:id,name']);
        return Inertia::render('Encargos/Index', [
            'paraMi' => $this->svc->paraMi($u)->map(fn($e) => $this->svc->resumir($e->load('para:id,name')))->values(),
            'encargue' => $base()->where('de_user_id', $u->id)->where('para_user_id', '!=', $u->id)->where('estado', 'pendiente')->orderByRaw('vence_en is null')->orderBy('vence_en')->orderBy('id')->limit(100)->get()->map(fn($e) => $this->svc->resumir($e))->values(),
            'cerrados' => $base()->where(fn($w) => $w->where('de_user_id', $u->id)->orWhere('para_user_id', $u->id))->where('estado', '!=', 'pendiente')->orderByDesc('updated_at')->limit(20)->get()->map(fn($e) => $this->svc->resumir($e))->values(),
            'equipo' => $this->svc->equipo($u)->map(fn($x) => ['id' => $x->id, 'nombre' => $x->name, 'whatsapp' => (bool) $x->mobile, 'yo' => $x->id === $u->id])->values(),
            'whatsappEmpresa' => ! empty($u->business->whatsapp_settings['token']) && ! empty($u->business->whatsapp_settings['phone_id']),
            'yo' => $u->id,
        ]);
    }

    public function guardar(Request $request)
    {
        $d = $request->validate(['para_user_id' => 'required|integer', 'texto' => 'required|string|max:300', 'detalle' => 'nullable|string|max:2000', 'vence_en' => 'nullable|date']);
        $para = User::where('business_id', $request->user()->business_id)->findOrFail($d['para_user_id']);
        $e = $this->svc->crear($request->user(), $para, $d['texto'], ! empty($d['vence_en']) ? Carbon::parse($d['vence_en']) : null, $d['detalle'] ?? null, null, 'pantalla');
        return back()->with('success', $para->id === $request->user()->id ? 'Anotado.' : 'Encargo enviado a ' . $para->name . ($e->aviso === 'whatsapp' ? ' por WhatsApp.' : ' (le llega a la campana).'));
    }

    public function hecho(Request $request, int $id)
    {
        $d = $request->validate(['nota' => 'nullable|string|max:300']);
        $this->svc->hecho(Encargo::findOrFail($id), $request->user(), $d['nota'] ?? null);
        return back()->with('success', 'Encargo hecho.');
    }

    public function cancelar(Request $request, int $id)
    {
        $this->svc->cancelar(Encargo::findOrFail($id), $request->user());
        return back()->with('success', 'Encargo cancelado.');
    }

    public function reabrir(Request $request, int $id)
    {
        $this->svc->reabrir(Encargo::findOrFail($id), $request->user());
        return back()->with('success', 'Encargo reabierto.');
    }
}
