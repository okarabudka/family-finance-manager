<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Entity\Category;

readonly class CategoryComparison
{
    public function __construct(
        public Category $category,
        public float $currentAmount,
        public float $previousAmount,
        public float $diff,
        public ?float $diffPercent,
    ) {}
}
