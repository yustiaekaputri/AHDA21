<?php
/** Configuration consumed only by the local Compose image. */
define('DB_NAME', 'sdp_development');
define('DB_USER', 'sdp_dev');
define('DB_PASSWORD', getenv('SDP_DB_PASSWORD'));
define('DB_HOST', 'db:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$secret = getenv('SDP_WP_SECRET');
if (!is_string($secret) || strlen($secret) < 32 || !DB_PASSWORD) {
    throw new RuntimeException('Jalankan scripts/setup.sh untuk menyiapkan konfigurasi WordPress lokal.');
}
foreach (['AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE'] as $name) {
    define($name . '_KEY', hash_hmac('sha256', $name . '_KEY', $secret));
    define($name . '_SALT', hash_hmac('sha256', $name . '_SALT', $secret));
}
unset($name, $secret);

$table_prefix = 'wp_';
define('WP_ENVIRONMENT_TYPE', 'local');
define('SDP_LOW_RESOURCE', true);
define('SDP_SAFE_MODE', true);
define('SDP_SMOKE_TEST_ENABLED', true);
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
