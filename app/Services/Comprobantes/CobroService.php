<?php

namespace App\Services\Comprobantes;

use App\Models\AuditLog;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CobroService
{
    // Registra un cobro con uno o más medios e imputa a comprobantes (o queda a cuenta).
    public function registrar(Contact $contact, array $data): Cobro
    {
        return DB::transaction(function () use ($contact, $data) {
            $user = Auth::user();
            $medios = collect($data['medios'])->filter(fn($m) => (float) $m['monto'] > 0)->values();
            $total  = round($medios->sum(fn($m) => (float) $m['monto']), 2);
            if ($total <= 0) {
                throw ValidationException::withMessages(['medios' => 'Ingresá al menos un medio de pago con importe.']);
            }

            $imputaciones = collect($data['imputaciones'] ?? [])->filter(fn($i) => (float) $i['monto'] > 0)->values();
            $totalImputado = round($imputaciones->sum(fn($i) => (float) $i['monto']), 2);
            if ($totalImputado > $total + 0.005) {
                throw ValidationException::withMessages(['imputaciones' => 'Lo imputado supera el total cobrado.']);
            }

            $ultimo = (int) Cobro::withoutGlobalScopes()->where('business_id', $user->business_id)->max('numero');
            $cobro = Cobro::create([
                'business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'contact_id' => $contact->id, 'user_id' => $user->id,
                'numero' => $ultimo + 1, 'fecha' => $data['fecha'] ?? today(), 'total' => $total, 'a_cuenta' => round($total - $totalImputado, 2), 'notas' => $data['notas'] ?? null,
            ]);

            foreach ($medios as $m) {
                $cobro->medios()->create(['medio' => $m['medio'], 'monto' => $m['monto'], 'referencia' => $m['referencia'] ?? null, 'datos' => $m['datos'] ?? null]);
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
                'fecha' => $cobro->fecha, 'tipo' => 'cobro', 'concepto' => "Recibo {$cobro->numeroFormateado()}", 'debe' => 0, 'haber' => $total,
            ]);
            CuentaCorriente::recalcularSaldo($contact->id);

            AuditLog::registrar('crear', $cobro, "Cobro {$cobro->numeroFormateado()} a {$contact->name} por $ " . number_format($total, 2, ',', '.'));
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
            CuentaCorriente::where('cobro_id', $cobro->id)->delete();
            $cobro->update(['estado' => 'anulado', 'notas' => trim(($cobro->notas ?? '') . "\nAnulado: {$motivo}")]);
            CuentaCorriente::recalcularSaldo($cobro->contact_id);
            AuditLog::registrar('anular', $cobro, "Anuló cobro {$cobro->numeroFormateado()}: {$motivo}");
        });
    }
}
