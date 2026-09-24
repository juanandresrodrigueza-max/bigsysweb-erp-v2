<?php
namespace App\Traits;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

// Aísla cada consulta a la empresa del usuario logueado y completa empresa y sucursal al crear.
trait BelongsToBusiness
{
    protected static function bootBelongsToBusiness(): void
    {
        static::creating(function ($model) {
            $user = Auth::user();
            if (! $user) {
                return;
            }
            if ($user->business_id && empty($model->business_id)) {
                $model->business_id = $user->business_id;
            }
            if ($user->current_location_id
                && in_array('business_location_id', $model->getFillable(), true)
                && empty($model->business_location_id)) {
                $model->business_location_id = $user->current_location_id;
            }
        });

        static::addGlobalScope('business', function (Builder $builder) {
            $user = Auth::user();
            if ($user && $user->business_id && ! $user->is_superadmin) {
                $builder->where($builder->getModel()->getTable() . '.business_id', $user->business_id);
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeDeSucursal(Builder $query, ?int $locationId): Builder
    {
        return $locationId ? $query->where($this->getTable() . '.business_location_id', $locationId) : $query;
    }
}
