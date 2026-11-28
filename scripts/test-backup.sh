#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ "${COMPOSE_PROJECT_NAME:-}" != sdp-test-* ]]; then
    echo 'Uji pemulihan memerlukan proyek sekali pakai sdp-test-*.' >&2
    exit 1
fi
umask 077
dump=$(mktemp)
files=$(mktemp)
trap 'rm -f "$dump" "$files"' EXIT

content() {
    docker compose --file docker-compose.yml exec -T wordpress "$@"
}

scripts/wp.sh option update sdp_npsn_catalog '{"BKUP0001":{"npsn":"BKUP0001","nama":"Sekolah FIKTIF Cadangan","sumber":"Sumber FIKTIF — 2025/2026","tanggal_data":"2025-09-01"}}' --format=json >/dev/null
post_id=$(scripts/wp.sh eval '
    $result = SDP_Directory::save(get_option("sdp_npsn_catalog")["BKUP0001"], false);
    if (is_wp_error($result)) { WP_CLI::error($result->get_error_message()); }
    echo $result["id"];
')
[[ "$post_id" =~ ^[1-9][0-9]*$ ]]
[[ "$(scripts/wp.sh post get "$post_id" --field=post_status)" == draft ]]
attachment_id=$(scripts/wp.sh eval '
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    $upload = wp_upload_bits("sdp-backup-fixture.png", null, $png);
    if ($upload["error"]) { WP_CLI::error($upload["error"]); }
    $attachment = wp_insert_attachment([
        "post_title" => "Media FIKTIF Cadangan",
        "post_mime_type" => "image/png",
    ], $upload["file"], '"$post_id"', true);
    if (is_wp_error($attachment)) { WP_CLI::error($attachment->get_error_message()); }
    echo $attachment;
')
[[ "$attachment_id" =~ ^[1-9][0-9]*$ ]]
media_path=$(scripts/wp.sh post meta get "$attachment_id" _wp_attached_file)
[[ "$media_path" =~ ^([0-9]{4}/[0-9]{2}/)?sdp-backup-fixture(-[0-9]+)?\.png$ ]]
uploads=/var/www/html/wp-content/uploads
media_hash=$(content sha256sum "$uploads/$media_path")

docker compose --file docker-compose.yml exec -T db sh -ec 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump --user="$MARIADB_USER" --single-transaction --skip-lock-tables "$MARIADB_DATABASE"' > "$dump"
content tar -C "$uploads" -cf - "$media_path" > "$files"
scripts/wp.sh option delete sdp_npsn_catalog >/dev/null
scripts/wp.sh post delete "$attachment_id" --force >/dev/null
scripts/wp.sh post delete "$post_id" --force >/dev/null
content test ! -e "$uploads/$media_path"
docker compose --file docker-compose.yml exec -T db sh -ec 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb --user="$MARIADB_USER" "$MARIADB_DATABASE"' < "$dump"
content tar -C "$uploads" -xf - < "$files"
[[ "$(content sha256sum "$uploads/$media_path")" == "$media_hash" ]]
[[ "$(scripts/wp.sh post get "$attachment_id" --field=post_parent)" == "$post_id" ]]
[[ "$(scripts/wp.sh post meta get "$attachment_id" _wp_attached_file)" == "$media_path" ]]
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
verifier=/tmp/sdp-verify-migration.php
docker compose --file docker-compose.yml cp deployment/vps/tools/verify-migration.php "wordpress:$verifier"
site_url=${SDP_SITE_URL:-http://127.0.0.1:${SDP_HTTP_PORT:-3000}}
scripts/wp.sh eval-file "$verifier" "$site_url" 1 BKUP0001
if mismatch=$(scripts/wp.sh eval-file "$verifier" https://wrong.example 1 BKUP0001 2>&1); then
    echo 'Pemeriksaan migrasi menerima URL tujuan yang salah.' >&2
    exit 1
fi
[[ "$mismatch" == *"URL home pada database atau runtime berbeda dari URL tujuan."* ]]
echo 'PASS: pemeriksaan migrasi menolak URL tujuan yang salah.'
scripts/wp.sh option delete sdp_npsn_catalog >/dev/null
scripts/wp.sh post delete "$attachment_id" --force >/dev/null
scripts/wp.sh post delete "$post_id" --force >/dev/null
echo 'PASS: cadangan memulihkan katalog NPSN, draf, indeks, dan lampiran dengan checksum identik.'
