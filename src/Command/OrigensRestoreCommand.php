<?php

declare(strict_types=1);

namespace App\Command;

use App\Artefact\ArchiveSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'origens:restore', description: 'Restore the database and photos from a backup ZIP. Refuses to run without --force.')]
final class OrigensRestoreCommand extends Command
{
    public function __construct(private ArchiveSnapshot $snapshots)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('zip', InputArgument::REQUIRED, 'Path of an origens-backup zip');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Replace the current database and photos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->snapshots->restore((string) $input->getArgument('zip'), (bool) $input->getOption('force'));
        } catch (\RuntimeException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }

        $output->writeln('Restaurado a partir de '.$input->getArgument('zip'));

        return Command::SUCCESS;
    }
}
