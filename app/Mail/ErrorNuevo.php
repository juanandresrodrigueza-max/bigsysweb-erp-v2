<?php

namespace App\Mail;

use App\Models\ErrorSistema;
use Illuminate\Mail\Mailable;

// Fase 76: aviso al administrador de BigSys cuando aparece un error nuevo en el sistema (o vuelve uno que se había marcado resuelto).
class ErrorNuevo extends Mailable
{
    public function __construct(public ErrorSistema $error, public bool $volvio = false) {}

    public function build(): static
    {
        $e = $this->error;
        return $this->subject(($this->volvio ? '↩ Volvió un error' : '🆕 Error nuevo') . ' en BigSysWeb: ' . class_basename($e->clase))
            ->html('<p>' . ($this->volvio ? 'Un error que estaba marcado como resuelto <b>volvió a pasar</b>' : 'Apareció un <b>error nuevo</b>') . ' en <b>BigSysWeb</b>:</p>'
                . '<p><b>' . e(class_basename($e->clase)) . '</b>: ' . e(\Illuminate\Support\Str::limit($e->mensaje, 300)) . '</p>'
                . '<ul><li>Dónde: <code>' . e($e->archivo) . ':' . (int) $e->linea . '</code></li><li>Ruta: ' . e((string) $e->ruta) . '</li>'
                . '<li>Empresa: ' . ($e->business_id ? '#' . (int) $e->business_id : 'ninguna') . ' · usuario: ' . ($e->user_id ? '#' . (int) $e->user_id : 'ninguno') . '</li>'
                . '<li>Código para el soporte: <code>' . e(substr($e->hash, 0, 8)) . '</code></li></ul>'
                . '<p>Los detalles y la traza están en el panel <b>Salud</b> del superadmin (' . e(url('/admin/salud')) . '). Si alguien de la empresa lo reporta desde la pantalla de error, el ticket llega con este mismo código.</p>');
    }
}
