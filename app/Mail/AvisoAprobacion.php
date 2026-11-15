<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

// Fase 106: los avisos de altas con aprobación (a BigSys: alta nueva, por vencer, bloqueada, resumen de vencimientos; al dueño: aprobada).
class AvisoAprobacion extends Mailable
{
    public function __construct(public string $asunto, public string $texto, public ?string $url = null) {}

    public function build(): static
    {
        $html = '<div style="font-family:sans-serif;max-width:600px;margin:0 auto">' . nl2br(e($this->texto))
            . ($this->url ? '<p style="margin-top:20px"><a href="' . e($this->url) . '" style="background:#a42785;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">Abrir en BigSys Web</a></p>' : '') . '</div>';
        return $this->subject($this->asunto)->html($html);
    }

    public function render(): string { return $this->texto . ($this->url ? "\n" . $this->url : ''); }
}
