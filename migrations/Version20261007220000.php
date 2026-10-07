<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the sequence for human-facing bakery order numbers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE public.bakery_order_number_seq START WITH 1000 INCREMENT BY 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE public.bakery_order_number_seq');
    }
}
