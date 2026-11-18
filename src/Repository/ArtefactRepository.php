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

    /** @return list<string> */
    public function recentTitles(int $limit = 30): array
    {
        /** @var list<mixed> $titles */
        $titles = $this->createQueryBuilder('a')
            ->select('a.title')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 30)))
            ->getQuery()
            ->getSingleColumnResult();

        $clean = [];
        foreach ($titles as $title) {
            if (!is_string($title)) {
                continue;
            }
            $title = trim($title);
            if ($title !== '') {
                $clean[] = $title;
            }
        }

        return $clean;
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<Artefact>
     */
    public function findSelected(array $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $id = strtolower($id);
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) || in_array($id, $wanted, true)) {
                continue;
            }
            $wanted[] = $id;
        }
        if ($wanted === []) {
            return [];
        }

        /** @var list<Artefact> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.id IN (:ids)')
            ->setParameter('ids', $wanted)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->getId()] = $row;
        }

        $ordered = [];
        foreach ($wanted as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function findByToken(string $token): ?Artefact
    {
        $artefact = $this->findOneBy(['publicToken' => $token]);

        return $artefact instanceof Artefact ? $artefact : null;
    }
}
