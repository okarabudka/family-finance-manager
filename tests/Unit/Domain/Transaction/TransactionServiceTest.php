<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Transaction;

use App\Domain\Account\AccountService;
use App\Domain\Transaction\TransactionService;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Family;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;

class TransactionServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AccountService&MockObject $accountService;
    private CacheInterface&MockObject $cache;
    private TransactionService $service;

    private Family $family;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->accountService = $this->createMock(AccountService::class);
        $this->cache          = $this->createMock(CacheInterface::class);

        $this->service = new TransactionService($this->em, $this->accountService, $this->cache);

        $this->family = new Family();
        $this->family->setName('Test Family')->setCurrency('EUR');
        // Set UUID so getId() doesn't fail before DB persist
        $this->setPrivateProperty($this->family, 'id', Uuid::v4());

        $this->user = new User();
        $this->user->setName('Test User')->setEmail('test@test.com')->setPassword('')->setFamily($this->family);

        $this->account = new Account();
        $this->account->setName('Main')->setType(AccountType::Checking)->setFamily($this->family)->setCurrency('EUR');
    }

    public function testAddExpenseCreatesTransactionWithCorrectType(): void
    {
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Transaction::class));
        $this->em->expects(self::once())->method('flush');
        $this->accountService->expects(self::once())->method('invalidateCache')->with($this->account);
        $this->cache->expects(self::atLeastOnce())->method('delete');

        $transaction = $this->service->addExpense(
            family: $this->family,
            user: $this->user,
            amount: '50.00',
            category: null,
            account: $this->account,
            description: 'Groceries',
        );

        self::assertSame(TransactionType::Expense, $transaction->getType());
        self::assertSame('50.00', $transaction->getAmount());
        self::assertSame('Groceries', $transaction->getDescription());
        self::assertSame($this->account, $transaction->getSourceAccount());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAddExpenseUsesAbsoluteAmount(): void
    {
        $this->em->method('persist');
        $this->em->method('flush');
        $this->accountService->method('invalidateCache');
        $this->cache->method('delete');

        // Negative amount should be stored as positive
        $transaction = $this->service->addExpense(
            family: $this->family,
            user: $this->user,
            amount: '-75.50',
            category: null,
            account: $this->account,
        );

        self::assertSame('75.50', $transaction->getAmount());
    }

    public function testAddIncomeCreatesTransactionWithCorrectType(): void
    {
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');
        $this->accountService->expects(self::once())->method('invalidateCache')->with($this->account);
        $this->cache->expects(self::atLeastOnce())->method('delete');

        $transaction = $this->service->addIncome(
            family: $this->family,
            user: $this->user,
            amount: '2500.00',
            category: null,
            account: $this->account,
            description: 'Salary',
        );

        self::assertSame(TransactionType::Income, $transaction->getType());
        self::assertSame('2500.00', $transaction->getAmount());
        self::assertSame($this->account, $transaction->getTargetAccount());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAddTransferInvalidatesBothAccounts(): void
    {
        $targetAccount = new Account();
        $targetAccount->setName('Savings')->setType(AccountType::Savings)->setFamily($this->family)->setCurrency('EUR');

        $this->em->method('persist');
        $this->em->method('flush');
        $this->cache->method('delete');

        $invalidatedAccounts = [];
        $this->accountService->method('invalidateCache')
            ->willReturnCallback(function (Account $a) use (&$invalidatedAccounts): void {
                $invalidatedAccounts[] = $a->getName();
            });

        $this->service->addTransfer(
            family: $this->family,
            user: $this->user,
            amount: '100.00',
            from: $this->account,
            to: $targetAccount,
        );

        self::assertContains('Main', $invalidatedAccounts);
        self::assertContains('Savings', $invalidatedAccounts);
    }

    public function testDeleteInvalidatesCacheAfterRemoval(): void
    {
        $transaction = new Transaction();
        $transaction
            ->setType(TransactionType::Expense)
            ->setAmount('50.00')
            ->setCurrency('EUR')
            ->setSourceAccount($this->account)
            ->setFamily($this->family)
            ->setCreatedBy($this->user)
            ->setExecutedAt(new \DateTimeImmutable('2025-03-01'));

        $this->em->expects(self::once())->method('remove')->with($transaction);
        $this->em->expects(self::once())->method('flush');
        $this->accountService->expects(self::once())->method('invalidateCache')->with($this->account);
        $this->cache->expects(self::atLeastOnce())->method('delete');

        $this->service->delete($transaction);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpdateInvalidatesOldAndNewMonthWhenDateChanges(): void
    {
        $transaction = new Transaction();
        $transaction
            ->setType(TransactionType::Expense)
            ->setAmount('50.00')
            ->setCurrency('EUR')
            ->setSourceAccount($this->account)
            ->setFamily($this->family)
            ->setCreatedBy($this->user)
            ->setExecutedAt(new \DateTimeImmutable('2025-01-15'));

        $this->em->method('flush');
        $this->accountService->method('invalidateCache');

        $deletedKeys = [];
        $this->cache->method('delete')
            ->willReturnCallback(function (string $key) use (&$deletedKeys): bool {
                $deletedKeys[] = $key;
                return true;
            });

        $this->service->update($transaction, [
            'executedAt' => new \DateTimeImmutable('2025-03-15'),
        ]);

        // Both old month (2025-01) and new month (2025-03) should be invalidated
        $keysStr = implode(',', $deletedKeys);
        self::assertStringContainsString('2025-01', $keysStr);
        self::assertStringContainsString('2025-03', $keysStr);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpdateDoesNotInvalidateNewMonthWhenDateUnchanged(): void
    {
        $transaction = new Transaction();
        $transaction
            ->setType(TransactionType::Expense)
            ->setAmount('50.00')
            ->setCurrency('EUR')
            ->setSourceAccount($this->account)
            ->setFamily($this->family)
            ->setCreatedBy($this->user)
            ->setExecutedAt(new \DateTimeImmutable('2025-03-15'));

        $this->em->method('flush');
        $this->accountService->method('invalidateCache');

        $deletedKeys = [];
        $this->cache->method('delete')
            ->willReturnCallback(function (string $key) use (&$deletedKeys): bool {
                $deletedKeys[] = $key;
                return true;
            });

        // Update only description, not date
        $this->service->update($transaction, ['description' => 'Updated description']);

        // Only monthly_report_ keys contain the date — trend keys don't
        $reportKeys = array_filter($deletedKeys, fn(string $k) => str_starts_with($k, 'monthly_report_'));
        self::assertCount(1, $reportKeys); // exactly one month invalidated
        foreach ($reportKeys as $key) {
            self::assertStringContainsString('2025-03', $key);
        }
    }

    private function setPrivateProperty(object $obj, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $property);
        $ref->setValue($obj, $value);
    }
}
