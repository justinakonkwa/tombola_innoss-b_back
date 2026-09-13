<?php

namespace App\Models;

use App\Enums\DrawStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Draw extends Model
{
    use HasUuids;

    protected $fillable = [
        'reference', 'campaign_id', 'status', 'pool_size', 'ticket_pool_hash', 'algorithm',
        'server_seed_hash', 'server_seed', 'client_seed', 'random_value', 'winning_position',
        'executed_by', 'closed_at', 'executed_at', 'published_at', 'evidence',
    ];

    protected $casts = [
        'status' => DrawStatus::class,
        'evidence' => 'array',
        'pool_size' => 'integer',
        'winning_position' => 'integer',
        'closed_at' => 'datetime',
        'executed_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    protected $hidden = ['server_seed'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DrawEntry::class);
    }

    public function winners(): HasMany
    {
        return $this->hasMany(Winner::class);
    }

    public function isFrozen(): bool
    {
        return $this->status->isFrozen();
    }
}
