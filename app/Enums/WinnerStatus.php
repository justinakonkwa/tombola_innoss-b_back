<?php

namespace App\Enums;

enum WinnerStatus: string
{
    case Pending = 'pending';
    case Contacted = 'contacted';
    case Verified = 'verified';
    case Validated = 'validated';
    case Delivered = 'delivered';
    case Rejected = 'rejected';
    case Forfeited = 'forfeited';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'À contacter',
            self::Contacted => 'Contacté',
            self::Verified => 'Identité vérifiée',
            self::Validated => 'Validé',
            self::Delivered => 'Lot remis',
            self::Rejected => 'Rejeté',
            self::Forfeited => 'Déchu',
        };
    }
}
