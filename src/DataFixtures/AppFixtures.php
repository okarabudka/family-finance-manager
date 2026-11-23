<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\CategoryRule;
use App\Entity\CategoryType;
use App\Entity\Family;
use App\Entity\ImportFile;
use App\Entity\ImportStatus;
use App\Entity\ImportTransaction;
use App\Entity\ImportTransactionStatus;
use App\Entity\MeterReading;
use App\Entity\MeterType;
use App\Entity\SavingsGoal;
use App\Entity\SavingsGoalStatus;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo data set: one family with six months of realistic but fictitious finances.
 *
 * Amounts are pseudo-random with a fixed seed, so every load produces the same numbers
 * relative to the current date and the dashboard charts are always populated.
 */
final class AppFixtures extends Fixture
{
    public const string DEMO_EMAIL = 'demo@example.com';
    public const string DEMO_PASSWORD = 'demo-password';
    public const int DEMO_TELEGRAM_CHAT_ID = 100200300;

    private const int MONTHS = 6;
    private const int SEED = 20260101;

    /** name => [type, icon, color, keywords for auto-categorisation] */
    private const array CATEGORIES = [
        'Groceries' => [CategoryType::Expense, '🛒', '#22C55E', ['rewe', 'aldi', 'lidl', 'edeka', 'groceries']],
        'Housing' => [CategoryType::Expense, '🏠', '#6366F1', ['rent', 'miete']],
        'Utilities' => [CategoryType::Expense, '💡', '#F59E0B', ['stadtwerke', 'electricity', 'water']],
        'Transport' => [CategoryType::Expense, '🚗', '#3B82F6', ['db ', 'fuel', 'aral', 'shell']],
        'Restaurants' => [CategoryType::Expense, '🍽️', '#EF4444', ['restaurant', 'pizza', 'cafe', 'coffee']],
        'Health' => [CategoryType::Expense, '💊', '#14B8A6', ['apotheke', 'pharmacy']],
        'Clothing' => [CategoryType::Expense, '👕', '#EC4899', ['zalando', 'h&m']],
        'Entertainment' => [CategoryType::Expense, '🎭', '#8B5CF6', ['netflix', 'spotify', 'cinema']],
        'Kids' => [CategoryType::Expense, '🧸', '#F97316', ['kita', 'school']],
        'Salary' => [CategoryType::Income, '💰', '#16A34A', ['salary', 'gehalt']],
        'Other income' => [CategoryType::Income, '🎁', '#0EA5E9', []],
    ];

    /** category => [description, min, max, times per month] */
    private const array MONTHLY_EXPENSES = [
        'Groceries' => [['REWE Markt', 'ALDI Sued', 'Lidl', 'EDEKA'], 25, 95, 8],
        'Transport' => [['Aral fuel station', 'DB ticket'], 20, 70, 3],
        'Restaurants' => [['Pizzeria Napoli', 'Cafe am Markt', 'Sushi Bar'], 12, 60, 3],
        'Entertainment' => [['Cinema', 'Concert tickets'], 15, 45, 1],
        'Health' => [['Apotheke'], 8, 40, 1],
        'Clothing' => [['Zalando', 'H&M'], 30, 120, 1],
    ];

    /** @var array<string, Category> */
    private array $categories = [];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        mt_srand(self::SEED);
        $today = new \DateTimeImmutable('today');

        $family = (new Family())->setName('The Demo Family')->setCurrency('EUR');
        $manager->persist($family);

        $demo = $this->createUser($family, self::DEMO_EMAIL, 'Alex Demo', ['ROLE_USER', 'ROLE_ADMIN'], self::DEMO_PASSWORD)
            ->setTelegramChatId(self::DEMO_TELEGRAM_CHAT_ID)
            ->setTelegramUsername('demo_user');
        $partner = $this->createUser($family, 'partner@example.com', 'Sam Demo', ['ROLE_USER'], self::DEMO_PASSWORD);
        $manager->persist($demo);
        $manager->persist($partner);

        $checking = $this->createAccount($family, 'Demo Bank checking', AccountType::Checking, '1850.00', 'DE89370400440532013000')
            ->setIsDefault(true);
        $cash = $this->createAccount($family, 'Wallet', AccountType::Cash, '120.00');
        $savings = $this->createAccount($family, 'Savings', AccountType::Savings, '4200.00', 'DE02120300000000202051');
        $card = $this->createAccount($family, 'Credit card', AccountType::CreditCard, '0.00');
        foreach ([$checking, $cash, $savings, $card] as $sortOrder => $account) {
            $manager->persist($account->setSortOrder($sortOrder));
        }

        foreach (self::CATEGORIES as $name => [$type, $icon, $color, $keywords]) {
            $category = (new Category())
                ->setName($name)
                ->setType($type)
                ->setIcon($icon)
                ->setColor($color)
                ->setFamily($family)
                ->setIsSystem(true);
            $manager->persist($category);
            $this->categories[$name] = $category;

            if ($keywords !== []) {
                $manager->persist((new CategoryRule())
                    ->setFamily($family)
                    ->setCategory($category)
                    ->setKeywords($keywords));
            }
        }

