# Architecture — Family Finance Manager

## Overview

Family Finance Manager is a self-hosted Symfony 7.4 application with two primary interfaces:

- **Telegram bot** — the main daily-use interface (add transactions, check balance, submit meter readings, receive reminders)
- **Web dashboard** — full management interface (analytics, imports, admin panel)

Both interfaces share the same domain services, Doctrine ORM entities, and PostgreSQL database. Long-running work (bank imports, reminder delivery, report generation) is handled asynchronously by a Symfony Messenger worker.

---

## Technology Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.3 |
| Framework | Symfony 7.4 |
| Database | PostgreSQL 16 (Doctrine ORM 3.6) |
| Cache / Sessions / Queue | Redis 7 |
| Message queue transport | Symfony Messenger via Redis |
| Admin panel | EasyAdmin 5.0 |
| Frontend | Tailwind CSS (CDN), Chart.js 4 (CDN), Webpack Encore (custom JS only) |
| Telegram charts | Quickchart.io (PNG generation) |
| CSV parsing | League/CSV 9 |
| Containerization | Docker + Docker Compose |
| Web server | Nginx 1.25 → PHP-FPM |

---

## Repository Layout

```
src/
├── Command/            # CLI commands (4)
├── Controller/
│   ├── Admin/          # EasyAdmin CRUD controllers (9) + dashboard
│   ├── Telegram/       # Webhook entry point (1)
│   └── Web/            # Web dashboard controllers (11)
├── DataFixtures/       # Dev seed data
├── Domain/             # Business logic, no HTTP or Telegram concerns
│   ├── Analytics/      # Charts, monthly reports, spending breakdown
│   ├── Category/       # Auto-categorization rule engine
│   ├── Import/         # Bank statement parsing (MT940, Sparkasse CSV)
│   ├── Meter/          # Meter reading service, consumption calculation
│   ├── Suggestion/     # Financial recommendations engine
│   └── Transaction/    # Quick input parser, CRUD, balance updates
├── Entity/             # Doctrine ORM entities + enums
├── Form/               # Symfony form types (web only)
├── Message/            # Async message DTOs (4)
├── MessageHandler/     # Async message handlers (4)
├── Repository/         # Doctrine query repositories (12)
├── Security/           # Auth providers
└── Telegram/           # Everything bot-specific
    ├── Conversation/   # Multi-step dialog implementations (6)
    ├── Handler/        # Command handlers (12) + callback handlers (2)
    ├── CommandHandlerInterface.php
    ├── CommandRegistry.php
    ├── ConversationInterface.php
    ├── ConversationManager.php
    ├── ConversationRegistry.php
    ├── TelegramFileDownloader.php
    ├── TelegramNotifier.php
    ├── TelegramSender.php
    └── UpdateDispatcher.php

templates/              # Twig templates (Tailwind CSS)
migrations/             # Doctrine migrations (3)
config/
├── packages/           # Symfony bundle configs (16 yaml files)
└── services.yaml       # DI wiring, CommandRegistry, ConversationRegistry
```

---

## Entities and Relationships

