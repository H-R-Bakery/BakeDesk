<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261008182235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update packaging rule and printer tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_packaging_rule_product_unit_active');
        $this->addSql('CREATE UNIQUE INDEX uniq_packaging_rule_product_unit_active ON packaging_rule (product_type_id, unit_id, active) WHERE active = TRUE');
        $this->addSql('DROP INDEX uniq_printer_default_for_labels');
        $this->addSql('CREATE UNIQUE INDEX uniq_printer_default_for_labels ON printer (default_for_labels) WHERE default_for_labels = TRUE');
        $this->addSql('ALTER TABLE unit RENAME COLUMN is_package_unit TO package_unit');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_packaging_rule_product_unit_active');
        $this->addSql('CREATE UNIQUE INDEX uniq_packaging_rule_product_unit_active ON packaging_rule (product_type_id, unit_id, active) WHERE (active = true)');
        $this->addSql('DROP INDEX uniq_printer_default_for_labels');
        $this->addSql('CREATE UNIQUE INDEX uniq_printer_default_for_labels ON printer (default_for_labels) WHERE (default_for_labels = true)');
        $this->addSql('ALTER TABLE unit RENAME COLUMN package_unit TO is_package_unit');
    }
}
