#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

wp=${SDP_TEST_WP:-./scripts/wp.sh}
"$wp" eval 'if (wp_get_environment_type() !== "local" || DB_NAME !== "sdp_development" || get_option("blog_public")) WP_CLI::halt(1);'
if [[ $wp == ./scripts/wp.sh && "${COMPOSE_PROJECT_NAME:-}" != sdp-test-* ]]; then
    echo 'Uji pilot memerlukan proyek sekali pakai sdp-test-*.' >&2
    exit 1
fi

work=$(mktemp -d)
post_id=
remote_csv=
cleanup() {
    if [[ $post_id =~ ^[1-9][0-9]*$ ]]; then "$wp" post delete "$post_id" --force >/dev/null; fi
    if [[ -n $remote_csv ]]; then docker compose exec -T wordpress rm -f "$remote_csv"; fi
    rm -rf "$work"
}
trap cleanup EXIT

cat > "$work/source.csv" <<'CSV'
npsn,nama,bentuk,status,provinsi,kabupaten,kecamatan
0ZZP0001,Sekolah Uji AHDA21 Satu,SD,NEGERI,Provinsi Uji,Kabupaten Uji,Kecamatan Uji
0ZZP0002,Sekolah Uji AHDA21 Dua,SD,NEGERI,Provinsi Uji,Kabupaten Uji,Kecamatan Uji
CSV
cat > "$work/config.json" <<'JSON'
{
    "provinsi": "Provinsi Uji",
    "kabupaten": "Kabupaten Uji",
    "identity": {"file": "source.csv", "source": "https://example.invalid/data-uji", "edition": "2025/2026", "rights": "FIKTIF untuk pengujian lokal"}
}
JSON
python3 tools/prepare_pilot.py "$work/config.json" "$work/pilot" --limit 2 >/dev/null
hash=$(python3 -c 'import json, sys; print(json.load(open(sys.argv[1]))["sources"]["identity"]["sha256_pilot"])' "$work/pilot/manifest.json")
csv="$work/pilot/identity.csv"
if [[ $wp == ./scripts/wp.sh ]]; then
    remote_csv="/tmp/sdp-pilot-preview-$$.csv"
    chmod 644 "$csv"
    docker compose cp "$csv" "wordpress:$remote_csv"
    csv="$remote_csv"
fi

post_id=$("$wp" eval '
    $result = SDP_Directory::save(["npsn" => "0ZZP0002", "nama" => "Sekolah FIKTIF Tersimpan"], false, "fill", null, null, null, true);
    if (is_wp_error($result)) WP_CLI::error($result->get_error_message());
    echo $result["id"];
')
preview() { "$wp" sdp pilot-preview "$csv" --kind=identity --sha256="$1" --user=sdp-admin; }
preview "$hash" > "$work/preview.json"
python3 - "$work/preview.json" <<'PY'
import json
import sys

with open(sys.argv[1]) as report:
    result = json.load(report)
expected = {"0ZZP0001": "new_identity", "0ZZP0002": "conflict_review"}
assert result["status"] == "preview_only", result
assert {row["npsn"]: row["status"] for row in result["rows"]} == expected, result
PY
if preview "$(printf '%064d' 0)" > "$work/bad-hash.log" 2>&1; then
    echo 'Checksum yang salah harus ditolak.' >&2
    exit 1
fi
grep -Fq "Jenis, ukuran, encoding atau checksum salah" "$work/bad-hash.log"
"$wp" eval '
    global $wpdb;
    $table = SDP_Directory::table();
    $rows = $wpdb->get_results("SELECT npsn, nama, post_id FROM `$table` WHERE npsn IN (\x270ZZP0001\x27,\x270ZZP0002\x27)", ARRAY_A);
    if (count($rows) !== 1 || $rows[0]["npsn"] !== "0ZZP0002" ||
        $rows[0]["nama"] !== "Sekolah FIKTIF Tersimpan" || get_post_status((int) $rows[0]["post_id"]) !== "draft") {
        WP_CLI::error("Pratinjau mengubah indeks atau draf.");
    }
'
echo 'PASS: hasil validator Python terbaca WP-CLI, profil baru dan konflik terdeteksi, checksum salah ditolak, dan data tetap utuh.'
