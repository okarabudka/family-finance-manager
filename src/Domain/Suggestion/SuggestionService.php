<?php

declare(strict_types=1);

namespace App\Domain\Suggestion;

use App\Domain\Analytics\AnalyticsService;
use App\Entity\Family;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;

class SuggestionService
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
        private readonly TransactionRepository $txRepo,
        private readonly CategoryRepository $categoryRepo,
    ) {}

    /**
     * Returns a list of suggestions/recommendations for the family.
     *
     * @return Suggestion[]
     */
    public function getSuggestions(Family $family): array
    {
        $now = new \DateTimeImmutable();
        $currentFrom = new \DateTimeImmutable($now->format('Y-m-01'));
        $currentTo = new \DateTimeImmutable($now->format('Y-m-t'));
        $prevFrom = $currentFrom->modify('-1 month');
        $prevTo = new \DateTimeImmutable($prevFrom->format('Y-m-t'));

        $comparison = $this->analyticsService->comparePeriods(
            $family,
            $currentFrom,
            $currentTo,
            $prevFrom,
            $prevTo,
        );

        $currency    = $family->getCurrency();
        $suggestions = [];

        // 1. Categories with abnormal growth (> 30%)
        foreach ($comparison->categoryComparisons as $comp) {
            if ($comp->diffPercent !== null && $comp->diffPercent > 30 && $comp->diff > 20) {
                $suggestions[] = new Suggestion(
                    type: SuggestionType::ExpenseSpike,
                    priority: SuggestionPriority::High,
                    title: "Expense increase: {$comp->category->getName()}",
                    message: sprintf(
                        'This month you spent %.0f %s on %s, which is %.0f%% more than last month (%.0f %s).',
                        $comp->currentAmount,
                        $currency,
                        $comp->category->getName(),
                        $comp->diffPercent,
                        $comp->previousAmount,
                        $currency,
                    ),
                    category: $comp->category,
                    amount: $comp->diff,
                );
            }
        }

        // 2. Budget exceeded
        foreach ($comparison->current->byCategory as $catReport) {
            if ($catReport->budgetUsedPercent !== null && $catReport->budgetUsedPercent > 100) {
                $suggestions[] = new Suggestion(
                    type: SuggestionType::BudgetExceeded,
                    priority: SuggestionPriority::High,
                    title: "Budget exceeded: {$catReport->category->getName()}",
                    message: sprintf(
                        'Budget for %s exceeded by %.0f%%. Spent %.0f %s of %.0f %s planned.',
                        $catReport->category->getName(),
                        $catReport->budgetUsedPercent - 100,
                        $catReport->amount,
                        $currency,
                        $catReport->budgetAmount,
                        $currency,
                    ),
                    category: $catReport->category,
                    amount: $catReport->amount - ($catReport->budgetAmount ?? 0),
                );
            }
        }

        // 3. Month is ending, balance is negative
        $daysLeft = (int) $now->diff($currentTo)->days;
        if ($daysLeft <= 7 && $comparison->current->balance < 0) {
            $suggestions[] = new Suggestion(
                type: SuggestionType::NegativeBalance,
                priority: SuggestionPriority::Critical,
                title: 'Expenses exceed income',
                message: sprintf(
                    '%d days left this month. Expenses exceed income by %.0f %s.',
                    $daysLeft,
                    abs($comparison->current->balance),
                    $currency,
                ),
            );
        }

        // 4. Categories without transactions (possibly forgotten)
        $suggestions = array_merge($suggestions, $this->detectMissingCategories($family));

        // Sort by priority descending
        usort($suggestions, fn ($a, $b) => $b->priority->value <=> $a->priority->value);

        return $suggestions;
    }

    /** @return Suggestion[] */
    private function detectMissingCategories(Family $family): array
    {
        $suggestions = [];

        $regularCategories = $this->txRepo->findRegularCategories($family, months: 3);

        foreach ($regularCategories as $categoryId) {
            $hasThisMonth = $this->txRepo->hasCategoryTransactionThisMonth($family, $categoryId);
            if (!$hasThisMonth) {
                $category = $this->categoryRepo->find($categoryId);
                if ($category === null) {
                    continue;
                }
                $suggestions[] = new Suggestion(
                    type: SuggestionType::MissingRegularExpense,
                    priority: SuggestionPriority::Medium,
                    title: "No records: {$category->getName()}",
                    message: "In previous months you had regular expenses for \"{$category->getName()}\", but none this month yet. Everything OK?",
                    category: $category,
                );
            }
        }

        return $suggestions;
    }
}
