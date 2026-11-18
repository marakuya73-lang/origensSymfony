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

    /** @var null|\Closure(string, int, string): void */
    private ?\Closure $attemptListener = null;

    /** @param \Closure(string, int, string): void $listener */
    public function onAttempt(\Closure $listener): void
    {
        $this->attemptListener = $listener;
    }

    public function chainStamp(): string
    {
        return 'cap-120-skip-404';
    }

    public function chainStampPath(): string
    {
        return dirname($this->logPath()).'/chain-stamp.txt';
    }

    public function resetBackoff(): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            "UPDATE profile_jobs SET run_at = :now WHERE status = 'queued'",
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    public function releaseBackoffAfterDeploy(): int
    {
        $path = $this->chainStampPath();
        $current = is_file($path) ? trim((string) file_get_contents($path)) : '';
        if ($current === $this->chainStamp()) {
            return 0;
        }
        $released = $this->resetBackoff();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $released;
        }
        file_put_contents($path, $this->chainStamp());

        return $released;
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

    public function runNow(int $limit): int
    {
        $done = 0;
        foreach ($this->jobs->queued($limit) as $job) {
            $job->setRunAt(new \DateTimeImmutable('-1 second'));
            $this->em->flush();
            $this->execute($job);
            ++$done;
        }

        return $done;
    }

    public function execute(ProfileJob $job, int $follow = 1): void
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
        $live = $this->inputs($job);
        $job->setAttempts($job->getAttempts() + 1);
        $job->setStage($live['audioPath'] !== null ? 'listening' : 'looking');
        $this->markArtefact($job, 'running');
        $this->em->flush();
        $started = microtime(true);
        $this->log($job, 'start', $started);
        $this->profiles->onAttempt(function (string $model, int $status, string $detail) use ($job, $started): void {
            $line = $model.' '.$status.' '.$detail;
            $this->log($job, $line, $started);
            if ($this->attemptListener !== null) {
                ($this->attemptListener)($model, $status, $detail);
            }
        });

        try {
            if ($this->abandoned($job)) {
                return;
            }
            $live = $this->inputs($job);
            $jpeg = $this->images->geminiJpeg($live['image']);
            $job->setStage('writing');
            $this->em->flush();
            if ($this->abandoned($job)) {
                return;
            }
            $audio = $this->audioBytes($live['audioPath'], $live['audioMime']);
            $history = json_decode($job->getHistory(), true);
            $turn = $this->profiles->suggest(
                $jpeg,
                'image/jpeg',
                $audio[0] ?? null,
                $audio[1] ?? null,
                $live['notes'],
                $job->getAnswer(),
                is_array($history) ? $history : [],
                $live['form'],
                $live['touched'],
                $this->artefacts->recentTitles(),
            );
            $profile = ProfileExchange::merge(
                $turn->profile,
                $live['form'],
                $live['touched'],
                $turn->changed,
            );
            if ($this->abandoned($job)) {
                return;
            }
            $latest = $this->inputs($job);
            if ($job->isRerun() || $latest['key'] !== $live['key']) {
                $this->queueAgain($job, $started, $follow);

                return;
            }
            $this->writeArtefact($job, $turn->profile);
            $kept = $this->artefactFor($job);
            $profile = $kept instanceof Artefact ? [
                'title' => $kept->getTitle(),
                'description' => $kept->getDescription(),
                'symbolism' => $kept->getSymbolism(),
                'dimensions' => $kept->getDimensions(),
                'materials' => $kept->getMaterials(),
                'care' => $kept->getCare(),
                'handmade' => $kept->getHandmade(),
                'size' => $kept->getSize(),
            ] : $profile->toArray();
            $encoded = json_encode([
                'profile' => $profile,
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

    public function audioFile(?string $relative): ?string
    {
        if ($relative === null || $relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $full = dirname($this->uploads).'/'.$relative;
        $root = realpath(dirname($this->uploads).'/audio');
        $file = realpath($full);
        if ($root === false || $file === false || !str_starts_with($file, $root) || !is_file($file)) {
            return null;
        }

        return $file;
    }

    public function forgetAudio(?string $relative): void
    {
        if ($relative === null || $relative === '' || str_contains($relative, '..')) {
            return;
        }
        $full = dirname($this->uploads).'/'.$relative;
        $root = realpath(dirname($this->uploads).'/audio');
        $file = realpath($full);
        if ($root === false || $file === false || !str_starts_with($file, $root)) {
            return;
        }
        if (is_file($file)) {
            unlink($file);
        }
    }

    /** @return array{stored: bool, label: string, mime: ?string} */
    public function confirmedAudio(Artefact $artefact): array
    {
        $empty = ['stored' => false, 'label' => 'Sem áudio', 'mime' => null];
        $relative = $artefact->getAudioPath();
        if ($relative === null || $relative === '' || str_contains($relative, '..')) {
            return $empty;
        }
        $full = dirname($this->uploads).'/'.$relative;
        clearstatcache(true, $full);
        $size = is_file($full) ? filesize($full) : false;
        if ($size === false || $size < 1) {
            return $empty;
        }
        $sample = file_get_contents($full, false, null, 0, 8);
        if (!is_string($sample) || $sample === '') {
            return $empty;
        }
        $mtime = filemtime($full);
        $when = is_int($mtime)
            ? (new \DateTimeImmutable('@'.$mtime))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m H:i')
            : '';
        $clock = $this->clock($artefact->getAudioDurationMs());
        $label = 'Áudio gravado'.($clock !== '' ? ' · '.$clock : '').($when !== '' ? ' · '.$when : '');

        return ['stored' => true, 'label' => $label, 'mime' => $artefact->getAudioMime()];
    }

    private function clock(?int $milliseconds): string
    {
        if ($milliseconds === null || $milliseconds < 1) {
            return '';
        }
        $seconds = (int) round($milliseconds / 1000);

        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    private function failOrRetry(ProfileJob $job, ProfileGenerationFailed $exception, float $started): void
    {
        if ($this->abandoned($job)) {
            return;
        }
        $permanent = in_array($exception->getMessage(), [
            ProfileGenerationFailed::UNCONFIGURED,
            ProfileGenerationFailed::REJECTED_KEY,
            ProfileGenerationFailed::INVALID_MODEL,
        ], true);
        $retry = !$permanent && in_array($exception->status(), [429, 502, 503], true) && $job->getAttempts() < 6;
        $job->setError($exception->detail());
        if ($retry) {
            $seconds = min(120, 15 * (2 ** max(0, $job->getAttempts() - 1)));
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
        $this->log($job, 'failed '.$exception->detail(), $started);
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
        if ($existing instanceof ProfileJob && $existing->getStatus() === 'queued') {
            if (!$force) {
                return $existing;
            }
            $this->copyInputs($existing, $artefact);
            $this->em->flush();

            return $existing;
        }
        if ($existing instanceof ProfileJob && $existing->getStatus() === 'running') {
            $this->noteWhileRunning($artefact);

            return $existing;
        }
        if ($existing instanceof ProfileJob) {
            return $this->requeue($existing);
        }

        return $this->enqueue($artefact);
    }

    public function noteWhileRunning(Artefact $artefact): void
    {
        $job = $this->jobs->latestFor($artefact->getId());
        if ($job instanceof ProfileJob && $job->getStatus() === 'running') {
            $job->setRerun(true);
            $this->em->flush();
        }
    }

    public function cancel(ProfileJob $job): void
    {
        if (!in_array($job->getStatus(), ['queued', 'running'], true)) {
            return;
        }
        $job->setStatus('cancelled');
        $job->setStage('cancelled');
        $job->setError(null);
        $this->markArtefact($job, '');
        $this->em->flush();
    }

    private function enqueue(Artefact $artefact): ?ProfileJob
    {
        $photo = $artefact->getImages()[0] ?? null;
        if (!is_string($photo) || $photo === '') {
            return null;
        }
        $job = new ProfileJob($photo);
        $job->setArtefactId($artefact->getId());
        $this->copyInputs($job, $artefact);
        $artefact->setAiStatus('queued');
        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    private function copyInputs(ProfileJob $job, Artefact $artefact): void
    {
        $photo = $artefact->getImages()[0] ?? null;
        if (is_string($photo) && $photo !== '') {
            $job->setImagePath($photo);
        }
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
        $job->setRunAt(new \DateTimeImmutable('-1 second'));
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
            $this->copyInputs($job, $artefact);
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

    private function abandoned(ProfileJob $job): bool
    {
        $this->em->refresh($job);
        if ($job->getStatus() !== 'cancelled') {
            return false;
        }
        $this->markArtefact($job, '');
        $this->em->flush();
        $this->logLine($job->getId().' cancelled');

        return true;
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

    /**
     * @return array{image: string, notes: string, audioPath: ?string, audioMime: ?string, form: array<string, mixed>, touched: list<string>, key: string}
     */
    private function inputs(ProfileJob $job): array
    {
        $artefact = $this->artefactFor($job);
        if ($artefact instanceof Artefact) {
            $this->em->refresh($artefact);
            $photo = $artefact->getImages()[0] ?? '';
            $image = is_string($photo) && $photo !== '' ? $photo : $job->getImagePath();
            $notes = $artefact->getNotes();
            $audioPath = $artefact->getAudioPath();
            $audioMime = $artefact->getAudioMime();
            $form = [
                'title' => $artefact->getTitle(),
                'description' => $artefact->getDescription(),
                'symbolism' => $artefact->getSymbolism(),
                'dimensions' => $artefact->getDimensions(),
                'materials' => $artefact->getMaterials(),
                'care' => $artefact->getCare(),
                'handmade' => $artefact->getHandmade(),
                'size' => $artefact->getSize(),
            ];
            $touched = $artefact->getManualFields();
        } else {
            $image = $job->getImagePath();
            $notes = $job->getNotes();
            $audioPath = $job->getAudioPath();
            $audioMime = $job->getAudioMime();
            $form = ProfileExchange::form($job->getForm());
            $touched = ProfileExchange::touched($job->getTouched());
        }

        return [
            'image' => $image,
            'notes' => $notes,
            'audioPath' => $audioPath,
            'audioMime' => $audioMime,
            'form' => $form,
            'touched' => $touched,
            'key' => $image.'|'.$notes.'|'.($audioPath ?? '').'|'.($audioMime ?? ''),
        ];
    }

    private function queueAgain(ProfileJob $job, float $started, int $follow): void
    {
        $job->setRerun(false);
        $job->setStatus('queued');
        $job->setStage('queued');
        $job->setError(null);
        $job->setRunAt(new \DateTimeImmutable('-1 second'));
        $this->markArtefact($job, 'queued');
        $this->log($job, 'rerun', $started);
        $this->em->flush();
        if ($follow > 0) {
            $this->execute($job, $follow - 1);
        }
    }

    /** @return array{0: string, 1: string}|null */
    private function audio(ProfileJob $job): ?array
    {
        return $this->audioBytes($job->getAudioPath(), $job->getAudioMime());
    }

    /** @return array{0: string, 1: string}|null */
    private function audioBytes(?string $relative, ?string $mime): ?array
    {
        if ($relative === null || $relative === '' || $mime === null || $mime === '') {
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
