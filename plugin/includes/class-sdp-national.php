<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** National-scale tools. Explicit CLI batches; public read-only cursor API; moderated claims. */
final class SDP_National {
	const SCHEMA        = '1';
	const PUBLIC_FIELDS = array( 'npsn', 'nama', 'jenjang', 'status', 'akreditasi', 'provinsi', 'kabupaten', 'kecamatan', 'jumlah_siswa', 'jumlah_guru', 'ruang_kelas', 'luas_tanah', 'tahun_data', 'tahun_akreditasi' );
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sdp_claims';}
	public static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . 'sdp_audit';}
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_sdp50_claim', array( __CLASS__, 'submit_claim' ) );
		add_action( 'admin_post_sdp50_review', array( __CLASS__, 'review' ) );
		add_action( 'admin_post_sdp50_maintenance', array( __CLASS__, 'maintenance' ) );
		add_filter( 'the_content', array( __CLASS__, 'claim_form' ), 30 );
		add_action( 'sdp_school_saved', array( __CLASS__, 'invalidate' ), 10, 0 );
		add_action( 'deleted_post', array( __CLASS__, 'delete' ), 4, 2 );
		add_action( 'deleted_user', array( __CLASS__, 'deleted_user' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'sdp import', array( __CLASS__, 'cli_import' ), array( 'synopsis' => '<file> --authorized=<confirmation> [--limit=<rows>] [--source=<label>] [--policy=<policy>] [--kind=<kind>] [--delimiter=<delimiter>] [--provinsi=<name>] [--kabupaten=<name>] [--kecamatan=<name>] [--jenjang=<level>] [--publish] [--restart]' ) );
			WP_CLI::add_command( 'sdp queue', array( __CLASS__, 'cli_queue' ), array( 'synopsis' => '[--rounds=<number>]' ) );
			WP_CLI::add_command( 'sdp health', array( __CLASS__, 'cli_health' ) );
			WP_CLI::add_command( 'sdp indexes', array( __CLASS__, 'cli_indexes' ), array( 'synopsis' => '--yes' ) );}
	}
	public static function install() {
		if ( get_option( 'sdp50_schema' ) === self::SCHEMA ) {
			return true;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = self::table();
		$a = self::audit_table();
		dbDelta(
			"CREATE TABLE $t (
            npsn varchar(8) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            evidence varchar(1000) NOT NULL,
            role_note varchar(191) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (npsn),
            KEY status_date (status,updated_at),
            KEY user_id (user_id)
        ) $c;"
		);
		dbDelta(
			"CREATE TABLE $a (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor bigint(20) unsigned NOT NULL DEFAULT 0,
            event varchar(40) NOT NULL,
            subject varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $c;"
		);
		foreach ( array( $t, $a ) as $name ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
				return false;
			}
		}
		update_option( 'sdp50_schema', self::SCHEMA, false );
		return true;
	}
	public static function invalidate() {
		delete_transient( 'sdp50_health' );}
	public static function log( $event, $subject = '' ) {
		global $wpdb;
		if ( get_option( 'sdp50_schema' ) !== self::SCHEMA ) {
			return;
		}
		$wpdb->insert(
			self::audit_table(),
			array(
				'actor'      => get_current_user_id(),
				'event'      => substr( $event, 0, 40 ),
				'subject'    => substr( $subject, 0, 191 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}
	public static function npsn( $v ) {
		return is_string( $v ) && preg_match( '/^[A-Z0-9]{8}$/D', strtoupper( $v ) ) ? strtoupper( $v ) : '';}
	public static function limit( $v, $default = 50, $max = 100 ) {
		return is_scalar( $v ) && preg_match( '/^\d{1,9}$/D', (string) $v ) ? max( 1, min( $max, (int) $v ) ) : $default;}
	public static function routes() {
		register_rest_route(
			'sdp/v1',
			'/schools',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'api_list' ),
			)
		);
		register_rest_route(
			'sdp/v1',
			'/schools/(?P<npsn>[A-Za-z0-9]{8})',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'api_detail' ),
			)
		);
	}
	public static function public_sql( $raw ) {
		global $wpdb;
		$where = array( "p.post_status='publish'", "p.post_password=''", 'p.post_type=%s' );
		$args  = array( SDP_Directory::TYPE );
		foreach ( SDP_Portal::FILTERS as $k => $label ) {
			$v = SDP_Portal::clean( $raw[ $k ] ?? '', 120 );
			if ( '' !== $v ) {
				$where[] = "s.$k=%s";
				$args[]  = $v;}
		}
		$q = SDP_Portal::clean( $raw['q'] ?? '', 120 );
		if ( '' !== $q ) {
			$where[] = '(s.nama LIKE %s OR s.npsn LIKE %s)';
			$args[]  = $wpdb->esc_like( $q ) . '%';
			$args[]  = $wpdb->esc_like( $q ) . '%';}
		$cursor = $raw['after'] ?? '';
		if ( '' !== $cursor ) {
			if ( ! self::npsn( $cursor ) ) {
				return new WP_Error( 'sdp_cursor', 'Cursor NPSN tidak valid.', array( 'status' => 400 ) );
			}$where[] = 's.npsn>%s';
			$args[]   = self::npsn( $cursor );}
		return array( ' FROM ' . SDP_Directory::table() . " s JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE " . implode( ' AND ', $where ), $args );
	}
	public static function public_item( $row ) {
		$d = json_decode( $row['payload'] ?? '', true );
		if ( ! is_array( $d ) ) {
			$d = array();
		}$o = array();
		foreach ( self::PUBLIC_FIELDS as $k ) {
			$v       = $row[ $k ] ?? $d[ $k ] ?? null;
			$o[ $k ] = is_scalar( $v ) && '' !== $v ? (string) $v : null;
		}$o['field_periods'] = array();
		foreach ( self::PUBLIC_FIELDS as $k ) {
			$p = SDP_Intelligence::field_period( $d, $k );
			if ( '' !== $p ) {
				$o['field_periods'][ $k ] = $p;
			}
		}$o['url']           = get_permalink( (int) $row['post_id'] );
		$o['updated_at_utc'] = $row['updated_at'] ?? null;
		return $o;}
	public static function api_list( $r ) {
		global $wpdb;
		$raw   = $r->get_params();
		$parts = self::public_sql( $raw );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}[$sql, $args] = $parts;
		$limit         = SDP_Resource::cap( self::limit( $raw['limit'] ?? 50 ), 20 );
		$rows          = $wpdb->get_results( $wpdb->prepare( 'SELECT s.*' . $sql . ' ORDER BY s.npsn LIMIT %d', array_merge( $args, array( $limit + 1 ) ) ), ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'sdp_db', 'Pencarian tidak tersedia.', array( 'status' => 503 ) );
		}$more = count( $rows ) > $limit;
		$rows  = array_slice( $rows, 0, $limit );
		return rest_ensure_response(
			array(
				'items'      => array_map( array( __CLASS__, 'public_item' ), $rows ),
				'next_after' => $more ? end( $rows )['npsn'] : null,
			)
		);}
	public static function api_detail( $r ) {
		global $wpdb;
		$route        = $r->get_url_params();
		[$sql, $args] = self::public_sql( array() );
		$npsn         = self::npsn( $route['npsn'] ?? '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bind the route NPSN to the shared public predicates and check visibility on each request.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT s.*' . $sql . ' AND s.npsn=%s LIMIT 1', array_merge( $args, array( $npsn ) ) ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'sdp_db', 'Pencarian tidak tersedia.', array( 'status' => 503 ) );
		}return $row ? rest_ensure_response( self::public_item( $row ) ) : new WP_Error( 'sdp_not_found', 'Profil publik tidak ditemukan.', array( 'status' => 404 ) );}
	public static function indexes() {
		return array(
			'sdp_region_level' => array( 'provinsi(60),kabupaten(60),kecamatan(60),jenjang,npsn' ),
			'sdp_level_region' => array( 'jenjang,provinsi(60),kabupaten(60),npsn' ),
			'sdp_recent'       => array( 'updated_at,npsn' ),
		);}
	public static function build_indexes() {
		global $wpdb;
		$t     = SDP_Directory::table();
		$found = $wpdb->get_results( "SHOW INDEX FROM $t", ARRAY_A );
		if ( ! is_array( $found ) || ! $found ) {
			return new WP_Error( 'db', 'Tabel sekolah tidak tersedia.' );
		}$have = array_column( $found, 'Key_name' );
		$added = array();
		foreach ( self::indexes() as $name => $spec ) {
			if ( in_array( $name, $have, true ) ) {
				continue;
			}if ( $wpdb->query( "ALTER TABLE $t ADD KEY $name (" . $spec[0] . ')' ) === false ) {
				return new WP_Error( 'db', 'Gagal membuat indeks ' . $name . '. Indeks sebelumnya yang berhasil tetap tersedia.' );
			}$added[] = $name;
		}self::log( 'indexes', implode( ',', $added ) );
		return $added;}
	public static function health( $refresh = false ) {
		global $wpdb;
		if ( ! $refresh && is_array( $v = get_transient( 'sdp50_health' ) ) ) {
			return $v;
		}$t  = SDP_Directory::table();
		$q   = SDP_Collector::table();
		$now = gmdate( 'Y-m-d H:i:s', time() - 365 * 86400 );
		$v   = array(
			'total'         => $wpdb->get_var( "SELECT COUNT(*) FROM $t" ),
			'published'     => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t s JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE p.post_type=%s AND p.post_status='publish' AND p.post_password=''", SDP_Directory::TYPE ) ),
			'stale'         => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE updated_at<%s", $now ) ),
			'orphaned'      => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t s LEFT JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE p.ID IS NULL OR p.post_type<>%s", SDP_Directory::TYPE ) ),
			'queue_pending' => $wpdb->get_var( "SELECT COUNT(*) FROM $q WHERE status='pending'" ),
			'queue_failed'  => $wpdb->get_var( "SELECT COUNT(*) FROM $q WHERE status='failed'" ),
			'seo_ready'     => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT s.npsn) FROM $t s JOIN {$wpdb->postmeta} m ON m.post_id=s.post_id WHERE m.meta_key=%s AND m.meta_value=%s", '_sdp_seo_ready', '1' ) ),
			'cron'          => wp_next_scheduled( SDP_Collector::HOOK ) ?: null,
			'php'           => PHP_VERSION,
			'db'            => $wpdb->db_version(),
		);
		set_transient( 'sdp50_health', $v, 300 );
		return $v;}
	public static function claim_form( $content ) {
		if ( ! is_singular( SDP_Directory::TYPE ) || ! in_the_loop() || ! is_main_query() || post_password_required() || get_post_status( get_queried_object_id() ) !== 'publish' || get_option( 'sdp50_schema' ) !== self::SCHEMA ) {
			return $content;
		}$d = SDP_SEO::current();
		$n  = self::npsn( $d['npsn'] ?? '' );
		if ( ! SDP_Engine::valid_npsn( $n ) || SDP_SEO::demo( $d ) ) {
			return $content;
		}global $wpdb;
		$claim = $wpdb->get_row( $wpdb->prepare( 'SELECT status,user_id FROM ' . self::table() . ' WHERE npsn=%s', $n ), ARRAY_A );
		$html  = '<section class="sdp-profile"><h2>Klaim profil sekolah</h2>';
		if ( $claim && 'approved' === $claim['status'] ) {
			return $content . $html . '<p>Klaim operator telah disetujui administrator. Ini bukan verifikasi mutu sekolah.</p></section>';
		}
		if ( $claim && 'pending' === $claim['status'] ) {
			return $content . $html . '<p>Pengajuan sedang ditinjau administrator.</p></section>';
		}
		if ( ! is_user_logged_in() ) {
			return $content . $html . '<p><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Login untuk mengajukan klaim</a>.</p></section>';
		}
		$html .= '<p>Gunakan tautan bukti publik resmi, bukan NIK, dokumen identitas atau data pribadi siswa. Persetujuan tidak memberi hak edit langsung.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp50_claim"><input type="hidden" name="npsn" value="' . esc_attr( $n ) . '">' . wp_nonce_field( 'sdp50_claim_' . $n, '_wpnonce', true, false ) . '<p><label>Peran di sekolah <input required maxlength="100" name="role_note"></label></p><p><label>Tautan bukti publik resmi <input required type="url" maxlength="1000" name="evidence"></label></p><button type="submit">Ajukan klaim</button></form></section>';
		return $content . $html;
	}
	public static function submit_claim() {
		if ( ! is_user_logged_in() || ! self::install() ) {
			wp_die( 'Akses ditolak.' );
		}$n = self::npsn( wp_unslash( $_POST['npsn'] ?? '' ) );
		if ( ! SDP_Engine::valid_npsn( $n ) ) {
			wp_die( 'NPSN tidak valid untuk klaim.' );
		}check_admin_referer( 'sdp50_claim_' . $n );
		$rows = SDP_Analytics::public_rows( array( $n ) );
		if ( ! $rows || is_wp_error( SDP_Intelligence::load( $n ) ) ) {
			wp_die( 'Profil publik tidak tersedia untuk klaim.' );
		}$uid = get_current_user_id();
		if ( get_transient( 'sdp50_claim_rate_' . $uid ) ) {
			wp_die( 'Tunggu 10 menit sebelum mengajukan kembali.' );
		}$raw = wp_unslash( $_POST['evidence'] ?? '' );
		$role = wp_unslash( $_POST['role_note'] ?? '' );
		$url  = is_string( $raw ) ? esc_url_raw( $raw, array( 'https', 'http' ) ) : '';
		$role = is_string( $role ) ? sanitize_text_field( $role ) : '';
		if ( ! $url || ! preg_match( '~^https?://~i', $url ) || strlen( $url ) > 1000 || ! $role || strlen( $role ) > 191 ) {
			wp_die( 'Bukti atau peran tidak valid.' );
		}global $wpdb;
		$t   = self::table();
		$now = current_time( 'mysql', true );
		// Atomic insert/retry after rejected or revoked; no overwrite of pending/approved.
		$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO $t (npsn,user_id,evidence,role_note,status,reviewed_by,updated_at) VALUES (%s,%d,%s,%s,'pending',0,%s) ON DUPLICATE KEY UPDATE user_id=IF(status IN ('rejected','revoked'),VALUES(user_id),user_id), evidence=IF(status IN ('rejected','revoked'),VALUES(evidence),evidence), role_note=IF(status IN ('rejected','revoked'),VALUES(role_note),role_note), reviewed_by=IF(status IN ('rejected','revoked'),0,reviewed_by), updated_at=IF(status IN ('rejected','revoked'),VALUES(updated_at),updated_at), status=IF(status IN ('rejected','revoked'),'pending',status)", $n, $uid, $url, $role, $now ) );
		if ( ! $ok ) {
			wp_die( 'Klaim gagal atau sudah ada.' );
		}set_transient( 'sdp50_claim_rate_' . $uid, 1, 600 );
		self::log( 'claim_submitted', $n );
		wp_safe_redirect( get_permalink( $rows[0]['post_id'] ) );
		exit;
	}
	public static function allowed_transition( $old, $new ) {
		return ( 'pending' === $old && in_array( $new, array( 'approved', 'rejected' ), true ) ) || ( 'approved' === $old && 'revoked' === $new );}
	public static function review() {
		if ( ! current_user_can( 'manage_options' ) || ! self::install() ) {
			wp_die( 'Akses ditolak.' );
		}$n = self::npsn( wp_unslash( $_POST['npsn'] ?? '' ) );
		check_admin_referer( 'sdp50_review_' . $n );
		$to = sanitize_key( $_POST['decision'] ?? '' );
		global $wpdb;
		$t   = self::table();
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $t WHERE npsn=%s", $n ) );
		if ( ! self::allowed_transition( $old, $to ) ) {
			wp_die( 'Transisi tidak valid.' );
		}if ( 'approved' === $to && ( empty( $_POST['verified'] ) || ! SDP_Analytics::public_rows( array( $n ) ) || is_wp_error( SDP_Intelligence::load( $n ) ) ) ) {
			wp_die( 'Verifikasi independen dan profil publik diperlukan.' );
		}$ok = $wpdb->update(
			$t,
			array(
				'status'      => $to,
				'reviewed_by' => get_current_user_id(),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'npsn'   => $n,
				'status' => $old,
			)
		);
		if ( 1 !== $ok ) {
			wp_die( 'Klaim berubah atau gagal disimpan.' );
		}self::log( 'claim_' . $to, $n );
		wp_safe_redirect( admin_url( 'admin.php?page=sdp-national' ) );
		exit;}
	public static function delete( $id, $post = null ) {
		if ( ( $post->post_type ?? '' ) !== SDP_Directory::TYPE ) {
			return;
		}self::invalidate();
		if ( get_option( 'sdp50_schema' ) !== self::SCHEMA ) {
			return;
		}global $wpdb;
		$n = $wpdb->get_var( $wpdb->prepare( 'SELECT npsn FROM ' . SDP_Directory::table() . ' WHERE post_id=%d', $id ) );
		if ( $n ) {
			$wpdb->delete( self::table(), array( 'npsn' => $n ) );
		}}
	public static function deleted_user( $uid ) {
		if ( get_option( 'sdp50_schema' ) !== self::SCHEMA ) {
			return;
		}global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => (int) $uid ) );
		$wpdb->update( self::table(), array( 'reviewed_by' => 0 ), array( 'reviewed_by' => (int) $uid ) );
		$wpdb->update( self::audit_table(), array( 'actor' => 0 ), array( 'actor' => (int) $uid ) );}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Skala Nasional', 'Skala Nasional', 'manage_options', 'sdp-national', array( __CLASS__, 'admin' ) );}
	public static function maintenance() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp50_maintenance' );
		$op = sanitize_key( $_POST['op'] ?? '' );
		if ( 'refresh' === $op ) {
			self::invalidate();
		} elseif ( 'purge_audit' === $op ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::audit_table() . ' WHERE created_at<%s', gmdate( 'Y-m-d H:i:s', time() - 90 * 86400 ) ) );
		} else {
			wp_die( 'Operasi tidak dikenal.' );
		}wp_safe_redirect( admin_url( 'admin.php?page=sdp-national' ) );
		exit;}
	public static function admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}if ( ! self::install() ) {
			echo '<div class="notice notice-error"><p>Migrasi tabel gagal.</p></div>';
			return;
		}$h = self::health();
		echo '<div class="wrap"><h1>Skala Nasional — SDP 5.1</h1><p>Ringkasan dapat disimpan sementara selama 5 menit. Kedaluwarsa berarti indeks belum ditulis ulang selama 365 hari, bukan bukti data sumber sudah lama.</p><table class="widefat striped"><tbody>';
		foreach ( $h as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( null === $v ? 'Tidak tersedia' : ( 'cron' === $k ? gmdate( 'Y-m-d H:i:s', (int) $v ) . ' UTC' : (string) $v ) ) . '</td></tr>';
		}echo '</tbody></table><p>WP-CLI: <code>wp sdp health</code>, <code>wp sdp indexes</code>, <code>wp sdp import</code>, <code>wp sdp queue</code>. Gunakan PANDUAN-V5.txt untuk backup dan impor.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp50_maintenance">';
		wp_nonce_field( 'sdp50_maintenance' );
		echo '<button class="button" name="op" value="refresh">Segarkan statistik</button> <button class="button" name="op" value="purge_audit">Hapus log &gt;90 hari</button></form><h2>Moderasi klaim (maks. 100 terbaru, pending dahulu)</h2>';
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " ORDER BY (status='pending') DESC,updated_at DESC LIMIT 100", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			echo '<div style="background:white;padding:12px;margin:8px 0"><strong>' . esc_html( $r['npsn'] . ' — ' . $r['status'] ) . '</strong><p>' . esc_html( 'User ID ' . $r['user_id'] . ' | ' . $r['role_note'] ) . ' | ' . esc_html( $r['evidence'] ) . '</p><p>Periksa bukti melalui kanal resmi sekolah secara independen. Tautan ditampilkan sebagai teks agar tidak dibuka otomatis.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp50_review"><input type="hidden" name="npsn" value="' . esc_attr( $r['npsn'] ) . '">';
			wp_nonce_field( 'sdp50_review_' . $r['npsn'] );
			if ( 'pending' === $r['status'] ) {
				echo '<label><input type="checkbox" name="verified" value="1"> Sudah verifikasi independen</label> <button class="button" name="decision" value="approved">Setujui</button> <button class="button" name="decision" value="rejected">Tolak</button>';
			} elseif ( 'approved' === $r['status'] ) {
				echo '<button class="button" name="decision" value="revoked">Cabut persetujuan</button>';
			}echo '</form></div>';
		}echo '<h2>Log administrasi (50 terakhir)</h2><table class="widefat"><tbody>';
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::audit_table() . ' ORDER BY id DESC LIMIT 50', ARRAY_A ) as $r ) {
			echo '<tr><td>' . esc_html( $r['created_at'] ) . '</td><td>' . esc_html( $r['event'] ) . '</td><td>' . esc_html( $r['subject'] ) . '</td></tr>';
		}echo '</tbody></table></div>';}
	public static function cli_health( $args, $assoc ) {
		WP_CLI::log( wp_json_encode( self::health( true ), JSON_PRETTY_PRINT ) );}
	public static function cli_indexes( $args, $assoc ) {
		if ( ! isset( $assoc['yes'] ) ) {
			WP_CLI::error( 'Cadangkan database; jalankan saat sepi dengan --yes.' );
		}$r = self::build_indexes();
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}WP_CLI::success( $r ? 'Indeks ditambahkan: ' . implode( ', ', $r ) : 'Semua indeks tersedia.' );}
	public static function cli_queue( $args, $assoc ) {
		$rounds = SDP_Resource::cap( self::limit( $assoc['rounds'] ?? 10, 10, 100 ), 5 );for ( $i = 0;$i < $rounds;$i++ ) {
			$r = SDP_Collector::lock( array( SDP_Collector::class, 'process' ) );
			if ( is_wp_error( $r ) ) {
				WP_CLI::error( $r->get_error_message() );
			}WP_CLI::log( $r );
			if ( ! SDP_Collector::pending() ) {
				break;
			}
		}SDP_Performance::reconcile();
		self::invalidate();}
	public static function header( $line ) {
		$keys = array_map( array( SDP_Collector::class, 'key' ), $line );
		if ( ! $keys || count( $keys ) > 150 || in_array( '', $keys, true ) || count( $keys ) !== count( array_unique( $keys ) ) ) {
			return new WP_Error( 'header', 'Header kosong, duplikat, atau terlalu banyak kolom.' );
		}$npsns = array_merge( array( 'npsn' ), SDP_Collector::aliases()['npsn'] );
		if ( ! array_intersect( $keys, $npsns ) ) {
			return new WP_Error( 'header', 'Header NPSN wajib.' );
		}return $keys;}
	public static function checkpoint_key( $hash, $config ) {
		return 'sdp50_job_' . substr( hash( 'sha256', $hash . '|' . wp_json_encode( $config ) ), 0, 40 );}
	/** Import at most --limit CSV records, with persisted per-record checkpoint. */
	public static function cli_import( $args, $assoc ) {
		if ( (string) ( $assoc['authorized'] ?? '' ) !== '1' ) {
			WP_CLI::error( 'Konfirmasi izin dataset dengan --authorized=1.' );
		}
		$path = realpath( $args[0] ?? '' );
		if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) || strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) !== 'csv' ) {
			WP_CLI::error( 'Berikan path CSV lokal yang dapat dibaca.' );
		}
		if ( ! self::install() || ! SDP_Analytics::install() ) {
			WP_CLI::error( 'Migrasi tabel gagal.' );
		}
		$c          = SDP_Collector::defaults();
		$c['label'] = sanitize_text_field( $assoc['source'] ?? 'CSV CLI' );
		if ( ! $c['label'] || strlen( $c['label'] ) > 191 ) {
			WP_CLI::error( 'Nama sumber tidak valid.' );
		}$c['policy'] = ( $assoc['policy'] ?? 'fill' ) === 'overwrite' ? 'overwrite' : 'fill';
		$c['publish'] = isset( $assoc['publish'] ) && current_user_can( 'publish_posts' );
		if ( isset( $assoc['publish'] ) && ! $c['publish'] ) {
			WP_CLI::error( 'Gunakan --user=<administrator> untuk publikasi.' );
		}
		if ( isset( $assoc['kind'] ) ) {
			if ( ! in_array( $assoc['kind'], array( 'general', 'statistics', 'accreditation', 'madrasah' ), true ) ) {
				WP_CLI::error( 'Jenis dataset tidak valid.' );
			}$c['kind'] = $assoc['kind'];}
		foreach ( array( 'provinsi', 'kabupaten', 'kecamatan', 'jenjang' ) as $k ) {
			$c[ $k ] = sanitize_text_field( $assoc[ $k ] ?? '' );
		}
		if ( ! in_array( $assoc['delimiter'] ?? 'comma', array( 'comma', 'semicolon' ), true ) || ! in_array( $assoc['policy'] ?? 'fill', array( 'fill', 'overwrite' ), true ) ) {
			WP_CLI::error( 'Delimiter atau policy tidak valid.' );
		}$delimiter = ( $assoc['delimiter'] ?? 'comma' ) === 'semicolon' ? ';' : ',';
		$limit      = SDP_Resource::cap( self::limit( $assoc['limit'] ?? 500, 500, 5000 ), 100 );
		$hash       = hash_file( 'sha256', $path );
		if ( ! $hash ) {
			WP_CLI::error( 'Gagal membaca checksum.' );
		}$key = self::checkpoint_key( $hash, array( $c, $delimiter ) );
		$r    = SDP_Collector::lock(
			function () use ( $path, $c, $delimiter, $limit, $key, $assoc, $hash ) {
				$f = fopen( $path, 'rb' );
				if ( ! $f ) {
					return new WP_Error( 'file', 'Gagal membuka CSV.' );
				}try {
						$head = fgetcsv( $f, 0, $delimiter, '"', '' );
					$keys     = self::header( is_array( $head ) ? $head : array() );
					if ( is_wp_error( $keys ) ) {
						return $keys;
					}
					$job = get_option( $key, false );
					if ( isset( $assoc['restart'] ) ) {
						$job = false;
						delete_option( $key );}
					if ( ! is_array( $job ) ) {
						$job = array(
							'offset'  => ftell( $f ),
							'seen'    => 0,
							'saved'   => 0,
							'skipped' => 0,
							'invalid' => 0,
							'done'    => false,
						);
					}
					if ( $job['done'] ) {
						return $job;
					}if ( fseek( $f, $job['offset'] ) !== 0 ) {
						return new WP_Error( 'seek', 'Checkpoint tidak dapat dibaca.' );
					}
					$started = microtime( true );for ( $i = 0;$i < $limit;$i++ ) {
						if ( $i > 0 && SDP_Resource::enabled() && microtime( true ) - $started > 15 ) {
							break;
						}
						$line = fgetcsv( $f, 0, $delimiter, '"', '' );
						if ( false === $line ) {
							if ( ! feof( $f ) ) {
								return new WP_Error( 'read', 'Gagal membaca CSV.' );
							}$job['done'] = true;
							if ( ! update_option( $key, $job, false ) && get_option( $key ) !== $job ) {
								return new WP_Error( 'checkpoint', 'Checkpoint akhir gagal disimpan.' );
							}break;}
						$num = $job['seen'] + 1;
						if ( array( null ) === $line ) {
							$job['skipped']++;
						} elseif ( count( $line ) !== count( $keys ) ) {
							$job['invalid']++;
							WP_CLI::warning( "Rekaman $num: jumlah kolom tidak cocok." );
						} else {
							$data = SDP_Collector::map_row( array_combine( $keys, $line ), $c );
							if ( null === $data ) {
								$job['skipped']++;
							} elseif ( is_wp_error( $data ) ) {
								$job['invalid']++;
								WP_CLI::warning( "Rekaman $num: " . $data->get_error_message() );
							} else {
								$save = SDP_Directory::save(
									$data,
									$c['publish'],
									$c['policy'],
									array(
										'label' => $c['label'],
										'url'   => '',
									)
								);
								if ( is_wp_error( $save ) ) {
											return new WP_Error( 'save', "Rekaman $num belum dicatat ke checkpoint: " . $save->get_error_message() . '. Perbaiki penyebab lalu ulangi.' );
								}$job['saved']++;}
						}
						$job['seen']++;
						$job['offset'] = ftell( $f );
						if ( ! update_option( $key, $job, false ) && get_option( $key ) !== $job ) {
							return new WP_Error( 'checkpoint', 'Checkpoint gagal disimpan. Hentikan proses.' );
						}
						if ( 49 === $i % 50 && function_exists( 'wp_cache_flush_runtime' ) ) {
							wp_cache_flush_runtime();
						}
					}return $job;
				} finally {
					fclose( $f );
				}}
		);
		self::invalidate();
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}self::log( 'cli_import', substr( $hash, 0, 12 ) . ' seen=' . $r['seen'] );
		WP_CLI::log( wp_json_encode( $r, JSON_PRETTY_PRINT ) );
		WP_CLI::success( $r['done'] ? 'Dataset selesai. Periksa rekaman invalid.' : 'Batch selesai. Ulangi perintah yang sama untuk melanjutkan.' );
	}
}
