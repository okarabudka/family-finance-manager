<?php

declare(strict_types=1);

namespace App\Entity;

enum TransactionType: string
{
    case Expense    = 'expense';
    case Income     = 'income';
    case Transfer   = 'transfer';
    case Refund     = 'refund';
    case Adjustment = 'adjustment';
}
