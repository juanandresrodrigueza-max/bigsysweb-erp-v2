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
            'kpis' => ['hoy' => Booking::whereDate('starts_at', today())->whereIn('status', ['pending', 'confirmed'])->count(), 'personas_hoy' => (int) Booking::whereDate('starts_at', today())->whereIn('status', ['pending', 'confirmed'])->sum('personas'), 'espera' => Booking::where('status', 'waitlist')->where('starts_at', '>=', today())->count(), 'pendientes' => Booking::where('status', 'pending')->where('starts_at', '>=', now())->count()],
            // Fase 91: la lista de espera (de hoy en adelante) y la capacidad por turno.
            'espera' => Booking::where('status', 'waitlist')->where('starts_at', '>=', today())->orderBy('starts_at')->orderBy('created_at')->get()->map(fn($r) => ['id' => $r->id, 'fecha' => $r->starts_at->format('d/m'), 'hora' => $r->starts_at->format('H:i'), 'nombre' => $r->nombre, 'telefono' => $r->telefono, 'personas' => $r->personas, 'origen' => $r->origen, 'desde' => $r->created_at->diffForHumans(), 'hay_lugar' => app(\App\Services\Gastronomia\ReservasService::class)->hayLugar($request->user()->business, $r->starts_at, (int) $r->personas)]),
            'capacidad' => app(\App\Services\Gastronomia\ReservasService::class)->capacidad($request->user()->business),
            'urlReservas' => url('/r/' . $cfg['slug']), 'reservasActivas' => (bool) $cfg['reservas_activas'], 'urlMenu' => url('/m/' . $cfg['slug']), 'menuActivo' => (bool) $cfg['menu_activo'],
            'mesas' => Mesa::where('activa', true)->orderBy('sector')->orderBy('orden')->get()->map(fn($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'sector' => $m->sector ?: 'Salón', 'url' => $m->urlQr($cfg['slug'])]), // Fase 95: el QR lleva el token de la mesa, no el id
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
        $d = $request->validate(['estado' => 'required|in:pending,confirmed,cancelled,completed,no_show,waitlist']);
        $r = Booking::findOrFail($id); $antes = $r->status; $r->update(['status' => $d['estado']]);
        // Fase 91: si se cancela una reserva, la primera de la lista de espera que entra pasa sola y se le avisa.
        $liberadas = in_array($antes, ['pending', 'confirmed'], true) && in_array($d['estado'], ['cancelled', 'no_show'], true) ? app(\App\Services\Gastronomia\ReservasService::class)->liberar($request->user()->business, $r) : [];
        if ($liberadas) return back()->with('success', 'Reserva cancelada. Pasó de la lista de espera: ' . collect($liberadas)->map(fn($x) => "{$x->nombre} ({$x->personas})")->implode(', ') . '. Ya se le avisó por WhatsApp.');
        \App\Models\Alerta::where('tipo', 'reserva_nueva')->where('detalle', 'like', '%' . $r->telefono . '%')->update(['resuelta_en' => now()]);
        return back()->with('success', 'Reserva ' . ['pending' => 'pendiente', 'confirmed' => 'confirmada', 'cancelled' => 'cancelada', 'completed' => 'completada', 'no_show' => 'marcada como no vino', 'waitlist' => 'en lista de espera'][$d['estado']] . '.');
    }
}
