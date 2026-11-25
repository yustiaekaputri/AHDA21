#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ "${1:-}" != --confirm ]] || [[ "${COMPOSE_PROJECT_NAME:-}" != sdp-test-* ]]; then
    echo 'Gunakan proyek sdp-test-* dan argumen --confirm untuk menghapus volume uji.' >&2
    exit 1
fi
docker compose --file docker-compose.yml down --volumes
