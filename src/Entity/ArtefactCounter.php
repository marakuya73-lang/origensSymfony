<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'artefact_counters')]
class ArtefactCounter
{
    #[ORM\Id]
    #[ORM\Column(length: 1)]
    private string $size = '';

    #[ORM\Id]
    #[ORM\Column]
    private int $category = 0;

    #[ORM\Column]
    private int $lastNumber = 0;
}
