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
        $file->move($folderPath, $name);

        return $folder.'/'.$name;
    }

    public function webPath(string $relative): string
    {
        if (!ArtefactRules::isValidImagePath($relative)) {
            throw new \InvalidArgumentException('Invalid image path.');
        }

        return 'uploads/artefacts/'.$relative;
    }

    public function exists(string $relative): bool
    {
        return $this->absolute($relative) !== null;
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

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidArtefact('Não foi possível enviar a foto. Tente novamente.');
        }
    }
}
