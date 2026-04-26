<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add meter_readings table for electricity and water meter tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE meter_readings (
                id UUID NOT NULL,
                family_id UUID NOT NULL,
                created_by_id UUID NOT NULL,
                type VARCHAR(20) NOT NULL,
                value NUMERIC(10, 2) NOT NULL,
                reading_date DATE NOT NULL,
                photo_path VARCHAR(500) DEFAULT NULL,
                notes VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql('COMMENT ON COLUMN meter_readings.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN meter_readings.family_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN meter_readings.created_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN meter_readings.reading_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN meter_readings.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE INDEX idx_meter_readings_family_type_date ON meter_readings (family_id, type, reading_date)');

        $this->addSql('ALTER TABLE meter_readings ADD CONSTRAINT FK_meter_readings_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE meter_readings ADD CONSTRAINT FK_meter_readings_user FOREIGN KEY (created_by_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meter_readings DROP CONSTRAINT FK_meter_readings_family');
        $this->addSql('ALTER TABLE meter_readings DROP CONSTRAINT FK_meter_readings_user');
        $this->addSql('DROP TABLE meter_readings');
    }
}
