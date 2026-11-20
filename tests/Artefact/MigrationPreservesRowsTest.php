<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use PHPUnit\Framework\TestCase;

final class MigrationPreservesRowsTest extends TestCase
{
    public function testRebuildingTheDescriptionColumnAndAddingSymbolismKeepTheRow(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE artefacts (id CHAR(36) NOT NULL, code VARCHAR(32) NOT NULL, public_token VARCHAR(32) NOT NULL, size VARCHAR(1) NOT NULL, category INTEGER NOT NULL, title VARCHAR(180) NOT NULL, description VARCHAR(2000) NOT NULL, dimensions VARCHAR(100) NOT NULL, materials CLOB NOT NULL, care CLOB NOT NULL, handmade CLOB NOT NULL, images CLOB NOT NULL, record_date DATE NOT NULL, created_at DATETIME NOT NULL, sale_price_cents INTEGER DEFAULT NULL, PRIMARY KEY (id))');
        $pdo->prepare('INSERT INTO artefacts (id, code, public_token, size, category, title, description, dimensions, materials, care, handmade, images, record_date, created_at, sale_price_cents) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                '11111111-1111-4111-8111-111111111111',
                'M-CAT1-001',
                str_repeat('a', 32),
                'M',
                1,
                'Peça que não pode sumir',
                'Descrição guardada.',
                '20 cm',
                '[]',
                '[]',
                'Feito à mão.',
                '[]',
                '2026-10-18',
                '2026-10-18 12:00:00',
                125050,
            ]);

        foreach ($this->statements(dirname(__DIR__, 2).'/migrations/Version20261117144414.php', 'up') as $sql) {
            $pdo->exec($sql);
        }
        foreach ($this->statements(dirname(__DIR__, 2).'/migrations/Version20261118160000.php', 'up') as $sql) {
            $pdo->exec($sql);
        }

        $row = $pdo->query("SELECT title, description, sale_price_cents, symbolism FROM artefacts WHERE code = 'M-CAT1-001'")->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('Peça que não pode sumir', $row['title']);
        self::assertSame('Descrição guardada.', $row['description']);
        self::assertSame(125050, (int) $row['sale_price_cents']);
        self::assertSame('', $row['symbolism']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM artefacts')->fetchColumn());
    }

    public function testTheDeployScriptBacksUpTheDatabaseBeforePulling(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/bin/origens-pull');
        $backup = (string) file_get_contents($root.'/bin/origens-backup');
        $pull = strpos($script, 'git -C "$PROJECT" pull --ff-only');
        $call = strpos($script, '"$PROJECT/bin/origens-backup"');

        self::assertNotFalse($call);
        self::assertNotFalse($pull);
        self::assertLessThan($pull, $call);
        self::assertStringContainsString('backup_db "$LIVE"', $backup);
        self::assertStringContainsString('database not found', $backup);
        $lib = (string) file_get_contents($root.'/bin/origens-lib.sh');
        self::assertStringContainsString('must be outside the project', $lib);
        self::assertStringContainsString('-gt 10', $lib);
        self::assertStringContainsString('public/uploads', $script);
        foreach (['origens-lib.sh', 'origens-pull', 'origens-backup'] as $name) {
            $body = (string) file_get_contents($root.'/bin/'.$name);
            self::assertDoesNotMatchRegularExpression('/<\(/', $body, $name);
            self::assertStringNotContainsString('/dev/fd', $body, $name);
            self::assertStringNotContainsString('mapfile', $body, $name);
            $output = [];
            $code = 0;
            exec('bash -n '.escapeshellarg($root.'/bin/'.$name), $output, $code);
            self::assertSame(0, $code, implode("\n", $output));
        }
    }

    public function testPruneKeepsTheTenNewestBackups(): void
    {
        $root = sys_get_temp_dir().'/origens-prune-'.bin2hex(random_bytes(4));
        mkdir($root.'/backups', 0775, true);
        for ($i = 0; $i < 12; ++$i) {
            $name = sprintf('%s/backups/origens-20260101T0000%02dZ.sqlite', $root, $i);
            file_put_contents($name, 'db');
            touch($name, 1700000000 + $i);
        }
        $lib = dirname(__DIR__, 2).'/bin/origens-lib.sh';
        $inner = 'source '.escapeshellarg($lib).'; prune_backups';
        exec('ORIGENS_DATA_ROOT='.escapeshellarg($root).' bash -c '.escapeshellarg($inner), $output, $code);
        $left = glob($root.'/backups/origens-*.sqlite') ?: [];
        self::assertSame(0, $code, implode("\n", $output));
        self::assertCount(10, $left);
        self::assertFileDoesNotExist(sprintf('%s/backups/origens-20260101T000000Z.sqlite', $root));
        self::assertFileExists(sprintf('%s/backups/origens-20260101T000011Z.sqlite', $root));
    }

    /** @return list<string> */
    private function statements(string $file, string $method): array
    {
        $source = (string) file_get_contents($file);
        $pattern = '/function '.$method.'\(Schema \$schema\): void\s*\{(.*?)\n    \}/s';
        self::assertSame(1, preg_match($pattern, $source, $match));
        preg_match_all('/addSql\(([\'"])(.*?)\\1\)/s', $match[1], $sql);
        self::assertNotEmpty($sql[2]);

        return array_map(static fn (string $statement): string => str_replace(["\\'", '\\"'], ["'", '"'], $statement), $sql[2]);
    }
}
