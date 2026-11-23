<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\CategoryType;
use App\Entity\Family;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /** @return Category[] */
    public function findByFamily(Family $family, CategoryType $type): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.family = :family')
            ->andWhere('c.type = :type')
            ->setParameter('family', $family)
            ->setParameter('type', $type)
            ->orderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByFamilyAndId(Family $family, string $id): ?Category
    {
        return $this->findOneBy(['id' => $id, 'family' => $family]);
    }

    public function createFamilyQueryBuilder(Family $family): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->where('c.family = :family')
            ->setParameter('family', $family)
            ->orderBy('c.sortOrder', 'ASC');
    }
}
