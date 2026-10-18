<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE booking (id INT AUTO_INCREMENT NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, customer_name VARCHAR(120) NOT NULL, customer_email VARCHAR(180) NOT NULL, customer_phone VARCHAR(30) DEFAULT NULL, note LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, cancel_token VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, cancelled_at DATETIME DEFAULT NULL, service_id INT NOT NULL, UNIQUE INDEX UNIQ_E00CEDDEAF53DC87 (cancel_token), INDEX idx_booking_period (starts_at, ends_at), INDEX IDX_E00CEDDEED5CA9E6 (service_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE opening_hour (id INT AUTO_INCREMENT NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, duration_minutes INT NOT NULL, price_cents INT NOT NULL, is_active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE time_off (id INT AUTO_INCREMENT NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, reason VARCHAR(255) DEFAULT NULL, INDEX idx_time_off_period (starts_at, ends_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEED5CA9E6 FOREIGN KEY (service_id) REFERENCES service (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEED5CA9E6');
        $this->addSql('DROP TABLE booking');
        $this->addSql('DROP TABLE opening_hour');
        $this->addSql('DROP TABLE service');
        $this->addSql('DROP TABLE time_off');
    }
}
