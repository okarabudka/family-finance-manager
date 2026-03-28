# Шаг 3 — Основная бизнес-логика

> **Статус: ✅ РЕАЛИЗОВАНО**

## Цель
Реализовать сервисы для работы с транзакциями, балансом счетов и категоризацией.

---

## 3.1 TransactionService

Основной сервис для создания/изменения транзакций.

```php
// src/Domain/Transaction/TransactionService.php

class TransactionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountService $accountService,
        private readonly CategoryRuleService $categoryRuleService,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * Инвалидирует кэш месячных отчётов для семьи за указанный месяц.
     * Вызывается после любого изменения транзакций.
     */
    private function invalidateAnalyticsCache(Family $family, \DateTimeImmutable $date): void
    {
        $this->cache->delete("monthly_report_{$family->getId()}_{$date->format('Y-m')}");
        $this->cache->delete("monthly_trend_{$family->getId()}_6");
        $this->cache->delete("monthly_trend_{$family->getId()}_12");
    }

    public function addExpense(
        Family $family,
        User $user,
        string $amount,   // строка, а не float — чтобы не терять точность
        ?Category $category,
        Account $account,
        string $description = '',
        ?\DateTimeImmutable $date = null,
    ): Transaction {
        $transaction = new Transaction();
        $transaction
            ->setType(TransactionType::Expense)
            ->setAmount((string) abs($amount))
            ->setCurrency($account->getCurrency())
            ->setDescription($description)
            ->setSourceAccount($account)
            ->setCategory($category)
            ->setFamily($family)
            ->setCreatedBy($user)
            ->setExecutedAt($date ?? new \DateTimeImmutable())
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($transaction);
        $this->em->flush();

        $this->accountService->invalidateCache($account);
        $this->invalidateAnalyticsCache($family, $transaction->getExecutedAt());

        return $transaction;
    }

    public function addIncome(
        Family $family,
        User $user,
        string $amount,
        ?Category $category,
        Account $account,
        string $description = '',
        ?\DateTimeImmutable $date = null,
    ): Transaction {
        $transaction = new Transaction();
        $transaction
            ->setType(TransactionType::Income)
            ->setAmount((string) abs($amount))
            ->setCurrency($account->getCurrency())
            ->setDescription($description)
            ->setTargetAccount($account)
            ->setCategory($category)
            ->setFamily($family)
            ->setCreatedBy($user)
            ->setExecutedAt($date ?? new \DateTimeImmutable())
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($transaction);
        $this->em->flush();

        $this->accountService->invalidateCache($account);
        $this->invalidateAnalyticsCache($family, $transaction->getExecutedAt());

        return $transaction;
    }

    public function addTransfer(
        Family $family,
        User $user,
        string $amount,
        Account $from,
        Account $to,
        string $description = '',
        ?\DateTimeImmutable $date = null,
    ): Transaction {
        $transaction = new Transaction();
        $transaction
            ->setType(TransactionType::Transfer)
            ->setAmount((string) abs($amount))
            ->setCurrency($from->getCurrency())
            ->setDescription($description)
            ->setSourceAccount($from)
            ->setTargetAccount($to)
            ->setFamily($family)
            ->setCreatedBy($user)
            ->setExecutedAt($date ?? new \DateTimeImmutable())
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($transaction);
        $this->em->flush();

        $this->accountService->invalidateCache($from);
        $this->accountService->invalidateCache($to);
        $this->invalidateAnalyticsCache($family, $transaction->getExecutedAt());

        return $transaction;
    }

    public function delete(Transaction $transaction): void
    {
        $this->em->remove($transaction);
        $this->em->flush();
    }

    public function update(Transaction $transaction, array $data): Transaction
    {
        if (isset($data['amount'])) {
            $transaction->setAmount((string) abs($data['amount']));
        }
        if (isset($data['description'])) {
            $transaction->setDescription($data['description']);
        }
        if (isset($data['category'])) {
            $transaction->setCategory($data['category']);
        }
        if (isset($data['executedAt'])) {
            $transaction->setExecutedAt($data['executedAt']);
        }

        $this->em->flush();

        // Инвалидируем кэш для всех затронутых счетов и аналитики
        if ($transaction->getSourceAccount()) {
            $this->accountService->invalidateCache($transaction->getSourceAccount());
        }
        if ($transaction->getTargetAccount()) {
            $this->accountService->invalidateCache($transaction->getTargetAccount());
        }
        $this->invalidateAnalyticsCache($transaction->getFamily(), $transaction->getExecutedAt());

        return $transaction;
    }
}
```

