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

    /**
     * Paiement le plus récent de la commande.
     *
     * On n'utilise PAS `latestOfMany()` : cette méthode s'appuie sur un agrégat
     * `MAX(id)`, or les clés primaires sont des UUID et PostgreSQL ne fournit
     * pas de fonction `max(uuid)`. La requête échouait donc en
     * « Undefined function: function max(uuid) does not exist », cassant la
     * page « Mes commandes » et le tunnel de paiement.
     *
     * Un `hasOne` ordonné donne le même résultat sans agrégat : lors du
     * chargement anticipé, Laravel conserve la première ligne rencontrée par
     * commande, donc la plus récente. L'ordre est rendu déterministe par l'`id`
     * en second critère (`created_at` n'a qu'une précision à la seconde).
     */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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
