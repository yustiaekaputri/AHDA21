#!/usr/bin/env bash
set -euo pipefail
: "${SDP_WP_ROOT:?Set the temporary WordPress directory}"
[[ -f "$SDP_WP_ROOT/wp-config.php" ]] || { echo 'Run setup-preview first'; exit 1; }
export SDP_PREVIEW_URL="${SDP_PREVIEW_URL:-http://127.0.0.1:8080}"
[[ "$SDP_PREVIEW_URL" =~ ^http://127\.0\.0\.1:([0-9]+)$ ]] || exit 1
port="${BASH_REMATCH[1]}"
# PHP built-in server is for local preview, not production.
exec php -S "127.0.0.1:$port" -t "$SDP_WP_ROOT"
