<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal d'audit append-only.
 * `hash` chaîne chaque entrée à la précédente (previous_hash) : toute
 * modification/suppression est détectable et de toute façon bloquée par trigger PostgreSQL.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'actor_email', 'actor_role', 'action', 'resource_type', 'resource_id',
        'old_values', 'new_values', 'ip', 'user_agent', 'previous_hash', 'hash',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
