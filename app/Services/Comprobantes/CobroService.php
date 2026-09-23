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
    public function registrar(Contact $contact, array $data): Cobro
    {
        return DB::transaction(function () use ($contact, $data) {
            $user = Auth::user();
            $medios = collect($data['medios'])->filter(fn($m) => (float) $m['monto'] > 0)->values();
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

            $ultimo = (int) Cobro::withoutGlobalScopes()->where('business_id', $user->business_id)->max('numero');
            $cobro = Cobro::create([
                'business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'contact_id' => $contact->id, 'user_id' => $user->id,
                'vendedor_id' => $data['vendedor_id'] ?? $contact->vendedor_id ?? \App\Models\Vendedor::deUsuario($user->id)?->id,
                'numero' => $ultimo + 1, 'fecha' => $data['fecha'] ?? today(), 'total' => $total, 'descuento' => $descuento, 'interes' => $interes, 'a_cuenta' => round($cancela - $totalImputado, 2), 'notas' => $data['notas'] ?? null,
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
                        $this->fondos->registrar($cuenta, ['fecha' => $cobro->fecha, 'origen' => 'cobro', 'origen_id' => $cobro->id, 'concepto' => "Cobro {$cobro->numeroFormateado()} · {$contact->name}", 'ingreso' => (float) $m['monto'], 'referencia' => $m['referencia'] ?? null]);
                    }
                }
                $medioRow = $cobro->medios()->create(['medio' => $m['medio'], 'monto' => $m['monto'], 'cuenta_fondos_id' => $cuentaId, 'cheque_id' => $chequeId, 'referencia' => $m['referencia'] ?? null, 'datos' => $m['datos'] ?? null]);
                if ($m['medio'] === 'tarjeta') app(\App\Services\Fondos\TarjetasService::class)->cuponDesdeCobro($cobro, $medioRow);
            }

            foreach ($imputaciones as $i) {
                $comp = Comprobante::lockForUpdate()->findOrFail($i['comprobante_id']);
                abort_if($comp->contact_id !== $contact->id, 422, 'El comprobante no pertenece al cliente.');
                $monto = min((float) $i['monto'], (float) $comp->saldo);
                if ($monto <= 0) {
                    continue;
                }
                $cobro->imputaciones()->create(['comprobante_id' => $comp->id, 'monto' => $monto]);
                $comp->decrement('saldo', $monto);
            }

            CuentaCorriente::create([
                'business_id' => $user->business_id, 'contact_id' => $contact->id, 'cobro_id' => $cobro->id,
                'fecha' => $cobro->fecha, 'tipo' => 'cobro', 'concepto' => "Recibo {$cobro->numeroFormateado()}" . ($descuento > 0 ? ' (con descuento)' : '') . ($interes > 0 ? ' (con interés)' : ''), 'debe' => 0, 'haber' => $cancela,
            ]);
            CuentaCorriente::recalcularSaldo($contact->id);

            AuditLog::registrar('crear', $cobro, "Cobro {$cobro->numeroFormateado()} a {$contact->name} por $ " . number_format($total, 2, ',', '.'));
            app(\App\Services\Contabilidad\ContabilidadService::class)->contabilizar($cobro->fresh(['medios', 'contact']));
            app(\App\Services\Integraciones\WebhookService::class)->disparar($cobro->business_id, 'cobro.registrado', ['id' => $cobro->id, 'numero' => $cobro->numeroFormateado(), 'fecha' => $cobro->fecha?->toDateString(), 'cliente' => ['id' => $contact->id, 'nombre' => $contact->name], 'total' => (float) $cobro->total, 'medios' => $cobro->medios->map(fn($m) => ['medio' => $m->medio, 'monto' => (float) $m->monto])->all()]);
            return $cobro->fresh(['medios', 'imputaciones']);
        });
    }

    public function anular(Cobro $cobro, string $motivo = ''): void
    {
        DB::transaction(function () use ($cobro, $motivo) {
            abort_if($cobro->estado === 'anulado', 422, 'Ya está anulado.');
            foreach ($cobro->imputaciones as $i) {
                Comprobante::where('id', $i->comprobante_id)->increment('saldo', (float) $i->monto);
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
