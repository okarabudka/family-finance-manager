# Family Finance Manager

A self-hosted family finance tracker with a Telegram bot as the primary interface and a web dashboard for detailed management. Built with PHP 8.3 / Symfony 7, PostgreSQL 16, and Redis 7. Background processing (bank statement imports, async notifications) runs via Symfony Messenger with a dedicated worker container.

---

## Features

- **Telegram bot** — add expenses and income on the go, check balances, and view monthly reports without opening a browser
- **Quick text input** — type `50 coffee` in the chat to record an expense in seconds
- **Multi-step interactive dialogs** — guided flows for adding transactions, transfers, and imports via inline keyboard buttons
- **Web dashboard** — full management of transactions, accounts, categories, budgets, and savings goals
- **Bank statement import** — parse MT940 (`.sta`) and Sparkasse CSV (`.csv`) files; processed in the background with SHA-256 deduplication to prevent double-imports
- **Pending categorization** — unrecognized imported transactions are queued for manual review via `/pending`
- **Budgets and savings goals** — track spending limits per category and progress toward savings targets
- **Analytics and charts** — monthly reports with category breakdown; charts rendered via Quickchart.io for inline Telegram delivery
- **Family accounts** — multiple family members share one space with separate personal accounts (checking, savings, cash, credit card)

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
git clone https://github.com/your-org/family-finance-manager.git
cd family-finance-manager

# Copy and edit environment variables
cp .env .env.local
# Open .env.local and set at minimum:
#   TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_URL, TELEGRAM_WEBHOOK_SECRET

# Start all services: app (PHP-FPM), nginx, db (PostgreSQL), redis, worker
docker compose up -d

# Install PHP dependencies
docker compose exec app composer install

# Run database migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# (Optional) Load sample/test data
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction
```

The web interface is now available at **http://localhost:8080**.

---

## Telegram Bot Setup

The Telegram bot is the primary user interface of the application. Follow these steps carefully.

### Step 1 — Create a bot via BotFather

1. Open Telegram and start a chat with **@BotFather**
2. Send `/newbot` and follow the prompts to choose a name and username
3. Copy the **bot token** BotFather provides (format: `123456789:AAF...`)

### Step 2 — Configure environment variables

Edit your `.env.local` file:

```dotenv
TELEGRAM_BOT_TOKEN=123456789:AAFxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# The public HTTPS URL Telegram will POST updates to
TELEGRAM_WEBHOOK_URL=https://yourdomain.com/telegram/webhook

# A random secret used to verify that requests genuinely come from Telegram
TELEGRAM_WEBHOOK_SECRET=your_random_secret_here
```

Generate a secure secret with:

```bash
openssl rand -hex 32
```

### Step 3 — Expose your server to Telegram (HTTPS required)

Telegram only delivers webhook updates to publicly accessible HTTPS endpoints.

**Production:** set `TELEGRAM_WEBHOOK_URL` to your real domain, e.g. `https://finance.example.com/telegram/webhook`.

**Local development with ngrok:**

```bash
# Install ngrok from https://ngrok.com, then:
ngrok http 8080
```

Copy the `https://` URL that ngrok prints (e.g. `https://abc123.ngrok.io`), append `/telegram/webhook`, and set the full URL as `TELEGRAM_WEBHOOK_URL` in `.env.local`. Then continue to Step 4 — you will need to re-run `set-webhook` every time ngrok gives you a new URL.

### Step 4 — Register the webhook with Telegram

```bash
docker compose exec app php bin/console app:telegram:set-webhook
# OR using Make:
make set-webhook
```

Expected output:

```
Webhook registered: https://yourdomain.com/telegram/webhook
```

### Step 5 — Test the bot

Send `/start` to your bot in Telegram. It will ask for your name and automatically create a family account with two default accounts (main checking and cash).

### Webhook security

Every incoming webhook request is validated against `TELEGRAM_WEBHOOK_SECRET` via the `X-Telegram-Bot-Api-Secret-Token` header. Requests with a missing or incorrect secret are rejected with HTTP 403 before any business logic runs.

---

## Available Bot Commands

| Command | Description |
|---|---|
| `/start` | Register a new account or return to the main menu |
| `/add` | Add an expense (guided multi-step dialog) |
| `/income` | Add income (guided multi-step dialog) |
| `/transfer` | Transfer funds between accounts (guided dialog) |
| `/balance` | Show current balance for all accounts |
| `/report` | Monthly income/expense report with per-category breakdown |
| `/stats` | Statistics and charts |
| `/import` | Upload a bank statement file (MT940 or Sparkasse CSV) |
| `/pending` | Review and categorize unclassified transactions from imports |
| `/budget` | View budget limits per category |
| `/settings` | User and family settings |
| `/help` | Show all available commands |
| `/cancel` | Cancel the current in-progress dialog |

