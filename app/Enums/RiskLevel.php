<?php

namespace App\Enums;

enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 80 => self::Critical,
            $score >= 60 => self::High,
            $score >= 35 => self::Medium,
            default => self::Low,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Risque faible',
            self::Medium => 'Risque modéré',
            self::High => 'Risque élevé',
            self::Critical => 'Risque critique',
        };
    }
}
