<?php

namespace App\Services\Pos;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Services\Comprobantes\CobroService;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Venta de mostrador: arma la factura, la emite y cobra en el mismo paso. Reusa todo el circuito de comprobantes y fondos.
class PosService
{
    public function __construct(private ComprobanteService $comprobantes, private CobroService $cobros, private CuotasService $cuotas) {}

    // Cliente genérico para ventas sin identificar (se crea una vez por empresa).
    public function consumidorFinal(): Contact
    {
        return Contact::customers()->where('condicion_iva', 'Consumidor Final')->where('name', 'Consumidor Final')->first()
            ?? Contact::create(['business_id' => Auth::user()->business_id, 'type' => 'customer', 'name' => 'Consumidor Final', 'condicion_iva' => 'Consumidor Final', 'is_active' => true, 'lista_precios' => 1]);
    }

    // $d: items[{product_id, descripcion, cantidad, precio_unit, descuento, alicuota_iva}], contact_id?, medios[{medio, monto, cuenta_fondos_id?, referencia?}], descuento_global?, notas?
    public function vender(array $d): array
    {
        return DB::transaction(function () use ($d) {
            $contact = ! empty($d['contact_id']) ? Contact::customers()->findOrFail($d['contact_id']) : $this->consumidorFinal();
            $items = collect($d['items'])->filter(fn($i) => (float) $i['cantidad'] > 0)->values();
            if ($items->isEmpty()) throw ValidationException::withMessages(['items' => 'El ticket está vacío.']);
            // Tarjeta en cuotas: el recargo del plan entra como ítem para que la factura cierre con lo que paga el cliente.
            [$mediosCuotas, $recargos] = $this->cuotas->aplicar(Auth::user()->business, array_values(array_filter($d['medios'] ?? [], fn($m) => (float) $m['monto'] > 0)));
            $d['medios'] = $mediosCuotas;
            $items = $items->concat($recargos)->values();

            // En mostrador y salón los precios son finales (IVA incluido). El comprobante guarda el neto; en Factura C no hay IVA.
            $tipo = $this->comprobantes->resolverTipo($d['tipo'] ?? 'FX', $contact);
            $conIva = $d['precios_con_iva'] ?? true;
            $lineas = $items->map(function ($i) use ($tipo, $conIva) {
                $p = ! empty($i['product_id']) ? \App\Models\Product::find($i['product_id']) : null;
                $al = $tipo === 'FC' ? 0 : (float) ($i['alicuota_iva'] ?? $p?->iva ?? 21);
                $precio = (float) $i['precio_unit'];
                if ($conIva && $al > 0) $precio = round($precio / (1 + $al / 100), 4);
                return ['product_id' => $p?->id, 'descripcion' => $i['descripcion'] ?? null, 'cantidad' => $i['cantidad'], 'precio_unit' => $precio, 'descuento' => $i['descuento'] ?? 0, 'alicuota_iva' => $al, 'serie' => $i['serie'] ?? null];
            })->all();

            $c = $this->comprobantes->guardarBorrador([
                'contact_id' => $contact->id, 'tipo' => $tipo, 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'notas' => $d['notas'] ?? null, 'items' => $lineas,
            ]);
            $c = $this->comprobantes->emitir($c);

            $medios = array_values(array_filter($d['medios'] ?? [], fn($m) => (float) $m['monto'] > 0));
            $pagado = round(array_sum(array_map(fn($m) => (float) $m['monto'], $medios)), 2);
            $total = (float) $c->total;
            $vuelto = 0;
            if ($pagado > $total + 0.005) {
                // El vuelto sale del efectivo.
                $vuelto = round($pagado - $total, 2);
                $ef = null;
                foreach ($medios as $k => $m) { if ($m['medio'] === 'efectivo') { $ef = $k; break; } }
                if ($ef === null) throw ValidationException::withMessages(['medios' => 'Lo pagado supera el total y no hay efectivo para dar vuelto.']);
                $medios[$ef]['monto'] = round((float) $medios[$ef]['monto'] - $vuelto, 2);
                $medios = array_values(array_filter($medios, fn($m) => (float) $m['monto'] > 0));
            }
            $medios = collect($medios);
            if ($pagado + 0.005 < $total && ! ($d['a_cuenta'] ?? false)) {
                throw ValidationException::withMessages(['medios' => 'Falta cobrar $ ' . number_format($total - $pagado, 2, ',', '.') . '. Si es a cuenta corriente, marcá "queda a cuenta".']);
            }

            $cobro = null;
            if ($medios->isNotEmpty()) {
                $cobro = $this->cobros->registrar($contact, ['fecha' => today()->toDateString(), 'notas' => 'Venta de mostrador', 'medios' => $medios->all(), 'imputaciones' => [['comprobante_id' => $c->id, 'monto' => min($total, round($medios->sum(fn($m) => (float) $m['monto']), 2))]]]);
            }
            AuditLog::registrar('pos', $c, "Venta de mostrador {$c->nombreTipo()} {$c->numeroFormateado()} $ " . number_format($total, 2, ',', '.'));
            return ['comprobante' => $c->fresh(), 'cobro' => $cobro, 'vuelto' => $vuelto];
        });
    }
}
