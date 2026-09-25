<?php

namespace App\Services\Suscripciones;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

// Límites del plan: facturas por mes y artículos cargados. Sin suscripción activa o con -1, no hay límite.
class LimitesPlanService
{
    public static function tiposFactura(): array
    {
        return array_keys(array_filter(Comprobante::TIPOS, fn($d) => ($d['grupo'] ?? null) === 'factura'));
    }

    public function facturasDelMes(Business $b): int
    {
        return Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')
            ->whereIn('tipo', self::tiposFactura())->whereBetween('fecha', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()])->count();
    }

    public function maxFacturas(Business $b): int { return (int) ($b->activeSubscription?->plan?->max_facturas_mes ?? -1); }
    public function maxArticulos(Business $b): int { return (int) ($b->activeSubscription?->plan?->max_products ?? -1); }

    // Uso para mostrar: [usados, máximo] con -1 = sin límite.
    public function uso(Business $b): array
    {
        return ['facturas' => [$this->facturasDelMes($b), $this->maxFacturas($b)], 'articulos' => [Product::withoutGlobalScopes()->where('business_id', $b->id)->count(), $this->maxArticulos($b)]];
    }

    public function verificarFactura(Business $b, Comprobante $c): void
    {
        $max = $this->maxFacturas($b);
        if ($max < 0 || ! in_array($c->tipo, self::tiposFactura(), true)) return;
        if ($this->facturasDelMes($b) >= $max) throw ValidationException::withMessages(['tipo' => "Llegaste a las {$max} facturas del mes que incluye tu plan {$b->activeSubscription->plan->name}. Pasá a un plan mayor en Configuración → Suscripción para seguir facturando."]);
    }

    public function verificarArticulo(Business $b): void
    {
        $max = $this->maxArticulos($b);
        if ($max >= 0 && Product::withoutGlobalScopes()->where('business_id', $b->id)->count() >= $max) throw ValidationException::withMessages(['name' => "Tu plan {$b->activeSubscription->plan->name} incluye hasta {$max} artículos. Pasá a un plan mayor en Configuración → Suscripción para cargar más."]);
    }
}
