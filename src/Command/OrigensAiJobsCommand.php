<?php

declare(strict_types=1);

namespace App\Command;

use App\Artefact\ProfileJobRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'origens:ai-jobs', description: 'Run queued AI profile jobs. Meant for a one-minute cron.')]
final class OrigensAiJobsCommand extends Command
{
    public function __construct(private ProfileJobRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum jobs in this run', '1');
        $this->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Stop starting new jobs after this many seconds', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $seconds = max(1, (int) $input->getOption('seconds'));
        set_time_limit($seconds + 30);
        $done = $this->runner->runDue($limit, $seconds);
        $output->writeln('jobs: '.$done);

        return Command::SUCCESS;
    }
}
