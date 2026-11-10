<?php

namespace App\Services\Fondos;

use App\Models\Cotizacion;
use Illuminate\Support\Facades\Http;

// Baja las cotizaciones del dólar (dolarapi.com) y las guarda como generales. Si falla, queda la última conocida.
class CotizacionService
{
    public const ULTIMO_INTENTO = 'cotizaciones_ultimo_intento'; // para que la salud diga qué pasó la última vez, no sólo "está vieja"

    public function actualizar(): array
    {
        $errores = [];
        foreach (['dolarapi' => fn() => $this->desdeDolarapi(), 'bluelytics' => fn() => $this->desdeBluelytics()] as $fuente => $f) {
            try { $res = $f(); if ($res) { $this->anotar(true, "{$fuente}: " . implode(', ', array_map(fn($k, $v) => "{$k} {$v}", array_keys($res), $res))); return $res; } $errores[] = "{$fuente}: sin datos"; }
            catch (\Throwable $e) { $errores[] = "{$fuente}: " . mb_substr($e->getMessage(), 0, 120); }
        }
        $this->anotar(false, implode(' · ', $errores));
        return ['error' => implode(' · ', $errores)];
    }

    private function desdeDolarapi(): array
    {
        $r = Http::timeout(8)->get('https://dolarapi.com/v1/dolares');
        if (! $r->ok()) throw new \RuntimeException('HTTP ' . $r->status());
        $res = [];
        foreach ((array) $r->json() as $d) {
            $tipo = match ($d['casa'] ?? '') { 'oficial' => 'oficial', 'blue' => 'blue', 'bolsa' => 'mep', 'tarjeta' => 'tarjeta', default => null };
            if (! $tipo || (float) ($d['venta'] ?? 0) <= 0) continue;
            $res[$tipo] = $this->guardar($tipo, (float) ($d['compra'] ?? 0), (float) $d['venta'], 'dolarapi', substr((string) ($d['fechaActualizacion'] ?? ''), 0, 10));
        }
        return $res;
    }

    // Segunda fuente (oficial y blue) por si dolarapi está caída o bloqueada desde el servidor.
    private function desdeBluelytics(): array
    {
        $r = Http::timeout(8)->get('https://api.bluelytics.com.ar/v2/latest');
        if (! $r->ok()) throw new \RuntimeException('HTTP ' . $r->status());
        $j = (array) $r->json(); $res = []; $fecha = substr((string) ($j['last_update'] ?? ''), 0, 10);
        foreach (['oficial' => 'oficial', 'blue' => 'blue'] as $k => $tipo) {
            if ((float) ($j[$k]['value_sell'] ?? 0) > 0) $res[$tipo] = $this->guardar($tipo, (float) ($j[$k]['value_buy'] ?? 0), (float) $j[$k]['value_sell'], 'bluelytics', $fecha);
        }
        return $res;
    }

    // La fecha de la cotización nunca es futura ni inválida; sin fecha de la fuente, es hoy.
    private function guardar(string $tipo, float $compra, float $venta, string $fuente, string $fecha): float
    {
        $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) && $fecha <= today()->toDateString() ? $fecha : today()->toDateString();
        Cotizacion::updateOrCreate(['business_id' => null, 'fecha' => $f, 'tipo' => $tipo], ['compra' => $compra ?: $venta, 'venta' => $venta, 'fuente' => $fuente]);
        return $venta;
    }

    private function anotar(bool $ok, string $detalle): void
    {
        \Illuminate\Support\Facades\Cache::put(self::ULTIMO_INTENTO, ['cuando' => now()->toIso8601String(), 'ok' => $ok, 'detalle' => mb_substr($detalle, 0, 300)], now()->addDays(7));
    }

    public function fijarManual(int $businessId, float $venta, ?float $compra = null, string $tipo = 'oficial'): Cotizacion
    {
        return Cotizacion::updateOrCreate(['business_id' => $businessId, 'fecha' => today()->toDateString(), 'tipo' => $tipo], ['compra' => $compra ?? $venta, 'venta' => $venta, 'fuente' => 'manual']);
    }
}
