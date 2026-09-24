<?php

namespace App\Services\Compras;

use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\Pago;
use App\Models\Retencion;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Orden de pago a proveedor: medios (incluye cheques y retenciones), imputación y cuenta corriente.
class PagoService
{
    public function __construct(private FondosService $fondos) {}

    public function registrar(Contact $proveedor, array $data): Pago
    {
        return DB::transaction(function () use ($proveedor, $data) {
            $user = Auth::user();
            // Medios en dólares: el importe viene en USD y se convierte a pesos a la cotización indicada (o la del día).
            $medios = collect($data['medios'])->filter(fn($m) => (float) $m['monto'] > 0)->map(function ($m) use ($user) {
                if (strtoupper($m['moneda'] ?? 'ARS') === 'USD') { $m['moneda'] = 'USD'; $m['cotizacion'] = (float) ($m['cotizacion'] ?? 0) ?: \App\Models\Cotizacion::valor($user->business_id); abort_if($m['cotizacion'] <= 0, 422, 'Falta la cotización del dólar.'); $m['monto_me'] = round((float) $m['monto'], 2); $m['monto'] = round($m['monto_me'] * $m['cotizacion'], 2); }
                else { $m['moneda'] = 'ARS'; $m['cotizacion'] = 1; $m['monto_me'] = null; }
                return $m;
            })->values();
            $total  = round($medios->sum(fn($m) => (float) $m['monto']), 2);
            if ($total <= 0) {
                throw ValidationException::withMessages(['medios' => 'Ingresá al menos un medio de pago con importe.']);
            }
            $descuento = round((float) ($data['descuento'] ?? 0), 2);
            $interes = round((float) ($data['interes'] ?? 0), 2);
            $cancela = round($total + $descuento - $interes, 2);
            if ($cancela <= 0) {
                throw ValidationException::withMessages(['interes' => 'El interés no puede superar lo pagado.']);
            }
            $imputaciones = collect($data['imputaciones'] ?? [])->filter(fn($i) => (float) $i['monto'] > 0)->values();
            $totalImputado = round($imputaciones->sum(fn($i) => (float) $i['monto']), 2);
            if ($totalImputado > $cancela + 0.005) {
                throw ValidationException::withMessages(['imputaciones' => 'Lo imputado supera lo que cancela esta orden (pagado + descuento − interés).']);
            }
            $cotHoy = (float) ($data['cotizacion'] ?? 0) ?: (float) ($medios->firstWhere('moneda', 'USD')['cotizacion'] ?? 0) ?: (float) \App\Models\Cotizacion::valor($user->business_id);

            $ultimo = (int) Pago::withoutGlobalScopes()->where('business_id', $user->business_id)->max('numero');
            $pago = Pago::create([
                'business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'contact_id' => $proveedor->id, 'user_id' => $user->id,
                'numero' => $ultimo + 1, 'fecha' => $data['fecha'] ?? today(), 'total' => $total, 'descuento' => $descuento, 'interes' => $interes, 'a_cuenta' => round($cancela - $totalImputado, 2), 'notas' => $data['notas'] ?? null, 'cotizacion' => $cotHoy ?: null,
            ]);

            foreach ($medios as $m) {
                $chequeId = null; $cuentaId = null; $d = $m['datos'] ?? [];
                switch ($m['medio']) {
                    case 'cheque_propio':
                        $cuenta = $this->fondos->cuentaPara('transferencia', $user, $m['cuenta_fondos_id'] ?? null);
                        $ch = Cheque::create([
                            'business_id' => $user->business_id, 'tipo' => 'propio', 'numero' => $d['numero'] ?? ($m['referencia'] ?? 's/n'), 'banco' => $cuenta?->banco ?? ($d['banco'] ?? null),
                            'emisor' => $proveedor->name, 'fecha_emision' => $pago->fecha, 'fecha_pago' => $d['fecha_pago'] ?? $pago->fecha, 'monto' => $m['monto'], 'echeq' => (bool) ($d['echeq'] ?? false),
                            'estado' => 'entregado', 'pago_id' => $pago->id, 'cuenta_fondos_id' => $cuenta?->id, 'contact_id' => $proveedor->id,
                        ]);
                        $chequeId = $ch->id; $cuentaId = $cuenta?->id;
                        break;
                    case 'cheque_tercero':
                        $ch = Cheque::enCartera()->lockForUpdate()->findOrFail($m['cheque_id'] ?? 0);
                        if (abs((float) $ch->monto - (float) $m['monto']) > 0.005) {
                            throw ValidationException::withMessages(['medios' => "El cheque {$ch->numero} es por $ " . number_format((float) $ch->monto, 2, ',', '.') . '; se entrega por su valor completo.']);
                        }
                        $ch->update(['estado' => 'entregado', 'pago_id' => $pago->id, 'fecha_estado' => today()]);
                        $chequeId = $ch->id;
                        break;
                    case 'retencion':
                        Retencion::create([
                            'business_id' => $user->business_id, 'pago_id' => $pago->id, 'contact_id' => $proveedor->id, 'tipo' => $d['tipo'] ?? 'ganancias', 'jurisdiccion' => $d['jurisdiccion'] ?? null,
                            'base' => $d['base'] ?? $m['monto'], 'alicuota' => $d['alicuota'] ?? 0, 'monto' => $m['monto'], 'certificado' => $d['certificado'] ?? null, 'fecha' => $pago->fecha,
                        ]);
                        break;
                    default:
                        $cuenta = $this->fondos->cuentaPara($m['medio'], $user, $m['cuenta_fondos_id'] ?? null);
                        if (! $cuenta) {
                            throw ValidationException::withMessages(['medios' => 'No hay una cuenta de fondos para ese medio. Creala en Fondos.']);
                        }
                        $cuentaId = $cuenta->id;
                        $this->fondos->registrar($cuenta, ['fecha' => $pago->fecha, 'origen' => 'pago', 'origen_id' => $pago->id, 'concepto' => "Pago {$pago->numeroFormateado()} · {$proveedor->name}" . ($m['moneda'] === 'USD' ? " · USD " . number_format($m['monto_me'], 2, ',', '.') : ''), 'egreso' => $m['moneda'] === 'USD' && $cuenta->moneda === 'USD' ? $m['monto_me'] : (float) $m['monto'], 'cotizacion' => $m['moneda'] === 'USD' && $cuenta->moneda === 'USD' ? $m['cotizacion'] : 1, 'referencia' => $m['referencia'] ?? null]);
                }
                $pago->medios()->create(['medio' => $m['medio'], 'monto' => $m['monto'], 'cuenta_fondos_id' => $cuentaId, 'cheque_id' => $chequeId, 'referencia' => $m['referencia'] ?? null, 'datos' => $d ?: null, 'moneda' => $m['moneda'], 'cotizacion' => $m['cotizacion'], 'monto_me' => $m['monto_me']]);
            }

            $difTotal = 0; $aplicadoTotal = 0;
            foreach ($imputaciones as $i) {
                $comp = Comprobante::compras()->lockForUpdate()->findOrFail($i['comprobante_id']);
                abort_if($comp->contact_id !== $proveedor->id, 422, 'El comprobante no pertenece al proveedor.');
                [$monto, $baja, $dif] = \App\Services\Comprobantes\CobroService::aplicarEnMonedaExtranjera($comp, (float) $i['monto'], $cotHoy);
                if ($monto <= 0) continue;
                $pago->imputaciones()->create(['comprobante_id' => $comp->id, 'monto' => $monto, 'dif_cambio' => $dif]);
                $comp->decrement('saldo', $baja);
                $difTotal += $dif; $aplicadoTotal += $monto;
            }
            if (abs($difTotal) > 0.005 || abs($aplicadoTotal - $totalImputado) > 0.005) $pago->forceFill(['a_cuenta' => round($cancela - $aplicadoTotal, 2)])->save();

            CuentaCorriente::create(['business_id' => $user->business_id, 'contact_id' => $proveedor->id, 'pago_id' => $pago->id, 'fecha' => $pago->fecha, 'tipo' => 'pago', 'concepto' => "Orden de pago {$pago->numeroFormateado()}" . ($descuento > 0 ? ' (con descuento)' : '') . (abs($difTotal) > 0.005 ? ' · dif. de cambio $ ' . number_format($difTotal, 2, ',', '.') : ''), 'debe' => 0, 'haber' => round($cancela - $difTotal, 2)]);
            CuentaCorriente::recalcularSaldo($proveedor->id);

            AuditLog::registrar('crear', $pago, "Pago {$pago->numeroFormateado()} a {$proveedor->name} por $ " . number_format($total, 2, ',', '.'));
            app(\App\Services\Integraciones\WebhookService::class)->disparar($pago->business_id, 'pago.registrado', ['id' => $pago->id, 'numero' => $pago->numeroFormateado(), 'fecha' => $pago->fecha?->toDateString(), 'proveedor' => ['id' => $proveedor->id, 'nombre' => $proveedor->name], 'total' => (float) $pago->total]);
            app(\App\Services\Contabilidad\ContabilidadService::class)->contabilizar($pago->fresh(['medios', 'contact']));
            return $pago->fresh(['medios', 'imputaciones', 'retenciones']);
        });
    }

