<?php

declare(strict_types=1);

namespace App\Twig;

use App\Artefact\ImageStore;
use Twig\Attribute\AsTwigFunction;

final class PhotoExtension
{
    public function __construct(private ImageStore $images)
    {
    }

    #[AsTwigFunction('photo_src')]
    public function src(string $webPath, int $size): string
    {
        return $this->images->sizedWebPath($webPath, $size);
    }
}
