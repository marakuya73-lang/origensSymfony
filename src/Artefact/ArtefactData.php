<?php

declare(strict_types=1);

namespace App\Artefact;

final readonly class ArtefactData
{
    public const GENERIC = 'Confira o nome, os materiais, os cuidados, a data e envie pelo menos uma foto.';

    /**
     * @param list<array{name: string, note: string}> $materials
     * @param list<string>                           $care
     * @param list<string>                           $images
     */
    public function __construct(
        public string $title,
        public string $size,
        public int $category,
        public string $description,
        public string $dimensions,
        public array $materials,
        public array $care,
        public string $handmade,
        public array $images,
        public \DateTimeImmutable $recordDate,
    ) {
    }

    /**
     * @param list<array{name?: mixed, note?: mixed}|mixed> $materials
     * @param list<mixed>                                  $imagePaths
     */
    public static function fromSubmitted(
        string $title,
        string $size,
        int $category,
        string $description,
        string $dimensions,
        array $materials,
        string $care,
        string $handmade,
        array $imagePaths,
        string $recordDate,
    ): self {
        if (count($imagePaths) > 12) {
            throw new InvalidArtefact('Selecione até 12 fotos por peça.');
        }

        $title = trim($title);
        $description = trim($description);
        $dimensions = trim($dimensions);
        $handmade = trim($handmade);
        $normalizedMaterials = self::materials($materials);
        $lines = self::careLines($care);
        $images = self::images($imagePaths);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $recordDate);

        $valid = in_array($size, ['P', 'M', 'G'], true)
            && in_array($category, [1, 2, 3], true)
            && $title !== ''
            && mb_strlen($title) <= 180
            && mb_strlen($description) <= 2000
            && mb_strlen($dimensions) <= 100
            && $normalizedMaterials !== null
            && $lines !== null
            && $handmade !== ''
            && mb_strlen($handmade) <= 5000
            && $images !== null
            && $date instanceof \DateTimeImmutable
            && ArtefactRules::isValidDate($recordDate);

        if (!$valid) {
            throw new InvalidArtefact(self::GENERIC);
        }

        return new self(
            $title,
            $size,
            $category,
            $description,
            $dimensions,
            $normalizedMaterials,
            $lines,
            $handmade,
            $images,
            $date,
        );
    }

    /**
     * @param list<array{name?: mixed, note?: mixed}|mixed> $materials
     *
     * @return list<array{name: string, note: string}>|null
     */
    private static function materials(array $materials): ?array
    {
        if ($materials === [] || count($materials) > 30) {
            return null;
        }

        $normalized = [];
        foreach ($materials as $material) {
            if (!is_array($material)) {
                return null;
            }
            $name = trim((string) ($material['name'] ?? ''));
            $note = trim((string) ($material['note'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150 || mb_strlen($note) > 2000) {
                return null;
            }
            $normalized[] = ['name' => $name, 'note' => $note];
        }

        return $normalized;
    }

    /** @return list<string>|null */
    private static function careLines(string $care): ?array
    {
        $lines = [];
        foreach (preg_split("/\r\n|\n|\r/", $care) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (mb_strlen($line) > 2000) {
                return null;
            }
            $lines[] = $line;
        }

        if ($lines === [] || count($lines) > 30) {
            return null;
        }

        return $lines;
    }

    /**
     * @param list<mixed> $imagePaths
     *
     * @return list<string>|null
     */
    private static function images(array $imagePaths): ?array
    {
        if ($imagePaths === [] || count($imagePaths) > 12) {
            return null;
        }

        $images = [];
        foreach ($imagePaths as $path) {
            if (!is_string($path) || !ArtefactRules::isValidImagePath($path)) {
                return null;
            }
            $images[] = $path;
        }

        return $images;
    }
}
