<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'type'         => $this->type,
            'quantity'     => $this->quantity,
            'stock_before' => $this->stock_before,
            'stock_after'  => $this->stock_after,
            'reason'       => $this->reason,
            'product'      => new ProductResource($this->whenLoaded('product')),
            'created_at'   => $this->created_at,
        ];
    }
}
