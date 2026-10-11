<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace item-local label metadata with immutable order package snapshots';
    }

    public function up(Schema $schema): void
    {
        // Development label jobs use the removed item-local metadata and cannot
        // be retried safely after this model change. Report jobs are retained.
        $this->addSql("DELETE FROM print_job WHERE document_type = 'label'");
        $this->addSql('ALTER TABLE print_job DROP CONSTRAINT FK_4FAA469BE415FB15');
        $this->addSql('DROP INDEX idx_print_job_order_item');
        $this->addSql('ALTER TABLE print_job DROP COLUMN order_item_id');
        $this->addSql('ALTER TABLE print_job DROP COLUMN package_quantity');
        $this->addSql('ALTER TABLE print_job DROP COLUMN order_package_number');
        $this->addSql('ALTER TABLE print_job DROP COLUMN order_package_count');
        $this->addSql('ALTER TABLE print_job ADD package_snapshot JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE print_job ADD label_set_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_print_job_label_set ON print_job (label_set_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_print_job_label_set');
        $this->addSql('ALTER TABLE print_job DROP COLUMN label_set_id');
        $this->addSql('ALTER TABLE print_job DROP COLUMN package_snapshot');
        $this->addSql('ALTER TABLE print_job ADD package_quantity INT DEFAULT NULL');
        $this->addSql('ALTER TABLE print_job ADD order_package_number INT DEFAULT NULL');
        $this->addSql('ALTER TABLE print_job ADD order_package_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE print_job ADD order_item_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_print_job_order_item ON print_job (order_item_id)');
        $this->addSql('ALTER TABLE print_job ADD CONSTRAINT FK_4FAA469BE415FB15 FOREIGN KEY (order_item_id) REFERENCES order_item (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }
}