```
Family
 ├── members        → User[]          (users.family_id → families.id)
 ├── accounts       → Account[]       (accounts.family_id → families.id)
 ├── categories     → Category[]      (categories.family_id → families.id)
 ├── budgets        → Budget[]        (budgets.family_id → families.id)
 ├── savingsGoals   → SavingsGoal[]   (savings_goals.family_id → families.id)
 ├── transactions   → Transaction[]   (transactions.family_id → families.id)
 ├── meterReadings  → MeterReading[]  (meter_readings.family_id → families.id)
 ├── reminders      → ReminderTemplate[] (reminder_templates.family_id → families.id, nullable)
 └── recommendations→ Recommendation[]

User
 ├── family         → Family          (nullable, SET NULL on delete)
 └── telegramChatId bigint (nullable) — links bot user to web user

Account
 ├── family         → Family
 └── type           → AccountType enum (checking, savings, cash, credit_card)

Transaction
 ├── family         → Family
 ├── sourceAccount  → Account (nullable)
 ├── targetAccount  → Account (nullable)
 ├── category       → Category (nullable)
 ├── createdBy      → User
 ├── importOrigin   → ImportTransaction (nullable, unique)
 └── type           → TransactionType enum

Category
 ├── family         → Family
 ├── parent         → Category (nullable, self-referencing)
 ├── children       → Category[]
 └── type           → CategoryType enum (expense, income)

CategoryRule
 ├── family         → Family
 ├── category       → Category
 ├── keywords       → JSON array
 └── counterpartyIban string (nullable)

ImportFile
 ├── account        → Account
 ├── uploadedBy     → User
 └── status         → ImportStatus enum (pending, processing, done, failed)

ImportTransaction
 ├── importFile     → ImportFile
 └── status         → ImportTransactionStatus enum (pending, categorized, skipped)

Budget
 ├── family         → Family
 ├── category       → Category
 └── UNIQUE(family_id, category_id, year, month)

SavingsGoal
 ├── family         → Family
 ├── account        → Account
 └── status         → SavingsGoalStatus enum (active, completed, archived)

MeterReading
 ├── family         → Family
 ├── createdBy      → User
 └── type           → MeterType enum (electricity, water)

ReminderTemplate
 ├── family         → Family (nullable — null means all families)
 ├── type           → ReminderType enum
 └── frequency      → ReminderFrequency enum (daily, weekly, monthly)
```

### Enums

| Enum | Values |
|---|---|
| `AccountType` | `checking`, `savings`, `cash`, `credit_card` |
| `CategoryType` | `expense`, `income` |
| `TransactionType` | `expense`, `income`, `transfer`, `refund`, `adjustment` |
| `ImportStatus` | `pending`, `processing`, `done`, `failed` |
| `ImportTransactionStatus` | `pending`, `categorized`, `skipped` |
| `SavingsGoalStatus` | `active`, `completed`, `archived` |
| `MeterType` | `electricity`, `water` |
| `ReminderType` | `meter_electricity`, `meter_water`, `budget_check`, `savings_check`, `custom` |
| `ReminderFrequency` | `daily`, `weekly`, `monthly` |

---

## Telegram Bot Architecture

### Incoming request flow

```
POST /telegram/webhook
        │
        ▼
WebhookController
  • Validates X-Telegram-Bot-Api-Secret-Token header → 403 if wrong
  • Deserializes JSON body
        │
        ▼
UpdateDispatcher
  • Checks ConversationManager → active dialog for this chatId?
  │
  ├─ YES → ConversationRegistry.get(conversationClass).handle(chatId, update, state)
  │
  └─ NO  → Detect update type
              ├─ /command text  → CommandRegistry.execute(command, update)
              ├─ callback_query → TransactionCallbackHandler or PendingImportHandler
              └─ plain text     → MessageHandler (quick input parser)
```

### Conversation state machine

Multi-step dialogs (add expense, import, meter reading, etc.) are implemented as `ConversationInterface` classes. State is stored in Redis with a 30-minute TTL.

```
ConversationManager (Redis-backed)
  • start(chatId, ConversationClass)     → writes {class, step: 0, data: {}} to Redis
  • getState(chatId)                     → reads current state
  • updateState(chatId, state)           → writes updated step/data
  • end(chatId)                          → deletes key from Redis
```

Each `ConversationInterface::handle(chatId, update, state)` reads `state['step']` and routes to the appropriate step method. Steps advance by calling `updateState` with `step + 1`.

**Registered conversations:**

| Class | Trigger | Steps |
|---|---|---|
| `RegisterConversation` | `/start` | name → family setup → default accounts |
| `AddExpenseConversation` | `/add` | amount → category (inline) → account → confirm |
| `AddIncomeConversation` | `/income` | amount → category → account → confirm |
| `AddTransferConversation` | `/transfer` | source account → target account → amount → confirm |
| `ImportConversation` | `/import` | account selection → file upload |
| `AddMeterConversation` | `/meter` | type selection → value → optional photo |

