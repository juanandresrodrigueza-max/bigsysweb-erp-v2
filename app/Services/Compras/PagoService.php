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
            $medios = collect($data['medios'])->filter(fn($m) => (float) $m['monto'] > 0)->values();
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

            $ultimo = (int) Pago::withoutGlobalScopes()->where('business_id', $user->business_id)->max('numero');
            $pago = Pago::create([
                'business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'contact_id' => $proveedor->id, 'user_id' => $user->id,
                'numero' => $ultimo + 1, 'fecha' => $data['fecha'] ?? today(), 'total' => $total, 'descuento' => $descuento, 'interes' => $interes, 'a_cuenta' => round($cancela - $totalImputado, 2), 'notas' => $data['notas'] ?? null,
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
                        $this->fondos->registrar($cuenta, ['fecha' => $pago->fecha, 'origen' => 'pago', 'origen_id' => $pago->id, 'concepto' => "Pago {$pago->numeroFormateado()} · {$proveedor->name}", 'egreso' => (float) $m['monto'], 'referencia' => $m['referencia'] ?? null]);
                }
                $pago->medios()->create(['medio' => $m['medio'], 'monto' => $m['monto'], 'cuenta_fondos_id' => $cuentaId, 'cheque_id' => $chequeId, 'referencia' => $m['referencia'] ?? null, 'datos' => $d ?: null]);
            }

            foreach ($imputaciones as $i) {
                $comp = Comprobante::compras()->lockForUpdate()->findOrFail($i['comprobante_id']);
                abort_if($comp->contact_id !== $proveedor->id, 422, 'El comprobante no pertenece al proveedor.');
                $monto = min((float) $i['monto'], (float) $comp->saldo);
                if ($monto <= 0) continue;
                $pago->imputaciones()->create(['comprobante_id' => $comp->id, 'monto' => $monto]);
                $comp->decrement('saldo', $monto);
            }

            CuentaCorriente::create(['business_id' => $user->business_id, 'contact_id' => $proveedor->id, 'pago_id' => $pago->id, 'fecha' => $pago->fecha, 'tipo' => 'pago', 'concepto' => "Orden de pago {$pago->numeroFormateado()}" . ($descuento > 0 ? ' (con descuento)' : ''), 'debe' => 0, 'haber' => $cancela]);
            CuentaCorriente::recalcularSaldo($proveedor->id);

            AuditLog::registrar('crear', $pago, "Pago {$pago->numeroFormateado()} a {$proveedor->name} por $ " . number_format($total, 2, ',', '.'));
            app(\App\Services\Contabilidad\ContabilidadService::class)->contabilizar($pago->fresh(['medios', 'contact']));
            return $pago->fresh(['medios', 'imputaciones', 'retenciones']);
        });
    }

    public function anular(Pago $pago, string $motivo = ''): void
    {
        DB::transaction(function () use ($pago, $motivo) {
            abort_if($pago->estado === 'anulado', 422, 'Ya está anulada.');
            foreach ($pago->imputaciones as $i) {
                Comprobante::where('id', $i->comprobante_id)->increment('saldo', (float) $i->monto);
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
