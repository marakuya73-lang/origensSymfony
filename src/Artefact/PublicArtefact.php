<?php

declare(strict_types=1);

namespace App\Artefact;

use App\Entity\Artefact;

final class PublicArtefact
{
    /**
     * @param list<array{name: string, note: string}> $materials
     * @param list<string>                           $care
     * @param list<string>                           $images
     */
    public function __construct(
        public string $path,
        public string $reference,
        public string $title,
        public string $lede,
        public string $dimensions,
        public array $materials,
        public string $handmade,
        public array $care,
        public string $recordDate,
        public array $images,
    ) {
    }

    public function displayUrl(): string
    {
        return 'origens.art.br/p/'.$this->path;
    }

    public static function fromEntity(Artefact $artefact, ImageStore $images): self
    {
        $photos = [];
        foreach ($artefact->getImages() as $path) {
            if (ArtefactRules::isValidImagePath($path)) {
                $photos[] = $images->webPath($path);
            }
        }

        return new self(
            path: $artefact->getPublicToken(),
            reference: $artefact->getCode(),
            title: $artefact->getTitle(),
            lede: $artefact->getDescription(),
            dimensions: $artefact->getDimensions() !== '' ? $artefact->getDimensions() : $artefact->getSize(),
            materials: $artefact->getMaterials(),
            handmade: $artefact->getHandmade(),
            care: $artefact->getCare(),
            recordDate: $artefact->getRecordDate()->format('d / m / Y'),
            images: $photos,
        );
    }
}
