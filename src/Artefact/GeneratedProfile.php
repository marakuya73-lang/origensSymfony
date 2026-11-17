<?php

declare(strict_types=1);

namespace App\Artefact;

final readonly class GeneratedProfile
{
    /**
     * @param list<array{name: string, note: string}> $materials
     * @param list<string>                           $care
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $dimensions,
        public array $materials,
        public array $care,
        public string $handmade,
        public string $size,
    ) {
    }

    /** @param array<mixed> $payload */
    public static function fromModel(array $payload): self
    {
        $title = self::text($payload['title'] ?? '', 180);
        if ($title === '') {
            throw new ProfileGenerationFailed(ProfileGenerationFailed::UNUSABLE, 502);
        }

        $materials = self::materials($payload['materials'] ?? []);
        $care = self::care($payload['care'] ?? []);

        return new self(
            $title,
            self::text($payload['description'] ?? '', 2000),
            self::text($payload['dimensions'] ?? '', 100),
            $materials,
            $care,
            self::text($payload['handmade'] ?? '', 5000) ?: ArtefactRules::PENDING,
            self::size($payload['size'] ?? ''),
        );
    }

    /** @return array{title: string, description: string, dimensions: string, materials: list<array{name: string, note: string}>, care: list<string>, handmade: string, size: string} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'dimensions' => $this->dimensions,
            'materials' => $this->materials,
            'care' => $this->care,
            'handmade' => $this->handmade,
            'size' => $this->size,
        ];
    }

    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max));
    }

    /** @return list<array{name: string, note: string}> */
    private static function materials(mixed $value): array
    {
        if (!is_array($value)) {
            return [['name' => ArtefactRules::PENDING, 'note' => '']];
        }

        $materials = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = self::text($row['name'] ?? '', 150);
            if ($name === '') {
                continue;
            }
            $materials[] = ['name' => $name, 'note' => self::text($row['note'] ?? '', 2000)];
            if (count($materials) === 30) {
                break;
            }
        }

        return $materials === [] ? [['name' => ArtefactRules::PENDING, 'note' => '']] : $materials;
    }

    /** @return list<string> */
    private static function care(mixed $value): array
    {
        $lines = [];
        $source = is_string($value) ? preg_split("/\r\n|\n|\r/", $value) : (is_array($value) ? $value : []);
        if (!is_array($source)) {
            return [ArtefactRules::PENDING];
        }

        foreach ($source as $line) {
            $line = self::text($line, 2000);
            if ($line === '') {
                continue;
            }
            $lines[] = $line;
            if (count($lines) === 30) {
                break;
            }
        }

        return $lines === [] ? [ArtefactRules::PENDING] : $lines;
    }

    private static function size(mixed $value): string
    {
        $size = is_string($value) ? strtoupper(trim($value)) : '';

        return in_array($size, ['P', 'M', 'G'], true) ? $size : '';
    }
}
