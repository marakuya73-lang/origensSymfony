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
        public string $symbolism = '',
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
            self::text($payload['description'] ?? '', ArtefactRules::DESCRIPTION_MAX),
            self::text($payload['dimensions'] ?? '', 100),
            $materials,
            $care,
            self::text($payload['handmade'] ?? '', 5000) ?: ArtefactRules::PENDING,
            self::size($payload['size'] ?? ''),
            self::text($payload['symbolism'] ?? '', ArtefactRules::DESCRIPTION_MAX),
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
            'symbolism' => $this->symbolism,
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
        if (is_string($value)) {
            $value = array_map(
                static fn (string $name): array => ['name' => $name, 'note' => ''],
                self::materialNames($value),
            );
        }
        if (!is_array($value)) {
            return [['name' => ArtefactRules::PENDING, 'note' => '']];
        }

        $materials = [];
        foreach ($value as $row) {
            if (is_string($row)) {
                $row = ['name' => $row, 'note' => ''];
            }
            if (!is_array($row)) {
                continue;
            }
            $names = self::materialNames(is_string($row['name'] ?? null) ? $row['name'] : '');
            $note = self::text($row['note'] ?? '', 2000);
            foreach ($names as $index => $name) {
                $name = self::text($name, 150);
                if ($name === '') {
                    continue;
                }
                $materials[] = ['name' => $name, 'note' => $index === 0 ? $note : ''];
                if (count($materials) === 30) {
                    break 2;
                }
            }
        }

        return $materials === [] ? [['name' => ArtefactRules::PENDING, 'note' => '']] : $materials;
    }

    /** @return list<string> */
    private static function materialNames(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;|\/|\R|\s+e\s+|\s+and\s+)\s*/iu', $value);
        if (!is_array($parts) || count($parts) < 2) {
            return [$value];
        }

        $names = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (mb_strlen($part) > 60) {
                return [$value];
            }
            $names[] = $part;
        }

        return count($names) >= 2 ? $names : [$value];
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
