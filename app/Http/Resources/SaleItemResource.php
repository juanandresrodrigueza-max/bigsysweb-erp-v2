<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'product'    => new ProductResource($this->whenLoaded('product')),
            'quantity'   => $this->quantity,
            'unit_price' => $this->unit_price,
            'discount'   => $this->discount,
            'subtotal'   => $this->subtotal,
        ];
    }
}
