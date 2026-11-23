<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\CategoryType;
use App\Entity\Family;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the repository queries against PostgreSQL; DAMADoctrineTestBundle rolls back after each test.
 */
final class TransactionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TransactionRepository $repository;
    private Family $family;
    private User $user;
    private Account $checking;
    private Account $savings;
    private Category $groceries;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(TransactionRepository::class);

        $this->family = (new Family())->setName('Integration family')->setCurrency('EUR');
        $this->user = (new User())->setEmail('integration@example.com')->setName('Integration')->setPassword('x')->setFamily($this->family);
        $this->checking = $this->account('Checking', AccountType::Checking);
        $this->savings = $this->account('Savings', AccountType::Savings);
        $this->groceries = (new Category())->setName('Groceries')->setType(CategoryType::Expense)->setFamily($this->family);

        foreach ([$this->family, $this->user, $this->checking, $this->savings, $this->groceries] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testAccountFlowsCountIncomeExpenseAndTransfers(): void
    {
        $this->transaction(TransactionType::Income, '1000.00', target: $this->checking);
        $this->transaction(TransactionType::Expense, '120.50', source: $this->checking, category: $this->groceries);
        $this->transaction(TransactionType::Transfer, '300.00', source: $this->checking, target: $this->savings);
        $this->em->flush();

        self::assertEqualsWithDelta(1000.0, $this->repository->sumInflow($this->checking), 0.001);
        self::assertEqualsWithDelta(420.5, $this->repository->sumOutflow($this->checking), 0.001);
        self::assertEqualsWithDelta(300.0, $this->repository->sumInflow($this->savings), 0.001);
    }

    public function testSumByCategoryIgnoresOtherPeriodsAndInternalTransfers(): void
    {
        $this->transaction(TransactionType::Expense, '20.00', source: $this->checking, category: $this->groceries, date: '2026-03-05');
        $this->transaction(TransactionType::Expense, '30.00', source: $this->checking, category: $this->groceries, date: '2026-03-20');
        $this->transaction(TransactionType::Expense, '99.00', source: $this->checking, category: $this->groceries, date: '2026-04-01');
        $this->transaction(TransactionType::Expense, '50.00', source: $this->checking, date: '2026-03-10')->setIsInternalTransfer(true);
        $this->em->flush();

        $rows = $this->repository->sumByCategory($this->family, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'));

        self::assertCount(1, $rows);
        self::assertSame($this->groceries->getId()->toRfc4122(), $rows[0]['categoryId']);
        self::assertEqualsWithDelta(50.0, (float) $rows[0]['total'], 0.001);
    }

    public function testSumByTypeOnlyCountsConfirmedTransactions(): void
    {
        $this->transaction(TransactionType::Income, '500.00', target: $this->checking, date: '2026-03-01');
        $this->transaction(TransactionType::Income, '70.00', target: $this->checking, date: '2026-03-02')->setIsConfirmed(false);
        $this->em->flush();

        $income = $this->repository->sumByType($this->family, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'), TransactionType::Income);

        self::assertEqualsWithDelta(500.0, $income, 0.001);
    }

    private function account(string $name, AccountType $type): Account
    {
        return (new Account())->setName($name)->setType($type)->setCurrency('EUR')->setInitialBalance('0.00')->setFamily($this->family);
    }

    private function transaction(
        TransactionType $type,
        string $amount,
        ?Account $source = null,
        ?Account $target = null,
        ?Category $category = null,
        string $date = 'today',
    ): Transaction {
        $transaction = (new Transaction())
            ->setType($type)
            ->setAmount($amount)
            ->setCurrency('EUR')
            ->setExecutedAt(new \DateTimeImmutable($date))
            ->setSourceAccount($source)
            ->setTargetAccount($target)
            ->setCategory($category)
            ->setCreatedBy($this->user)
            ->setFamily($this->family);
        $this->em->persist($transaction);

        return $transaction;
    }
}
