<?php

declare(strict_types=1);

namespace App\Entity;

enum AccountType: string
{
    case Checking   = 'checking';
    case Savings    = 'savings';
    case Cash       = 'cash';
    case CreditCard = 'credit_card';
}
