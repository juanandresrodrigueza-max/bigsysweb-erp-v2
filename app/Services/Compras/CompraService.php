<?php

namespace App\Services\Compras;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Facturas de compra: mismo modelo Comprobante con direccion=compra; el número lo pone el proveedor.
class CompraService
{
    public function __construct(private \App\Services\Stock\StockService $stock) {}

    public function guardarBorrador(array $data, ?Comprobante $c = null): Comprobante
    {
        return DB::transaction(function () use ($data, $c) {
            $user = Auth::user();
            $proveedor = Contact::suppliers()->findOrFail($data['contact_id']);

            $c ??= new Comprobante(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'direccion' => 'compra', 'afip_estado' => 'no_aplica']);
            abort_if($c->exists && $c->estado !== 'borrador', 422, 'Solo se editan borradores.');

            $numero = preg_replace('/\s+/', '', $data['numero_proveedor'] ?? '');
            if ($numero && ! preg_match('/^\d{1,5}-\d{1,8}$/', $numero)) {
                throw ValidationException::withMessages(['numero_proveedor' => 'Formato esperado: punto de venta-número, por ejemplo 0003-00012345.']);
            }
            if ($numero) {
                [$pv, $num] = explode('-', $numero);
                $numero = sprintf('%04d-%08d', (int) $pv, (int) $num);
                $dup = Comprobante::compras()->where('contact_id', $proveedor->id)->where('tipo', $data['tipo'])->where('numero_proveedor', $numero)->where('id', '!=', $c->id ?? 0)->exists();
                if ($dup) {
                    throw ValidationException::withMessages(['numero_proveedor' => "Ya cargaste {$data['tipo']} {$numero} de este proveedor."]);
                }
            }

            $dias = (int) ($data['dias_vto'] ?? $proveedor->dias_pago ?? 0);
            $c->fill([
                'contact_id' => $proveedor->id, 'tipo' => $data['tipo'], 'numero_proveedor' => $numero ?: null, 'cae_proveedor' => $data['cae_proveedor'] ?? null,
                'origen_carga' => $data['origen_carga'] ?? 'manual', 'origen_id' => $data['origen_id'] ?? $c->origen_id,
                'fecha' => $data['fecha'], 'fecha_vto' => isset($data['fecha_vto']) && $data['fecha_vto'] ? $data['fecha_vto'] : \Carbon\Carbon::parse($data['fecha'])->addDays($dias),
                'condicion' => $data['condicion'] ?? 'cta_cte', 'notas' => $data['notas'] ?? null,
            ])->save();

            $c->items()->delete();
            foreach (array_values($data['items']) as $i => $it) {
                $product = ! empty($it['product_id']) ? Product::find($it['product_id']) : null;
                $al = (float) ($it['alicuota_iva'] ?? 21);
                $calc = ComprobanteItem::calcular((float) $it['cantidad'], (float) $it['precio_unit'], (float) ($it['descuento'] ?? 0), $al);
                $c->items()->create(['product_id' => $product?->id, 'descripcion' => $it['descripcion'] ?: ($product?->name ?? 'Ítem'), 'cantidad' => $it['cantidad'], 'unidad' => $it['unidad'] ?? $product?->unit, 'precio_unit' => $it['precio_unit'], 'descuento' => $it['descuento'] ?? 0, 'alicuota_iva' => $al, 'orden' => $i, ...$calc]);
            }

            $c->impuestos()->delete();
            foreach ($data['impuestos'] ?? [] as $imp) {
                if ((float) ($imp['monto'] ?? 0) > 0) {
                    $c->impuestos()->create(['tipo' => $imp['tipo'], 'base' => $imp['base'] ?? 0, 'alicuota' => $imp['alicuota'] ?? 0, 'monto' => $imp['monto']]);
                }
            }
            $c->recalcularTotales();
            // Percepciones/otros impuestos del proveedor suman al total (recalcularTotales solo toma iibb*)
            $otros = (float) $c->impuestos()->where('tipo', 'not like', 'iibb%')->sum('monto');
            if ($otros > 0) {
                $c->forceFill(['percepciones' => (float) $c->percepciones + $otros, 'total' => round((float) $c->total + $otros, 2), 'saldo' => $c->estado === 'emitido' ? round((float) $c->saldo + $otros, 2) : round((float) $c->total + $otros, 2)])->save();
            }
            return $c->fresh(['items', 'contact']);
        });
    }

    // Registrar = confirmar la factura de compra: cuenta corriente del proveedor, stock y costo.
    public function registrar(Comprobante $c): Comprobante
    {
        return DB::transaction(function () use ($c) {
            abort_if($c->direccion !== 'compra', 422, 'No es una compra.');
            abort_if($c->estado !== 'borrador', 422, 'Ya fue registrada.');
            abort_if($c->items()->count() === 0, 422, 'La compra no tiene ítems.');

            $c->forceFill(['estado' => 'emitido', 'emitido_en' => now(), 'saldo' => $c->def()['cc'] > 0 ? $c->total : 0])->save();

            $signo = $c->def()['cc'];
            if ($signo !== 0) {
                CuentaCorriente::create([
                    'business_id' => $c->business_id, 'contact_id' => $c->contact_id, 'comprobante_id' => $c->id, 'fecha' => $c->fecha,
                    'fecha_vto' => $signo > 0 ? $c->fecha_vto : null, 'tipo' => $c->def()['grupo'], 'concepto' => "{$c->nombreTipo()} {$c->numeroFormateado()} (compra)",
                    'debe' => $signo > 0 ? $c->total : 0, 'haber' => $signo < 0 ? $c->total : 0,
                ]);
                if ($signo < 0 && $c->origen_id) {
                    $origen = $c->origen;
                    $origen->decrement('saldo', min((float) $origen->saldo, (float) $c->total));
                }
                CuentaCorriente::recalcularSaldo($c->contact_id);
            }

            if ($c->def()['stock']) {
                $sentido = $c->esNotaCredito() ? -1 : 1; // compra entra, NC de compra devuelve
                $deposito = \App\Models\Deposito::porDefecto($c->business_location_id);
                foreach ($c->items as $it) {
                    if (! $it->product_id || ! ($p = Product::find($it->product_id))) continue;
                    $costo = (float) $it->precio_unit * (1 - (float) $it->descuento / 100);
                    if ($sentido > 0) {
                        $p->forceFill(['cost' => $costo])->save();
                    }
                    $this->stock->mover($p, $sentido * (float) $it->cantidad, $deposito, $sentido > 0 ? 'in' : 'out', "Compra {$c->nombreTipo()} {$c->numeroFormateado()}", $c, $costo, $c->business_location_id);
                }
                $c->forceFill(['stock_impactado' => true])->save();
            }

            AuditLog::registrar('crear', $c, "Registró compra {$c->nombreTipo()} {$c->numeroFormateado()} de {$c->contact?->name}");
            app(\App\Services\Contabilidad\ContabilidadService::class)->contabilizar($c->fresh(['items', 'contact']));
            return $c->fresh();
        });
    }

    public function anular(Comprobante $c, string $motivo = ''): Comprobante
    {
        return DB::transaction(function () use ($c, $motivo) {
            abort_if($c->estado === 'anulado', 422, 'Ya está anulada.');
            if ($c->estado === 'emitido') {
                abort_if($c->pagosImputados()->exists(), 422, 'Tiene pagos imputados. Anulá primero los pagos.');
                CuentaCorriente::where('comprobante_id', $c->id)->delete();
                CuentaCorriente::recalcularSaldo($c->contact_id);
                if ($c->stock_impactado) {
                    $sentido = $c->esNotaCredito() ? 1 : -1;
                    $deposito = \App\Models\Deposito::porDefecto($c->business_location_id);
                    foreach ($c->items as $it) {
                        if (! $it->product_id || ! ($p = Product::find($it->product_id))) continue;
                        $this->stock->mover($p, $sentido * (float) $it->cantidad, $deposito, $sentido > 0 ? 'in' : 'out', "Anulación compra {$c->numeroFormateado()}", $c, null, $c->business_location_id);
                    }
                }
            }
            $c->forceFill(['estado' => 'anulado', 'anulado_en' => now(), 'saldo' => 0, 'stock_impactado' => false, 'notas' => trim(($c->notas ?? '') . "\nAnulada: {$motivo}")])->save();
            AuditLog::registrar('anular', $c, "Anuló compra {$c->numeroFormateado()}: {$motivo}");
            app(\App\Services\Contabilidad\ContabilidadService::class)->anular('compra', $c->id, $motivo);
            return $c;
        });
    }
}