### Quick text input

Instead of using `/add`, type the amount and a description directly:

```
50 coffee
150.50 restaurant
```

The bot auto-detects the category using keyword rules and posts the expense to the default account. Inline buttons in the confirmation message let you reassign the category or delete the transaction.

---

## Web Interface

Open **http://localhost:8080** in your browser.

The web dashboard provides:

- Transaction list with filtering, search, and pagination
- Account management (create, edit, set default)
- Category tree management with budget limits
- Savings goals tracking with progress indicators
- Monthly and custom-range analytics with Chart.js charts

### Create the first web user

```bash
docker compose exec app php bin/console app:user:create admin@example.com "Admin" yourpassword --admin
```

---

## Adding a Second Family Member

There is currently no self-service invite flow. A second member can be added in two ways:

### Via Telegram (recommended)

1. The second person sends `/start` to the bot — this creates their own separate family
2. Find their new user record in the database:
   ```sql
   SELECT id, name, email, family_id FROM users ORDER BY created_at DESC LIMIT 5;
   ```
3. Update their `family_id` to match the first person's family:
   ```sql
   UPDATE users SET family_id = '<first-person-family-uuid>' WHERE id = '<second-person-uuid>';
   ```
4. Delete the now-orphaned family that was auto-created for them:
   ```sql
   DELETE FROM families WHERE id = '<orphaned-family-uuid>';
   ```

### Via console command (web-only users)

```bash
docker compose exec app php bin/console app:user:create member@example.com "Anna" password
```

This creates a new user with their own family. Then follow the SQL steps above to move them into the shared family.

> **Note:** A proper invite-link flow is on the roadmap. For now, the SQL approach takes about 30 seconds and is safe to do on a live database.

---

## Categorization Rules

The bot uses rules to automatically assign a category to imported transactions. Rules are matched in two ways:

- **By counterparty IBAN** — exact match, highest priority. Useful for recurring payments (rent, insurance, salary).
- **By keyword** — substring match (case-insensitive) in the transaction description. Multiple keywords per rule are supported.

Rules are managed directly in the database. There is no web UI for rules yet.

### Add a rule via SQL

```sql
-- First, find the family UUID and category UUID you want to use
SELECT id, name FROM families;
SELECT id, name FROM categories WHERE family_id = '<your-family-uuid>';

-- Rule: all transactions from IBAN DE89370400440532013000 → "Rent" category
INSERT INTO category_rules (id, family_id, category_id, keywords, counterparty_iban, priority)
VALUES (
    gen_random_uuid(),
    '<family-uuid>',
    '<rent-category-uuid>',
    '[]',                          -- no keywords needed for IBAN rule
    'DE89370400440532013000',
    10                             -- higher number = checked first
);

-- Rule: description contains "rewe" or "lidl" → "Groceries" category
INSERT INTO category_rules (id, family_id, category_id, keywords, counterparty_iban, priority)
VALUES (
    gen_random_uuid(),
    '<family-uuid>',
    '<groceries-category-uuid>',
    '["rewe", "lidl", "aldi", "penny"]',
    NULL,
    5
);
```

### Connect to the database

```bash
# Development
docker compose exec db psql -U app family_finance

# Production
docker compose -f docker-compose.prod.yml exec db psql -U app family_finance
```

### How matching works

When a transaction is imported, rules are evaluated in descending `priority` order:

1. All IBAN rules are checked first across all priorities
2. Then keyword rules in priority order

The first match wins. Transactions with no match get status `pending` and appear in `/pending` for manual review. After you manually assign a category via `/pending`, consider adding a rule so the next import auto-classifies the same counterparty.

---

## Bank Statement Import

Supported formats:

- **MT940** (`.sta`) — standard German bank export format
- **Sparkasse CSV** (`.csv`) — Sparkasse-specific CSV export

Import workflow:

1. Send `/import` to the bot
2. Select the target account from the inline keyboard
3. Upload the statement file as a Telegram document attachment
4. The file is dispatched to the background worker queue and processed asynchronously — you do not wait for it
5. You receive a Telegram notification when processing completes, including a count of imported and skipped (duplicate) transactions
6. Send `/pending` to review and manually categorize any transactions the bot could not auto-classify

Duplicate prevention: each imported transaction is identified by a SHA-256 hash of its key fields. Re-uploading the same file or overlapping date ranges will not create duplicates.

---

## Available Make Commands

