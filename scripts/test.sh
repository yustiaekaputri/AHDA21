#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

export COMPOSE_PROJECT_NAME="sdp-test-${GITHUB_RUN_ID:-local}-${SDP_PHP_VERSION:-8.3}-$$"
export SDP_HTTP_PORT="${SDP_TEST_PORT:-3001}"
export SDP_BIND_ADDRESS=127.0.0.1
export SDP_SITE_URL="http://127.0.0.1:$SDP_HTTP_PORT"
export SDP_BROWSER_URL="$SDP_SITE_URL"
trap 'scripts/reset-test-db.sh --confirm' EXIT

mkdir -p artifacts/reports artifacts/screenshots artifacts/tooling
scripts/setup.sh
scripts/install.sh
scripts/wp.sh eval 'global $wpdb; echo wp_json_encode(["wordpress" => get_bloginfo("version"), "php" => PHP_VERSION, "database" => $wpdb->get_var("SELECT VERSION()"), "wp_cli" => WP_CLI_VERSION, "locale" => get_locale()], JSON_PRETTY_PRINT);' > artifacts/reports/runtime.json
scripts/wp.sh eval-file /opt/sdp/tests/localwp/sdp-run-localwp.php DISPOSABLE-LOCAL-ONLY --user=sdp-admin 2>&1 | tee artifacts/reports/rc6.log
scripts/wp.sh plugin deactivate sekolah-directory-pro
scripts/wp.sh plugin activate sekolah-directory-pro
scripts/test-backup.sh | tee artifacts/reports/backup.log
scripts/test-cli-import.sh | tee artifacts/reports/cli-import.log

phpunit="$PWD/artifacts/tooling/phpunit.phar"
if [[ ! -f "$phpunit" ]]; then
    curl -fsSL https://phar.phpunit.de/phpunit-10.5.67.phar -o "$phpunit"
fi
echo "0635a0a340df88ea3a2c4e726a0df14160fbb542550a36cbda70921dbd756f95  $phpunit" | sha256sum --check --status
docker compose --file docker-compose.yml cp "$phpunit" wordpress:/tmp/sdp-phpunit.phar
docker compose --file docker-compose.yml exec -T wordpress php /tmp/sdp-phpunit.phar --bootstrap /opt/sdp/tests/integration/bootstrap.php \
    --log-junit /tmp/sdp-phpunit.xml /opt/sdp/tests/integration
docker compose --file docker-compose.yml cp wordpress:/tmp/sdp-phpunit.xml artifacts/reports/phpunit.xml
scripts/wp.sh eval-file /opt/sdp/scripts/seed-demo.php --user=sdp-admin
export SDP_ADMIN_PASSWORD
SDP_ADMIN_PASSWORD=$(sed -n 's/^SDP_ADMIN_PASSWORD=//p' .env)
export SDP_OPERATOR_PASSWORD
SDP_OPERATOR_PASSWORD=$(openssl rand -hex 24)
if ! printf '%s\n' "$SDP_OPERATOR_PASSWORD" | scripts/wp.sh user create sdp-operator demo-operator@example.invalid \
    --role=subscriber --prompt=user_pass >/dev/null 2>&1; then
    echo 'Akun operator untuk pengujian gagal dibuat.' >&2
    exit 1
fi
npm test
