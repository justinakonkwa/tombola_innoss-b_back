<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_reference' => $this->whenLoaded('order', fn () => $this->order?->reference),
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'operator' => $this->operator,
            'payer_phone' => $this->payer_phone ? substr($this->payer_phone, 0, 4).'****'.substr($this->payer_phone, -2) : null,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'commission' => (float) $this->commission,
            'net' => (float) $this->net,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'checkout_url' => $this->checkout_url,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
