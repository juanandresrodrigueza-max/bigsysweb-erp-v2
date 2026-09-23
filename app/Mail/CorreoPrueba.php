<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

// Correo de prueba que se manda desde el panel superadmin para comprobar el SMTP.
class CorreoPrueba extends Mailable
{
    public function build(): static
    {
        return $this->subject('Prueba de correo · BigSysWeb')->html('<p>Este es un correo de prueba de <b>BigSysWeb</b>.</p><p>Si lo estás leyendo, el envío de mails está bien configurado.</p>');
    }
}
