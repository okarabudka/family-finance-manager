<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum CategoryType: string implements TranslatableInterface
{
    case Expense = 'expense';
    case Income = 'income';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.category_type.'.$this->value, [], 'messages', $locale);
    }
}
