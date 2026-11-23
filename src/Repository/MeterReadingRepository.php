<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Family;
use App\Entity\MeterReading;
use App\Entity\MeterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MeterReading>
 */
class MeterReadingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MeterReading::class);
    }

    /** @return MeterReading[] ordered ASC for consumption calculation */
    public function findByFamilyAndType(Family $family, MeterType $type): array
    {
        return $this->createQueryBuilder('mr')
            ->where('mr.family = :family')
            ->andWhere('mr.type = :type')
            ->setParameter('family', $family)
            ->setParameter('type', $type)
            ->orderBy('mr.readingDate', 'ASC')
            ->addOrderBy('mr.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestByFamilyAndType(Family $family, MeterType $type): ?MeterReading
    {
        $result = $this->createQueryBuilder('mr')
            ->where('mr.family = :family')
            ->andWhere('mr.type = :type')
            ->setParameter('family', $family)
            ->setParameter('type', $type)
            ->orderBy('mr.readingDate', 'DESC')
            ->addOrderBy('mr.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert(null === $result || $result instanceof MeterReading);

        return $result;
    }
}
