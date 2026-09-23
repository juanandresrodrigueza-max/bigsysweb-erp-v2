<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

// Aviso al administrador de BigSys cuando un control de salud del servidor está en crítico.
class AvisoSalud extends Mailable
{
    public function __construct(public array $criticos, public array $salud) {}

    public function build(): static
    {
        $lis = implode('', array_map(fn($c) => '<li><b>' . e($c['nombre']) . '</b>: ' . e($c['detalle']) . '</li>', $this->criticos));
        return $this->subject('⚠ BigSysWeb: ' . count($this->criticos) . ' control(es) en crítico')
            ->html('<p>El servidor de <b>BigSysWeb</b> tiene controles en estado crítico:</p><ul>' . $lis . '</ul><p>Revisá el panel <b>Salud</b> del superadmin (' . e(url('/admin/salud')) . '). Este aviso no se repite por el mismo control durante 6 horas.</p>');
    }
}
