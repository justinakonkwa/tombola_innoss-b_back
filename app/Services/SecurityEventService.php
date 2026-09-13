<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Événements de sécurité (cahier des charges §13, §36).
 * Alimente la supervision et les alertes du back-office.
 */
class SecurityEventService
{
    public function log(
        string $type,
        string $severity,
        ?User $user = null,
        array $metadata = [],
        ?string $description = null,
    ): SecurityEvent {
        if (in_array($severity, ['high', 'critical'], true)) {
            Log::warning('Événement de sécurité', [
                'type' => $type,
                'severity' => $severity,
                'user_id' => $user?->id,
                'metadata' => $metadata,
            ]);
        }

        return SecurityEvent::query()->create([
            'type' => $type,
            'severity' => $severity,
            'user_id' => $user?->id,
            'ip' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
            'description' => $description,
            'metadata' => $metadata ?: null,
        ]);
    }
}
