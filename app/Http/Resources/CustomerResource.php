<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'email'    => $this->email,
            'phone'    => $this->phone,
            'document' => $this->document,
            'address'  => $this->address,
            'active'   => $this->active,
            'sales'    => SaleResource::collection($this->whenLoaded('sales')),
            'created_at' => $this->created_at,
        ];
    }
}
