<?php

namespace App\Http\Controllers\Hoteleria;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Estadia;
use App\Models\EstadiaConsumo;
use App\Models\Habitacion;
use App\Models\Product;
use App\Services\Hoteleria\HoteleriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

// Hotelería: cuadro de reservas, habitaciones, estadías con consumos, check-in y check-out.
class HoteleriaController extends Controller
{
    public function index(Request $request, HoteleriaService $svc)
    {
        $desde = ($request->desde ? Carbon::parse($request->desde) : today()->subDay())->startOfDay();
        $b = $request->user()->business;
        return Inertia::render('Hoteleria/Index', [
            'cuadro' => $svc->cuadro($desde, 14), 'desde' => $desde->toDateString(), 'kpis' => $svc->kpis($b), 'tipos' => Habitacion::TIPOS, 'estadosHab' => Habitacion::ESTADOS, 'estadosEst' => Estadia::ESTADOS,
            'llegadas' => Estadia::with('habitacion:id,nombre')->where('estado', 'reservada')->whereDate('desde', '<=', today())->orderBy('desde')->get()->map(fn($e) => ['id' => $e->id, 'nombre' => $e->nombre, 'habitacion' => $e->habitacion->nombre, 'desde' => $e->desde->format('d/m'), 'hasta' => $e->hasta->format('d/m'), 'personas' => $e->personas, 'telefono' => $e->telefono, 'atrasada' => $e->desde->lt(today())]),
            'salidas' => Estadia::with('habitacion:id,nombre')->where('estado', 'checkin')->whereDate('hasta', '<=', today())->orderBy('hasta')->get()->map(fn($e) => ['id' => $e->id, 'nombre' => $e->nombre, 'habitacion' => $e->habitacion->nombre, 'hasta' => $e->hasta->format('d/m'), 'saldo' => $e->saldo(), 'atrasada' => $e->hasta->lt(today())]),
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone', 'email', 'document']),
            'todasHabitaciones' => Habitacion::orderBy('orden')->orderBy('nombre')->get()->map(fn($h) => ['id' => $h->id, 'nombre' => $h->nombre, 'tipo' => $h->tipo, 'capacidad' => $h->capacidad, 'tarifa' => (float) $h->tarifa, 'piso' => $h->piso, 'estado' => $h->estado, 'activa' => $h->activa, 'orden' => $h->orden]),
        ]);
    }

