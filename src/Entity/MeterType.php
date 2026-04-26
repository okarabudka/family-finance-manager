<?php

declare(strict_types=1);

namespace App\Entity;

enum MeterType: string
{
    case Electricity = 'electricity';
    case Water       = 'water';

    public function label(): string
    {
        return match ($this) {
            self::Electricity => 'Electricity',
            self::Water       => 'Water',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Electricity => 'kWh',
            self::Water       => 'm³',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Electricity => '⚡',
            self::Water       => '💧',
        };
    }
}
