<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Entity\Account;
use App\Entity\Family;
use App\Repository\TransactionRepository;
use Symfony\Contracts\Cache\CacheInterface;

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

    /** @return array<string, float> */
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

        // Sum up: inflows (income, incoming transfers)
        $inflow = $this->transactionRepo->sumInflow($account);
        // Subtract: expenses and outgoing transfers
        $outflow = $this->transactionRepo->sumOutflow($account);

        return $initial + $inflow - $outflow;
    }

    public function invalidateCache(Account $account): void
    {
        $this->cache->delete('account_balance_' . $account->getId());
    }
}
