<?php

declare(strict_types=1);

namespace App\Domain\Category;

use App\Entity\Category;
use App\Entity\Family;
use App\Entity\Transaction;
use App\Repository\CategoryRuleRepository;

class CategoryRuleService
{
    public function __construct(
        private readonly CategoryRuleRepository $ruleRepo,
    ) {}

    /**
     * Determines a transaction category based on counterparty IBAN and description keywords.
     */
    public function guessCategory(
        Family $family,
        string $description,
        ?string $counterpartyIban = null,
    ): ?Category {
        // Load rules ordered by priority (DESC) — higher priority first
        $rules = $this->ruleRepo->findByFamilyOrderedByPriority($family);

        // First check IBAN-based rules (more specific)
        foreach ($rules as $rule) {
            if ($counterpartyIban && $rule->getCounterpartyIban() === $counterpartyIban) {
                return $rule->getCategory();
            }
        }

        // Then search by keywords in the description
        $descLower = mb_strtolower($description);
        foreach ($rules as $rule) {
            foreach ($rule->getKeywords() as $keyword) {
                if (str_contains($descLower, mb_strtolower($keyword))) {
                    return $rule->getCategory();
                }
            }
        }

        return null;
    }

    /**
     * When a user manually assigns a category to an imported transaction,
     * this can be used to learn and propose a new rule for future automation.
     */
    public function learnFromTransaction(Transaction $transaction): void
    {
        // Only act on transactions that originated from an import
        if ($transaction->getImportOrigin() === null) {
            return;
        }

        // Logic for proposing a new rule is queued for user confirmation
    }
}
