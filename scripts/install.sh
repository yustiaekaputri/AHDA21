#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
port=${SDP_HTTP_PORT:-$(sed -n 's/^SDP_HTTP_PORT=//p' .env)}
url=${SDP_SITE_URL:-http://127.0.0.1:${port:-3000}}
admin_password=$(sed -n 's/^SDP_ADMIN_PASSWORD=//p' .env)
[[ -n "$admin_password" ]]
if ! scripts/wp.sh core is-installed; then
    if ! printf '%s\n' "$admin_password" | scripts/wp.sh core install --url="$url" \
        --title='Sekolah Directory Indonesia — DEMO' --admin_user=sdp-admin \
        --admin_email=demo-admin@example.invalid --skip-email --prompt=admin_password >/dev/null 2>&1; then
        echo 'Instalasi WordPress gagal. Periksa kontainer dan koneksi database.' >&2
        exit 1
    fi
fi
scripts/wp.sh option update home "$url"
scripts/wp.sh option update siteurl "$url"
scripts/wp.sh option update blog_public 0
scripts/wp.sh site switch-language id_ID
scripts/wp.sh plugin activate sekolah-directory-pro
scripts/wp.sh theme activate sekolah-directory-theme
scripts/wp.sh rewrite structure '/%postname%/'
