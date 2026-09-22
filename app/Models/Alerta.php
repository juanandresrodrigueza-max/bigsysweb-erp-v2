<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Alerta extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'business_location_id', 'modulo', 'tipo', 'severidad', 'titulo', 'detalle',
        'url', 'modelo', 'modelo_id', 'leida_por', 'resuelta_en',
    ];

    protected $casts = ['leida_por' => 'array', 'resuelta_en' => 'datetime'];

    public function scopeActivas(Builder $q): Builder
    {
        return $q->whereNull('resuelta_en');
    }

    public function scopeNoLeidasPor(Builder $q, int $userId): Builder
    {
        return $q->activas()->where(function ($w) use ($userId) {
            $w->whereNull('leida_por')->orWhereJsonDoesntContain('leida_por', $userId);
        });
    }

    public function scopeVisiblesPara(Builder $q, User $user): Builder
    {
        $modulos = $user->modulosVisibles();
        return $q->whereIn('modulo', $modulos)->where(function ($w) use ($user) {
            $w->whereNull('business_location_id');
            if ($user->current_location_id) {
                $w->orWhere('business_location_id', $user->current_location_id);
            }
        });
    }

    public function marcarLeida(int $userId): void
    {
        $leida = $this->leida_por ?? [];
        if (! in_array($userId, $leida, true)) {
            $leida[] = $userId;
            $this->update(['leida_por' => $leida]);
        }
    }

    public static function emitir(array $datos): self
    {
        return static::updateOrCreate(
            [
                'business_id' => $datos['business_id'],
                'tipo'        => $datos['tipo'],
                'modelo'      => $datos['modelo'] ?? null,
                'modelo_id'   => $datos['modelo_id'] ?? null,
            ],
            array_merge($datos, ['resuelta_en' => null])
        );
    }
}
