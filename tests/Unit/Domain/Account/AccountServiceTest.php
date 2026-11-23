<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Account;

use App\Domain\Account\AccountService;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Family;
use App\Repository\TransactionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;

#[AllowMockObjectsWithoutExpectations]
class AccountServiceTest extends TestCase
{
    private TransactionRepository&MockObject $txRepo;
    private CacheInterface&MockObject $cache;
    private AccountService $service;

    protected function setUp(): void
    {
        $this->txRepo = $this->createMock(TransactionRepository::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->service = new AccountService($this->txRepo, $this->cache);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Make the cache call through to the callback immediately (no caching). */
    private function cacheDelegates(): void
    {
        $this->cache
            ->method('get')
            ->willReturnCallback(static fn (string $key, callable $cb) => $cb());
    }

    private function makeAccount(
        float $initialBalance = 0.0,
        string $currency = 'EUR',
        bool $active = true,
        bool $includeInTotal = true,
    ): Account {
        $account = new Account();
        $account
            ->setName('Test Account')
            ->setType(AccountType::Checking)
            ->setCurrency($currency)
            ->setFamily(new Family());

        $account->setInitialBalance((string) $initialBalance);
        $this->setPrivateId($account, Uuid::v4());

        if (!$active) {
            $account->setIsActive(false);
        }
        if (!$includeInTotal) {
            $account->setIncludeInTotal(false);
        }

        return $account;
    }

    // ── getBalance ────────────────────────────────────────────────────────────

    public function testBalanceIsInitialWhenNoTransactions(): void
    {
        $this->cacheDelegates();
        $this->txRepo->method('sumInflow')->willReturn(0.0);
        $this->txRepo->method('sumOutflow')->willReturn(0.0);

        $account = $this->makeAccount(initialBalance: 500.0);
        $balance = $this->service->getBalance($account);

        self::assertEqualsWithDelta(500.0, $balance, 0.001);
    }

    public function testBalanceAddsInflow(): void
    {
        $this->cacheDelegates();
        $this->txRepo->method('sumInflow')->willReturn(300.0);
        $this->txRepo->method('sumOutflow')->willReturn(0.0);

        $account = $this->makeAccount(initialBalance: 100.0);
        $balance = $this->service->getBalance($account);

        self::assertEqualsWithDelta(400.0, $balance, 0.001);
    }

    public function testBalanceSubtractsOutflow(): void
    {
        $this->cacheDelegates();
        $this->txRepo->method('sumInflow')->willReturn(0.0);
        $this->txRepo->method('sumOutflow')->willReturn(80.0);

        $account = $this->makeAccount(initialBalance: 200.0);
        $balance = $this->service->getBalance($account);

        self::assertEqualsWithDelta(120.0, $balance, 0.001);
    }

    public function testBalanceCombinesAllComponents(): void
    {
        $this->cacheDelegates();
        $this->txRepo->method('sumInflow')->willReturn(1000.0);
        $this->txRepo->method('sumOutflow')->willReturn(350.0);

        $account = $this->makeAccount(initialBalance: 250.0);
        $balance = $this->service->getBalance($account);

        // 250 + 1000 - 350 = 900
        self::assertEqualsWithDelta(900.0, $balance, 0.001);
    }

    public function testBalanceCanBeNegative(): void
    {
        $this->cacheDelegates();
        $this->txRepo->method('sumInflow')->willReturn(0.0);
        $this->txRepo->method('sumOutflow')->willReturn(500.0);

        $account = $this->makeAccount(initialBalance: 100.0);
        $balance = $this->service->getBalance($account);

        self::assertEqualsWithDelta(-400.0, $balance, 0.001);
    }

    public function testBalanceWithZeroInitial(): void
    {
        $this->cacheDelegates();
        $this->txRepo->method('sumInflow')->willReturn(200.0);
        $this->txRepo->method('sumOutflow')->willReturn(200.0);

        $account = $this->makeAccount(initialBalance: 0.0);
        $balance = $this->service->getBalance($account);

        self::assertEqualsWithDelta(0.0, $balance, 0.001);
    }

    public function testGetBalanceUsesCacheKey(): void
    {
        $account = $this->makeAccount(initialBalance: 100.0);
        $id = Uuid::v4();
        $this->setPrivateId($account, $id);

        $expectedKey = 'account_balance_'.$id->toRfc4122();

        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(self::stringContains((string) $id))
            ->willReturnCallback(static fn (string $key, callable $cb) => $cb());

        $this->txRepo->method('sumInflow')->willReturn(0.0);
        $this->txRepo->method('sumOutflow')->willReturn(0.0);

        $this->service->getBalance($account);
    }

    // ── invalidateCache ───────────────────────────────────────────────────────

    public function testInvalidateCacheDeletesAccountKey(): void
    {
        $account = $this->makeAccount();
        $id = Uuid::v4();
        $this->setPrivateId($account, $id);

        $this->cache
            ->expects(self::once())
            ->method('delete')
            ->with(self::stringContains((string) $id));

        $this->service->invalidateCache($account);
    }

    // ── getTotalBalance ───────────────────────────────────────────────────────

    public function testTotalBalanceAggregatesSingleCurrency(): void
    {
        $this->cacheDelegates();
        $this->txRepo->method('sumInflow')->willReturn(0.0);
        $this->txRepo->method('sumOutflow')->willReturn(0.0);

        $family = $this->makeFamilyWithAccounts([
            $this->makeAccount(initialBalance: 100.0, currency: 'EUR'),
            $this->makeAccount(initialBalance: 200.0, currency: 'EUR'),
        ]);

        $totals = $this->service->getTotalBalance($family);

        self::assertArrayHasKey('EUR', $totals);
        self::assertEqualsWithDelta(300.0, $totals['EUR'], 0.001);
    }

    public function testTotalBalanceAggregatesMultipleCurrencies(): void
    {
        $this->cacheDelegates();
        $this->txRepo->method('sumInflow')->willReturn(0.0);
        $this->txRepo->method('sumOutflow')->willReturn(0.0);

        $family = $this->makeFamilyWithAccounts([
            $this->makeAccount(initialBalance: 500.0, currency: 'EUR'),
            $this->makeAccount(initialBalance: 200.0, currency: 'USD'),
        ]);

        $totals = $this->service->getTotalBalance($family);

        self::assertEqualsWithDelta(500.0, $totals['EUR'], 0.001);
        self::assertEqualsWithDelta(200.0, $totals['USD'], 0.001);
    }

    public function testTotalBalanceExcludesInactiveAccounts(): void
    {
        $this->cacheDelegates();
        $this->txRepo->method('sumInflow')->willReturn(0.0);
        $this->txRepo->method('sumOutflow')->willReturn(0.0);

        $family = $this->makeFamilyWithAccounts([
            $this->makeAccount(initialBalance: 100.0, active: true),
            $this->makeAccount(initialBalance: 999.0, active: false),
        ]);

        $totals = $this->service->getTotalBalance($family);

        self::assertEqualsWithDelta(100.0, $totals['EUR'], 0.001);
    }

    public function testTotalBalanceExcludesAccountsNotInTotal(): void
    {
        $this->cacheDelegates();
        $this->txRepo->method('sumInflow')->willReturn(0.0);
        $this->txRepo->method('sumOutflow')->willReturn(0.0);

        $family = $this->makeFamilyWithAccounts([
            $this->makeAccount(initialBalance: 100.0, includeInTotal: true),
            $this->makeAccount(initialBalance: 999.0, includeInTotal: false),
        ]);

        $totals = $this->service->getTotalBalance($family);

        self::assertEqualsWithDelta(100.0, $totals['EUR'], 0.001);
    }

    public function testTotalBalanceEmptyWhenNoActiveAccounts(): void
    {
        $family = $this->makeFamilyWithAccounts([
            $this->makeAccount(active: false),
        ]);

        $totals = $this->service->getTotalBalance($family);

        self::assertSame([], $totals);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @param Account[] $accounts */
    private function makeFamilyWithAccounts(array $accounts): Family
    {
        $family = $this->createStub(Family::class);
        $family->method('getAccounts')->willReturn(new ArrayCollection($accounts));

        return $family;
    }

    private function setPrivateId(object $obj, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, 'id');
        $ref->setValue($obj, $value);
    }
}
