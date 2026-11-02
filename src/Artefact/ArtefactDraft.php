<?php

declare(strict_types=1);

namespace App\Artefact;

use App\Entity\Artefact;

final class ArtefactDraft
{
    /**
     * @param list<array{name: string, note: string}> $materials
     * @param list<array{path: string, src: string}>  $images
     */
    public function __construct(
        public string $title,
        public string $size,
        public int $category,
        public string $description,
        public string $dimensions,
        public string $recordDate,
        public string $handmade,
        public array $materials,
        public string $care,
        public array $images,
        public bool $locked,
        public ?string $code,
    ) {
    }

    public static function blank(): self
    {
        return new self(
            title: '',
            size: 'M',
            category: 1,
            description: '',
            dimensions: '',
            recordDate: ArtefactRules::localToday(),
            handmade: ArtefactRules::HANDMADE_DEFAULT,
            materials: [['name' => '', 'note' => '']],
            care: ArtefactRules::CARE_DEFAULT,
            images: [],
            locked: false,
            code: null,
        );
    }

    public static function fromArtefact(Artefact $artefact, ImageStore $images): self
    {
        return new self(
            title: $artefact->getTitle(),
            size: $artefact->getSize(),
            category: $artefact->getCategory(),
            description: $artefact->getDescription(),
            dimensions: $artefact->getDimensions(),
            recordDate: $artefact->getRecordDate()->format('Y-m-d'),
            handmade: $artefact->getHandmade(),
            materials: $artefact->getMaterials() ?: [['name' => '', 'note' => '']],
            care: implode("\n", $artefact->getCare()),
            images: self::views($artefact->getImages(), $images),
            locked: true,
            code: $artefact->getCode(),
        );
    }

    /**
     * @param list<string>                            $paths
     * @param list<array{name: string, note: string}> $materials
     */
    public static function fromPosted(
        string $title,
        string $size,
        int $category,
        string $description,
        string $dimensions,
        string $recordDate,
        string $handmade,
        array $materials,
        string $care,
        array $paths,
        ImageStore $images,
        ?Artefact $current,
    ): self {
        if (!in_array($size, ['P', 'M', 'G'], true)) {
            $size = 'M';
        }
        if (!in_array($category, [1, 2, 3], true)) {
            $category = 1;
        }
        if ($materials === []) {
            $materials = [['name' => '', 'note' => '']];
        }

        return new self(
            title: $title,
            size: $current?->getSize() ?? $size,
            category: $current?->getCategory() ?? $category,
            description: $description,
            dimensions: $dimensions,
            recordDate: $recordDate !== '' ? $recordDate : ArtefactRules::localToday(),
            handmade: $handmade !== '' ? $handmade : ArtefactRules::HANDMADE_DEFAULT,
            materials: $materials,
            care: $care !== '' ? $care : ArtefactRules::CARE_DEFAULT,
            images: self::views($paths, $images),
            locked: $current !== null,
            code: $current?->getCode(),
        );
    }

    /**
     * @param list<string> $paths
     *
     * @return list<array{path: string, src: string}>
     */
    private static function views(array $paths, ImageStore $images): array
    {
        $views = [];
        foreach ($paths as $path) {
            if (!ArtefactRules::isValidImagePath($path)) {
                continue;
            }
            $views[] = ['path' => $path, 'src' => $images->webPath($path)];
        }

        return $views;
    }
}
