<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\ArchiveSnapshot;
use App\Artefact\ArtefactData;
use App\Artefact\ArtefactRegistrar;
use App\Command\OrigensRestoreCommand;
use App\Entity\Artefact;
use App\Tests\SchemaBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ArchiveSnapshotTest extends KernelTestCase
{
    private const IMAGE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        SchemaBuilder::rebuild(static::getContainer()->get(EntityManagerInterface::class));
        $photo = static::getContainer()->getParameter('artefact_upload_dir').'/'.self::IMAGE;
        self::assertIsString($photo);
        $folder = dirname($photo);
        if (!is_dir($folder)) {
            mkdir($folder, 0775, true);
        }
        file_put_contents($photo, 'foto-da-peca');
    }

    public function testTheZipHoldsTheDatabasePhotosSpreadsheetAndReadme(): void
    {
        $created = $this->registrar()->create($this->data('Peça guardada', 250050, 'A casa fica em paz.'));
        $zipPath = $this->snapshots()->temporaryDownload();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));
        $names = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = $zip->getNameIndex($index);
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        self::assertContains('database.sqlite', $names);
        self::assertContains('README.txt', $names);
        self::assertContains('artefactos.csv', $names);
        self::assertContains('artefactos.xlsx', $names);
        self::assertContains('photos/'.self::IMAGE, $names);
        $csv = (string) $zip->getFromName('artefactos.csv');
        self::assertStringContainsString('Peça guardada', $csv);
        self::assertStringContainsString('A casa fica em paz.', $csv);
        self::assertStringContainsString($created->getPublicToken(), $csv);
        self::assertStringContainsString('2.500,50', $csv);
        self::assertStringContainsString('origens:restore', (string) $zip->getFromName('README.txt'));
        $zip->close();
        unlink($zipPath);
    }

    public function testRestoreRefusesWithoutForceAndThenKeepsThePermanentLink(): void
    {
        $created = $this->registrar()->create($this->data('Peça original', null, ''));
        $token = $created->getPublicToken();
        $zipPath = $this->snapshots()->temporaryDownload();
        $this->registrar()->update($created, $this->data('Peça alterada', null, 'Outro sentido.'));

        $tester = new CommandTester($this->restore());
        self::assertSame(1, $tester->execute(['zip' => $zipPath]));
        self::assertStringContainsString('--force', $tester->getDisplay());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $still = $em->find(Artefact::class, $created->getId());
        self::assertInstanceOf(Artefact::class, $still);
        self::assertSame('Peça alterada', $still->getTitle());

        self::assertSame(0, $tester->execute(['zip' => $zipPath, '--force' => true]));
        $em->clear();
        $em->getConnection()->close();
        $restored = $em->find(Artefact::class, $created->getId());
        self::assertInstanceOf(Artefact::class, $restored);
        self::assertSame('Peça original', $restored->getTitle());
        self::assertSame($token, $restored->getPublicToken());
        $photo = static::getContainer()->getParameter('artefact_upload_dir').'/'.self::IMAGE;
        self::assertIsString($photo);
        unlink($photo);
        self::assertSame(0, $tester->execute(['zip' => $zipPath, '--force' => true]));
        self::assertSame('foto-da-peca', (string) file_get_contents($photo));
        unlink($zipPath);
    }

    private function registrar(): ArtefactRegistrar
    {
        $registrar = static::getContainer()->get(ArtefactRegistrar::class);
        self::assertInstanceOf(ArtefactRegistrar::class, $registrar);

        return $registrar;
    }

    private function snapshots(): ArchiveSnapshot
    {
        $snapshots = static::getContainer()->get(ArchiveSnapshot::class);
        self::assertInstanceOf(ArchiveSnapshot::class, $snapshots);

        return $snapshots;
    }

    private function restore(): OrigensRestoreCommand
    {
        $command = static::getContainer()->get(OrigensRestoreCommand::class);
        self::assertInstanceOf(OrigensRestoreCommand::class, $command);

        return $command;
    }

    private function data(string $title, ?int $cents, string $symbolism): ArtefactData
    {
        return ArtefactData::fromSubmitted(
            $title,
            'M',
            1,
            'Descrição da peça.',
            '20 cm',
            [['name' => 'Palha', 'note' => 'Trançada.']],
            'Guardar seco.',
            'Feito à mão com calma.',
            [self::IMAGE],
            '2026-10-18',
            $cents,
            $symbolism,
        );
    }
}
