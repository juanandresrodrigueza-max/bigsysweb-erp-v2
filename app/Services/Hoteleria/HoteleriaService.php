<?php

namespace App\Services\Hoteleria;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Estadia;
use App\Models\Habitacion;
use App\Models\Product;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Hotelería: cuadro de reservas, check-in, consumos a la habitación y check-out con factura.
class HoteleriaService
{
    public function __construct(private ComprobanteService $comprobantes, private FondosService $fondos) {}

    // Cuadro de ocupación: habitaciones × días con las estadías que caen en cada uno.
    public function cuadro(Carbon $desde, int $dias = 14): array
    {
        $hasta = $desde->copy()->addDays($dias);
        $habs = Habitacion::where('activa', true)->orderBy('orden')->orderBy('nombre')->get();
        $est = Estadia::with('contact:id,name')->whereIn('estado', ['reservada', 'checkin'])->where('desde', '<', $hasta)->where('hasta', '>', $desde)->get();
        $fechas = collect(range(0, $dias - 1))->map(fn($i) => $desde->copy()->addDays($i));
        return [
            'dias' => $fechas->map(fn($f) => ['fecha' => $f->toDateString(), 'dia' => $f->locale('es')->isoFormat('dd'), 'num' => $f->day, 'hoy' => $f->isToday(), 'finde' => $f->isWeekend()])->all(),
            'habitaciones' => $habs->map(fn($h) => ['id' => $h->id, 'nombre' => $h->nombre, 'tipo' => Habitacion::TIPOS[$h->tipo] ?? $h->tipo, 'capacidad' => $h->capacidad, 'tarifa' => (float) $h->tarifa, 'estado' => $h->estado, 'piso' => $h->piso,
                'estadias' => $est->where('habitacion_id', $h->id)->map(fn($e) => ['id' => $e->id, 'nombre' => $e->contact?->name ?? $e->nombre, 'estado' => $e->estado, 'desde' => $e->desde->toDateString(), 'hasta' => $e->hasta->toDateString(), 'inicio' => max(0, (int) $desde->diffInDays($e->desde, false)), 'largo' => (int) max($desde, $e->desde)->diffInDays(min($hasta, $e->hasta)), 'personas' => $e->personas])->values()->all()])->all(),
        ];
    }

    public function disponibles(string $desde, string $hasta, ?int $ignorar = null)
    {
        $ocupadas = Estadia::whereIn('estado', ['reservada', 'checkin'])->where('desde', '<', $hasta)->where('hasta', '>', $desde)->when($ignorar, fn($q) => $q->where('id', '!=', $ignorar))->pluck('habitacion_id');
        return Habitacion::where('activa', true)->whereNotIn('id', $ocupadas)->where('estado', '!=', 'mantenimiento')->orderBy('orden')->get();
    }

