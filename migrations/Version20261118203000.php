<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Save a piece before AI and remember which fields were written by hand';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE artefacts ADD COLUMN notes CLOB NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE artefacts ADD COLUMN audio_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE artefacts ADD COLUMN audio_mime VARCHAR(80) DEFAULT NULL');
        $this->addSql("ALTER TABLE artefacts ADD COLUMN ai_status VARCHAR(16) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE artefacts ADD COLUMN manual_fields CLOB NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE profile_jobs ADD COLUMN artefact_id CHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_profile_jobs_artefact ON profile_jobs (artefact_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_profile_jobs_artefact');
        $this->addSql('ALTER TABLE profile_jobs DROP COLUMN artefact_id');
        $this->addSql('ALTER TABLE artefacts DROP COLUMN manual_fields');
        $this->addSql('ALTER TABLE artefacts DROP COLUMN ai_status');
        $this->addSql('ALTER TABLE artefacts DROP COLUMN audio_mime');
        $this->addSql('ALTER TABLE artefacts DROP COLUMN audio_path');
        $this->addSql('ALTER TABLE artefacts DROP COLUMN notes');
    }
}
