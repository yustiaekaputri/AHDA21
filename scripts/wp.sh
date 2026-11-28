#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
exec docker compose --file docker-compose.yml exec -T -u www-data wordpress wp "$@"
