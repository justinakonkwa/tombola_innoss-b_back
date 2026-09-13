<?php

namespace App\Enums;

enum MobileMoneyOperator: string
{
    case Airtel = 'airtel';
    case Mpesa = 'mpesa';
    case Orange = 'orange';
    case Africell = 'africell';

    public function label(): string
    {
        return match ($this) {
            self::Airtel => 'Airtel Money',
            self::Mpesa => 'M-Pesa',
            self::Orange => 'Orange Money',
            self::Africell => 'Africell Money',
        };
    }

    /** Préfixes RDC utilisés pour déduire l'opérateur depuis le numéro du payeur. */
    public static function fromPhone(string $phone): ?self
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $national = str_starts_with($digits, '243') ? substr($digits, 3) : $digits;

        return match (true) {
            str_starts_with($national, '99'), str_starts_with($national, '97') => self::Airtel,
            str_starts_with($national, '82'), str_starts_with($national, '83') => self::Mpesa,
            str_starts_with($national, '89'), str_starts_with($national, '84'), str_starts_with($national, '80') => self::Orange,
            str_starts_with($national, '90'), str_starts_with($national, '91') => self::Africell,
            default => null,
        };
    }
}