    // Aplica el saldo "a cuenta" de una orden de pago ya registrada a facturas de compra pendientes.
    public function aplicarACuenta(Pago $pago, array $data): Pago
    {
        return DB::transaction(function () use ($pago, $data) {
            abort_if($pago->estado === 'anulado', 422, 'La orden de pago está anulada.');
            $pago = Pago::lockForUpdate()->findOrFail($pago->id);
            $disponible = round((float) $pago->a_cuenta, 2);
            abort_if($disponible <= 0, 422, 'Esta orden de pago no tiene saldo a cuenta para aplicar.');
            $cotHoy = (float) ($data['cotizacion'] ?? 0) ?: (float) $pago->cotizacion ?: (float) \App\Models\Cotizacion::valor($pago->business_id);
            $pedido = array_sum(array_map(fn($i) => (float) $i['monto'], $data['imputaciones'] ?? []));
            if ($pedido > $disponible + 0.005) throw ValidationException::withMessages(['imputaciones' => 'Lo imputado supera el saldo a cuenta ($ ' . number_format($disponible, 2, ',', '.') . ').']);

            $difTotal = 0; $aplicado = 0;
            foreach ($data['imputaciones'] ?? [] as $i) {
                $comp = Comprobante::compras()->lockForUpdate()->findOrFail($i['comprobante_id']);
                abort_if($comp->contact_id !== $pago->contact_id, 422, 'El comprobante no pertenece al proveedor.');
                [$monto, $baja, $dif] = \App\Services\Comprobantes\CobroService::aplicarEnMonedaExtranjera($comp, min((float) $i['monto'], $disponible - $aplicado), $cotHoy);
                if ($monto <= 0) continue;
                $pago->imputaciones()->create(['comprobante_id' => $comp->id, 'monto' => $monto, 'dif_cambio' => $dif]);
                $comp->decrement('saldo', $baja);
                $difTotal += $dif; $aplicado += $monto;
            }
            abort_if($aplicado <= 0, 422, 'No se aplicó nada: indicá montos sobre facturas pendientes.');
            $pago->forceFill(['a_cuenta' => round($disponible - $aplicado, 2), 'notas' => trim(($pago->notas ?? '') . "\nAplicado a facturas $ " . number_format($aplicado, 2, ',', '.') . ' el ' . today()->format('d/m/Y'))])->save();

            if (abs($difTotal) > 0.005) {
                CuentaCorriente::create(['business_id' => $pago->business_id, 'contact_id' => $pago->contact_id, 'pago_id' => $pago->id, 'fecha' => today(), 'tipo' => 'ajuste', 'concepto' => "Dif. de cambio al aplicar orden de pago {$pago->numeroFormateado()}", 'debe' => $difTotal > 0 ? $difTotal : 0, 'haber' => $difTotal < 0 ? -$difTotal : 0]);
                app(\App\Services\Contabilidad\ContabilidadService::class)->asientoPorClaves($pago->business_id, $pago->business_location_id, today(), "Dif. de cambio aplicación orden de pago {$pago->numeroFormateado()}", 'pago', $pago->id, $difTotal > 0
                    ? [['clave' => 'dif_cambio_neg', 'debe' => $difTotal], ['clave' => 'proveedores', 'haber' => $difTotal, 'contact_id' => $pago->contact_id]]
                    : [['clave' => 'proveedores', 'debe' => -$difTotal, 'contact_id' => $pago->contact_id], ['clave' => 'dif_cambio', 'haber' => -$difTotal]]);
            }
            CuentaCorriente::recalcularSaldo($pago->contact_id);
            AuditLog::registrar('editar', $pago, "Aplicó $ " . number_format($aplicado, 2, ',', '.') . " a cuenta de la orden de pago {$pago->numeroFormateado()} a facturas");
            return $pago->fresh(['imputaciones']);
        });
    }

