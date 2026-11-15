<?php

namespace App\Console\Commands;

use App\Services\MercadoPago\MercadoPagoMovimientosService;
use Illuminate\Console\Command;

// Fase 99: cada madrugada, los movimientos de Mercado Pago de las empresas que lo pidieron.
class MercadoPagoMovimientos extends Command
{
    protected $signature = 'mercadopago:movimientos';
    protected $description = 'Trae los cobros, comisiones y devoluciones de Mercado Pago de cada empresa a su cuenta de billetera y los concilia';

    public function handle(MercadoPagoMovimientosService $svc): int
    {
        foreach ($svc->traerTodas() as $empresa => $r) $this->line($empresa . ': ' . (isset($r['error']) ? "ERROR {$r['error']}" : "{$r['nuevos']} nuevos, {$r['conciliados']} resueltos"));
        return self::SUCCESS;
    }
}
