<?php

declare(strict_types=1);

namespace App\Entity;

use App\Artefact\ArtefactData;
use App\Artefact\ArtefactRules;
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

    #[ORM\Column(length: ArtefactRules::DESCRIPTION_MAX)]
    private string $description = '';

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $symbolism = '';

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

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $salePriceCents = null;

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $notes = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $audioPath = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $audioMime = null;

    #[ORM\Column(length: 16, options: ['default' => ''])]
    private string $aiStatus = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $manualFields = [];

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
        if ($this->publicToken !== '' && $this->publicToken !== $publicToken) {
            throw new \LogicException('A public link is stored once and cannot be replaced.');
        }
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

    public function getSymbolism(): string
    {
        return $this->symbolism;
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

    public function getSalePriceCents(): ?int
    {
        return $this->salePriceCents;
    }

    public function createdOn(): string
    {
        return $this->createdAt
            ->setTimezone(new \DateTimeZone('America/Sao_Paulo'))
            ->format('d/m/Y');
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function setNotes(string $notes): void
    {
        $this->notes = $notes;
    }

    public function getAudioPath(): ?string
    {
        return $this->audioPath;
    }

    public function setAudioPath(?string $audioPath): void
    {
        $this->audioPath = $audioPath;
    }

    public function getAudioMime(): ?string
    {
        return $this->audioMime;
    }

    public function setAudioMime(?string $audioMime): void
    {
        $this->audioMime = $audioMime;
    }

    public function getAiStatus(): string
    {
        return $this->aiStatus;
    }

    public function setAiStatus(string $aiStatus): void
    {
        $this->aiStatus = $aiStatus;
    }

    public function aiLabel(): string
    {
        return match ($this->aiStatus) {
            'queued' => 'aguardando IA',
            'running' => 'gerando',
            'done' => 'pronto',
            'failed' => 'falhou',
            default => '',
        };
    }

    /** @return list<string> */
    public function getManualFields(): array
    {
        return $this->manualFields;
    }

    public function isManual(string $field): bool
    {
        return in_array($field, $this->manualFields, true);
    }

    public function apply(ArtefactData $data): void
    {
        $this->title = $data->title;
        $this->size = $data->size;
        $this->category = $data->category;
        $this->description = $data->description;
        $this->symbolism = $data->symbolism;
        $this->dimensions = $data->dimensions;
        $this->materials = $data->materials;
        $this->care = $data->care;
        $this->handmade = $data->handmade;
        $this->images = array_values($data->images);
        $this->recordDate = $data->recordDate;
        $this->salePriceCents = $data->salePriceCents;
    }

    /** @param list<string> $touched */
    public function absorb(ArtefactData $data, array $touched): void
    {
        $this->apply(new ArtefactData(
            $this->chooseText($this->title, $data->title, 'title', $touched),
            $data->size,
            $data->category,
            $this->chooseText($this->description, $data->description, 'description', $touched),
            $data->dimensions,
            $this->chooseMaterials($data->materials, $touched),
            $data->care,
            $data->handmade,
            $data->images,
            $data->recordDate,
            $data->salePriceCents,
            $this->chooseText($this->symbolism, $data->symbolism, 'symbolism', $touched),
        ));
    }

    public function fillFromProfile(\App\Artefact\GeneratedProfile $profile): void
    {
        if ($this->title === '' || !$this->isManual('title')) {
            $this->title = $profile->title;
            $this->unlock('title');
        }
        if ($this->description === '' || !$this->isManual('description')) {
            $this->description = $profile->description;
            $this->unlock('description');
        }
        if ($this->symbolism === '' || !$this->isManual('symbolism')) {
            $this->symbolism = $profile->symbolism;
            $this->unlock('symbolism');
        }
        if (($this->materials === [] || !$this->isManual('materials')) && $profile->materials !== []) {
            $this->materials = $profile->materials;
            $this->unlock('materials');
        }
    }

    /** @param list<string> $touched */
    private function chooseText(string $stored, string $incoming, string $field, array $touched): string
    {
        $touchedField = in_array($field, $touched, true);
        if ($incoming === '' && $stored !== '' && !$touchedField) {
            return $stored;
        }
        if ($touchedField || ($incoming !== '' && $incoming !== $stored)) {
            if ($incoming === '') {
                $this->unlock($field);
            } else {
                $this->lock($field);
            }
        }

        return $incoming;
    }

    /**
     * @param list<array{name: string, note: string}> $incoming
     * @param list<string>                            $touched
     *
     * @return list<array{name: string, note: string}>
     */
    private function chooseMaterials(array $incoming, array $touched): array
    {
        $touchedField = in_array('materials', $touched, true);
        if ($incoming === [] && $this->materials !== [] && !$touchedField) {
            return $this->materials;
        }
        if ($touchedField || $incoming !== $this->materials) {
            if ($incoming === []) {
                $this->unlock('materials');
            } elseif ($incoming !== $this->materials) {
                $this->lock('materials');
            }
        }

        return $incoming;
    }

    private function lock(string $field): void
    {
        if (!$this->isManual($field)) {
            $this->manualFields[] = $field;
        }
    }

    private function unlock(string $field): void
    {
        $this->manualFields = array_values(array_diff($this->manualFields, [$field]));
    }
}
