<?php

namespace App\Services\Comprobantes;

use App\Models\Acopio;
use App\Models\AcopioItem;
use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\Product;
use App\Models\PuntoVenta;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComprobanteService
{
    public function __construct(private AfipEmisor $afip) {}

    // Crea o actualiza un borrador con sus ítems y recalcula totales.
    public function guardarBorrador(array $data, ?Comprobante $c = null): Comprobante
    {
        return DB::transaction(function () use ($data, $c) {
            $user = Auth::user();
            $contact = ! empty($data['contact_id']) ? Contact::findOrFail($data['contact_id']) : null;
            $tipo = $this->resolverTipo($data['tipo'], $contact);

            $c ??= new Comprobante(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'direccion' => 'venta']);
            abort_if($c->exists && $c->estado !== 'borrador', 422, 'Solo se pueden editar comprobantes en borrador.');

            $dias = (int) ($data['dias_vto'] ?? $contact?->dias_pago ?? $contact?->tipoCliente?->dias_pago ?? 0);
            $c->fill([
                'contact_id'      => $contact?->id,
                'punto_venta_id'  => $data['punto_venta_id'] ?? $this->puntoVentaPorDefecto($user)?->id,
                'origen_id'       => $data['origen_id'] ?? $c->origen_id,
                'tipo'            => $tipo,
                'fecha'           => $data['fecha'] ?? today(),
                'fecha_vto'       => ($data['condicion'] ?? 'cta_cte') === 'cta_cte' ? \Carbon\Carbon::parse($data['fecha'] ?? today())->addDays($dias) : ($data['fecha'] ?? today()),
                'condicion'       => $data['condicion'] ?? 'cta_cte',
                'es_acopio'       => (bool) ($data['es_acopio'] ?? false),
                'notas'           => $data['notas'] ?? null,
            ])->save();

            $c->items()->delete();
            foreach (array_values($data['items']) as $i => $it) {
                $product = ! empty($it['product_id']) ? Product::find($it['product_id']) : null;
                $al = (float) ($it['alicuota_iva'] ?? $product?->iva ?? 21);
                $calc = ComprobanteItem::calcular((float) $it['cantidad'], (float) $it['precio_unit'], (float) ($it['descuento'] ?? 0), $al);
                $c->items()->create([
                    'product_id' => $product?->id, 'descripcion' => $it['descripcion'] ?: ($product?->name ?? 'Ítem'),
                    'cantidad' => $it['cantidad'], 'unidad' => $it['unidad'] ?? $product?->unit, 'precio_unit' => $it['precio_unit'],
                    'descuento' => $it['descuento'] ?? 0, 'alicuota_iva' => $al, 'orden' => $i, ...$calc,
                ]);
            }

            $c->impuestos()->delete();
            if ($contact?->percepcion_iibb && $c->esFactura()) {
                $base = $c->items()->sum('neto');
                $c->impuestos()->create(['tipo' => 'iibb', 'base' => $base, 'alicuota' => 3, 'monto' => round($base * 0.03, 2)]);
            }

            $c->recalcularTotales();
            return $c->fresh(['items', 'contact']);
        });
    }

    // Emite: numera, pide CAE, impacta cuenta corriente, stock y acopio.
    public function emitir(Comprobante $c): Comprobante
    {
        return DB::transaction(function () use ($c) {
            abort_if($c->estado !== 'borrador', 422, 'El comprobante ya fue emitido.');
            abort_if($c->items()->count() === 0, 422, 'El comprobante no tiene ítems.');
            if ($c->def()['cc'] !== 0 && ! $c->contact_id) {
                throw ValidationException::withMessages(['contact_id' => 'Elegí un cliente para emitir este comprobante.']);
            }
            if ($c->esFactura() && $c->contact && $c->condicion === 'cta_cte' && (float) $c->contact->credit_limit > 0
                && (float) $c->contact->balance + (float) $c->total > (float) $c->contact->credit_limit) {
                throw ValidationException::withMessages(['contact_id' => 'El cliente supera su límite de crédito. Cobrá al contado o ampliá el límite.']);
            }

            $business = $c->business;
            $pv = $c->puntoVenta ?? $this->puntoVentaPorDefecto(Auth::user());
            abort_if(! $pv, 422, 'Configurá un punto de venta antes de emitir.');
            $c->punto_venta = $pv->numero;
            $c->punto_venta_id = $pv->id;

            $res = $this->afip->emitir($c, $business);
            if ($res['estado'] === 'rechazado') {
                throw ValidationException::withMessages(['afip' => 'AFIP rechazó el comprobante: ' . $res['error']]);
            }
            $numero = $res['numero'] ?? $pv->proximoNumero($c->tipo);
            if ($res['estado'] === 'aprobado') {
                $pv->sincronizarUltimo($c->tipo, $numero);
            }

            $c->forceFill([
                'numero' => $numero, 'estado' => 'emitido', 'emitido_en' => now(),
                'afip_estado' => $res['estado'], 'cae' => $res['cae'] ?? null, 'cae_vto' => $res['cae_vto'] ?? null,
                'afip_respuesta' => $res['respuesta'] ?? null,
                'saldo' => $c->def()['cc'] > 0 ? $c->total : 0,
            ])->save();

            $this->impactarCuentaCorriente($c);
            $this->impactarStock($c);
            if ($c->es_acopio && $c->esFactura()) {
                $this->crearAcopio($c);
            }
            if ($c->origen && $c->origen->tipo === 'PRE' && $c->esFactura()) {
                Alerta::where('modelo', 'Comprobante')->where('modelo_id', $c->origen_id)->update(['resuelta_en' => now()]);
            }

            AuditLog::registrar('emitir', $c, "Emitió {$c->nombreTipo()} {$c->numeroFormateado()}");
            return $c->fresh();
        });
    }

    public function anular(Comprobante $c, string $motivo = ''): Comprobante
    {
        return DB::transaction(function () use ($c, $motivo) {
            abort_if($c->estado === 'anulado', 422, 'Ya está anulado.');
            if ($c->estado === 'emitido' && $c->esFiscal() && $c->afip_estado === 'aprobado') {
                abort(422, 'Un comprobante fiscal aprobado por AFIP no se anula: emití una nota de crédito.');
            }
            if ($c->estado === 'emitido' && $c->imputaciones()->exists()) {
                abort(422, 'Tiene cobros imputados. Anulá primero los cobros.');
            }
            if ($c->estado === 'emitido') {
                CuentaCorriente::where('comprobante_id', $c->id)->delete();
                if ($c->contact_id) {
                    CuentaCorriente::recalcularSaldo($c->contact_id);
                }
                if ($c->stock_impactado) {
                    $this->revertirStock($c);
                }
                $c->acopio?->delete();
            }
            $c->forceFill(['estado' => 'anulado', 'anulado_en' => now(), 'saldo' => 0, 'notas' => trim(($c->notas ?? '') . "\nAnulado: {$motivo}")])->save();
            AuditLog::registrar('anular', $c, "Anuló {$c->nombreTipo()} {$c->numeroFormateado()}: {$motivo}");
            return $c;
        });
    }

    // Presupuesto o remito -> factura; factura -> remito o nota de crédito.
    public function convertir(Comprobante $origen, string $tipoDestino, array $extra = []): Comprobante
    {
        abort_if($origen->estado !== 'emitido', 422, 'Solo se convierten comprobantes emitidos.');
        $contact = $origen->contact;
        $data = [
            'contact_id' => $origen->contact_id, 'tipo' => $tipoDestino, 'origen_id' => $origen->id,
            'fecha' => today()->toDateString(), 'condicion' => $extra['condicion'] ?? $origen->condicion, 'es_acopio' => $extra['es_acopio'] ?? false,
            'notas' => "Generado desde {$origen->nombreTipo()} {$origen->numeroFormateado()}",
            'items' => $origen->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => $i->precio_unit, 'descuento' => $i->descuento, 'alicuota_iva' => $i->alicuota_iva])->all(),
        ];
        return $this->guardarBorrador($data);
    }

    // FA/FB/FC según empresa y cliente; el resto se respeta.
    public function resolverTipo(string $tipo, ?Contact $contact): string
    {
        $business = Auth::user()->business;
        $letra = Contact::letraPara($business, $contact);
        return match ($tipo) {
            'FA', 'FB', 'FC', 'FX' => 'F' . $letra,
            'NCA', 'NCB', 'NCC', 'NCX' => 'NC' . $letra,
            'NDA', 'NDB', 'NDC', 'NDX' => 'ND' . $letra,
            default => $tipo,
        };
    }

    public function puntoVentaPorDefecto($user): ?PuntoVenta
    {
        return PuntoVenta::where('activo', true)
            ->orderByRaw('CASE WHEN business_location_id = ? THEN 0 ELSE 1 END', [$user->current_location_id])
            ->orderBy('numero')->first();
    }

    private function impactarCuentaCorriente(Comprobante $c): void
    {
        $signo = $c->def()['cc'];
        if ($signo === 0 || ! $c->contact_id) {
            return;
        }
        CuentaCorriente::create([
            'business_id' => $c->business_id, 'contact_id' => $c->contact_id, 'comprobante_id' => $c->id,
            'fecha' => $c->fecha, 'fecha_vto' => $signo > 0 ? $c->fecha_vto : null, 'tipo' => $c->def()['grupo'],
            'concepto' => "{$c->nombreTipo()} {$c->numeroFormateado()}",
            'debe' => $signo > 0 ? $c->total : 0, 'haber' => $signo < 0 ? $c->total : 0,
        ]);
        if ($signo < 0 && $c->origen_id) {
            // La NC cancela saldo de la factura de origen.
            $origen = $c->origen;
            $aplicar = min((float) $origen->saldo, (float) $c->total);
            $origen->decrement('saldo', $aplicar);
        }
        CuentaCorriente::recalcularSaldo($c->contact_id);
    }

    private function impactarStock(Comprobante $c): void
    {
        if (! $c->def()['stock'] || $c->es_acopio) {
            return;
        }
        // Si el origen ya movió stock (remito -> factura o factura -> remito) no se repite.
        if ($c->origen && $c->origen->stock_impactado && in_array($c->def()['grupo'], ['factura', 'remito'], true)) {
            return;
        }
        $sentido = $c->esNotaCredito() ? 1 : -1; // NC devuelve mercadería
        foreach ($c->items as $it) {
            if (! $it->product_id) {
                continue;
            }
            $p = Product::lockForUpdate()->find($it->product_id);
            if (! $p) {
                continue;
            }
            $antes = (float) $p->stock;
            $p->stock = $antes + $sentido * (float) $it->cantidad;
            $p->save();
            StockMovement::create([
                'business_id' => $c->business_id, 'business_location_id' => $c->business_location_id, 'product_id' => $p->id, 'user_id' => Auth::id(),
                'type' => $sentido > 0 ? 'in' : 'out', 'quantity' => (float) $it->cantidad, 'stock_before' => $antes, 'stock_after' => (float) $p->stock,
                'reason' => "{$c->nombreTipo()} {$c->numeroFormateado()}", 'movable_id' => $c->id, 'movable_type' => Comprobante::class,
            ]);
        }
        $c->forceFill(['stock_impactado' => true])->save();
    }

    private function revertirStock(Comprobante $c): void
    {
        $sentido = $c->esNotaCredito() ? -1 : 1;
        foreach ($c->items as $it) {
            if (! $it->product_id) {
                continue;
            }
            $p = Product::lockForUpdate()->find($it->product_id);
            if (! $p) {
                continue;
            }
            $antes = (float) $p->stock;
            $p->stock = $antes + $sentido * (float) $it->cantidad;
            $p->save();
            StockMovement::create([
                'business_id' => $c->business_id, 'business_location_id' => $c->business_location_id, 'product_id' => $p->id, 'user_id' => Auth::id(),
                'type' => $sentido > 0 ? 'in' : 'out', 'quantity' => (float) $it->cantidad, 'stock_before' => $antes, 'stock_after' => (float) $p->stock,
                'reason' => "Anulación {$c->nombreTipo()} {$c->numeroFormateado()}", 'movable_id' => $c->id, 'movable_type' => Comprobante::class,
            ]);
        }
        $c->forceFill(['stock_impactado' => false])->save();
    }

    private function crearAcopio(Comprobante $c): void
    {
        $acopio = Acopio::create([
            'business_id' => $c->business_id, 'business_location_id' => $c->business_location_id, 'contact_id' => $c->contact_id,
            'comprobante_id' => $c->id, 'fecha' => $c->fecha, 'fecha_limite' => $c->fecha->copy()->addMonths(6), 'estado' => 'abierto',
        ]);
        foreach ($c->items as $it) {
            AcopioItem::create(['acopio_id' => $acopio->id, 'product_id' => $it->product_id, 'descripcion' => $it->descripcion, 'cantidad_facturada' => $it->cantidad, 'precio_congelado' => $it->precio_unit]);
        }
    }
}
