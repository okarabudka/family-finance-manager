<?php

declare(strict_types=1);

namespace App\Domain\Suggestion;

use App\Entity\Category;

readonly class Suggestion
{
    public function __construct(
        public SuggestionType $type,
        public SuggestionPriority $priority,
        public string $title,
        public string $message,
        public ?Category $category = null,
        public ?float $amount = null,
    ) {
    }
}
