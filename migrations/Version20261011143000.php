<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store order-wide box numbering on label print jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE print_job ADD order_package_number INT DEFAULT NULL');
        $this->addSql('ALTER TABLE print_job ADD order_package_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE print_job DROP order_package_number');
        $this->addSql('ALTER TABLE print_job DROP order_package_count');
    }
}
