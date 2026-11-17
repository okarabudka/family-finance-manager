# Family Finance Manager

A self-hosted family finance tracker with a Telegram bot as the main interface and a Symfony web dashboard for
detailed management.

## Context

A personal project built between March and April 2026 to track a family's money in one place: record expenses on the
go from Telegram, import bank statements, plan budgets and savings goals, and follow electricity and water meter
readings. It is a full Symfony application with a domain layer shared by two interfaces (bot and web), background
processing via Symfony Messenger, Docker-based development and production setups, and a CI/CD workflow. The project is
archived here; development continues in a newer project.

## Features

- **Telegram bot**: add expenses and income, transfer between accounts, check balances, monthly reports and spending
  statistics with charts, budgets, meter readings
- **Quick text input**: type `50 coffee` to record an expense; keyword rules pick the category, inline buttons allow
  changing the category or deleting the transaction
- **Multi-step dialogs** with inline keyboards for transactions, transfers, imports and meter readings
- **Bank statement import**: MT940 (`.sta`) and Sparkasse CSV, processed in the background with SHA-256
  deduplication; unrecognised transactions go to a `/pending` review queue
- **Categorisation rules** by counterparty IBAN (exact match) or keywords, evaluated by priority
- **Web dashboard**: transactions with filtering and pagination, accounts, hierarchical categories, budgets, savings
  goals, meter history with charts, imports, recommendations, user and family settings
- **Meter readings**: electricity (kWh) and water (m3) with optional photo and automatic consumption delta
- **Scheduled reminders** (daily / weekly / monthly) per family via Telegram and/or email
- **Weekly report** sent to all family members via Telegram
- **Family accounts**: several members share one family space with checking, savings, cash and credit card accounts
- **Admin panel** with EasyAdmin CRUD for all entities at `/admin`

Bot commands: `/start`, `/add`, `/income`, `/transfer`, `/balance`, `/report`, `/stats`, `/import`, `/pending`,
`/budget`, `/meter`, `/help`.

## Tech stack

- PHP 8.3, Symfony 7.4, Doctrine ORM
- PostgreSQL 16, Redis 7 (cache, sessions, Messenger transport)
- Symfony Messenger with a dedicated worker container
- EasyAdmin 5, Twig, Tailwind CSS, Chart.js, Webpack Encore
- Telegram Bot API (webhook), Quickchart.io for chart images in Telegram, League CSV
- Docker Compose (app, nginx, db, redis, worker), Nginx 1.25
- PHPUnit 12, PHPStan level 6 with Symfony and Doctrine extensions
- GitHub Actions: PHPStan + PHPUnit, then Docker image build and SSH deploy

## Architecture

- `src/Domain/`: business logic without HTTP or Telegram concerns (Account, Analytics, Category, Import, Meter,
  Suggestion, Transaction)
- `src/Telegram/`: update dispatcher, command registry and handlers, conversation manager with multi-step dialog
  implementations, sender and notifier
- `src/Controller/`: `Web/` dashboard, `Admin/` EasyAdmin CRUD, `Telegram/` webhook entry point
- `src/Message/` + `src/MessageHandler/`: async jobs for imports, notifications, reminders and report generation
- `src/Command/`: console commands for creating users, setting the webhook, sending reminders and the weekly report
- Telegram webhook requests are validated against a shared secret header; wrong or missing secrets get HTTP 403

Details on request flows, entities and services: [ARCHITECTURE.md](ARCHITECTURE.md). The original planning documents
are in [docs/plan](docs/plan).

## Getting started

Requirements: Docker Engine 24+ and Docker Compose v2. The bot needs a token from Telegram's BotFather and a public
HTTPS URL for the webhook (for local development, a tunnel such as ngrok).

```bash
git clone https://github.com/okarabudka/family-finance-manager.git
cd family-finance-manager

cp .env .env.local
# set at least TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_URL, TELEGRAM_WEBHOOK_SECRET

docker compose up -d
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction   # optional sample data

docker compose exec app php bin/console app:user:create admin@example.com "Admin" your_password --admin
docker compose exec app php bin/console app:telegram:set-webhook
```

Web interface: http://localhost:8080. Send `/start` to the bot to register and create a family with two default
accounts.

Main environment variables (placeholders only):

```dotenv
APP_SECRET=generate_with_openssl_rand_hex_32
DATABASE_URL=postgresql://app:your_db_password@db:5432/family_finance?serverVersion=16&charset=utf8
REDIS_URL=redis://redis:6379
MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages
TELEGRAM_BOT_TOKEN=your_bot_token
TELEGRAM_WEBHOOK_URL=https://yourdomain.example/telegram/webhook
TELEGRAM_WEBHOOK_SECRET=your_random_secret
STORAGE_LOCAL_PATH=%kernel.project_dir%/var/uploads
MAILER_DSN=null://null
```

Useful Make targets: `make up`, `make down`, `make shell`, `make migrate`, `make fixtures`, `make set-webhook`,
`make assets`, `make assets-watch`, `make cache-clear`.

Production uses `docker-compose.prod.yml` with the image built from `docker/php/Dockerfile.prod`, hourly cron for
`app:reminders:send`, a weekly cron for `app:report:weekly` and a daily database backup via `scripts/backup.sh`.

## Testing

Unit tests cover the domain services (accounts, transactions, quick input parser, categorisation rules, MT940 and
Sparkasse CSV parsers, import processing, suggestions, meters) and the reminder template entity.

```bash
make test       # docker compose exec app php bin/phpunit
make analyse    # PHPStan, level 6
make install-hooks   # optional git pre-commit hook: PHPStan + php -l on staged files
```

Manual end-to-end scenarios to run after a deploy are described in [TESTING.md](TESTING.md).

## Status

Archived. Developed from March to April 2026; the work continues in a newer project and this repository is no longer
maintained.

## License

MIT, see [LICENSE](LICENSE).
