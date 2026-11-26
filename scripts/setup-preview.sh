#!/usr/bin/env bash
# New disposable WordPress only. Never pass credentials or paths from production.
set -euo pipefail
repo="$(cd "$(dirname "$0")/.." && pwd)"
: "${SDP_WP_ROOT:?Set SDP_WP_ROOT to a new empty temporary directory}"
: "${SDP_DB_PASSWORD:?Set a disposable test database password}"
export SDP_DB_HOST="${SDP_DB_HOST:-127.0.0.1}" SDP_DB_PORT="${SDP_DB_PORT:-3306}"
export SDP_DB_NAME=sdp_test SDP_DB_USER=sdp_test
export SDP_PREVIEW_URL="${SDP_PREVIEW_URL:-http://127.0.0.1:8080}"
[[ "$SDP_DB_HOST" == 127.0.0.1 || "$SDP_DB_HOST" == localhost ]] || { echo 'Only local DB allowed'; exit 1; }
[[ "$SDP_PREVIEW_URL" =~ ^http://127\.0\.0\.1:[0-9]+$ ]] || { echo 'Only loopback preview allowed'; exit 1; }
mkdir -p "$SDP_WP_ROOT"
root="$(cd "$SDP_WP_ROOT" && pwd -P)"
[[ "$root" != "$repo" && "$root" != / && -z "$(find "$root" -mindepth 1 -maxdepth 1 -print -quit)" ]] || { echo 'WordPress directory must be empty'; exit 1; }
export SDP_DB_PASSWORD
php -r 'mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT); $db=new mysqli(getenv("SDP_DB_HOST"),getenv("SDP_DB_USER"),getenv("SDP_DB_PASSWORD"),getenv("SDP_DB_NAME"),(int)getenv("SDP_DB_PORT")); if($db->query("SHOW TABLES")->num_rows!==0){fwrite(STDERR,"Refuse nonempty test database\n");exit(1);} echo "Database: ".$db->server_info.PHP_EOL;'
if [[ -n "${SDP_WP_CLI:-}" ]]; then
  wp=(php "$SDP_WP_CLI" --path="$root" --allow-root)
else
  wp=(wp --path="$root" --allow-root)
fi
version=()
[[ -z "${SDP_WP_VERSION:-}" ]] || version=(--version="$SDP_WP_VERSION")
"${wp[@]}" core download "${version[@]}"
"${wp[@]}" config create --dbname="$SDP_DB_NAME" --dbuser="$SDP_DB_USER" --dbpass="$SDP_DB_PASSWORD" --dbhost="$SDP_DB_HOST:$SDP_DB_PORT"
"${wp[@]}" config set WP_ENVIRONMENT_TYPE local
for name in SDP_SAFE_MODE SDP_SMOKE_TEST_ENABLED SDP_LOW_RESOURCE DISABLE_WP_CRON WP_HTTP_BLOCK_EXTERNAL AUTOMATIC_UPDATER_DISABLED; do
  "${wp[@]}" config set "$name" true --raw
done
"${wp[@]}" core install --url="$SDP_PREVIEW_URL" --title='Sekolah Directory — DEMO FIKTIF' --admin_user=sdp_preview_admin --admin_password="$(openssl rand -hex 24)" --admin_email=admin@example.invalid --skip-email
# The installation directory is the HTTP document root, not a URL prefix.
# Persist both options before defining the constants (which override option reads).
"${wp[@]}" option update home "$SDP_PREVIEW_URL"
"${wp[@]}" option update siteurl "$SDP_PREVIEW_URL"
"${wp[@]}" config set WP_HOME "$SDP_PREVIEW_URL"
"${wp[@]}" config set WP_SITEURL "$SDP_PREVIEW_URL"
"${wp[@]}" eval 'foreach ([home_url(),site_url(),wp_login_url()] as $url) { echo $url.PHP_EOL; } if (home_url()!==getenv("SDP_PREVIEW_URL") || site_url()!==getenv("SDP_PREVIEW_URL") || wp_login_url()!==getenv("SDP_PREVIEW_URL")."/wp-login.php") WP_CLI::error("Unexpected WordPress canonical URLs");'
"${wp[@]}" option update blog_public 0
"${wp[@]}" option update permalink_structure ''
if [[ "${SDP_LOGIN_PROBE:-0}" == 1 ]]; then bash "$repo/scripts/probe-login.sh" core; fi
# Optional archive-based installation is restricted to disposable GitHub CI.
if [[ -n "${SDP_PLUGIN_ARCHIVE:-}${SDP_THEME_ARCHIVE:-}" ]]; then
  [[ "${GITHUB_ACTIONS:-}" == true && "$root" == "$RUNNER_TEMP/sdp-wordpress" && -f "$SDP_PLUGIN_ARCHIVE" && -f "$SDP_THEME_ARCHIVE" && "$SDP_PLUGIN_ARCHIVE" == *.zip && "$SDP_THEME_ARCHIVE" == *.zip ]] || { echo 'Archive mode requires disposable CI and both ZIPs'; exit 1; }
  "${wp[@]}" plugin install "$SDP_PLUGIN_ARCHIVE"
  "${wp[@]}" theme install "$SDP_THEME_ARCHIVE"
else
  mkdir -p "$root/wp-content/plugins/sekolah-directory-pro" "$root/wp-content/themes/sekolah-directory-theme"
  cp -R "$repo/plugin/." "$root/wp-content/plugins/sekolah-directory-pro/"
  cp -R "$repo/theme/." "$root/wp-content/themes/sekolah-directory-theme/"
fi
"${wp[@]}" plugin activate sekolah-directory-pro
"${wp[@]}" theme activate sekolah-directory-theme
if [[ "${SDP_LOGIN_PROBE:-0}" == 1 ]]; then bash "$repo/scripts/probe-login.sh" active; fi
"${wp[@]}" core version
php -v
"${wp[@]}" eval-file "$repo/tests/localwp/sdp-run-localwp.php" DISPOSABLE-LOCAL-ONLY --user=sdp_preview_admin
: "${SDP_PREVIEW_MANIFEST:?Set absolute path for preview manifest outside WordPress}"
export SDP_PREVIEW_MANIFEST
"${wp[@]}" eval-file "$repo/scripts/seed-preview.php" --user=sdp_preview_admin
if [[ "${SDP_LOGIN_PROBE:-0}" == 1 ]]; then bash "$repo/scripts/probe-login.sh" seeded; fi
echo "Ready: $root (loopback only)"
