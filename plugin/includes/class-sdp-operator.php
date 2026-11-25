<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Frontend operator proposals. The only publication path is explicit admin evidence review. */
final class SDP_Operator {
	const SCHEMA    = '1';
	const SHORTCODE = 'portal_operator_sekolah';
	const PAGE      = 'sdp71_operator_page';
	const FIELDS    = array( 'alamat', 'desa', 'kode_pos', 'latitude', 'longitude', 'telepon', 'email', 'website', 'media_sosial', 'kurikulum', 'waktu_belajar', 'visi', 'misi', 'tujuan', 'sejarah', 'jumlah_siswa', 'siswa_laki_laki', 'siswa_perempuan', 'jumlah_guru', 'jumlah_tendik', 'guru_sertifikasi', 'jumlah_rombel', 'luas_tanah', 'luas_bangunan', 'ruang_kelas', 'laboratorium', 'perpustakaan', 'toilet', 'uks', 'tempat_ibadah', 'lapangan', 'kondisi_bangunan', 'internet', 'listrik', 'air', 'jurusan', 'mitra_industri', 'ekstrakurikuler', 'prestasi', 'spmb', 'kuota', 'biaya', 'beasiswa' );
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sdp_operator_proposals';}
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 62 );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'portal' ) );
		add_action( 'admin_post_sdp71_submit', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_sdp71_review', array( __CLASS__, 'review' ) );
		add_action( 'admin_post_sdp71_page', array( __CLASS__, 'create_page' ) );
		add_action( 'template_redirect', array( __CLASS__, 'private_page' ), 0 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 99 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap' ), 20, 2 );
		add_filter( 'the_content', array( __CLASS__, 'profile_link' ), 32 );
		add_action( 'deleted_user', array( __CLASS__, 'delete_user' ) );
		add_action( 'deleted_post', array( __CLASS__, 'delete_school' ), 3, 2 );
	}
	public static function install() {
		if ( get_option( 'sdp71_schema' ) === self::SCHEMA ) {
			return true;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		$c = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            npsn varchar(8) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            claim_token char(64) NOT NULL,
            field varchar(40) NOT NULL,
            candidate longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            reviewed_at datetime NULL,
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY user_history (user_id,id),
            KEY pending_queue (status,id),
            KEY npsn (npsn)
        ) $c;"
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) !== $t ) {
			return false;
		}
		update_option( 'sdp71_schema', self::SCHEMA, false );
		return true;
	}
	public static function fields( $d ) {
		$f                  = array_intersect_key( SDP_Directory::labels(), array_flip( self::FIELDS ), SDP_Intelligence::relevant( $d ) );
		$f['media_request'] = 'Usulan logo / foto (URL)';
		return $f;}
	public static function claim_token( $row ) {
		return hash( 'sha256', serialize( array( (string) ( $row['npsn'] ?? '' ), (int) ( $row['user_id'] ?? 0 ), (string) ( $row['status'] ?? '' ), (string) ( $row['updated_at'] ?? '' ), (int) ( $row['reviewed_by'] ?? 0 ) ) ) );}
	public static function owns( $row, $uid, $n ) {
		return is_array( $row ) && $uid > 0 && (int) ( $row['user_id'] ?? 0 ) === $uid && ( $row['npsn'] ?? '' ) === $n && ( $row['status'] ?? '' ) === 'approved';}
	public static function claim( $n, $uid ) {
		if ( ! SDP_Engine::valid_npsn( $n ) || $uid < 1 || get_option( 'sdp50_schema' ) !== SDP_National::SCHEMA ) {
			return new WP_Error( 'claim', 'Klaim aktif diperlukan.' );
		}
		global $wpdb;
		$t = SDP_National::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT npsn,user_id,status,updated_at,reviewed_by FROM $t WHERE npsn=%s AND user_id=%d", $n, $uid ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) || ! self::owns( $r, $uid, $n ) ) {
			return new WP_Error( 'claim', 'Klaim aktif diperlukan.' );
		}return $r;
	}
	public static function school( $n ) {
		$d = SDP_Intelligence::load( $n );
		if ( is_wp_error( $d ) ) {
			return $d;
		}$rows = SDP_Analytics::public_rows( array( $n ) );
		if ( ! $rows ) {
			return new WP_Error( 'school', 'Profil publik tidak tersedia.' );
		}return $d;}
	public static function validate( $raw, $n, $d ) {
		if ( ! is_array( $raw ) || ! SDP_Engine::valid_npsn( $n ) || ( $d['npsn'] ?? null ) !== $n ) {
			return new WP_Error( 'invalid', 'Identitas tidak valid.' );
		}
		foreach ( array( 'field', 'value', 'url', 'period', 'note' ) as $k ) {
			if ( ! is_string( $raw[ $k ] ?? null ) ) {
				return new WP_Error( 'invalid', 'Isian harus berupa teks.' );
			}
		}
		$f = $raw['field'];
		if ( ! isset( self::fields( $d )[ $f ] ) || strlen( $raw['value'] ) > 6000 || strlen( $raw['note'] ) > 1000 || strlen( $raw['period'] ) > 40 || strlen( $raw['url'] ) > 1000 ) {
			return new WP_Error( 'invalid', 'Atribut atau panjang isian tidak valid.' );
		}
		if ( 'media_request' === $f ) {
			$url   = SDP_Media::evidence_url( trim( $raw['value'] ) );
			$proof = SDP_Media::evidence_url( trim( $raw['url'] ) );
			$note  = sanitize_textarea_field( $raw['note'] );
			if ( ! $url || ! $proof || trim( $note ) === '' || ( $raw['authorized'] ?? '' ) !== '1' ) {
				return new WP_Error( 'media', 'URL HTTPS gambar, bukti dan catatan izin wajib.' );
			}
			return array(
				'field'  => $f,
				'value'  => $url,
				'url'    => $proof,
				'period' => '',
				'note'   => $note,
				'type'   => 'operator',
			);
		}
		$raw['type'] = 'operator';
		$c           = SDP_Intelligence::validate( $raw, $n );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		if ( in_array( $f, SDP_Intelligence::STATS, true ) && '' === $c['period'] ) {
			return new WP_Error( 'period', 'Periode wajib untuk statistik.' );
		}
		return $c;
	}
	public static function page_url() {
		$id = (int) get_option( self::PAGE );
		return $id && SDP_Portal::context( $id ) ? get_permalink( $id ) : ''; }
	public static function is_portal() {
		if ( ! is_singular( 'page' ) ) {
			return false;
		}$p = get_post( get_queried_object_id() );
		return $p && ( (int) $p->ID === (int) get_option( self::PAGE ) || has_shortcode( $p->post_content, self::SHORTCODE ) );}
	public static function private_page() {
		if ( self::is_portal() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}nocache_headers();}}
	public static function robots( $r ) {
		if ( self::is_portal() ) {
			unset( $r['index'] );
			$r['noindex']  = true;
			$r['nofollow'] = true;
		}return $r;}
	public static function sitemap( $args, $type ) {
		$id = (int) get_option( self::PAGE );
		if ( 'page' === $type && $id ) {
			$args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), array( $id ) ) ) );
		}return $args;}
	public static function registration_open() {
		return (bool) get_option( 'users_can_register' ) && get_option( 'default_role' ) === 'subscriber';}
	public static function profile_link( $content ) {
		if ( ! is_singular( SDP_Directory::TYPE ) || ! in_the_loop() || ! is_main_query() || post_password_required() || get_post_status( get_queried_object_id() ) !== 'publish' ) {
			return $content;
		}$url = self::page_url();
		return $url ? $content . '<p><a href="' . esc_url( $url ) . '">Portal operator sekolah</a></p>' : $content;}
	public static function url() {
		return admin_url( 'admin.php?page=sdp-operator' );}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Portal Operator', 'Portal Operator', 'manage_options', 'sdp-operator', array( __CLASS__, 'admin' ) );}
	public static function create_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp71_page' );
		if ( ! self::page_url() ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Portal Operator Sekolah',
					'post_name'    => 'portal-operator-sekolah',
					'post_content' => '[' . self::SHORTCODE . ']',
					'post_status'  => 'publish',
				),
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				wp_die( 'Gagal membuat halaman.' );
			}update_option( self::PAGE, $id );
		}wp_safe_redirect( self::url() );
		exit;}
	private static function form( $action, $nonce ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $nonce );}
	private static function history( $rows ) {
		echo '<div class="sdp71-table"><table><thead><tr><th>NPSN</th><th>Atribut</th><th>Status</th><th>Waktu UTC</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r['npsn'] ) . '</td><td>' . esc_html( SDP_Directory::labels()[ $r['field'] ] ?? 'Usulan media' ) . '</td><td>' . esc_html(
				array(
					'pending'  => 'Menunggu',
					'approved' => 'Disetujui',
					'rejected' => 'Ditolak',
					'handled'  => 'Media ditinjau',
				)[ $r['status'] ] ?? $r['status']
			) . '</td><td>' . esc_html( $r['created_at'] ) . '</td></tr>';
		}echo '</tbody></table></div>';}
	public static function portal() {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || ! self::is_portal() ) {
			return '<p>Buka halaman Portal Operator untuk melihat akun Anda.</p>';
		}
		self::private_page();
		if ( ! is_user_logged_in() ) {
			$url = self::page_url() ?: get_permalink();
			return '<section class="sdp71"><h2>Portal operator sekolah</h2><p>Login untuk melihat klaim dan mengajukan perubahan profil.</p><p><a href="' . esc_url( wp_login_url( $url ) ) . '">Masuk</a>' . ( self::registration_open() ? ' · <a href="' . esc_url( wp_registration_url() ) . '">Daftar akun</a>' : ' · Hubungi administrator untuk akun.' ) . '</p></section>';}
		if ( get_option( 'sdp71_schema' ) !== self::SCHEMA || get_option( 'sdp50_schema' ) !== SDP_National::SCHEMA ) {
			return '<p>Portal belum siap. Hubungi administrator.</p>';
		}
		global $wpdb;
		$uid     = get_current_user_id();
		$t       = self::table();
		$claims  = SDP_National::table();
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT npsn,status FROM $claims WHERE user_id=%d ORDER BY updated_at DESC LIMIT 50", $uid ), ARRAY_A );
		$history = $wpdb->get_results( $wpdb->prepare( "SELECT npsn,field,status,created_at FROM $t WHERE user_id=%d ORDER BY id DESC LIMIT 50", $uid ), ARRAY_A );
		if ( ! is_array( $rows ) || ! is_array( $history ) || ! empty( $wpdb->last_error ) ) {
			return '<p>Portal tidak tersedia sementara.</p>';
		}
		$root = dirname( __DIR__ ) . '/sekolah-directory-pro.php';
		wp_enqueue_style( 'sdp-operator', plugins_url( 'assets/operator.css', $root ), array(), SDP_Directory::VERSION );
		$n = wp_unslash( $_GET['sdp_operator_npsn'] ?? '' );
		$d = SDP_Engine::valid_npsn( $n ) && ! is_wp_error( self::claim( $n, $uid ) ) ? self::school( $n ) : null;
		ob_start();
		echo '<section class="sdp71"><h2>Portal operator sekolah</h2><p>Ajukan informasi publik sekolah yang dapat dibuktikan. Jangan masukkan data pribadi siswa, NIK, dokumen identitas, atau rahasia. Tidak ada perubahan langsung sebelum moderasi.</p><p>Cari profil sekolah melalui direktori atau masukkan NPSN di bawah, buka profil dan ajukan klaim. Persetujuan klaim bukan penilaian mutu sekolah.</p><form method="get" action="' . esc_url( self::page_url() ?: get_permalink() ) . '">' . ( ! get_option( 'permalink_structure' ) ? '<input type="hidden" name="page_id" value="' . (int) get_queried_object_id() . '">' : '' ) . '<p><label>NPSN sekolah <input name="sdp_operator_npsn" maxlength="8" required></label></p><button type="submit">Cari sekolah</button></form><p><a href="' . esc_url( wp_logout_url( self::page_url() ?: home_url( '/' ) ) ) . '">Keluar</a></p><h3>Klaim saya (50 terakhir)</h3>';
		if ( ! $rows ) {
			echo '<p>Belum ada klaim.</p>';
		}foreach ( $rows as $r ) {
			echo '<p>' . esc_html( $r['npsn'] ) . ' — ' . esc_html( $r['status'] );
			if ( 'approved' === $r['status'] ) {
				echo ' · <a href="' . esc_url( add_query_arg( 'sdp_operator_npsn', $r['npsn'], self::page_url() ?: get_permalink() ) ) . '">Ajukan perubahan</a>';
			}echo '</p>';}
		if ( is_array( $d ) ) {
			echo '<h3>' . esc_html( $d['nama'] ) . ' — ' . esc_html( $n ) . '</h3><details><summary>Data profil saat ini</summary><dl>';
			foreach ( self::fields( $d ) as $k => $l ) {
				if ( 'media_request' === $k ) {
					continue;
				}echo '<dt>' . esc_html( $l ) . '</dt><dd>' . esc_html( $d[ $k ] ?? 'Belum tersedia' ) . esc_html( SDP_Intelligence::period_note( $d, $k ) ) . '</dd>';
			}echo '</dl></details>';
			self::form( 'sdp71_submit', 'sdp71_submit_' . $n );
			echo '<input type="hidden" name="npsn" value="' . esc_attr( $n ) . '"><p><label>Atribut <select name="candidate[field]">';
			foreach ( self::fields( $d ) as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
			}echo '</select></label></p><p><label>Nilai yang diusulkan<textarea name="candidate[value]" rows="5" maxlength="6000" required></textarea></label></p><p><label>URL bukti publik<input name="candidate[url]" type="url" maxlength="1000" required></label></p><p><label>Periode (wajib untuk statistik)<input name="candidate[period]" placeholder="2025/2026 ganjil" maxlength="40"></label></p><p><label>Catatan (privat untuk peninjauan)<textarea name="candidate[note]" maxlength="1000" rows="3"></textarea></label></p><p>Usulan media: masukkan URL HTTPS gambar sebagai nilai, URL bukti izin, dan catatan izin. Gambar tidak diunduh otomatis.</p><p><label><input type="checkbox" name="candidate[authorized]" value="1"> Saya berhak mengusulkan publikasi media ini (khusus media).</label></p><button type="submit">Kirim usulan</button></form>';
		} elseif ( '' !== $n ) {
			$public = SDP_Engine::valid_npsn( $n ) ? SDP_Analytics::public_rows( array( $n ) ) : array();
			if ( $public ) {
				echo '<p><a href="' . esc_url( get_permalink( (int) $public[0]['post_id'] ) ) . '">Buka profil publik dan ajukan klaim</a>. Akses pengajuan perubahan memerlukan klaim aktif.</p>';
			} else {
				echo '<p>Profil atau klaim aktif tidak tersedia.</p>';
			}
		}
		echo '<h3>Riwayat usulan saya (50 terakhir)</h3>';
		self::history( $history );
		echo '</section>';
		return ob_get_clean();
	}
	public static function submit() {
		if ( ! is_user_logged_in() || ! self::install() ) {
			wp_die( 'Akses ditolak.' );
		}$n = wp_unslash( $_POST['npsn'] ?? '' );
		if ( ! SDP_Engine::valid_npsn( $n ) ) {
			wp_die( 'NPSN tidak valid.' );
		}check_admin_referer( 'sdp71_submit_' . $n );
		$uid   = get_current_user_id();
		$claim = self::claim( $n, $uid );
		$d     = self::school( $n );
		if ( is_wp_error( $claim ) || is_wp_error( $d ) ) {
			wp_die( 'Klaim aktif dan profil publik diperlukan.' );
		}
		$c = self::validate( wp_unslash( $_POST['candidate'] ?? array() ), $n, $d );
		if ( is_wp_error( $c ) ) {
			wp_die( esc_html( $c->get_error_message() ) );
		}global $wpdb;
		$t    = self::table();
		$lock = 'sdp71u_' . md5( $t . $uid );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) !== '1' ) {
			wp_die( 'Sedang diproses.' );
		}
		$error = '';
		try {
			$count  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id=%d AND status='pending'", $uid ) );
			$recent = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id=%d AND created_at >= %s", $uid, gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
			$claim  = self::claim( $n, $uid );
			if ( is_wp_error( $claim ) || null === $count || null === $recent || ! empty( $wpdb->last_error ) || (int) $count >= 20 || (int) $recent >= 10 ) {
				$error = 'Klaim berubah, database bermasalah atau batas usulan tercapai.';
			} elseif ( $wpdb->insert(
				$t,
				array(
					'npsn'        => $n,
					'user_id'     => $uid,
					'claim_token' => self::claim_token( $claim ),
					'field'       => $c['field'],
					'candidate'   => wp_json_encode( $c ),
					'status'      => 'pending',
					'created_at'  => current_time( 'mysql', true ),
				)
			) !== 1 ) {
				$error = 'Gagal menyimpan usulan.';
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );}
		if ( $error ) {
			wp_die( esc_html( $error ) );
		}wp_safe_redirect( self::page_url() ?: home_url( '/' ) );
		exit;
	}
	public static function reviewable( $row, $claim, $d ) {
		return is_array( $row ) && ( $row['status'] ?? '' ) === 'pending' && is_array( $d ) && self::owns( $claim, (int) $row['user_id'], $row['npsn'] ) && hash_equals( (string) $row['claim_token'], self::claim_token( $claim ) ) && ( $d['npsn'] ?? null ) === $row['npsn'];}
	public static function review() {
		if ( ! current_user_can( 'manage_options' ) || ! self::install() ) {
			wp_die( 'Akses ditolak.' );
		}$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'sdp71_review_' . $id );
		$op = $_POST['decision'] ?? '';
		if ( ! in_array( $op, array( 'approve', 'reject', 'handled' ), true ) || ( $_POST['confirm'] ?? '' ) !== '1' ) {
			wp_die( 'Konfirmasi diperlukan.' );
		}
		global $wpdb;
		$t    = self::table();
		$lock = 'sdp71r_' . md5( $t . $id );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) !== '1' ) {
			wp_die( 'Sedang ditinjau.' );
		}$error = '';
		try {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", $id ), ARRAY_A );
			if ( ! $r || 'pending' !== $r['status'] || ! empty( $wpdb->last_error ) ) {
				$error = 'Usulan tidak tersedia.';} else {
				$to = 'rejected';
				if ( 'reject' !== $op ) {
					$d         = self::school( $r['npsn'] );
					$claim     = self::claim( $r['npsn'], (int) $r['user_id'] );
					$c         = json_decode( $r['candidate'], true );
					$raw       = is_array( $c ) ? $c + array( 'authorized' => '1' ) : array();
					$validated = self::validate( $raw, $r['npsn'], is_array( $d ) ? $d : array() );
					$snap      = wp_unslash( $_POST['snapshot'] ?? '' );
					if ( ! self::reviewable( $r, $claim, $d ) || is_wp_error( $validated ) || ( $validated['field'] ?? null ) !== $r['field'] || ! is_string( $snap ) || ! hash_equals( SDP_Intelligence::snapshot( $d ), $snap ) ) {
						$error = 'Klaim atau profil berubah; muat ulang dan periksa bukti.';
					} elseif ( 'handled' === $op && 'media_request' === $r['field'] ) {
						$to = 'handled';
					} elseif ( 'approve' === $op && 'media_request' !== $r['field'] ) {
						$c   = $validated;
						$res = SDP_Directory::save(
							array(
								'npsn'      => $r['npsn'],
								$c['field'] => $c['value'],
							),
							false,
							'overwrite',
							array(
								'label' => 'Operator sekolah',
								'url'   => $c['url'],
							),
							array( 'npsn' => $r['npsn'] ),
							array(
								'snapshot'  => $snap,
								'candidate' => $c,
							)
						);
						if ( is_wp_error( $res ) ) {
							$error = $res->get_error_message();
						} else {
							$to = 'approved';
						}
					} else {
						$error = 'Tindakan tidak sesuai jenis usulan.';
					}
				}
				if ( ! $error ) {
					$ok = $wpdb->update(
						$t,
						array(
							'status'      => $to,
							'reviewed_at' => current_time( 'mysql', true ),
							'reviewed_by' => get_current_user_id(),
						),
						array(
							'id'     => $id,
							'status' => 'pending',
						)
					);
					if ( 1 !== $ok ) {
						$error = 'Gagal mencatat status; periksa profil sebelum mencoba lagi.';
					} else {
						SDP_National::log( 'operator_' . $to, (string) $id );
					}
				}
				}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}if ( $error ) {
			wp_die( esc_html( $error ) );
		}wp_safe_redirect( self::url() );
		exit;
	}
	public static function admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}echo '<div class="wrap"><h1>Portal Operator — v7.1</h1><p>Registrasi menggunakan akun WordPress; aktifkan hanya jika dibutuhkan dan pastikan peran akun baru Subscriber. Kecualikan halaman portal dari cache server/CDN. Verifikasi klaim di menu Skala Nasional. Usulan media hanya catatan; gunakan Media Sekolah untuk mengimpor setelah memeriksa izin.</p>';
		self::form( 'sdp71_page', 'sdp71_page' );
		echo '<button class="button">Buat halaman portal</button></form>';
		if ( $url = self::page_url() ) {
			echo '<p><a href="' . esc_url( $url ) . '">Buka portal operator</a></p>';
		}if ( ! self::install() ) {
			echo '<p>Gagal membuat tabel.</p></div>';
			return;}
		global $wpdb;
		$t      = self::table();
		$before = absint( $_GET['before'] ?? 0 );
		$sql    = "SELECT * FROM $t WHERE status='pending'";
		$rows   = $wpdb->get_results( $before ? $wpdb->prepare( $sql . ' AND id<%d ORDER BY id DESC LIMIT 50', $before ) : $sql . ' ORDER BY id DESC LIMIT 50', ARRAY_A );
		echo '<h2>Antrean moderasi (maksimal 50)</h2>';
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			echo '<p>Gagal membaca antrean.</p></div>';
			return;}
		foreach ( $rows as $r ) {
			$id    = (int) $r['id'];
			$c     = json_decode( $r['candidate'], true );
			$d     = self::school( $r['npsn'] );
			$claim = self::claim( $r['npsn'], (int) $r['user_id'] );
			$can   = self::reviewable( $r, $claim, $d ) && is_array( $c );
			echo '<hr><h3>#' . $id . ' · ' . esc_html( $r['npsn'] ) . ' · ' . esc_html( $r['field'] ) . '</h3><p>Pengusul #' . (int) $r['user_id'] . ' · ' . esc_html( $r['created_at'] ) . ' UTC</p>';
			if ( is_array( $c ) ) {
				echo '<p>Nilai saat ini: ' . esc_html( is_array( $d ) ? ( $d[ $r['field'] ] ?? '—' ) : '—' ) . '</p><p>Usulan: ' . nl2br( esc_html( $c['value'] ?? '' ) ) . '</p><p>Periode: ' . esc_html( $c['period'] ?? '' ) . '</p><p>Bukti: <a rel="noopener noreferrer" target="_blank" href="' . esc_url( $c['url'] ?? '' ) . '">' . esc_html( $c['url'] ?? '' ) . '</a></p><p>Catatan: ' . nl2br( esc_html( $c['note'] ?? '' ) ) . '</p>';
				if ( 'media_request' === $r['field'] ) {
					echo '<p>Media: <a rel="noopener noreferrer" target="_blank" href="' . esc_url( $c['value'] ?? '' ) . '">Buka URL gambar</a> (tidak diunduh plugin).</p>';
				}
			}self::form( 'sdp71_review', 'sdp71_review_' . $id );
			echo '<input type="hidden" name="id" value="' . $id . '"><p><label><input type="checkbox" name="confirm" value="1" required> Saya telah memeriksa bukti dan keputusan ini.</label></p>';
			if ( $can ) {
				echo '<input type="hidden" name="snapshot" value="' . esc_attr( SDP_Intelligence::snapshot( $d ) ) . '">';
				echo 'media_request' === $r['field'] ? '<button class="button" name="decision" value="handled">Tandai media ditinjau (tanpa impor)</button>' : '<button class="button button-primary" name="decision" value="approve">Setujui dan kunci atribut</button>';
			} else {
				echo '<p>Klaim atau profil tidak aktif; usulan tidak dapat disetujui.</p>';
			}echo ' <button class="button" name="decision" value="reject">Tolak</button></form>';}
		if ( count( $rows ) === 50 ) {
			echo '<p><a href="' . esc_url( add_query_arg( 'before', (int) end( $rows )['id'], self::url() ) ) . '">Antrean lebih lama</a></p>';
		}if ( ! $rows ) {
			echo '<p>Tidak ada usulan tertunda.</p>';
		}echo '</div>';
	}
	public static function delete_user( $uid ) {
		if ( get_option( 'sdp71_schema' ) !== self::SCHEMA ) {
			return;
		}global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => (int) $uid ) );
		$wpdb->update( self::table(), array( 'reviewed_by' => 0 ), array( 'reviewed_by' => (int) $uid ) );}
	public static function delete_school( $id, $post = null ) {
		if ( get_option( 'sdp71_schema' ) !== self::SCHEMA || ( $post->post_type ?? '' ) !== SDP_Directory::TYPE ) {
			return;
		}global $wpdb;
		$n = $wpdb->get_var( $wpdb->prepare( 'SELECT npsn FROM ' . SDP_Directory::table() . ' WHERE post_id=%d', $id ) );
		if ( $n ) {
			$wpdb->delete( self::table(), array( 'npsn' => $n ) );
		}}
}
