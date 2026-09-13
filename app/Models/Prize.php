<?php

namespace App\Models;

use App\Enums\PrizeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prize extends Model
{
    use HasUuids;

    protected $fillable = [
        'campaign_id', 'name', 'slug', 'description', 'media', 'indicative_value',
        'currency', 'quantity', 'quantity_awarded', 'is_main', 'position',
        'draw_at', 'status',
    ];

    protected $casts = [
        'status' => PrizeStatus::class,
        'media' => 'array',
        'indicative_value' => 'decimal:2',
        'is_main' => 'boolean',
        'quantity' => 'integer',
        'quantity_awarded' => 'integer',
        'draw_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function winners(): HasMany
    {
        return $this->hasMany(Winner::class);
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->quantity - $this->quantity_awarded);
    }
}
