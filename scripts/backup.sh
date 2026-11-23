#!/bin/bash
# Dumps the production database. Run from the project directory on the Docker host, e.g. from cron:
#   0 3 * * * cd /opt/family-finance && scripts/backup.sh
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
ENV_FILE="${ENV_FILE:-.env.prod}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/family-finance}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
DATE=$(date +%Y%m%d_%H%M%S)
TARGET="$BACKUP_DIR/family_finance_$DATE.sql.gz"

mkdir -p "$BACKUP_DIR"

ENV_ARGS=()
if [ -f "$ENV_FILE" ]; then
    ENV_ARGS=(--env-file "$ENV_FILE")
fi

# Credentials are read inside the db container, so they never appear in the host process list.
docker compose -f "$COMPOSE_FILE" "${ENV_ARGS[@]}" exec -T db \
    sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > "$TARGET"

find "$BACKUP_DIR" -name "*.sql.gz" -mtime +"$RETENTION_DAYS" -delete

echo "Backup saved: $TARGET"
