<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\ImageStore;
use App\Command\OrigensImagesResizeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImageResizeTest extends KernelTestCase
{
    public function testALargePhotoIsStoredAt2000AndHasSmallerSizes(): void
    {
        $directory = sys_get_temp_dir().'/origens-resize-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $source = $directory.'/wide.jpg';
        $canvas = imagecreatetruecolor(3000, 1000);
        imagejpeg($canvas, $source, 90);
        imagedestroy($canvas);

        (new ImageStore($directory))->reprocess($source);

        $main = imagecreatefromjpeg($source);
        self::assertInstanceOf(\GdImage::class, $main);
        self::assertSame(2000, imagesx($main));
        self::assertSame(667, imagesy($main));
        imagedestroy($main);
        self::assertFileExists($source.'.original');
        $medium = imagecreatefromjpeg($directory.'/wide-1200.jpg');
        $thumb = imagecreatefromjpeg($directory.'/wide-400.jpg');
        self::assertInstanceOf(\GdImage::class, $medium);
        self::assertInstanceOf(\GdImage::class, $thumb);
        self::assertSame(1200, imagesx($medium));
        self::assertSame(400, imagesx($thumb));
        imagedestroy($medium);
        imagedestroy($thumb);
    }

    public function testTheResizeCommandProcessesAnExistingPhoto(): void
    {
        self::bootKernel();
        $uploads = static::getContainer()->getParameter('artefact_upload_dir');
        self::assertIsString($uploads);
        $folder = $uploads.'/cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        if (!is_dir($folder)) {
            mkdir($folder, 0775, true);
        }
        $path = $folder.'/dddddddd-dddd-4ddd-8ddd-dddddddddddd.jpg';
        $canvas = imagecreatetruecolor(2400, 800);
        imagejpeg($canvas, $path, 80);
        imagedestroy($canvas);

        $command = static::getContainer()->get(OrigensImagesResizeCommand::class);
        self::assertInstanceOf(OrigensImagesResizeCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));

        $image = imagecreatefromjpeg($path);
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertLessThanOrEqual(2000, imagesx($image));
        imagedestroy($image);
        self::assertFileExists($folder.'/dddddddd-dddd-4ddd-8ddd-dddddddddddd-400.jpg');
        self::assertFileExists($folder.'/dddddddd-dddd-4ddd-8ddd-dddddddddddd-1200.jpg');
    }
}
