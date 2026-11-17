<?php

declare(strict_types=1);

namespace App\Artefact;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AudioClip
{
    public const REJECTED = 'Use um áudio curto em WebM, MP3, M4A, WAV ou OGG de até 10 MB.';

    private const MAX_BYTES = 10 * 1024 * 1024;

    /** @var array<string, string> */
    private const MIME = [
        'audio/webm' => 'audio/webm',
        'video/webm' => 'audio/webm',
        'audio/ogg' => 'audio/ogg',
        'application/ogg' => 'audio/ogg',
        'audio/opus' => 'audio/opus',
        'audio/mpeg' => 'audio/mpeg',
        'audio/mp3' => 'audio/mp3',
        'audio/mp4' => 'audio/m4a',
        'video/mp4' => 'audio/m4a',
        'audio/m4a' => 'audio/m4a',
        'audio/x-m4a' => 'audio/m4a',
        'audio/aac' => 'audio/aac',
        'audio/wav' => 'audio/wav',
        'audio/x-wav' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/flac' => 'audio/flac',
        'audio/x-flac' => 'audio/flac',
    ];

    /** @var array<string, string> */
    private const EXTENSIONS = [
        'webm' => 'audio/webm',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/opus',
        'mp3' => 'audio/mp3',
        'mpeg' => 'audio/mpeg',
        'm4a' => 'audio/m4a',
        'mp4' => 'audio/m4a',
        'aac' => 'audio/aac',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
    ];

    /** @return array{0: string, 1: string}|null */
    public static function read(mixed $file): ?array
    {
        if (!$file instanceof UploadedFile || UPLOAD_ERR_NO_FILE === $file->getError()) {
            return null;
        }

        $size = $file->getSize();
        if (!$file->isValid() || $size === false || $size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArtefact(self::REJECTED);
        }

        $mime = self::mime($file);
        if ($mime === null) {
            throw new InvalidArtefact(self::REJECTED);
        }

        return [$file->getContent(), $mime];
    }

    private static function mime(UploadedFile $file): ?string
    {
        $candidates = [
            $file->getMimeType() ?: '',
            $file->getClientMimeType() ?: '',
        ];
        foreach ($candidates as $candidate) {
            $normalized = strtolower(trim(strtok($candidate, ';') ?: ''));
            if (isset(self::MIME[$normalized])) {
                return self::MIME[$normalized];
            }
        }

        $extension = strtolower($file->getClientOriginalExtension());

        return self::EXTENSIONS[$extension] ?? null;
    }
}
