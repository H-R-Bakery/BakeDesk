<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add default label printer configuration and rendered document paths';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE printer ADD default_for_labels BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_printer_default_for_labels ON printer (default_for_labels) WHERE default_for_labels = TRUE');
        $this->addSql('ALTER TABLE print_job ADD document_path VARCHAR(2048) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE print_job DROP document_path');
        $this->addSql('DROP INDEX uniq_printer_default_for_labels');
        $this->addSql('ALTER TABLE printer DROP default_for_labels');
    }
}
