<?php

declare(strict_types=1);

namespace App\Artefact;

use App\Entity\Artefact;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SellerTable
{
    public function __construct(
        private ImageStore $images,
        #[Autowire('%kernel.project_dir%/public/favicon.png')]
        private string $logoPath = '',
    ) {
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
                $artefact->getSymbolism(),
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
            'Simbologia',
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

    /**
     * @param list<Artefact> $artefacts
     */
    public function cataloguePdf(array $artefacts): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->catalogueHtml($artefacts));
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    /**
     * @param list<Artefact> $artefacts
     */
    public function catalogueHtml(array $artefacts): string
    {
        $rows = '';
        $total = 0;
        foreach ($artefacts as $artefact) {
            $cents = $artefact->getSalePriceCents();
            if ($cents !== null) {
                $total += $cents;
            }
            $photo = $this->thumbnailDataUri($artefact);
            $image = $photo !== null ? '<img class="thumb" src="'.$photo.'" alt="">' : '';
            $rows .= '<tr>'
                .'<td class="photo">'.$image.'</td>'
                .'<td class="name">'.$this->h($this->pieceName($artefact)).'</td>'
                .'<td>'.$this->h($this->categoryLabel($artefact)).'</td>'
                .'<td class="center">'.$this->h($artefact->getSize()).'</td>'
                .'<td class="price">'.$this->h(SalePrice::brl($cents)).'</td>'
                .'</tr>';
        }
        $count = count($artefacts);
        $logo = $this->logoDataUri();
        $mark = $logo !== null ? '<img class="logo" src="'.$logo.'" alt="">' : '';

        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><style>'
            .'@page { margin: 16mm 14mm 16mm; }'
            .'body { margin: 0; color: #3c3428; font-family: DejaVu Sans, sans-serif; font-size: 11px; }'
            .'.top { margin-bottom: 22px; }'
            .'.logo { height: 58px; }'
            .'h1 { margin: 10px 0 0; font-family: DejaVu Serif, serif; font-weight: normal; font-size: 28px; letter-spacing: 0.18em; }'
            .'.date { margin: 4px 0 0; color: #6d6456; letter-spacing: 0.08em; font-size: 10px; }'
            .'table { width: 100%; border-collapse: collapse; }'
            .'thead { display: table-header-group; }'
            .'tr, thead, tfoot { page-break-inside: avoid; }'
            .'th { padding: 8px 10px 10px; border-bottom: 1px solid #c9bfae; font-size: 8px; letter-spacing: 0.16em; text-transform: uppercase; text-align: left; font-weight: normal; color: #6d6456; }'
            .'td { padding: 12px 10px; border-bottom: 1px solid #e6dfd2; vertical-align: middle; }'
            .'.thumb { width: 64px; height: auto; }'
            .'.photo { width: 78px; }'
            .'.name { font-family: DejaVu Serif, serif; font-size: 14px; }'
            .'.center { text-align: center; }'
            .'.price, th.price { text-align: right; white-space: nowrap; }'
            .'tfoot td { border-bottom: 0; padding-top: 16px; font-family: DejaVu Serif, serif; font-size: 13px; }'
            .'</style></head><body>'
            .'<div class="top">'.$mark.'<h1>ORIGENS</h1><p class="date">'.$this->h($this->todayLabel()).'</p></div>'
            .'<table><thead><tr><th>Foto</th><th>Nome</th><th>Categoria</th><th class="center">Tamanho</th><th class="price">Preço</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody>'
            .'<tfoot><tr><td colspan="4">'.$count.' abanilhos</td><td class="price">'.$this->h(SalePrice::brl($total)).'</td></tr></tfoot>'
            .'</table></body></html>';
    }

    /**
     * @param list<Artefact> $artefacts
     */
    public function catalogueXlsx(array $artefacts): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Artefactos');
        foreach (['Foto', 'Nome', 'Categoria', 'Tamanho', 'Preço'] as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }
        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(16);
        $format = '"R$" #,##0.00';
        $temporary = [];
        $count = 0;
        foreach ($artefacts as $artefact) {
            ++$count;
            $row = $count + 1;
            $photo = $this->thumbnailFile($artefact);
            if ($photo !== null) {
                $temporary[] = $photo;
                $sheet->getRowDimension($row)->setRowHeight(64);
                $drawing = new Drawing();
                $drawing->setPath($photo);
                $drawing->setHeight(80);
                $drawing->setCoordinates('A'.$row);
                $drawing->setOffsetX(6);
                $drawing->setOffsetY(4);
                $drawing->setWorksheet($sheet);
            }
            $sheet->setCellValue('B'.$row, $this->pieceName($artefact));
            $sheet->setCellValue('C'.$row, $this->categoryLabel($artefact));
            $sheet->setCellValue('D'.$row, $artefact->getSize());
            $cents = $artefact->getSalePriceCents();
            if ($cents !== null) {
                $sheet->setCellValue('E'.$row, $cents / 100);
                $sheet->getStyle('E'.$row)->getNumberFormat()->setFormatCode($format);
            }
        }
        $totalRow = $count + 2;
        $sheet->setCellValue('B'.$totalRow, $count.' abanilhos');
        $sheet->setCellValue('E'.$totalRow, $count === 0 ? 0 : '=SUM(E2:E'.($totalRow - 1).')');
        $sheet->getStyle('E'.$totalRow)->getNumberFormat()->setFormatCode($format);

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new \RuntimeException('Não foi possível gerar a planilha XLSX.');
        }
        (new Xlsx($spreadsheet))->save($path);
        foreach ($temporary as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $contents = file_get_contents($path);
        unlink($path);
        if ($contents === false) {
            throw new \RuntimeException('Não foi possível gerar a planilha XLSX.');
        }

        return $contents;
    }

    private function pieceName(Artefact $artefact): string
    {
        return $artefact->getTitle() !== '' ? $artefact->getTitle() : 'Peça em registro';
    }

    private function categoryLabel(Artefact $artefact): string
    {
        return ArtefactRules::CATEGORY_LABELS[$artefact->getCategory()] ?? (string) $artefact->getCategory();
    }

    private function todayLabel(): string
    {
        $months = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        $today = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));

        return $today->format('j').' de '.$months[(int) $today->format('n')].' de '.$today->format('Y');
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function logoDataUri(): ?string
    {
        if ($this->logoPath === '' || !is_file($this->logoPath)) {
            return null;
        }
        $bytes = file_get_contents($this->logoPath);

        return is_string($bytes) && $bytes !== '' ? 'data:image/png;base64,'.base64_encode($bytes) : null;
    }

    private function thumbnailDataUri(Artefact $artefact): ?string
    {
        $jpeg = $this->thumbnailJpeg($artefact);

        return $jpeg === null ? null : 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    private function thumbnailFile(Artefact $artefact): ?string
    {
        $jpeg = $this->thumbnailJpeg($artefact);
        if ($jpeg === null) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'org');
        if ($path === false || file_put_contents($path, $jpeg) === false) {
            return null;
        }

        return $path;
    }

    private function thumbnailJpeg(Artefact $artefact): ?string
    {
        $first = $artefact->getImages()[0] ?? null;
        if (!is_string($first)) {
            return null;
        }
        $full = $this->images->localPath($first);
        if ($full === null) {
            return null;
        }
        $bytes = file_get_contents($full);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof \GdImage) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            imagedestroy($image);

            return null;
        }
        $scale = min(80 / $width, 80 / $height, 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($thumb === false) {
            imagedestroy($image);

            return null;
        }
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);
        ob_start();
        imagejpeg($thumb, null, 82);
        imagedestroy($thumb);
        $jpeg = ob_get_clean();

        return is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
    }
}
