# Family Finance Manager

A self-hosted family finance tracker with a Telegram bot as the primary interface and a web dashboard for detailed management. Built with PHP 8.3 / Symfony 7.4, PostgreSQL 16, and Redis 7. Background processing (bank imports, async notifications, scheduled reminders) runs via Symfony Messenger with a dedicated worker container.

---

## Features

- **Telegram bot** — add expenses and income on the go, check balances, view monthly reports, and submit meter readings without opening a browser
- **Quick text input** — type `50 coffee` in the chat to record an expense instantly
- **Multi-step interactive dialogs** — guided flows for transactions, transfers, imports, and meter readings via inline keyboard buttons
- **Web dashboard** — full management of transactions, accounts, categories, budgets, savings goals, and recommendations
- **Meter readings** — submit electricity (kWh) and water (m³) readings via Telegram with optional photo; view consumption history and bar charts in the web dashboard
- **Scheduled reminders** — configure recurring notifications (daily / weekly / monthly) per family in the admin panel; delivered via Telegram and/or email
- **Bank statement import** — parse MT940 (`.sta`) and Sparkasse CSV files; processed in background with SHA-256 deduplication
- **Pending categorization** — unrecognized imported transactions queue for manual review via `/pending`
- **Budgets** — track spending limits per category with monthly planning
- **Savings goals** — track progress toward savings targets with status tracking
- **Recommendations** — automated financial suggestions visible in the web dashboard
- **Analytics and charts** — monthly reports with category breakdown; charts via Quickchart.io for Telegram and Chart.js for the web
- **Family accounts** — multiple members share one family space with separate accounts (checking, savings, cash, credit card)
- **Admin panel** — EasyAdmin 5 CRUD for all entities at `/admin`

---

## Requirements

### Option A: Docker (recommended)

- Docker Engine 24+
- Docker Compose v2+

### Option B: Local / bare-metal

- PHP 8.3 with extensions: `pdo_pgsql`, `redis`, `intl`, `mbstring`, `curl`
- Composer 2
- PostgreSQL 16
- Redis 7
- Node.js 20+ with npm (for frontend assets)

---

## Quick Start (Docker)

```bash
git clone https://github.com/okarabudka/family-finance-manager.git
cd family-finance-manager

# Copy and edit environment variables
cp .env .env.local
# Set at minimum: TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_URL, TELEGRAM_WEBHOOK_SECRET

# Start all services (app, nginx, db, redis, worker)
docker compose up -d

# Install PHP dependencies
docker compose exec app composer install

# Run database migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# (Optional) Load sample data
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction
```

Web interface: **http://localhost:8080**

---

## Telegram Bot Setup

### Step 1 — Create a bot via BotFather

1. Open Telegram, start a chat with **@BotFather**
2. Send `/newbot` and follow the prompts
3. Copy the bot token (format: `123456789:AAF...`)

### Step 2 — Configure environment variables

```dotenv
TELEGRAM_BOT_TOKEN=123456789:AAFxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TELEGRAM_WEBHOOK_URL=https://yourdomain.com/telegram/webhook
TELEGRAM_WEBHOOK_SECRET=your_random_secret_here
```

Generate a secure secret:

```bash
openssl rand -hex 32
```

### Step 3 — Expose your server (HTTPS required)

Telegram only posts to public HTTPS endpoints.

**Production:** set `TELEGRAM_WEBHOOK_URL` to your real domain.

**Local development with ngrok:**

```bash
ngrok http 8080
# Copy the https:// URL, append /telegram/webhook, set as TELEGRAM_WEBHOOK_URL
```

Re-run `set-webhook` every time ngrok gives a new URL.

### Step 4 — Register the webhook

```bash
docker compose exec app php bin/console app:telegram:set-webhook
# or: make set-webhook
```

### Step 5 — Test the bot

Send `/start` to your bot. It will ask for your name and create a family with two default accounts.

### Webhook security

Every request is validated against `TELEGRAM_WEBHOOK_SECRET` via the `X-Telegram-Bot-Api-Secret-Token` header. Requests with wrong or missing secret are rejected with HTTP 403.

---

## Bot Commands

