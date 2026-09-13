<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrizeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'media' => $this->media ?? [],
            'indicative_value' => $this->indicative_value !== null ? (float) $this->indicative_value : null,
            'currency' => $this->currency,
            'quantity' => (int) $this->quantity,
            'quantity_awarded' => (int) $this->quantity_awarded,
            'is_main' => (bool) $this->is_main,
            'position' => (int) $this->position,
            'draw_at' => $this->draw_at?->toIso8601String(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
        ];
    }
}
