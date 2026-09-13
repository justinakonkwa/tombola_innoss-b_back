<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_email' => $this->actor_email,
            'actor_role' => $this->actor_role,
            'action' => $this->action,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip' => $this->ip,
            'hash' => $this->hash,
            'previous_hash' => $this->previous_hash,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