---

## 3.2 AccountService — подсчёт баланса

Баланс считается динамически из суммы транзакций + начальный баланс.

```php
// src/Domain/Account/AccountService.php

class AccountService
{
    public function __construct(
        private readonly TransactionRepository $transactionRepo,
        private readonly CacheInterface $cache,
    ) {}

    public function getBalance(Account $account): float
    {
        $cacheKey = 'account_balance_' . $account->getId();

        return $this->cache->get($cacheKey, function () use ($account) {
            return $this->calculateBalance($account);
        });
    }

    public function getTotalBalance(Family $family): array
    {
        $accounts = $family->getAccounts()->filter(
            fn(Account $a) => $a->isActive() && $a->isIncludeInTotal()
        );

        $totals = [];
        foreach ($accounts as $account) {
            $currency = $account->getCurrency();
            $balance = $this->getBalance($account);
            $totals[$currency] = ($totals[$currency] ?? 0) + $balance;
        }

        return $totals;
    }

    private function calculateBalance(Account $account): float
    {
        $initial = (float) $account->getInitialBalance();

        // Суммируем: входящие транзакции (доходы, входящие переводы)
        $inflow = $this->transactionRepo->sumInflow($account);
        // Вычитаем: расходы и исходящие переводы
        $outflow = $this->transactionRepo->sumOutflow($account);

        return $initial + $inflow - $outflow;
    }

    public function invalidateCache(Account $account): void
    {
        $this->cache->delete('account_balance_' . $account->getId());
    }
}
```

---

## 3.3 TransactionRepository — основные запросы

```php
// src/Repository/TransactionRepository.php

class TransactionRepository extends ServiceEntityRepository
{
    public function sumInflow(Account $account): float
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.amount)')
            ->where('t.targetAccount = :account')
            ->andWhere('t.type IN (:types)')
            ->setParameter('account', $account)
            ->setParameter('types', [TransactionType::Income, TransactionType::Transfer])
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    public function sumOutflow(Account $account): float
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.amount)')
            ->where('t.sourceAccount = :account')
            ->andWhere('t.type IN (:types)')
            ->setParameter('account', $account)
            ->setParameter('types', [TransactionType::Expense, TransactionType::Transfer])
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    public function findByPeriod(
        Family $family,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?TransactionType $type = null,
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->where('t.family = :family')
            ->andWhere('t.executedAt BETWEEN :from AND :to')
            ->setParameter('family', $family)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('t.executedAt', 'DESC');

        if ($type !== null) {
            $qb->andWhere('t.type = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    public function sumByCategory(
        Family $family,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->createQueryBuilder('t')
            ->select('IDENTITY(t.category) as categoryId, SUM(t.amount) as total')
            ->where('t.family = :family')
            ->andWhere('t.executedAt BETWEEN :from AND :to')
            ->andWhere('t.type = :type')
            ->andWhere('t.isConfirmed = true')   // не учитываем неподтверждённые
            ->andWhere('t.isInternalTransfer = false')  // переводы не считаем расходом
            ->setParameter('family', $family)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('type', TransactionType::Expense)
            ->groupBy('t.category')
            ->getQuery()
            ->getArrayResult();
    }

    public function findRecentForFamily(Family $family, int $limit = 20): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.family = :family')
            ->setParameter('family', $family)
            ->orderBy('t.executedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Используется в AnalyticsService для подсчёта суммарного дохода/расхода за период.
     */
    public function sumByType(
        Family $family,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        TransactionType $type,
    ): float {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.amount)')
            ->where('t.family = :family')
            ->andWhere('t.executedAt BETWEEN :from AND :to')
            ->andWhere('t.type = :type')
            ->andWhere('t.isConfirmed = true')
            ->setParameter('family', $family)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Возвращает ID категорий, по которым были регулярные транзакции (минимум N месяцев из последних X).
     * Используется в SuggestionService для обнаружения «пропущенных» регулярных расходов.
     */
    public function findRegularCategories(Family $family, int $months = 3): array
    {
        $from = new \DateTimeImmutable("-{$months} months");

        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.category) as categoryId, COUNT(DISTINCT SUBSTRING(t.executedAt, 1, 7)) as monthCount')
            ->where('t.family = :family')
            ->andWhere('t.executedAt >= :from')
            ->andWhere('t.type = :type')
            ->andWhere('t.category IS NOT NULL')
            ->setParameter('family', $family)
            ->setParameter('from', $from)
            ->setParameter('type', TransactionType::Expense)
            ->groupBy('t.category')
            ->having('monthCount >= :months')
            ->setParameter('months', $months)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'categoryId');
    }

    /**
     * Проверяет, есть ли в текущем месяце хотя бы одна транзакция по категории.
     */
    public function hasCategoryTransactionThisMonth(Family $family, string $categoryId): bool
    {
        $now = new \DateTimeImmutable();
        $from = new \DateTimeImmutable($now->format('Y-m-01'));
        $to   = new \DateTimeImmutable($now->format('Y-m-t') . ' 23:59:59');

        $count = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.family = :family')
            ->andWhere('t.executedAt BETWEEN :from AND :to')
            ->andWhere('IDENTITY(t.category) = :categoryId')
            ->setParameter('family', $family)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Используется в TransactionController для фильтруемого списка транзакций.
     */
    public function createListQuery(Family $family, array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.family = :family')
            ->setParameter('family', $family)
            ->orderBy('t.executedAt', 'DESC');

        if (!empty($filters['type'])) {
            $qb->andWhere('t.type = :type')
               ->setParameter('type', TransactionType::from($filters['type']));
        }
        if (!empty($filters['category'])) {
            $qb->andWhere('IDENTITY(t.category) = :category')
               ->setParameter('category', $filters['category']);
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('t.executedAt >= :from')
               ->setParameter('from', new \DateTimeImmutable($filters['from']));
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('t.executedAt <= :to')
               ->setParameter('to', new \DateTimeImmutable($filters['to']));
        }

        return $qb;
    }
}
```

