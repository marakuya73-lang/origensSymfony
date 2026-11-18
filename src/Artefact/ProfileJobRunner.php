<?php

declare(strict_types=1);

namespace App\Artefact;

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
        $job = $this->jobs->find($id);
        if (!$job instanceof ProfileJob || $job->getStatus() !== 'queued' || $job->getRunAt() > new \DateTimeImmutable()) {
            return;
        }
        $this->execute($job);
    }

    public function runDue(int $limit, int $seconds): int
    {
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
        $job->setStatus('running');
        $job->setAttempts($job->getAttempts() + 1);
        $job->setStage($job->getAudioPath() !== null ? 'listening' : 'looking');
        $this->em->flush();
        $started = microtime(true);

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
        $retry = !$permanent && in_array($exception->status(), [429, 502, 503], true) && $job->getAttempts() < 3;
        $job->setError($exception->getMessage());
        if ($retry) {
            $job->setStatus('queued');
            $job->setStage('queued');
            $job->setRunAt(new \DateTimeImmutable('+'.(20 * $job->getAttempts()).' seconds'));
            $this->log($job, 'retry '.$exception->status(), $started);

            return;
        }
        $job->setStatus('failed');
        $job->setStage('failed');
        $this->log($job, 'failed '.$exception->getMessage(), $started);
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

    private function log(ProfileJob $job, string $message, float $started): void
    {
        $parent = dirname($this->uploads);
        $directory = basename($parent) === 'uploads' ? dirname($parent).'/logs' : $parent.'/logs';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
        $line = sprintf(
            "%s %s %s %.2fs %s\n",
            (new \DateTimeImmutable())->format('c'),
            $job->getId(),
            $job->getStatus(),
            microtime(true) - $started,
            str_replace(["\n", "\r"], ' ', $message),
        );
        file_put_contents($directory.'/ai.log', $line, FILE_APPEND);
    }
}
