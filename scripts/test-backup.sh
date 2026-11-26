#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ "${COMPOSE_PROJECT_NAME:-}" != sdp-test-* ]]; then
    echo 'Uji pemulihan memerlukan proyek sekali pakai sdp-test-*.' >&2
    exit 1
fi
umask 077
dump=$(mktemp)
trap 'rm -f "$dump"' EXIT

scripts/wp.sh option update sdp_npsn_catalog '{"BKUP0001":{"npsn":"BKUP0001","nama":"Sekolah FIKTIF Cadangan","sumber":"Sumber FIKTIF — 2025/2026","tanggal_data":"2025-09-01"}}' --format=json >/dev/null
post_id=$(scripts/wp.sh eval '
    $result = SDP_Directory::save(get_option("sdp_npsn_catalog")["BKUP0001"], false);
    if (is_wp_error($result)) { WP_CLI::error($result->get_error_message()); }
    echo $result["id"];
')
[[ "$post_id" =~ ^[1-9][0-9]*$ ]]
[[ "$(scripts/wp.sh post get "$post_id" --field=post_status)" == draft ]]
docker compose --file docker-compose.yml exec -T db sh -ec 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump --user="$MARIADB_USER" --single-transaction --skip-lock-tables "$MARIADB_DATABASE"' > "$dump"
scripts/wp.sh option delete sdp_npsn_catalog >/dev/null
scripts/wp.sh post delete "$post_id" --force >/dev/null
docker compose --file docker-compose.yml exec -T db sh -ec 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb --user="$MARIADB_USER" "$MARIADB_DATABASE"' < "$dump"
scripts/wp.sh eval '
    global $wpdb;
    $record = get_option("sdp_npsn_catalog")["BKUP0001"] ?? [];
    $table = SDP_Directory::table();
    $row = $wpdb->get_row($wpdb->prepare("SELECT post_id, nama FROM `$table` WHERE npsn=%s", "BKUP0001"), ARRAY_A);
    if (($record["sumber"] ?? "") !== "Sumber FIKTIF — 2025/2026" ||
        ($record["tanggal_data"] ?? "") !== "2025-09-01" ||
        ($row["nama"] ?? "") !== ($record["nama"] ?? null) ||
        get_post_status((int) ($row["post_id"] ?? 0)) !== "draft") {
        WP_CLI::error("Katalog, indeks, atau status draf gagal dipulihkan.");
    }
'
scripts/wp.sh option delete sdp_npsn_catalog >/dev/null
scripts/wp.sh post delete "$post_id" --force >/dev/null
echo 'PASS: snapshot MariaDB memulihkan katalog NPSN, sumber data, draf, dan indeks sekolah.'
