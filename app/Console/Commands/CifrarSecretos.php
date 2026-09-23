<?php

namespace App\Console\Commands;

use App\Services\Afip\CertificadoCifrado;
use Illuminate\Console\Command;

// Pasa a cifrado lo que haya quedado en claro de versiones anteriores (certificados AFIP en disco). Las credenciales en la base se cifran con la migración.
class CifrarSecretos extends Command
{
    protected $signature = 'erp:cifrar-secretos';
    protected $description = 'Cifra certificados AFIP que estén guardados sin cifrar';

    public function handle(): int
    {
        $n = CertificadoCifrado::cifrarExistentes();
        $this->info("Certificados cifrados en {$n} empresa(s).");
        return self::SUCCESS;
    }
}
