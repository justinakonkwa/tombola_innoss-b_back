<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'reference', 'user_id', 'campaign_id', 'quantity', 'unit_price', 'total_amount',
        'currency', 'status', 'idempotency_key', 'ip', 'user_agent', 'risk_score',
        'expires_at', 'paid_at',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'quantity' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function ticketBatch(): HasOne
    {
        return $this->hasOne(TicketBatch::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isPaid(): bool
    {
        return $this->status === OrderStatus::Paid;
    }

    public function isPayable(): bool
    {
        return in_array($this->status, [OrderStatus::Pending, OrderStatus::Processing], true)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
