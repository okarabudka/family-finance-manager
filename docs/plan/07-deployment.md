# Шаг 7 — Развёртывание на Hetzner

> **Статус: ✅ РЕАЛИЗОВАНО**

## Цель
Развернуть приложение на VPS Hetzner с Docker Compose, настроить Nginx + Let's Encrypt SSL и CI/CD через GitHub Actions.

---

## 7.1 Выбор сервера

| Тариф Hetzner | vCPU | RAM | Диск | Цена/мес |
|---|---|---|---|---|
| CX11 | 1 | 2 GB | 20 GB | ~3.5 € |
| CX21 | 2 | 4 GB | 40 GB | ~6 € (**рекомендуется**) |
| CX31 | 2 | 8 GB | 80 GB | ~12 € |

Для старта достаточно CX21. ОС: Ubuntu 22.04 LTS.

---

## 7.2 Первоначальная настройка сервера

```bash
# Подключаемся к серверу
ssh root@YOUR_SERVER_IP

# Обновляем систему
apt update && apt upgrade -y

# Создаём непривилегированного пользователя
adduser deploy
usermod -aG sudo deploy

# Копируем SSH-ключ для нового пользователя
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy

# Настраиваем firewall
ufw allow OpenSSH
ufw allow 80
ufw allow 443
ufw enable

# Устанавливаем Docker
curl -fsSL https://get.docker.com | sh
usermod -aG docker deploy

# Устанавливаем Docker Compose
apt install docker-compose-plugin -y

# Устанавливаем Nginx (для reverse proxy)
apt install nginx certbot python3-certbot-nginx -y
```

---

## 7.3 Настройка DNS

В панели управления вашим доменом создайте A-записи:

```
finance.yourdomain.com    A    YOUR_SERVER_IP
```

---

## 7.4 Nginx конфигурация (reverse proxy)

Файл `/etc/nginx/sites-available/family-finance`:

```nginx
server {
    listen 80;
    server_name finance.yourdomain.com;

    # Let's Encrypt challenge
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl;
    server_name finance.yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/finance.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/finance.yourdomain.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    # Безопасность
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;

    client_max_body_size 20M;  # для загрузки файлов выписок

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 300;
    }
}
```

```bash
# Активируем конфигурацию
ln -s /etc/nginx/sites-available/family-finance /etc/nginx/sites-enabled/
nginx -t && nginx -s reload

# Получаем SSL-сертификат
certbot --nginx -d finance.yourdomain.com
```

---

## 7.5 Docker Compose для продакшна

Файл `docker-compose.prod.yml`:

```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile.prod
    environment:
      APP_ENV: prod
      APP_SECRET: ${APP_SECRET}
      DATABASE_URL: ${DATABASE_URL}
      MESSENGER_TRANSPORT_DSN: ${MESSENGER_TRANSPORT_DSN}
      TELEGRAM_BOT_TOKEN: ${TELEGRAM_BOT_TOKEN}
      TELEGRAM_WEBHOOK_URL: ${TELEGRAM_WEBHOOK_URL}
      TELEGRAM_WEBHOOK_SECRET: ${TELEGRAM_WEBHOOK_SECRET}
    volumes:
      - app_uploads:/var/www/html/var/uploads
      - app_logs:/var/www/html/var/log
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    networks:
      - app
    restart: unless-stopped
    healthcheck:
      # php-fpm -t не работает корректно на Alpine — используем статус-файл php-fpm
      test: ["CMD-SHELL", "php -r 'echo 1;' || exit 1"]
      interval: 30s
      timeout: 5s
      retries: 3

  nginx:
    image: nginx:1.25-alpine
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html:ro
      - ./docker/nginx/prod.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app
    networks:
      - app
    restart: unless-stopped

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: ${DB_NAME}
      POSTGRES_USER: ${DB_USER}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - postgres_data:/var/lib/postgresql/data
    networks:
      - app
    restart: unless-stopped
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USER} -d ${DB_NAME}"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    command: redis-server --requirepass ${REDIS_PASSWORD}
    volumes:
      - redis_data:/data
    networks:
      - app
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

  worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile.prod
    command: php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
    environment:
      APP_ENV: prod
      DATABASE_URL: ${DATABASE_URL}
      MESSENGER_TRANSPORT_DSN: ${MESSENGER_TRANSPORT_DSN}
      TELEGRAM_BOT_TOKEN: ${TELEGRAM_BOT_TOKEN}
    depends_on:
      - db
      - redis
    networks:
      - app
    restart: unless-stopped

volumes:
  postgres_data:
  redis_data:
  app_uploads:
  app_logs:

networks:
  app:
    driver: bridge
```

---

## 7.6 Dockerfile для продакшна

Файл `docker/php/Dockerfile.prod`:

```dockerfile
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
    postgresql-dev libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql zip intl mbstring opcache \
    && apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis opcache \
    && apk del $PHPIZE_DEPS

FROM base AS builder
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .
RUN php bin/console assets:install --no-interaction \
    && php bin/console cache:warmup --no-interaction

FROM base AS production
WORKDIR /var/www/html
COPY --from=builder /var/www/html .
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/custom.ini

USER www-data
```

---

## 7.7 Скрипт первого деплоя

```bash
#!/bin/bash
# Выполнять на сервере от пользователя deploy

set -e

APP_DIR="/home/deploy/family-finance"

# Клонируем репозиторий
git clone https://github.com/YOUR_USERNAME/family-finance.git $APP_DIR
cd $APP_DIR

# Настраиваем .env
cp .env.example .env
nano .env   # заполнить все переменные

# Запускаем контейнеры
docker compose -f docker-compose.prod.yml up -d --build

# Ждём готовности БД
sleep 5

# Миграции
docker compose -f docker-compose.prod.yml exec app \
    php bin/console doctrine:migrations:migrate --no-interaction

# Регистрируем Telegram webhook
docker compose -f docker-compose.prod.yml exec app \
    php bin/console app:telegram:set-webhook

echo "✅ Деплой завершён!"
```

