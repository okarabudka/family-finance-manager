# Шаг 2 — База данных и сущности Doctrine

## Цель
Создать полную схему БД через Doctrine ORM с миграциями.

---

## 2.1 Схема базы данных

### Диаграмма связей

```
families ──< family_members >── users
   │
   ├──< accounts
   │       └── balance (вычисляется из transactions)
   │
   ├──< categories (дерево, self-referencing)
   │
   ├──< budgets
   │       └── FK → categories
   │
   └──< transactions
           ├── FK → accounts (source_account)
           ├── FK → accounts (target_account, для переводов)
           ├── FK → categories
           ├── FK → users (created_by)
           └── FK → import_transactions (origin)

import_files ──< import_transactions
```

---

## 2.2 Сущность User

```php
// src/Entity/User.php
#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 100)]
    private string $name;

    // Telegram-интеграция
    #[ORM\Column(nullable: true)]
    private ?int $telegramChatId = null;

    #[ORM\Column(nullable: true)]
    private ?string $telegramUsername = null;

    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    private ?Family $family = null;
}
```

---

## 2.3 Сущность Family

```php
// src/Entity/Family.php
#[ORM\Entity]
#[ORM\Table(name: 'families')]
class Family
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';  // ISO 4217

    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'family', cascade: ['remove'])]
    private Collection $members;

    #[ORM\OneToMany(targetEntity: Account::class, mappedBy: 'family', cascade: ['remove'])]
    private Collection $accounts;
}
```

---

## 2.4 Сущность Account (счёт/копилка)

```php
// src/Entity/Account.php

enum AccountType: string
{
    case Checking  = 'checking';   // расчётный счёт
    case Savings   = 'savings';    // сберегательный / копилка
    case Cash      = 'cash';       // наличные
    case CreditCard = 'credit_card';
}

#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    ...
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;              // "Sparkasse", "Наличные", "Копилка на машину"

    #[ORM\Column(enumType: AccountType::class)]
    private AccountType $type;

    // Начальный баланс при заведении счёта
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $initialBalance = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    // IBAN для автоматического сопоставления при импорте
    #[ORM\Column(nullable: true, length: 34)]
    private ?string $iban = null;

    #[ORM\Column(default: true)]
    private bool $isActive = true;

    #[ORM\Column(default: true)]
    private bool $includeInTotal = true;  // включать в общий баланс

    #[ORM\ManyToOne(targetEntity: Family::class, inversedBy: 'accounts')]
    private Family $family;

    #[ORM\Column(type: 'smallint', default: 0)]
    private int $sortOrder = 0;

    // Счёт по умолчанию при быстром вводе
    #[ORM\Column(default: false)]
    private bool $isDefault = false;
}
```

---

## 2.5 Сущность Category (дерево категорий)

```php
// src/Entity/Category.php

enum CategoryType: string
{
    case Expense = 'expense';
    case Income  = 'income';
}

#[ORM\Entity]
#[ORM\Table(name: 'categories')]
class Category
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    ...
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(enumType: CategoryType::class)]
    private CategoryType $type;

    // Иконка/эмодзи для отображения в боте
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $icon = null;  // например "🛒"

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null; // hex "#FF5733"

    // Родительская категория (для подкатегорий)
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    private ?Category $parent = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent', cascade: ['remove'])]
    private Collection $children;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    private Family $family;

    #[ORM\Column(default: false)]
    private bool $isSystem = false;   // системные категории нельзя удалить

    #[ORM\Column(type: 'smallint', default: 0)]
    private int $sortOrder = 0;
}
```

---

## 2.6 Сущность Transaction (главная)

