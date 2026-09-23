<?php

namespace App\Services\Servicios;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\OrdenTrabajo;
use App\Models\Product;
use App\Services\Canales\WhatsappPedidosService;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Servicio técnico: la orden de trabajo va de recibido a entregado; el cliente aprueba el presupuesto por link y firma al retirar.
class OrdenesService
{
    public function __construct(private ComprobanteService $comprobantes, private WhatsappPedidosService $wa) {}

    public function crear(array $d): OrdenTrabajo
    {
        $u = Auth::user();
        $ot = OrdenTrabajo::create(['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id, 'numero' => (int) OrdenTrabajo::withoutGlobalScopes()->where('business_id', $u->business_id)->max('numero') + 1, 'token' => Str::random(40), 'fecha_ingreso' => $d['fecha_ingreso'] ?? today(), 'estado' => 'recibido'] + $d);
        AuditLog::registrar('crear', $ot, "OT {$ot->numeroFormateado()} · {$ot->equipo} de {$ot->clienteNombre()}");
        return $ot;
    }

    public function actualizar(OrdenTrabajo $ot, array $d): OrdenTrabajo
    {
        $ot->fill($d)->save();
        if (! empty($d['presupuesto']) && $ot->estado === 'diagnostico') $ot->update(['estado' => 'presupuestado']);
        AuditLog::registrar('editar', $ot, "Editó OT {$ot->numeroFormateado()}");
        return $ot->fresh();
    }

    public function estado(OrdenTrabajo $ot, string $estado): array
    {
        abort_if(! isset(OrdenTrabajo::ESTADOS[$estado]), 422, 'Estado inválido.');
        $ot->update(['estado' => $estado] + ($estado === 'aprobado' ? ['aprobado_en' => now()] : []));
        AuditLog::registrar('editar', $ot, "OT {$ot->numeroFormateado()} → " . OrdenTrabajo::ESTADOS[$estado]);
        // Avisos al cliente: presupuesto listo para aprobar y equipo listo para retirar.
        $aviso = null;
        if (in_array($estado, ['presupuestado', 'listo'], true) && $ot->telefonoCliente()) {
            $texto = $estado === 'presupuestado'
                ? "Hola {$ot->clienteNombre()}! Ya revisamos tu {$ot->equipo}. Diagnóstico: " . ($ot->diagnostico ?: 'ver detalle') . ". El presupuesto es $ " . number_format((float) $ot->presupuesto, 0, ',', '.') . ". Podés aprobarlo acá: {$ot->urlPublica()} · {$ot->business->name}"
                : "Hola {$ot->clienteNombre()}! Tu {$ot->equipo} ya está listo para retirar" . ((float) $ot->presupuesto > 0 ? " (total $ " . number_format((float) $ot->presupuesto, 0, ',', '.') . ")" : '') . ". Te esperamos. {$ot->business->name}";
            $aviso = $this->wa->responder($ot->business, $ot->telefonoCliente(), $texto);
        }
        return ['ot' => $ot->fresh(), 'aviso' => $aviso];
    }

    // El cliente aprueba (o rechaza) el presupuesto desde el link público.
    public function respuestaCliente(OrdenTrabajo $ot, bool $aprueba, ?string $nombre = null): void
    {
        abort_if(! in_array($ot->estado, ['presupuestado', 'diagnostico'], true), 422, 'El presupuesto ya fue respondido.');
        $ot->update($aprueba ? ['estado' => 'aprobado', 'aprobado_en' => now(), 'firma_nombre' => $nombre] : ['estado' => 'cancelado', 'notas' => trim(($ot->notas ?? '') . "\nPresupuesto rechazado por el cliente.")]);
        AuditLog::registrar('editar', $ot, "El cliente " . ($aprueba ? 'aprobó' : 'rechazó') . " el presupuesto de OT {$ot->numeroFormateado()}");
    }

    public function item(OrdenTrabajo $ot, array $d)
    {
        $p = ! empty($d['product_id']) ? Product::find($d['product_id']) : null;
        $precio = (float) ($d['precio_unit'] ?? 0) ?: ($p ? $p->precioLista(1) : 0);
        $it = $ot->items()->create(['product_id' => $p?->id, 'tipo' => $d['tipo'] ?? ($p && $p->tipo === 'servicio' ? 'mano_obra' : 'material'), 'descripcion' => $d['descripcion'] ?: ($p?->name ?? 'Ítem'), 'cantidad' => $d['cantidad'] ?? 1, 'precio_unit' => $precio, 'total' => round((float) ($d['cantidad'] ?? 1) * $precio, 2)]);
        if ((float) $ot->presupuesto <= 0 || ($d['actualizar_presupuesto'] ?? false)) $ot->update(['presupuesto' => $ot->totalItems()]);
        return $it;
    }

    public function tarea(OrdenTrabajo $ot, string $descripcion) { return $ot->tareas()->create(['descripcion' => $descripcion, 'user_id' => Auth::id()]); }

    public function firmar(OrdenTrabajo $ot, string $firma, ?string $nombre): void
    {
        $ot->update(['firma' => $firma, 'firma_nombre' => $nombre]);
        AuditLog::registrar('editar', $ot, "Firma del cliente en OT {$ot->numeroFormateado()}");
    }

    // Entrega: arma la factura con la hoja de trabajo (materiales salen del stock al emitir) y cierra la OT.
    public function facturar(OrdenTrabajo $ot, string $condicion = 'contado'): Comprobante
    {
        abort_if($ot->comprobante_id, 422, 'La orden ya está facturada.');
        return DB::transaction(function () use ($ot, $condicion) {
            $contact = $ot->contact ?? Contact::customers()->where('name', 'Consumidor Final')->first();
            abort_if(! $contact, 422, 'Asigná un cliente a la orden.');
            $ri = (Auth::user()->business->condicion_iva ?? '') === 'Responsable Inscripto';
            $items = $ot->items()->with('product')->get();
            $lineas = $items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => $ri && $i->product ? round((float) $i->precio_unit / (1 + (float) $i->product->iva / 100), 2) : ($ri ? round((float) $i->precio_unit / 1.21, 2) : (float) $i->precio_unit), 'descuento' => 0, 'alicuota_iva' => $i->product ? (float) $i->product->iva : 21])->all();
            if (! $lineas) $lineas = [['product_id' => null, 'descripcion' => "Reparación {$ot->equipo} · OT {$ot->numeroFormateado()}", 'cantidad' => 1, 'precio_unit' => $ri ? round((float) $ot->presupuesto / 1.21, 2) : (float) $ot->presupuesto, 'descuento' => 0, 'alicuota_iva' => 21]];
            $c = $this->comprobantes->guardarBorrador(['contact_id' => $contact->id, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => $condicion, 'orden_trabajo_id' => $ot->id, 'notas' => "OT {$ot->numeroFormateado()} · {$ot->equipo}" . ($ot->serie ? " · serie {$ot->serie}" : ''), 'items' => $lineas]);
            $c = $this->comprobantes->emitir($c);
            $ot->update(['comprobante_id' => $c->id, 'estado' => 'entregado', 'entregado_en' => now()]);
            AuditLog::registrar('emitir', $ot, "Facturó OT {$ot->numeroFormateado()}: {$c->nombreTipo()} {$c->numeroFormateado()}");
            return $c;
        });
    }

