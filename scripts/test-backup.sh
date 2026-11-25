#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ "${COMPOSE_PROJECT_NAME:-}" != sdp-test-* ]]; then
    echo 'Uji pemulihan memerlukan proyek sekali pakai sdp-test-*.' >&2
    exit 1
fi
umask 077
dump=$(mktemp)
trap 'rm -f "$dump"' EXIT

scripts/wp.sh option update sdp_backup_probe fixture-backup >/dev/null
docker compose --file docker-compose.yml exec -T db sh -ec 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump --user="$MARIADB_USER" --single-transaction --skip-lock-tables "$MARIADB_DATABASE"' > "$dump"
scripts/wp.sh option delete sdp_backup_probe >/dev/null
docker compose --file docker-compose.yml exec -T db sh -ec 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb --user="$MARIADB_USER" "$MARIADB_DATABASE"' < "$dump"
[[ "$(scripts/wp.sh option get sdp_backup_probe)" == fixture-backup ]]
scripts/wp.sh option delete sdp_backup_probe >/dev/null
echo 'PASS: snapshot MariaDB memulihkan opsi fixture WordPress.'
