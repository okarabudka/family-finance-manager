<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum TransactionType: string implements TranslatableInterface
{
    case Expense    = 'expense';
    case Income     = 'income';
    case Transfer   = 'transfer';
    case Refund     = 'refund';
    case Adjustment = 'adjustment';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.transaction_type.'.$this->value, [], 'messages', $locale);
    }
}
