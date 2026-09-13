<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    use HasUuids;

    protected $fillable = [
        'ticket_number', 'serial', 'batch_id', 'user_id', 'campaign_id', 'prize_id',
        'order_id', 'payment_id', 'status', 'qr_token', 'is_locked', 'issued_at',
        'used_at', 'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'status' => TicketStatus::class,
        'is_locked' => 'boolean',
        'serial' => 'integer',
        'issued_at' => 'datetime',
        'used_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $hidden = ['qr_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(Prize::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TicketBatch::class, 'batch_id');
    }

    public function winner(): HasOne
    {
        return $this->hasOne(Winner::class);
    }

    public function scopeEligibleForDraw(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TicketStatus::Valid->value,
            TicketStatus::Winner->value,
        ]);
    }

    public function scopeOwnedBy(Builder $query, User|string $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }


    /**
     * Contenu du QR code affiché au participant. Le jeton est opaque et signé :
     * il ne permet pas de deviner les autres tickets, et le contrôle au guichet
     * se fait côté serveur (aucune confiance dans le client).
     *
     * @return array<string, string>
     */
    public function qrPayload(): array
    {
        $payload = $this->ticket_number.'|'.$this->qr_token;

        return [
            'ticket_number' => $this->ticket_number,
            'token' => $this->qr_token,
            'signature' => hash_hmac('sha256', $payload, (string) config('app.key')),
            'verify_url' => rtrim((string) config('tombola.frontend_url'), '/').'/verifier/'.$this->ticket_number,
        ];
    }

    /** Vérifie l'authenticité d'un QR code scanné. */
    public static function verifyQrSignature(string $ticketNumber, string $token, string $signature): bool
    {
        return hash_equals(
            hash_hmac('sha256', $ticketNumber.'|'.$token, (string) config('app.key')),
            $signature
        );
    }

    /** Payload stable servant d'empreinte dans le snapshot de tirage. */
    public function hashPayload(): string
    {
        return implode('|', [$this->ticket_number, $this->user_id, $this->serial]);
    }
}
