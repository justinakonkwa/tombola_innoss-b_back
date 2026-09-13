<?php

namespace App\Models;

use App\Enums\WinnerStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Winner extends Model
{
    use HasUuids;

    protected $fillable = [
        'draw_id', 'prize_id', 'ticket_id', 'user_id', 'rank', 'status', 'is_published',
        'notified_at', 'identity_verified_at', 'delivered_at', 'proof', 'notes', 'handled_by',
    ];

    protected $casts = [
        'status' => WinnerStatus::class,
        'proof' => 'array',
        'is_published' => 'boolean',
        'rank' => 'integer',
        'notified_at' => 'datetime',
        'identity_verified_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(Prize::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
