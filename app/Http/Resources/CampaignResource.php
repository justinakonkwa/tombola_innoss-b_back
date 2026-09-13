<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'hero_media_url' => $this->hero_media_url,
            'hero_poster_url' => $this->hero_poster_url,
            'og_image_url' => $this->og_image_url,
            'ticket_price' => (float) $this->ticket_price,
            'currency' => $this->currency,
            'formatted_ticket_price' => number_format((float) $this->ticket_price, 2, ',', ' ').' '.$this->currency,
            'max_tickets' => (int) $this->max_tickets,
            'tickets_sold' => (int) $this->tickets_sold,
            'tickets_remaining' => $this->ticketsRemaining(),
            'sales_progress' => $this->salesProgress(),
            'min_tickets_per_order' => (int) $this->min_tickets_per_order,
            'max_tickets_per_order' => (int) $this->max_tickets_per_order,
            'max_tickets_per_user' => $this->max_tickets_per_user !== null ? (int) $this->max_tickets_per_user : null,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'draw_at' => $this->draw_at?->toIso8601String(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_featured' => (bool) $this->is_featured,
            'accepts_sales' => $this->acceptsSales(),
            'is_sold_out' => $this->isSoldOut(),
            'terms_url' => $this->terms_url,
            'prizes' => PrizeResource::collection($this->whenLoaded('prizes')),
            'main_prize' => new PrizeResource($this->whenLoaded('mainPrize')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