| Command | Description |
|---|---|
| `/start` | Register or return to main menu |
| `/add` | Add an expense (guided dialog) |
| `/income` | Add income (guided dialog) |
| `/transfer` | Transfer between accounts (guided dialog) |
| `/balance` | Show current balance for all accounts |
| `/report` | Monthly income/expense report with chart |
| `/stats` | Spending analytics with chart |
| `/import` | Upload a bank statement (MT940 or Sparkasse CSV) |
| `/pending` | Review and categorize unclassified import transactions |
| `/budget` | View monthly budget limits per category |
| `/meter` | Submit an electricity or water meter reading |
| `/help` | Show all available commands |

### Quick text input

Type amount and description directly — no command needed:

```
50 coffee
150.50 restaurant
```

The bot auto-categorizes via keyword rules and posts to the default account. Inline buttons let you change the category or delete the transaction.

---

## Meter Readings

Track electricity and water consumption without leaving Telegram.

### Submitting a reading

1. Send `/meter`
2. Select **⚡ Electricity** or **💧 Water**
3. Type the current value (e.g. `12345.67`). The bot shows the previous reading for reference
4. Send a photo of the meter — or type `skip`

The reading is saved with today's date. Consumption delta is calculated automatically.

### Web dashboard — `/meters`

- Bar charts of consumption over time (kWh / m³)
- Full reading history with consumption delta per period
- Links to view saved meter photos
- Delete individual readings

---

## Reminders

Schedule recurring notifications delivered to family members via Telegram and/or email.

### Configuring reminders

Admin panel → **Notifications → Reminders** (`/admin`).

| Field | Description |
|---|---|
| **Name** | Internal label, e.g. "Submit electricity meter" |
| **Family** | Target family — leave empty to send to all families |
| **Type** | `meter_electricity`, `meter_water`, `budget_check`, `savings_check`, or `custom` |
| **Frequency** | `daily`, `weekly`, or `monthly` |
| **Send at hour** | Hour 0–23; the cron command runs hourly and fires when current hour matches |
| **Day of week** | For weekly: 1 = Monday … 7 = Sunday |
| **Day of month** | For monthly: 1–31 |
| **Message text** | Notification body sent to all recipients |
| **Send via Telegram** | On/off |
| **Send via Email** | On/off (requires `MAILER_DSN`) |
| **Active** | Enable/disable without deleting |

### Cron setup (production)

```bash
crontab -e
# Add:
0 * * * * docker compose -f /opt/family-finance/docker-compose.prod.yml exec -T app php bin/console app:reminders:send >> /var/log/family-finance-reminders.log 2>&1
```

The command checks every active template, dispatches due reminders to the worker queue, and updates `last_sent_at` to prevent duplicate sends.

### Email configuration

```dotenv
# Disable (default, safe for dev)
MAILER_DSN=null://null

# SMTP
MAILER_DSN=smtp://user:pass@smtp.example.com:587

# Gmail
MAILER_DSN=gmail+smtp://your@gmail.com:app-password@default

MAILER_FROM=noreply@yourdomain.com
```

---

## Web Interface

Open **http://localhost:8080**.

| Section | URL | Description |
|---|---|---|
| Dashboard | `/dashboard` | Balance summary, 6-month trend |
| Transactions | `/transactions` | List with filtering, search, pagination |
| Accounts | `/accounts` | Create and manage accounts |
| Categories | `/categories` | Hierarchical category tree |
| Budgets | `/budgets` | Monthly limits per category |
| Savings Goals | `/savings-goals` | Target tracking with progress |
| Meters | `/meters` | Electricity and water reading history + charts |
| Imports | `/imports` | Uploaded bank statement files |
| Pending | `/imports/pending` | Unclassified import transactions |
| Recommendations | `/recommendations` | Automated financial suggestions |
| Settings | `/settings` | User profile, family settings, add family member |
| Admin | `/admin` | EasyAdmin CRUD for all entities |

### Create the first web user

```bash
docker compose exec app php bin/console app:user:create admin@example.com "Admin" yourpassword --admin
```

---

## Adding a Second Family Member

### Via web settings

Go to **Settings → Add Family Member** (`/settings/users/new`) to create a new user directly into your family.

