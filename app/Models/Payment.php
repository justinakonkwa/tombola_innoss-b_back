<?php

namespace App\Models;

use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id', 'provider', 'provider_payment_id', 'provider_reference', 'channel', 'operator',
        'payer_phone', 'amount', 'currency', 'commission', 'net', 'status', 'checkout_url',
        'provider_payload', 'confirmed_at',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'channel' => PaymentChannel::class,
        'amount' => 'decimal:2',
        'commission' => 'decimal:2',
        'net' => 'decimal:2',
        'provider_payload' => 'array',
        'confirmed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function ticketBatch(): HasOne
    {
        return $this->hasOne(TicketBatch::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    public function hasTicketsIssued(): bool
    {
        return $this->ticketBatch()->exists();
    }
}
