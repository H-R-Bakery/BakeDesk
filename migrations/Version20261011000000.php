<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert bakery quantities and package metadata from decimals to integers';
    }

    public function up(Schema $schema): void
    {
        // PostgreSQL may round numeric values when casting to integer. Refuse
        // fractional historical data before any column is altered.
        $this->addSql("DO $$\nBEGIN\n    IF EXISTS (SELECT 1 FROM order_item WHERE quantity <> trunc(quantity)) THEN\n        RAISE EXCEPTION 'Cannot convert order_item.quantity to integer: fractional values exist.';\n    END IF;\nEND $$");
        $this->addSql("DO $$\nBEGIN\n    IF EXISTS (SELECT 1 FROM unit WHERE each_equivalent <> trunc(each_equivalent)) THEN\n        RAISE EXCEPTION 'Cannot convert unit.each_equivalent to integer: fractional values exist.';\n    END IF;\nEND $$");
        $this->addSql("DO $$\nBEGIN\n    IF EXISTS (SELECT 1 FROM packaging_rule WHERE quantity_per_package <> trunc(quantity_per_package)) THEN\n        RAISE EXCEPTION 'Cannot convert packaging_rule.quantity_per_package to integer: fractional values exist.';\n    END IF;\nEND $$");
        $this->addSql("DO $$\nBEGIN\n    IF EXISTS (SELECT 1 FROM print_job WHERE package_quantity IS NOT NULL AND package_quantity <> trunc(package_quantity)) THEN\n        RAISE EXCEPTION 'Cannot convert print_job.package_quantity to integer: fractional values exist.';\n    END IF;\nEND $$");

        $this->addSql('ALTER TABLE order_item ALTER quantity TYPE INT USING quantity::integer');
        $this->addSql('ALTER TABLE unit ALTER each_equivalent TYPE INT USING each_equivalent::integer');
        $this->addSql('ALTER TABLE packaging_rule ALTER quantity_per_package TYPE INT USING quantity_per_package::integer');
        $this->addSql('ALTER TABLE print_job ALTER package_quantity TYPE INT USING package_quantity::integer');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_item ALTER quantity TYPE NUMERIC(10, 2) USING quantity::numeric');
        $this->addSql('ALTER TABLE unit ALTER each_equivalent TYPE NUMERIC(10, 2) USING each_equivalent::numeric');
        $this->addSql('ALTER TABLE packaging_rule ALTER quantity_per_package TYPE NUMERIC(10, 2) USING quantity_per_package::numeric');
        $this->addSql('ALTER TABLE print_job ALTER package_quantity TYPE NUMERIC(10, 2) USING package_quantity::numeric');
    }
}
