<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Family;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

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

    /** @return Transaction[] */
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

    /** @return array<int, array{categoryId: string|null, total: string|null}> */
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
            ->andWhere('t.isConfirmed = true')
            ->andWhere('t.isInternalTransfer = false')
            ->setParameter('family', $family)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('type', TransactionType::Expense)
            ->groupBy('t.category')
            ->getQuery()
            ->getArrayResult();
    }

    /** @return Transaction[] */
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
     * Used in AnalyticsService to compute total income/expense for a period.
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
     * Returns category IDs that had regular transactions (at least N months out of the last X).
     * Used in SuggestionService to detect missing regular expenses.
     */
    /** @return array<int, string> */
    public function findRegularCategories(Family $family, int $months = 3): array
    {
        $from = new \DateTimeImmutable("-{$months} months");

        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.category) as categoryId, COUNT(DISTINCT YEAR(t.executedAt) * 100 + MONTH(t.executedAt)) as monthCount')
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
     * Checks whether there is at least one transaction for the given category in the current month.
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
     * Used in TransactionController for a filterable transaction list.
     *
     * @param array<string, mixed> $filters
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
