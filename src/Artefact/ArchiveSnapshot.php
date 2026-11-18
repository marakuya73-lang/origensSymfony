<?php

declare(strict_types=1);

namespace App\Artefact;

use App\Repository\ArtefactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ArchiveSnapshot
{
    public function __construct(
        private EntityManagerInterface $em,
        private ArtefactRepository $artefacts,
        private SellerTable $sheet,
        private TabularFile $files,
        #[Autowire('%artefact_upload_dir%')]
        private string $uploads,
    ) {
    }

    public function databasePath(): string
    {
        $path = (string) ($this->em->getConnection()->getParams()['path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('Não foi possível localizar o banco.');
        }

        return $path;
    }

    public function snapshotDirectory(): string
    {
        return dirname($this->databasePath()).'/snapshots';
    }

    public function downloadName(\DateTimeImmutable $moment): string
    {
        return 'origens-backup-'.$moment->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Ymd-Hi').'.zip';
    }

    public function writeZip(string $destination): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Não foi possível gerar o backup.');
        }

        $database = $this->consistentDatabase();
        $table = $this->sheet->build($this->artefacts->recent(), 'https://origens.art.br');
        $zip = new \ZipArchive();
        if ($zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            unlink($database);
            throw new \RuntimeException('Não foi possível gerar o backup.');
        }

        $zip->addFile($database, 'database.sqlite');
        $zip->addFromString('artefactos.csv', $this->files->csv($table['headers'], $table['rows']));
        $zip->addFromString('artefactos.xlsx', $this->files->xlsx($table['headers'], $table['rows'], SellerTable::PRICE_COLUMN));
        $zip->addFromString('README.txt', $this->readme());
        $this->addPhotos($zip);
        $zip->close();
        unlink($database);
    }

    public function storeSnapshot(): string
    {
        $directory = $this->snapshotDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível guardar o backup.');
        }
        $moment = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $name = $this->downloadName($moment);
        $path = $directory.'/'.$name;
        if (is_file($path)) {
            $path = $directory.'/origens-backup-'.$moment->format('Ymd-His').'.zip';
        }
        $this->writeZip($path);

        return $path;
    }

    public function temporaryDownload(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'origens-backup-');
        if ($path === false) {
            throw new \RuntimeException('Não foi possível gerar o backup.');
        }
        unlink($path);
        $path .= '.zip';
        $this->writeZip($path);

        return $path;
    }

    public function markDownloaded(): void
    {
        file_put_contents($this->marker(), (string) time());
    }

    /** @return array{label: string, stale: bool} */
    public function status(): array
    {
        $marker = $this->marker();
        $downloaded = is_file($marker) ? (int) trim((string) file_get_contents($marker)) : 0;
        $database = $this->databasePath();
        $changed = is_file($database) ? (int) filemtime($database) : 0;
        $moment = $downloaded > 0
            ? (new \DateTimeImmutable('@'.$downloaded))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i')
            : 'nenhum';

        return [
            'label' => 'Último backup baixado: '.$moment,
            'stale' => $this->artefacts->count([]) > 0 && ($downloaded === 0 || $changed > $downloaded),
        ];
    }

    public function restore(string $zipPath, bool $force): void
    {
        if (!$force) {
            throw new \RuntimeException('Restaurar substitui o banco e as fotos. Rode de novo com --force para confirmar.');
        }
        if (!is_file($zipPath)) {
            throw new \RuntimeException('Não foi possível abrir o backup.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true || $zip->locateName('database.sqlite') === false) {
            throw new \RuntimeException('O backup não contém database.sqlite.');
        }

        $incoming = tempnam(sys_get_temp_dir(), 'origens-restore-');
        if ($incoming === false) {
            throw new \RuntimeException('Não foi possível restaurar o backup.');
        }
        file_put_contents($incoming, $zip->getFromName('database.sqlite'));
        if (!str_starts_with((string) file_get_contents($incoming, false, null, 0, 15), 'SQLite format 3')) {
            unlink($incoming);
            throw new \RuntimeException('O banco dentro do backup não é um SQLite válido.');
        }

        $live = $this->databasePath();
        $backupDir = dirname($live).'/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            unlink($incoming);
            throw new \RuntimeException('Não foi possível guardar o banco atual antes de restaurar.');
        }
        $safety = $backupDir.'/origens-before-restore-'.gmdate('Ymd\THis\Z').'.sqlite';
        if (is_file($live)) {
            $this->copyDatabase($live, $safety);
            if (!is_file($safety) || filesize($safety) === 0) {
                unlink($incoming);
                throw new \RuntimeException('Não foi possível guardar o banco atual antes de restaurar.');
            }
        }

        $this->em->getConnection()->close();
        if (!copy($incoming, $live)) {
            unlink($incoming);
            throw new \RuntimeException('Não foi possível restaurar o banco.');
        }
        unlink($incoming);
        $this->extractPhotos($zip);
        $zip->close();
    }

    private function consistentDatabase(): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'origens-db-');
        if ($copy === false) {
            throw new \RuntimeException('Não foi possível copiar o banco.');
        }
        unlink($copy);
        $copy .= '.sqlite';
        $this->copyDatabase($this->databasePath(), $copy);

        return $copy;
    }

    private function copyDatabase(string $source, string $destination): void
    {
        if (is_file($destination)) {
            unlink($destination);
        }
        if (class_exists(\SQLite3::class)) {
            $from = new \SQLite3($source, SQLITE3_OPEN_READONLY);
            $to = new \SQLite3($destination);
            $ok = $from->backup($to);
            $to->close();
            $from->close();
            if ($ok === false || !is_file($destination)) {
                throw new \RuntimeException('Não foi possível copiar o banco.');
            }

            return;
        }

        $pdo = new \PDO('sqlite:'.$source);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("VACUUM INTO '".str_replace("'", "''", $destination)."'");
    }

    private function addPhotos(\ZipArchive $zip): void
    {
        if (!is_dir($this->uploads)) {
            return;
        }
        $root = realpath($this->uploads);
        if ($root === false) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $relative = substr($full, strlen($root) + 1);
            if ($relative === false || str_contains($relative, '..')) {
                continue;
            }
            $zip->addFile($full, 'photos/'.str_replace('\\', '/', $relative));
        }
    }

    private function extractPhotos(\ZipArchive $zip): void
    {
        if (!is_dir($this->uploads) && !mkdir($this->uploads, 0775, true) && !is_dir($this->uploads)) {
            throw new \RuntimeException('Não foi possível restaurar as fotos.');
        }
        $root = realpath($this->uploads);
        if ($root === false) {
            throw new \RuntimeException('Não foi possível restaurar as fotos.');
        }
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || !str_starts_with($name, 'photos/') || str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, strlen('photos/'));
            if ($relative === false || str_contains($relative, '..')) {
                throw new \RuntimeException('O backup contém um caminho de foto inválido.');
            }
            $target = $root.'/'.$relative;
            $folder = dirname($target);
            if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
                throw new \RuntimeException('Não foi possível restaurar as fotos.');
            }
            $contents = $zip->getFromIndex($index);
            if ($contents === false || file_put_contents($target, $contents) === false) {
                throw new \RuntimeException('Não foi possível restaurar as fotos.');
            }
        }
    }

    private function marker(): string
    {
        return dirname($this->databasePath()).'/last-download.txt';
    }

    private function readme(): string
    {
        return <<<'TXT'
ORIGENS — backup

Este arquivo guarda uma cópia durável do arquivo de peças. A planilha abre sem o site, mesmo daqui a muitos anos.

database.sqlite
    O banco SQLite, copiado de forma consistente enquanto o site podia estar gravando.
    Contém as peças, os links públicos /p/<token>, o preço de venda e a simbologia.

photos/
    As fotografias, no mesmo caminho relativo usado pelo site.

artefactos.csv
artefactos.xlsx
    Todas as peças. O CSV é UTF-8 com BOM e separador ; para o Excel em português.
    Inclui preço de venda, simbologia, materiais e o link público.

Para restaurar com o aplicativo, na pasta do projeto:
    bin/console origens:restore CAMINHO/origens-backup.zip --force
    Antes de substituir, o comando copia o banco atual para a pasta de backups.

Para restaurar à mão:
    1. Feche o site.
    2. Substitua o arquivo origens.sqlite por database.sqlite.
    3. Copie o conteúdo de photos/ para a pasta das fotos (uploads/artefacts).
    4. Os links /p/<token> continuam os mesmos, porque estão dentro do banco.

TXT;
    }
}
