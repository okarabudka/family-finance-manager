SHELL := /bin/sh

export UID := $(shell id -u)
export GID := $(shell id -g)
APP_PORT ?= 8080
MAILPIT_PORT ?= 8025
export APP_PORT MAILPIT_PORT

COMPOSE := docker compose
PHP     := $(COMPOSE) exec -T app
CONSOLE := $(PHP) php bin/console
DB      := $(COMPOSE) exec -T db psql -U app -d family_finance

# Sample Telegram text for `make bot-replay`
TEXT ?= /balance

.DEFAULT_GOAL := help
.PHONY: help up down restart logs shell build seed fixtures migrate test-db test test-unit coverage stan cs cs-fix \
        qa tailwind-watch cache-clear set-webhook bot-replay install-hooks

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-16s %s\n", $$1, $$2}'

up: ## Build and start the stack, install dependencies, migrate and seed demo data
	$(COMPOSE) up -d --build --wait --remove-orphans
	@$(MAKE) --no-print-directory seed
	@echo "App:     http://localhost:$(APP_PORT)  (demo@example.com / demo-password)"
	@echo "Mailpit: http://localhost:$(MAILPIT_PORT)"

down: ## Stop the stack
	$(COMPOSE) down

restart: down up ## Restart the stack

logs: ## Follow application and worker logs
	$(COMPOSE) logs -f app worker

shell: ## Open a shell in the PHP container
	$(COMPOSE) exec app sh

build: ## Rebuild images
	$(COMPOSE) build

seed: ## Load demo fixtures unless the database already has users
	@if [ "`$(DB) -tAc 'SELECT 1 FROM users LIMIT 1'`" = "1" ]; then \
		echo "Database already contains data, demo fixtures skipped (run 'make fixtures' to reset)."; \
	else \
		$(MAKE) --no-print-directory fixtures; \
	fi

fixtures: ## Reset the database to the demo data set
	$(CONSOLE) doctrine:fixtures:load --no-interaction
	$(CONSOLE) cache:pool:clear cache.app

migrate: ## Run database migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

test-db: ## Recreate the test database with the demo data set
	$(CONSOLE) doctrine:database:drop --env=test --force --if-exists
	$(CONSOLE) doctrine:database:create --env=test
	$(CONSOLE) doctrine:migrations:migrate --env=test --no-interaction
	$(CONSOLE) doctrine:fixtures:load --env=test --no-interaction

test: test-db ## Run the whole test suite
	$(PHP) php bin/phpunit

test-unit: ## Run unit tests only (no database needed)
	$(PHP) php bin/phpunit --testsuite unit

coverage: test-db ## Run tests with coverage (HTML report in var/coverage)
	$(PHP) php bin/phpunit --coverage-html var/coverage --coverage-text

stan: ## Static analysis
	$(PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

cs: ## Check coding standards
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix coding standards
	$(PHP) vendor/bin/php-cs-fixer fix

qa: cs stan test ## Run all quality checks

tailwind-watch: ## Rebuild Tailwind CSS on change
	$(COMPOSE) exec app php bin/console tailwind:build --watch

cache-clear: ## Clear the Symfony cache
	$(CONSOLE) cache:clear

set-webhook: ## Register the Telegram webhook (needs real TELEGRAM_* values in .env.local)
	$(CONSOLE) app:telegram:set-webhook

bot-replay: ## Send a recorded Telegram update to the local webhook: make bot-replay TEXT="12.50 coffee"
	@since=`date -u +%Y-%m-%dT%H:%M:%SZ`; \
	sed 's|__TEXT__|$(TEXT)|' tests/Fixtures/telegram/message.json \
		| curl -sS -X POST "http://localhost:$(APP_PORT)/telegram/webhook" \
			-H 'Content-Type: application/json' \
			-H 'X-Telegram-Bot-Api-Secret-Token: dev-webhook-secret' \
			--data-binary @- -w ' (HTTP %{http_code})\n'; \
	$(COMPOSE) logs --no-log-prefix --since "$$since" app | grep 'Telegram reply' || true

install-hooks: ## Install the git pre-commit hook
	cp scripts/pre-commit .git/hooks/pre-commit
	chmod +x .git/hooks/pre-commit