### Command handlers

Each `CommandHandlerInterface` handles one `/command`. Registered in `config/services.yaml` under `CommandRegistry`:

`start`, `balance`, `report`, `add`, `import`, `stats`, `income`, `transfer`, `budget`, `pending`, `meter`, `help`

### Non-command handlers

- **`MessageHandler`** — catches plain text messages outside an active conversation. Delegates to `QuickInputParser` (parses `"50 coffee"` → `{amount: 50, description: "coffee"}`), creates the transaction, and replies with inline confirmation buttons.
- **`TransactionCallbackHandler`** — handles `tx:*` callback queries (edit/delete transaction buttons).
- **`PendingImportHandler`** — handles `pending:*` callback queries (categorize import transactions).

---

## Asynchronous Processing

All async work goes through **Symfony Messenger** with a Redis transport. A dedicated `worker` container consumes the `async` queue.

```
App container                         Worker container
     │                                      │
     │  dispatch(Message)                   │
     │ ─────────────────────────► Redis ───►│
     │                            queue     │  handler.invoke(message)
     │                                      │  → DB write, Telegram API, email
```

### Messages and handlers

| Message | Handler | Triggered by |
|---|---|---|
| `ProcessImportMessage` | `ProcessImportMessageHandler` | File uploaded via `/import` |
| `SendNotificationMessage` | `SendNotificationMessageHandler` | Import complete, budget alerts |
| `SendReminderMessage` | `SendReminderMessageHandler` | `app:reminders:send` cron command |
| `GenerateReportMessage` | `GenerateReportMessageHandler` | `app:report:weekly` command |

The worker restarts every 3600 seconds (`--time-limit=3600`) to prevent memory leaks.

Failed messages land in a `failed` transport and can be inspected and retried:

```bash
php bin/console messenger:failed:show
php bin/console messenger:failed:retry
```

---

## Reminder System

```
cron (every hour)
     │
     ▼
app:reminders:send command
     │  loads all active ReminderTemplate records
     │  calls template.isDue(now) on each
     │    isDue() checks:
     │      • current hour == sendHour
     │      • not already sent in current period (lastSentAt guard)
     │      • correct dayOfWeek / dayOfMonth for weekly/monthly
     │
     ▼  for each due template:
dispatch(SendReminderMessage)
     │
     ▼ (async, in worker)
SendReminderMessageHandler
     │  resolves recipients (family members, or all users if family=null)
     │  for each user:
     │    • sendViaTelegram → TelegramSender.sendMessage(chatId, text)
     │    • sendViaEmail    → Mailer.send(Email to user.email)
     │  updates template.lastSentAt = now
     ▼
```

---

## Web Request Flow

```
HTTP request
     │
     ▼
Nginx (port 8080 → 80)
     │
     ▼
PHP-FPM (Symfony kernel)
     │
     ├─ /admin/*   → EasyAdmin 5 (AbstractCrudController subclasses)
     ├─ /telegram/webhook → WebhookController → UpdateDispatcher
     └─ /*         → Web controllers (AbstractController subclasses)
                         │
                         ├─ Doctrine ORM (PostgreSQL)
                         ├─ Domain services (Analytics, Meter, Transaction, …)
                         └─ Twig templates (Tailwind CSS, Chart.js)
```

### Authentication

Web authentication uses Symfony Security with a form login (`/login`) and a `UserRepository`-backed user provider. Telegram users are identified by `telegramChatId` stored on the `User` entity — no separate auth required for the bot.

All web dashboard routes are protected with `#[IsGranted('ROLE_USER')]`. The admin panel at `/admin` uses a separate `ROLE_ADMIN` check configured in `security.yaml`.

