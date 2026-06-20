<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'description'    => $this->description,
            'price_monthly'  => $this->price_monthly,
            'price_yearly'   => $this->price_yearly,
            'max_users'      => $this->max_users,
            'max_locations'  => $this->max_locations,
            'max_products'   => $this->max_products,
            'features'       => $this->features,
            'is_active'      => $this->is_active,
            'is_free'        => $this->is_free,
        ];
    }
}