    public function reservar(array $d, ?Estadia $e = null): Estadia
    {
        return DB::transaction(function () use ($d, $e) {
            $u = Auth::user();
            $hab = Habitacion::findOrFail($d['habitacion_id']);
            if (! $this->disponibles($d['desde'], $d['hasta'], $e?->id)->contains('id', $hab->id)) throw ValidationException::withMessages(['habitacion_id' => "La habitación {$hab->nombre} ya está reservada en esas fechas."]);
            if ((int) ($d['personas'] ?? 1) > $hab->capacidad) throw ValidationException::withMessages(['personas' => "La habitación {$hab->nombre} es para {$hab->capacidad} personas."]);
            $e ??= new Estadia(['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id, 'token' => Str::random(40), 'estado' => 'reservada', 'origen' => $d['origen'] ?? 'manual']);
            $e->fill(['habitacion_id' => $hab->id, 'contact_id' => $d['contact_id'] ?? null, 'nombre' => $d['nombre'] ?? ($d['contact_id'] ? Contact::find($d['contact_id'])?->name : null) ?? 'Huésped', 'telefono' => $d['telefono'] ?? null, 'email' => $d['email'] ?? null, 'documento' => $d['documento'] ?? null, 'personas' => $d['personas'] ?? 2, 'desde' => $d['desde'], 'hasta' => $d['hasta'], 'tarifa_noche' => ($d['tarifa_noche'] ?? null) !== null && $d['tarifa_noche'] !== '' ? $d['tarifa_noche'] : (float) $hab->tarifa, 'notas' => $d['notas'] ?? null])->save();
            AuditLog::registrar($e->wasRecentlyCreated ? 'crear' : 'editar', $e, "Reserva {$hab->nombre} · {$e->nombre} · " . $e->desde->format('d/m') . ' al ' . $e->hasta->format('d/m'));
            return $e;
        });
    }

    // Seña: entra a una cuenta de fondos y queda como anticipo hasta el check-out.
    public function senia(Estadia $e, float $monto, CuentaFondos $cuenta): void
    {
        $this->fondos->registrar($cuenta, ['fecha' => today(), 'origen' => 'senia', 'origen_id' => $e->id, 'concepto' => "Seña reserva {$e->habitacion->nombre} · {$e->nombre}", 'ingreso' => $monto]);
        $e->increment('senia', $monto);
        AuditLog::registrar('crear', $e, "Seña $ " . number_format($monto, 2, ',', '.') . " de {$e->nombre}");
    }

    public function checkin(Estadia $e): void
    {
        abort_if($e->estado !== 'reservada', 422, 'La reserva no está pendiente de check-in.');
        $e->update(['estado' => 'checkin', 'checkin_en' => now()]);
        $e->habitacion->update(['estado' => 'ocupada']);
        AuditLog::registrar('editar', $e, "Check-in {$e->nombre} en {$e->habitacion->nombre}");
    }

    public function consumo(Estadia $e, array $d)
    {
        abort_if($e->estado !== 'checkin', 422, 'Solo se cargan consumos a huéspedes alojados.');
        $p = ! empty($d['product_id']) ? Product::find($d['product_id']) : null;
        $precio = (float) ($d['precio_unit'] ?? 0) ?: ($p ? $p->precioLista(1) : 0);
        $c = $e->consumos()->create(['product_id' => $p?->id, 'user_id' => Auth::id(), 'fecha' => $d['fecha'] ?? today(), 'descripcion' => $d['descripcion'] ?: ($p?->name ?? 'Consumo'), 'cantidad' => $d['cantidad'] ?? 1, 'precio_unit' => $precio, 'total' => round((float) ($d['cantidad'] ?? 1) * $precio, 2)]);
        return $c;
    }

    // Check-out: factura alojamiento + consumos − seña, libera la habitación (queda en limpieza).
    public function checkout(Estadia $e, string $condicion = 'contado'): Comprobante
    {
        abort_if($e->estado !== 'checkin', 422, 'El huésped no está alojado.');
        return DB::transaction(function () use ($e, $condicion) {
            $contact = $e->contact ?? Contact::customers()->where('name', 'Consumidor Final')->first();
            $ri = (Auth::user()->business->condicion_iva ?? '') === 'Responsable Inscripto';
            $sinIva = fn($v, $al = 21) => $ri ? round($v / (1 + $al / 100), 2) : $v;
            $noches = $e->noches();
            $items = [['product_id' => null, 'descripcion' => "Alojamiento {$e->habitacion->nombre} · {$noches} noche" . ($noches > 1 ? 's' : '') . ' (' . $e->desde->format('d/m') . ' al ' . $e->hasta->format('d/m') . ')', 'cantidad' => $noches, 'precio_unit' => $sinIva((float) $e->tarifa_noche), 'descuento' => 0, 'alicuota_iva' => 21]];
            foreach ($e->consumos()->with('product')->get() as $c) $items[] = ['product_id' => $c->product_id, 'descripcion' => $c->descripcion, 'cantidad' => (float) $c->cantidad, 'precio_unit' => $sinIva((float) $c->precio_unit, $c->product ? (float) $c->product->iva : 21), 'descuento' => 0, 'alicuota_iva' => $c->product ? (float) $c->product->iva : 21];
            if ((float) $e->senia > 0) $items[] = ['product_id' => null, 'descripcion' => 'Seña recibida', 'cantidad' => 1, 'precio_unit' => -$sinIva((float) $e->senia), 'descuento' => 0, 'alicuota_iva' => 21];
            $c = $this->comprobantes->guardarBorrador(['contact_id' => $contact?->id, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => $condicion, 'estadia_id' => $e->id, 'notas' => "Estadía {$e->habitacion->nombre} · {$e->nombre}", 'items' => $items]);
            $c = $this->comprobantes->emitir($c);
            // La seña deja de ser anticipo: pasa a cancelar la factura (asiento: anticipos de clientes a deudores).
            if ((float) $e->senia > 0) app(\App\Services\Contabilidad\ContabilidadService::class)->asientoPorClaves($e->business_id, $e->business_location_id, today(), "Aplicación de seña · {$e->nombre}", 'senia_aplicada', $e->id, [['clave' => 'anticipos_clientes', 'debe' => (float) $e->senia, 'haber' => 0, 'detalle' => 'Seña aplicada'], ['clave' => 'deudores', 'debe' => 0, 'haber' => (float) $e->senia, 'detalle' => $contact?->name, 'contact_id' => $contact?->id]]);
            $e->update(['estado' => 'checkout', 'checkout_en' => now(), 'comprobante_id' => $c->id]);
            $e->habitacion->update(['estado' => 'limpieza']);
            AuditLog::registrar('emitir', $e, "Check-out {$e->nombre}: {$c->nombreTipo()} {$c->numeroFormateado()}");
            return $c;
        });
    }

    public function cancelar(Estadia $e, string $estado = 'cancelada'): void
    {
        abort_if(! in_array($e->estado, ['reservada', 'checkin'], true), 422, 'La estadía ya terminó.');
        $e->update(['estado' => $estado]);
        if ($e->habitacion->estado === 'ocupada') $e->habitacion->update(['estado' => 'limpieza']);
        AuditLog::registrar('anular', $e, "Reserva de {$e->nombre} " . ($estado === 'no_show' ? 'no se presentó' : 'cancelada'));
    }

    public function kpis(Business $b): array
    {
        $habs = Habitacion::where('activa', true)->count();
        $hoy = today();
        $ocupadas = Estadia::where('estado', 'checkin')->count();
        $mes = Estadia::where('estado', 'checkout')->whereMonth('checkout_en', $hoy->month)->whereYear('checkout_en', $hoy->year)->get();
        $nochesMes = (int) Estadia::whereIn('estado', ['checkin', 'checkout'])->where('desde', '<=', $hoy->copy()->endOfMonth())->where('hasta', '>=', $hoy->copy()->startOfMonth())->get()->sum(fn($e) => max(0, (int) max($e->desde, $hoy->copy()->startOfMonth())->diffInDays(min($e->hasta, $hoy->copy()->endOfMonth()->addDay()))));
        $diasMes = $hoy->daysInMonth;
        return ['habitaciones' => $habs, 'ocupadas' => $ocupadas, 'ocupacion' => $habs ? round($ocupadas / $habs * 100) : 0, 'llegadas' => Estadia::where('estado', 'reservada')->whereDate('desde', $hoy)->count(), 'salidas' => Estadia::where('estado', 'checkin')->whereDate('hasta', $hoy)->count(), 'limpieza' => Habitacion::where('estado', 'limpieza')->count(),
            'ocupacion_mes' => $habs ? round($nochesMes / ($habs * $diasMes) * 100) : 0, 'ingresos_mes' => round((float) Comprobante::where('direccion', 'venta')->where('estado', 'emitido')->whereNotNull('estadia_id')->whereMonth('fecha', $hoy->month)->whereYear('fecha', $hoy->year)->sum('total'), 2), 'adr' => $mes->count() ? round((float) $mes->avg('tarifa_noche'), 2) : 0];
    }
}
