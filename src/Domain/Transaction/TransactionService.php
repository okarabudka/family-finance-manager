<?php

declare(strict_types=1);

namespace App\Domain\Transaction;

use App\Domain\Account\AccountService;
use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Family;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class TransactionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountService $accountService,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Invalidates monthly report cache for a family for the given month.
     * Called after any transaction change.
     */
    private function invalidateAnalyticsCache(Family $family, \DateTimeImmutable $date): void
    {
        $this->cache->delete("monthly_report_{$family->getId()}_{$date->format('Y-m')}");
        $this->cache->delete("monthly_trend_{$family->getId()}_6");
    }

    public function addExpense(
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
            ->setType(TransactionType::Expense)
            ->setAmount(self::normalizeAmount($amount))
            ->setCurrency($account->getCurrency())
            ->setDescription($description)
            ->setSourceAccount($account)
            ->setCategory($category)
            ->setFamily($family)
            ->setCreatedBy($user)
            ->setExecutedAt($date ?? new \DateTimeImmutable());

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
            ->setAmount(self::normalizeAmount($amount))
            ->setCurrency($account->getCurrency())
            ->setDescription($description)
            ->setTargetAccount($account)
            ->setCategory($category)
            ->setFamily($family)
            ->setCreatedBy($user)
            ->setExecutedAt($date ?? new \DateTimeImmutable());

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
            ->setAmount(self::normalizeAmount($amount))
            ->setCurrency($from->getCurrency())
            ->setDescription($description)
            ->setSourceAccount($from)
            ->setTargetAccount($to)
            ->setFamily($family)
            ->setCreatedBy($user)
            ->setExecutedAt($date ?? new \DateTimeImmutable());

        $this->em->persist($transaction);
        $this->em->flush();

        $this->accountService->invalidateCache($from);
        $this->accountService->invalidateCache($to);
        $this->invalidateAnalyticsCache($family, $transaction->getExecutedAt());

        return $transaction;
    }

    /** @param array{amount?: string, description?: string|null, category?: Category|null, executedAt?: \DateTimeImmutable} $data */
    public function update(Transaction $transaction, array $data): Transaction
    {
        // Capture old date BEFORE updating — needed to clear old month's cache
        $oldDate = $transaction->getExecutedAt();

        if (isset($data['amount'])) {
            $transaction->setAmount(self::normalizeAmount($data['amount']));
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

        $family = $transaction->getFamily();

        if ($transaction->getSourceAccount()) {
            $this->accountService->invalidateCache($transaction->getSourceAccount());
        }
        if ($transaction->getTargetAccount()) {
            $this->accountService->invalidateCache($transaction->getTargetAccount());
        }

        // Invalidate both old and new month in case the date changed
        $this->invalidateAnalyticsCache($family, $oldDate);
        $newDate = $transaction->getExecutedAt();
        if ($oldDate->format('Y-m') !== $newDate->format('Y-m')) {
            $this->invalidateAnalyticsCache($family, $newDate);
        }

        return $transaction;
    }

    public function delete(Transaction $transaction): void
    {
        $family = $transaction->getFamily();
        $date = $transaction->getExecutedAt();

        if ($transaction->getSourceAccount()) {
            $this->accountService->invalidateCache($transaction->getSourceAccount());
        }
        if ($transaction->getTargetAccount()) {
            $this->accountService->invalidateCache($transaction->getTargetAccount());
        }

        $this->em->remove($transaction);
        $this->em->flush();

        $this->invalidateAnalyticsCache($family, $date);
    }

    /**
     * Matches the decimal(15, 2) column, so the entity holds the same value as the database.
     */
    private static function normalizeAmount(string $amount): string
    {
        return number_format(abs((float) $amount), 2, '.', '');
    }
}
