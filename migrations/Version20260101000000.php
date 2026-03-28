<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: all tables for the family finance manager application.';
    }

    public function up(Schema $schema): void
    {
        // families
        $this->addSql(<<<'SQL'
            CREATE TABLE families (
                id UUID NOT NULL,
                name VARCHAR(100) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
                PRIMARY KEY (id)
            )
        SQL);

        // users
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id UUID NOT NULL,
                family_id UUID DEFAULT NULL,
                email VARCHAR(180) NOT NULL,
                password VARCHAR(255) NOT NULL,
                name VARCHAR(100) NOT NULL,
                telegram_chat_id BIGINT DEFAULT NULL,
                telegram_username VARCHAR(255) DEFAULT NULL,
                roles JSON NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN users.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN users.family_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE INDEX IDX_1483A5E9C35E566A ON users (family_id)');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9C35E566A FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // accounts
        $this->addSql(<<<'SQL'
            CREATE TABLE accounts (
                id UUID NOT NULL,
                family_id UUID NOT NULL,
                name VARCHAR(100) NOT NULL,
                type VARCHAR(255) NOT NULL,
                initial_balance NUMERIC(15, 2) NOT NULL DEFAULT 0,
                currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
                iban VARCHAR(34) DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                include_in_total BOOLEAN NOT NULL DEFAULT TRUE,
                sort_order SMALLINT NOT NULL DEFAULT 0,
                is_default BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN accounts.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN accounts.family_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE INDEX IDX_CAC89EACC35E566A ON accounts (family_id)');
        $this->addSql('ALTER TABLE accounts ADD CONSTRAINT FK_CAC89EACC35E566A FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // categories
        $this->addSql(<<<'SQL'
            CREATE TABLE categories (
                id UUID NOT NULL,
                family_id UUID NOT NULL,
                parent_id UUID DEFAULT NULL,
                name VARCHAR(100) NOT NULL,
                type VARCHAR(255) NOT NULL,
                icon VARCHAR(10) DEFAULT NULL,
                color VARCHAR(7) DEFAULT NULL,
                is_system BOOLEAN NOT NULL DEFAULT FALSE,
                sort_order SMALLINT NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN categories.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN categories.family_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN categories.parent_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE INDEX IDX_3AF34668C35E566A ON categories (family_id)');
        $this->addSql('CREATE INDEX IDX_3AF34668727ACA70 ON categories (parent_id)');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_3AF34668C35E566A FOREIGN KEY (family_id) REFERENCES families (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_3AF34668727ACA70 FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // category_rules
        $this->addSql(<<<'SQL'
            CREATE TABLE category_rules (
                id UUID NOT NULL,
                family_id UUID NOT NULL,
                category_id UUID NOT NULL,
                keywords JSON NOT NULL,
                counterparty_iban VARCHAR(34) DEFAULT NULL,
                priority SMALLINT NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN category_rules.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN category_rules.family_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN category_rules.category_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE INDEX IDX_7D5C9B14C35E566A ON category_rules (family_id)');
        $this->addSql('CREATE INDEX IDX_7D5C9B1412469DE2 ON category_rules (category_id)');
        $this->addSql('ALTER TABLE category_rules ADD CONSTRAINT FK_7D5C9B14C35E566A FOREIGN KEY (family_id) REFERENCES families (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE category_rules ADD CONSTRAINT FK_7D5C9B1412469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // import_files
        $this->addSql(<<<'SQL'
            CREATE TABLE import_files (
                id UUID NOT NULL,
                account_id UUID NOT NULL,
                uploaded_by_id UUID NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                storage_path VARCHAR(255) NOT NULL,
                format VARCHAR(20) NOT NULL,
                status VARCHAR(255) NOT NULL,
                error_message TEXT DEFAULT NULL,
                total_rows INT NOT NULL DEFAULT 0,
                imported_rows INT NOT NULL DEFAULT 0,
                skipped_rows INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN import_files.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN import_files.account_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN import_files.uploaded_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN import_files.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_6B24BDD69B6B5FBA ON import_files (account_id)');
        $this->addSql('CREATE INDEX IDX_6B24BDD6A2B28FE8 ON import_files (uploaded_by_id)');
        $this->addSql('CREATE INDEX IDX_6B24BDD67B00651C ON import_files (status)');
        $this->addSql('ALTER TABLE import_files ADD CONSTRAINT FK_6B24BDD69B6B5FBA FOREIGN KEY (account_id) REFERENCES accounts (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE import_files ADD CONSTRAINT FK_6B24BDD6A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // import_transactions
        $this->addSql(<<<'SQL'
            CREATE TABLE import_transactions (
                id UUID NOT NULL,
                import_file_id UUID NOT NULL,
                suggested_category_id UUID DEFAULT NULL,
                amount NUMERIC(15, 2) NOT NULL,
                date DATE NOT NULL,
                raw_description VARCHAR(500) DEFAULT NULL,
                counterparty_name VARCHAR(100) DEFAULT NULL,
                counterparty_iban VARCHAR(34) DEFAULT NULL,
                confidence_score DOUBLE PRECISION DEFAULT NULL,
                status VARCHAR(255) NOT NULL,
                deduplication_hash VARCHAR(64) NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN import_transactions.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN import_transactions.import_file_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN import_transactions.suggested_category_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN import_transactions.date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_import_tx_hash ON import_transactions (deduplication_hash)');
        $this->addSql('CREATE INDEX IDX_import_tx_file ON import_transactions (import_file_id)');
        $this->addSql('CREATE INDEX IDX_import_tx_status ON import_transactions (status)');
        $this->addSql('ALTER TABLE import_transactions ADD CONSTRAINT FK_import_tx_file FOREIGN KEY (import_file_id) REFERENCES import_files (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE import_transactions ADD CONSTRAINT FK_import_tx_category FOREIGN KEY (suggested_category_id) REFERENCES categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // transactions
        $this->addSql(<<<'SQL'
            CREATE TABLE transactions (
                id UUID NOT NULL,
                family_id UUID NOT NULL,
                created_by_id UUID NOT NULL,
                source_account_id UUID DEFAULT NULL,
                target_account_id UUID DEFAULT NULL,
                category_id UUID DEFAULT NULL,
                import_origin_id UUID DEFAULT NULL,
                type VARCHAR(255) NOT NULL,
                amount NUMERIC(15, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
                description VARCHAR(500) DEFAULT NULL,
                executed_at DATE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                is_internal_transfer BOOLEAN NOT NULL DEFAULT FALSE,
                is_confirmed BOOLEAN NOT NULL DEFAULT TRUE,
                tags JSON DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN transactions.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transactions.family_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transactions.created_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transactions.source_account_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transactions.target_account_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transactions.category_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transactions.import_origin_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transactions.executed_at IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN transactions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_EAA81A4CC35E566A ON transactions (family_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4CB03A8386 ON transactions (created_by_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4CE6F98D4 ON transactions (executed_at)');
        $this->addSql('CREATE INDEX IDX_EAA81A4CC35E566A_E6F98D4 ON transactions (family_id, executed_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EAA81A4C8B20DDCE ON transactions (import_origin_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C12469DE2 ON transactions (category_id)');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4CC35E566A FOREIGN KEY (family_id) REFERENCES families (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4CB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4CE6BD68B5 FOREIGN KEY (source_account_id) REFERENCES accounts (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4C18BD6CF6 FOREIGN KEY (target_account_id) REFERENCES accounts (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4C12469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4C8B20DDCE FOREIGN KEY (import_origin_id) REFERENCES import_transactions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // savings_goals
        $this->addSql(<<<'SQL'
            CREATE TABLE savings_goals (
                id UUID NOT NULL,
                family_id UUID NOT NULL,
                account_id UUID NOT NULL,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(500) DEFAULT NULL,
                target_amount NUMERIC(15, 2) DEFAULT NULL,
                target_date DATE DEFAULT NULL,
                status VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN savings_goals.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN savings_goals.family_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN savings_goals.account_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN savings_goals.target_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN savings_goals.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_savings_goals_family ON savings_goals (family_id)');
        $this->addSql('CREATE INDEX IDX_savings_goals_status ON savings_goals (status)');
        $this->addSql('ALTER TABLE savings_goals ADD CONSTRAINT FK_savings_goals_family FOREIGN KEY (family_id) REFERENCES families (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE savings_goals ADD CONSTRAINT FK_savings_goals_account FOREIGN KEY (account_id) REFERENCES accounts (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // recommendations
        $this->addSql(<<<'SQL'
            CREATE TABLE recommendations (
                id UUID NOT NULL,
                family_id UUID NOT NULL,
                type VARCHAR(50) NOT NULL,
                priority SMALLINT NOT NULL,
                title VARCHAR(200) NOT NULL,
                message TEXT NOT NULL,
                period VARCHAR(7) NOT NULL,
                seen_by_user BOOLEAN NOT NULL DEFAULT FALSE,
                generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN recommendations.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN recommendations.family_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN recommendations.generated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_recommendations_family ON recommendations (family_id)');
        $this->addSql('CREATE INDEX IDX_recommendations_period ON recommendations (family_id, period)');
        $this->addSql('ALTER TABLE recommendations ADD CONSTRAINT FK_recommendations_family FOREIGN KEY (family_id) REFERENCES families (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // budgets
        $this->addSql(<<<'SQL'
            CREATE TABLE budgets (
                id UUID NOT NULL,
                family_id UUID NOT NULL,
                category_id UUID NOT NULL,
                year SMALLINT NOT NULL,
                month SMALLINT NOT NULL,
                planned_amount NUMERIC(15, 2) NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN budgets.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN budgets.family_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN budgets.category_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_budgets_family_category_year_month ON budgets (family_id, category_id, year, month)');
        $this->addSql('CREATE INDEX IDX_budgets_family ON budgets (family_id)');
        $this->addSql('CREATE INDEX IDX_budgets_family_year_month ON budgets (family_id, year, month)');
        $this->addSql('ALTER TABLE budgets ADD CONSTRAINT FK_budgets_family FOREIGN KEY (family_id) REFERENCES families (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE budgets ADD CONSTRAINT FK_budgets_category FOREIGN KEY (category_id) REFERENCES categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // doctrine_migration_versions is managed by the migrations bundle itself;
        // add the families UUID comment last
        $this->addSql('COMMENT ON COLUMN families.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN accounts.id IS \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budgets DROP CONSTRAINT FK_budgets_family');
        $this->addSql('ALTER TABLE budgets DROP CONSTRAINT FK_budgets_category');
        $this->addSql('ALTER TABLE recommendations DROP CONSTRAINT FK_recommendations_family');
        $this->addSql('ALTER TABLE savings_goals DROP CONSTRAINT FK_savings_goals_family');
        $this->addSql('ALTER TABLE savings_goals DROP CONSTRAINT FK_savings_goals_account');
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4CC35E566A');
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4CB03A8386');
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4CE6BD68B5');
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4C18BD6CF6');
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4C12469DE2');
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4C8B20DDCE');
        $this->addSql('ALTER TABLE import_transactions DROP CONSTRAINT FK_import_tx_file');
        $this->addSql('ALTER TABLE import_transactions DROP CONSTRAINT FK_import_tx_category');
        $this->addSql('ALTER TABLE import_files DROP CONSTRAINT FK_6B24BDD69B6B5FBA');
        $this->addSql('ALTER TABLE import_files DROP CONSTRAINT FK_6B24BDD6A2B28FE8');
        $this->addSql('ALTER TABLE category_rules DROP CONSTRAINT FK_7D5C9B14C35E566A');
        $this->addSql('ALTER TABLE category_rules DROP CONSTRAINT FK_7D5C9B1412469DE2');
        $this->addSql('ALTER TABLE categories DROP CONSTRAINT FK_3AF34668C35E566A');
        $this->addSql('ALTER TABLE categories DROP CONSTRAINT FK_3AF34668727ACA70');
        $this->addSql('ALTER TABLE accounts DROP CONSTRAINT FK_CAC89EACC35E566A');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E9C35E566A');
        $this->addSql('DROP TABLE budgets');
        $this->addSql('DROP TABLE recommendations');
        $this->addSql('DROP TABLE savings_goals');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE import_transactions');
        $this->addSql('DROP TABLE import_files');
        $this->addSql('DROP TABLE category_rules');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE accounts');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE families');
    }
}
