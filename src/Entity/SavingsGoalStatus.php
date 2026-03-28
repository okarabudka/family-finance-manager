<?php

declare(strict_types=1);

namespace App\Entity;

enum SavingsGoalStatus: string
{
    case Active    = 'active';
    case Completed = 'completed';
    case Paused    = 'archived';
}
