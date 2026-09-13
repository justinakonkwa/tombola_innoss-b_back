<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Le jeton QR n'est révélé qu'au propriétaire du ticket.
        $isOwner = $request->user()?->id === $this->user_id;

        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'campaign' => new CampaignResource($this->whenLoaded('campaign')),
            'campaign_id' => $this->campaign_id,
            'prize' => new PrizeResource($this->whenLoaded('prize')),
            'order_reference' => $this->whenLoaded('order', fn () => $this->order?->reference),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'used_at' => $this->used_at?->toIso8601String(),
            'qr_payload' => $this->when($isOwner, fn () => $this->qrPayload()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
