<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

readonly class MonthlyReport
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public float $totalIncome,
        public float $totalExpenses,
        public float $balance,
        /** @var CategoryReport[] */
        public array $byCategory,
    ) {
    }
}
