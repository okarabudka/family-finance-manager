<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum MeterType: string implements TranslatableInterface
{
    case Electricity = 'electricity';
    case Water = 'water';

    public function label(): string
    {
        return match ($this) {
            self::Electricity => 'Electricity',
            self::Water => 'Water',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Electricity => 'kWh',
            self::Water => 'm³',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Electricity => '⚡',
            self::Water => '💧',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.meter_type.'.$this->value, [], 'messages', $locale);
    }
}
