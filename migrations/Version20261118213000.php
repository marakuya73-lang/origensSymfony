<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a running profile job run once more when the piece gains audio or notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile_jobs ADD COLUMN rerun BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile_jobs DROP COLUMN rerun');
    }
}
