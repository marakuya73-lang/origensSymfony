<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\TabularFile;
use PHPUnit\Framework\TestCase;

final class TabularFileTest extends TestCase
{
    public function testCsvIsUtf8WithBomAndExcelSeparator(): void
    {
        $csv = (new TabularFile())->csv(
            ['Nome', 'Notas'],
            [["Cerâmica", "fibra; \"seca\"\nà sombra"], ['=SOMA(1)']],
        );

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('Cerâmica;"fibra; ""seca""', $csv);
        self::assertStringContainsString("\nà sombra", $csv);
        self::assertStringContainsString("'=SOMA(1)", $csv);
        self::assertStringNotContainsString(',', explode("\r\n", $csv)[0]);
    }

    public function testXlsxContainsTheTextInTheWorksheet(): void
    {
        $xlsx = (new TabularFile())->xlsx(['Nome'], [['Cerâmica de coração']]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        self::assertIsString($path);
        file_put_contents($path, $xlsx);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        self::assertIsString($sheet);
        self::assertStringContainsString('Cerâmica de coração', $sheet);
        self::assertStringContainsString('Nome', $sheet);
    }
}
