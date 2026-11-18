<?php

namespace App\Command;

use App\Artefact\ProfileJobRunner;
use App\Entity\ProfileJob;
use App\Repository\ProfileJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'origens:ai-jobs', description: 'Run queued profile generations')]
final class OrigensAiJobsCommand extends Command
{
    public function __construct(
        private readonly ProfileJobRunner $runner,
        private readonly ProfileJobRepository $jobs,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Jobs per run', '1')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds', '50')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Print jobs and do not run them')
            ->addOption('now', null, InputOption::VALUE_NONE, 'Ignore backoff and run queued jobs now')
            ->addOption('job', null, InputOption::VALUE_REQUIRED, 'Run this job id, ignoring backoff')
            ->addOption('reset-backoff', null, InputOption::VALUE_NONE, 'Make every queued job due now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = $this->em->getConnection()->getParams();
        $path = (string) ($params['path'] ?? $params['url'] ?? '');
        $this->say($output, 'database: '.$path);
        $this->say($output, 'log: '.$this->runner->logPath());
        if ($input->getOption('reset-backoff')) {
            $this->say($output, 'backoff reset: '.$this->runner->resetBackoff());

            return Command::SUCCESS;
        }
        $this->runner->releaseStale();
        if (!$input->getOption('list')) {
            $released = $this->runner->releaseBackoffAfterDeploy();
            if ($released > 0) {
                $this->say($output, 'backoff reset: '.$released);
            }
        }
        $queued = $this->jobs->countStatus('queued');
        $running = $this->jobs->countStatus('running');
        $this->say($output, 'queued: '.$queued);
        $this->say($output, 'running: '.$running);
        foreach ($this->jobs->recent() as $job) {
            $this->say($output, sprintf(
                'job %s status=%s stage=%s attempts=%d outcome=%s',
                $job->getId(),
                $job->getStatus(),
                $job->getStage(),
                $job->getAttempts(),
                $job->getError() ?? $job->getStatus(),
            ));
        }
        if ($input->getOption('list')) {
            return Command::SUCCESS;
        }

        $now = $input->getOption('now') === true;
        $jobId = $input->getOption('job');
        $jobId = is_string($jobId) ? $jobId : '';
        if (!$now && $jobId === '') {
            $clock = new \DateTimeImmutable();
            foreach ($this->jobs->recent() as $job) {
                if ($job->getStatus() === 'queued' && $job->getRunAt() > $clock) {
                    $this->say($output, sprintf('job %s skipped: due at %s', $job->getId(), $job->getRunAt()->format('Y-m-d H:i:s')));
                }
            }
        }

        $this->runner->onAttempt(function (string $model, int $status, string $detail) use ($output): void {
            $this->say($output, 'try '.$model.' '.$status.' '.$detail);
        });

        if ($jobId !== '') {
            $job = $this->jobs->find($jobId);
            if (!$job instanceof ProfileJob) {
                $this->say($output, 'job '.$jobId.' missing');

                return Command::FAILURE;
            }
            if ($job->getStatus() !== 'queued') {
                $this->runner->requeue($job);
            }
            $job->setRunAt(new \DateTimeImmutable('-1 second'));
            $this->em->flush();
            $this->runner->execute($job);
            $this->report($output, $job);

            return Command::SUCCESS;
        }

        $limit = (int) $input->getOption('limit');
        if ($now && $input instanceof ArgvInput && !$input->hasParameterOption('--limit')) {
            $limit = max($limit, 20);
        }
        if ($now) {
            $done = $this->runner->runNow(max(1, $limit));
        } else {
            $done = $this->runner->runDue($limit, (int) $input->getOption('seconds'));
        }
        $this->say($output, 'jobs: '.$done);

        return Command::SUCCESS;
    }

    private function report(OutputInterface $output, ProfileJob $job): void
    {
        $this->em->refresh($job);
        $this->say($output, sprintf('job %s outcome=%s', $job->getId(), $job->getError() ?? $job->getStatus()));
    }

    private function say(OutputInterface $output, string $line): void
    {
        $output->writeln($line);
        fwrite(STDERR, $line."\n");
        $this->runner->logLine($line);
    }
}
