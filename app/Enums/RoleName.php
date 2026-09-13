<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Finance = 'finance';
    case Support = 'support';
    case Auditor = 'auditor';
    case Participant = 'participant';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super administrateur',
            self::Admin => 'Administrateur',
            self::Finance => 'Finance',
            self::Support => 'Support',
            self::Auditor => 'Auditeur',
            self::Participant => 'Participant',
        };
    }

    /** Les rôles internes accèdent au back-office et exigent le MFA. */
    public function isStaff(): bool
    {
        return $this !== self::Participant;
    }
}
