<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Used = 'used';
    case Winner = 'winner';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Valid => 'Valide',
            self::Used => 'Utilisé',
            self::Winner => 'Gagnant',
            self::Cancelled => 'Annulé',
            self::Refunded => 'Remboursé',
            self::Suspended => 'Suspendu',
        };
    }

    /** Éligible au tirage : un ticket valide ou déjà gagnant d'un autre lot. */
    public function isEligibleForDraw(): bool
    {
        return in_array($this, [self::Valid, self::Winner], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Valid, self::Winner], true);
    }
}
