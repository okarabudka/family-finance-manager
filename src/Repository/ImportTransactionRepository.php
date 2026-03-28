<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Family;
use App\Entity\ImportFile;
use App\Entity\ImportTransaction;
use App\Entity\ImportTransactionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportTransaction>
 */
class ImportTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportTransaction::class);
    }

    public function hashExists(string $hash): bool
    {
        $count = $this->createQueryBuilder('it')
            ->select('COUNT(it.id)')
            ->where('it.deduplicationHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function findFirstPending(ImportFile $importFile): ?ImportTransaction
    {
        return $this->createQueryBuilder('it')
            ->where('it.importFile = :importFile')
            ->andWhere('it.status = :status')
            ->setParameter('importFile', $importFile)
            ->setParameter('status', ImportTransactionStatus::Pending)
            ->orderBy('it.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countPending(ImportFile $importFile): int
    {
        return (int) $this->createQueryBuilder('it')
            ->select('COUNT(it.id)')
            ->where('it.importFile = :importFile')
            ->andWhere('it.status = :status')
            ->setParameter('importFile', $importFile)
            ->setParameter('status', ImportTransactionStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns the first pending ImportTransaction for any ImportFile belonging to the given family.
     * Joins ImportFile -> Account -> Family to scope by family.
     */
    public function findFirstPendingForFamily(Family $family): ?ImportTransaction
    {
        return $this->createQueryBuilder('it')
            ->join('it.importFile', 'imf')
            ->join('imf.account', 'a')
            ->where('a.family = :family')
            ->andWhere('it.status = :status')
            ->setParameter('family', $family)
            ->setParameter('status', ImportTransactionStatus::Pending)
            ->orderBy('it.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
