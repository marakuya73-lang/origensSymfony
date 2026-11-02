<?php

declare(strict_types=1);

namespace App\Artefact;

final class Submission
{
    public function __construct(
        public ArtefactDraft $draft,
        public ?ArtefactData $data,
        public ?string $error,
    ) {
    }

    public function ok(): bool
    {
        return $this->data instanceof ArtefactData && $this->error === null;
    }
}
