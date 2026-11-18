<?php

declare(strict_types=1);

namespace App\Entity;

use App\Artefact\Identifier;
use App\Repository\ProfileJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProfileJobRepository::class)]
#[ORM\Table(name: 'profile_jobs')]
#[ORM\Index(name: 'IDX_profile_jobs_due', columns: ['status', 'run_at'])]
class ProfileJob
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(length: 16)]
    private string $status = 'queued';

    #[ORM\Column(length: 16)]
    private string $stage = 'queued';

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $result = null;

    #[ORM\Column(length: 255)]
    private string $imagePath;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $audioPath = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $audioMime = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $notes = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $answer = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $history = '[]';

    #[ORM\Column(type: Types::TEXT)]
    private string $form = '{}';

    #[ORM\Column(type: Types::TEXT)]
    private string $touched = '[]';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $runAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $imagePath)
    {
        $this->id = Identifier::uuid();
        $this->imagePath = $imagePath;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->runAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function setStage(string $stage): void
    {
        $this->stage = $stage;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): void
    {
        $this->result = $result;
    }

    public function getImagePath(): string
    {
        return $this->imagePath;
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

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function setNotes(string $notes): void
    {
        $this->notes = $notes;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    public function setAnswer(string $answer): void
    {
        $this->answer = $answer;
    }

    public function getHistory(): string
    {
        return $this->history;
    }

    public function setHistory(string $history): void
    {
        $this->history = $history;
    }

    public function getForm(): string
    {
        return $this->form;
    }

    public function setForm(string $form): void
    {
        $this->form = $form;
    }

    public function getTouched(): string
    {
        return $this->touched;
    }

    public function setTouched(string $touched): void
    {
        $this->touched = $touched;
    }

    public function getRunAt(): \DateTimeImmutable
    {
        return $this->runAt;
    }

    public function setRunAt(\DateTimeImmutable $runAt): void
    {
        $this->runAt = $runAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
