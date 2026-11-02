<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102125059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the artefact archive tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE artefact_counters (size VARCHAR(1) NOT NULL, category INTEGER NOT NULL, last_number INTEGER NOT NULL, PRIMARY KEY (size, category))');
        $this->addSql('CREATE TABLE artefacts (id CHAR(36) NOT NULL, code VARCHAR(32) NOT NULL, public_token VARCHAR(32) NOT NULL, size VARCHAR(1) NOT NULL, category INTEGER NOT NULL, title VARCHAR(180) NOT NULL, description VARCHAR(2000) NOT NULL, dimensions VARCHAR(100) NOT NULL, materials CLOB NOT NULL, care CLOB NOT NULL, handmade CLOB NOT NULL, images CLOB NOT NULL, record_date DATE NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E5C86F377153098 ON artefacts (code)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E5C86F3AE981E3B ON artefacts (public_token)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE artefact_counters');
        $this->addSql('DROP TABLE artefacts');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
