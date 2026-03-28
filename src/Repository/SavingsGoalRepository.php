<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Family;
use App\Entity\SavingsGoal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavingsGoal>
 */
class SavingsGoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavingsGoal::class);
    }

    /** @return SavingsGoal[] */
    public function findByFamily(Family $family): array
    {
        return $this->createQueryBuilder('sg')
            ->where('sg.family = :family')
            ->setParameter('family', $family)
            ->orderBy('sg.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