        for ($offset = self::MONTHS - 1; $offset >= 0; --$offset) {
            $monthStart = $today->modify('first day of this month')->modify(\sprintf('-%d months', $offset));
            $lastDay = 0 === $offset ? (int) $today->format('j') : (int) $monthStart->format('t');
            $this->loadMonth($manager, $family, [$demo, $partner], $monthStart, $lastDay, $checking, $cash, $savings, $card);
        }

        $this->loadBudgets($manager, $family, $today);
        $this->loadSavingsGoals($manager, $family, $savings, $today);
        $this->loadMeterReadings($manager, $family, $demo, $today);
        $this->loadPendingImport($manager, $checking, $demo, $today);

        $manager->flush();
    }

    /**
     * @param list<User> $members
     */
    private function loadMonth(
        ObjectManager $manager,
        Family $family,
        array $members,
        \DateTimeImmutable $monthStart,
        int $lastDay,
        Account $checking,
        Account $cash,
        Account $savings,
        Account $card,
    ): void {
        $day = static fn (int $d): \DateTimeImmutable => $monthStart->modify(\sprintf('+%d days', min($d, $lastDay) - 1));
        $member = static fn (): User => $members[mt_rand(0, \count($members) - 1)];

        if ($lastDay >= 1) {
            $this->persistTransaction($manager, $family, $members[0], TransactionType::Income, '3450.00', 'Salary Example GmbH', $day(1), 'Salary', target: $checking);
            $this->persistTransaction($manager, $family, $members[0], TransactionType::Expense, '1150.00', 'Rent', $day(1), 'Housing', source: $checking);
        }
        if ($lastDay >= 3) {
            $this->persistTransaction($manager, $family, $members[1], TransactionType::Income, '1280.00', 'Salary Sample AG', $day(3), 'Salary', target: $checking);
            $this->persistTransaction($manager, $family, $members[1], TransactionType::Expense, '230.00', 'Kita fee', $day(3), 'Kids', source: $checking);
        }
        if ($lastDay >= 5) {
            $this->persistTransaction($manager, $family, $members[0], TransactionType::Expense, $this->amount(140, 175), 'Stadtwerke electricity and water', $day(5), 'Utilities', source: $checking);
            $this->persistTransaction($manager, $family, $members[0], TransactionType::Transfer, '400.00', 'Monthly savings', $day(5), null, source: $checking, target: $savings);
        }
        if ($lastDay >= 15) {
            $this->persistTransaction($manager, $family, $members[0], TransactionType::Expense, '17.99', 'Netflix', $day(15), 'Entertainment', source: $card);
            $this->persistTransaction($manager, $family, $members[1], TransactionType::Transfer, '200.00', 'Cash withdrawal', $day(15), null, source: $checking, target: $cash);
        }
        if ($lastDay >= 25 && 0 === mt_rand(0, 2)) {
            $this->persistTransaction($manager, $family, $members[1], TransactionType::Income, $this->amount(40, 180), 'Marketplace sale', $day(25), 'Other income', target: $checking);
        }

        foreach (self::MONTHLY_EXPENSES as $category => [$descriptions, $min, $max, $times]) {
            for ($i = 0; $i < $times; ++$i) {
                $source = match (mt_rand(0, 4)) {
                    0 => $cash,
                    1 => $card,
                    default => $checking,
                };
                $this->persistTransaction(
                    $manager,
                    $family,
                    $member(),
                    TransactionType::Expense,
                    $this->amount($min, $max),
                    $descriptions[mt_rand(0, \count($descriptions) - 1)],
                    $day(mt_rand(1, 28)),
                    $category,
                    source: $source,
                );
            }
        }
    }

    private function loadBudgets(ObjectManager $manager, Family $family, \DateTimeImmutable $today): void
    {
        $planned = ['Groceries' => '550.00', 'Restaurants' => '120.00', 'Transport' => '150.00', 'Entertainment' => '60.00', 'Clothing' => '80.00', 'Utilities' => '170.00'];

        foreach ([$today->modify('first day of last month'), $today] as $month) {
            foreach ($planned as $category => $amount) {
                $manager->persist((new Budget())
                    ->setFamily($family)
                    ->setCategory($this->categories[$category])
                    ->setYear((int) $month->format('Y'))
                    ->setMonth((int) $month->format('n'))
                    ->setPlannedAmount($amount));
            }
        }
    }

    private function loadSavingsGoals(ObjectManager $manager, Family $family, Account $savings, \DateTimeImmutable $today): void
    {
        $goals = [
            ['Summer vacation', 'Two weeks at the Baltic Sea', '3000.00', $today->modify('+7 months'), SavingsGoalStatus::Active],
            ['Emergency fund', 'Three months of fixed costs', '9000.00', $today->modify('+2 years'), SavingsGoalStatus::Active],
            ['New bicycle', null, '900.00', $today->modify('-1 month'), SavingsGoalStatus::Completed],
        ];

        foreach ($goals as [$name, $description, $target, $date, $status]) {
            $manager->persist((new SavingsGoal())
                ->setFamily($family)
                ->setAccount($savings)
                ->setName($name)
                ->setDescription($description)
                ->setTargetAmount($target)
                ->setTargetDate($date)
                ->setStatus($status));
        }
    }

    private function loadMeterReadings(ObjectManager $manager, Family $family, User $user, \DateTimeImmutable $today): void
    {
        $electricity = 18240.0;
        $water = 431.0;

        for ($offset = 8; $offset >= 0; --$offset) {
            $date = $today->modify('first day of this month')->modify(\sprintf('-%d months', $offset));
            $winter = \in_array((int) $date->format('n'), [11, 12, 1, 2, 3], true);
            $electricity += mt_rand($winter ? 260 : 190, $winter ? 330 : 240);
            $water += mt_rand(8, 13);

            $manager->persist((new MeterReading())->setFamily($family)->setCreatedBy($user)
                ->setType(MeterType::Electricity)->setValue($electricity)->setReadingDate($date));
            $manager->persist((new MeterReading())->setFamily($family)->setCreatedBy($user)
                ->setType(MeterType::Water)->setValue($water)->setReadingDate($date));
        }
    }

    private function loadPendingImport(ObjectManager $manager, Account $checking, User $user, \DateTimeImmutable $today): void
    {
        $file = (new ImportFile())
            ->setOriginalFilename('demo-statement.csv')
            ->setStoragePath('var/uploads/demo-statement.csv')
            ->setFormat('csv_sparkasse')
            ->setStatus(ImportStatus::Done)
            ->setAccount($checking)
            ->setUploadedBy($user)
            ->setTotalRows(5)
            ->setImportedRows(2)
            ->setSkippedRows(0);
        $manager->persist($file);

        $rows = [
            ['-42.80', 'REWE Markt sagt Danke', 'REWE Markt GmbH', 'Groceries', 0.92, ImportTransactionStatus::AutoImported],
            ['-9.99', 'Spotify family plan', 'Spotify AB', 'Entertainment', 0.88, ImportTransactionStatus::AutoImported],
            ['-64.15', 'Online order 4711', 'Example Shop', null, null, ImportTransactionStatus::Pending],
            ['-23.40', 'Card payment Baeckerei', 'Baeckerei Beispiel', null, null, ImportTransactionStatus::Pending],
            ['150.00', 'Refund insurance', 'Demo Versicherung AG', null, null, ImportTransactionStatus::Pending],
        ];

        foreach ($rows as $i => [$amount, $description, $counterparty, $category, $confidence, $status]) {
            $manager->persist((new ImportTransaction())
                ->setImportFile($file)
                ->setAmount($amount)
                ->setDate($today->modify(\sprintf('-%d days', $i + 1)))
                ->setRawDescription($description)
                ->setCounterpartyName($counterparty)
                ->setSuggestedCategory(null !== $category ? $this->categories[$category] : null)
                ->setConfidenceScore($confidence)
                ->setStatus($status)
                ->setDeduplicationHash(hash('sha256', 'demo-import-'.$i)));
        }
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(Family $family, string $email, string $name, array $roles, string $password): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setName($name)
            ->setFamily($family)
            ->setRoles($roles);

        return $user->setPassword($this->passwordHasher->hashPassword($user, $password));
    }

    private function createAccount(Family $family, string $name, AccountType $type, string $initialBalance, ?string $iban = null): Account
    {
        return (new Account())
            ->setName($name)
            ->setType($type)
            ->setCurrency('EUR')
            ->setInitialBalance($initialBalance)
            ->setIban($iban)
            ->setFamily($family);
    }

    private function persistTransaction(
        ObjectManager $manager,
        Family $family,
        User $user,
        TransactionType $type,
        string $amount,
        string $description,
        \DateTimeImmutable $date,
        ?string $category,
        ?Account $source = null,
        ?Account $target = null,
    ): void {
        $manager->persist((new Transaction())
            ->setType($type)
            ->setAmount($amount)
            ->setCurrency('EUR')
            ->setDescription($description)
            ->setExecutedAt($date)
            ->setSourceAccount($source)
            ->setTargetAccount($target)
            ->setCategory(null !== $category ? $this->categories[$category] : null)
            ->setCreatedBy($user)
            ->setFamily($family)
            ->setIsInternalTransfer(TransactionType::Transfer === $type));
    }

    private function amount(int $min, int $max): string
    {
        return \sprintf('%d.%02d', mt_rand($min, $max - 1), mt_rand(0, 99));
    }
}