---

## File Storage

Uploaded files (bank statements, meter photos) are stored in `STORAGE_LOCAL_PATH` (default: `var/uploads/`) in subdirectories:

```
var/uploads/
├── imports/        # Bank statement files (MT940, CSV)
└── meters/         # Meter photos downloaded from Telegram
```

Files are saved by `TelegramFileDownloader` with randomized hex filenames. Meter photos are served through `MeterController::photo()` which enforces family-scoped access control before streaming the file — they are never directly exposed by Nginx.

---

## Domain Services

| Service | Responsibilities |
|---|---|
| `TransactionService` | CRUD, balance updates, quick input |
| `AccountService` | Balance calculation, default account lookup |
| `CategoryRuleService` | Auto-categorize by IBAN or keyword |
| `ImportProcessor` | Parse file, deduplicate, apply rules, notify |
| `ImportParserFactory` | Detect format, delegate to MT940 or CSV parser |
| `QuickInputParser` | Parse `"50 coffee"` → amount + description |
| `AnalyticsService` | Monthly reports, trends, category breakdown |
| `ChartGenerator` | Generate PNG charts via Quickchart.io |
| `SuggestionService` | Generate financial recommendations |
| `MeterService` | Save readings, calculate consumption deltas, chart data |

---

## Database Migrations

| File | Description |
|---|---|
| `Version20260101000000` | Initial schema — all base tables (families, users, accounts, transactions, categories, budgets, savings_goals, imports, recommendations) |
| `Version20260426000001` | Add `meter_readings` table |
| `Version20260426000002` | Add `reminder_templates` table |

---

## Configuration Wiring (services.yaml)

Services requiring explicit constructor arguments beyond autowiring:

| Service | Explicit args |
|---|---|
| `TelegramSender` | `$botToken` |
| `TelegramFileDownloader` | `$botToken`, `$uploadDir` |
| `ChartGenerator` | `$storagePath` |
| `TelegramSetWebhookCommand` | `$botToken`, `$webhookUrl`, `$webhookSecret` |
| `WebhookController` | `$webhookSecret` |
| `ConversationManager` | `$cache`, `$cachePool` (Redis pool, lazy-loaded) |
| `ImportParserFactory` | `$parsers` (tagged iterator: `app.import_parser`) |
| `SendReminderMessageHandler` | `$mailerFrom` |
| `CommandRegistry` | `$handlers` (keyed array: command → handler service) |
| `ConversationRegistry` | `$conversations` (keyed by FQCN) |

---

## Key Design Decisions

**Conversation state in Redis (not DB)**
Dialog steps are ephemeral by design. A 30-minute TTL means abandoned dialogs self-clean. The tradeoff — state lost on Redis restart — is acceptable for a household tool.

**Family isolation**
Every query involving user data is scoped by `family_id`. There is no global data model except `ReminderTemplate` (where `family = null` means "all families"). This prevents cross-family data leakage without a dedicated multi-tenancy layer.

**Poll-based reminders (cron) vs. Symfony Scheduler**
The project deliberately avoids adding a scheduler process. A single hourly cron job running `app:reminders:send` is sufficient and easier to operate. The `isDue()` logic on `ReminderTemplate` is self-contained and testable without a scheduler framework.

**SHA-256 deduplication for imports**
Bank statement re-uploads are common. Each `ImportTransaction` gets a hash of its key fields on first import. Subsequent imports of the same transaction silently skip it rather than erroring or creating a duplicate.

**Quickchart.io for Telegram charts**
Telegram bots can send images but not run JavaScript. Chart.js requires a browser. Quickchart.io renders Chart.js server-side and returns a PNG. The web dashboard uses Chart.js directly (it already runs in a browser).

**EasyAdmin for the admin panel**
All entity management (including `ReminderTemplate` configuration) is handled by EasyAdmin 5 CRUD controllers. This avoids building a custom admin UI for low-frequency operations.
