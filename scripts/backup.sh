#!/bin/bash
set -euo pipefail

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="${BACKUP_DIR:-/var/backups/family-finance}"

mkdir -p "$BACKUP_DIR"

docker compose exec -T database pg_dump -U app family_finance | gzip > "$BACKUP_DIR/family_finance_$DATE.sql.gz"

# Keep only last 30 days
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +30 -delete

echo "Backup saved: $BACKUP_DIR/family_finance_$DATE.sql.gz"