### Via Telegram (manual SQL)

1. Second person sends `/start` — creates their own family
2. Find their record:
   ```sql
   SELECT id, name, family_id FROM users ORDER BY created_at DESC LIMIT 5;
   ```
3. Move them into your family:
   ```sql
   UPDATE users SET family_id = '<your-family-uuid>' WHERE id = '<their-uuid>';
   DELETE FROM families WHERE id = '<orphaned-family-uuid>';
   ```

### Via console (web-only users)

```bash
docker compose exec app php bin/console app:user:create member@example.com "Anna" password
# Then reassign family_id via SQL as above
```

---

## Categorization Rules

Rules auto-assign categories to imported transactions. Two matching strategies:

- **By counterparty IBAN** — exact match, highest priority. For recurring payments (rent, salary, insurance).
- **By keyword** — case-insensitive substring match in description. Multiple keywords per rule.

Rules evaluated in descending `priority` order. First match wins. No-match transactions go to `pending`.

### Add a rule via SQL

```sql
SELECT id, name FROM families;
SELECT id, name FROM categories WHERE family_id = '<family-uuid>';

-- IBAN rule
INSERT INTO category_rules (id, family_id, category_id, keywords, counterparty_iban, priority)
VALUES (gen_random_uuid(), '<family-uuid>', '<category-uuid>', '[]', 'DE89370400440532013000', 10);

-- Keyword rule
INSERT INTO category_rules (id, family_id, category_id, keywords, counterparty_iban, priority)
VALUES (gen_random_uuid(), '<family-uuid>', '<category-uuid>', '["rewe", "lidl", "aldi"]', NULL, 5);
```

### Connect to the database

```bash
# Dev
docker compose exec db psql -U app family_finance

# Prod
docker compose -f docker-compose.prod.yml exec db psql -U app family_finance
```

---

## Bank Statement Import

Supported formats:

- **MT940** (`.sta`) — standard German bank export
- **Sparkasse CSV** (`.csv`) — Sparkasse-specific CSV export

Workflow:

1. Send `/import` to the bot
2. Select the target account
3. Upload the file as a Telegram document attachment
4. Processing happens in the background — you get a Telegram notification when done with import counts
5. Use `/pending` to categorize unclassified transactions

Deduplication: SHA-256 hash of key transaction fields prevents double-imports.

---

## Console Commands

| Command | Description |
|---|---|
| `app:user:create <email> <name> <password> [--admin]` | Create a new web user |
| `app:telegram:set-webhook` | Register the Telegram webhook with Telegram's API |
| `app:report:weekly` | Generate and send weekly financial summary to all family members via Telegram |
| `app:reminders:send` | Check active reminder templates and dispatch due notifications |

---

## Make Commands

| Command | Description |
|---|---|
| `make up` | Start all Docker containers |
| `make down` | Stop and remove all containers |
| `make shell` | Open bash in the app container |
| `make migrate` | Run pending database migrations |
| `make fixtures` | Load fixture/seed data (dev only) |
| `make test` | Run PHPUnit test suite |
| `make cache-clear` | Clear Symfony cache |
| `make set-webhook` | Register Telegram webhook |
| `make assets` | Build frontend assets (one-time) |
| `make assets-watch` | Build assets and watch for changes |
| `make analyse` | Run PHPStan static analysis |
| `make install-hooks` | Install git pre-commit hook (run once after cloning) |

---

## Configuration Reference

| Variable | Description | Default / Example |
|---|---|---|
| `APP_ENV` | Application environment | `dev` / `prod` |
| `APP_SECRET` | Symfony secret — generate once, never rotate | `openssl rand -hex 32` |
| `DATABASE_URL` | PostgreSQL DSN | `postgresql://app:secret@db:5432/family_finance?serverVersion=16&charset=utf8` |
| `REDIS_URL` | Redis URL (cache, sessions) | `redis://redis:6379` |
| `MESSENGER_TRANSPORT_DSN` | Messenger queue transport | `redis://redis:6379/messages` |
| `TELEGRAM_BOT_TOKEN` | Bot token from @BotFather | `123456789:AAFxxxxxx` |
| `TELEGRAM_WEBHOOK_URL` | Public HTTPS URL for Telegram updates | `https://yourdomain.com/telegram/webhook` |
| `TELEGRAM_WEBHOOK_SECRET` | Secret to authenticate Telegram requests | 32+ random hex chars |
| `STORAGE_LOCAL_PATH` | Directory for uploaded files (imports, meter photos) | `%kernel.project_dir%/var/uploads` |
| `DEFAULT_URI` | Base URL for CLI-generated links | `https://yourdomain.com` |
| `MAILER_DSN` | Mailer transport DSN | `null://null` (disabled) |
| `MAILER_FROM` | From address for reminder emails | `noreply@yourdomain.com` |

