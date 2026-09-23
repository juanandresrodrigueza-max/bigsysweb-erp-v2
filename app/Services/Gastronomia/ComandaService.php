<?php

namespace App\Services\Gastronomia;

use App\Models\AuditLog;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Mesa;
use App\Models\Product;
use App\Services\Fondos\FondosService;
use App\Services\Pos\PosService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Mesas y comandas: abrir, pedir, mandar a cocina, entregar, pedir la cuenta y cerrar cobrando (genera la factura con el POS).
class ComandaService
{
    public function __construct(private PosService $pos, private FondosService $fondos) {}

    public function abrir(array $d): Comanda
    {
        $user = Auth::user();
        $mesa = ! empty($d['mesa_id']) ? Mesa::findOrFail($d['mesa_id']) : null;
        if ($mesa && $mesa->comandaAbierta) return $mesa->comandaAbierta;
        $c = Comanda::create([
            'business_id' => $user->business_id, 'business_location_id' => $mesa?->business_location_id ?? $user->current_location_id, 'mesa_id' => $mesa?->id, 'user_id' => $user->id,
            'numero' => (Comanda::withoutGlobalScopes()->where('business_id', $user->business_id)->max('numero') ?? 0) + 1, 'tipo' => $mesa ? 'mesa' : ($d['tipo'] ?? 'mostrador'), 'estado' => 'abierta', 'total' => 0, 'propina' => 0, 'descuento' => 0,
            'cubiertos' => $d['cubiertos'] ?? ($mesa ? 2 : 0), 'cliente' => $d['cliente'] ?? null, 'direccion' => $d['direccion'] ?? null, 'telefono' => $d['telefono'] ?? null, 'abierta_en' => now(),
        ]);
        return $c;
    }

    public function agregar(Comanda $c, array $it): ComandaItem
    {
        abort_if(! in_array($c->estado, ['abierta', 'cuenta'], true), 422, 'La comanda está cerrada.');
        $p = ! empty($it['product_id']) ? Product::findOrFail($it['product_id']) : null;
        $ronda = (int) ($c->items()->max('ronda') ?? 1);
        $enviados = $c->items()->where('ronda', $ronda)->whereNotNull('enviado_en')->exists();
        $ri = (Auth::user()->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $al = $ri ? (float) ($p?->iva ?? 21) : 0;
        $item = $c->items()->create([
            'product_id' => $p?->id, 'descripcion' => $it['descripcion'] ?? $p?->name ?? 'Ítem', 'cantidad' => $it['cantidad'] ?? 1,
            'precio_unit' => $it['precio_unit'] ?? ($p ? round((float) $p->price * (1 + $al / 100), 2) : 0),   // precio final de carta
            'alicuota_iva' => $al, 'notas' => $it['notas'] ?? null, 'va_cocina' => $p ? $p->va_cocina : true, 'ronda' => $enviados ? $ronda + 1 : max(1, $ronda), 'estado' => 'pedido',
        ]);
        $c->recalcular();
        if ($c->estado === 'cuenta') $c->update(['estado' => 'abierta', 'cuenta_en' => null]);
        return $item;
    }

    public function quitar(ComandaItem $item): void
    {
        abort_if(in_array($item->estado, ['entregado'], true), 422, 'Ya se entregó; anulalo con motivo desde la cuenta.');
        $item->enviado_en ? $item->update(['estado' => 'anulado']) : $item->delete();
        $item->comanda->recalcular();
    }

    // Manda a cocina todo lo pedido que todavía no fue. Lo que no va a cocina (bebidas) queda "listo" al instante.
    public function enviarCocina(Comanda $c): int
    {
        $n = 0;
        foreach ($c->items()->where('estado', 'pedido')->get() as $it) {
            $it->update($it->va_cocina ? ['estado' => 'cocina', 'enviado_en' => now()] : ['estado' => 'listo', 'enviado_en' => now(), 'listo_en' => now()]);
            $n++;
        }
        return $n;
    }

    public function estadoItem(ComandaItem $item, string $estado): void
    {
        abort_unless(array_key_exists($estado, ComandaItem::ESTADOS), 422);
        $item->update(['estado' => $estado] + ($estado === 'listo' ? ['listo_en' => now()] : []));
    }

    public function pedirCuenta(Comanda $c): void
    {
        $c->update(['estado' => 'cuenta', 'cuenta_en' => now()]);
    }

    // Cierra la comanda: factura + cobro con el POS, propina a caja como ingreso, mesa liberada.
    public function cerrar(Comanda $c, array $d): array
    {
        return DB::transaction(function () use ($c, $d) {
            abort_if(! in_array($c->estado, ['abierta', 'cuenta'], true), 422, 'La comanda ya está cerrada.');
            $items = $c->items()->where('estado', '!=', 'anulado')->get();
            if ($items->isEmpty()) throw ValidationException::withMessages(['items' => 'La comanda no tiene ítems.']);
            if (isset($d['descuento'])) $c->update(['descuento' => (float) $d['descuento']]);
            $c->recalcular();

            $lineas = $items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'alicuota_iva' => (float) $i->alicuota_iva, 'descuento' => (float) $c->descuento > 0 ? round((float) $c->descuento / ($c->total + (float) $c->descuento) * 100, 4) : 0])->all();
            $propina = (float) ($d['propina'] ?? 0);
            $medios = collect($d['medios'] ?? [])->map(fn($m) => $m)->all();
            // La propina se paga junto pero no es venta: se descuenta de los medios antes de cobrar la factura.
            if ($propina > 0) {
                $resto = $propina;
                foreach ($medios as &$m) { $q = min($resto, (float) $m['monto']); $m['monto'] = round((float) $m['monto'] - $q, 2); $resto -= $q; if ($resto <= 0) break; }
                unset($m);
            }
            $venta = $this->pos->vender(['items' => $lineas, 'contact_id' => $d['contact_id'] ?? null, 'medios' => $medios, 'notas' => $c->titulo() . ' · comanda ' . $c->numeroFormateado(), 'a_cuenta' => $d['a_cuenta'] ?? false]);

            if ($propina > 0) {
                $caja = $this->fondos->cuentaPara('efectivo', Auth::user());
                if ($caja) $this->fondos->registrar($caja, ['origen' => 'ingreso', 'concepto' => "Propina {$c->titulo()}", 'ingreso' => $propina]);
            }
            $c->update(['estado' => 'cerrada', 'cerrada_en' => now(), 'comprobante_id' => $venta['comprobante']->id, 'propina' => $propina]);
            $c->items()->whereIn('estado', ['pedido', 'cocina', 'listo'])->update(['estado' => 'entregado']);
            AuditLog::registrar('cerrar_comanda', $c, "Cerró {$c->titulo()} por $ " . number_format((float) $c->total, 2, ',', '.'));
            return $venta + ['comanda' => $c->fresh()];
        });
    }

    public function anular(Comanda $c, string $motivo): void
    {
        abort_if($c->estado === 'cerrada', 422, 'Una comanda cerrada se anula desde el comprobante.');
        $c->update(['estado' => 'anulada', 'cerrada_en' => now(), 'notas' => trim(($c->notas ?? '') . "\nAnulada: {$motivo}")]);
        AuditLog::registrar('anular', $c, "Anuló comanda {$c->numeroFormateado()}: {$motivo}");
    }
}