    public function anular(Pago $pago, string $motivo = ''): void
    {
        DB::transaction(function () use ($pago, $motivo) {
            abort_if($pago->estado === 'anulado', 422, 'Ya está anulada.');
            foreach ($pago->imputaciones as $i) {
                Comprobante::where('id', $i->comprobante_id)->increment('saldo', round((float) $i->monto - (float) $i->dif_cambio, 2));
            }
            $pago->imputaciones()->delete();
            foreach ($pago->medios as $m) {
                if ($m->cheque_id) {
                    $ch = Cheque::find($m->cheque_id);
                    if ($ch?->tipo === 'propio') {
                        abort_if($ch->estado === 'pagado', 422, "El cheque propio {$ch->numero} ya se debitó; no se puede anular.");
                        $ch->update(['estado' => 'anulado', 'fecha_estado' => today()]);
                    } elseif ($ch?->tipo === 'tercero') {
                        $ch->update(['estado' => 'cartera', 'pago_id' => null, 'fecha_estado' => today()]);
                    }
                }
            }
            $pago->retenciones()->delete();
            $this->fondos->revertir('pago', $pago->id);
            CuentaCorriente::where('pago_id', $pago->id)->delete();
            $pago->update(['estado' => 'anulado', 'notas' => trim(($pago->notas ?? '') . "\nAnulada: {$motivo}")]);
            CuentaCorriente::recalcularSaldo($pago->contact_id);
            AuditLog::registrar('anular', $pago, "Anuló pago {$pago->numeroFormateado()}: {$motivo}");
            app(\App\Services\Contabilidad\ContabilidadService::class)->anular('pago', $pago->id, $motivo);
        });
    }
}
