<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** On-demand administrator diagnostics; no network, telemetry, full scan or automatic DDL. */
final class SDP_Production {
	const PAGE = 'sdp-production';
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 90 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'sdp readiness', array( __CLASS__, 'cli' ), array( 'synopsis' => '[--audit] [--after=<npsn>]' ) );
		}}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Kesiapan Produksi', 'Kesiapan Produksi', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );}
	public static function safe() {
		return defined( 'SDP_SAFE_MODE' ) && SDP_SAFE_MODE === true;}
	public static function limits() {
		return SDP_Resource::cap( 100, 25 );}
	public static function cursor( $v ) {
		return is_string( $v ) && preg_match( '/^(?:[A-Z0-9]{8})?$/D', $v ) === 1;}
	public static function tables() {
		return array(
			'Sekolah'  => array( SDP_Directory::table(), true, array_merge( array( 'npsn', 'post_id', 'payload', 'updated_at' ), SDP_Directory::indexed() ) ),
			'Antrean'  => array( SDP_Collector::table(), true, array( 'id', 'npsn', 'status', 'payload' ) ),
			'Riwayat'  => array( SDP_Analytics::history_table(), true, array( 'id', 'npsn', 'payload' ) ),
			'Koreksi'  => array( SDP_Analytics::reports_table(), true, array( 'id', 'npsn', 'status' ) ),
			'Klaim'    => array( SDP_National::table(), true, array( 'npsn', 'user_id', 'status' ) ),
			'Audit'    => array( SDP_National::audit_table(), true, array( 'id', 'event', 'created_at' ) ),
			'Operator' => array( SDP_Operator::table(), true, array( 'id', 'npsn', 'user_id', 'status', 'candidate' ) ),
			'Wilayah'  => array( SDP_Region::table(), false, array( 'code', 'parent', 'name', 'system', 'edition' ) ),
		);}
	public static function schema() {
		global $wpdb;
		$out = array();foreach ( self::tables() as $label => [$t, $required, $fields] ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
			if ( $wpdb->last_error ) {
				$out[ $label ] = array(
					'status' => 'error',
					'note'   => 'Pemeriksaan tabel gagal.',
				);
				continue;}
			if ( $exists !== $t ) {
				$out[ $label ] = array(
					'status' => $required ? 'error' : 'warning',
					'note'   => $required ? 'Tabel belum ada.' : 'Master wilayah opsional belum disiapkan.',
				);
				continue;}
			$cols          = $wpdb->get_col( "SHOW COLUMNS FROM `$t`" );
			$missing       = array_diff( $fields, (array) $cols );
			$out[ $label ] = array(
				'status' => $wpdb->last_error || $missing ? 'error' : 'ok',
				'note'   => $wpdb->last_error ? 'Pemeriksaan kolom gagal.' : ( $missing ? 'Kolom belum lengkap: ' . implode( ', ', $missing ) : 'Kolom dasar tersedia.' ),
			);
		}return $out;}
	public static function readiness() {
		global $wpdb;
		$ext = array();
		foreach ( array( 'mysqli', 'json', 'openssl', 'curl', 'mbstring', 'fileinfo', 'gd', 'zip', 'xmlreader', 'dom' ) as $e ) {
			$ext[ $e ] = extension_loaded( $e );
		}
		$r = array(
			'PHP'                  => array(
				'status' => version_compare( PHP_VERSION, '8.0', '>=' ) ? 'ok' : 'error',
				'note'   => PHP_VERSION,
			),
			'WordPress'            => array(
				'status' => version_compare( get_bloginfo( 'version' ), '6.4', '>=' ) ? 'ok' : 'error',
				'note'   => get_bloginfo( 'version' ),
			),
			'Database'             => array(
				'status' => 'info',
				'note'   => (string) $wpdb->db_version() . ' (versi saja, bukan uji kompatibilitas)',
			),
			'HTTPS'                => array(
				'status' => str_starts_with( home_url( '/' ), 'https://' ) ? 'ok' : 'warning',
				'note'   => 'Periksa HTTPS publik dan reverse proxy.',
			),
			'Indeks mesin pencari' => array(
				'status' => get_option( 'blog_public' ) ? 'ok' : 'warning',
				'note'   => get_option( 'blog_public' ) ? 'WordPress tidak memblokir secara global.' : 'WordPress meminta mesin pencari tidak mengindeks.',
			),
			'Debug'                => array(
				'status' => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'warning' : 'ok',
				'note'   => 'WP_DEBUG sebaiknya false di produksi.',
			),
			'Benchmark'            => array(
				'status' => defined( 'SDP_BENCHMARK_ENABLED' ) && SDP_BENCHMARK_ENABLED ? 'warning' : 'ok',
				'note'   => 'Jangan aktifkan benchmark di produksi.',
			),
			'Mode darurat'         => array(
				'status' => self::safe() ? 'warning' : 'info',
				'note'   => self::safe() ? 'Aktif; otomatisasi terjadwal berhenti.' : 'Nonaktif.',
			),
			'Mode sumber daya'     => array(
				'status' => 'info',
				'note'   => SDP_Resource::enabled() ? 'Hemat' : 'Standar',
			),
			'Schema marker inti'   => array(
				'status' => 'info',
				'note'   => (string) get_option( 'sdp_db_version', 'belum tercatat' ) . ' (tidak membuktikan kesehatan tabel)',
			),
		);
		foreach ( $ext as $e => $on ) {
			$r[ 'Ekstensi ' . $e ] = array(
				'status' => $on ? 'ok' : ( in_array( $e, array( 'mysqli', 'json' ), true ) ? 'error' : 'warning' ),
				'note'   => $on ? 'Tersedia' : 'Tidak tersedia; fitur terkait mungkin tidak dapat digunakan.',
			);
		}return $r + self::schema();}
	public static function inspect( $row ) {
		$issues = array();
		$d      = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( ! is_array( $d ) ) {
			return array( 'payload_invalid_or_oversized' );
		}
		foreach ( array( 'npsn', 'nama', 'sumber' ) as $k ) {
			if ( isset( $d[ $k ] ) && ! is_scalar( $d[ $k ] ) ) {
				return array( 'payload_invalid' );
			}
		}
		if ( ! self::cursor( (string) ( $row['npsn'] ?? '' ) ) || empty( $row['npsn'] ) || ( $d['npsn'] ?? null ) !== $row['npsn'] ) {
			$issues[] = 'npsn_mismatch';
		}
		foreach ( SDP_Directory::indexed() as $k ) {
			if ( ! is_scalar( $d[ $k ] ?? '' ) || (string) ( $d[ $k ] ?? '' ) !== (string) ( $row[ $k ] ?? '' ) ) {
				$issues[] = 'index_mismatch';
				break;}
		}
		foreach ( array( 'nama', 'jenjang', 'status', 'provinsi', 'kabupaten', 'kecamatan' ) as $k ) {
			if ( ! is_scalar( $d[ $k ] ?? null ) || trim( (string) $d[ $k ] ) === '' ) {
				$issues[] = 'identity_incomplete';
				break;}
		}
		if ( ( $row['post_type'] ?? '' ) !== SDP_Directory::TYPE ) {
			$issues[] = 'post_missing_or_wrong_type';
		}
		if ( ( $row['post_status'] ?? '' ) === 'publish' && empty( $row['protected'] ) ) {
			if ( SDP_SEO::demo( $d ) ) {
				$issues[] = 'demo_public';
			}if ( ! SDP_SEO::ready( $d ) ) {
				$issues[] = 'seo_not_ready';
			}
		}
		return $issues;}
	public static function audit( $after = '' ) {
		if ( ! self::cursor( $after ) ) {
			return new WP_Error( 'cursor', 'Cursor NPSN tidak valid.' );
		}global $wpdb;
		$t    = SDP_Directory::table();
		$n    = self::limits();
		$cols = implode( ',', array_map( fn( $k )=>'s.' . $k, SDP_Directory::indexed() ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.npsn,$cols,CASE WHEN OCTET_LENGTH(s.payload)<=262144 THEN s.payload ELSE NULL END AS payload,p.post_type,p.post_status,CASE WHEN p.post_password<>'' THEN 1 ELSE 0 END AS protected FROM $t s LEFT JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE s.npsn>%s ORDER BY s.npsn LIMIT %d", $after, $n + 1 ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'database', 'Audit gagal. Periksa schema dan izin database.' );
		}$more = count( $rows ) > $n;
		$rows  = array_slice( $rows, 0, $n );
		$out   = array(
			'checked' => count( $rows ),
			'issues'  => array(),
			'next'    => null,
			'scope'   => 'sampel berurutan, bukan audit seluruh database',
		);
		foreach ( $rows as $row ) {
			$i = self::inspect( $row );
			if ( $i ) {
				$out['issues'][] = array(
					'npsn'  => $row['npsn'],
					'flags' => $i,
				);
			}
		}if ( $more ) {
			$out['next'] = $rows[ count( $rows ) - 1 ]['npsn'];
		}return $out;}
	public static function cli( $args, $assoc ) {
		$r = isset( $assoc['audit'] ) ? self::audit( $assoc['after'] ?? '' ) : self::readiness();
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}WP_CLI::log( wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}$result = null;
		$msg     = '';
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
			check_admin_referer( 'sdp90_readiness' );
			$op = $_POST['op'] ?? '';
			if ( 'audit' === $op ) {
				$result = self::audit( is_string( $_POST['after'] ?? null ) ? wp_unslash( $_POST['after'] ) : '' );
			} elseif ( 'repair' === $op && ( $_POST['backup'] ?? '' ) === '1' && self::safe() ) {
				$r   = SDP_Collector::lock( fn()=>SDP_Directory::install_schema() );
				$msg = true === $r ? 'Schema inti diperiksa dan marker diperbarui.' : 'Pemeriksaan/perbaikan gagal atau kolektor sedang sibuk.';
			} else {
				$msg = 'Tindakan tidak valid, mode darurat belum aktif, atau konfirmasi cadangan belum diisi.';
			}
		}
		echo '<div class="wrap"><h1>Kesiapan Produksi — SDP 10.0 RC1</h1><p>Pemeriksaan terbatas, bukan sertifikasi keamanan atau kapasitas. Tidak ada pekerjaan baru saat halaman publik dibuka.</p>';
		if ( $msg ) {
			echo '<p>' . esc_html( $msg ) . '</p>';
		}echo '<table class="widefat striped"><tbody>';
		foreach ( self::readiness() as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v['status'] ) . '</td><td>' . esc_html( $v['note'] ) . '</td></tr>';
		}echo '</tbody></table><h2>Audit kualitas data bertahap</h2><form method="post">';
		wp_nonce_field( 'sdp90_readiness' );
		echo '<label>Setelah NPSN (kosong = awal) <input name="after" maxlength="8" value="' . esc_attr( is_array( $result ) ? ( $result['next'] ?? '' ) : '' ) . '"></label> <button class="button" name="op" value="audit">Audit maks. ' . (int) self::limits() . ' profil</button></form>';
		if ( is_wp_error( $result ) ) {
			echo '<p>' . esc_html( $result->get_error_message() ) . '</p>';
		} elseif ( is_array( $result ) ) {
			echo '<pre>' . esc_html( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		}echo '<h2>Perbaikan schema inti</h2><p>Aktifkan SDP_SAFE_MODE terlebih dahulu. Hanya pada staging atau jendela pemeliharaan setelah backup. dbDelta dapat mengunci tabel; tidak memperbaiki payload atau schema modul lain.</p><form method="post">';
		wp_nonce_field( 'sdp90_readiness' );
		echo '<label><input type="checkbox" name="backup" value="1"> Backup telah diverifikasi dan pekerjaan dihentikan</label> <button class="button" name="op" value="repair">Periksa / perbaiki tabel sekolah</button></form><h2>Mode darurat</h2><p>Tambahkan <code>define(\'SDP_SAFE_MODE\', true);</code> pada wp-config.php untuk menghentikan otomatisasi terjadwal SDP. Tindakan manual dan plugin lain tidak diblokir. Setelah pulih, hapus konstanta dan periksa jadwal kolektor melalui Performa &amp; Cache.</p></div>';}
}
