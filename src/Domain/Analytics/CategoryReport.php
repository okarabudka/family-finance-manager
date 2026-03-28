<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Entity\Category;

readonly class CategoryReport
{
    public function __construct(
        public Category $category,
        public float $amount,
        public float $percent,
        public ?float $budgetAmount,
        public ?float $budgetUsedPercent,
    ) {}
}
