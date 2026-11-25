<?php
/** Run via wp eval-file on disposable, empty local WordPress only. Never install as a plugin. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI || PHP_SAPI !== 'cli') { exit(1); }
if (!isset($args) || $args !== ['DISPOSABLE-LOCAL-ONLY']) {
    WP_CLI::error('Explicit confirmation required: wp eval-file /path/sdp-run-localwp.php DISPOSABLE-LOCAL-ONLY --user=<admin>');
    exit(1);
}
foreach (['sdp-preflight-local.php','sdp-smoke-rc6.php'] as $file) {
    if (!is_file(__DIR__.'/'.$file) || !is_readable(__DIR__.'/'.$file)) {
        WP_CLI::error('Missing toolkit file: '.$file); exit(1);
    }
}
WP_CLI::log('SDP LOCALWP VALIDATION | PREFLIGHT');
// Preflight calls WP_CLI::error (nonzero exit) on any failed required check.
require __DIR__.'/sdp-preflight-local.php';
WP_CLI::log('SDP LOCALWP VALIDATION | SMOKE');
// Smoke independently verifies local environment, permissions and empty school database.
require __DIR__.'/sdp-smoke-rc6.php';
WP_CLI::log('SDP LOCALWP VALIDATION | COMPLETE');
WP_CLI::log('Review all output; discard test clone. This is not production approval.');
