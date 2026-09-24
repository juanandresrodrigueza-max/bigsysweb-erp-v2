<?php

namespace App\Http\Controllers\Gastronomia;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Mesa;
use App\Services\Canales\TiendaService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

// Reservas del salón (las que entran por la web y las que se cargan a mano) y códigos QR de las mesas para el menú.
class ReservasController extends Controller
{
    public function index(Request $request, TiendaService $tienda)
    {
        $desde = $request->desde ?: today()->toDateString();
        $hasta = $request->hasta ?: today()->addDays(7)->toDateString();
        $cfg = $tienda->config($request->user()->business);
        return Inertia::render('Gastronomia/Reservas', [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'reservas' => Booking::whereBetween('starts_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])->orderBy('starts_at')->get()->map(fn($r) => ['id' => $r->id, 'fecha' => $r->starts_at->format('d/m'), 'hora' => $r->starts_at->format('H:i'), 'dia' => $r->starts_at->locale('es')->isoFormat('dddd D'), 'nombre' => $r->nombre ?: ($r->contact?->name ?? 'Sin nombre'), 'telefono' => $r->telefono, 'personas' => $r->personas, 'estado' => $r->status, 'origen' => $r->origen, 'notas' => $r->notes]),
            'kpis' => ['hoy' => Booking::whereDate('starts_at', today())->whereIn('status', ['pending', 'confirmed'])->count(), 'personas_hoy' => (int) Booking::whereDate('starts_at', today())->whereIn('status', ['pending', 'confirmed'])->sum('personas'), 'pendientes' => Booking::where('status', 'pending')->where('starts_at', '>=', now())->count()],
            'urlReservas' => url('/r/' . $cfg['slug']), 'reservasActivas' => (bool) $cfg['reservas_activas'], 'urlMenu' => url('/m/' . $cfg['slug']), 'menuActivo' => (bool) $cfg['menu_activo'],
            'mesas' => Mesa::where('activa', true)->orderBy('sector')->orderBy('orden')->get()->map(fn($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'sector' => $m->sector ?: 'Salón', 'url' => url('/m/' . $cfg['slug'] . '?mesa=' . $m->id)]),
        ]);
    }

    public function guardar(Request $request)
    {
        $d = $request->validate(['nombre' => 'required|string|max:120', 'telefono' => 'nullable|string|max:40', 'fecha' => 'required|date', 'hora' => 'required|date_format:H:i', 'personas' => 'required|integer|min:1|max:50', 'notas' => 'nullable|string|max:300']);
        $inicio = \Carbon\Carbon::parse($d['fecha'] . ' ' . $d['hora']);
        Booking::create(['business_id' => $request->user()->business_id, 'location_id' => $request->user()->current_location_id, 'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addHours(2), 'status' => 'confirmed', 'price' => 0, 'notes' => $d['notas'] ?? null, 'nombre' => $d['nombre'], 'telefono' => $d['telefono'] ?? null, 'personas' => $d['personas'], 'origen' => 'manual', 'token' => Str::random(40)]);
        return back()->with('success', 'Reserva cargada.');
    }

    public function estado(Request $request, int $id)
    {
        $d = $request->validate(['estado' => 'required|in:pending,confirmed,cancelled,completed,no_show']);
        $r = Booking::findOrFail($id); $r->update(['status' => $d['estado']]);
        \App\Models\Alerta::where('tipo', 'reserva_nueva')->where('detalle', 'like', '%' . $r->telefono . '%')->update(['resuelta_en' => now()]);
        return back()->with('success', 'Reserva ' . ['pending' => 'pendiente', 'confirmed' => 'confirmada', 'cancelled' => 'cancelada', 'completed' => 'completada', 'no_show' => 'marcada como no vino'][$d['estado']] . '.');
    }
}
