<?php
/**
 * Verifikasi hasil migrasi melalui WP-CLI.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( count( $args ) !== 3 || ! ctype_digit( $args[1] ) || ! preg_match( '/^[A-Z0-9]{8}$/Di', $args[2] ) ) {
	WP_CLI::error( 'Penggunaan: wp eval-file verify-migration.php URL_TUJUAN JUMLAH_KATALOG NPSN_DRAF' );
}

list( $expected_url, $expected_count, $draft_npsn ) = $args;
$expected_url = untrailingslashit( $expected_url );
$url_parts    = wp_parse_url( $expected_url );
if ( empty( $url_parts['host'] ) || ! in_array( $url_parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
	WP_CLI::error( 'Masukkan URL tujuan lengkap.' );
}

global $wpdb;
foreach ( array( 'home' => home_url(), 'siteurl' => site_url() ) as $name => $effective_url ) {
	$stored_url = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	if ( untrailingslashit( (string) $stored_url ) !== $expected_url || untrailingslashit( $effective_url ) !== $expected_url ) {
		WP_CLI::error( "URL {$name} pada database atau runtime berbeda dari URL tujuan." );
	}
}

if ( ! class_exists( 'SDP_SEO' ) ) {
	WP_CLI::error( 'Sekolah Directory Pro belum aktif.' );
}

$catalog = get_option( 'sdp_npsn_catalog', array() );
if ( ! is_array( $catalog ) || count( $catalog ) !== (int) $expected_count ) {
	WP_CLI::error( 'Jumlah rekaman katalog berbeda dari instalasi asal.' );
}

$profile = SDP_SEO::by_npsn( $draft_npsn );
if ( get_post_status( (int) ( $profile['post_id'] ?? 0 ) ) !== 'draft' ) {
	WP_CLI::error( 'Profil sampel tidak ditemukan sebagai draf yang terhubung dengan indeks.' );
}

wp_set_current_user( 0 );
$response = rest_do_request( '/sdp/v1/schools/' . strtoupper( $draft_npsn ) );
if ( $response->get_status() !== 404 || ( $response->get_data()['code'] ?? '' ) === 'rest_no_route' ) {
	WP_CLI::error( 'Endpoint detail harus tersedia dan menyembunyikan draf dari pengunjung.' );
}

WP_CLI::success( 'URL tujuan, jumlah katalog, relasi draf, dan respons REST anonim sesuai.' );
