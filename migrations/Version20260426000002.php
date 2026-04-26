<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reminder_templates table for scheduled Telegram/email notifications.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reminder_templates (
                id UUID NOT NULL,
                family_id UUID DEFAULT NULL,
                name VARCHAR(100) NOT NULL,
                type VARCHAR(30) NOT NULL,
                frequency VARCHAR(10) NOT NULL,
                send_hour SMALLINT NOT NULL DEFAULT 9,
                day_of_week SMALLINT DEFAULT NULL,
                day_of_month SMALLINT DEFAULT NULL,
                message_text TEXT NOT NULL,
                send_via_telegram BOOLEAN NOT NULL DEFAULT TRUE,
                send_via_email BOOLEAN NOT NULL DEFAULT FALSE,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                last_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql('COMMENT ON COLUMN reminder_templates.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN reminder_templates.family_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN reminder_templates.last_sent_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN reminder_templates.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE INDEX idx_reminder_templates_active ON reminder_templates (is_active)');
        $this->addSql('CREATE INDEX idx_reminder_templates_family ON reminder_templates (family_id)');

        $this->addSql('ALTER TABLE reminder_templates ADD CONSTRAINT FK_reminder_templates_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reminder_templates DROP CONSTRAINT FK_reminder_templates_family');
        $this->addSql('DROP TABLE reminder_templates');
    }
}
