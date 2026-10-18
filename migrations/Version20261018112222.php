<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018112222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE booking (id INT AUTO_INCREMENT NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, customer_name VARCHAR(120) NOT NULL, customer_email VARCHAR(180) NOT NULL, customer_phone VARCHAR(30) DEFAULT NULL, note LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, cancel_token VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, cancelled_at DATETIME DEFAULT NULL, calendar_id INT NOT NULL, service_id INT NOT NULL, UNIQUE INDEX UNIQ_E00CEDDEAF53DC87 (cancel_token), INDEX idx_booking_calendar_period (calendar_id, starts_at, ends_at), INDEX IDX_E00CEDDEA40A2C8 (calendar_id), INDEX IDX_E00CEDDEED5CA9E6 (service_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE calendar (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(60) NOT NULL, description LONGTEXT DEFAULT NULL, is_active TINYINT NOT NULL, slot_step_minutes SMALLINT NOT NULL, min_notice_hours SMALLINT NOT NULL, horizon_weeks SMALLINT NOT NULL, cancel_notice_hours SMALLINT NOT NULL, requires_approval TINYINT NOT NULL, phone_required TINYINT NOT NULL, notes_enabled TINYINT NOT NULL, form_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, company_id INT NOT NULL, UNIQUE INDEX uniq_calendar_company_slug (company_id, slug), INDEX IDX_6EA9A146979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE calendar_service (calendar_id INT NOT NULL, service_id INT NOT NULL, INDEX IDX_83EAE115A40A2C8 (calendar_id), INDEX IDX_83EAE115ED5CA9E6 (service_id), PRIMARY KEY (calendar_id, service_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE company (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(60) NOT NULL, description LONGTEXT DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, accent_color VARCHAR(7) NOT NULL, logo_filename VARCHAR(255) DEFAULT NULL, timezone VARCHAR(64) NOT NULL, is_suspended TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_4FBF094F989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE opening_hour (id INT AUTO_INCREMENT NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, calendar_id INT NOT NULL, INDEX IDX_969BD765A40A2C8 (calendar_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, duration_minutes INT NOT NULL, price_cents INT NOT NULL, is_active TINYINT NOT NULL, company_id INT NOT NULL, INDEX IDX_E19D9AD2979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE time_off (id INT AUTO_INCREMENT NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, reason VARCHAR(255) DEFAULT NULL, calendar_id INT NOT NULL, INDEX idx_time_off_calendar_period (calendar_id, starts_at, ends_at), INDEX IDX_F13D3629A40A2C8 (calendar_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(120) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, is_verified TINYINT NOT NULL, company_role VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, company_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), INDEX IDX_8D93D649979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_calendar (user_id INT NOT NULL, calendar_id INT NOT NULL, INDEX IDX_8E244546A76ED395 (user_id), INDEX IDX_8E244546A40A2C8 (calendar_id), PRIMARY KEY (user_id, calendar_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEA40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEED5CA9E6 FOREIGN KEY (service_id) REFERENCES service (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE calendar ADD CONSTRAINT FK_6EA9A146979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calendar_service ADD CONSTRAINT FK_83EAE115A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calendar_service ADD CONSTRAINT FK_83EAE115ED5CA9E6 FOREIGN KEY (service_id) REFERENCES service (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE opening_hour ADD CONSTRAINT FK_969BD765A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service ADD CONSTRAINT FK_E19D9AD2979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_off ADD CONSTRAINT FK_F13D3629A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D649979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_calendar ADD CONSTRAINT FK_8E244546A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_calendar ADD CONSTRAINT FK_8E244546A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEA40A2C8');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEED5CA9E6');
        $this->addSql('ALTER TABLE calendar DROP FOREIGN KEY FK_6EA9A146979B1AD6');
        $this->addSql('ALTER TABLE calendar_service DROP FOREIGN KEY FK_83EAE115A40A2C8');
        $this->addSql('ALTER TABLE calendar_service DROP FOREIGN KEY FK_83EAE115ED5CA9E6');
        $this->addSql('ALTER TABLE opening_hour DROP FOREIGN KEY FK_969BD765A40A2C8');
        $this->addSql('ALTER TABLE service DROP FOREIGN KEY FK_E19D9AD2979B1AD6');
        $this->addSql('ALTER TABLE time_off DROP FOREIGN KEY FK_F13D3629A40A2C8');
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649979B1AD6');
        $this->addSql('ALTER TABLE user_calendar DROP FOREIGN KEY FK_8E244546A76ED395');
        $this->addSql('ALTER TABLE user_calendar DROP FOREIGN KEY FK_8E244546A40A2C8');
        $this->addSql('DROP TABLE booking');
        $this->addSql('DROP TABLE calendar');
        $this->addSql('DROP TABLE calendar_service');
        $this->addSql('DROP TABLE company');
        $this->addSql('DROP TABLE opening_hour');
        $this->addSql('DROP TABLE service');
        $this->addSql('DROP TABLE time_off');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE user_calendar');
    }
}
