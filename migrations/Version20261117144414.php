<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117144414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow a longer artefact description';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__artefacts AS SELECT id, code, public_token, size, category, title, description, dimensions, materials, care, handmade, images, record_date, created_at, sale_price_cents FROM artefacts');
        $this->addSql('DROP TABLE artefacts');
        $this->addSql('CREATE TABLE artefacts (id CHAR(36) NOT NULL, code VARCHAR(32) NOT NULL, public_token VARCHAR(32) NOT NULL, size VARCHAR(1) NOT NULL, category INTEGER NOT NULL, title VARCHAR(180) NOT NULL, description VARCHAR(8000) NOT NULL, dimensions VARCHAR(100) NOT NULL, materials CLOB NOT NULL, care CLOB NOT NULL, handmade CLOB NOT NULL, images CLOB NOT NULL, record_date DATE NOT NULL, created_at DATETIME NOT NULL, sale_price_cents INTEGER DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO artefacts (id, code, public_token, size, category, title, description, dimensions, materials, care, handmade, images, record_date, created_at, sale_price_cents) SELECT id, code, public_token, size, category, title, description, dimensions, materials, care, handmade, images, record_date, created_at, sale_price_cents FROM __temp__artefacts');
        $this->addSql('DROP TABLE __temp__artefacts');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E5C86F3AE981E3B ON artefacts (public_token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E5C86F377153098 ON artefacts (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__artefacts AS SELECT id, code, public_token, size, category, title, description, dimensions, materials, care, handmade, images, record_date, created_at, sale_price_cents FROM artefacts');
        $this->addSql('DROP TABLE artefacts');
        $this->addSql('CREATE TABLE artefacts (id CHAR(36) NOT NULL, code VARCHAR(32) NOT NULL, public_token VARCHAR(32) NOT NULL, size VARCHAR(1) NOT NULL, category INTEGER NOT NULL, title VARCHAR(180) NOT NULL, description VARCHAR(2000) NOT NULL, dimensions VARCHAR(100) NOT NULL, materials CLOB NOT NULL, care CLOB NOT NULL, handmade CLOB NOT NULL, images CLOB NOT NULL, record_date DATE NOT NULL, created_at DATETIME NOT NULL, sale_price_cents INTEGER DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO artefacts (id, code, public_token, size, category, title, description, dimensions, materials, care, handmade, images, record_date, created_at, sale_price_cents) SELECT id, code, public_token, size, category, title, description, dimensions, materials, care, handmade, images, record_date, created_at, sale_price_cents FROM __temp__artefacts');
        $this->addSql('DROP TABLE __temp__artefacts');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E5C86F377153098 ON artefacts (code)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E5C86F3AE981E3B ON artefacts (public_token)');
    }
}
