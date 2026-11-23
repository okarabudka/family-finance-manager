<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Suggestion;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Analytics\CategoryComparison;
use App\Domain\Analytics\CategoryReport;
use App\Domain\Analytics\MonthlyReport;
use App\Domain\Analytics\PeriodComparison;
use App\Domain\Suggestion\SuggestionPriority;
use App\Domain\Suggestion\SuggestionService;
use App\Domain\Suggestion\SuggestionType;
use App\Entity\Category;
use App\Entity\CategoryType;
use App\Entity\Family;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class SuggestionServiceTest extends TestCase
{
    private AnalyticsService&MockObject $analyticsService;
    private TransactionRepository&MockObject $txRepo;
    private CategoryRepository&MockObject $categoryRepo;
    private SuggestionService $service;
    private Family $family;

    protected function setUp(): void
    {
        $this->analyticsService = $this->createMock(AnalyticsService::class);
        $this->txRepo           = $this->createMock(TransactionRepository::class);
        $this->categoryRepo     = $this->createMock(CategoryRepository::class);

        $this->service = new SuggestionService(
            $this->analyticsService,
            $this->txRepo,
            $this->categoryRepo,
            $this->createTranslator(),
        );

        $this->family = new Family();
        $this->family->setName('Test')->setCurrency('EUR');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Renders "key {param: value, ...}" so tests can check which message and values were used. */
    private function createTranslator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            /** @param array<string, mixed> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id.' '.json_encode($parameters, \JSON_UNESCAPED_UNICODE);
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    private function makeCategory(string $name = 'Test Category'): Category
    {
        $c = new Category();
        $c->setName($name)->setType(CategoryType::Expense);
        return $c;
    }

    private function makeEmptyMonthlyReport(): MonthlyReport
    {
        return new MonthlyReport(
            from:          new \DateTimeImmutable('first day of this month'),
            to:            new \DateTimeImmutable('last day of this month'),
            totalIncome:   0.0,
            totalExpenses: 0.0,
            balance:       0.0,
            byCategory:    [],
        );
    }

    /** @param list<CategoryComparison> $categoryComparisons */
    private function makePeriodComparison(
        MonthlyReport $current,
        MonthlyReport $previous,
        array $categoryComparisons = [],
    ): PeriodComparison {
        return new PeriodComparison($current, $previous, $categoryComparisons);
    }

    /** Set up analytics to return the given comparison and no missing-category flags. */
    private function stubComparison(PeriodComparison $comparison): void
    {
        $this->analyticsService->method('comparePeriods')->willReturn($comparison);
        $this->txRepo->method('findRegularCategories')->willReturn([]);
    }

    // ── no suggestions ────────────────────────────────────────────────────────

    public function testReturnsEmptyWhenNoAnomalies(): void
    {
        $empty = $this->makeEmptyMonthlyReport();
        $this->stubComparison($this->makePeriodComparison($empty, $empty));

        $result = $this->service->getSuggestions($this->family);

        self::assertSame([], $result);
    }

    // ── expense spike ─────────────────────────────────────────────────────────

    public function testDetectsExpenseSpikeAboveThreshold(): void
    {
        $category = $this->makeCategory('Restaurants');

        $comparison = $this->makePeriodComparison(
            current:  $this->makeEmptyMonthlyReport(),
            previous: $this->makeEmptyMonthlyReport(),
            categoryComparisons: [
                new CategoryComparison(
                    category:        $category,
                    currentAmount:   130.0,
                    previousAmount:  100.0,
                    diff:            30.0,
                    diffPercent:     30.0, // exactly 30 — NOT above threshold
                ),
            ],
        );
        $this->stubComparison($comparison);

        $result = $this->service->getSuggestions($this->family);

        $spikes = array_filter($result, fn($s) => $s->type === SuggestionType::ExpenseSpike);
        // diffPercent must be > 30 (strict), so 30.0 should NOT trigger
        self::assertCount(0, $spikes);
    }

    public function testDetectsExpenseSpikeStrictlyAbove30Percent(): void
    {
        $category = $this->makeCategory('Restaurants');

        $comparison = $this->makePeriodComparison(
            current:  $this->makeEmptyMonthlyReport(),
            previous: $this->makeEmptyMonthlyReport(),
            categoryComparisons: [
                new CategoryComparison(
                    category:        $category,
                    currentAmount:   131.0,
                    previousAmount:  100.0,
                    diff:            31.0,
                    diffPercent:     31.0,
                ),
            ],
        );
        $this->stubComparison($comparison);

        $result = $this->service->getSuggestions($this->family);

        $spikes = array_filter($result, fn($s) => $s->type === SuggestionType::ExpenseSpike);
        self::assertCount(1, $spikes);
        $spike = array_values($spikes)[0];
        self::assertSame(SuggestionPriority::High, $spike->priority);
        self::assertSame($category, $spike->category);
        self::assertStringStartsWith('suggestion.expense_spike.title', $spike->title);
        self::assertStringContainsString('"%percent%":"31"', $spike->message);
        self::assertStringContainsString('"%current%":"131"', $spike->message);
    }

    public function testExpenseSpikeRequiresDiffAbove20(): void
    {
        // 50% increase but only 10 EUR difference — should NOT trigger
        $category = $this->makeCategory('Coffee');

        $comparison = $this->makePeriodComparison(
            current:  $this->makeEmptyMonthlyReport(),
            previous: $this->makeEmptyMonthlyReport(),
            categoryComparisons: [
                new CategoryComparison(
                    category:        $category,
                    currentAmount:   30.0,
                    previousAmount:  20.0,
                    diff:            10.0,     // ≤ 20
                    diffPercent:     50.0,     // > 30%
                ),
            ],
        );
        $this->stubComparison($comparison);

        $result = $this->service->getSuggestions($this->family);

        $spikes = array_filter($result, fn($s) => $s->type === SuggestionType::ExpenseSpike);
        self::assertCount(0, $spikes);
    }

    public function testExpenseSpikeNotTriggeredWhenNullDiffPercent(): void
    {
        $category = $this->makeCategory('New Category');

        $comparison = $this->makePeriodComparison(
            current:  $this->makeEmptyMonthlyReport(),
            previous: $this->makeEmptyMonthlyReport(),
            categoryComparisons: [
                new CategoryComparison(
                    category:      $category,
                    currentAmount: 200.0,
                    previousAmount: 0.0,
                    diff:          200.0,
                    diffPercent:   null, // no previous data
                ),
            ],
        );
        $this->stubComparison($comparison);

        $result = $this->service->getSuggestions($this->family);

        $spikes = array_filter($result, fn($s) => $s->type === SuggestionType::ExpenseSpike);
        self::assertCount(0, $spikes);
    }

    // ── budget exceeded ───────────────────────────────────────────────────────

    public function testDetectsBudgetExceeded(): void
    {
        $category = $this->makeCategory('Groceries');

        $catReport = new CategoryReport(
            category:          $category,
            amount:            550.0,
            percent:           40.0,
            budgetAmount:      500.0,
            budgetUsedPercent: 110.0, // > 100 → exceeded
        );

        $current = new MonthlyReport(
            from:          new \DateTimeImmutable('first day of this month'),
            to:            new \DateTimeImmutable('last day of this month'),
            totalIncome:   0.0,
            totalExpenses: 550.0,
            balance:       -550.0,
            byCategory:    [$catReport],
        );

        $this->stubComparison($this->makePeriodComparison($current, $this->makeEmptyMonthlyReport()));

        $result = $this->service->getSuggestions($this->family);

        $exceeded = array_filter($result, fn($s) => $s->type === SuggestionType::BudgetExceeded);
        self::assertCount(1, $exceeded);
        $s = array_values($exceeded)[0];
        self::assertSame(SuggestionPriority::High, $s->priority);
        self::assertSame($category, $s->category);
        self::assertStringStartsWith('suggestion.budget_exceeded.title', $s->title);
        self::assertStringContainsString('"%category%":"Groceries"', $s->message);
        self::assertStringContainsString('"%currency%":"EUR"', $s->message);
    }

    public function testBudgetNotExceededAt100Percent(): void
    {
        $category  = $this->makeCategory('Groceries');
        $catReport = new CategoryReport(
            category:          $category,
            amount:            500.0,
            percent:           40.0,
            budgetAmount:      500.0,
            budgetUsedPercent: 100.0, // exactly 100 — not exceeded (strict >)
        );

        $current = new MonthlyReport(
            from:          new \DateTimeImmutable('first day of this month'),
            to:            new \DateTimeImmutable('last day of this month'),
            totalIncome:   0.0,
            totalExpenses: 500.0,
            balance:       -500.0,
            byCategory:    [$catReport],
        );

        $this->stubComparison($this->makePeriodComparison($current, $this->makeEmptyMonthlyReport()));

        $result = $this->service->getSuggestions($this->family);

        $exceeded = array_filter($result, fn($s) => $s->type === SuggestionType::BudgetExceeded);
        self::assertCount(0, $exceeded);
    }

    public function testBudgetExceededNotTriggeredWhenNoBudget(): void
    {
        $category  = $this->makeCategory('Entertainment');
        $catReport = new CategoryReport(
            category:          $category,
            amount:            300.0,
            percent:           20.0,
            budgetAmount:      null,
            budgetUsedPercent: null, // no budget set
        );

        $current = new MonthlyReport(
            from:          new \DateTimeImmutable('first day of this month'),
            to:            new \DateTimeImmutable('last day of this month'),
            totalIncome:   0.0,
            totalExpenses: 300.0,
            balance:       -300.0,
            byCategory:    [$catReport],
        );

        $this->stubComparison($this->makePeriodComparison($current, $this->makeEmptyMonthlyReport()));

        $result = $this->service->getSuggestions($this->family);

        $exceeded = array_filter($result, fn($s) => $s->type === SuggestionType::BudgetExceeded);
        self::assertCount(0, $exceeded);
    }

    // ── missing regular expense ───────────────────────────────────────────────

    public function testDetectsMissingRegularExpense(): void
    {
        $category = $this->makeCategory('Subscriptions');

        $this->analyticsService->method('comparePeriods')
            ->willReturn($this->makePeriodComparison(
                $this->makeEmptyMonthlyReport(),
                $this->makeEmptyMonthlyReport(),
            ));

        // Category appeared regularly in last 3 months
        $this->txRepo->method('findRegularCategories')
            ->willReturn(['cat-uuid-1']);

        // But no transaction this month
        $this->txRepo->method('hasCategoryTransactionThisMonth')
            ->willReturn(false);

        $this->categoryRepo->method('find')
            ->willReturn($category);

        $result = $this->service->getSuggestions($this->family);

        $missing = array_filter($result, fn($s) => $s->type === SuggestionType::MissingRegularExpense);
        self::assertCount(1, $missing);
        $s = array_values($missing)[0];
        self::assertSame(SuggestionPriority::Medium, $s->priority);
        self::assertSame($category, $s->category);
        self::assertStringStartsWith('suggestion.missing_expense.title', $s->title);
    }

    public function testNoMissingExpenseWhenCategoryHasTransactionThisMonth(): void
    {
        $this->analyticsService->method('comparePeriods')
            ->willReturn($this->makePeriodComparison(
                $this->makeEmptyMonthlyReport(),
                $this->makeEmptyMonthlyReport(),
            ));

        $this->txRepo->method('findRegularCategories')->willReturn(['cat-uuid-1']);
        $this->txRepo->method('hasCategoryTransactionThisMonth')->willReturn(true);

        $result = $this->service->getSuggestions($this->family);

        $missing = array_filter($result, fn($s) => $s->type === SuggestionType::MissingRegularExpense);
        self::assertCount(0, $missing);
    }

    public function testMissingExpenseSkipsDeletedCategory(): void
    {
        $this->analyticsService->method('comparePeriods')
            ->willReturn($this->makePeriodComparison(
                $this->makeEmptyMonthlyReport(),
                $this->makeEmptyMonthlyReport(),
            ));

        $this->txRepo->method('findRegularCategories')->willReturn(['cat-uuid-orphan']);
        $this->txRepo->method('hasCategoryTransactionThisMonth')->willReturn(false);
        $this->categoryRepo->method('find')->willReturn(null); // deleted category

        $result = $this->service->getSuggestions($this->family);

        $missing = array_filter($result, fn($s) => $s->type === SuggestionType::MissingRegularExpense);
        self::assertCount(0, $missing);
    }

    // ── sorting ───────────────────────────────────────────────────────────────

    public function testSuggestionsAreSortedByPriorityDescending(): void
    {
        $catExpense = $this->makeCategory('Food');
        $catBudget  = $this->makeCategory('Transport');

        // Spike (High) + BudgetExceeded (High) simultaneously
        $comparison = $this->makePeriodComparison(
            current: new MonthlyReport(
                from:          new \DateTimeImmutable('first day of this month'),
                to:            new \DateTimeImmutable('last day of this month'),
                totalIncome:   0.0,
                totalExpenses: 550.0,
                balance:       -550.0,
                byCategory: [
                    new CategoryReport(
                        category:          $catBudget,
                        amount:            550.0,
                        percent:           100.0,
                        budgetAmount:      500.0,
                        budgetUsedPercent: 110.0,
                    ),
                ],
            ),
            previous: $this->makeEmptyMonthlyReport(),
            categoryComparisons: [
                new CategoryComparison(
                    category:        $catExpense,
                    currentAmount:   200.0,
                    previousAmount:  100.0,
                    diff:            100.0,
                    diffPercent:     100.0,
                ),
            ],
        );

        $this->analyticsService->method('comparePeriods')->willReturn($comparison);
        $this->txRepo->method('findRegularCategories')->willReturn([]);

        $result = $this->service->getSuggestions($this->family);

        self::assertNotEmpty($result);

        // Verify descending sort
        $priorities = array_map(fn($s) => $s->priority->value, $result);
        $sorted = $priorities;
        rsort($sorted);
        self::assertSame($sorted, $priorities);
    }
}
