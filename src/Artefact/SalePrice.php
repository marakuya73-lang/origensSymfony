<?php

declare(strict_types=1);

namespace App\Artefact;

final class SalePrice
{
    public const INVALID = 'Informe o preço de venda em reais, por exemplo 1.250,00, ou deixe em branco.';

    private const MAX_CENTS = 99_999_999;

    public static function cents(string $raw): ?int
    {
        $raw = str_replace(['R$', ' ', "\u{00a0}"], '', trim($raw));
        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (1 === preg_match('/^\d{1,3}(\.\d{3})+$/', $raw)) {
            $raw = str_replace('.', '', $raw);
        }

        if (1 !== preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
            throw new InvalidArtefact(self::INVALID);
        }

        [$reais, $fraction] = array_pad(explode('.', $raw, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0', STR_PAD_RIGHT);
        if ($reais === '' || strlen($reais) > 7) {
            throw new InvalidArtefact(self::INVALID);
        }

        $cents = ((int) $reais * 100) + (int) $fraction;
        if ($cents > self::MAX_CENTS) {
            throw new InvalidArtefact(self::INVALID);
        }

        return $cents;
    }

    public static function input(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        return number_format($cents / 100, 2, ',', '.');
    }

    public static function brl(?int $cents): string
    {
        $input = self::input($cents);

        return $input === '' ? '' : 'R$ '.$input;
    }

    public static function excel(?int $cents): string
    {
        return self::input($cents);
    }
}
