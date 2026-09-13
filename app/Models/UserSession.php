<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'device_id', 'refresh_token_hash', 'ip', 'user_agent',
        'last_used_at', 'expires_at', 'revoked_at', 'revoked_reason',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function revoke(string $reason = 'manual'): void
    {
        $this->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();
    }
}
