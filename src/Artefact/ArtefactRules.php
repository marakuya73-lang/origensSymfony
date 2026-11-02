<?php

declare(strict_types=1);

namespace App\Artefact;

final class ArtefactRules
{
    public const HANDMADE_DEFAULT = 'Cada peça nasce de um trabalho manual atento, que começa muito antes da montagem: na busca paciente pelos materiais certos, na observação das suas formas e texturas e na escolha de cada elemento. São horas de dedicação, preparação e criação, reunidas num processo que pede presença, cuidado e intenção. Aos poucos, cada material encontra o seu lugar e a peça revela a sua própria forma.';

    public const CARE_DEFAULT = "Guardar em local seco, longe da luz solar direta.\nManusear com cuidado.\nRemover a poeira com pincel macio e seco.";

    public const PENDING = 'informação a documentar';

    public const CODE_PATTERN = '/^[PMG]-CAT[123]-\d{3,}$/';

    public const TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

    public const IMAGE_PATTERN = '/^[a-f0-9-]{36}\/[a-f0-9-]{36}\.(?:jpg|jpeg|png|webp)$/';

    public static function localToday(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    }

    public static function publicPath(string $token): string
    {
        if (!self::isValidToken($token)) {
            throw new \InvalidArgumentException('A public address requires a 32-character token.');
        }

        return '/p/'.$token;
    }

    public static function formatCode(string $size, int $category, int $number): string
    {
        $suffix = $number < 1000 ? str_pad((string) $number, 3, '0', STR_PAD_LEFT) : (string) $number;

        return $size.'-CAT'.$category.'-'.$suffix;
    }

    public static function isValidCode(string $code): bool
    {
        return 1 === preg_match(self::CODE_PATTERN, $code);
    }

    public static function isValidToken(string $token): bool
    {
        return 1 === preg_match(self::TOKEN_PATTERN, $token);
    }

    public static function isValidImagePath(string $path): bool
    {
        return 1 === preg_match(self::IMAGE_PATTERN, $path);
    }

    public static function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
