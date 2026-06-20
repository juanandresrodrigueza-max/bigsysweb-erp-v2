<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'short_name'  => $this->short_name,
            'address'     => $this->address,
            'city'        => $this->city,
            'province'    => $this->province,
            'postal_code' => $this->postal_code,
            'phone'       => $this->phone,
            'email'       => $this->email,
            'is_active'   => $this->is_active,
            'is_default'  => $this->is_default,
        ];
    }
}
