<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Family;
use App\Entity\Recommendation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Recommendation>
 */
class RecommendationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recommendation::class);
    }

    /** @return Recommendation[] */
    public function findActiveByFamily(Family $family): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.family = :family')
            ->andWhere('r.seenByUser = false')
            ->setParameter('family', $family)
            ->orderBy('r.priority', 'DESC')
            ->addOrderBy('r.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
