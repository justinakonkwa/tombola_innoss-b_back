<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot de tickets issu d'un paiement confirmé.
 * `payment_id` est unique en base : c'est le verrou d'idempotence de l'attribution.
 */
class TicketBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id', 'payment_id', 'campaign_id', 'user_id', 'quantity',
        'first_serial', 'last_serial', 'checksum', 'numbers', 'generated_at',
    ];

    protected $casts = [
        'numbers' => 'array',
        'quantity' => 'integer',
        'first_serial' => 'integer',
        'last_serial' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'batch_id');
    }
}