> **`APP_SECRET`** signs Symfony sessions and tokens. Generate once with `openssl rand -hex 32`, store in a secrets vault, and never change it on a running instance.

---

## Local Development

### Pre-commit hook

```bash
make install-hooks
```

Every `git commit` then runs automatically:

1. **PHPStan** — static analysis of `src/` at level 6 (with Symfony + Doctrine extensions)
2. **PHP syntax check** — `php -l` on every staged `.php` file

Fix failures, re-stage, commit again. Skip with `git commit --no-verify` only in exceptional cases.

### Running tests

```bash
make test
# or: docker compose exec app php bin/phpunit
```

---

## Production Deployment

### Server requirements

| Resource | Minimum | Recommended |
|---|---|---|
| CPU | 1 vCPU | 2 vCPU |
| RAM | 1 GB | 2 GB |
| Disk | 20 GB SSD | 40 GB SSD |
| OS | Ubuntu 22.04 LTS | Ubuntu 22.04 LTS |
| Ports | 22, 80, 443 | 22, 80, 443 |

### Step 1 — Prepare the server

```bash
adduser deploy
usermod -aG sudo,docker deploy
curl -fsSL https://get.docker.com | sh

ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
```

### Step 2 — SSH access for deployment

```bash
# Local machine
ssh-keygen -t ed25519 -C "deploy@family-finance"
ssh-copy-id deploy@your-server-ip
```

### Step 3 — Clone and configure

```bash
ssh deploy@your-server-ip
sudo mkdir -p /opt/family-finance && sudo chown deploy:deploy /opt/family-finance
git clone https://github.com/okarabudka/family-finance-manager.git /opt/family-finance
cd /opt/family-finance
cp .env .env.local
```

Edit `.env.local`:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<openssl rand -hex 32>

DATABASE_URL=postgresql://app:<db_password>@db:5432/family_finance?serverVersion=16&charset=utf8
POSTGRES_PASSWORD=<db_password>

REDIS_URL=redis://redis:6379
MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages

TELEGRAM_BOT_TOKEN=<token from @BotFather>
TELEGRAM_WEBHOOK_URL=https://yourdomain.com/telegram/webhook
TELEGRAM_WEBHOOK_SECRET=<openssl rand -hex 32>

STORAGE_LOCAL_PATH=/var/www/html/var/uploads
DEFAULT_URI=https://yourdomain.com

MAILER_DSN=smtp://user:pass@smtp.example.com:587
MAILER_FROM=noreply@yourdomain.com
```

### Step 4 — SSL with Nginx + Certbot

```bash
sudo apt install -y nginx certbot python3-certbot-nginx
sudo certbot --nginx -d yourdomain.com
```

`/etc/nginx/sites-available/family-finance`:

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    client_max_body_size 20M;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/family-finance /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

In `docker-compose.prod.yml`, bind nginx to localhost only:

```yaml
  nginx:
    ports:
      - "127.0.0.1:8080:80"
```

### Step 5 — First launch

```bash
cd /opt/family-finance
docker build -f docker/php/Dockerfile.prod -t family-finance:latest .
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f docker-compose.prod.yml exec app php bin/console cache:warmup
docker compose -f docker-compose.prod.yml exec app php bin/console app:user:create admin@example.com "Admin" yourpassword --admin
docker compose -f docker-compose.prod.yml exec app php bin/console app:telegram:set-webhook
```

Verify: `docker compose -f docker-compose.prod.yml ps` — all five services (`app`, `nginx`, `db`, `redis`, `worker`) should be `running`.

### Step 6 — Cron jobs

```bash
crontab -e
```

```cron
# Scheduled reminders — run every hour
0 * * * * docker compose -f /opt/family-finance/docker-compose.prod.yml exec -T app php bin/console app:reminders:send >> /var/log/family-finance-reminders.log 2>&1