    public function guardarHabitacion(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:40', 'tipo' => 'required|in:' . implode(',', array_keys(Habitacion::TIPOS)), 'capacidad' => 'required|integer|min:1|max:20', 'tarifa' => 'required|numeric|min:0', 'piso' => 'nullable|string|max:20', 'estado' => 'nullable|in:' . implode(',', array_keys(Habitacion::ESTADOS)), 'activa' => 'boolean', 'orden' => 'nullable|integer']);
        $u = $request->user();
        $h = $id ? Habitacion::findOrFail($id) : new Habitacion(['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id]);
        $h->fill($d)->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $h, "Habitación {$h->nombre}");
        return back()->with('success', 'Habitación guardada.');
    }

    public function estadoHabitacion(Request $request, int $id)
    {
        $d = $request->validate(['estado' => 'required|in:' . implode(',', array_keys(Habitacion::ESTADOS))]);
        Habitacion::findOrFail($id)->update(['estado' => $d['estado']]);
        return back()->with('success', 'Habitación ' . strtolower(Habitacion::ESTADOS[$d['estado']]) . '.');
    }

    public function reservar(Request $request, HoteleriaService $svc, ?int $id = null)
    {
        $d = $request->validate(['habitacion_id' => 'required|integer', 'contact_id' => 'nullable|integer', 'nombre' => 'nullable|string|max:120', 'telefono' => 'nullable|string|max:40', 'email' => 'nullable|email|max:120', 'documento' => 'nullable|string|max:30', 'personas' => 'required|integer|min:1', 'desde' => 'required|date', 'hasta' => 'required|date|after:desde', 'tarifa_noche' => 'nullable|numeric|min:0', 'origen' => 'nullable|string|max:20', 'notas' => 'nullable|string|max:500']);
        $e = $svc->reservar($d, $id ? Estadia::findOrFail($id) : null);
        return $id ? back()->with('success', 'Reserva actualizada.') : redirect("/hoteleria/estadias/{$e->id}")->with('success', "Reserva de {$e->nombre} en {$e->habitacion->nombre} confirmada.");
    }

    public function estadia(int $id)
    {
        $e = Estadia::with('habitacion', 'contact:id,name,phone,email', 'consumos.product:id,name', 'comprobante:id,tipo,punto_venta,numero,total,saldo')->findOrFail($id);
        return Inertia::render('Hoteleria/Estadia', [
            'estadia' => ['id' => $e->id, 'habitacion' => $e->habitacion->nombre, 'habitacion_id' => $e->habitacion_id, 'tipo' => Habitacion::TIPOS[$e->habitacion->tipo] ?? $e->habitacion->tipo, 'contact_id' => $e->contact_id, 'nombre' => $e->nombre, 'telefono' => $e->telefono ?: $e->contact?->phone, 'email' => $e->email ?: $e->contact?->email, 'documento' => $e->documento, 'personas' => $e->personas, 'desde' => $e->desde->toDateString(), 'hasta' => $e->hasta->toDateString(), 'noches' => $e->noches(), 'estado' => $e->estado, 'tarifa_noche' => (float) $e->tarifa_noche, 'senia' => (float) $e->senia, 'origen' => $e->origen, 'checkin_en' => $e->checkin_en?->format('d/m/Y H:i'), 'checkout_en' => $e->checkout_en?->format('d/m/Y H:i'), 'notas' => $e->notas, 'alojamiento' => $e->totalAlojamiento(), 'consumos_total' => $e->totalConsumos(), 'total' => $e->total(), 'saldo' => $e->saldo(), 'comprobante' => $e->comprobante ? ['id' => $e->comprobante->id, 'label' => $e->comprobante->nombreTipo() . ' ' . $e->comprobante->numeroFormateado(), 'saldo' => (float) $e->comprobante->saldo] : null,
                'consumos' => $e->consumos->map(fn($c) => ['id' => $c->id, 'fecha' => $c->fecha->format('d/m'), 'descripcion' => $c->descripcion, 'cantidad' => (float) $c->cantidad, 'precio_unit' => (float) $c->precio_unit, 'total' => (float) $c->total])],
            'estados' => Estadia::ESTADOS, 'productos' => Product::where('active', true)->orderBy('name')->limit(500)->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'precio' => $p->precioLista(1)]),
            'habitaciones' => Habitacion::where('activa', true)->orderBy('orden')->get(['id', 'nombre', 'tarifa', 'capacidad']), 'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'cuentas' => CuentaFondos::where('activa', true)->whereIn('tipo', ['caja', 'banco', 'billetera'])->where('moneda', 'ARS')->orderBy('tipo')->get(['id', 'nombre', 'tipo']),
        ]);
    }

    public function checkin(int $id, HoteleriaService $svc) { $svc->checkin(Estadia::findOrFail($id)); return back()->with('success', 'Check-in hecho. ¡Bienvenidos!'); }

    public function consumo(Request $request, int $id, HoteleriaService $svc)
    {
        $d = $request->validate(['product_id' => 'nullable|integer', 'descripcion' => 'nullable|string|max:200', 'cantidad' => 'required|numeric|gt:0', 'precio_unit' => 'nullable|numeric|min:0', 'fecha' => 'nullable|date']);
        $svc->consumo(Estadia::findOrFail($id), $d);
        return back()->with('success', 'Consumo cargado a la habitación.');
    }

    public function borrarConsumo(int $id, int $consumo)
    {
        $e = Estadia::findOrFail($id); abort_if($e->estado !== 'checkin', 422, 'La estadía ya cerró.');
        EstadiaConsumo::where('estadia_id', $id)->findOrFail($consumo)->delete();
        return back()->with('success', 'Consumo quitado.');
    }

    public function senia(Request $request, int $id, HoteleriaService $svc)
    {
        $d = $request->validate(['monto' => 'required|numeric|gt:0', 'cuenta_id' => 'required|integer']);
        $svc->senia(Estadia::findOrFail($id), (float) $d['monto'], CuentaFondos::findOrFail($d['cuenta_id']));
        return back()->with('success', 'Seña registrada.');
    }

    public function checkout(Request $request, int $id, HoteleriaService $svc)
    {
        $d = $request->validate(['condicion' => 'nullable|in:contado,cta_cte']);
        $c = $svc->checkout(Estadia::findOrFail($id), $d['condicion'] ?? 'contado');
        return redirect("/comprobantes/{$c->id}")->with('success', "Check-out hecho: {$c->nombreTipo()} {$c->numeroFormateado()} emitida. Cobrá y listo.");
    }

    public function cancelar(Request $request, int $id, HoteleriaService $svc)
    {
        $d = $request->validate(['estado' => 'nullable|in:cancelada,no_show']);
        $svc->cancelar(Estadia::findOrFail($id), $d['estado'] ?? 'cancelada');
        return redirect('/hoteleria')->with('success', 'Reserva cancelada.');
    }
}
