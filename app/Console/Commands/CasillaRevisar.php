<?php

namespace App\Console\Commands;

use App\Services\Compras\BandejaFacturasService;
use Illuminate\Console\Command;

// Fase 120.3: baja las facturas que llegaron por mail a la casilla y las deja leídas para revisar (cada 5 minutos).
class CasillaRevisar extends Command
{
    protected $signature = 'casilla:revisar';
    protected $description = 'Lee la casilla de facturas y deja cada factura a medio cargar en la empresa que corresponde';

    public function handle(BandejaFacturasService $svc): int
    {
        if (! BandejaFacturasService::configurada()) { $this->line('La casilla de facturas no está configurada (Panel → Sistema).'); return self::SUCCESS; }
        $r = $svc->revisar();
        $this->info("Casilla: {$r['correos']} correos, {$r['facturas']} facturas para revisar, {$r['sin_empresa']} sin empresa." . ($r['error'] ? " Error: {$r['error']}" : ''));
        return self::SUCCESS;
    }
}
