#!/bin/bash
set -e

cd "$(dirname "$0")"

# Set retention days (default 7)
RETENTION_DAYS=7
DATE=$(date +"%Y%m%d_%H%M")
BACKUP_DIR="./backups"
FINAL_ARCHIVE="observium_backup_$DATE.tar.gz"

echo "[+] Starting Observium Backup..."
mkdir -p "$BACKUP_DIR"

# Source .env if exists
if [ -f .env ]; then
    export $(grep -v "^#" .env | xargs)
else
    echo "[-] Error: .env file not found. Cannot proceed."
    exit 1
fi

echo "[+] Dumping Database (observium-db)..."
docker exec -i observium-db mariadb-dump -u root -p"${MARIADB_ROOT_PASSWORD}" ${DB_NAME:-observium} > "$BACKUP_DIR/db_backup.sql"

echo "[+] Archiving RRD Data (this might take a while depending on size)..."
docker run --rm -v observium-rrd:/rrd -v $(pwd)/$BACKUP_DIR:/backup alpine tar -czf /backup/rrd_backup.tar.gz -C /rrd .

echo "[+] Copying .env file..."
cp .env "$BACKUP_DIR/.env_backup"

echo "[+] Packaging final backup archive: $FINAL_ARCHIVE..."
cd "$BACKUP_DIR"
tar -czf "$FINAL_ARCHIVE" db_backup.sql rrd_backup.tar.gz .env_backup

echo "[+] Cleaning up temporary files..."
rm db_backup.sql rrd_backup.tar.gz .env_backup
cd ..

echo "[+] Backup successfully created at: $BACKUP_DIR/$FINAL_ARCHIVE"

echo "[+] Applying retention policy (deleting backups older than $RETENTION_DAYS days)..."
find "$BACKUP_DIR" -name "observium_backup_*.tar.gz" -type f -mtime +$RETENTION_DAYS -exec rm -f {} \;

echo "[+] Done!"
