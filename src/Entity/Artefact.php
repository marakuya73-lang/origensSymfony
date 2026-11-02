<?php

declare(strict_types=1);

namespace App\Entity;

use App\Artefact\ArtefactData;
use App\Artefact\Identifier;
use App\Repository\ArtefactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArtefactRepository::class)]
#[ORM\Table(name: 'artefacts')]
class Artefact
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(length: 32, unique: true)]
    private string $code = '';

    #[ORM\Column(length: 32, unique: true)]
    private string $publicToken = '';

    #[ORM\Column(length: 1)]
    private string $size = 'M';

    #[ORM\Column]
    private int $category = 1;

    #[ORM\Column(length: 180)]
    private string $title = '';

    #[ORM\Column(length: 2000)]
    private string $description = '';

    #[ORM\Column(length: 100)]
    private string $dimensions = '';

    /** @var list<array{name: string, note: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $materials = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $care = [];

    #[ORM\Column(type: Types::TEXT)]
    private string $handmade = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $images = [];

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $recordDate;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Identifier::uuid();
        $this->createdAt = new \DateTimeImmutable();
        $this->recordDate = new \DateTimeImmutable('today');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getPublicToken(): string
    {
        return $this->publicToken;
    }

    public function setPublicToken(string $publicToken): void
    {
        $this->publicToken = $publicToken;
    }

    public function getSize(): string
    {
        return $this->size;
    }

    public function setSize(string $size): void
    {
        $this->size = $size;
    }

    public function getCategory(): int
    {
        return $this->category;
    }

    public function setCategory(int $category): void
    {
        $this->category = $category;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDimensions(): string
    {
        return $this->dimensions;
    }

    /** @return list<array{name: string, note: string}> */
    public function getMaterials(): array
    {
        return $this->materials;
    }

    /** @return list<string> */
    public function getCare(): array
    {
        return $this->care;
    }

    public function getHandmade(): string
    {
        return $this->handmade;
    }

    /** @return list<string> */
    public function getImages(): array
    {
        return $this->images;
    }

    public function getRecordDate(): \DateTimeImmutable
    {
        return $this->recordDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function createdOn(): string
    {
        return $this->createdAt
            ->setTimezone(new \DateTimeZone('America/Sao_Paulo'))
            ->format('d/m/Y');
    }

    public function apply(ArtefactData $data): void
    {
        $this->title = $data->title;
        $this->description = $data->description;
        $this->dimensions = $data->dimensions;
        $this->materials = $data->materials;
        $this->care = $data->care;
        $this->handmade = $data->handmade;
        $this->images = array_values($data->images);
        $this->recordDate = $data->recordDate;
    }
}
