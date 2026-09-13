<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'short_description', 'hero_media_url', 'hero_poster_url',
        'og_image_url', 'ticket_price', 'currency', 'max_tickets', 'tickets_sold', 'tickets_reserved',
        'min_tickets_per_order', 'max_tickets_per_order', 'max_tickets_per_user',
        'starts_at', 'ends_at', 'draw_at', 'status', 'is_featured', 'terms_url', 'settings', 'created_by',
    ];

    protected $casts = [
        'status' => CampaignStatus::class,
        'ticket_price' => 'decimal:2',
        'settings' => 'array',
        'is_featured' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'draw_at' => 'datetime',
        'max_tickets' => 'integer',
        'tickets_sold' => 'integer',
        'tickets_reserved' => 'integer',
    ];

    public function prizes(): HasMany
    {
        return $this->hasMany(Prize::class)->orderBy('position');
    }

    public function mainPrize(): HasOne
    {
        return $this->hasOne(Prize::class)->where('is_main', true);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function draws(): HasMany
    {
        return $this->hasMany(Draw::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CampaignStatus::Scheduled->value,
            CampaignStatus::Active->value,
            CampaignStatus::Closed->value,
            CampaignStatus::Drawn->value,
        ]);
    }

    public function scopeOpenForSales(Builder $query): Builder
    {
        return $query->where('status', CampaignStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function ticketsRemaining(): int
    {
        return max(0, $this->max_tickets - $this->tickets_sold - $this->tickets_reserved);
    }

    public function acceptsSales(): bool
    {
        if (! $this->status->acceptsSales()) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        return $this->ticketsRemaining() > 0;
    }

    public function isSoldOut(): bool
    {
        return $this->ticketsRemaining() <= 0;
    }

    /** Progression des ventes en pourcentage (0–100). */
    public function salesProgress(): float
    {
        if ($this->max_tickets <= 0) {
            return 0.0;
        }

        return round(min(100, ($this->tickets_sold / $this->max_tickets) * 100), 2);
    }
}
