<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store AI profile jobs so generation can finish after the request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE profile_jobs (id CHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, stage VARCHAR(16) NOT NULL, attempts INTEGER NOT NULL, error CLOB DEFAULT NULL, result CLOB DEFAULT NULL, image_path VARCHAR(255) NOT NULL, audio_path VARCHAR(255) DEFAULT NULL, audio_mime VARCHAR(80) DEFAULT NULL, notes CLOB NOT NULL, answer CLOB NOT NULL, history CLOB NOT NULL, form CLOB NOT NULL, touched CLOB NOT NULL, run_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_profile_jobs_due ON profile_jobs (status, run_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE profile_jobs');
    }
}
