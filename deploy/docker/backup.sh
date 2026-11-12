#!/bin/sh
set -eu
umask 077
config_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
backup_dir=${1:?Usage: backup.sh /absolute/backup/directory}
stamp=$(date -u +%Y%m%d-%H%M%S)
mkdir -p "$backup_dir"
docker compose -f "$config_dir/compose.yaml" exec -T site-db pg_dump -U postgres -d magexpert -Fc --no-owner --no-acl > "$backup_dir/site-$stamp.dump"
docker compose -f "$config_dir/compose.yaml" exec -T sender-db pg_dump -U postgres -d sender -Fc --no-owner --no-acl > "$backup_dir/sender-$stamp.dump"
tar -czf "$backup_dir/config-$stamp.tar.gz" -C "$config_dir" compose.yaml .env secrets
tar -czf "$backup_dir/files-$stamp.tar.gz" -C "${STATE_DIR:-/srv/mag-services/data}" site-storage sender-storage
printf 'Backup completed: %s\n' "$backup_dir"
