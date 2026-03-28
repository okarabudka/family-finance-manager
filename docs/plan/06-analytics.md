# Шаг 6 — Аналитика, графики и предложения по оптимизации

> **Статус: ✅ РЕАЛИЗОВАНО**

## Цель
Реализовать AnalyticsService с месячными отчётами, графиками Chart.js в веб-интерфейсе и генерацией PNG-графиков для Telegram. Добавить SuggestionService с анализом аномалий и рекомендациями.

---

## 6.1 AnalyticsService

```php
// src/Domain/Analytics/AnalyticsService.php

class AnalyticsService
{
    public function __construct(
        private readonly TransactionRepository $txRepo,
        private readonly CategoryRepository $categoryRepo,
        private readonly BudgetRepository $budgetRepo,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * Месячный отчёт: доходы, расходы, разбивка по категориям.
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
            // Категория могла быть удалена — пропускаем "осиротевшие" транзакции
            if ($category === null) {
                continue;
            }
            $budget = $this->budgetRepo->findForMonth($family, $category, (int)$from->format('Y'), (int)$from->format('n'));

            $categoriesData[] = new CategoryReport(
                category: $category,
                amount: (float) $row['total'],
                percent: $totalExpenses > 0 ? ((float)$row['total'] / $totalExpenses * 100) : 0,
                budgetAmount: $budget ? (float)$budget->getPlannedAmount() : null,
                budgetUsedPercent: ($budget && (float)$budget->getPlannedAmount() > 0)
                    ? ((float)$row['total'] / (float)$budget->getPlannedAmount() * 100)
                    : null,
            );
        }

        // Сортируем по сумме убыванию
        usort($categoriesData, fn($a, $b) => $b->amount <=> $a->amount);

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
     * Сравнение расходов по категориям: текущий месяц vs прошлый.
     */
    public function comparePeriods(
        Family $family,
        \DateTimeImmutable $currentFrom,
        \DateTimeImmutable $currentTo,
        \DateTimeImmutable $previousFrom,
        \DateTimeImmutable $previousTo,
    ): PeriodComparison {
        $current = $this->buildMonthlyReport($family, $currentFrom, $currentTo);
        $previous = $this->buildMonthlyReport($family, $previousFrom, $previousTo);

        $comparisons = [];
        foreach ($current->byCategory as $currentCat) {
            $prevCat = $this->findCategoryInReport($previous, $currentCat->category);
            $prevAmount = $prevCat?->amount ?? 0;
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

    /**
     * Данные для графика расходов по месяцам (последние N месяцев).
     */
    public function getMonthlyTrend(Family $family, int $months = 6): array
    {
        $cacheKey = "monthly_trend_{$family->getId()}_{$months}";

        return $this->cache->get($cacheKey, function () use ($family, $months) {
            return $this->buildMonthlyTrend($family, $months);
        }, 3600);
    }

    private function buildMonthlyTrend(Family $family, int $months): array
    {
        $result = [];
        $now = new \DateTimeImmutable();

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = $now->modify("-{$i} months");
            $from = new \DateTimeImmutable($date->format('Y-m-01'));
            $to = new \DateTimeImmutable($date->format('Y-m-t'));

            $expenses = (float) array_sum(array_column(
                $this->txRepo->sumByCategory($family, $from, $to),
                'total'
            ));
            $income = $this->txRepo->sumByType($family, $from, $to, TransactionType::Income);

            $result[] = [
                'month'    => $date->format('M Y'),
                'expenses' => $expenses,
                'income'   => $income,
                'balance'  => $income - $expenses,
            ];
        }

        return $result;
    }
}
```

> **Примечание**: `getMonthlyTrend` не кэшировался в исходной версии — добавлен TTL 3600 с теми же параметрами, что и у `getMonthlyReport`. Кэш инвалидируется при создании транзакции в `TransactionService`.
```

---

## 6.2 SuggestionService — рекомендации по оптимизации

```php
// src/Domain/Suggestion/SuggestionService.php

