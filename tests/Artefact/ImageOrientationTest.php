<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\ImageStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageOrientationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/origens-orient-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    public function testOrientationSixTurnsTheSensorImageUpright(): void
    {
        $stored = $this->storeOriented(6);
        $image = imagecreatefromjpeg($stored);
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertSame(10, imagesx($image));
        self::assertSame(30, imagesy($image));
        self::assertTrue($this->isRed(imagecolorat($image, 9, 0)));
        self::assertTrue($this->isGreen(imagecolorat($image, 0, 29)));
        self::assertNotSame(6, $this->orientation($stored));
        imagedestroy($image);
    }

    public function testOrientationEightTurnsTheOtherWay(): void
    {
        $stored = $this->storeOriented(8);
        $image = imagecreatefromjpeg($stored);
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertSame(10, imagesx($image));
        self::assertSame(30, imagesy($image));
        self::assertTrue($this->isRed(imagecolorat($image, 0, 29)));
        self::assertTrue($this->isGreen(imagecolorat($image, 9, 0)));
        imagedestroy($image);
    }

    public function testOrientationThreeTurnsTheImageUpsideDown(): void
    {
        $stored = $this->storeOriented(3);
        $image = imagecreatefromjpeg($stored);
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertSame(30, imagesx($image));
        self::assertSame(10, imagesy($image));
        self::assertTrue($this->isRed(imagecolorat($image, 29, 9)));
        self::assertTrue($this->isGreen(imagecolorat($image, 0, 0)));
        imagedestroy($image);
    }

    private function storeOriented(int $orientation): string
    {
        $canvas = imagecreatetruecolor(30, 10);
        $red = imagecolorallocate($canvas, 255, 0, 0);
        $green = imagecolorallocate($canvas, 0, 255, 0);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 0, 0, 0));
        imagesetpixel($canvas, 0, 0, $red);
        imagesetpixel($canvas, 29, 9, $green);
        $raw = tempnam(sys_get_temp_dir(), 'jpg');
        self::assertIsString($raw);
        imagejpeg($canvas, $raw, 100);
        imagedestroy($canvas);
        $bytes = (string) file_get_contents($raw);
        file_put_contents($raw, $this->withOrientation($bytes, $orientation));

        $relative = (new ImageStore($this->directory))->store(new UploadedFile($raw, 'camera.jpg', 'image/jpeg', null, true));

        return $this->directory.'/'.$relative;
    }

    private function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "II*\x00\x08\x00\x00\x00"
            ."\x01\x00"
            ."\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientation)."\x00\x00"
            ."\x00\x00\x00\x00";
        $payload = "Exif\x00\x00".$tiff;

        return "\xFF\xD8"."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    private function isRed(int $color): bool
    {
        return (($color >> 16) & 255) > 200 && (($color >> 8) & 255) < 40 && ($color & 255) < 40;
    }

    private function isGreen(int $color): bool
    {
        return (($color >> 16) & 255) < 40 && (($color >> 8) & 255) > 200 && ($color & 255) < 40;
    }

    private function orientation(string $path): int
    {
        $exif = @exif_read_data($path);

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;
    }
}
