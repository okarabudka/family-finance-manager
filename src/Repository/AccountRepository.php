<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Family;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /** @return Account[] */
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

    public function findByFamilyAndId(Family $family, string $id): ?Account
    {
        return $this->findOneBy(['id' => $id, 'family' => $family]);
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
