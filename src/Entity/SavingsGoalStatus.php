<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum SavingsGoalStatus: string implements TranslatableInterface
{
    case Active = 'active';
    case Completed = 'completed';
    case Paused = 'archived';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.savings_goal_status.'.$this->value, [], 'messages', $locale);
    }
}
