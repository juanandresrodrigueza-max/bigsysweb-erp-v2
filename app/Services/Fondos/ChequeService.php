<?php

namespace App\Services\Fondos;

use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\CuentaFondos;
use Illuminate\Support\Facades\DB;

class ChequeService
{
    public function __construct(private FondosService $fondos) {}

    public function depositar(Cheque $c, CuentaFondos $banco): Cheque
    {
        abort_if($c->tipo !== 'tercero' || $c->estado !== 'cartera', 422, 'Solo se depositan cheques de terceros en cartera.');
        abort_if($banco->tipo !== 'banco', 422, 'Elegí una cuenta bancaria.');
        return DB::transaction(function () use ($c, $banco) {
            $c->update(['estado' => 'depositado', 'cuenta_fondos_id' => $banco->id, 'fecha_estado' => today()]);
            $this->fondos->registrar($banco, ['fecha' => max($c->fecha_pago, today()), 'origen' => 'cheque', 'origen_id' => $c->id, 'concepto' => "Depósito cheque {$c->numero} {$c->banco} ({$c->emisor})", 'ingreso' => (float) $c->monto]);
            AuditLog::registrar('editar', $c, "Depositó cheque {$c->numero} en {$banco->nombre}");
            return $c;
        });
    }

    public function rechazar(Cheque $c, string $motivo = ''): Cheque
    {
        return DB::transaction(function () use ($c, $motivo) {
            if ($c->estado === 'depositado' && $c->cuenta_fondos_id) {
                $this->fondos->registrar($c->cuenta, ['origen' => 'cheque', 'origen_id' => $c->id, 'concepto' => "Rechazo cheque {$c->numero} {$c->banco}", 'egreso' => (float) $c->monto]);
            }
            $c->update(['estado' => 'rechazado', 'fecha_estado' => today(), 'notas' => trim(($c->notas ?? '') . "\nRechazado: {$motivo}")]);
            AuditLog::registrar('editar', $c, "Cheque {$c->numero} rechazado: {$motivo}");
            return $c;
        });
    }

    public function marcarCobrado(Cheque $c): Cheque
    {
        abort_if($c->estado !== 'depositado', 422, 'Solo se acreditan cheques depositados.');
        $c->update(['estado' => 'cobrado', 'fecha_estado' => today()]);
        return $c;
    }

    // Cheque propio entregado a un proveedor: se debita del banco cuando se paga.
    public function debitarPropio(Cheque $c): Cheque
    {
        abort_if($c->tipo !== 'propio' || $c->estado !== 'entregado', 422, 'El cheque no está pendiente de débito.');
        return DB::transaction(function () use ($c) {
            $this->fondos->registrar($c->cuenta, ['fecha' => $c->fecha_pago, 'origen' => 'cheque', 'origen_id' => $c->id, 'concepto' => "Débito cheque propio {$c->numero} ({$c->emisor})", 'egreso' => (float) $c->monto]);
            $c->update(['estado' => 'pagado', 'fecha_estado' => today()]);
            return $c;
        });
    }
}