# Weekly report — every Monday at 08:00
0 8 * * 1 docker compose -f /opt/family-finance/docker-compose.prod.yml exec -T app php bin/console app:report:weekly >> /var/log/family-finance-weekly.log 2>&1

# Database backup — daily at 03:00
0 3 * * * cd /opt/family-finance && bash scripts/backup.sh >> /var/log/family-finance-backup.log 2>&1
```

### Deploying updates

```bash
ssh deploy@your-server-ip
cd /opt/family-finance

git pull origin main
docker build -f docker/php/Dockerfile.prod -t family-finance:latest .
docker compose -f docker-compose.prod.yml up -d --no-deps app worker nginx
docker compose -f docker-compose.prod.yml exec -T app php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f docker-compose.prod.yml exec -T app php bin/console cache:warmup
```

After pulling a new version, check for new env vars:

```bash
diff .env .env.local
```

### Database backups

Backups go to `/var/backups/family-finance/` as gzipped SQL, retained for 30 days.

Restore:

```bash
gunzip -c /var/backups/family-finance/family_finance_20250301_030000.sql.gz \
  | docker compose -f docker-compose.prod.yml exec -T db psql -U app family_finance
```

### Monitoring

```bash
docker compose -f docker-compose.prod.yml logs -f worker
docker compose -f docker-compose.prod.yml exec app php bin/console messenger:stats
docker compose -f docker-compose.prod.yml restart worker
```

---

## Troubleshooting

### Bot does not respond

1. Check webhook is registered:
   ```bash
   curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
   ```
   `"url"` must match `TELEGRAM_WEBHOOK_URL`. If empty, run `make set-webhook`.

2. Check endpoint is reachable:
   ```bash
   curl -I https://yourdomain.com/telegram/webhook
   # Expected: HTTP 405 (GET not allowed — correct)
   ```

3. Check logs:
   ```bash
   docker compose logs -f nginx app
   ```

### Webhook returns HTTP 403

`TELEGRAM_WEBHOOK_SECRET` mismatch. Verify `.env.local` on the server matches the value used at registration, then re-register:

```bash
docker compose exec app php bin/console app:telegram:set-webhook
```

### Imports not processing

```bash
docker compose ps worker
docker compose logs worker
docker compose restart worker
docker compose exec app php bin/console messenger:failed:show
docker compose exec app php bin/console messenger:failed:retry
```

### Reminders not sending

1. Check cron is installed: `crontab -l | grep reminders`
2. Test manually:
   ```bash
   docker compose exec app php bin/console app:reminders:send
   # Expected: "Done. Dispatched N reminder(s)."
   ```
3. Check `last_sent_at` — if today, it already fired and won't repeat until next period:
   ```sql
   SELECT name, frequency, last_sent_at FROM reminder_templates;
   ```
4. For email: verify `MAILER_DSN` is not `null://null`

### Redis connection refused

```bash
docker compose ps redis
docker compose restart redis
```

### Blank page / class not found after deploy

```bash
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console cache:warmup
```

### Migration error

```bash
docker compose exec app php bin/console doctrine:migrations:status
# Fix the cause, then:
docker compose exec app php bin/console doctrine:migrations:migrate
```

### Charts missing in Telegram

Charts use [Quickchart.io](https://quickchart.io/). To self-host:

```bash
docker run -p 3400:3400 ianw/quickchart
```

Then in `config/services.yaml`:

```yaml
App\Domain\Analytics\ChartGenerator:
    arguments:
        $quickchartBaseUrl: 'http://your-server:3400'
```

---

## Architecture

See [ARCHITECTURE.md](ARCHITECTURE.md) for a detailed breakdown of the system design, request flows, entity relationships, and service layer.

---

## License

MIT License. See [LICENSE](LICENSE) for details.
