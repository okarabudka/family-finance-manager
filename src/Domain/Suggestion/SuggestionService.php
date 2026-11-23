<?php

declare(strict_types=1);

namespace App\Domain\Suggestion;

use App\Domain\Analytics\AnalyticsService;
use App\Entity\Family;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

class SuggestionService
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
        private readonly TransactionRepository $txRepo,
        private readonly CategoryRepository $categoryRepo,
        private readonly TranslatorInterface $translator,
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
                    title: $this->translator->trans('suggestion.expense_spike.title', [
                        '%category%' => $comp->category->getName(),
                    ]),
                    message: $this->translator->trans('suggestion.expense_spike.message', [
                        '%current%'  => sprintf('%.0f', $comp->currentAmount),
                        '%previous%' => sprintf('%.0f', $comp->previousAmount),
                        '%percent%'  => sprintf('%.0f', $comp->diffPercent),
                        '%currency%' => $currency,
                        '%category%' => $comp->category->getName(),
                    ]),
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
                    title: $this->translator->trans('suggestion.budget_exceeded.title', [
                        '%category%' => $catReport->category->getName(),
                    ]),
                    message: $this->translator->trans('suggestion.budget_exceeded.message', [
                        '%category%' => $catReport->category->getName(),
                        '%percent%'  => sprintf('%.0f', $catReport->budgetUsedPercent - 100),
                        '%spent%'    => sprintf('%.0f', $catReport->amount),
                        '%planned%'  => sprintf('%.0f', $catReport->budgetAmount),
                        '%currency%' => $currency,
                    ]),
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
                title: $this->translator->trans('suggestion.negative_balance.title'),
                message: $this->translator->trans('suggestion.negative_balance.message', [
                    '%days%'     => $daysLeft,
                    '%amount%'   => sprintf('%.0f', abs($comparison->current->balance)),
                    '%currency%' => $currency,
                ]),
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
                    title: $this->translator->trans('suggestion.missing_expense.title', [
                        '%category%' => $category->getName(),
                    ]),
                    message: $this->translator->trans('suggestion.missing_expense.message', [
                        '%category%' => $category->getName(),
                    ]),
                    category: $category,
                );
            }
        }

        return $suggestions;
    }
}