```php
// src/Entity/Transaction.php

enum TransactionType: string
{
    case Expense    = 'expense';
    case Income     = 'income';
    case Transfer   = 'transfer';   // перевод между своими счетами
    case Refund     = 'refund';     // возврат средств
    case Adjustment = 'adjustment'; // корректировка баланса
}

#[ORM\Entity]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(columns: ['executed_at'])]
#[ORM\Index(columns: ['family_id', 'executed_at'])]
class Transaction
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    ...
    private Uuid $id;

    #[ORM\Column(enumType: TransactionType::class)]
    private TransactionType $type;

    // Сумма всегда положительная, тип определяет направление
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    // Дата совершения операции (может отличаться от даты ввода)
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $executedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    // Для расходов: откуда списываем
    #[ORM\ManyToOne(targetEntity: Account::class)]
    private ?Account $sourceAccount = null;

    // Для переводов: куда зачисляем
    #[ORM\ManyToOne(targetEntity: Account::class)]
    private ?Account $targetAccount = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    private ?Category $category = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    private Family $family;

    // Ссылка на импортированную транзакцию (если создана из выписки)
    #[ORM\OneToOne(targetEntity: ImportTransaction::class)]
    private ?ImportTransaction $importOrigin = null;

    // Признак внутреннего перевода (не влияет на расходы/доходы в аналитике)
    #[ORM\Column(default: false)]
    private bool $isInternalTransfer = false;

    // Подтверждена ли транзакция пользователем (неподтверждённые не идут в аналитику)
    #[ORM\Column(default: true)]
    private bool $isConfirmed = true;

    // Теги (json, а не simple_array — чтобы поддерживать теги с запятой)
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tags = null;
}
```

---

## 2.7 Сущности для импорта выписок

```php
// src/Entity/ImportFile.php

enum ImportStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Done       = 'done';
    case Failed     = 'failed';
}

#[ORM\Entity]
#[ORM\Table(name: 'import_files')]
class ImportFile
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)] private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $originalFilename;

    #[ORM\Column(length: 255)]
    private string $storagePath;

    #[ORM\Column(length: 20)]
    private string $format;   // 'mt940', 'csv_sparkasse', 'csv_generic'

    #[ORM\Column(enumType: ImportStatus::class)]
    private ImportStatus $status = ImportStatus::Pending;

    #[ORM\Column(nullable: true, type: 'text')]
    private ?string $errorMessage = null;

    #[ORM\Column(default: 0)]
    private int $totalRows = 0;

    #[ORM\Column(default: 0)]
    private int $importedRows = 0;

    #[ORM\Column(default: 0)]
    private int $skippedRows = 0;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private User $uploadedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
}
```

```php
// src/Entity/ImportTransaction.php

enum ImportTransactionStatus: string
{
    case Pending   = 'pending';    // ожидает решения пользователя
    case Confirmed = 'confirmed';  // подтверждена → создана Transaction
    case Skipped   = 'skipped';    // пропущена (дубликат или ненужная)
    case AutoImported = 'auto_imported'; // автоматически импортирована
}

#[ORM\Entity]
#[ORM\Table(name: 'import_transactions')]
class ImportTransaction
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)] private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ImportFile::class)]
    private ImportFile $importFile;

    // Данные из файла (сырые)
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $rawDescription = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $counterpartyName = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $counterpartyIban = null;

    // Предложенная системой категория (может быть изменена)
    #[ORM\ManyToOne(targetEntity: Category::class)]
    private ?Category $suggestedCategory = null;

    // Уверенность автоматической категоризации (0.0 – 1.0)
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $confidenceScore = null;

    #[ORM\Column(enumType: ImportTransactionStatus::class)]
    private ImportTransactionStatus $status = ImportTransactionStatus::Pending;

    // Хэш для определения дубликатов
    #[ORM\Column(length: 64, unique: true)]
    private string $deduplicationHash;
}
```

---

## 2.8 Сущность SavingsGoal (копилка / накопительная цель)

```php
// src/Entity/SavingsGoal.php

enum SavingsGoalStatus: string
{
    case Active    = 'active';
    case Completed = 'completed';
    case Archived  = 'archived';
}

#[ORM\Entity]
#[ORM\Table(name: 'savings_goals')]
class SavingsGoal
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    private Family $family;

    // Счёт-копилка, куда идут переводы
    #[ORM\ManyToOne(targetEntity: Account::class)]
    private Account $account;

    #[ORM\Column(length: 100)]
    private string $name;              // "Отпуск 2025", "Подушка безопасности"

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    // Целевая сумма (null = без цели)
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $targetAmount = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $targetDate = null;

    #[ORM\Column(enumType: SavingsGoalStatus::class)]
    private SavingsGoalStatus $status = SavingsGoalStatus::Active;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
}
```

---

## 2.9 Сущность Recommendation (сохранённые рекомендации)

