<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007223002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make updated_at nullable';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE bakery_order_number_seq CASCADE');
        $this->addSql('ALTER TABLE bakery_order ALTER updated_at DROP NOT NULL');
        $this->addSql('ALTER TABLE customer ALTER updated_at DROP NOT NULL');
        $this->addSql('ALTER TABLE employee ALTER updated_at DROP NOT NULL');
        $this->addSql('ALTER TABLE printer ALTER updated_at DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE bakery_order_number_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('ALTER TABLE bakery_order ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE customer ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE employee ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE printer ALTER updated_at SET NOT NULL');
    }
}
