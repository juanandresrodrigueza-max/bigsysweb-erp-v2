<?php

namespace App\Services\Fondos;

use App\Models\Cotizacion;
use Illuminate\Support\Facades\Http;

// Baja las cotizaciones del dólar (dolarapi.com) y las guarda como generales. Si falla, queda la última conocida.
class CotizacionService
{
    public function actualizar(): array
    {
        $res = [];
        try {
            $r = Http::timeout(8)->get('https://dolarapi.com/v1/dolares');
            if (! $r->ok()) return ['error' => 'HTTP ' . $r->status()];
            foreach ($r->json() as $d) {
                $tipo = match ($d['casa'] ?? '') { 'oficial' => 'oficial', 'blue' => 'blue', 'bolsa' => 'mep', 'tarjeta' => 'tarjeta', default => null };
                if (! $tipo) continue;
                $fecha = substr((string) ($d['fechaActualizacion'] ?? now()->toDateString()), 0, 10);
                Cotizacion::updateOrCreate(['business_id' => null, 'fecha' => $fecha, 'tipo' => $tipo], ['compra' => (float) ($d['compra'] ?? 0), 'venta' => (float) ($d['venta'] ?? 0), 'fuente' => 'dolarapi']);
                $res[$tipo] = (float) ($d['venta'] ?? 0);
            }
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        return $res;
    }

    public function fijarManual(int $businessId, float $venta, ?float $compra = null, string $tipo = 'oficial'): Cotizacion
    {
        return Cotizacion::updateOrCreate(['business_id' => $businessId, 'fecha' => today()->toDateString(), 'tipo' => $tipo], ['compra' => $compra ?? $venta, 'venta' => $venta, 'fuente' => 'manual']);
    }
}
