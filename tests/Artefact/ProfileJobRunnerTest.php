<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\GeminiProfileGenerator;
use App\Artefact\ProfileGenerationFailed;
use App\Artefact\ProfileJobRunner;
use App\Entity\ProfileJob;
use App\Tests\SchemaBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProfileJobRunnerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        SchemaBuilder::rebuild(static::getContainer()->get(EntityManagerInterface::class));
    }

    public function testAQueuedJobFinishesAndKeepsTheResult(): void
    {
        $this->useGenerator(new MockHttpClient(fn () => $this->profile()));
        $job = $this->job();
        $this->runner()->execute($job);

        self::assertSame('done', $job->getStatus());
        self::assertStringContainsString('Abano de palha', (string) $job->getResult());
        self::assertFileExists($this->log());
    }

    public function testABusyModelIsQueuedAgainUntilTheThirdFailure(): void
    {
        $this->useGenerator(new MockHttpClient(fn () => new MockResponse('{"error":{"status":"RESOURCE_EXHAUSTED"}}', ['http_code' => 429])));
        $job = $this->job();
        $runner = $this->runner();
        $runner->execute($job);
        self::assertSame('queued', $job->getStatus());
        self::assertSame(1, $job->getAttempts());

        $job->setRunAt(new \DateTimeImmutable('-1 second'));
        $runner->execute($job);
        $job->setRunAt(new \DateTimeImmutable('-1 second'));
        $runner->execute($job);

        self::assertSame('failed', $job->getStatus());
        self::assertSame(3, $job->getAttempts());
        self::assertSame(ProfileGenerationFailed::LIMITED, $job->getError());
    }

    public function testTheCronCommandRunsAQueuedJob(): void
    {
        $this->useGenerator(new MockHttpClient(fn () => $this->profile()));
        $job = $this->job();
        $command = static::getContainer()->get(\App\Command\OrigensAiJobsCommand::class);
        self::assertInstanceOf(\App\Command\OrigensAiJobsCommand::class, $command);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['--limit' => '1', '--seconds' => '10']));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $again = $em->find(ProfileJob::class, $job->getId());
        self::assertInstanceOf(ProfileJob::class, $again);
        self::assertSame('done', $again->getStatus());
        self::assertStringContainsString('queued: 1', $tester->getDisplay());
        self::assertStringContainsString($job->getId(), $tester->getDisplay());
        self::assertStringContainsString('jobs: 1', $tester->getDisplay());
    }

    public function testListPrintsTheJobAndDoesNotRunIt(): void
    {
        $job = $this->job();
        $command = static::getContainer()->get(\App\Command\OrigensAiJobsCommand::class);
        self::assertInstanceOf(\App\Command\OrigensAiJobsCommand::class, $command);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['--list' => true]));
        self::assertStringContainsString('queued: 1', $tester->getDisplay());
        self::assertStringContainsString($job->getId(), $tester->getDisplay());
        self::assertStringNotContainsString('jobs:', $tester->getDisplay());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $again = $em->find(ProfileJob::class, $job->getId());
        self::assertInstanceOf(ProfileJob::class, $again);
        self::assertSame('queued', $again->getStatus());
    }

    public function testAPollKicksAJobThatHasWaited(): void
    {
        $this->useGenerator(new MockHttpClient(fn () => $this->profile()));
        $job = $this->job();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE profile_jobs SET created_at = :created WHERE id = :id',
            ['created' => (new \DateTimeImmutable('-10 seconds'))->format('Y-m-d H:i:s'), 'id' => $job->getId()],
        );
        $em->clear();
        self::assertTrue($this->runner()->kick($job->getId()));
        $again = $em->find(ProfileJob::class, $job->getId());
        self::assertInstanceOf(ProfileJob::class, $again);
        self::assertSame('done', $again->getStatus());
    }

    public function testAStaleRunningJobIsQueuedAgain(): void
    {
        $job = $this->job();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE profile_jobs SET status = :status, updated_at = :updated WHERE id = :id',
            [
                'status' => 'running',
                'updated' => (new \DateTimeImmutable('-3 minutes'))->format('Y-m-d H:i:s'),
                'id' => $job->getId(),
            ],
        );
        $em->clear();
        self::assertSame(1, $this->runner()->releaseStale());
        $again = $em->find(ProfileJob::class, $job->getId());
        self::assertInstanceOf(ProfileJob::class, $again);
        self::assertSame('queued', $again->getStatus());
    }

    private function job(): ProfileJob
    {
        $directory = static::getContainer()->getParameter('artefact_upload_dir');
        self::assertIsString($directory);
        $folder = $directory.'/eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        if (!is_dir($folder)) {
            mkdir($folder, 0775, true);
        }
        $file = $folder.'/ffffffff-ffff-4fff-8fff-ffffffffffff.jpg';
        $canvas = imagecreatetruecolor(40, 20);
        imagejpeg($canvas, $file, 80);
        imagedestroy($canvas);
        $job = new ProfileJob('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee/ffffffff-ffff-4fff-8fff-ffffffffffff.jpg');
        $job->setNotes('Palha.');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($job);
        $em->flush();

        return $job;
    }

    private function runner(): ProfileJobRunner
    {
        $runner = static::getContainer()->get(ProfileJobRunner::class);
        self::assertInstanceOf(ProfileJobRunner::class, $runner);

        return $runner;
    }

    private function useGenerator(MockHttpClient $client): void
    {
        static::getContainer()->set(GeminiProfileGenerator::class, new GeminiProfileGenerator($client, 'test-key', 'gemini-3.8-flash'));
    }

    private function profile(): MockResponse
    {
        return new MockResponse((string) json_encode([
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'title' => 'Abano de palha',
                        'description' => 'Peça leve.',
                        'dimensions' => '',
                        'materials' => [['name' => 'Palha', 'note' => '']],
                        'care' => ['Guardar seco.'],
                        'handmade' => 'Feito à mão.',
                        'size' => 'M',
                        'symbolism' => '',
                        'questions' => [],
                        'complete' => true,
                        'changed' => [],
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function log(): string
    {
        $uploads = static::getContainer()->getParameter('artefact_upload_dir');
        self::assertIsString($uploads);

        $parent = dirname($uploads);

        return (basename($parent) === 'uploads' ? dirname($parent) : $parent).'/logs/ai.log';
    }
}
