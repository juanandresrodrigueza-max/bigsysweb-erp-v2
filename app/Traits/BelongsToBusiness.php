<?php
namespace App\Traits;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToBusiness
{
    protected static function bootBelongsToBusiness(): void
    {
        static::creating(function ($model) {
            if (auth()->check() && auth()->user()->business_id && ! $model->business_id) {
                $model->business_id = auth()->user()->business_id;
            }
        });

        static::addGlobalScope('business', function (Builder $builder) {
            if (auth()->check() && auth()->user()->business_id && ! auth()->user()->is_superadmin) {
                $builder->where($builder->getModel()->getTable() . '.business_id', auth()->user()->business_id);
            }
        });
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
