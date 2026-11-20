<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a nullable sale price in cents to artefacts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artefacts ADD COLUMN sale_price_cents INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artefacts DROP COLUMN sale_price_cents');
    }
}
