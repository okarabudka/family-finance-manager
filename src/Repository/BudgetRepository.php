<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Family;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Budget>
 */
class BudgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Budget::class);
    }

    public function findForMonth(Family $family, Category $category, int $year, int $month): ?Budget
    {
        return $this->findOneBy([
            'family' => $family,
            'category' => $category,
            'year' => $year,
            'month' => $month,
        ]);
    }

    /** @return Budget[] */
    public function findByFamilyAndMonth(Family $family, int $year, int $month): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.family = :family')
            ->andWhere('b.year = :year')
            ->andWhere('b.month = :month')
            ->setParameter('family', $family)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->orderBy('b.plannedAmount', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