| Command | Description |
|---|---|
| `make up` | Start all Docker containers in the background |
| `make down` | Stop and remove all containers |
| `make shell` | Open a bash shell inside the app container |
| `make migrate` | Run pending database migrations |
| `make fixtures` | Load fixture/seed data (development only) |
| `make test` | Run the PHPUnit test suite |
| `make cache-clear` | Clear the Symfony application cache |
| `make set-webhook` | Register the Telegram webhook URL with Telegram's API |
| `make assets` | Build frontend assets (one-time, development build) |
| `make assets-watch` | Build frontend assets and watch for changes |
| `make analyse` | Run PHPStan static analysis |
| `make install-hooks` | Install the git pre-commit hook (run once after cloning) |

---

## Configuration Reference

| Variable | Description | Example value |
|---|---|---|
| `APP_ENV` | Application environment | `dev` / `prod` |
| `APP_SECRET` | Symfony secret key — change in production | `openssl rand -hex 32` output |
| `DATABASE_URL` | PostgreSQL connection DSN | `postgresql://app:secret@db:5432/family_finance?serverVersion=16&charset=utf8` |
| `REDIS_URL` | Redis connection URL (cache and sessions) | `redis://redis:6379` |
| `MESSENGER_TRANSPORT_DSN` | Symfony Messenger queue transport | `redis://redis:6379/messages` |
| `TELEGRAM_BOT_TOKEN` | Bot token from @BotFather | `123456789:AAFxxxxxx` |
| `TELEGRAM_WEBHOOK_URL` | Public HTTPS URL for incoming Telegram updates | `https://finance.example.com/telegram/webhook` |
| `TELEGRAM_WEBHOOK_SECRET` | Secret token to authenticate Telegram requests | 32+ random hex characters |
| `STORAGE_LOCAL_PATH` | Directory path for uploaded files (bank statements) | `%kernel.project_dir%/var/uploads` |
| `DEFAULT_URI` | Base URL used when generating URLs in CLI context | `https://finance.example.com` |

> **Warning:** `APP_SECRET` is used by Symfony to sign sessions and security tokens. **Never change it after the first deployment** — doing so will invalidate all existing sessions and any signed URLs. Generate it once with `openssl rand -hex 32` and keep it in a secrets vault.

---

## Local Development Setup

### Install the pre-commit hook

After cloning the repository, run the following **once** to activate the pre-commit hook:

```bash
make install-hooks
```

From that point on, every `git commit` automatically runs:

1. **PHPStan** — full static analysis of `src/` (level 6, with Symfony and Doctrine extensions)
2. **PHP syntax check** — `php -l` on every staged `.php` file

If either check fails the commit is aborted and the error is printed. Fix the issue, re-stage, and commit again. The hook can be bypassed in exceptional cases with `git commit --no-verify` (use sparingly).

---

## Running Tests

```bash
make test
# OR directly:
docker compose exec app php bin/phpunit
```

---

## Upgrading

### Minor updates (patch / minor versions)

```bash
ssh deploy@your-server-ip
cd /opt/family-finance

git pull origin main
docker build -f docker/php/Dockerfile.prod -t family-finance:latest .
docker compose -f docker-compose.prod.yml up -d --no-deps app worker
docker compose -f docker-compose.prod.yml exec -T app php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f docker-compose.prod.yml exec -T app php bin/console cache:warmup
```

### Checking for new environment variables

After pulling a new version, compare `.env` (committed defaults) against your `.env.local`:

```bash
diff .env .env.local
```

New variables added to `.env` since your last deploy may need to be set in `.env.local` before restarting.

### Keeping `APP_SECRET` stable

`APP_SECRET` is used to sign Symfony sessions and tokens. **Never rotate it on a running instance** — all active sessions will be invalidated immediately. If you must rotate it (e.g. after a secret leak), schedule a maintenance window and warn users they will be logged out.

### Database schema changes

Migrations run automatically in the deploy steps above. To preview what will run without applying:

```bash
docker compose -f docker-compose.prod.yml exec app php bin/console doctrine:migrations:status
docker compose -f docker-compose.prod.yml exec app php bin/console doctrine:migrations:migrate --dry-run
```

Always take a database backup before applying migrations to a production database.

---

## Troubleshooting

### Bot does not respond to messages

1. **Check the webhook is registered:**
   ```bash
   curl "https://api.telegram.org/bot<YOUR_TOKEN>/getWebhookInfo"
   ```
   Look for `"url"` — it should match your `TELEGRAM_WEBHOOK_URL`. If it is empty, run `make set-webhook`.

