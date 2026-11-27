#!/usr/bin/env bash
set -euo pipefail

source_dir=/opt/sdp/source
cgroup=/sys/fs/cgroup
reports=/reports
site=http://127.0.0.1:8080
db_dir=/var/lib/sdp-budget
profiles=${SDP_BUDGET_PROFILES:-500000}
export SDP_BUDGET_PROFILES="$profiles"

[[ $(cat "$cgroup/memory.max") == 1073741824 && $(cat "$cgroup/memory.swap.max") == 0 ]]
read -r quota period < "$cgroup/cpu.max"
[[ $quota != max && $quota == "$period" ]]
[[ -d "$reports" && ! -e "$db_dir" ]]
[[ $profiles == 10000 || $profiles == 100000 || $profiles == 500000 ]]

capture() {
    local phase=$1
    python3 - "$cgroup" "$phase" <<'PY'
import json
import sys
from pathlib import Path

root = Path(sys.argv[1])
events = dict(line.split() for line in (root / 'memory.events').read_text().splitlines())
stats = dict(line.split() for line in (root / 'memory.stat').read_text().splitlines())
print(json.dumps({
    'phase': sys.argv[2],
    'cpu_limit': (root / 'cpu.max').read_text().strip(),
    'bytes': {key: int((root / f'memory.{key}').read_text()) for key in ('max', 'current', 'peak', 'swap.current')},
    'components': {key: int(stats[key]) for key in ('anon', 'file', 'kernel', 'shmem')},
    'events': {key: int(value) for key, value in events.items()},
}, indent=2))
if int(events['oom']) or int(events['oom_kill']):
    sys.exit(1)
PY

}

cleanup() {
    local status=$?
    capture finish | tee "$reports/finish.json" || status=1
    find "$reports" -maxdepth 1 -type f -exec chmod a+r {} + || status=1
    exit "$status"
}
trap cleanup EXIT
capture start > "$reports/start.json"

export SDP_DB_PASSWORD SDP_WP_SECRET
SDP_DB_PASSWORD=$(openssl rand -hex 20)
SDP_WP_SECRET=$(openssl rand -hex 32)
wp() { runuser -u www-data --preserve-environment -- /usr/local/bin/wp --path=/var/www/html "$@"; }
sql() { mariadb --protocol=socket --socket=/run/mysqld/mysqld.sock --user=root "$@"; }

install -d -o mysql -g mysql -m 0750 "$db_dir"
install -d -o mysql -g mysql -m 0755 /run/mysqld
runuser -u mysql -- mariadb-install-db --datadir="$db_dir" --auth-root-authentication-method=socket --skip-test-db
mariadbd --defaults-file="$source_dir/deployment/vps/mariadb/micro-1gb.cnf" --user=mysql \
    --datadir="$db_dir" --socket=/run/mysqld/mysqld.sock --skip-networking > "$reports/mariadb.log" 2>&1 &
for attempt in {1..30}; do
    if sql --execute='SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 1
done
sql <<SQL
CREATE DATABASE sdp_development CHARACTER SET utf8mb4;
CREATE USER 'sdp_dev'@'localhost' IDENTIFIED BY '$SDP_DB_PASSWORD';
GRANT ALL PRIVILEGES ON sdp_development.* TO 'sdp_dev'@'localhost';
SQL

sed -i 's@db:3306@localhost:/run/mysqld/mysqld.sock@' /var/www/html/wp-config.php
openssl rand -hex 24 | wp core install --url="$site" --title='Sekolah Directory Indonesia — FIKTIF' \
    --admin_user=sdp-admin --admin_email=qa@example.invalid --skip-email --prompt=admin_password >/dev/null
wp option update blog_public 0 --quiet
wp plugin activate sekolah-directory-pro
wp theme activate sekolah-directory-theme
wp rewrite structure '/%postname%/' --quiet
wp post create --post_type=page --post_name=direktori-sekolah --post_title='Direktori FIKTIF' \
    --post_content='[portal_sekolah]' --post_status=publish --user=sdp-admin --quiet
if [[ $profiles == 10000 ]]; then
    wp eval-file "$source_dir/scripts/benchmark-fixtures.php" "$profiles" DISPOSABLE-LOCAL-ONLY --user=sdp-admin | tee "$reports/fixtures.json"
else
    wp eval-file "$source_dir/scripts/benchmark-fixtures.php" 1 DISPOSABLE-LOCAL-ONLY --user=sdp-admin >/dev/null
    wp eval-file "$source_dir/tests/vps-budget/seed-read-fixture.php" DISPOSABLE-LOCAL-ONLY --user=sdp-admin | tee "$reports/fixtures.json"
