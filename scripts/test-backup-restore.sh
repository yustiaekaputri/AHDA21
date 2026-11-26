#!/usr/bin/env bash
# Destructive restore ONLY within the dedicated disposable CI database.
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${SDP_PREVIEW_URL:-}" == http://127.0.0.1:8080 && "$SDP_WP_ROOT" == "$RUNNER_TEMP/sdp-wordpress" ]]
[[ "${SDP_DB_PASSWORD:-}" == disposable-ci-only && "${SDP_DB_PORT:-}" =~ ^[0-9]+$ ]]
[[ "$SDP_DB_PORT" -gt 0 && "$SDP_DB_PORT" -le 65535 ]]
umask 077
private="$(mktemp -d "$RUNNER_TEMP/sdp-restore.XXXXXXXX")"
trap 'rm -rf -- "$private"' EXIT
wp=(php -d memory_limit=128M "$SDP_WP_CLI" --path="$SDP_WP_ROOT" --allow-root --user=sdp_preview_admin)
conn=(--protocol=TCP --host=127.0.0.1 --port="$SDP_DB_PORT" --user=sdp_test)
mkdir -p artifacts
"${wp[@]}" eval-file tests/integration/backup-state.php snapshot > artifacts/before-restore.json
# Do not print or upload SQL dumps, which include credentials and user metadata.
MYSQL_PWD="$SDP_DB_PASSWORD" mariadb-dump --no-defaults "${conn[@]}" --single-transaction --quick --skip-lock-tables --no-tablespaces --add-drop-table sdp_test > "$private/backup.sql"
[[ -s "$private/backup.sql" ]]
sha256sum "$private/backup.sql" > "$private/backup.sha256"
"${wp[@]}" eval-file tests/integration/backup-state.php mutate
sha256sum --status -c "$private/backup.sha256"
MYSQL_PWD="$SDP_DB_PASSWORD" mariadb --no-defaults "${conn[@]}" sdp_test < "$private/backup.sql"
"${wp[@]}" eval-file tests/integration/backup-state.php snapshot > artifacts/after-restore.json
diff -u artifacts/before-restore.json artifacts/after-restore.json
echo 'PASS: logical database backup/restore preserves all four fixture rows, posts, metadata, public counts and draft REST privacy'
