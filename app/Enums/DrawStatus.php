<?php

namespace App\Enums;

enum DrawStatus: string
{
    case Pending = 'pending';
    case Closed = 'closed';
    case Snapshot = 'snapshot';
    case Executed = 'executed';
    case Published = 'published';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En préparation',
            self::Closed => 'Ventes fermées',
            self::Snapshot => 'Pool figé',
            self::Executed => 'Exécuté',
            self::Published => 'Publié',
            self::Cancelled => 'Annulé',
        };
    }

    /** Un tirage exécuté ne peut plus être rejoué (garanti aussi par trigger PostgreSQL). */
    public function isFrozen(): bool
    {
        return in_array($this, [self::Executed, self::Published], true);
    }
}
