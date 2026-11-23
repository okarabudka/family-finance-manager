<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ImportTransactionStatus: string implements TranslatableInterface
{
    case Pending      = 'pending';
    case AutoImported = 'auto_imported';
    case Confirmed    = 'confirmed';
    case Skipped      = 'skipped';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.import_transaction_status.'.$this->value, [], 'messages', $locale);
    }
}