class SuggestionService
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
        private readonly TransactionRepository $txRepo,
        private readonly CategoryRepository $categoryRepo,  // был пропущен в оригинале
    ) {}

    /**
     * Возвращает список рекомендаций для семьи.
     */
    public function getSuggestions(Family $family): array
    {
        $now = new \DateTimeImmutable();
        $currentFrom = new \DateTimeImmutable($now->format('Y-m-01'));
        $currentTo = new \DateTimeImmutable($now->format('Y-m-t'));
        $prevFrom = $currentFrom->modify('-1 month');
        $prevTo = new \DateTimeImmutable($prevFrom->format('Y-m-t'));

        $comparison = $this->analyticsService->comparePeriods(
            $family, $currentFrom, $currentTo, $prevFrom, $prevTo
        );

        $suggestions = [];

        // 1. Категории с аномальным ростом (> 30%)
        foreach ($comparison->categoryComparisons as $comp) {
            if ($comp->diffPercent !== null && $comp->diffPercent > 30 && $comp->diff > 20) {
                $suggestions[] = new Suggestion(
                    type: SuggestionType::ExpenseSpike,
                    priority: SuggestionPriority::High,
                    title: "Рост расходов: {$comp->category->getName()}",
                    message: sprintf(
                        'В этом месяце вы потратили на %s %.0f €, что на %.0f%% больше, чем в прошлом (%.0f €).',
                        $comp->category->getName(),
                        $comp->currentAmount,
                        $comp->diffPercent,
                        $comp->previousAmount,
                    ),
                    category: $comp->category,
                    amount: $comp->diff,
                );
            }
        }

        // 2. Превышение бюджета
        foreach ($comparison->current->byCategory as $catReport) {
            if ($catReport->budgetUsedPercent !== null && $catReport->budgetUsedPercent > 100) {
                $suggestions[] = new Suggestion(
                    type: SuggestionType::BudgetExceeded,
                    priority: SuggestionPriority::High,
                    title: "Бюджет превышен: {$catReport->category->getName()}",
                    message: sprintf(
                        'Бюджет на %s превышен на %.0f%%. Потрачено %.0f € из %.0f € запланированных.',
                        $catReport->category->getName(),
                        $catReport->budgetUsedPercent - 100,
                        $catReport->amount,
                        $catReport->budgetAmount,
                    ),
                    category: $catReport->category,
                    amount: $catReport->amount - $catReport->budgetAmount,
                );
            }
        }

        // 3. Месяц заканчивается, баланс отрицательный
        $daysLeft = (int)$now->diff($currentTo)->days;
        if ($daysLeft <= 7 && $comparison->current->balance < 0) {
            $suggestions[] = new Suggestion(
                type: SuggestionType::NegativeBalance,
                priority: SuggestionPriority::Critical,
                title: 'Расходы превышают доходы',
                message: sprintf(
                    'До конца месяца %d дн. Расходы превышают доходы на %.0f €.',
                    $daysLeft,
                    abs($comparison->current->balance),
                ),
            );
        }

        // 4. Категория без транзакций уже N дней (возможно забыли внести)
        $suggestions = array_merge($suggestions, $this->detectMissingCategories($family));

        // Сортируем по приоритету
        usort($suggestions, fn($a, $b) => $b->priority->value <=> $a->priority->value);

        return $suggestions;
    }

    private function detectMissingCategories(Family $family): array
    {
        // Если в прошлом месяце была регулярная категория (например, аренда),
        // но в текущем её нет — предупреждаем
        $suggestions = [];
        $now = new \DateTimeImmutable();

        $regularCategories = $this->txRepo->findRegularCategories($family, months: 3);

        foreach ($regularCategories as $categoryId) {
            $hasThisMonth = $this->txRepo->hasCategoryTransactionThisMonth($family, $categoryId);
            if (!$hasThisMonth) {
                $category = $this->categoryRepo->find($categoryId);
                // Категория могла быть удалена — пропускаем
                if ($category === null) {
                    continue;
                }
                $suggestions[] = new Suggestion(
                    type: SuggestionType::MissingRegularExpense,
                    priority: SuggestionPriority::Medium,
                    title: "Нет записей: {$category->getName()}",
                    message: "В прошлых месяцах у вас были регулярные расходы на «{$category->getName()}», но в этом месяце пока нет. Всё в порядке?",
                    category: $category,
                );
            }
        }

        return $suggestions;
    }
}
```

---

## 6.3 Value Objects для отчётов

```php
// src/Domain/Analytics/MonthlyReport.php
readonly class MonthlyReport
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public float $totalIncome,
        public float $totalExpenses,
        public float $balance,
        /** @var CategoryReport[] */
        public array $byCategory,
    ) {}
}

