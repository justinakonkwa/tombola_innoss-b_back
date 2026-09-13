<?php

namespace App\Enums;

enum PaymentChannel: string
{
    case MobileMoney = 'mobile_money';
    case Card = 'card';
    case Rdv = 'rdv';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::MobileMoney => 'Mobile Money',
            self::Card => 'Carte bancaire',
            self::Rdv => 'Réseau local RDV',
            self::Unknown => 'Non déterminé',
        };
    }
}
