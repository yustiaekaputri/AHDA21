#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
(
    umask 077
    flock 9
    if [[ ! -f .env ]]; then
        {
            cat .env.example
            printf 'SDP_DB_PASSWORD=%s\n' "$(openssl rand -hex 24)"
            printf 'SDP_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        } > .env
    fi
    if ! grep -q '^SDP_WP_SECRET=' .env; then
        printf 'SDP_WP_SECRET=%s\n' "$(openssl rand -hex 32)" >> .env
    fi
) 9>.env.lock
export SDP_PHP_VERSION=${SDP_PHP_VERSION:-$(sed -n 's/^SDP_PHP_VERSION=//p' .env)}
case "$SDP_PHP_VERSION" in
    8.2) export SDP_PHP_OS_IMAGE=debian:bookworm-slim ;;
    8.3) export SDP_PHP_OS_IMAGE=ubuntu:24.04 ;;
    *) echo 'Gunakan SDP_PHP_VERSION=8.2 atau 8.3.' >&2; exit 1 ;;
esac
compose=(docker compose -f docker-compose.yml)
if [[ -n "${CODEX_PROXY_CERT:-}" && -r "$CODEX_PROXY_CERT" ]]; then
    proxy_config=$(mktemp)
    trap 'rm -f "$proxy_config"' EXIT
    cat > "$proxy_config" <<'YAML'
services:
  wordpress:
    build:
      secrets: [sdp_proxy_ca]
secrets:
  sdp_proxy_ca:
    file: ${CODEX_PROXY_CERT}
YAML
    compose+=(-f "$proxy_config")
fi
"${compose[@]}" up -d --build --wait