    public function tablero(): array
    {
        $ots = OrdenTrabajo::with('contact:id,name,phone', 'tecnico:id,name')->whereNotIn('estado', ['entregado', 'cancelado'])->orderByRaw("case prioridad when 'alta' then 0 when 'normal' then 1 else 2 end")->orderBy('fecha_prometida')->get();
        return $ots->map(fn($o) => $this->resumir($o))->groupBy('estado')->all();
    }

    public function resumir(OrdenTrabajo $o): array
    {
        return ['id' => $o->id, 'numero' => $o->numeroFormateado(), 'cliente' => $o->clienteNombre(), 'telefono' => $o->telefonoCliente(), 'equipo' => $o->equipo, 'marca_modelo' => $o->marca_modelo, 'falla' => $o->falla, 'estado' => $o->estado, 'prioridad' => $o->prioridad, 'tecnico' => $o->tecnico?->name, 'ingreso' => $o->fecha_ingreso->format('d/m'), 'prometida' => $o->fecha_prometida?->format('d/m'), 'atrasada' => $o->fecha_prometida && $o->fecha_prometida->isPast() && ! in_array($o->estado, ['listo', 'entregado', 'cancelado'], true), 'presupuesto' => (float) $o->presupuesto, 'dias' => (int) $o->fecha_ingreso->diffInDays(today())];
    }
}
