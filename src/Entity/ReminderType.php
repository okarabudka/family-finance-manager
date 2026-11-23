<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ReminderType: string implements TranslatableInterface
{
    case MeterElectricity = 'meter_electricity';
    case MeterWater = 'meter_water';
    case BudgetCheck = 'budget_check';
    case SavingsCheck = 'savings_check';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::MeterElectricity => '⚡ Electricity meter reading',
            self::MeterWater => '💧 Water meter reading',
            self::BudgetCheck => '📊 Budget check',
            self::SavingsCheck => '🐷 Savings goal check',
            self::Custom => '📝 Custom',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.reminder_type.'.$this->value, [], 'messages', $locale);
    }
}
