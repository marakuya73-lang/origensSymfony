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
            ->orderBy('j.createdAt', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $job instanceof ProfileJob ? $job : null;
    }

    public function latestFor(string $artefactId): ?ProfileJob
    {
        $job = $this->createQueryBuilder('j')
            ->andWhere('j.artefactId = :artefact')
            ->setParameter('artefact', $artefactId)
            ->orderBy('j.createdAt', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $job instanceof ProfileJob ? $job : null;
    }

    /** @return list<ProfileJob> */
    public function queued(int $limit): array
    {
        /** @var list<ProfileJob> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->andWhere('j.status = :status')
            ->setParameter('status', 'queued')
            ->orderBy('j.createdAt', \SortDirection::Ascending)
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $jobs;
    }

    /** @return list<ProfileJob> */
    public function recent(int $limit = 20): array
    {
        /** @var list<ProfileJob> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->orderBy('j.createdAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $jobs;
    }

    public function releaseStale(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('j')
            ->update()
            ->set('j.status', ':queued')
            ->set('j.stage', ':stage')
            ->where('j.status = :running')
            ->andWhere('j.updatedAt < :before')
            ->setParameter('queued', 'queued')
            ->setParameter('stage', 'queued')
            ->setParameter('running', 'running')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    public function countStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
