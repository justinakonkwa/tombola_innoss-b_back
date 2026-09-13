<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Créé',
            self::Pending => 'En attente',
            self::Success => 'Payé',
            self::Failed => 'Échoué',
            self::Cancelled => 'Annulé',
            self::Refunded => 'Remboursé',
        };
    }

    /** Seul un paiement au statut success autorise la génération de tickets. */
    public function isSettled(): bool
    {
        return $this === self::Success;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Cancelled, self::Refunded], true);
    }
}