// src/Domain/Analytics/CategoryReport.php
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

// src/Domain/Analytics/CategoryComparison.php
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

// src/Domain/Analytics/PeriodComparison.php
readonly class PeriodComparison
{
    public function __construct(
        public MonthlyReport $current,
        public MonthlyReport $previous,
        /** @var CategoryComparison[] */
        public array $categoryComparisons,
    ) {}
}

// src/Domain/Suggestion/Suggestion.php
readonly class Suggestion
{
    public function __construct(
        public SuggestionType $type,
        public SuggestionPriority $priority,
        public string $title,
        public string $message,
        public ?Category $category = null,
        public ?float $amount = null,
    ) {}
}

enum SuggestionType: string {
    case ExpenseSpike          = 'expense_spike';
    case BudgetExceeded        = 'budget_exceeded';
    case NegativeBalance       = 'negative_balance';
    case MissingRegularExpense = 'missing_regular_expense';
}

enum SuggestionPriority: int {
    case Low      = 1;
    case Medium   = 2;
    case High     = 3;
    case Critical = 4;
}
```

---

## 6.4 Веб-дашборд с Chart.js

```twig
{# templates/dashboard/index.html.twig #}
{% extends 'base.html.twig' %}

{% block content %}
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    {# Карточки с балансом #}
    {% for account in accounts %}
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">{{ account.name }}</div>
        <div class="text-2xl font-bold {{ balance[account.id] >= 0 ? 'text-green-600' : 'text-red-600' }}">
            {{ balance[account.id]|number_format(2, ',', '.') }} {{ account.currency }}
        </div>
    </div>
    {% endfor %}
</div>

{# График расходов по категориям (пончик) #}
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold mb-4">Расходы по категориям</h3>
        <canvas id="categoryChart" width="400" height="300"></canvas>
    </div>

    {# График по месяцам (линия) #}
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold mb-4">Динамика за 6 месяцев</h3>
        <canvas id="trendChart" width="400" height="300"></canvas>
    </div>
</div>

{# Рекомендации #}
{% if suggestions %}
<div class="mt-6">
    <h3 class="font-semibold mb-3">💡 Рекомендации</h3>
    {% for suggestion in suggestions %}
    {# SuggestionPriority: Critical=4, High=3, Medium=2, Low=1 #}
    <div class="bg-{{ suggestion.priority.value >= 4 ? 'red' : (suggestion.priority.value >= 3 ? 'orange' : 'yellow') }}-50 border border-{{ suggestion.priority.value >= 4 ? 'red' : (suggestion.priority.value >= 3 ? 'orange' : 'yellow') }}-200 rounded p-3 mb-2">
        <div class="font-medium">{{ suggestion.title }}</div>
        <div class="text-sm text-gray-600">{{ suggestion.message }}</div>
    </div>
    {% endfor %}
</div>
{% endif %}

<script>
// График по категориям
const categoryCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(categoryCtx, {
    type: 'doughnut',
    data: {
        labels: {{ categoryLabels|json_encode|raw }},
        datasets: [{
            data: {{ categoryAmounts|json_encode|raw }},
            backgroundColor: {{ categoryColors|json_encode|raw }},
        }]
    },
    options: {
        plugins: {
            tooltip: {
                callbacks: {
                    label: (ctx) => `${ctx.label}: ${ctx.parsed.toFixed(2)} €`
                }
            }
        }
    }
});

// График тренда
const trendCtx = document.getElementById('trendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'bar',
    data: {
        labels: {{ trendLabels|json_encode|raw }},
        datasets: [
            {
                label: 'Расходы',
                data: {{ trendExpenses|json_encode|raw }},
                backgroundColor: 'rgba(239, 68, 68, 0.5)',
                borderColor: 'rgb(239, 68, 68)',
                borderWidth: 1,
            },
            {
                label: 'Доходы',
                data: {{ trendIncome|json_encode|raw }},
                backgroundColor: 'rgba(34, 197, 94, 0.5)',
                borderColor: 'rgb(34, 197, 94)',
                borderWidth: 1,
            }
        ]
    },
    options: {
        scales: { y: { beginAtZero: true } }
    }
});
</script>
{% endblock %}
```

---

## 6.5 Генерация PNG-графика для Telegram

Для отправки графика в Telegram нужно сгенерировать PNG на сервере (Chart.js работает только в браузере). Используем `mbarchart/php-chart` или Quickchart.io API.

```php
// src/Domain/Analytics/ChartGenerator.php

class ChartGenerator
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $storagePath,
    ) {}

    /**
     * Генерирует PNG через Quickchart.io (бесплатный, self-host тоже доступен).
     */
    public function generatePieChart(array $labels, array $data, string $title): string
    {
        $config = [
            'type' => 'doughnut',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'data' => $data,
                    'backgroundColor' => $this->generateColors(count($data)),
                ]],
            ],
            'options' => [
                'plugins' => [
                    'title' => ['display' => true, 'text' => $title, 'font' => ['size' => 16]],
                    'legend' => ['position' => 'bottom'],
                ],
            ],
        ];

        $chartsDir = $this->storagePath . '/charts';
        if (!is_dir($chartsDir)) {
            mkdir($chartsDir, 0755, true);
        }
        $tmpFile = $chartsDir . '/' . uniqid('chart_') . '.png';

        try {
            $response = $this->httpClient->request('GET', 'https://quickchart.io/chart', [
                'query' => [
                    'c' => json_encode($config),
                    'width' => 600,
                    'height' => 400,
                    'backgroundColor' => 'white',
                ]
            ]);
            file_put_contents($tmpFile, $response->getContent());
        } catch (\Exception $e) {
            // Не оставляем битый файл при ошибке
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            throw $e;
        }

        return $tmpFile;
    }

    public function generateBarChart(array $labels, array $expenses, array $income, string $title): string
    {
        $config = [
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'Расходы', 'data' => $expenses, 'backgroundColor' => '#EF4444'],
                    ['label' => 'Доходы',  'data' => $income,   'backgroundColor' => '#22C55E'],
                ],
            ],
            'options' => [
                'plugins' => ['title' => ['display' => true, 'text' => $title]],
                'scales'  => ['y' => ['beginAtZero' => true]],
            ],
        ];

        $chartsDir = $this->storagePath . '/charts';
        if (!is_dir($chartsDir)) {
            mkdir($chartsDir, 0755, true);
        }
        $tmpFile = $chartsDir . '/' . uniqid('chart_') . '.png';

        try {
            $response = $this->httpClient->request('GET', 'https://quickchart.io/chart', [
                'query' => ['c' => json_encode($config), 'width' => 700, 'height' => 400, 'backgroundColor' => 'white']
            ]);
            file_put_contents($tmpFile, $response->getContent());
        } catch (\Exception $e) {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            throw $e;
        }

        return $tmpFile;
    }

    private function generateColors(int $count): array
    {
        $palette = ['#6366F1','#F59E0B','#EF4444','#22C55E','#3B82F6','#EC4899','#14B8A6','#F97316','#8B5CF6','#06B6D4'];
        $colors = [];
        for ($i = 0; $i < $count; $i++) {
            $colors[] = $palette[$i % count($palette)];
        }
        return $colors;
    }
}
```

---

## 6.6 Telegram команда /stats

```php
class StatsCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly AnalyticsService $analyticsService,
        private readonly ChartGenerator $chartGenerator,
        private readonly TelegramSender $sender,
    ) {}

    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if (!$user || !$user->getFamily()) {
            $this->sender->sendMessage($chatId, '❌ Сначала зарегистрируйтесь: /start');
            return;
        }
        $family = $user->getFamily();
        $now = new \DateTimeImmutable();

        $report = $this->analyticsService->getMonthlyReport(
            $family,
            new \DateTimeImmutable($now->format('Y-m-01')),
            new \DateTimeImmutable($now->format('Y-m-t')),
        );

        // Генерируем PNG
        $labels = array_map(fn($c) => $c->category->getName(), $report->byCategory);
        $amounts = array_map(fn($c) => round($c->amount, 2), $report->byCategory);

        $chartPath = $this->chartGenerator->generatePieChart(
            $labels,
            $amounts,
            'Расходы ' . $now->format('F Y')
        );

        $caption = "📊 *Расходы по категориям — {$now->format('F Y')}*\n\n"
            . "📤 Расходы: *{$report->totalExpenses} €*\n"
            . "📥 Доходы: *{$report->totalIncome} €*\n"
            . "💾 Сохранено: *{$report->balance} €*";

        $this->sender->sendPhoto($chatId, $chartPath, $caption);

        // Удаляем временный файл
        unlink($chartPath);
    }
}
```

---

## 6.7 Еженедельный отчёт (Scheduler)

```php
// src/Command/WeeklyReportCommand.php

