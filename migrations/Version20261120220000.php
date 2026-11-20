<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a suggested price, 30% above the selling price unless she set it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artefacts ADD COLUMN suggested_price_cents INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE artefacts ADD COLUMN suggested_price_manual BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE artefacts SET suggested_price_cents = CAST(ROUND(sale_price_cents * 1.3) AS INTEGER), suggested_price_manual = 0 WHERE sale_price_cents IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artefacts DROP COLUMN suggested_price_manual');
        $this->addSql('ALTER TABLE artefacts DROP COLUMN suggested_price_cents');
    }
}
