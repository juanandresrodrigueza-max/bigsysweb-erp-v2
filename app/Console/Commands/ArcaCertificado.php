<?php

namespace App\Console\Commands;

use App\Services\Afip\ArcaPlataforma;
use Illuminate\Console\Command;

// Fase 121: carga el certificado de BigSys para ARCA desde archivos del servidor (sin pasar por el navegador ni el chat).
//   php artisan arca:certificado /ruta/cert.crt /ruta/clave.key --produccion
// Sin clave usa la del pedido generado en Panel → Sistema. Después: php artisan arca:certificado --probar
class ArcaCertificado extends Command
{
    protected $signature = 'arca:certificado {cert? : Ruta del .crt} {key? : Ruta de la clave privada} {--produccion} {--homologacion} {--probar : Sólo prueba lo que ya está cargado}';
    protected $description = 'Carga (o prueba) el certificado de BigSys con el que el ERP factura en nombre de las empresas';

    public function handle(): int
    {
        if (! $this->option('probar')) {
            $cert = $this->argument('cert');
            if (! $cert || ! is_readable($cert)) { $this->error('Pasá la ruta del certificado (.crt).'); return self::FAILURE; }
            $key = $this->argument('key');
            if ($key && ! is_readable($key)) { $this->error("No se puede leer la clave: {$key}"); return self::FAILURE; }
            $prod = $this->option('produccion') ? true : ($this->option('homologacion') ? false : null);
            try { $info = ArcaPlataforma::cargar(file_get_contents($cert), $key ? file_get_contents($key) : null, $prod); }
            catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
            ArcaPlataforma::limpiarTickets();
            $this->info("Certificado cargado: alias {$info['alias']}, CUIT {$info['cuit']}, vence el {$info['vence']->format('d/m/Y')}.");
        }
        $r = ArcaPlataforma::probar();
        foreach ($r['pasos'] as $p) $this->line(($p['ok'] ? '✓ ' : '✗ ') . "{$p['nombre']}: {$p['detalle']}");
        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
