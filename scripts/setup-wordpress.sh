#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[[ ${1:-} == DISPOSABLE-LOCAL-ONLY ]] || { echo 'Use DISPOSABLE-LOCAL-ONLY on a fresh development stack'; exit 1; }
docker compose up -d --build
wp() { docker compose exec -T -u www-data wordpress wp "$@"; }
ready=0
for i in $(seq 1 90); do
  if docker compose exec -T wordpress test -f /var/www/html/wp-config.php && docker compose exec -T wordpress test -f /var/www/html/wp-includes/version.php && curl -fsS http://127.0.0.1:8080/wp-admin/install.php >/dev/null; then ready=1; break; fi
  sleep 2
done
[[ $ready == 1 ]] || { echo 'WordPress startup timeout'; exit 1; }
if wp core is-installed; then echo 'Existing WordPress: refusing to install over it. Use a fresh stack.'; exit 1; fi
password=$(python3 -c 'import secrets; print(secrets.token_urlsafe(24))')
(umask 077; printf '%s\n' "$password" > .env.sdp-admin-password)
wp core install --url=http://127.0.0.1:8080 --title='Sekolah Directory DEMO FIKTIF' --admin_user=sdp_local_admin --admin_password="$password" --admin_email=admin@example.invalid --skip-email
wp option update blog_public 0
wp plugin activate sekolah-directory-pro
wp theme activate sekolah-directory-theme
wp rewrite structure '/%postname%/' --hard
wp eval '$r=SDP_Region::install(); if(is_wp_error($r)) WP_CLI::error($r->get_error_message());' --user=sdp_local_admin
wp core version
docker compose exec -T wordpress php -v
docker compose exec -T wordpress php -m
wp eval 'global $wpdb; $v=$wpdb->get_var("SELECT VERSION()"); if (!$v || $wpdb->last_error) WP_CLI::error("Database unavailable"); WP_CLI::log("Database: ".$v);'
