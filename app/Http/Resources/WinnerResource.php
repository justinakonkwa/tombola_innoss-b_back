<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WinnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isStaff = (bool) $request->user()?->isStaff();

        return [
            'id' => $this->id,
            'rank' => (int) $this->rank,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'prize' => new PrizeResource($this->whenLoaded('prize')),
            'ticket_number' => $this->whenLoaded('ticket', fn () => $this->ticket?->ticket_number),
            // Référence et date du tirage : attendues par la page publique des
            // gagnants pour afficher « lot gagné le … ».
            'draw_reference' => $this->whenLoaded('draw', fn () => $this->draw?->reference),
            'draw_date' => $this->whenLoaded('draw', fn () => $this->draw?->executed_at?->toIso8601String()),
            'campaign' => $this->whenLoaded('draw', function () {
                $campaign = $this->draw?->relationLoaded('campaign') ? $this->draw->campaign : null;

                return $campaign ? [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'slug' => $campaign->slug,
                ] : null;
            }),
            // Minimisation des données personnelles : nom masqué côté public.
            'winner_name' => $isStaff
                ? $this->whenLoaded('user', fn () => $this->user?->fullName())
                : $this->whenLoaded('user', fn () => $this->user?->maskedName()),
            'is_published' => (bool) $this->is_published,
            'notified_at' => $this->notified_at?->toIso8601String(),
            'identity_verified_at' => $this->identity_verified_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