---

## 3.3.1 AccountRepository — вспомогательные методы

```php
// src/Repository/AccountRepository.php

class AccountRepository extends ServiceEntityRepository
{
    public function findActiveByFamily(Family $family): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.family = :family')
            ->andWhere('a.isActive = true')
            ->setParameter('family', $family)
            ->orderBy('a.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDefaultForFamily(Family $family): ?Account
    {
        return $this->findOneBy(['family' => $family, 'isDefault' => true]);
    }

    public function createFamilyQueryBuilder(Family $family): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->where('a.family = :family')
            ->andWhere('a.isActive = true')
            ->setParameter('family', $family)
            ->orderBy('a.sortOrder', 'ASC');
    }
}
```

---

## 3.3.2 CategoryRepository — вспомогательные методы

```php
// src/Repository/CategoryRepository.php

class CategoryRepository extends ServiceEntityRepository
{
    public function findByFamily(Family $family, CategoryType $type): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.family = :family')
            ->andWhere('c.type = :type')
            ->setParameter('family', $family)
            ->setParameter('type', $type)
            ->orderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function createFamilyQueryBuilder(Family $family): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->where('c.family = :family')
            ->setParameter('family', $family)
            ->orderBy('c.sortOrder', 'ASC');
    }
}
```

---

## 3.3.3 UserRepository — вспомогательные методы

```php
// src/Repository/UserRepository.php

class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function findByTelegramChatId(int $chatId): ?User
    {
        return $this->findOneBy(['telegramChatId' => $chatId]);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->findOneBy(['email' => $identifier])
            ?? throw new UserNotFoundException("User not found: $identifier");
    }
}
```

---

## 3.3.4 BudgetRepository

```php
// src/Repository/BudgetRepository.php

class BudgetRepository extends ServiceEntityRepository
{
    public function findForMonth(Family $family, Category $category, int $year, int $month): ?Budget
    {
        return $this->findOneBy([
            'family'   => $family,
            'category' => $category,
            'year'     => $year,
            'month'    => $month,
        ]);
    }
}
```

---

## 3.4 CategoryRuleService — автокатегоризация

```php
// src/Domain/Category/CategoryRuleService.php

class CategoryRuleService
{
    public function __construct(
        private readonly CategoryRuleRepository $ruleRepo,
    ) {}

    /**
     * Определяет категорию транзакции по ключевым словам и IBAN контрагента.
     */
    public function guessCategory(
        Family $family,
        string $description,
        ?string $counterpartyIban = null,
    ): ?Category {
        // Загружаем правила, отсортированные по приоритету (DESC) — иначе порядок произвольный
        $rules = $this->ruleRepo->findByFamilyOrderedByPriority($family);

        // Сначала проверяем правила с IBAN (более специфичные)
        foreach ($rules as $rule) {
            if ($counterpartyIban && $rule->getCounterpartyIban() === $counterpartyIban) {
                return $rule->getCategory();
            }
        }

        // Затем ищем по ключевым словам
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

    public function learnFromTransaction(Transaction $transaction): void
    {
        // Когда пользователь вручную назначает категорию импортированной транзакции,
        // предлагаем создать правило для автоматизации в будущем
        if ($transaction->getImportOrigin() === null) {
            return;
        }

        // Логика создания нового правила сохраняется в очередь для предложения пользователю
    }
}
```