#[AsCommand(name: 'app:report:weekly')]
class WeeklyReportCommand extends Command
{
    public function __construct(
        private readonly FamilyRepository $familyRepo,
        private readonly SuggestionService $suggestionService,
        private readonly TelegramSender $sender,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $families = $this->familyRepo->findAll();

        foreach ($families as $family) {
            $suggestions = $this->suggestionService->getSuggestions($family);

            foreach ($family->getMembers() as $user) {
                if (!$user->getTelegramChatId()) continue;

                if (empty($suggestions)) {
                    $this->sender->sendMessage(
                        $user->getTelegramChatId(),
                        '✅ Всё отлично! Никаких предупреждений на эту неделю.'
                    );
                } else {
                    $text = "💡 *Еженедельный обзор*\n\n";
                    foreach (array_slice($suggestions, 0, 3) as $s) {
                        $text .= "• {$s->title}: {$s->message}\n\n";
                    }
                    $this->sender->sendMessage($user->getTelegramChatId(), $text, parseMode: 'Markdown');
                }
            }
        }

        return Command::SUCCESS;
    }
}
```

Запуск по расписанию (добавить в crontab или через Symfony Scheduler):
```
0 9 * * 1 docker compose exec -T app php bin/console app:report:weekly
```

---

## Результат шага 6

- `AnalyticsService` с месячными отчётами и трендами
- `SuggestionService` — аномалии, превышения бюджета, регулярные расходы
- Веб-дашборд с Chart.js (пончик + столбцы)
- `ChartGenerator` — PNG-графики через Quickchart.io для Telegram
- Команда `/stats` отправляет диаграмму прямо в чат
- Еженедельный отчёт с рекомендациями

## Следующий шаг

→ [07-deployment.md](07-deployment.md) — Развёртывание на Hetzner, SSL, CI/CD
