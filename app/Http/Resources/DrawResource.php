<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrawResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isStaff = (bool) $request->user()?->isStaff();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'campaign' => new CampaignResource($this->whenLoaded('campaign')),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'pool_size' => (int) $this->pool_size,
            'ticket_pool_hash' => $this->ticket_pool_hash,
            'algorithm' => $this->algorithm,
            'server_seed_hash' => $this->server_seed_hash,
            // Le serveur ne révèle sa graine qu'après exécution du tirage (commit-reveal).
            'server_seed' => $this->when($isStaff || $this->isFrozen(), fn () => $this->server_seed),
            'client_seed' => $this->client_seed,
            'random_value' => $this->random_value,
            'winning_position' => $this->winning_position !== null ? (int) $this->winning_position : null,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'executor' => new UserResource($this->whenLoaded('executor')),
            'winners' => WinnerResource::collection($this->whenLoaded('winners')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
