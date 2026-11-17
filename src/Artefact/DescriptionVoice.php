<?php

declare(strict_types=1);

namespace App\Artefact;

final class DescriptionVoice
{
    /** @var list<string> */
    private const LEADING = [
        'como foi dito',
        'como dito',
        'como mencionado',
        'conforme mencionado',
        'conforme o relato',
        'conforme relatado',
        'segundo o relato',
        'segundo a tradição',
        'segundo o áudio',
        'segundo a foto',
        'segundo a fotografia',
        'como se vê',
        'como se ouve',
        'como se escuta',
        'a artesã contou que',
        'a artesã disse que',
        'a artesã relatou que',
        'o áudio revela que',
        'o áudio diz que',
        'a foto mostra que',
        'a fotografia mostra que',
    ];

    /** @param array<mixed> $payload
     *  @return array<mixed>
     */
    public static function polishPayload(array $payload): array
    {
        foreach (['title', 'description'] as $field) {
            if (is_string($payload[$field] ?? null)) {
                $payload[$field] = self::polish($payload[$field]);
            }
        }

        return $payload;
    }

    public static function polish(string $text): string
    {
        $pattern = '/(^|(?<=[.!?]\s)|(?<=\n))(?:'.implode('|', array_map(static fn (string $prefix): string => preg_quote($prefix, '/'), self::LEADING)).')\s*[,:;]?\s+(\p{L})/iu';
        $polished = $text;
        for ($pass = 0; $pass < 4; ++$pass) {
            $next = preg_replace_callback(
                $pattern,
                static function (array $match): string {
                    $letter = $match[2];
                    $upper = mb_strtoupper($letter, 'UTF-8');

                    return $match[1].($upper !== '' ? $upper : $letter);
                },
                $polished,
            );
            if (!is_string($next) || $next === $polished) {
                break;
            }
            $polished = $next;
        }

        $polished = trim($polished);

        return $polished === '' ? $text : $polished;
    }
}
