<?php

declare(strict_types=1);

namespace App\Artefact;

use App\Entity\Artefact;

final class SellerTable
{
    public function __construct(private ImageStore $images)
    {
    }

    /**
     * @param list<Artefact> $artefacts
     *
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function build(array $artefacts, string $origin): array
    {
        $rows = [];
        foreach ($artefacts as $artefact) {
            $token = $artefact->getPublicToken();
            $rows[] = [
                $artefact->getCode(),
                $artefact->getTitle(),
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

        return ['headers' => self::headers(), 'rows' => $rows];
    }

    /** @return list<string> */
    public static function headers(): array
    {
        return [
            'Referência',
            'Nome',
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

    private function imageUrl(Artefact $artefact, string $origin): string
    {
        $first = $artefact->getImages()[0] ?? null;
        if (!is_string($first) || !ArtefactRules::isValidImagePath($first)) {
            return '';
        }

        return $origin.'/'.$this->images->webPath($first);
    }
}
