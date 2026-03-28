<?php

declare(strict_types=1);

namespace App\Entity;

enum ImportTransactionStatus: string
{
    case Pending      = 'pending';
    case AutoImported = 'auto_imported';
    case Confirmed    = 'confirmed';
    case Skipped      = 'skipped';
}
