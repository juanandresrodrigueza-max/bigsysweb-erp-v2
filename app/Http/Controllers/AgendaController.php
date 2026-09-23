<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

// Agenda de turnos para servicios (peluquería, taller, consultorio, estudio): semana, turnos por profesional, recordatorio y cobro.
class AgendaController extends Controller
{
    public const ESTADOS = ['pending' => 'Pendiente', 'confirmed' => 'Confirmado', 'completed' => 'Atendido', 'cancelled' => 'Cancelado', 'no_show' => 'No vino'];

    public function index(Request $request)
    {
        $desde = ($request->semana ? Carbon::parse($request->semana) : today())->startOfWeek();
        $hasta = $desde->copy()->addDays(6)->endOfDay();
        $b = $request->user()->business;
        $turnos = Booking::with('contact:id,name,phone', 'service:id,name,price', 'assignedUser:id,name', 'comprobante:id,tipo,punto_venta,numero,saldo')->whereBetween('starts_at', [$desde, $hasta])->when($request->profesional, fn($q, $p) => $q->where('assigned_to', $p))->orderBy('starts_at')->get();
        return Inertia::render('Agenda/Index', [
            'semana' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'dias' => collect(range(0, 6))->map(fn($i) => ['fecha' => $desde->copy()->addDays($i)->toDateString(), 'label' => $desde->copy()->addDays($i)->locale('es')->isoFormat('ddd D'), 'hoy' => $desde->copy()->addDays($i)->isToday()])],
            'turnos' => $turnos->map(fn($t) => ['id' => $t->id, 'fecha' => $t->starts_at->toDateString(), 'hora' => $t->starts_at->format('H:i'), 'fin' => $t->ends_at?->format('H:i'), 'minutos' => (int) $t->starts_at->diffInMinutes($t->ends_at ?? $t->starts_at->copy()->addHour()), 'cliente' => $t->contact?->name ?? $t->nombre ?? 'Sin nombre', 'contact_id' => $t->contact_id, 'telefono' => $t->telefono ?? $t->contact?->phone, 'servicio' => $t->service?->name, 'service_id' => $t->service_id, 'profesional' => $t->assignedUser?->name, 'assigned_to' => $t->assigned_to, 'estado' => $t->status, 'precio' => (float) $t->price, 'notas' => $t->notes, 'origen' => $t->origen, 'comprobante' => $t->comprobante ? $t->comprobante->nombreTipo() . ' ' . $t->comprobante->numeroFormateado() : null, 'comprobante_id' => $t->comprobante_id, 'recordado' => (bool) $t->recordado_en, 'color' => $t->color]),
            'profesionales' => User::where('business_id', $b->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'servicios' => Product::where('active', true)->where('tipo', 'servicio')->orderBy('name')->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price, 'iva' => (float) $p->iva]),
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'filtros' => $request->only('profesional'), 'estados' => self::ESTADOS,
            'kpis' => ['hoy' => Booking::whereDate('starts_at', today())->whereIn('status', ['pending', 'confirmed'])->count(), 'semana' => $turnos->whereIn('status', ['pending', 'confirmed', 'completed'])->count(), 'sin_confirmar' => $turnos->where('status', 'pending')->count(), 'facturable' => (float) $turnos->where('status', 'completed')->whereNull('comprobante_id')->sum('price')],
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $d = $request->validate(['contact_id' => 'nullable|integer', 'nombre' => 'nullable|string|max:120', 'telefono' => 'nullable|string|max:40', 'service_id' => 'nullable|integer', 'assigned_to' => 'nullable|integer', 'fecha' => 'required|date', 'hora' => 'required|date_format:H:i', 'minutos' => 'required|integer|min:5|max:600', 'precio' => 'nullable|numeric|min:0', 'notas' => 'nullable|string|max:300', 'estado' => 'nullable|in:' . implode(',', array_keys(self::ESTADOS))]);
        $inicio = Carbon::parse($d['fecha'] . ' ' . $d['hora']);
        $fin = $inicio->copy()->addMinutes($d['minutos']);
        if ($d['assigned_to'] ?? null) {
            $choque = Booking::where('assigned_to', $d['assigned_to'])->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '<', $fin)->where('ends_at', '>', $inicio)->when($id, fn($q) => $q->where('id', '!=', $id))->first();
            if ($choque) return back()->withErrors(['hora' => 'Ese profesional ya tiene un turno a las ' . $choque->starts_at->format('H:i') . ' (' . ($choque->contact?->name ?? $choque->nombre) . ').']);
        }
        $srv = ! empty($d['service_id']) ? Product::find($d['service_id']) : null;
        $t = $id ? Booking::findOrFail($id) : new Booking(['business_id' => $request->user()->business_id, 'location_id' => $request->user()->current_location_id, 'origen' => 'manual', 'token' => \Illuminate\Support\Str::random(40), 'status' => 'confirmed']);
        $t->fill(['contact_id' => $d['contact_id'] ?? null, 'nombre' => $d['nombre'] ?? null, 'telefono' => $d['telefono'] ?? null, 'service_id' => $srv?->id, 'assigned_to' => $d['assigned_to'] ?? null, 'starts_at' => $inicio, 'ends_at' => $fin, 'price' => $d['precio'] ?? ($srv ? (float) $srv->price : 0), 'notes' => $d['notas'] ?? null, 'personas' => 1] + (isset($d['estado']) ? ['status' => $d['estado']] : []))->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $t, 'Turno ' . $inicio->format('d/m H:i') . ' · ' . ($t->contact?->name ?? $t->nombre));
        return back()->with('success', $id ? 'Turno actualizado.' : 'Turno agendado para el ' . $inicio->format('d/m') . ' a las ' . $inicio->format('H:i') . '.');
    }

    public function estado(Request $request, int $id)
    {
        $d = $request->validate(['estado' => 'required|in:' . implode(',', array_keys(self::ESTADOS))]);
        $t = Booking::findOrFail($id); $t->update(['status' => $d['estado']]);
        return back()->with('success', 'Turno ' . strtolower(self::ESTADOS[$d['estado']]) . '.');
    }

    public function recordar(int $id)
    {
        $t = Booking::with('contact', 'service', 'assignedUser')->findOrFail($id);
        $tel = $t->telefono ?? $t->contact?->phone; abort_if(! $tel, 422, 'El turno no tiene teléfono.');
        $texto = "Hola " . ($t->contact?->name ?? $t->nombre) . "! Te recordamos tu turno" . ($t->service ? " de {$t->service->name}" : '') . " el " . $t->starts_at->locale('es')->isoFormat('dddd D [de] MMMM') . " a las " . $t->starts_at->format('H:i') . ($t->assignedUser ? " con {$t->assignedUser->name}" : '') . ". Si no podés venir, avisanos. ¡Gracias! " . $t->business->name;
        $r = app(\App\Services\Canales\WhatsappPedidosService::class)->responder($t->business, $tel, $texto);
        $t->update(['recordado_en' => now()]);
        return $r['enviado'] ? back()->with('success', 'Recordatorio enviado por WhatsApp.') : back()->with('success', 'Abrí WhatsApp para enviar el recordatorio.')->with('abrir', $r['link']);
    }

    // Cobrar: arma la factura del servicio (contado) y la deja lista para cobrar.
    public function cobrar(Request $request, int $id, ComprobanteService $svc)
    {
        $t = Booking::with('contact', 'service')->findOrFail($id);
        abort_if($t->comprobante_id, 422, 'Este turno ya está facturado.');
        $contact = $t->contact ?? Contact::customers()->where('name', 'Consumidor Final')->first();
        $ri = ($request->user()->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $c = $svc->guardarBorrador(['contact_id' => $contact?->id, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'notas' => 'Turno del ' . $t->starts_at->format('d/m/Y H:i'), 'items' => [['product_id' => $t->service_id, 'descripcion' => $t->service?->name ?? 'Servicio', 'cantidad' => 1, 'precio_unit' => $t->service && $ri ? (float) $t->price / (1 + (float) $t->service->iva / 100) : (float) $t->price, 'descuento' => 0, 'alicuota_iva' => $t->service ? (float) $t->service->iva : 21]]]);
        $c = $svc->emitir($c);
        $t->update(['comprobante_id' => $c->id, 'status' => 'completed']);
        return redirect("/comprobantes/{$c->id}")->with('success', "{$c->nombreTipo()} {$c->numeroFormateado()} emitida. Registrá el cobro.");
    }
}
