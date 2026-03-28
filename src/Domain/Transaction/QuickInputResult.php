<?php

declare(strict_types=1);

namespace App\Domain\Transaction;

/**
 * Parsed result of a quick-input expense string like "50 продукты" or "150.50 ресторан".
 */
final class QuickInputResult
{
    public function __construct(
        /** Decimal string, e.g. "50.00" */
        public readonly string $amount,
        /** Human-readable description / merchant hint */
        public readonly string $description,
    ) {}
}
