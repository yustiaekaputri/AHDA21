#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

export SDP_HTTP_PORT=3000
export SDP_BIND_ADDRESS=127.0.0.1
if [[ -n "${CODESPACE_NAME:-}" && -n "${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-}" ]]; then
    export SDP_SITE_URL="https://${CODESPACE_NAME}-${SDP_HTTP_PORT}.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
fi
scripts/start.sh
printf 'Dashboard demo: %s/wp-admin/admin.php?page=sdp-directory\n' "${SDP_SITE_URL:-http://127.0.0.1:3000}"
