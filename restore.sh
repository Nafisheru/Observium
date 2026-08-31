#!/bin/bash
set -e

cd "$(dirname "$0")"

if [ -z "$1" ]; then
    echo "Usage: ./restore.sh <backup_file.tar.gz>"
    echo "Example: ./restore.sh backups/observium_backup_20231025_0200.tar.gz"
    exit 1
fi

BACKUP_FILE=$1
if [ ! -f "$BACKUP_FILE" ]; then
    echo "[-] Error: File $BACKUP_FILE not found."
    exit 1
fi

# Get absolute path of backup file
BACKUP_ABS_PATH=$(realpath "$BACKUP_FILE")

echo "⚠️  WARNING: This will OVERWRITE your current Observium Database, RRD data, and .env file!"
read -p "Are you sure you want to proceed? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Restore cancelled."
    exit 1
fi

TMP_DIR=$(mktemp -d)

echo "[+] Extracting backup archive to temporary folder..."
tar -xzf "$BACKUP_ABS_PATH" -C "$TMP_DIR"

echo "[+] Restoring .env file..."
cp "$TMP_DIR/.env_backup" ./.env

# Source new .env to get passwords
export $(grep -v "^#" .env | xargs)

echo "[+] Restoring Database (this may take a few minutes)..."
# Ensure the DB container is running
if ! docker ps | grep -q "observium-db"; then
    echo "[-] Error: observium-db container is not running. Please run 'docker compose up -d' first."
    rm -rf "$TMP_DIR"
    exit 1
fi

docker exec -i observium-db mariadb -u root -p"${MARIADB_ROOT_PASSWORD}" ${DB_NAME:-observium} < "$TMP_DIR/db_backup.sql"

echo "[+] Restoring RRD Data..."
# Clear existing and extract new
docker run --rm -v observium-rrd:/rrd -v "$TMP_DIR":/backup alpine sh -c "rm -rf /rrd/* && tar -xzf /backup/rrd_backup.tar.gz -C /rrd"

echo "[+] Cleaning up..."
rm -rf "$TMP_DIR"

echo "[+] Restarting Observium containers..."
docker compose restart

echo "[+] Restore completed successfully! Your data is back."
