<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Closed = 'closed';
    case Drawn = 'drawn';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Scheduled => 'Programmée',
            self::Active => 'Active',
            self::Closed => 'Clôturée',
            self::Drawn => 'Tirage effectué',
            self::Archived => 'Archivée',
        };
    }

    /** Une campagne n'accepte des tickets que si elle est active et dans sa fenêtre de vente. */
    public function acceptsSales(): bool
    {
        return $this === self::Active;
    }

    public function isPubliclyVisible(): bool
    {
        return in_array($this, [self::Scheduled, self::Active, self::Closed, self::Drawn], true);
    }
}
