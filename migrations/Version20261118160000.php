<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an optional symbolic message to artefacts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE artefacts ADD COLUMN symbolism CLOB NOT NULL DEFAULT ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artefacts DROP COLUMN symbolism');
    }
}
