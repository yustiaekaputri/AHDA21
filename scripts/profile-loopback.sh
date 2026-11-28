#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ "${1:-}" != DISPOSABLE-LOCAL-ONLY || "${COMPOSE_PROJECT_NAME:-}" != sdp-test-* ]]; then
    echo 'Gunakan proyek sdp-test-* dengan database kosong dan konfirmasi DISPOSABLE-LOCAL-ONLY.' >&2
    exit 1
fi
port=${SDP_HTTP_PORT:-3001}
mkdir -p artifacts/reports
scripts/wp.sh eval '
    if (wp_get_environment_type() !== "local" || DB_NAME !== "sdp_development") WP_CLI::halt(1);
    $page = get_page_by_path("direktori-sekolah");
    if (!$page) {
        $id = wp_insert_post(["post_type" => "page", "post_name" => "direktori-sekolah", "post_title" => "Direktori FIKTIF", "post_content" => "[portal_sekolah]", "post_status" => "publish"], true);
        if (is_wp_error($id)) WP_CLI::error($id->get_error_message());
        update_option("sdp_page_id", $id);
    }
' --user=sdp-admin
read -r -a counts <<< "${SDP_BENCHMARK_COUNTS:-100 1000 10000}"
for count in "${counts[@]}"; do
    scripts/wp.sh eval-file /opt/sdp/scripts/benchmark-fixtures.php "$count" DISPOSABLE-LOCAL-ONLY --user=sdp-admin \
        > "artifacts/reports/fixture-$count.json"
    scripts/wp.sh eval 'echo wp_json_encode(SDP_Benchmark::run(["sdp_provinsi"=>"Provinsi Fiktif 00"],3,15,true),JSON_PRETTY_PRINT);' \
        > "artifacts/reports/profile-$count.json"
    python3 tools/benchmark_local.py http "http://127.0.0.1:$port/direktori-sekolah/" --requests 10 --workers 1 --local-test \
        > "artifacts/reports/http-$count.json"
done
