<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'status'         => $this->status,
            'billing_cycle'  => $this->billing_cycle,
            'amount'         => $this->amount,
            'starts_at'      => $this->starts_at,
            'ends_at'        => $this->ends_at,
            'trial_ends_at'  => $this->trial_ends_at,
            'is_active'      => $this->isActive(),
            'is_on_trial'    => $this->isOnTrial(),
            'plan'           => new PlanResource($this->whenLoaded('plan')),
        ];
    }
}
