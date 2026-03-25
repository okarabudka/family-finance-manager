# FamilyFinance — Семейный менеджер расходов

Телеграм-бот и веб-приложение для учёта семейных расходов, доходов и накоплений с поддержкой импорта выписок из банков (Sparkasse и другие).

---

## Возможности

- **Учёт расходов и доходов** — ручной ввод через Telegram или веб-интерфейс
- **Импорт банковских выписок** — загрузка CSV/MT940 из Sparkasse и других банков; нераспознанные транзакции предлагаются для ручного подтверждения
- **Счета и копилки** — нескоghjлько счетов (наличные, банк, копилка), общий баланс в реальном времени
- **Категоризация** — гибкое дерево категорий, автоматическое определение по ключевым словам
- **Аналитика и графики** — расходы по категориям, динамика по месяцам, сравнение периодов
- **Предложения по оптимизации** — анализ трат, выявление аномалий, сравнение с предыдущими месяцами
- **Telegram-бот** — основной интерфейс: добавление транзакций, запрос отчётов, уведомления
- **Веб-интерфейс** — дашборд с графиками, управление категориями и счетами, просмотр истории
- **Мультипользовательность** — несколько членов семьи могут иметь доступ к одному бюджету

---

## Стек технологий

| Слой | Технология |
|---|---|
| Backend | PHP 8.3, Symfony 7 |
| База данных | PostgreSQL 16 |
| Очереди / кэш | Redis 7 |
| Фронтенд | Twig + Chart.js + Tailwind CSS |
| Telegram | Bot API (webhook), biblioteka `irazasyed/telegram-bot-sdk` |
| Парсинг выписок | `codelicious/mt940` (MT940), нативный CSV-парсер |
| Очереди задач | Symfony Messenger + Redis transport |
| Авторизация | Symfony Security, JWT или сессии |
| Деплой | Docker Compose на VPS Hetzner |
| CI/CD | GitHub Actions |

---

## Архитектура

```
┌──────────────────────────────────────────────┐
│                  Клиенты                      │
│  Telegram App      Browser (Web UI)           │
└────────┬─────────────────────┬────────────────┘
         │ HTTPS webhook       │ HTTPS
         ▼                     ▼
┌──────────────────────────────────────────────┐
│              Symfony Application              │
│                                               │
│  ┌─────────────────┐  ┌────────────────────┐ │
│  │  TelegramBundle  │  │  Web Controllers   │ │
│  │  (WebhookCtrl)   │  │  (Dashboard, API)  │ │
│  └────────┬─────────┘  └─────────┬──────────┘ │
│           │                      │             │
│  ┌────────▼──────────────────────▼──────────┐ │
│  │              Domain Services              │ │
│  │  TransactionService  AnalyticsService     │ │
│  │  ImportService       BudgetService        │ │
│  │  AccountService      SuggestionService    │ │
│  └────────────────────┬──────────────────────┘ │
│                       │                         │
│  ┌────────────────────▼──────────────────────┐ │
│  │           Symfony Messenger                │ │
│  │   ImportJob  NotificationJob  ReportJob    │ │
│  └────────────────────┬──────────────────────┘ │
└───────────────────────┼──────────────────────┘
                        │
         ┌──────────────┼──────────────┐
         ▼              ▼              ▼
    PostgreSQL        Redis         S3/Local
    (данные)        (кэш/очередь)  (файлы выписок)
```

### Основные модули

```
src/
├── Controller/
│   ├── Web/          # Веб-интерфейс
│   └── Telegram/     # Webhook-контроллер бота
│
├── Domain/
│   ├── Account/      # Счета и копилки
│   ├── Transaction/  # Доходы, расходы
│   ├── Category/     # Категории
│   ├── Import/       # Импорт выписок
│   ├── Analytics/    # Отчёты, графики
│   └── Suggestion/   # Рекомендации по оптимизации
│
├── Telegram/
│   ├── Handler/      # Обработчики команд бота
│   ├── Conversation/ # Многошаговые диалоги
│   └── Keyboard/     # Inline/Reply клавиатуры
│
├── Message/          # Symfony Messenger messages
├── MessageHandler/   # Обработчики очередей
│
└── Entity/           # Doctrine ORM сущности
```

---

## Структура базы данных

```
users                   — пользователи системы
families                — семья/группа (несколько пользователей)
accounts                — счета (банк, наличные, копилка)
categories              — дерево категорий расходов/доходов
transactions            — все транзакции (расходы, доходы, переводы)
transaction_tags        — теги транзакций
import_files            — загруженные файлы выписок
import_transactions     — строки из выписки до подтверждения
category_rules          — правила автокатегоризации
budgets                 — месячные бюджеты по категориям
```

---

## Установка

### Требования

- Docker и Docker Compose
- Telegram Bot Token (получить у @BotFather)
- Домен с HTTPS (для webhook) или публичный IP

### Быстрый старт

```bash
# 1. Клонировать репозиторий
git clone https://github.com/YOUR_USERNAME/family-finance.git
cd family-finance

# 2. Скопировать и настроить конфигурацию
cp .env.example .env
# Заполнить переменные в .env (см. ниже)

# 3. Запустить контейнеры
docker compose up -d

# 4. Установить зависимости и накатить миграции
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate

# 5. Зарегистрировать webhook Telegram
docker compose exec app php bin/console app:telegram:set-webhook

# 6. Создать первого пользователя
docker compose exec app php bin/console app:user:create
```

### Переменные окружения (.env)

```env
# База данных
DATABASE_URL="postgresql://user:password@db:5432/family_finance"

# Redis
REDIS_URL=redis://redis:6379

# Telegram
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_WEBHOOK_URL=https://yourdomain.com/telegram/webhook

# Приложение
APP_SECRET=your_random_secret
APP_URL=https://yourdomain.com

# Хранилище файлов (опционально, по умолчанию local)
STORAGE_TYPE=local   # или s3
```

---

## Использование Telegram-бота

| Команда | Описание |
|---|---|
| `/start` | Приветствие и регистрация |
| `/add` | Добавить расход |
| `/income` | Добавить доход |
| `/balance` | Текущий баланс по счетам |
| `/report` | Отчёт за текущий месяц |
| `/import` | Загрузить файл выписки |
| `/stats` | Статистика и графики |
| `/budget` | Бюджеты по категориям |
| `/help` | Справка |

### Быстрый ввод расхода

Просто отправьте боту сообщение в формате:
```
50 продукты
150.50 ресторан обед с семьёй
-200 перевод на копилку
```

---

## Развёртывание на Hetzner

Подробная инструкция: [docs/plan/07-deployment.md](docs/plan/07-deployment.md)

Краткий план:
1. Создать VPS (CX21 или выше), настроить DNS
2. Установить Docker + Docker Compose
3. Склонировать репозиторий, настроить `.env`
4. Запустить `docker compose -f docker-compose.prod.yml up -d`
5. Настроить Nginx reverse proxy + Let's Encrypt SSL
6. Зарегистрировать Telegram webhook

---

## Дорожная карта

- [x] Архитектура и план
- [ ] Базовый CRUD транзакций
- [ ] Telegram-бот (ввод/просмотр)
- [ ] Импорт Sparkasse CSV/MT940
- [ ] Веб-дашборд с графиками
- [ ] Категоризация с правилами
- [ ] Предложения по оптимизации
- [ ] Мультипользователь / семья
- [ ] Мобильно-адаптивный UI
- [ ] Экспорт данных

---

## Лицензия

MIT
