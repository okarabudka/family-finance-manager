# Шаг 1 — Настройка проекта

## Цель
Создать базовую структуру Symfony-проекта с Docker-окружением, готовым к разработке.

---

## 1.1 Создание Symfony-проекта

```bash
composer create-project symfony/skeleton:"7.*" family-finance
cd family-finance

# Установить основные пакеты
composer require \
    symfony/orm-pack \
    symfony/messenger \
    symfony/security-bundle \
    symfony/twig-bundle \
    symfony/asset \
    symfony/validator \
    symfony/form \
    symfony/mailer \
    symfony/http-client \
    symfony/cache \
    symfony/serializer-pack \
    symfony/uid \
    doctrine/doctrine-migrations-bundle \
    stof/doctrine-extensions-bundle \
    knplabs/knp-paginator-bundle \
    irazasyed/telegram-bot-sdk \
    codelicious/mt940 \
    league/csv \
    symfony/webpack-encore-bundle

# Dev-зависимости
composer require --dev \
    symfony/maker-bundle \
    symfony/debug-bundle \
    doctrine/doctrine-fixtures-bundle \
    phpunit/phpunit \
    symfony/phpunit-bridge \
    fakerphp/faker \
    squizlabs/php_codesniffer
```

---

## 1.2 Структура директорий

```
family-finance/
├── config/
│   ├── packages/
│   └── routes/
├── docs/
│   └── plan/           ← файлы этого плана
├── docker/
│   ├── nginx/
│   │   └── default.conf
│   ├── php/
│   │   ├── Dockerfile
│   │   └── php.ini
│   └── redis/
├── migrations/
├── public/
├── src/
│   ├── Command/
│   ├── Controller/
│   │   ├── Web/
│   │   └── Telegram/
│   ├── Domain/
│   │   ├── Account/
│   │   ├── Analytics/
│   │   ├── Category/
│   │   ├── Import/
│   │   ├── Suggestion/
│   │   └── Transaction/
│   ├── Entity/
│   ├── Message/
│   ├── MessageHandler/
│   ├── Repository/
│   ├── Security/
│   └── Telegram/
│       ├── Conversation/
│       ├── Handler/
│       └── Keyboard/
├── templates/
│   ├── base.html.twig
│   ├── dashboard/
│   ├── transaction/
│   ├── account/
│   └── category/
├── tests/
├── .env.example
├── docker-compose.yml
├── docker-compose.prod.yml
└── Makefile
```

---

## 1.3 Docker Compose (разработка)

Файл `docker-compose.yml`:

```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    volumes:
      - .:/var/www/html
      - /var/www/html/vendor
    environment:
      - APP_ENV=dev
    depends_on:
      - db
      - redis
    networks:
      - app

  nginx:
    image: nginx:1.25-alpine
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app
    networks:
      - app

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: family_finance
      POSTGRES_USER: app
      POSTGRES_PASSWORD: secret
    volumes:
      - postgres_data:/var/lib/postgresql/data
    ports:
      - "5432:5432"
    networks:
      - app

  redis:
    image: redis:7-alpine
    volumes:
      - redis_data:/data
    networks:
      - app

  worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    command: php bin/console messenger:consume async --time-limit=3600
    volumes:
      - .:/var/www/html
    depends_on:
      - db
      - redis
    networks:
      - app
    restart: unless-stopped

volumes:
  postgres_data:
  redis_data:

networks:
  app:
```

---

## 1.4 Dockerfile для PHP

Файл `docker/php/Dockerfile`:

```dockerfile
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        zip \
        intl \
        mbstring \
        opcache

RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
```

---

## 1.5 Makefile — удобные команды

Файл `Makefile`:

```makefile
.PHONY: up down shell migrate fixtures test

up:
	docker compose up -d

down:
	docker compose down

shell:
	docker compose exec app bash

migrate:
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

fixtures:
	docker compose exec app php bin/console doctrine:fixtures:load --no-interaction

test:
	docker compose exec app php bin/phpunit

cache-clear:
	docker compose exec app php bin/console cache:clear

set-webhook:
	docker compose exec app php bin/console app:telegram:set-webhook
```

---

## 1.6 Файл .env.example

```env
APP_ENV=dev
APP_SECRET=change_this_to_random_string
APP_URL=http://localhost:8080

DATABASE_URL="postgresql://app:secret@db:5432/family_finance?serverVersion=16&charset=utf8"

MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages

TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_URL=https://yourdomain.com/telegram/webhook
TELEGRAM_WEBHOOK_SECRET=change_this_to_random_string

STORAGE_TYPE=local
STORAGE_LOCAL_PATH=%kernel.project_dir%/var/uploads
```

---

## 1.7 Конфигурация пакетов

### config/packages/doctrine.yaml
```yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        types:
            uuid: Symfony\Bridge\Doctrine\Types\UuidType
    orm:
        auto_generate_proxy_classes: true
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
        mappings:
            App:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Entity'
                prefix: 'App\Entity'
```

### config/packages/messenger.yaml
```yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2
        routing:
            'App\Message\ProcessImportMessage': async
            'App\Message\SendNotificationMessage': async
            'App\Message\GenerateReportMessage': async
```

---

## Результат шага 1

После выполнения:
- Работающий `docker compose up -d`
- Доступный `http://localhost:8080` с дефолтной страницей Symfony
- Подключение к PostgreSQL и Redis
- Makefile с командами для ежедневной работы
- `.env.example` для документирования переменных

## Следующий шаг

→ [02-database.md](02-database.md) — Проектирование базы данных и создание сущностей
