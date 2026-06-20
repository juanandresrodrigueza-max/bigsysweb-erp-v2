<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'status'       => $this->status,
            'subtotal'     => $this->subtotal,
            'discount'     => $this->discount,
            'tax'          => $this->tax,
            'total'        => $this->total,
            'notes'        => $this->notes,
            'confirmed_at' => $this->confirmed_at,
            'customer'     => new CustomerResource($this->whenLoaded('customer')),
            'items'        => SaleItemResource::collection($this->whenLoaded('items')),
            'created_at'   => $this->created_at,
        ];
    }
}
