<?php

namespace App\Models;

use App\Enums\WebhookStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhook extends Model
{
    protected $fillable = [
        'provider', 'event', 'provider_reference', 'signature', 'timestamp_header',
        'payload_hash', 'payload', 'status', 'error', 'ip', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => WebhookStatus::class,
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'provider_reference', 'provider_reference');
    }
}
