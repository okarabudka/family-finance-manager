<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Entity\Category;

/**
 * One row in the "by category" section of a monthly report.
 */
final class CategoryReportItem
{
    public function __construct(
        public readonly Category $category,
        /** Decimal string representing the total amount */
        public readonly string $amount,
        /** Percentage of total expenses, 0-100 */
        public readonly float $percent,
    ) {}
}
