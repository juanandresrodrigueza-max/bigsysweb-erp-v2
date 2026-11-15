<?php

namespace App\Services\Agentes;

use App\Models\Business;
use App\Models\Propuesta;
use App\Models\User;

// Fase 107: un agente de Bigcito. De noche mira la empresa y deja propuestas; aprobar una ejecuta la acción con los servicios de siempre.
// Cada propuesta: ['clave', 'titulo', 'detalle', 'datos', 'accion' (null = sólo enterarse), 'importe', 'prioridad'].
interface Agente
{
    public function nombre(): string;

    /** @return array<int, array> */
    public function proponer(Business $b): array;

    /** Ejecuta la acción de una propuesta aprobada y devuelve el resultado en palabras. */
    public function ejecutar(Propuesta $p, User $u): string;
}
