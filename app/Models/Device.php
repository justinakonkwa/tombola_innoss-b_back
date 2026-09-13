<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    protected $fillable = ['user_id', 'fingerprint', 'label', 'user_agent', 'last_ip', 'is_trusted', 'last_seen_at'];

    protected $casts = ['is_trusted' => 'boolean', 'last_seen_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
