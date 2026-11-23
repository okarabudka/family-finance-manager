<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

readonly class PeriodComparison
{
    public function __construct(
        public MonthlyReport $current,
        public MonthlyReport $previous,
        /** @var CategoryComparison[] */
        public array $categoryComparisons,
    ) {
    }
}
