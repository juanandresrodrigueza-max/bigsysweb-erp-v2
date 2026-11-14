<?php

namespace App\Services\Gastronomia;

use App\Models\Business;
use App\Models\User;
use App\Services\Avisos\CelularService;

// Fase 95: lo que pasa en una mesa le llega al celular del mozo (app nativa o web push), no sólo a la campanita del panel:
// "Llaman al mozo en Mesa 5", "Piden la cuenta en Mesa 5", "Pedido desde el QR de Mesa 5". Van a los usuarios que pueden cargar
// comandas en esa sucursal (mozos, encargados, dueño). Nunca frena el pedido si el envío falla.
class AvisosSalonService
{
    public static function mozos(Business $b, ?int $locationId): \Illuminate\Support\Collection
    {
        return User::withoutGlobalScopes()->where('business_id', $b->id)->where('status', 'active')->get()
            ->filter(fn(User $u) => $u->puede('gastronomia', 'crear') && (! $locationId || ! $u->current_location_id || (int) $u->current_location_id === (int) $locationId))
            ->values();
    }

    public static function avisar(Business $b, ?int $locationId, string $titulo, string $texto, string $url): int
    {
        $n = 0;
        try {
            $svc = app(CelularService::class);
            foreach (self::mozos($b, $locationId) as $u) { try { $n += $svc->enviar($u, $titulo, $texto, $url); } catch (\Throwable) {} }
        } catch (\Throwable) {}
        return $n;
    }
}
