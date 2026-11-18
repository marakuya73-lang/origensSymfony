<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ArtefactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'origens:check', description: 'Show where pieces are stored and whether that place can be written.')]
final class OrigensCheckCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private ArtefactRepository $artefacts,
        #[Autowire('%artefact_upload_dir%')]
        private string $uploads,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) ($this->em->getConnection()->getParams()['path'] ?? '');
        $writable = $path !== '' && (is_file($path) ? is_writable($path) : is_dir(dirname($path)) && is_writable(dirname($path)));
        $uploadsWritable = is_dir($this->uploads) ? is_writable($this->uploads) : is_dir(dirname($this->uploads)) && is_writable(dirname($this->uploads));
        $backup = $this->latestBackup($path);

        $output->writeln('database: '.($path !== '' ? $path : 'unknown'));
        $output->writeln('writable: '.($writable ? 'yes' : 'no'));
        $output->writeln('artefacts: '.$this->artefacts->count([]));
        $output->writeln('uploads: '.$this->uploads);
        $output->writeln('uploads_writable: '.($uploadsWritable ? 'yes' : 'no'));
        $output->writeln('last_backup: '.($backup ?? 'none'));

        return $path !== '' && $writable ? Command::SUCCESS : Command::FAILURE;
    }

    private function latestBackup(string $database): ?string
    {
        $directories = [];
        if ($database !== '') {
            $directories[] = dirname($database).'/backups';
        }
        $newest = null;
        $newestTime = 0;
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (glob($directory.'/origens-*.sqlite') ?: [] as $file) {
                $time = filemtime($file);
                if ($time !== false && $time >= $newestTime) {
                    $newestTime = $time;
                    $newest = $file.' '.gmdate('Y-m-d\\TH:i:s\\Z', $time);
                }
            }
        }

        return $newest;
    }
}
