<?php

declare(strict_types=1);

namespace App\Artefact;

use App\Entity\Artefact;
use App\Repository\ArtefactRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class ArtefactRegistrar
{
    public function __construct(
        private EntityManagerInterface $em,
        private ArtefactRepository $artefacts,
    ) {
    }

    public function create(ArtefactData $data): Artefact
    {
        try {
            /** @var Artefact $artefact */
            $artefact = $this->em->wrapInTransaction(function () use ($data): Artefact {
                $artefact = new Artefact();
                $artefact->setSize($data->size);
                $artefact->setCategory($data->category);
                $artefact->apply($data);
                $artefact->setCode($this->nextCode($data->size, $data->category));
                $artefact->setPublicToken($this->nextToken());
                $this->em->persist($artefact);

                return $artefact;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArtefact('Não foi possível salvar a peça. Nenhum código existente foi alterado.');
        }

        return $artefact;
    }

    /** @return list<string> Image paths removed from the record */
    public function update(Artefact $artefact, ArtefactData $data): array
    {
        $removed = array_values(array_diff($artefact->getImages(), $data->images));
        $artefact->apply($data);
        $this->em->flush();

        return $removed;
    }

    public function remove(Artefact $artefact): void
    {
        $this->em->remove($artefact);
        $this->em->flush();
    }

    private function nextCode(string $size, int $category): string
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO artefact_counters (size, category, last_number) VALUES (:size, :category, 1)
             ON CONFLICT(size, category) DO UPDATE SET last_number = artefact_counters.last_number + 1',
            ['size' => $size, 'category' => $category],
        );

        $number = (int) $this->em->getConnection()->fetchOne(
            'SELECT last_number FROM artefact_counters WHERE size = :size AND category = :category',
            ['size' => $size, 'category' => $category],
        );

        return ArtefactRules::formatCode($size, $category, $number);
    }

    private function nextToken(): string
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $token = bin2hex(random_bytes(16));
            if (!$this->artefacts->findByToken($token)) {
                return $token;
            }
        }

        throw new \RuntimeException('Could not allocate a public token.');
    }
}