---

## 3.5 Быстрый парсер текстовых сообщений (для Telegram)

```php
// src/Domain/Transaction/QuickInputParser.php

/**
 * Парсит быстрый ввод вида:
 *   "50 продукты"
 *   "150.50 ресторан обед с женой"
 *   "+1500 зарплата"
 *   "-200 перевод копилка"
 */
class QuickInputParser
{
    public function parse(string $input): ?QuickInputResult
    {
        // Паттерн: опциональный знак, число, пробел, описание
        $pattern = '/^([+-]?\d+(?:[.,]\d{1,2})?)\s+(.+)$/u';

        if (!preg_match($pattern, trim($input), $matches)) {
            return null;
        }

        $rawAmount = str_replace(',', '.', $matches[1]);
        $amount = (float) $rawAmount;

        if ($amount <= 0 && !str_starts_with($matches[1], '-')) {
            return null;  // нулевая или некорректная сумма без знака
        }

        $description = trim($matches[2]);

        // Определяем тип по знаку
        $type = match(true) {
            str_starts_with($matches[1], '+') => TransactionType::Income,
            str_starts_with($matches[1], '-') => TransactionType::Transfer,
            default                            => TransactionType::Expense,
        };

        return new QuickInputResult(
            amount: (string) abs($amount),   // строка для точности
            description: $description,
            type: $type,
        );
    }
}

readonly class QuickInputResult
{
    public function __construct(
        public string $amount,   // строка, не float — передаётся напрямую в TransactionService
        public string $description,
        public TransactionType $type,
    ) {}
}
```

---

## 3.6 Веб-контроллеры (базовые CRUD)

```php
// src/Controller/Web/TransactionController.php

#[Route('/transactions')]
class TransactionController extends AbstractController
{
    #[Route('', name: 'transaction_list', methods: ['GET'])]
    public function list(
        Request $request,
        TransactionRepository $repo,
        PaginatorInterface $paginator,
    ): Response {
        $family = $this->getUser()->getFamily();
        $query = $repo->createListQuery($family, $request->query->all());

        $transactions = $paginator->paginate($query, $request->query->getInt('page', 1), 30);

        return $this->render('transaction/list.html.twig', [
            'transactions' => $transactions,
        ]);
    }

    #[Route('/new', name: 'transaction_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        TransactionService $transactionService,
    ): Response {
        $form = $this->createForm(TransactionFormType::class);  // НЕ TransactionType — это Enum
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $transactionService->addExpense(
                family: $this->getUser()->getFamily(),
                user: $this->getUser(),
                amount: $data['amount'],
                category: $data['category'],
                account: $data['account'],
                description: $data['description'],
                date: $data['executedAt'],
            );

            $this->addFlash('success', 'Транзакция добавлена');
            return $this->redirectToRoute('transaction_list');
        }

        return $this->render('transaction/new.html.twig', ['form' => $form]);
    }
}
```

---

## 3.7 Веб-форма транзакции

```php
// src/Form/TransactionFormType.php

class TransactionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $family = $options['family'];

        $builder
            ->add('type', EnumType::class, [
                'class' => TransactionType::class,
                'label' => 'Тип',
            ])
            ->add('amount', MoneyType::class, [
                'currency' => $options['family']->getCurrency(),  // не хардкодим EUR
                'label' => 'Сумма',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'query_builder' => fn($repo) => $repo->createFamilyQueryBuilder($family),
                'label' => 'Категория',
            ])
            ->add('account', EntityType::class, [
                'class' => Account::class,
                'query_builder' => fn($repo) => $repo->createFamilyQueryBuilder($family),
                'label' => 'Счёт',
            ])
            ->add('executedAt', DateType::class, [
                'label' => 'Дата',
                'data' => new \DateTime(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('family');
        $resolver->setAllowedTypes('family', Family::class);
    }
}
```

---

## Результат шага 3

- `TransactionService` — создание расходов, доходов, переводов
- `AccountService` — подсчёт баланса с кэшированием в Redis
- `CategoryRuleService` — автокатегоризация по ключевым словам
- `QuickInputParser` — парсинг текстовых сообщений от бота
- Базовые веб-контроллеры со списком и формой создания транзакций

## Следующий шаг

→ [04-telegram-bot.md](04-telegram-bot.md) — Telegram-бот: команды, диалоги, клавиатуры
