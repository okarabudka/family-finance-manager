.PHONY: up down shell migrate fixtures test cache-clear set-webhook assets assets-watch analyse install-hooks

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

assets:
	docker compose exec app npm run dev

assets-watch:
	docker compose exec app npm run watch

analyse:
	docker compose exec app vendor/bin/phpstan analyse

install-hooks:
	cp scripts/pre-commit .git/hooks/pre-commit
	chmod +x .git/hooks/pre-commit
	@echo "Git pre-commit hook installed."