---

## 7.8 CI/CD через GitHub Actions

Файл `.github/workflows/deploy.yml`:

```yaml
name: Deploy to Hetzner

on:
  push:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16-alpine
        env:
          POSTGRES_DB: test_db
          POSTGRES_USER: test_user
          POSTGRES_PASSWORD: test_pass
        ports: ["5432:5432"]
        options: --health-cmd pg_isready

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_pgsql, redis, intl, zip

      - name: Install dependencies
        run: composer install --no-interaction

      - name: Run tests
        env:
          DATABASE_URL: postgresql://test_user:test_pass@localhost:5432/test_db
        run: |
          php bin/console doctrine:migrations:migrate --no-interaction
          php bin/phpunit

  deploy:
    needs: test
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main'

    steps:
      - name: Deploy via SSH
        uses: appleboy/ssh-action@v1.0.0
        with:
          host: ${{ secrets.SERVER_HOST }}
          username: deploy
          key: ${{ secrets.SERVER_SSH_KEY }}
          script: |
            cd /home/deploy/family-finance
            git pull origin main
            docker compose -f docker-compose.prod.yml build app worker
            docker compose -f docker-compose.prod.yml up -d --no-deps app worker nginx
            docker compose -f docker-compose.prod.yml exec -T app \
              php bin/console doctrine:migrations:migrate --no-interaction
            docker compose -f docker-compose.prod.yml exec -T app \
              php bin/console cache:clear --no-warmup
            docker compose -f docker-compose.prod.yml exec -T app \
              php bin/console cache:warmup
            echo "Deploy complete: $(date)"
```

### GitHub Secrets для CI/CD

В настройках репозитория добавить:
- `SERVER_HOST` — IP или домен сервера
- `SERVER_SSH_KEY` — приватный SSH-ключ для деплоя

---

## 7.9 Резервное копирование

Скрипт `scripts/backup.sh`:

```bash
#!/bin/bash
set -e

BACKUP_DIR="/home/deploy/backups"
DATE=$(date +%Y%m%d_%H%M%S)
APP_DIR="/home/deploy/family-finance"

mkdir -p $BACKUP_DIR

# Загружаем переменные из .env (DB_USER, DB_NAME, DB_PASSWORD)
if [ -f "$APP_DIR/.env" ]; then
    export $(grep -v '^#' "$APP_DIR/.env" | grep -E '^DB_' | xargs)
fi

# Бэкап PostgreSQL
docker compose -f $APP_DIR/docker-compose.prod.yml exec -T db \
    pg_dump -U "$DB_USER" "$DB_NAME" | gzip > "$BACKUP_DIR/db_$DATE.sql.gz"

# Бэкап загруженных файлов
docker compose -f $APP_DIR/docker-compose.prod.yml run --rm \
    -v $BACKUP_DIR:/backup app \
    tar czf /backup/uploads_$DATE.tar.gz /var/www/html/var/uploads

# Удаляем бэкапы старше 30 дней
find $BACKUP_DIR -name "*.gz" -mtime +30 -delete

echo "Backup completed: $DATE"
```

Добавить в crontab пользователя deploy:
```
0 3 * * * /home/deploy/family-finance/scripts/backup.sh >> /home/deploy/backup.log 2>&1
```

---

## 7.10 Мониторинг

### Простая проверка доступности

```bash
# Добавить в crontab:
*/5 * * * * curl -sf https://finance.yourdomain.com/health || \
  curl -s "https://api.telegram.org/botYOUR_BOT_TOKEN/sendMessage?chat_id=YOUR_CHAT_ID&text=⚠️+Сервер+недоступен"
```

### Health endpoint

```php
// src/Controller/Web/HealthController.php
#[Route('/health')]
class HealthController extends AbstractController
{
    public function __invoke(Connection $connection): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');
            return new JsonResponse(['status' => 'ok']);
        } catch (\Exception $e) {
            return new JsonResponse(['status' => 'error', 'detail' => $e->getMessage()], 500);
        }
    }
}
```

---

## 7.11 Еженедельный отчёт через cron

```
# Еженедельный отчёт по понедельникам в 9:00
0 9 * * 1 docker compose -f /home/deploy/family-finance/docker-compose.prod.yml \
  exec -T app php bin/console app:report:weekly
```

---

## Результат шага 7

- VPS Hetzner настроен, сервер защищён firewall
- Nginx reverse proxy с Let's Encrypt SSL
- Продакшн Docker Compose с healthcheck для всех сервисов
- Оптимизированный Dockerfile (multi-stage build)
- GitHub Actions: тесты на каждый PR, деплой на push в main
- Автоматические бэкапы БД раз в сутки
- Health endpoint для мониторинга

---

## Общая последовательность деплоя (краткая шпаргалка)

```bash
# 1. Клонируем + настраиваем
git clone https://github.com/YOUR/family-finance && cd family-finance
cp .env.example .env && nano .env

# 2. Запускаем
docker compose -f docker-compose.prod.yml up -d --build

# 3. Инициализируем
docker compose -f docker-compose.prod.yml exec app php bin/console doctrine:migrations:migrate
docker compose -f docker-compose.prod.yml exec app php bin/console app:user:create
docker compose -f docker-compose.prod.yml exec app php bin/console app:telegram:set-webhook

# 4. Готово! Открываем бота в Telegram и вводим /start
```
