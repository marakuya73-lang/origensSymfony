<?php

namespace App\Command;

use App\Artefact\ProfileJobRunner;
use App\Repository\ProfileJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
            ->addOption('list', null, InputOption::VALUE_NONE, 'Print jobs and do not run them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = $this->em->getConnection()->getParams();
        $path = (string) ($params['path'] ?? $params['url'] ?? '');
        $this->say($output, 'database: '.$path);
        $this->say($output, 'log: '.$this->runner->logPath());
        $this->runner->releaseStale();
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
        $done = $this->runner->runDue((int) $input->getOption('limit'), (int) $input->getOption('seconds'));
        $this->say($output, 'jobs: '.$done);

        return Command::SUCCESS;
    }

    private function say(OutputInterface $output, string $line): void
    {
        $output->writeln($line);
        fwrite(STDERR, $line."\n");
        $this->runner->logLine($line);
    }
}
