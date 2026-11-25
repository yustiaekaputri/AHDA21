#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
wp=${SDP_TEST_WP:-./scripts/wp.sh}
fixtures=${SDP_TEST_FIXTURES:-/opt/sdp/tests/integration}

"$wp" eval 'if (wp_get_environment_type() !== "local" || DB_NAME !== "sdp_development") WP_CLI::halt(1);'
"$wp" sdp import "$fixtures/import-fixture.csv" --authorized=1 --limit=1 --restart
"$wp" eval 'global $wpdb; $table = SDP_Directory::table(); $ids = $wpdb->get_col("SELECT npsn FROM `$table` WHERE npsn IN (\x27FIK90001\x27,\x27FIK90002\x27)"); if ($ids !== ["FIK90001"]) WP_CLI::error("Checkpoint batch pertama tidak valid.");'
"$wp" sdp import "$fixtures/import-fixture.csv" --authorized=1 --limit=1
"$wp" sdp import "$fixtures/import-fixture.csv" --authorized=1 --limit=1
"$wp" eval '
    global $wpdb;
    $table = SDP_Directory::table();
    $ids = $wpdb->get_col("SELECT post_id FROM `$table` WHERE npsn IN (\x27FIK90001\x27,\x27FIK90002\x27)");
    if (count($ids) !== 2 || count(array_unique($ids)) !== 2) WP_CLI::error("Dua profil dengan identitas unik diperlukan.");
    foreach ($ids as $id) {
        if (get_post_status($id) !== "draft") WP_CLI::error("Fixture impor harus tersimpan sebagai draf.");
        wp_delete_post((int) $id, true);
    }
    WP_CLI::success("Impor CSV menggunakan checkpoint, menghindari duplikasi, dan menyimpan draf.");
'
