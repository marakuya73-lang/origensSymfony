<?php

declare(strict_types=1);

namespace App\Artefact;

use App\Entity\Artefact;
use App\Entity\ProfileJob;
use App\Repository\ArtefactRepository;
use App\Repository\ProfileJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ProfileJobRunner
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProfileJobRepository $jobs,
        private ArtefactRepository $artefacts,
        private ImageStore $images,
        private GeminiProfileGenerator $profiles,
        #[Autowire('%artefact_upload_dir%')]
        private string $uploads,
    ) {
    }

    public function executeId(string $id): void
    {
        $this->releaseStale();
        $job = $this->jobs->find($id);
        if (!$job instanceof ProfileJob || $job->getStatus() !== 'queued' || $job->getRunAt() > new \DateTimeImmutable()) {
            $this->logLine($id.' skip status='.($job instanceof ProfileJob ? $job->getStatus() : 'missing'));

            return;
        }
        $this->execute($job);
    }

    public function kick(string $id): bool
    {
        $this->releaseStale();
        $job = $this->jobs->find($id);
        if (!$job instanceof ProfileJob || $job->getStatus() !== 'queued' || $job->getRunAt() > new \DateTimeImmutable()) {
            return false;
        }
        if ($job->getCreatedAt() > new \DateTimeImmutable('-5 seconds')) {
            return false;
        }
        $this->logLine($id.' kick');
        $this->execute($job);

        return true;
    }

    public function releaseStale(): int
    {
        $released = $this->jobs->releaseStale(new \DateTimeImmutable('-2 minutes'));
        if ($released > 0) {
            $this->em->getConnection()->executeStatement(
                "UPDATE artefacts SET ai_status = 'queued' WHERE ai_status = 'running' AND id IN (SELECT artefact_id FROM profile_jobs WHERE status = 'queued' AND artefact_id IS NOT NULL)",
            );
        }

        return $released;
    }

    public function detachWebRequest(): void
    {
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();

            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    public function runDue(int $limit, int $seconds): int
    {
        $this->releaseStale();
        $this->logLine('command queued='.$this->jobs->countStatus('queued').' running='.$this->jobs->countStatus('running'));
        $deadline = time() + max(1, $seconds);
        $done = 0;
        while ($done < $limit && time() < $deadline) {
            $job = $this->jobs->nextDue(new \DateTimeImmutable());
            if (!$job instanceof ProfileJob) {
                break;
            }
            $this->execute($job);
            ++$done;
        }

        return $done;
    }

    public function execute(ProfileJob $job): void
    {
        $claimed = $this->em->getConnection()->executeStatement(
            'UPDATE profile_jobs SET status = :running, updated_at = :now WHERE id = :id AND status = :queued',
            [
                'running' => 'running',
                'queued' => 'queued',
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $job->getId(),
            ],
        );
        if ($claimed !== 1) {
            $this->logLine($job->getId().' skip claim');

            return;
        }
        $this->em->refresh($job);
        $job->setAttempts($job->getAttempts() + 1);
        $job->setStage($job->getAudioPath() !== null ? 'listening' : 'looking');
        $this->markArtefact($job, 'running');
        $this->em->flush();
        $started = microtime(true);
        $this->log($job, 'start', $started);
        $this->profiles->onAttempt(function (string $model, int $status, string $detail) use ($job, $started): void {
            $this->log($job, $model.' '.$status.' '.$detail, $started);
        });

        try {
            $jpeg = $this->images->geminiJpeg($job->getImagePath());
            $job->setStage('writing');
            $this->em->flush();
            $audio = $this->audio($job);
            $history = json_decode($job->getHistory(), true);
            $form = json_decode($job->getForm(), true);
            $touched = json_decode($job->getTouched(), true);
            $turn = $this->profiles->suggest(
                $jpeg,
                'image/jpeg',
                $audio[0] ?? null,
                $audio[1] ?? null,
                $job->getNotes(),
                $job->getAnswer(),
                is_array($history) ? $history : [],
                is_array($form) ? $form : [],
                is_array($touched) ? $touched : [],
                $this->artefacts->recentTitles(),
            );
            $profile = ProfileExchange::merge(
                $turn->profile,
                is_array($form) ? ProfileExchange::form($job->getForm()) : ProfileExchange::form('{}'),
                is_array($touched) ? ProfileExchange::touched($job->getTouched()) : [],
                $turn->changed,
            );
            $encoded = json_encode([
                'profile' => $profile->toArray(),
                'questions' => $turn->questions,
                'complete' => $turn->complete,
                'changed' => $turn->changed,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $job->setResult($encoded);
            $job->setStatus('done');
            $job->setStage('done');
            $job->setError(null);
            $this->writeArtefact($job, $turn->profile);
            $this->log($job, 'done', $started);
        } catch (ProfileGenerationFailed $exception) {
            $this->failOrRetry($job, $exception, $started);
        } catch (\Throwable $exception) {
            $this->log($job, $exception->getMessage(), $started);
            $this->failOrRetry($job, new ProfileGenerationFailed(ProfileGenerationFailed::UNAVAILABLE, 502), $started);
        }

        $this->em->flush();
    }

    public function storeAudio(string $bytes, string $mime): string
    {
        $extension = match ($mime) {
            'audio/webm', 'video/webm' => 'webm',
            'audio/mp4', 'video/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            default => 'bin',
        };
        $directory = dirname($this->uploads).'/audio';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidArtefact('Não foi possível guardar o áudio.');
        }
        $name = Identifier::uuid().'.'.$extension;
        if (file_put_contents($directory.'/'.$name, $bytes) === false) {
            throw new InvalidArtefact('Não foi possível guardar o áudio.');
        }

        return 'audio/'.$name;
    }

    private function failOrRetry(ProfileJob $job, ProfileGenerationFailed $exception, float $started): void
    {
        $permanent = in_array($exception->getMessage(), [
            ProfileGenerationFailed::UNCONFIGURED,
            ProfileGenerationFailed::REJECTED_KEY,
            ProfileGenerationFailed::INVALID_MODEL,
        ], true);
        $retry = !$permanent && in_array($exception->status(), [429, 502, 503], true) && $job->getAttempts() < 6;
        $job->setError($exception->getMessage());
        if ($retry) {
            $seconds = 15 * (2 ** max(0, $job->getAttempts() - 1));
            $job->setStatus('queued');
            $job->setStage('queued');
            $job->setRunAt(new \DateTimeImmutable('+'.$seconds.' seconds'));
            $this->markArtefact($job, 'queued');
            $this->log($job, 'retry '.$exception->status().' '.$exception->detail().' +'.$seconds.'s', $started);

            return;
        }
        $job->setStatus('failed');
        $job->setStage('failed');
        $this->markArtefact($job, 'failed');
        $this->log($job, 'failed '.$exception->getMessage(), $started);
    }

    public function queueFor(Artefact $artefact, bool $force): ?ProfileJob
    {
        $photo = $artefact->getImages()[0] ?? null;
        if (!is_string($photo) || $photo === '') {
            return null;
        }
        $needs = $force || $artefact->getTitle() === '' || $artefact->getDescription() === '';
        if (!$needs) {
            return null;
        }
        $existing = $this->jobs->latestFor($artefact->getId());
        if ($existing instanceof ProfileJob && in_array($existing->getStatus(), ['queued', 'running'], true)) {
            return $existing;
        }
        if ($existing instanceof ProfileJob) {
            return $this->requeue($existing);
        }

        $job = new ProfileJob($photo);
        $job->setArtefactId($artefact->getId());
        $job->setNotes($artefact->getNotes());
        $job->setAudioPath($artefact->getAudioPath());
        $job->setAudioMime($artefact->getAudioMime());
        $job->setForm((string) json_encode([
            'title' => $artefact->getTitle(),
            'description' => $artefact->getDescription(),
            'symbolism' => $artefact->getSymbolism(),
            'dimensions' => $artefact->getDimensions(),
            'materials' => $artefact->getMaterials(),
            'care' => $artefact->getCare(),
            'handmade' => $artefact->getHandmade(),
            'size' => $artefact->getSize(),
        ], JSON_UNESCAPED_UNICODE));
        $job->setTouched((string) json_encode($artefact->getManualFields(), JSON_UNESCAPED_UNICODE));
        $artefact->setAiStatus('queued');
        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    public function requeue(ProfileJob $job): ProfileJob
    {
        if ($job->getStatus() === 'running') {
            return $job;
        }
        $job->setStatus('queued');
        $job->setStage('queued');
        $job->setError(null);
        $job->setAttempts(0);
        $job->setRunAt(new \DateTimeImmutable('-1 second'));
        $artefact = $this->artefactFor($job);
        if ($artefact instanceof Artefact) {
            $photo = $artefact->getImages()[0] ?? null;
            if (is_string($photo) && $photo !== '') {
                $job->setImagePath($photo);
            }
            $job->setNotes($artefact->getNotes());
            $job->setAudioPath($artefact->getAudioPath());
            $job->setAudioMime($artefact->getAudioMime());
            $job->setTouched((string) json_encode($artefact->getManualFields(), JSON_UNESCAPED_UNICODE));
        }
        $this->markArtefact($job, 'queued');
        $this->em->flush();

        return $job;
    }

    private function writeArtefact(ProfileJob $job, GeneratedProfile $profile): void
    {
        $artefact = $this->artefactFor($job);
        if (!$artefact instanceof Artefact) {
            return;
        }
        $this->em->refresh($artefact);
        $artefact->fillFromProfile($profile);
        $artefact->setAiStatus('done');
    }

    private function markArtefact(ProfileJob $job, string $status): void
    {
        $artefact = $this->artefactFor($job);
        if ($artefact instanceof Artefact) {
            $artefact->setAiStatus($status);
        }
    }

    private function artefactFor(ProfileJob $job): ?Artefact
    {
        $id = $job->getArtefactId();
        if ($id === null || $id === '') {
            return null;
        }
        $artefact = $this->artefacts->find($id);

        return $artefact instanceof Artefact ? $artefact : null;
    }

    /** @return array{0: string, 1: string}|null */
    private function audio(ProfileJob $job): ?array
    {
        $relative = $job->getAudioPath();
        $mime = $job->getAudioMime();
        if ($relative === null || $mime === null) {
            return null;
        }
        $full = dirname($this->uploads).'/'.$relative;
        if (!is_file($full)) {
            return null;
        }
        $bytes = file_get_contents($full);

        return is_string($bytes) && $bytes !== '' ? [$bytes, $mime] : null;
    }

    public function logPath(): string
    {
        $parent = dirname($this->uploads);

        return (basename($parent) === 'uploads' ? dirname($parent) : $parent).'/logs/ai.log';
    }

    public function logLine(string $message): void
    {
        $path = $this->logPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
        file_put_contents($path, (new \DateTimeImmutable())->format('c').' '.$message."\n", FILE_APPEND);
    }

    private function log(ProfileJob $job, string $message, float $started): void
    {
        $this->logLine(sprintf(
            '%s %s %.2fs %s',
            $job->getId(),
            $job->getStatus(),
            microtime(true) - $started,
            str_replace(["\n", "\r"], ' ', $message),
        ));
    }
}
