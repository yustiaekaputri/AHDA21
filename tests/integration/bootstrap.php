<?php

$path = getenv('SDP_WP_PATH') ?: '/var/www/html';
require rtrim($path, '/') . '/wp-load.php';
require_once WP_PLUGIN_DIR . "/sekolah-directory-pro/includes/class-sdp-production.php";
