<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
            self::Blocked => 'Bloqué',
        };
    }

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
