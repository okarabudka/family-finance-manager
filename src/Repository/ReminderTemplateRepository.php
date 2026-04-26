<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReminderTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReminderTemplate>
 */
class ReminderTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReminderTemplate::class);
    }

    /** @return ReminderTemplate[] */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('rt')
            ->where('rt.isActive = true')
            ->orderBy('rt.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
