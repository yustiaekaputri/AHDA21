<?php
/** Add synthetic profiles using WordPress and the normal plugin persistence path. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || (int) get_option('blog_public') !== 0 || !current_user_can('manage_options')
    || DB_NAME !== 'sdp_development' || ($args[1] ?? '') !== 'DISPOSABLE-LOCAL-ONLY') {
    exit(1);
}
$limit = filter_var($args[0] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500000]]);
if ($limit === false) {
    WP_CLI::error('Jumlah baris harus 1–500.000.');
}

global $wpdb;
$table = SDP_Directory::table();
$pattern = $wpdb->esc_like('TEST') . '%';
$already = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE npsn LIKE %s", $pattern));
$guard = get_option('sdp_seo_config', []);
update_option('sdp_seo_config', array_merge((array) $guard, ['guard' => 0]));
$start = microtime(true);
try {
    for ($index = $already; $index < $limit; $index++) {
        $code = strtoupper(str_pad(base_convert((string) $index, 10, 36), 4, '0', STR_PAD_LEFT));
        $result = SDP_Directory::save([
            'npsn' => 'TEST' . $code,
            'nama' => sprintf('Sekolah FIKTIF %06d', $index),
            'jenjang' => $index % 2 ? 'SD' : 'MI',
            'status' => 'Negeri',
            'provinsi' => sprintf('Provinsi Fiktif %02d', $index % 38),
            'kabupaten' => sprintf('Kabupaten Fiktif %03d', $index % 514),
            'kecamatan' => sprintf('Kecamatan Fiktif %04d', $index % 7200),
            'sumber' => 'Dataset FIKTIF benchmark lokal',
        ], true);
        if (is_wp_error($result) || get_post_status($result['id']) !== 'publish') {
            throw new RuntimeException(is_wp_error($result) ? $result->get_error_message() : 'Profil benchmark belum terbit.');
        }
        if (($index + 1) % 100 === 0) {
            wp_cache_flush_runtime();
        }
    }
} finally {
    update_option('sdp_seo_config', $guard);
}
WP_CLI::log(wp_json_encode([
    'fixtures' => max($already, $limit),
    'added' => max(0, $limit - $already),
    'seconds' => round(microtime(true) - $start, 3),
], JSON_PRETTY_PRINT));
