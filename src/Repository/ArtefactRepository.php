<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artefact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Artefact>
 */
class ArtefactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Artefact::class);
    }

    /** @return list<Artefact> */
    public function recent(): array
    {
        /** @var list<Artefact> $rows */
        $rows = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findByToken(string $token): ?Artefact
    {
        $artefact = $this->findOneBy(['publicToken' => $token]);

        return $artefact instanceof Artefact ? $artefact : null;
    }
}
