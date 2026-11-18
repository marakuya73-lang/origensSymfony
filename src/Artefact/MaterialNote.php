<?php

declare(strict_types=1);

namespace App\Artefact;

use Symfony\Component\String\UnicodeString;

final class MaterialNote
{
    /** @var list<string> */
    private const GENERIC_FEATHERS = ['pena', 'penas', 'pluma', 'plumas'];

    /** @var list<string> */
    private const FORMULAS = [
        'em muitas tradições indígenas brasileiras',
        'em muitas tradições indígenas',
        'em muitas tradições',
        'na tradição das pedras',
        'na tradição das penas',
        'na tradição dos panos',
        'na tradição do trançado brasileiro',
        'na tradição',
        'segundo a tradição',
    ];

    /**
     * @param array<mixed> $payload
     *
     * @return array<mixed>
     */
    public static function refine(array $payload): array
    {
        if (!is_array($payload['materials'] ?? null)) {
            return $payload;
        }

        $rows = [];
        foreach ($payload['materials'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $note = trim((string) ($row['note'] ?? ''));
            if ($name === '') {
                continue;
            }
            foreach (self::expand($name, $note) as $expanded) {
                $rows[] = $expanded;
            }
        }

        $payload['materials'] = self::dropRepeatedOpenings($rows);

        return $payload;
    }

    /**
     * @return list<array{name: string, note: string}>
     */
    public static function expand(string $name, string $note): array
    {
        $species = self::featherSpecies($name.' '.$note);
        if (self::isGenericFeather($name) && $species !== []) {
            $rows = [];
            foreach ($species as $bird) {
                $feather = 'pena de '.$bird;
                $rows[] = ['name' => $feather, 'note' => self::polish($feather, self::clauseFor($bird, $note))];
            }

            return $rows;
        }

        return [['name' => $name, 'note' => self::polish($name, $note)]];
    }

    public static function polish(string $name, string $note): string
    {
        $note = trim($note);
        if ($note === '') {
            return '';
        }

        $note = self::stripFragment($name, $note);
        for ($pass = 0; $pass < 4; ++$pass) {
            $note = ltrim($note, " \t\n\r\0\x0B.,;:!-—");
            $next = self::stripFormula(self::stripLeadingName($name, $note));
            if ($next === $note) {
                break;
            }
            $note = $next;
        }

        $note = self::capitalize(trim($note, " \t\n\r\0\x0B,;:-—"));

        return $note;
    }

    /** @return list<string> */
    private static function featherSpecies(string $text): array
    {
        $found = [];
        if (preg_match_all('/penas?\s+de\s+([^,.;]+?)(?=\s+e\s+penas?\s+de|\s*,|\s*;|\s*\.|$)/iu', $text, $matches) < 1) {
            return [];
        }
        foreach ($matches[1] as $species) {
            $species = trim((string) $species);
            if ($species === '' || self::isGenericFeather($species)) {
                continue;
            }
            $key = self::fold($species);
            $found[$key] = $species;
        }

        return array_values($found);
    }

    private static function clauseFor(string $bird, string $note): string
    {
        $withoutOthers = preg_replace('/penas?\s+de\s+(?!'.preg_quote($bird, '/').'\b)[^,.;]+/iu', '', $note);
        $text = is_string($withoutOthers) ? $withoutOthers : $note;
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+e\s+(?=[,.])/u', '', $text) ?? $text;

        return trim($text, " \t\n\r\0\x0B,;e");
    }

    private static function stripFragment(string $name, string $note): string
    {
        if (preg_match('/^(\p{L}{1,3})\.\s+(.*)$/u', $note, $match) !== 1) {
            return $note;
        }
        $prefix = self::fold($match[1]);
        $foldedName = self::fold($name);
        if ($prefix !== '' && str_starts_with($foldedName, $prefix) && strlen($prefix) < strlen($foldedName)) {
            return $match[2];
        }

        return $note;
    }

    private static function stripLeadingName(string $name, string $note): string
    {
        $pattern = '/^(?:o|a|os|as|um|uma)?\s*'.preg_quote($name, '/').'\s*(?:é|e|sao|são|:|—|-|,|\.)?\s*/iu';
        $stripped = preg_replace($pattern, '', $note, 1);

        return is_string($stripped) ? trim($stripped) : $note;
    }

    private static function stripFormula(string $note): string
    {
        foreach (self::FORMULAS as $formula) {
            $pattern = '/^'.preg_quote($formula, '/').'\s*[,:;]?\s*/iu';
            $stripped = preg_replace($pattern, '', $note, 1);
            if (is_string($stripped) && $stripped !== $note) {
                return trim($stripped);
            }
        }

        return $note;
    }

    /**
     * @param list<array{name: string, note: string}> $rows
     *
     * @return list<array{name: string, note: string}>
     */
    private static function dropRepeatedOpenings(array $rows): array
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            $opening = self::opening($row['note']);
            if ($opening !== '' && isset($seen[$opening])) {
                $rest = preg_replace('/^.*?[.!?]\s+/u', '', $row['note'], 1);
                if (is_string($rest) && $rest !== $row['note'] && trim($rest) !== '') {
                    $rows[$index]['note'] = self::capitalize(trim($rest));
                }
            }
            if ($opening !== '') {
                $seen[$opening] = true;
            }
        }

        return $rows;
    }

    private static function opening(string $note): string
    {
        if (preg_match('/^(.{0,80}?[.!?])/u', $note, $match) !== 1) {
            return self::fold($note);
        }

        return self::fold($match[1]);
    }

    private static function isGenericFeather(string $name): bool
    {
        return in_array(self::fold($name), self::GENERIC_FEATHERS, true);
    }

    private static function capitalize(string $note): string
    {
        if ($note === '') {
            return '';
        }
        $first = mb_substr($note, 0, 1, 'UTF-8');

        return mb_strtoupper($first, 'UTF-8').mb_substr($note, 1, null, 'UTF-8');
    }

    private static function fold(string $value): string
    {
        $folded = (new UnicodeString($value))->folded()->toString();
        $folded = str_replace(["'", '’', '-'], ' ', $folded);
        $folded = preg_replace('/[^a-z0-9]+/', ' ', $folded) ?? '';

        return trim(preg_replace('/\s+/', ' ', $folded) ?? '');
    }
}
