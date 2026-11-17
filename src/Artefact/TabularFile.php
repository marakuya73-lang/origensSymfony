<?php

declare(strict_types=1);

namespace App\Artefact;

final class TabularFile
{
    /**
     * @param list<string>        $headers
     * @param list<list<string>>  $rows
     */
    public function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível gerar o CSV.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        $this->csvRow($handle, $headers);
        foreach ($rows as $row) {
            $this->csvRow($handle, $row);
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);
        if ($contents === false) {
            throw new \RuntimeException('Não foi possível gerar o CSV.');
        }

        return $contents;
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function xlsx(array $headers, array $rows, ?int $priceColumn = null): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Não foi possível gerar a planilha XLSX.');
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new \RuntimeException('Não foi possível gerar a planilha XLSX.');
        }
        unlink($path);

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Não foi possível gerar a planilha XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($headers, $rows, $priceColumn));
        $zip->close();

        $contents = file_get_contents($path);
        unlink($path);
        if ($contents === false) {
            throw new \RuntimeException('Não foi possível gerar a planilha XLSX.');
        }

        return $contents;
    }

    /** @param resource $handle
     *  @param list<string> $row
     */
    private function csvRow($handle, array $row): void
    {
        fputcsv($handle, array_map($this->plain(...), $row), ';', '"', '', "\r\n");
    }

    private function plain(string $value): string
    {
        $value = $this->xmlText($value);
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private function sheet(array $headers, array $rows, ?int $priceColumn): string
    {
        $lines = [$headers, ...$rows];
        $totalRow = $priceColumn !== null && $rows !== [] ? count($lines) : null;
        $xml = [];
        foreach ($lines as $index => $line) {
            $excelRow = $index + 1;
            $cells = [];
            foreach (array_values($line) as $column => $value) {
                $ref = $this->column($column).$excelRow;
                if ($priceColumn === $column && $totalRow !== null && $excelRow === $totalRow) {
                    $letter = $this->column($column);
                    $amount = $this->invariantAmount($value);
                    $cells[] = '<c r="'.$ref.'" s="1"><f>SUM('.$letter.'2:'.$letter.($totalRow - 1).')</f><v>'.$amount.'</v></c>';
                    continue;
                }
                if ($priceColumn === $column && $totalRow !== null && $excelRow > 1 && $excelRow < $totalRow && $value !== '') {
                    $cents = SalePrice::cents($value);
                    if ($cents !== null) {
                        $cells[] = '<c r="'.$ref.'" s="1"><v>'.$this->invariantAmount(SalePrice::excel($cents)).'</v></c>';
                        continue;
                    }
                }
                $cells[] = '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape($this->plain($value)).'</t></is></c>';
            }
            $xml[] = '<row r="'.$excelRow.'">'.implode('', $cells).'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .implode('', $xml)
            .'</sheetData></worksheet>';
    }

    private function invariantAmount(string $formatted): string
    {
        $cents = SalePrice::cents($formatted) ?? 0;

        return number_format($cents / 100, 2, '.', '');
    }

    private function column(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            --$index;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xmlText(string $value): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value);

        return $clean ?? $value;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Artefactos" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;R$&quot;\ #,##0.00"/></numFmts>'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            .'</styleSheet>';
    }
}
