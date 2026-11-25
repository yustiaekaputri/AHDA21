#!/usr/bin/env bash
# Run manually ONLY inside a disposable GitHub Codespace created with .devcontainer.
set -euo pipefail
umask 077
repo="$(cd "$(dirname "$0")/.." && pwd -P)"
[[ "${CODESPACES:-}" == true && -n "${CODESPACE_NAME:-}" && -n "${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-}" ]] || { echo 'GitHub Codespace only'; exit 1; }
[[ "$CODESPACE_NAME" =~ ^[a-zA-Z0-9-]+$ && "$GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN" =~ ^[a-zA-Z0-9.-]+$ ]] || exit 1
[[ "${1:-}" == PRIVATE-DEMO-ONLY && $# == 1 ]] || { echo 'Verify port 8080 is Private in the Ports tab; then run bash scripts/codespace-preview.sh PRIVATE-DEMO-ONLY'; exit 1; }
export SDP_CODESPACE_HOST="$CODESPACE_NAME-8080.$GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN"
url="https://$SDP_CODESPACE_HOST"
state="$HOME/.cache/sdp-codespace-preview"
mkdir -p "$state"
chmod 700 "$state"
export SDP_WP_ROOT="$state/wordpress" SDP_WP_CLI="$state/wp-cli.phar" SDP_PREVIEW_MANIFEST="$state/manifest.json"
export SDP_PREVIEW_URL=http://127.0.0.1:8080
unset SDP_PREVIEW_AUTH
sudo service mariadb start
if [[ ! -f "$state/ready" ]]; then
  [[ ! -e "$SDP_WP_ROOT" && ! -e "$SDP_PREVIEW_MANIFEST" ]] || { echo 'Incomplete setup: use a new disposable Codespace; no automatic deletion'; exit 1; }
  # Refuse to modify pre-existing test database/user; never reset a database.
  existing="$(sudo mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='sdp_test'; SELECT COUNT(*) FROM mysql.user WHERE User='sdp_test';")"
  [[ "$existing" == $'0\n0' ]] || { echo 'Database or user already exists; use a fresh disposable Codespace'; exit 1; }
  export SDP_DB_PASSWORD="$(openssl rand -hex 24)"
  export SDP_DB_HOST=127.0.0.1 SDP_DB_PORT=3306
  # Hex-only random credential. Feed SQL through stdin, not command-line arguments.
  sudo mariadb <<SQL
CREATE DATABASE sdp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sdp_test'@'127.0.0.1' IDENTIFIED BY '$SDP_DB_PASSWORD';
GRANT ALL PRIVILEGES ON sdp_test.* TO 'sdp_test'@'127.0.0.1';
SQL
  curl -fsSL --retry 3 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$SDP_WP_CLI"
  bash "$repo/scripts/setup-preview.sh"
  unset SDP_DB_PASSWORD
  php "$SDP_WP_CLI" --path="$SDP_WP_ROOT" config set WP_HOME "$url"
  php "$SDP_WP_CLI" --path="$SDP_WP_ROOT" config set WP_SITEURL "$url"
  php "$SDP_WP_CLI" --path="$SDP_WP_ROOT" config set SDP_SMOKE_TEST_ENABLED false --raw
  printf '%s\n' "$(git -C "$repo" rev-parse HEAD)" > "$state/ready"
else
  [[ "$(cat "$state/ready")" == "$(git -C "$repo" rev-parse HEAD)" ]] || { echo 'Source commit changed. Create a new disposable Codespace for a fresh tested preview.'; exit 1; }
  [[ -f "$SDP_WP_ROOT/wp-config.php" && -f "$SDP_WP_CLI" ]] || { echo 'Preview incomplete; use a new Codespace'; exit 1; }
fi
echo "Private demo: $url"
echo 'Keep port 8080 Private. Press Ctrl+C to stop the web server; stop the Codespace when finished.'
exec php -d "auto_prepend_file=$repo/scripts/codespace-https.php" -S 127.0.0.1:8080 -t "$SDP_WP_ROOT"
