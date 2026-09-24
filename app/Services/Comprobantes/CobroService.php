<?php

namespace App\Services\Comprobantes;

use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CobroService
{
    public function __construct(private FondosService $fondos) {}

    // Registra un cobro con uno o más medios, impacta fondos/cheques e imputa a comprobantes (o queda a cuenta).

    // Factura en dólares: lo que cancela es el saldo en USD a la cotización de la factura; lo que se cobra/paga son pesos a la cotización de hoy.
    // La diferencia entre ambos es diferencia de cambio. Devuelve [pesos aplicados, pesos que bajan del saldo, diferencia].
    public static function aplicarEnMonedaExtranjera(Comprobante $comp, float $montoArs, float $cotHoy): array
    {
        if (($comp->moneda ?? 'ARS') === 'ARS' || (float) $comp->cotizacion <= 0 || $cotHoy <= 0) { $m = min($montoArs, (float) $comp->saldo); return [$m, $m, 0.0]; }
        $usdPend = round((float) $comp->saldo / (float) $comp->cotizacion, 2);
        $usd = min(round($montoArs / $cotHoy, 2), $usdPend);
        $cancela = $usd >= $usdPend - 0.005 ? (float) $comp->saldo : round($usd * (float) $comp->cotizacion, 2);
        $aplicado = $usd >= $usdPend - 0.005 ? min($montoArs, round($usd * $cotHoy, 2)) : round($usd * $cotHoy, 2);
        return [round($aplicado, 2), round($cancela, 2), round($aplicado - $cancela, 2)];
    }

    public function registrar(Contact $contact, array $data): Cobro
    {
        return DB::transaction(function () use ($contact, $data) {
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

            // Descuento: bonificación que cancela deuda sin cobrarse. Interés: se cobra de más y no cancela deuda.
            $descuento = round((float) ($data['descuento'] ?? 0), 2);
            $interes = round((float) ($data['interes'] ?? 0), 2);
            $cancela = round($total + $descuento - $interes, 2); // lo que baja de la cuenta corriente
            if ($cancela <= 0) {
                throw ValidationException::withMessages(['interes' => 'El interés no puede superar lo cobrado.']);
            }
            $imputaciones = collect($data['imputaciones'] ?? [])->filter(fn($i) => (float) $i['monto'] > 0)->values();
            $totalImputado = round($imputaciones->sum(fn($i) => (float) $i['monto']), 2);
            if ($totalImputado > $cancela + 0.005) {
                throw ValidationException::withMessages(['imputaciones' => 'Lo imputado supera lo que cancela este recibo (cobrado + descuento − interés).']);
            }
            // Cotización del recibo: para imputar a facturas en dólares (la del dato, la del medio en USD o la del día).
            $cotHoy = (float) ($data['cotizacion'] ?? 0) ?: (float) ($medios->firstWhere('moneda', 'USD')['cotizacion'] ?? 0) ?: (float) \App\Models\Cotizacion::valor($user->business_id);

            $ultimo = (int) Cobro::withoutGlobalScopes()->where('business_id', $user->business_id)->max('numero');
            $cobro = Cobro::create([
                'business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'contact_id' => $contact->id, 'user_id' => $user->id,
                'vendedor_id' => $data['vendedor_id'] ?? $contact->vendedor_id ?? \App\Models\Vendedor::deUsuario($user->id)?->id,
                // Quién cobró: el elegido, el cobrador asignado al cliente o nadie (entonces la comisión por cobranza es del vendedor).
                'cobrador_id' => $data['cobrador_id'] ?? $contact->cobrador_id,
                'numero' => $ultimo + 1, 'fecha' => $data['fecha'] ?? today(), 'total' => $total, 'descuento' => $descuento, 'interes' => $interes, 'a_cuenta' => round($cancela - $totalImputado, 2), 'notas' => $data['notas'] ?? null, 'cotizacion' => $cotHoy ?: null,
            ]);

            foreach ($medios as $m) {
                $chequeId = null; $cuentaId = null;
                if ($m['medio'] === 'cheque') {
                    $d = $m['datos'] ?? [];
                    $cheque = Cheque::create([
                        'business_id' => $user->business_id, 'tipo' => 'tercero', 'numero' => $d['numero'] ?? ($m['referencia'] ?? 's/n'), 'banco' => $d['banco'] ?? null,
                        'emisor' => $d['emisor'] ?? $contact->name, 'cuit_emisor' => $d['cuit'] ?? $contact->cuit, 'fecha_emision' => $d['fecha_emision'] ?? ($data['fecha'] ?? today()),
                        'fecha_pago' => $d['fecha_pago'] ?? ($data['fecha'] ?? today()), 'monto' => $m['monto'], 'echeq' => (bool) ($d['echeq'] ?? false), 'estado' => 'cartera',
                        'cobro_id' => $cobro->id, 'contact_id' => $contact->id,
                    ]);
                    $chequeId = $cheque->id;
                } elseif ($m['medio'] !== 'retencion' && $m['medio'] !== 'tarjeta') {
                    $cuenta = $this->fondos->cuentaPara($m['medio'], $user, $m['cuenta_fondos_id'] ?? null);
                    if ($cuenta) {
                        $cuentaId = $cuenta->id;
                        $this->fondos->registrar($cuenta, ['fecha' => $cobro->fecha, 'origen' => 'cobro', 'origen_id' => $cobro->id, 'concepto' => "Cobro {$cobro->numeroFormateado()} · {$contact->name}" . ($m['moneda'] === 'USD' ? " · USD " . number_format($m['monto_me'], 2, ',', '.') : ''), 'ingreso' => $m['moneda'] === 'USD' && $cuenta->moneda === 'USD' ? $m['monto_me'] : (float) $m['monto'], 'cotizacion' => $m['moneda'] === 'USD' && $cuenta->moneda === 'USD' ? $m['cotizacion'] : 1, 'referencia' => $m['referencia'] ?? null]);
                    }
                }
                $medioRow = $cobro->medios()->create(['medio' => $m['medio'], 'monto' => $m['monto'], 'cuenta_fondos_id' => $cuentaId, 'cheque_id' => $chequeId, 'referencia' => $m['referencia'] ?? null, 'datos' => $m['datos'] ?? null, 'moneda' => $m['moneda'], 'cotizacion' => $m['cotizacion'], 'monto_me' => $m['monto_me']]);
                if ($m['medio'] === 'tarjeta') app(\App\Services\Fondos\TarjetasService::class)->cuponDesdeCobro($cobro, $medioRow);
            }

            $difTotal = 0; $aplicadoTotal = 0;
            foreach ($imputaciones as $i) {
                $comp = Comprobante::lockForUpdate()->findOrFail($i['comprobante_id']);
                abort_if($comp->contact_id !== $contact->id, 422, 'El comprobante no pertenece al cliente.');
                [$monto, $baja, $dif] = self::aplicarEnMonedaExtranjera($comp, (float) $i['monto'], $cotHoy);
                if ($monto <= 0) {
                    continue;
                }
                $cobro->imputaciones()->create(['comprobante_id' => $comp->id, 'monto' => $monto, 'dif_cambio' => $dif]);
                $comp->decrement('saldo', $baja);
                $difTotal += $dif; $aplicadoTotal += $monto;
            }
            if (abs($difTotal) > 0.005 || abs($aplicadoTotal - $totalImputado) > 0.005) $cobro->forceFill(['a_cuenta' => round($cancela - $aplicadoTotal, 2)])->save();

            CuentaCorriente::create([
                'business_id' => $user->business_id, 'contact_id' => $contact->id, 'cobro_id' => $cobro->id,
                'fecha' => $cobro->fecha, 'tipo' => 'cobro', 'concepto' => "Recibo {$cobro->numeroFormateado()}" . ($descuento > 0 ? ' (con descuento)' : '') . ($interes > 0 ? ' (con interés)' : '') . (abs($difTotal) > 0.005 ? ' · dif. de cambio $ ' . number_format($difTotal, 2, ',', '.') : ''), 'debe' => 0, 'haber' => round($cancela - $difTotal, 2),
            ]);
            CuentaCorriente::recalcularSaldo($contact->id);

            AuditLog::registrar('crear', $cobro, "Cobro {$cobro->numeroFormateado()} a {$contact->name} por $ " . number_format($total, 2, ',', '.'));
            app(\App\Services\Contabilidad\ContabilidadService::class)->contabilizar($cobro->fresh(['medios', 'contact']));
            app(\App\Services\Integraciones\WebhookService::class)->disparar($cobro->business_id, 'cobro.registrado', ['id' => $cobro->id, 'numero' => $cobro->numeroFormateado(), 'fecha' => $cobro->fecha?->toDateString(), 'cliente' => ['id' => $contact->id, 'nombre' => $contact->name], 'total' => (float) $cobro->total, 'medios' => $cobro->medios->map(fn($m) => ['medio' => $m->medio, 'monto' => (float) $m->monto])->all()]);
            \App\Jobs\NotificarCrmJob::avisar($cobro->business_id, 'cobro.registrado', ['id' => $cobro->id, 'numero' => $cobro->numeroFormateado(), 'fecha' => $cobro->fecha?->toDateString(), 'cliente' => ['id' => $contact->id, 'nombre' => $contact->name], 'total' => (float) $cobro->total, 'saldo' => 0, 'estado' => 'registrado']);
            return $cobro->fresh(['medios', 'imputaciones']);
        });
    }

    // Aplica el saldo "a cuenta" de un recibo ya registrado a facturas pendientes (el recibo se pudo hacer sin comprobantes y se imputa después).
    public function aplicarACuenta(Cobro $cobro, array $data): Cobro
    {
        return DB::transaction(function () use ($cobro, $data) {
            abort_if($cobro->estado === 'anulado', 422, 'El recibo está anulado.');
            $cobro = Cobro::lockForUpdate()->findOrFail($cobro->id);
            $disponible = round((float) $cobro->a_cuenta, 2);
            abort_if($disponible <= 0, 422, 'Este recibo no tiene saldo a cuenta para aplicar.');
            $cotHoy = (float) ($data['cotizacion'] ?? 0) ?: (float) $cobro->cotizacion ?: (float) \App\Models\Cotizacion::valor($cobro->business_id);
            $pedido = array_sum(array_map(fn($i) => (float) $i['monto'], $data['imputaciones'] ?? []));
            if ($pedido > $disponible + 0.005) throw ValidationException::withMessages(['imputaciones' => 'Lo imputado supera el saldo a cuenta del recibo ($ ' . number_format($disponible, 2, ',', '.') . ').']);

            $difTotal = 0; $aplicado = 0;
            foreach ($data['imputaciones'] ?? [] as $i) {
                $comp = Comprobante::lockForUpdate()->findOrFail($i['comprobante_id']);
                abort_if($comp->contact_id !== $cobro->contact_id, 422, 'El comprobante no pertenece al cliente.');
                [$monto, $baja, $dif] = self::aplicarEnMonedaExtranjera($comp, min((float) $i['monto'], $disponible - $aplicado), $cotHoy);
                if ($monto <= 0) continue;
                $cobro->imputaciones()->create(['comprobante_id' => $comp->id, 'monto' => $monto, 'dif_cambio' => $dif]);
                $comp->decrement('saldo', $baja);
                $difTotal += $dif; $aplicado += $monto;
            }
            abort_if($aplicado <= 0, 422, 'No se aplicó nada: indicá montos sobre facturas pendientes.');
            $cobro->forceFill(['a_cuenta' => round($disponible - $aplicado, 2), 'notas' => trim(($cobro->notas ?? '') . "\nAplicado a facturas $ " . number_format($aplicado, 2, ',', '.') . ' el ' . today()->format('d/m/Y'))])->save();

            // La diferencia de cambio ajusta la cuenta corriente (el recibo ya la acreditó por lo cobrado) y va a resultado.
            if (abs($difTotal) > 0.005) {
                CuentaCorriente::create(['business_id' => $cobro->business_id, 'contact_id' => $cobro->contact_id, 'cobro_id' => $cobro->id, 'fecha' => today(), 'tipo' => 'ajuste', 'concepto' => "Dif. de cambio al aplicar recibo {$cobro->numeroFormateado()}", 'debe' => $difTotal > 0 ? $difTotal : 0, 'haber' => $difTotal < 0 ? -$difTotal : 0]);
                app(\App\Services\Contabilidad\ContabilidadService::class)->asientoPorClaves($cobro->business_id, $cobro->business_location_id, today(), "Dif. de cambio aplicación recibo {$cobro->numeroFormateado()}", 'cobro', $cobro->id, $difTotal > 0
                    ? [['clave' => 'deudores', 'debe' => $difTotal, 'contact_id' => $cobro->contact_id], ['clave' => 'dif_cambio', 'haber' => $difTotal]]
                    : [['clave' => 'dif_cambio_neg', 'debe' => -$difTotal], ['clave' => 'deudores', 'haber' => -$difTotal, 'contact_id' => $cobro->contact_id]]);
            }
            CuentaCorriente::recalcularSaldo($cobro->contact_id);
            AuditLog::registrar('editar', $cobro, "Aplicó $ " . number_format($aplicado, 2, ',', '.') . " a cuenta del recibo {$cobro->numeroFormateado()} a facturas");
            return $cobro->fresh(['imputaciones']);
        });
    }

    public function anular(Cobro $cobro, string $motivo = ''): void
    {
        DB::transaction(function () use ($cobro, $motivo) {
            abort_if($cobro->estado === 'anulado', 422, 'Ya está anulado.');
            foreach ($cobro->imputaciones as $i) {
                Comprobante::where('id', $i->comprobante_id)->increment('saldo', round((float) $i->monto - (float) $i->dif_cambio, 2));
            }
            $cobro->imputaciones()->delete();
            foreach ($cobro->medios()->whereNotNull('cheque_id')->get() as $m) {
                $ch = Cheque::find($m->cheque_id);
                abort_if($ch && ! in_array($ch->estado, ['cartera', 'rechazado'], true), 422, "El cheque {$ch->numero} ya fue depositado o entregado; no se puede anular el cobro.");
                $ch?->update(['estado' => 'anulado', 'fecha_estado' => today()]);
            }
            $this->fondos->revertir('cobro', $cobro->id);
            abort_if(\App\Models\CuponTarjeta::where('cobro_id', $cobro->id)->where('estado', 'liquidado')->exists(), 422, 'Tiene cupones de tarjeta ya liquidados; anulá primero la liquidación.');
            \App\Models\CuponTarjeta::where('cobro_id', $cobro->id)->delete();
            CuentaCorriente::where('cobro_id', $cobro->id)->delete();
            $cobro->update(['estado' => 'anulado', 'notas' => trim(($cobro->notas ?? '') . "\nAnulado: {$motivo}")]);
            CuentaCorriente::recalcularSaldo($cobro->contact_id);
            AuditLog::registrar('anular', $cobro, "Anuló cobro {$cobro->numeroFormateado()}: {$motivo}");
            app(\App\Services\Contabilidad\ContabilidadService::class)->anular('cobro', $cobro->id, $motivo);
        });
    }
}
