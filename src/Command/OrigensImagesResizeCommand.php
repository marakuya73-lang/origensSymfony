<?php

declare(strict_types=1);

namespace App\Command;

use App\Artefact\ImageStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'origens:images:resize', description: 'Make 2000px originals and smaller sizes for photos already on disk.')]
final class OrigensImagesResizeCommand extends Command
{
    public function __construct(
        private ImageStore $images,
        #[Autowire('%artefact_upload_dir%')]
        private string $uploads,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_dir($this->uploads)) {
            $output->writeln('photos: 0');

            return Command::SUCCESS;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploads, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $name = $file->getFilename();
            if (str_contains($name, '.original') || preg_match('/-(?:400|1200)\.jpg$/', $name) === 1) {
                continue;
            }
            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }
            $this->images->reprocess($path);
            ++$count;
        }
        $output->writeln('photos: '.$count);

        return Command::SUCCESS;
    }
}
