<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'name'                       => $this->name,
            'slug'                       => $this->slug,
            'email'                      => $this->email,
            'phone'                      => $this->phone,
            'logo'                       => $this->logo,
            'currency'                   => $this->currency,
            'country'                    => $this->country,
            'timezone'                   => $this->timezone,
            'locale'                     => $this->locale,
            'date_format'                => $this->date_format,
            'cuit'                       => $this->cuit,
            'razon_social'               => $this->razon_social,
            'condicion_iva'              => $this->condicion_iva,
            'afip_punto_venta'           => $this->afip_punto_venta,
            'afip_produccion'            => $this->afip_produccion,
            'is_active'                  => $this->is_active,
            'financial_year_start_month' => $this->financial_year_start_month,
            'locations'                  => BusinessLocationResource::collection($this->whenLoaded('locations')),
            'subscription'               => new SubscriptionResource($this->whenLoaded('activeSubscription')),
            'created_at'                 => $this->created_at,
        ];
    }
}
