<?php

namespace App\Services\Afip;

// Error de ARCA con el detalle ya legible y, si lo hay, el código (600, 602, 10016…). Se distingue de un error de red o de
// programación para que quien llama pueda mostrarlo tal cual. El mensaje lleva el código entre paréntesis, «(10016) …»,
// que es el formato que ya entiende AfipErrores.
class ArcaError extends \RuntimeException
{
    public function __construct(string $mensaje, public readonly ?string $codigo = null)
    {
        parent::__construct($mensaje);
    }
}
