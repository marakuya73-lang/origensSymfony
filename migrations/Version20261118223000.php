<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember how long a saved recording lasts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artefacts ADD COLUMN audio_duration_ms INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artefacts DROP COLUMN audio_duration_ms');
    }
}
