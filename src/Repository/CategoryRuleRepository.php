<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CategoryRule;
use App\Entity\Family;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategoryRule>
 */
class CategoryRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategoryRule::class);
    }

    /**
     * Returns rules ordered by priority DESC so higher-priority rules are evaluated first.
     *
     * @return array<int, CategoryRule>
     */
    public function findByFamilyOrderedByPriority(Family $family): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.family = :family')
            ->setParameter('family', $family)
            ->orderBy('r.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
