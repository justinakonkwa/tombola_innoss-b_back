<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrawEntry extends Model
{
    protected $fillable = ['draw_id', 'ticket_id', 'position', 'ticket_number', 'ticket_hash'];

    protected $casts = ['position' => 'integer'];

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
