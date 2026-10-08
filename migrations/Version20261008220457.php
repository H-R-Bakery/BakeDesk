<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008220457 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add configurable individual-item conversion to units';
    }

    public function up(Schema $schema): void
    {
        // Existing units receive a valid neutral value and must be reviewed by an administrator.
        $this->addSql('ALTER TABLE unit ADD each_equivalent NUMERIC(10, 2) DEFAULT 1.00 NOT NULL');
        $this->addSql('ALTER TABLE unit ALTER each_equivalent DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unit DROP each_equivalent');
    }
}
