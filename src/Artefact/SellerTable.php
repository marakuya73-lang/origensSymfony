<?php

declare(strict_types=1);

namespace App\Artefact;

use App\Entity\Artefact;

final class SellerTable
{
    public function __construct(private ImageStore $images)
    {
    }

    public const PRICE_COLUMN = 2;

    /**
     * @param list<Artefact> $artefacts
     *
     * @return array{headers: list<string>, rows: list<list<string>>, priceColumn: int}
     */
    public function build(array $artefacts, string $origin): array
    {
        $rows = [];
        $totalCents = 0;
        $missingPrices = 0;
        foreach ($artefacts as $artefact) {
            $cents = $artefact->getSalePriceCents();
            if ($cents === null) {
                ++$missingPrices;
            } else {
                $totalCents += $cents;
            }
            $token = $artefact->getPublicToken();
            $rows[] = [
                $artefact->getCode(),
                $artefact->getTitle(),
                SalePrice::excel($cents),
                $artefact->getDescription(),
                $artefact->getSize(),
                (string) $artefact->getCategory(),
                $artefact->getDimensions(),
                $this->materials($artefact),
                implode("\n", $artefact->getCare()),
                $artefact->getHandmade(),
                $artefact->getRecordDate()->format('d/m/Y'),
                ArtefactRules::isValidToken($token) ? $origin.ArtefactRules::publicPath($token) : '',
                $this->imageUrl($artefact, $origin),
            ];
        }

        $rows[] = $this->totalRow($totalCents, $missingPrices);

        return ['headers' => self::headers(), 'rows' => $rows, 'priceColumn' => self::PRICE_COLUMN];
    }

    /** @return list<string> */
    public static function headers(): array
    {
        return [
            'Referência',
            'Nome',
            'Preço de venda (R$)',
            'Descrição',
            'Tamanho',
            'Categoria',
            'Dimensão',
            'Materiais',
            'Cuidados',
            'Feito à mão',
            'Data do registro',
            'Endereço',
            'Foto',
        ];
    }

    private function materials(Artefact $artefact): string
    {
        $lines = [];
        foreach ($artefact->getMaterials() as $material) {
            $lines[] = $material['note'] !== '' ? $material['name'].' — '.$material['note'] : $material['name'];
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function totalRow(int $totalCents, int $missingPrices): array
    {
        $row = array_fill(0, count(self::headers()), '');
        $row[0] = 'Total';
        $row[1] = match ($missingPrices) {
            0 => '',
            1 => '1 sem preço',
            default => $missingPrices.' sem preço',
        };
        $row[self::PRICE_COLUMN] = SalePrice::excel($totalCents);

        return $row;
    }

    private function imageUrl(Artefact $artefact, string $origin): string
    {
        $first = $artefact->getImages()[0] ?? null;
        if (!is_string($first) || !ArtefactRules::isValidImagePath($first)) {
            return '';
        }

        return $origin.'/'.$this->images->webPath($first);
    }
}
