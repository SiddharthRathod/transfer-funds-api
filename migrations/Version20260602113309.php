<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602113309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transfers (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(15, 2) NOT NULL, reference VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, from_account_id INT NOT NULL, to_account_id INT NOT NULL, UNIQUE INDEX UNIQ_802A3918AEA34913 (reference), INDEX IDX_802A3918B0CF99BD (from_account_id), INDEX IDX_802A3918BC58BDC7 (to_account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE transfers ADD CONSTRAINT FK_802A3918B0CF99BD FOREIGN KEY (from_account_id) REFERENCES accounts (id)');
        $this->addSql('ALTER TABLE transfers ADD CONSTRAINT FK_802A3918BC58BDC7 FOREIGN KEY (to_account_id) REFERENCES accounts (id)');
        $this->addSql('ALTER TABLE accounts DROP currency, DROP created_at, CHANGE owner name VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transfers DROP FOREIGN KEY FK_802A3918B0CF99BD');
        $this->addSql('ALTER TABLE transfers DROP FOREIGN KEY FK_802A3918BC58BDC7');
        $this->addSql('DROP TABLE transfers');
        $this->addSql('ALTER TABLE accounts ADD currency VARCHAR(3) NOT NULL, ADD created_at DATETIME NOT NULL, CHANGE name owner VARCHAR(255) NOT NULL');
    }
}
