<?php

declare(strict_types=1);

namespace App\Artefact;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageStore
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private string $directory)
    {
    }

    /** @param list<mixed> $files
     *  @return list<string>
     */
    public function storeMany(array $files): array
    {
        $accepted = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || UPLOAD_ERR_NO_FILE === $file->getError()) {
                continue;
            }
            $this->assertAcceptable($file);
            $accepted[] = $file;
        }

        $paths = [];
        foreach ($accepted as $file) {
            $paths[] = $this->store($file);
        }

        return $paths;
    }

    public function store(UploadedFile $file): string
    {
        $this->assertAcceptable($file);
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new InvalidArtefact('Use fotos JPG, PNG ou WebP de até 10 MB.'),
        };

        $this->ensureDirectory($this->directory);
        $folder = Identifier::uuid();
        $folderPath = $this->directory.'/'.$folder;
        $this->ensureDirectory($folderPath);

        $name = Identifier::uuid().'.'.$extension;
        $full = $folderPath.'/'.$name;
        $file->move($folderPath, $name);
        if ($extension === 'jpg') {
            $this->normalizeJpegOrientation($full);
        }
        $this->reprocess($full);

        return $folder.'/'.$name;
    }

    public function reprocess(string $path): void
    {
        $image = $this->loadGd($path);
        if (!$image instanceof \GdImage) {
            return;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) > 2000) {
            $backup = $path.'.original';
            if (!is_file($backup)) {
                copy($path, $backup);
            }
            $scaled = $this->scale($image, 2000);
            $this->saveGd($scaled, $path);
            imagedestroy($image);
            $image = $scaled;
            $width = imagesx($image);
            $height = imagesy($image);
        }
        $this->writeVariant($image, $path, 1200, $width, $height);
        $this->writeVariant($image, $path, 400, $width, $height);
        imagedestroy($image);
    }

    public function geminiJpeg(string $relative): string
    {
        $full = $this->absolute($relative);
        if ($full === null) {
            throw new InvalidArtefact('Envie pelo menos uma foto da peça antes de gerar o perfil.');
        }
        $image = $this->loadGd($full);
        if (!$image instanceof \GdImage) {
            return $this->read($relative);
        }
        $scaled = $this->scale($image, 1024);
        if ($scaled !== $image) {
            imagedestroy($image);
        }
        ob_start();
        imagejpeg($scaled, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($scaled);
        if (!is_string($bytes) || $bytes === '') {
            return $this->read($relative);
        }

        return $bytes;
    }

    public function sizedWebPath(string $webPath, int $size): string
    {
        $prefix = 'uploads/artefacts/';
        $relative = str_starts_with($webPath, $prefix) ? substr($webPath, strlen($prefix)) : $webPath;
        $info = pathinfo($relative);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'].'/';
        $variant = $dir.$info['filename'].'-'.$size.'.jpg';
        if (is_file($this->directory.'/'.$variant)) {
            return $prefix.$variant;
        }

        return str_starts_with($webPath, 'uploads/') ? $webPath : $prefix.$relative;
    }

    public function webPath(string $relative): string
    {
        if (!ArtefactRules::isValidImagePath($relative)) {
            throw new \InvalidArgumentException('Invalid image path.');
        }

        return 'uploads/artefacts/'.$relative;
    }

    public function read(string $relative): string
    {
        $full = $this->absolute($relative);
        if ($full === null) {
            throw new InvalidArtefact('Envie pelo menos uma foto da peça antes de gerar o perfil.');
        }

        $contents = file_get_contents($full);
        if ($contents === false || $contents === '') {
            throw new InvalidArtefact('Envie pelo menos uma foto da peça antes de gerar o perfil.');
        }

        return $contents;
    }

    public function mimeType(string $relative): string
    {
        if ($this->absolute($relative) === null) {
            throw new InvalidArtefact('Envie pelo menos uma foto da peça antes de gerar o perfil.');
        }

        return match (strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => throw new InvalidArtefact('Envie pelo menos uma foto da peça antes de gerar o perfil.'),
        };
    }

    public function exists(string $relative): bool
    {
        return $this->absolute($relative) !== null;
    }

    public function localPath(string $relative): ?string
    {
        return $this->absolute($relative);
    }

    /** @param list<string> $paths */
    public function assertExist(array $paths): void
    {
        foreach ($paths as $path) {
            if (!$this->exists($path)) {
                throw new InvalidArtefact('Uma das fotos não foi encontrada. Envie-a novamente.');
            }
        }
    }

    public function delete(string $relative): void
    {
        $full = $this->absolute($relative);
        if ($full === null) {
            return;
        }

        unlink($full);
        $folder = dirname($full);
        $root = realpath($this->directory);
        if ($root === false || $folder === $root || !is_dir($folder)) {
            return;
        }

        $remaining = array_diff(scandir($folder) ?: [], ['.', '..']);
        if ($remaining === []) {
            rmdir($folder);
        }
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new InvalidArtefact('Use fotos JPG, PNG ou WebP de até 10 MB.');
        }

        $size = $file->getSize();
        $mime = $file->getMimeType();
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArtefact('Use fotos JPG, PNG ou WebP de até 10 MB.');
        }
    }

    private function absolute(string $relative): ?string
    {
        if (!ArtefactRules::isValidImagePath($relative)) {
            return null;
        }

        $root = realpath($this->directory);
        if ($root === false) {
            return null;
        }

        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $full = realpath($candidate);
        if ($full === false || !is_file($full) || !str_starts_with($full, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $full;
    }

    private function loadGd(string $path): ?\GdImage
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        $image = @imagecreatefromstring($bytes);

        return $image instanceof \GdImage ? $image : null;
    }

    private function scale(\GdImage $image, int $longest): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $max = max($width, $height);
        if ($max <= $longest) {
            return $image;
        }
        $ratio = $longest / $max;
        $target = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
        imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

        return $target;
    }

    private function saveGd(\GdImage $image, string $path): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'png') {
            imagepng($image, $path);
            return;
        }
        if ($extension === 'webp' && function_exists('imagewebp')) {
            imagewebp($image, $path, 82);
            return;
        }
        imagejpeg($image, $path, 86);
    }

    private function writeVariant(\GdImage $image, string $path, int $longest, int $width, int $height): void
    {
        $info = pathinfo($path);
        $target = $info['dirname'].'/'.$info['filename'].'-'.$longest.'.jpg';
        $scaled = $this->scale($image, $longest);
        imagejpeg($scaled, $target, 82);
        if ($scaled !== $image) {
            imagedestroy($scaled);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidArtefact('Não foi possível enviar a foto. Tente novamente.');
        }
    }

    private function normalizeJpegOrientation(string $path): void
    {
        if (!function_exists('exif_read_data') || !function_exists('imagecreatefromjpeg') || !function_exists('imagerotate')) {
            return;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        if ($orientation < 2 || $orientation > 8) {
            return;
        }

        $image = @imagecreatefromjpeg($path);
        if ($image === false) {
            return;
        }

        $oriented = $this->orientJpeg($image, $orientation);
        imagejpeg($oriented, $path, 92);
        imagedestroy($oriented);
    }

    private function orientJpeg(\GdImage $image, int $orientation): \GdImage
    {
        return match ($orientation) {
            2 => $this->flipped($image, IMG_FLIP_HORIZONTAL),
            3 => $this->turned($image, 180),
            4 => $this->flipped($image, IMG_FLIP_VERTICAL),
            5 => $this->flipped($this->turned($image, 90), IMG_FLIP_HORIZONTAL),
            6 => $this->turned($image, -90),
            7 => $this->flipped($this->turned($image, -90), IMG_FLIP_HORIZONTAL),
            8 => $this->turned($image, 90),
            default => $image,
        };
    }

    private function turned(\GdImage $image, float $angle): \GdImage
    {
        $result = imagerotate($image, $angle, 0);
        if (!$result instanceof \GdImage) {
            return $image;
        }
        if ($result !== $image) {
            imagedestroy($image);
        }

        return $result;
    }

    private function flipped(\GdImage $image, int $mode): \GdImage
    {
        imageflip($image, $mode);

        return $image;
    }
}