2. **Check the webhook URL is reachable from the internet:**
   ```bash
   curl -I https://yourdomain.com/telegram/webhook
   ```
   Expected: `HTTP/2 405` (Method Not Allowed for GET is correct — the endpoint only accepts POST).

3. **Check the nginx and app container logs:**
   ```bash
   docker compose logs -f nginx app
   ```

---

### Webhook returns HTTP 403

Your server is rejecting Telegram requests because the secret header does not match. Verify that `TELEGRAM_WEBHOOK_SECRET` in `.env.local` on the server is exactly the same value that was used when you registered the webhook. Re-register after any change:

```bash
docker compose exec app php bin/console app:telegram:set-webhook
```

---

### Imports are not being processed

The import processor runs inside the `worker` container. Check if it is alive:

```bash
docker compose ps worker
docker compose logs worker
```

If the worker exited, restart it:

```bash
docker compose restart worker
```

Check if there are messages stuck in the queue:

```bash
docker compose exec app php bin/console messenger:stats
```

If messages are stuck in the `failed` transport, inspect and retry them:

```bash
docker compose exec app php bin/console messenger:failed:show
docker compose exec app php bin/console messenger:failed:retry
```

---

### Redis connection refused

```
redis: Connection refused [tcp://redis:6379]
```

The Redis container is not running or not reachable. Check:

```bash
docker compose ps redis
docker compose restart redis
```

---

### "Class not found" or blank pages after deploy

The Symfony cache is stale. Rebuild it:

```bash
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console cache:warmup
```

---

### Database migration error

If a migration fails mid-run, check the current state:

```bash
docker compose exec app php bin/console doctrine:migrations:status
```

Migrations that failed are marked as not executed. Fix the underlying issue (e.g. out-of-disk space, wrong DB user permissions) and re-run `doctrine:migrations:migrate`.

---

### Telegram charts are not appearing

