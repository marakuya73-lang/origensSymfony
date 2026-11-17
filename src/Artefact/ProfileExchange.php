<?php

declare(strict_types=1);

namespace App\Artefact;

final class ProfileExchange
{
    public const ANSWER_REQUIRED = 'Escreva uma resposta ou envie um áudio para continuar.';

    /**
     * @return list<array{role: string, text: string}>
     */
    public static function history(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $history = [];
        foreach ($decoded as $turn) {
            if (!is_array($turn) || !in_array($turn['role'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }
            $text = self::text($turn['text'] ?? '', 1500);
            if ($text === '') {
                continue;
            }
            $history[] = ['role' => $turn['role'], 'text' => $text];
            if (count($history) === 16) {
                break;
            }
        }

        return $history;
    }

    /** @return array{title: string, description: string, dimensions: string, materials: list<array{name: string, note: string}>, care: list<string>, handmade: string, size: string} */
    public static function form(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'title' => self::text($decoded['title'] ?? '', 180),
            'description' => self::text($decoded['description'] ?? '', ArtefactRules::DESCRIPTION_MAX),
            'dimensions' => self::text($decoded['dimensions'] ?? '', 100),
            'materials' => self::materials($decoded['materials'] ?? []),
            'care' => self::care($decoded['care'] ?? []),
            'handmade' => self::text($decoded['handmade'] ?? '', 5000),
            'size' => self::size($decoded['size'] ?? ''),
        ];
    }

    /** @return list<string> */
    public static function touched(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $touched = [];
        foreach ($decoded as $field) {
            if (!is_string($field) || !in_array($field, ProfileTurn::FIELDS, true) || in_array($field, $touched, true)) {
                continue;
            }
            $touched[] = $field;
        }

        return $touched;
    }

    /**
     * @param array{title: string, description: string, dimensions: string, materials: list<array{name: string, note: string}>, care: list<string>, handmade: string, size: string} $form
     * @param list<string>                                                                                                                                                       $touched
     * @param list<string>                                                                                                                                                       $changed
     */
    public static function merge(GeneratedProfile $suggested, array $form, array $touched, array $changed): GeneratedProfile
    {
        $keep = static fn (string $field): bool => in_array($field, $touched, true) && !in_array($field, $changed, true);
        $materials = self::materials($form['materials']);

        return new GeneratedProfile(
            $keep('title') && $form['title'] !== '' ? $form['title'] : $suggested->title,
            $keep('description') ? $form['description'] : self::description($form['description'], $suggested->description, in_array('description', $changed, true)),
            $keep('dimensions') ? $form['dimensions'] : $suggested->dimensions,
            $keep('materials') && $materials !== [] ? $materials : $suggested->materials,
            $keep('care') && $form['care'] !== [] ? $form['care'] : $suggested->care,
            $keep('handmade') && $form['handmade'] !== '' ? $form['handmade'] : $suggested->handmade,
            $keep('size') && $form['size'] !== '' ? $form['size'] : $suggested->size,
        );
    }

    private static function description(string $current, string $suggested, bool $rewrite): string
    {
        if ($rewrite || $current === '') {
            return $suggested;
        }
        if ($suggested === '' || mb_strlen($suggested) < mb_strlen($current)) {
            return $current;
        }

        return $suggested;
    }

    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim(preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '');
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max));
    }

    /** @return list<array{name: string, note: string}> */
    private static function materials(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
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

        return $materials;
    }

    /** @return list<string> */
    private static function care(mixed $value): array
    {
        $source = is_string($value) ? preg_split("/\r\n|\n|\r/", $value) : (is_array($value) ? $value : []);
        if (!is_array($source)) {
            return [];
        }

        $lines = [];
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

        return $lines;
    }

    private static function size(mixed $value): string
    {
        $size = is_string($value) ? strtoupper(trim($value)) : '';

        return in_array($size, ['P', 'M', 'G'], true) ? $size : '';
    }
}
