<?php

namespace App\Services\Correo;

// La conexión de verdad: un socket.
class ConexionSocket
{
    public function __construct(private $s) {}

    public function escribir(string $t): void { fwrite($this->s, $t); }

    public function linea(): string|false
    {
        $l = fgets($this->s);
        return ($l === false || $l === '') ? false : $l;
    }

    public function bytes(int $n): string
    {
        $b = '';
        while (strlen($b) < $n) {
            $t = fread($this->s, min(65536, $n - strlen($b)));
            if ($t === false || $t === '') break;
            $b .= $t;
        }
        return $b;
    }
}