```php
// src/Entity/Recommendation.php
#[ORM\Entity]
#[ORM\Table(name: 'recommendations')]
class Recommendation
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    private Family $family;

    #[ORM\Column(length: 50)]
    private string $type;           // 'expense_spike', 'budget_exceeded', etc.

    #[ORM\Column(type: 'smallint')]
    private int $priority;          // 1=low, 2=medium, 3=high, 4=critical

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $message;

    // Период расчёта
    #[ORM\Column(length: 7)]
    private string $period;         // '2025-03' (год-месяц)

    #[ORM\Column(default: false)]
    private bool $seenByUser = false;

    #[ORM\Column]
    private \DateTimeImmutable $generatedAt;
}
```

---

## 2.10 Сущность Budget (бюджет по категориям)

```php
// src/Entity/Budget.php
#[ORM\Entity]
#[ORM\Table(name: 'budgets')]
#[ORM\UniqueConstraint(columns: ['family_id', 'category_id', 'year', 'month'])]
class Budget
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)] private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    private Family $family;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    private Category $category;

    #[ORM\Column(type: 'smallint')]
    private int $year;

    #[ORM\Column(type: 'smallint')]
    private int $month;  // 1-12

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $plannedAmount;
}
```

---

## 2.11 Сущность CategoryRule (правила автокатегоризации)

```php
// src/Entity/CategoryRule.php
#[ORM\Entity]
#[ORM\Table(name: 'category_rules')]
class CategoryRule
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)] private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    private Family $family;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    private Category $category;

    // Ключевые слова для поиска в описании транзакции (json, не simple_array — допускаем запятые)
    #[ORM\Column(type: 'json')]
    private array $keywords = [];

    // Опционально: IBAN контрагента
    #[ORM\Column(length: 34, nullable: true)]
    private ?string $counterpartyIban = null;

    #[ORM\Column(type: 'smallint', default: 0)]
    private int $priority = 0;  // чем больше, тем раньше применяется
}
```

---

## 2.12 Создание миграций

```bash
# Создать первую миграцию
php bin/console doctrine:migrations:diff

# Применить
php bin/console doctrine:migrations:migrate

# Проверить схему
php bin/console doctrine:schema:validate
```

---

## 2.13 Фикстуры для разработки

```php
// src/DataFixtures/AppFixtures.php
class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $family = new Family();
        $family->setName('Моя семья')->setCurrency('EUR');
        $manager->persist($family);

        $user = new User();
        $user->setEmail('admin@example.com')
             ->setName('Администратор')
             ->setFamily($family)
             ->setRoles(['ROLE_USER', 'ROLE_ADMIN'])
             ->setPassword($this->passwordHasher->hashPassword($user, 'password'));
        $manager->persist($user);

        // Счета
        $bankAccount = new Account();
        $bankAccount->setName('Sparkasse')
                    ->setType(AccountType::Checking)
                    ->setIban('DE12345678901234567890')
                    ->setFamily($family);
        $manager->persist($bankAccount);

        $cashAccount = new Account();
        $cashAccount->setName('Наличные')
                    ->setType(AccountType::Cash)
                    ->setFamily($family);
        $manager->persist($cashAccount);

        // Категории расходов
        $categories = [
            ['🛒 Продукты', 'expense'],
            ['🏠 Жильё', 'expense'],
            ['🚗 Транспорт', 'expense'],
            ['🍽️ Рестораны', 'expense'],
            ['💊 Здоровье', 'expense'],
            ['👕 Одежда', 'expense'],
            ['🎭 Развлечения', 'expense'],
            ['📚 Образование', 'expense'],
            ['💰 Зарплата', 'income'],
            ['🎁 Прочие доходы', 'income'],
        ];

        foreach ($categories as [$name, $type]) {
            $cat = new Category();
            $cat->setName($name)
                ->setType(CategoryType::from($type))
                ->setFamily($family)
                ->setIsSystem(true);
            $manager->persist($cat);
        }

        $manager->flush();
    }
}
```

---

## Результат шага 2

- Все Doctrine-сущности созданы и задокументированы
- Первая миграция сгенерирована и применена
- Фикстуры загружены: тестовый пользователь, счета, категории

## Следующий шаг

→ [03-core-features.md](03-core-features.md) — Основная бизнес-логика (транзакции, баланс, категоризация)
