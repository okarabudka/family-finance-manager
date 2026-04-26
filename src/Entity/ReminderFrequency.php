<?php

declare(strict_types=1);

namespace App\Entity;

enum ReminderFrequency: string
{
    case Daily   = 'daily';
    case Weekly  = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily   => 'Daily',
            self::Weekly  => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }
}
