<?php

declare(strict_types=1);

namespace App\Domain\Suggestion;

enum SuggestionType: string
{
    case ExpenseSpike = 'expense_spike';
    case BudgetExceeded = 'budget_exceeded';
    case NegativeBalance = 'negative_balance';
    case MissingRegularExpense = 'missing_regular_expense';
}
