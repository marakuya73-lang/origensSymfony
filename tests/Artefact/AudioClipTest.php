<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\AudioClip;
use App\Artefact\InvalidArtefact;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AudioClipTest extends TestCase
{
    public function testReadsAWavClip(): void
    {
        $clip = AudioClip::read($this->wav());

        self::assertIsArray($clip);
        self::assertNotSame('', $clip[0]);
        self::assertSame('audio/wav', $clip[1]);
    }

    public function testRejectsAFileThatIsNotAudio(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        self::assertIsString($path);
        file_put_contents($path, 'não é áudio');

        $this->expectException(InvalidArtefact::class);
        $this->expectExceptionMessage(AudioClip::REJECTED);
        AudioClip::read(new UploadedFile($path, 'nota.txt', 'text/plain', null, true));
    }

    public function testMissingUploadIsOptional(): void
    {
        self::assertNull(AudioClip::read(null));
    }

    private function wav(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wav');
        self::assertIsString($path);
        $header = 'RIFF'.pack('V', 36).'WAVEfmt '.pack('V', 16).pack('v', 1).pack('v', 1).pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8).'data'.pack('V', 0);
        file_put_contents($path, $header);

        return new UploadedFile($path, 'descricao.wav', 'audio/wav', null, true);
    }
}
