<?php

declare(strict_types=1);

namespace App\Command;

use App\Artefact\ArchiveSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'origens:snapshot', description: 'Write a durable ZIP of the database, photos and spreadsheet. Copies are kept.')]
final class OrigensSnapshotCommand extends Command
{
    public function __construct(private ArchiveSnapshot $snapshots)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->snapshots->storeSnapshot();
        $output->writeln($path);

        return Command::SUCCESS;
    }
}
