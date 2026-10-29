<?php

namespace App\Http\Controllers\Comprobantes;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\OrdenEntrega;
use App\Models\OrdenEntregaItem;
use App\Services\Ventas\RepartoService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 37.1 a 37.3: armar el viaje, el recorrido y los links del chofer y del cliente.
class RepartoController extends Controller
{
    public function __construct(private RepartoService $svc) {}

    public function armar(Request $request)
    {
        $loc = BusinessLocation::find($request->user()->current_location_id);
        return Inertia::render('Comprobantes/ArmarViaje', ['pendientes' => $this->svc->pendientes(), 'origen' => ['nombre' => $loc?->name, 'lat' => $loc?->lat !== null ? (float) $loc->lat : null, 'lng' => $loc?->lng !== null ? (float) $loc->lng : null, 'direccion' => trim(($loc?->address ?? '') . ' ' . ($loc?->city ?? ''))]]);
    }

    public function crear(Request $request)
    {
        $d = $request->validate(['fecha' => 'required|date', 'repartidor' => 'nullable|string|max:80', 'vehiculo' => 'nullable|string|max:40', 'notas' => 'nullable|string|max:500', 'ordenar' => 'boolean',
            'paradas' => 'required|array|min:1', 'paradas.*.tipo' => 'required|in:comprobante,acopio,parada', 'paradas.*.id' => 'nullable|integer', 'paradas.*.cantidades' => 'nullable|array', 'paradas.*.nombre' => 'nullable|string|max:120', 'paradas.*.direccion' => 'nullable|string|max:200', 'paradas.*.contact_id' => 'nullable|integer']);
        $oe = $this->svc->armar($d);
        return redirect("/comprobantes/entregas/{$oe->id}")->with('success', "Viaje {$oe->numeroFormateado()} armado con " . $oe->items->count() . ' paradas.');
    }

    // El depósito con su ubicación (de donde sale el camión).
    public function origen(Request $request)
    {
        $d = $request->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180']);
        BusinessLocation::findOrFail($request->user()->current_location_id)->update($d);
        return back()->with('success', 'Ubicación del depósito guardada.');
    }

    public function ordenar(int $id)
    {
        $r = $this->svc->ordenar(OrdenEntrega::findOrFail($id));
        return back()->with('success', "Paradas ordenadas: unos {$r['km']} km" . ($r['sin_ubicacion'] ? " ({$r['sin_ubicacion']} sin ubicación al final)" : '') . '.');
    }

    public function reordenar(Request $request, int $id)
    {
        $d = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $this->svc->reordenar(OrdenEntrega::findOrFail($id), $d['ids']);
        return back();
    }

    // ---- Público: el chofer ----
    private function hoja(string $token): OrdenEntrega { return OrdenEntrega::withoutGlobalScopes()->where('token_chofer', $token)->firstOrFail(); }

    public function chofer(string $token)
    {
        $oe = $this->hoja($token); $b = \App\Models\Business::find($oe->business_id);
        $items = OrdenEntregaItem::with(['comprobante' => fn($q) => $q->withoutGlobalScopes()->with(['items', 'contact' => fn($c) => $c->withoutGlobalScopes()]), 'acopio' => fn($q) => $q->withoutGlobalScopes()->with('items'), 'contact' => fn($q) => $q->withoutGlobalScopes()])->where('orden_entrega_id', $oe->id)->orderBy('orden')->get();
        return view('entregas.chofer', ['oe' => $oe, 'b' => $b, 'items' => $items->map(fn($i) => ['i' => $i, 'lleva' => $this->svc->lleva($i), 'tel' => $i->contact?->mobile ?: $i->contact?->phone])->all(), 'maps' => $this->svc->maps($oe)]);
    }

    public function salir(string $token) { $this->svc->salir($this->hoja($token)); return back(); }

    public function posicion(Request $request, string $token)
    {
        $d = $request->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180']);
        $this->svc->posicion($this->hoja($token), (float) $d['lat'], (float) $d['lng']);
        return response()->json(['ok' => true]);
    }

    private function parada(string $token, int $item): OrdenEntregaItem
    {
        $oe = $this->hoja($token);
        return OrdenEntregaItem::with(['hoja' => fn($q) => $q->withoutGlobalScopes(), 'comprobante' => fn($q) => $q->withoutGlobalScopes()->with('items'), 'acopio' => fn($q) => $q->withoutGlobalScopes()->with('items')])->where('orden_entrega_id', $oe->id)->findOrFail($item);
    }

    public function entregue(Request $request, string $token, int $item)
    {
        $d = $request->validate(['recibio' => 'required|string|max:120', 'foto' => 'nullable|file|max:12288|mimes:jpg,jpeg,png,webp,heic', 'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric']);
        $it = $this->parada($token, $item);
        $this->conEmpresa($it->hoja, function () use ($request, $it, $d) {
            $foto = $request->file('foto')?->store("entregas/{$it->hoja->business_id}/{$it->hoja->id}");
            $this->svc->entregue($it, $d['recibio'], $foto, isset($d['lat']) ? (float) $d['lat'] : null, isset($d['lng']) ? (float) $d['lng'] : null);
        });
        return back()->with('ok', 'Entrega registrada.');
    }

    public function noSePudo(Request $request, string $token, int $item)
    {
        $d = $request->validate(['motivo' => 'required|string|max:200']);
        $this->svc->noSePudo($this->parada($token, $item), $d['motivo']);
        return back()->with('ok', 'Anotado: vuelve a pendientes.');
    }

    // El chofer no tiene usuario: lo que mueve stock y arma remitos corre a nombre de quien armó el viaje.
    private function conEmpresa(OrdenEntrega $oe, \Closure $fn): void
    {
        $u = \App\Models\User::find($oe->user_id); $antes = auth()->user();
        auth()->setUser($u);
        try { $fn(); } finally { if ($antes) auth()->setUser($antes); else auth()->forgetGuards(); }
    }

    // ---- Público: el cliente ----
    public function seguimiento(Request $request, string $token)
    {
        $it = OrdenEntregaItem::with(['hoja' => fn($q) => $q->withoutGlobalScopes()])->where('token', $token)->firstOrFail();
        $s = $this->svc->seguimiento($it);
        if ($request->wantsJson()) return response()->json($s);
        return view('entregas.seguimiento', ['s' => $s, 'token' => $token]);
    }
}