fi
wp eval 'global $wpdb; printf("WordPress %s, PHP %s, MariaDB %s\n", get_bloginfo("version"), PHP_VERSION, $wpdb->get_var("SELECT VERSION()"));'

install -d /run/php
sed '/^\[sdp\]/a clear_env = no' "$source_dir/deployment/vps/php-fpm/micro-1gb.conf" > /tmp/sdp-fpm-pool.conf
{
    printf '[global]\nerror_log = /reports/php-fpm.log\n'
    cat /tmp/sdp-fpm-pool.conf
} > /tmp/sdp-fpm.conf
cp "$source_dir/deployment/vps/php-fpm/micro-1gb-opcache.ini" /etc/php/8.3/fpm/conf.d/99-sdp-budget.ini
php-fpm8.3 --fpm-config /tmp/sdp-fpm.conf --nodaemonize &

cat > /tmp/sdp-nginx.conf <<'NGINX'
user www-data;
worker_processes 1;
pid /run/nginx-budget.pid;
error_log /reports/nginx.log;
events { worker_connections 64; }
http {
    access_log off;
    server {
        listen 127.0.0.1:8080;
        root /var/www/html;
        index index.php;
        location / { try_files $uri $uri/ /index.php?$args; }
        location ~ \.php$ {
            try_files $uri =404;
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_pass unix:/run/php/php8.3-fpm-sdp.sock;
        }
    }
}
NGINX
nginx -c /tmp/sdp-nginx.conf -g 'daemon off;' &
for attempt in {1..30}; do
    if curl -fsS "$site/" --output /dev/null 2>/dev/null; then break; fi
    sleep 1
done

directory="$site/direktori-sekolah/"
wp eval 'SDP_Performance::invalidate();' --quiet
curl -fsS --max-time 30 -o /tmp/sdp-directory.html -w '%{time_total} %{size_download}\n' "$directory" > "$reports/cold.tsv"
total=$(python3 -c 'import sys; print(f"{int(sys.argv[1]):,}")' "$profiles")
grep -Fq "$total sekolah ditemukan" /tmp/sdp-directory.html
curl -fsS "$directory?sdp_q=TEST0000" -o /tmp/sdp-search.html
grep -q '1 sekolah ditemukan' /tmp/sdp-search.html
grep -q 'Sekolah FIKTIF 000000' /tmp/sdp-search.html
province="$directory?sdp_provinsi=Provinsi%20Fiktif%2000"
partial="$directory?sdp_q=000200"
curl -fsS --max-time 30 "$province" -o /tmp/sdp-province.html
province_total=$(python3 -c 'import sys; print(f"{(int(sys.argv[1])-1)//38+1:,}")' "$profiles")
grep -Fq "$province_total sekolah ditemukan" /tmp/sdp-province.html
curl -fsS --max-time 30 "$partial" -o /tmp/sdp-partial.html
grep -q '1 sekolah ditemukan' /tmp/sdp-partial.html
grep -q 'Sekolah FIKTIF 000200' /tmp/sdp-partial.html
python3 "$source_dir/tools/benchmark_local.py" http "$directory" --requests 36 --workers 4 --local-test > "$reports/directory.json"
python3 "$source_dir/tools/benchmark_local.py" http "$directory?sdp_q=TEST0000" --requests 24 --workers 4 --local-test > "$reports/npsn.json"
python3 "$source_dir/tools/benchmark_local.py" http "$province" --requests 24 --workers 4 --local-test > "$reports/province.json"
python3 "$source_dir/tools/benchmark_local.py" http "$partial" --requests 24 --workers 4 --local-test > "$reports/partial.json"
python3 - "$reports" <<'PY'
from pathlib import Path
import json
import sys

reports = Path(sys.argv[1])
for name, count in (('directory', 36), ('npsn', 24), ('province', 24), ('partial', 24)):
    result = json.loads((reports / f'{name}.json').read_text())
    if result['ok'] != count or result['errors']:
        raise SystemExit(f'Permintaan gagal: {name}')
    print(f"{name}: {count} respons HTTP 200, p95 {result['p95_ms']} ms")
PY

if [[ $profiles == 500000 ]]; then
    curl -fsS --max-time 30 "$directory?sdp_q=Sekolah%20FIKTIF" -o /tmp/sdp-broad.html
    grep -Fq "$total sekolah ditemukan" /tmp/sdp-broad.html
    wp eval 'SDP_Performance::invalidate();' --quiet
    python3 "$source_dir/tests/vps-budget/sustained-read.py" | tee "$reports/sustained.json"
fi
