<?php

namespace App\Console\Commands;

use App\Models\Canal;
use App\Services\Canales\CanalesService;
use App\Services\Canales\PublicacionesService;
use Illuminate\Console\Command;

// Cada 5 minutos: precio y stock hacia las tiendas (lo que cambió). Cada 10: pregunta a cada tienda qué ventas pagas hubo, por si un aviso se perdió.
class CanalesSincronizar extends Command
{
    protected $signature = 'canales:sincronizar {--pedidos : También importar pedidos}';
    protected $description = 'Mantiene las publicaciones (precio y stock) y, con --pedidos, importa las ventas de cada tienda';

    public function handle(PublicacionesService $pub, CanalesService $canales): int
    {
        foreach (Canal::withoutGlobalScopes()->where('activo', true)->whereIn('tipo', ['mercadolibre', 'woocommerce', 'shopify', 'tiendanube'])->get() as $c) {
            try {
                $r = $pub->sincronizar($c);
                $this->line("{$c->nombre}: {$r['actualizadas']} actualizadas, {$r['errores']} errores");
                if ($this->option('pedidos') && $c->importar_pedidos) { $i = $canales->importarPedidos($c); $this->line("  pedidos: {$i['importados']}" . ($i['error'] ? " · {$i['error']}" : '')); }
            } catch (\Throwable $e) { $this->error("{$c->nombre}: {$e->getMessage()}"); }
        }
        // Fase 54: Rappi no avisa los pedidos, hay que preguntarle.
        if ($this->option('pedidos')) foreach (Canal::withoutGlobalScopes()->where('activo', true)->where('tipo', 'rappi')->where('importar_pedidos', true)->get() as $c) {
            try { $i = $canales->importarPedidos($c); $this->line("{$c->nombre}: pedidos {$i['importados']}" . ($i['error'] ? " · {$i['error']}" : '')); } catch (\Throwable $e) { $this->error("{$c->nombre}: {$e->getMessage()}"); }
        }
        return self::SUCCESS;
    }
}
