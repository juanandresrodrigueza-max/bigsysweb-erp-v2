<?php

namespace App\Console\Commands;

use App\Services\Afip\ArcaVencimiento;
use Illuminate\Console\Command;

// Fase 123.3: avisa a los administradores de BigSys cuando el certificado de ARCA está por vencer (30, 7 y 1 día antes, y vencido).
class ArcaVencimientoCommand extends Command
{
    protected $signature = 'arca:vencimiento';
    protected $description = 'Avisa por mail, WhatsApp y celular cuando el certificado de BigSys para ARCA está por vencer';

    public function handle(ArcaVencimiento $v): int
    {
        $r = $v->revisar();
        $this->info($r['aviso'] ? "Aviso enviado ({$r['aviso']}): {$r['asunto']}" : $r['motivo']);
        return self::SUCCESS;
    }
}