Charts use the external [Quickchart.io](https://quickchart.io/) service. If it is blocked by your server's outbound firewall, or if you need full data privacy, you can self-host Quickchart:

```bash
docker run -p 3400:3400 ianw/quickchart
```

Then point the app at your local instance by setting the Quickchart base URL in `config/services.yaml`:

```yaml
App\Domain\Analytics\ChartGenerator:
    arguments:
        $quickchartBaseUrl: 'http://your-server:3400'
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
| Open ports | 22, 80, 443 | 22, 80, 443 |

---

### Step 1 — Prepare the server

Connect as root, then create a dedicated deploy user and install Docker:

```bash
# Create a non-root deploy user
adduser deploy
usermod -aG sudo,docker deploy

# Install Docker Engine (official script, Ubuntu 22.04)
curl -fsSL https://get.docker.com | sh

# Verify
docker --version       # Docker version 26+
docker compose version # Docker Compose version v2+
```

Configure the firewall:

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
```

---

### Step 2 — Set up SSH access for deployment

On your **local machine**, generate a key pair if you don't have one:

```bash
ssh-keygen -t ed25519 -C "deploy@family-finance"
```

Copy the public key to the server:

```bash
ssh-copy-id deploy@your-server-ip
```

Log in as the deploy user and confirm passwordless access works:

```bash
ssh deploy@your-server-ip
```

---

### Step 3 — Clone and configure the application

```bash
# Log in as deploy
ssh deploy@your-server-ip

# Create the application directory
sudo mkdir -p /opt/family-finance
sudo chown deploy:deploy /opt/family-finance

# Clone the repository
git clone https://github.com/your-org/family-finance-manager.git /opt/family-finance
cd /opt/family-finance

# Create production environment file
cp .env .env.local
```

Edit `/opt/family-finance/.env.local` and set all required values:

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
```

---

### Step 4 — Configure SSL with Nginx + Certbot (host-level)

The production `docker-compose.prod.yml` exposes only port **80** from the nginx container. SSL termination is handled by a host-level Nginx acting as a reverse proxy, with certificates issued by Let's Encrypt.

```bash
# Install Nginx and Certbot on the host
sudo apt install -y nginx certbot python3-certbot-nginx

# Obtain a certificate (stops Nginx temporarily)
sudo certbot --nginx -d yourdomain.com
```

Create `/etc/nginx/sites-available/family-finance`:

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

Enable the site and reload:

```bash
sudo ln -s /etc/nginx/sites-available/family-finance /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Update `docker-compose.prod.yml` to expose nginx on port 8080 instead of 80 (so the host Nginx can proxy to it):

```yaml
  nginx:
    ports:
      - "127.0.0.1:8080:80"   # only accessible from localhost
```

Certbot installs a systemd timer to auto-renew certificates. Verify it is active:

```bash
sudo systemctl status certbot.timer
```

---

### Step 5 — First launch

Build the Docker image on the server (or pull from your registry — see CI/CD section below):

```bash
cd /opt/family-finance

# Build the production image
docker build -f docker/php/Dockerfile.prod -t family-finance:latest .

# Start all services
docker compose -f docker-compose.prod.yml up -d

# Run database migrations
docker compose -f docker-compose.prod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction

# Warm up the Symfony cache
docker compose -f docker-compose.prod.yml exec app php bin/console cache:warmup

# Create the first admin user
docker compose -f docker-compose.prod.yml exec app php bin/console app:user:create admin@example.com "Admin" yourpassword --admin

# Register the Telegram webhook
docker compose -f docker-compose.prod.yml exec app php bin/console app:telegram:set-webhook
```

Verify all containers are running:

```bash
docker compose -f docker-compose.prod.yml ps
```

Expected output: `app`, `nginx`, `db`, `redis`, `worker` all with status `running`.

---

### Deploying updates

#### Manual update

```bash
ssh deploy@your-server-ip
cd /opt/family-finance

# Pull the latest code
git pull origin main

# Rebuild the image
docker build -f docker/php/Dockerfile.prod -t family-finance:latest .

# Apply the update with zero-downtime restart
docker compose -f docker-compose.prod.yml up -d --no-deps app worker nginx

# Run any new migrations
docker compose -f docker-compose.prod.yml exec -T app php bin/console doctrine:migrations:migrate --no-interaction

# Warm up the cache
docker compose -f docker-compose.prod.yml exec -T app php bin/console cache:warmup
```

#### Automated via GitHub Actions

The `.github/workflows/deploy.yml` pipeline runs on every push to `main`:

1. Runs tests and PHPStan
2. Builds and pushes the Docker image to your registry
3. SSH-es into the server and pulls + restarts the containers

Configure these **GitHub repository secrets** (Settings → Secrets → Actions):

| Secret | Value |
|---|---|
| `REGISTRY` | Your Docker registry URL, e.g. `registry.example.com` or `ghcr.io/your-org` |
| `HOST` | Server IP address or hostname |
| `USER` | SSH user on the server (`deploy`) |
| `SSH_KEY` | Private SSH key (`cat ~/.ssh/id_ed25519`) |

After adding the secrets, push to `main` — the pipeline will build, push, and deploy automatically.

#### Rollback

To roll back to a previous image tag:

```bash
ssh deploy@your-server-ip
cd /opt/family-finance

APP_IMAGE=registry.example.com/family-finance:<previous-sha> \
  docker compose -f docker-compose.prod.yml up -d app worker
```

Previous SHA values are visible in the GitHub Actions run history or with `docker images`.

---

### Database backups

The `scripts/backup.sh` script dumps the database and retains the last 30 days of backups:

```bash
# Run manually
cd /opt/family-finance && bash scripts/backup.sh

# Or schedule via cron (runs daily at 03:00)
crontab -e
# Add:
0 3 * * * cd /opt/family-finance && bash scripts/backup.sh >> /var/log/family-finance-backup.log 2>&1
```

Backups are stored in `/var/backups/family-finance/` as gzipped SQL files.

To restore a backup:

```bash
gunzip -c /var/backups/family-finance/family_finance_20250301_030000.sql.gz \
  | docker compose -f docker-compose.prod.yml exec -T db psql -U app family_finance
```

---

### Monitoring and logs

```bash
# Live logs from all containers
docker compose -f docker-compose.prod.yml logs -f

# Logs from a specific container
docker compose -f docker-compose.prod.yml logs -f worker

# Check worker is consuming messages
docker compose -f docker-compose.prod.yml exec app php bin/console messenger:stats

# Restart the worker if it gets stuck
docker compose -f docker-compose.prod.yml restart worker
```

---

## Architecture Notes

- **Dialog state** is stored in Redis with a 30-minute TTL. In-progress multi-step dialogs (e.g. adding an expense) are lost if Redis restarts — this is an acceptable tradeoff for simplicity.
- **Background processing** uses a dedicated `worker` container running `messenger:consume async`. Bank statement imports and other async tasks are handled here without blocking the bot response.
- **Charts in Telegram** are generated via [Quickchart.io](https://quickchart.io/) as PNG images, since Chart.js requires a browser context. You can self-host a Quickchart instance for full data privacy.
- **Conversation routing**: incoming Telegram updates flow through `UpdateDispatcher` → `CommandRegistry` or `ConversationManager`. Active dialogs take priority over command parsing, so mid-dialog messages are always routed correctly.

---

## License

MIT License. See [LICENSE](LICENSE) for details.
