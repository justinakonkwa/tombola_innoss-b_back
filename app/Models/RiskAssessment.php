<?php

namespace App\Models;

use App\Enums\RiskAction;
use App\Enums\RiskLevel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'order_id', 'score', 'level', 'action', 'signals',
        'is_reviewed', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'level' => RiskLevel::class,
        'action' => RiskAction::class,
        'signals' => 'array',
        'score' => 'integer',
        'is_reviewed' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
