#!/bin/sh
# Prepares the development container: dependencies, database schema and assets.
# Only runs for php-fpm so that the worker (same image) does not race the app container.
set -e

if [ "$1" = "php-fpm" ]; then
    composer install --prefer-dist --no-interaction --no-progress
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php bin/console importmap:install
    php bin/console tailwind:build
fi

exec docker-php-entrypoint "$@"
