<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfileJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfileJob>
 */
class ProfileJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfileJob::class);
    }

    public function nextDue(\DateTimeImmutable $now): ?ProfileJob
    {
        $job = $this->createQueryBuilder('j')
            ->andWhere('j.status = :status')
            ->andWhere('j.runAt <= :now')
            ->setParameter('status', 'queued')
            ->setParameter('now', $now)
            ->orderBy('j.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $job instanceof ProfileJob ? $job : null;
    }
}
