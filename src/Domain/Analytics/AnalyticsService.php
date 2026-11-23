<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Entity\Family;
use App\Entity\TransactionType;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use Symfony\Contracts\Cache\CacheInterface;

class AnalyticsService
{
    public function __construct(
        private readonly TransactionRepository $txRepo,
        private readonly CategoryRepository $categoryRepo,
        private readonly BudgetRepository $budgetRepo,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Monthly report: income, expenses, breakdown by category.
     */
    public function getMonthlyReport(
        Family $family,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): MonthlyReport {
        $cacheKey = "monthly_report_{$family->getId()}_{$from->format('Y-m')}";

        return $this->cache->get($cacheKey, function () use ($family, $from, $to) {
            return $this->buildMonthlyReport($family, $from, $to);
        }, 3600);
    }

    private function buildMonthlyReport(Family $family, \DateTimeImmutable $from, \DateTimeImmutable $to): MonthlyReport
    {
        $expenses = $this->txRepo->sumByCategory($family, $from, $to);
        $totalExpenses = array_sum(array_column($expenses, 'total'));

        $income = $this->txRepo->sumByType($family, $from, $to, TransactionType::Income);

        $categoriesData = [];
        foreach ($expenses as $row) {
            $category = $this->categoryRepo->find($row['categoryId']);
            // Category may have been deleted — skip orphaned transactions
            if (null === $category) {
                continue;
            }
            $budget = $this->budgetRepo->findForMonth(
                $family,
                $category,
                (int) $from->format('Y'),
                (int) $from->format('n'),
            );

            $categoriesData[] = new CategoryReport(
                category: $category,
                amount: (float) $row['total'],
                percent: $totalExpenses > 0 ? ((float) $row['total'] / $totalExpenses * 100) : 0,
                budgetAmount: $budget ? (float) $budget->getPlannedAmount() : null,
                budgetUsedPercent: ($budget && (float) $budget->getPlannedAmount() > 0)
                    ? ((float) $row['total'] / (float) $budget->getPlannedAmount() * 100)
                    : null,
            );
        }

        // Sort by amount descending
        usort($categoriesData, fn ($a, $b) => $b->amount <=> $a->amount);

        return new MonthlyReport(
            from: $from,
            to: $to,
            totalIncome: $income,
            totalExpenses: $totalExpenses,
            balance: $income - $totalExpenses,
            byCategory: $categoriesData,
        );
    }

    /**
     * Compare expenses by category: current month vs previous.
     */
    public function comparePeriods(
        Family $family,
        \DateTimeImmutable $currentFrom,
        \DateTimeImmutable $currentTo,
        \DateTimeImmutable $previousFrom,
        \DateTimeImmutable $previousTo,
    ): PeriodComparison {
        $current = $this->getMonthlyReport($family, $currentFrom, $currentTo);
        $previous = $this->getMonthlyReport($family, $previousFrom, $previousTo);

        $comparisons = [];
        foreach ($current->byCategory as $currentCat) {
            $prevCat = $this->findCategoryInReport($previous, $currentCat->category);
            $prevAmount = null !== $prevCat ? $prevCat->amount : 0;
            $diff = $currentCat->amount - $prevAmount;
            $diffPercent = $prevAmount > 0 ? ($diff / $prevAmount * 100) : null;

            $comparisons[] = new CategoryComparison(
                category: $currentCat->category,
                currentAmount: $currentCat->amount,
                previousAmount: $prevAmount,
                diff: $diff,
                diffPercent: $diffPercent,
            );
        }

        return new PeriodComparison(
            current: $current,
            previous: $previous,
            categoryComparisons: $comparisons,
        );
    }

    private function findCategoryInReport(MonthlyReport $report, \App\Entity\Category $category): ?CategoryReport
    {
        foreach ($report->byCategory as $catReport) {
            if ($catReport->category->getId()->equals($category->getId())) {
                return $catReport;
            }
        }

        return null;
    }

    /**
     * Data for the expense trend chart (last N months).
     */
    /** @return array<int, array{month: string, expenses: float, income: float, balance: float}> */
    public function getMonthlyTrend(Family $family, int $months = 6): array
    {
        $cacheKey = "monthly_trend_{$family->getId()}_{$months}";

        return $this->cache->get($cacheKey, function () use ($family, $months) {
            return $this->buildMonthlyTrend($family, $months);
        }, 3600);
    }

    /** @return array<int, array{month: string, expenses: float, income: float, balance: float}> */
    private function buildMonthlyTrend(Family $family, int $months): array
    {
        $result = [];
        $now = new \DateTimeImmutable();

        for ($i = $months - 1; $i >= 0; --$i) {
            $date = $now->modify("-{$i} months");
            $from = new \DateTimeImmutable($date->format('Y-m-01'));
            $to = new \DateTimeImmutable($date->format('Y-m-t'));

            $expenses = (float) array_sum(array_column(
                $this->txRepo->sumByCategory($family, $from, $to),
                'total',
            ));
            $income = $this->txRepo->sumByType($family, $from, $to, TransactionType::Income);

            $result[] = [
                'month' => $date->format('M Y'),
                'expenses' => $expenses,
                'income' => $income,
                'balance' => $income - $expenses,
            ];
        }

        return $result;
    }
}
