<?php
/** WP-CLI read-only preflight. Run only on a disposable LocalWP clone. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI || PHP_SAPI !== 'cli') { exit(1); }
function sdp_preflight_local_host($url) {
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    return in_array($host, ['localhost','127.0.0.1','::1','[::1]'], true) || str_ends_with($host,'.local') || str_ends_with($host,'.test');
}
(function () {
    global $wpdb;
    $checks = []; $warnings = [];
    $add = static function($key, $ok) use (&$checks) { $checks[$key] = (bool)$ok; };
    $add('php_8_or_newer', version_compare(PHP_VERSION,'8.0','>='));
    $add('wordpress_6_4_or_newer', version_compare(get_bloginfo('version'),'6.4','>='));
    $add('mysqli_loaded', extension_loaded('mysqli'));
    $add('local_environment', wp_get_environment_type() === 'local');
    $add('local_home_and_site_url', sdp_preflight_local_host(home_url('/')) && sdp_preflight_local_host(site_url('/')));
    $add('single_site', !is_multisite());
    $add('non_indexed', (string)get_option('blog_public') === '0');
    $add('administrator', current_user_can('manage_options'));
    $add('safe_mode_enabled', defined('SDP_SAFE_MODE') && SDP_SAFE_MODE === true);
    $add('smoke_test_enabled', defined('SDP_SMOKE_TEST_ENABLED') && SDP_SMOKE_TEST_ENABLED === true);
    $add('benchmark_disabled', !defined('SDP_BENCHMARK_ENABLED') || SDP_BENCHMARK_ENABLED !== true);
    $add('plugin_rc4', class_exists('SDP_Directory') && SDP_Directory::VERSION === '10.0.0-rc.4');
    $add('theme_v7', wp_get_theme()->get_stylesheet() === 'sekolah-directory-theme' && wp_get_theme()->get('Version') === '7.0.0');
    $add('production_module', class_exists('SDP_Production'));
    $add('low_resource_mode', class_exists('SDP_Resource') && SDP_Resource::enabled());
    // Avoid plugin-table queries on a nonlocal, unauthorized, or incorrectly configured site.
    if (!in_array(false,$checks,true)) {
        $schema = SDP_Production::schema();
        $add('required_schema_ready', is_array($schema) && count($schema) >= 8 && !in_array('error',array_column($schema,'status'),true));
        if ($checks['required_schema_ready']) {
            $table = SDP_Directory::table();
            $row = $wpdb->get_var("SELECT npsn FROM `$table` LIMIT 1");
            $add('school_index_empty', !$wpdb->last_error && $row === null);
            $row = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s LIMIT 1",SDP_Directory::TYPE));
            $add('school_posts_empty', !$wpdb->last_error && $row === null);
        }
    }
    foreach (['curl','mbstring','gd','zip','xmlreader','dom'] as $ext) if (!extension_loaded($ext)) $warnings[] = 'optional_extension_missing:'.$ext;
    $ok = !in_array(false,$checks,true) && count($checks) === 18;
    WP_CLI::log(wp_json_encode(['tool'=>'SDP local preflight','status'=>$ok?'pass':'blocked','checks'=>$checks,'warnings'=>$warnings,'note'=>'Read-only checks; not smoke test, security audit, or production approval. Verify clone is disposable and no tunnel is active.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    if (!$ok) WP_CLI::error('Preflight blocked. Fix false checks on the disposable local clone.');
    WP_CLI::success('Preflight passed. Continue only on this disposable clone.');
})();
