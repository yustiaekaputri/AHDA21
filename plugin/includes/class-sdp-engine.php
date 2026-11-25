<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Bounded opt-in enrichment of existing NPSNs, never a crawler or a discovery API. */
final class SDP_Engine {
	const OPTION = 'sdp60_campaign';
	const MAX    = 100;
	const FIELDS = array( 'jenjang', 'status', 'naungan', 'alamat', 'desa', 'kecamatan', 'kabupaten', 'provinsi', 'akreditasi', 'luas_tanah', 'sk_pendirian', 'tanggal_pendirian', 'sk_operasional', 'tanggal_operasional', 'internet', 'listrik', 'website' );
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 28 );
		add_action( 'admin_post_sdp_engine', array( __CLASS__, 'action' ) );
		add_action( SDP_Collector::HOOK, array( __CLASS__, 'tick' ), 20 );
	}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Mesin Pengayaan', 'Mesin Pengayaan', 'manage_options', 'sdp-engine', array( __CLASS__, 'page' ) );}
	public static function state() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array(); }
	public static function valid_npsn( $n ) {
		return is_string( $n ) && preg_match( '/^[A-Z0-9]{8}$/D', $n ) && ! preg_match( '/^(DEMO|SIMU|TEST)/D', $n );}
	public static function filters( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'filter', 'Filter tidak valid.' );
		}
		$f = array();
		foreach ( array( 'provinsi', 'kabupaten', 'kecamatan', 'jenjang', 'after' ) as $k ) {
			$v = $raw[ $k ] ?? '';
			if ( ! is_scalar( $v ) ) {
				return new WP_Error( 'filter', 'Filter tidak valid.' );
			}$f[ $k ] = sanitize_text_field( (string) $v );
			if ( strlen( $f[ $k ] ) > 120 ) {
				return new WP_Error( 'filter', 'Filter terlalu panjang.' );
			}
		}
		if ( '' === $f['provinsi'] ) {
			return new WP_Error( 'filter', 'Pilih minimal satu provinsi (nama persis di database).' );
		}
		if ( '' !== $f['kecamatan'] && '' === $f['kabupaten'] ) {
			return new WP_Error( 'filter', 'Kecamatan memerlukan kabupaten/kota.' );
		}
		if ( '' !== $f['jenjang'] && ! in_array( $f['jenjang'], SDP_Directory::levels(), true ) ) {
			return new WP_Error( 'filter', 'Jenjang tidak valid.' );
		}
		$f['after'] = strtoupper( $f['after'] );
		if ( '' !== $f['after'] && ! self::valid_npsn( $f['after'] ) ) {
			return new WP_Error( 'filter', 'Cursor NPSN tidak valid.' );
		}
		$limit = $raw['limit'] ?? 20;
		if ( ! is_scalar( $limit ) || ! preg_match( '/^\d{1,3}$/D', (string) $limit ) || (int) $limit < 1 || (int) $limit > self::MAX ) {
			return new WP_Error( 'filter', 'Batas 1–100 sekolah.' );
		}$f['limit'] = (int) $limit;
		return $f;
	}
	public static function select( $f ) {
		global $wpdb;
		$t    = SDP_Directory::table();
		$sql  = "SELECT s.npsn,s.nama,s.jenjang,s.kabupaten,s.kecamatan FROM $t s JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE p.post_type=%s AND p.post_status IN ('publish','draft','pending') AND p.post_password='' AND s.npsn NOT LIKE %s AND s.npsn NOT LIKE %s AND s.npsn NOT LIKE %s";
		$args = array( SDP_Directory::TYPE, 'DEMO%', 'SIMU%', 'TEST%' );
		foreach ( array( 'provinsi', 'kabupaten', 'kecamatan', 'jenjang' ) as $k ) {
			if ( '' !== $f[ $k ] ) {
				$sql   .= " AND s.$k=%s";
				$args[] = $f[ $k ];}
		}
		if ( '' !== $f['after'] ) {
			$sql   .= ' AND s.npsn>%s';
			$args[] = $f['after'];}
		$sql   .= ' ORDER BY s.npsn LIMIT %d';
		$args[] = $f['limit'] + 1;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'database', 'Gagal membaca sekolah.' );
		}
		$more = count( $rows ) > $f['limit'];
		$rows = array_slice( $rows, 0, $f['limit'] );
		foreach ( $rows as $r ) {
			if ( ! self::valid_npsn( $r['npsn'] ?? null ) ) {
				return new WP_Error( 'identity', 'Indeks berisi NPSN tidak valid. Periksa database.' );
			}
		}
		return array(
			'filters' => $f,
			'rows'    => $rows,
			'next'    => $more ? end( $rows )['npsn'] : '',
		);
	}
	public static function make( $preview, $auto ) {
		if ( empty( $preview['rows'] ) || count( $preview['rows'] ) > self::MAX ) {
			return new WP_Error( 'empty', 'Tidak ada kandidat yang valid.' );
		}
		$ids = array_column( $preview['rows'], 'npsn' );
		if ( count( $ids ) !== count( $preview['rows'] ) || count( array_unique( $ids ) ) !== count( $ids ) ) {
			return new WP_Error( 'identity', 'Kandidat tidak valid.' );
		}foreach ( $ids as $id ) {
			if ( ! self::valid_npsn( $id ) ) {
				return new WP_Error( 'identity', 'NPSN tidak valid.' );
			}
		}
		return array(
			'status'  => 'running',
			'auto'    => (bool) $auto,
			'filters' => $preview['filters'],
			'ids'     => $ids,
			'next'    => $preview['next'],
			'pos'     => 0,
			'events'  => array(),
			'error'   => '',
			'created' => current_time( 'mysql', true ),
		);
	}
	private static function store( $s ) {
		if ( update_option( self::OPTION, $s, false ) || self::state() === $s ) {
			return true;
		}return new WP_Error( 'database', 'Gagal menyimpan checkpoint. Periksa database sebelum melanjutkan.' );}
	private static function name_key( $v ) {
		return strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $v ) ?? '' ) );}
	public static function additions( $old, $remote ) {
		if ( ! self::valid_npsn( $old['npsn'] ?? null ) || ( $remote['npsn'] ?? null ) !== $old['npsn'] || empty( $old['nama'] ) || empty( $remote['nama'] ) || self::name_key( $old['nama'] ) !== self::name_key( $remote['nama'] ) ) {
			return new WP_Error( 'identity', 'NPSN atau nama tidak cocok. Periksa manual.' );
		}
		foreach ( array( 'jenjang', 'status' ) as $k ) {
			if ( ! empty( $old[ $k ] ) && ! empty( $remote[ $k ] ) && self::name_key( $old[ $k ] ) !== self::name_key( $remote[ $k ] ) ) {
				return new WP_Error( 'identity', 'Konflik ' . $k . '. Periksa manual.' );
			}
		}
		// Do not mix records with conflicting location. Compare explicitly, not by guessing.
		foreach ( array( 'provinsi', 'kabupaten', 'kecamatan' ) as $k ) {
			if ( ! empty( $old[ $k ] ) && ! empty( $remote[ $k ] ) && ! SDP_Collector::region_match( $old[ $k ], $remote[ $k ], $k ) ) {
				return new WP_Error( 'identity', 'Konflik ' . $k . '. Periksa manual.' );
			}
		}
		$out = array( 'npsn' => $old['npsn'] );
		foreach ( self::FIELDS as $k ) {
			if ( ( ! isset( $old[ $k ] ) || '' === $old[ $k ] ) && SDP_SEO::present( $remote, $k ) ) {
				$out[ $k ] = $remote[ $k ];
			}
		}return $out;
	}
	private static function load( $id, $f ) {
		global $wpdb;
		$t = SDP_Directory::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT s.*,p.post_status,p.post_password,p.post_type FROM $t s JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE s.npsn=%s", $id ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'database', 'Gagal membaca profil.' );
		}
		if ( ! $r || SDP_Directory::TYPE !== $r['post_type'] || ! in_array( $r['post_status'], array( 'publish', 'draft', 'pending' ), true ) || '' !== $r['post_password'] ) {
			return null;
		}
		foreach ( array( 'provinsi', 'kabupaten', 'kecamatan', 'jenjang' ) as $k ) {
			if ( '' !== $f[ $k ] && self::name_key( $r[ $k ] ) !== self::name_key( $f[ $k ] ) ) {
				return null;
			}
		}
		$d = json_decode( $r['payload'], true );
		if ( ! is_array( $d ) || ( $d['npsn'] ?? null ) !== $id ) {
			return new WP_Error( 'database', 'Payload tidak valid.' );
		}if ( SDP_SEO::demo( $d ) ) {
			return null;
		}return $d;
	}
	public static function transition( $s, $op ) {
		if ( empty( $s['ids'] ) || ! isset( $s['pos'], $s['status'] ) ) {
			return new WP_Error( 'campaign', 'Belum ada kampanye.' );
		}
		if ( 'pause' === $op && 'running' === $s['status'] ) {
			$s['status'] = 'paused';
		} elseif ( 'resume' === $op && 'paused' === $s['status'] ) {
			$s['status'] = 'running';
			$s['error']  = '';
		} elseif ( 'skip' === $op && 'paused' === $s['status'] ) {
			$s = self::advance( $s, 'skipped', 'Dilewati administrator.' );
		} elseif ( 'cancel' === $op && in_array( $s['status'], array( 'running', 'paused' ), true ) ) {
			$s['status'] = 'cancelled';
			$s['auto']   = false;
		} else {
			return new WP_Error( 'state', 'Operasi tidak sesuai status kampanye.' );
		}return $s;
	}
	public static function advance( $s, $status, $message ) {
		$s['events'][] = array(
			'npsn'    => $s['ids'][ $s['pos'] ],
			'status'  => $status,
			'message' => $message,
			'time'    => current_time( 'mysql', true ),
		);
		++$s['pos'];
		$s['error'] = '';
		if ( $s['pos'] >= count( $s['ids'] ) ) {
			$s['status'] = 'done';
			$s['auto']   = false;
		}return $s;
	}
	public static function halt( $s, $err ) {
		$s['status'] = 'paused';
		$s['error']  = $err->get_error_message();
		$r           = self::store( $s );
		return is_wp_error( $r ) ? $r : $err;}
	public static function step( $automatic = false ) {
		// Caller must hold the existing collector lock (also used by the manual NPSN preview).
		$s = self::state();
		if ( ( $s['status'] ?? '' ) !== 'running' || ( $automatic && empty( $s['auto'] ) ) ) {
			return 'Tidak ada kampanye aktif untuk diproses.';
		}
		if ( SDP_Collector::pending() ) {
			return new WP_Error( 'pending', 'Selesaikan antrean dataset terlebih dahulu.' );
		}
		$id = $s['ids'][ $s['pos'] ] ?? null;
		if ( ! self::valid_npsn( $id ) ) {
			return self::halt( $s, new WP_Error( 'identity', 'Checkpoint NPSN tidak valid.' ) );
		}
		$old = self::load( $id, $s['filters'] );
		if ( is_wp_error( $old ) ) {
			return self::halt( $s, $old );
		}
		if ( null === $old ) {
			$r = self::store( self::advance( $s, 'skipped', 'Profil sudah tidak memenuhi filter atau bukan profil terbuka.' ) );
			return is_wp_error( $r ) ? $r : 'Profil dilewati.';}
		if ( get_transient( 'sdp_reference_rate' ) ) {
			return new WP_Error( 'rate', 'Tunggu minimal 60 detik sejak permintaan profil terakhir.' );
		}
		set_transient( 'sdp_reference_rate', 1, 60 );
		if ( ! get_transient( 'sdp_reference_rate' ) ) {
			return self::halt( $s, new WP_Error( 'rate', 'Tidak dapat menyimpan pembatas permintaan.' ) );
		}
		$remote = SDP_Sources::fetch_reference( $id );
		if ( is_wp_error( $remote ) ) {
			return self::halt( $s, $remote );
		}
		$add = self::additions( $old, $remote );
		if ( is_wp_error( $add ) ) {
			return self::halt( $s, $add );
		}
		if ( count( $add ) === 1 ) {
			$r = self::store( self::advance( $s, 'unchanged', 'Tidak ada kolom kosong yang dapat dilengkapi.' ) );
			return is_wp_error( $r ) ? $r : 'Tidak ada data tambahan.';}
		$expected = array_intersect_key( $old, array_flip( array( 'npsn', 'nama', 'jenjang', 'status', 'provinsi', 'kabupaten', 'kecamatan' ) ) );
		$result   = SDP_Directory::save(
			$add,
			false,
			'fill',
			array(
				'label' => 'Referensi Data Pendidikan',
				'url'   => SDP_Sources::BASE . rawurlencode( $id ),
			),
			$expected
		);
		if ( is_wp_error( $result ) ) {
			return self::halt( $s, $result );
		}
		$message = ! empty( $result['unchanged'] ) ? 'Tidak ada perubahan (data telah terisi).' : 'Kolom kandidat: ' . implode( ', ', array_diff( array_keys( $add ), array( 'npsn' ) ) );
		$r       = self::store( self::advance( $s, ! empty( $result['unchanged'] ) ? 'unchanged' : 'updated', $message ) );
		return is_wp_error( $r ) ? $r : $message;
	}
	public static function tick() {
		if ( ! SDP_Resource::scheduled_network() || ! SDP_Performance::guard_legacy() ) {
			return;
		}if ( ( self::state()['status'] ?? '' ) === 'running' && ! empty( self::state()['auto'] ) ) {
			SDP_Collector::lock(
				function () {
					return self::step( true );
				}
			);
		}}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp_engine' );
		$op  = sanitize_key( $_POST['op'] ?? '' );
		$uid = get_current_user_id();
		$key = 'sdp60_preview_' . $uid;
		$r   = SDP_Collector::lock(
			function () use ( $op, $key ) {
				$s = self::state();
				if ( 'preview' === $op ) {
					$f = self::filters( wp_unslash( $_POST['filter'] ?? array() ) );
					if ( is_wp_error( $f ) ) {
						return $f;
					}delete_transient( $key );
					$p = self::select( $f );
					if ( is_wp_error( $p ) ) {
						return $p;
					}set_transient( $key, $p, 600 );
					return 'Pratinjau tersedia selama 10 menit. Belum ada permintaan ke portal.';}
				if ( in_array( $op, array( 'start', 'resume', 'step' ), true ) && empty( $_POST['authorized'] ) ) {
					return new WP_Error( 'permission', 'Konfirmasi bahwa akses otomatis dan penggunaan ulang data diizinkan sumber.' );
				}
				if ( 'start' === $op ) {
					if ( in_array( $s['status'] ?? '', array( 'running', 'paused' ), true ) ) {
						return new WP_Error( 'active', 'Selesaikan atau batalkan kampanye lama.' );
					}$p = get_transient( $key );
					if ( ! is_array( $p ) ) {
						return new WP_Error( 'preview', 'Pratinjau kedaluwarsa.' );
					}$s = self::make( $p, ! empty( $_POST['auto'] ) );
					if ( is_wp_error( $s ) ) {
						return $s;
					}$saved = self::store( $s );
					if ( is_wp_error( $saved ) ) {
						return $saved;
					}delete_transient( $key );
					return 'Kampanye dibuat. Proses manual atau jadwal yang dipilih.';}
				if ( 'step' === $op ) {
					return self::step();
				}
				if ( in_array( $op, array( 'pause', 'resume', 'skip', 'cancel' ), true ) ) {
					$s = self::transition( $s, $op );
					if ( is_wp_error( $s ) ) {
						return $s;
					}$r = self::store( $s );
					return is_wp_error( $r ) ? $r : 'Status kampanye diperbarui.';}
				return new WP_Error( 'op', 'Operasi tidak dikenal.' );
			}
		);
		if ( ! is_wp_error( $r ) ) {
			SDP_Performance::reconcile();
		}
		set_transient(
			'sdp60_notice_' . $uid,
			array(
				'error' => is_wp_error( $r ),
				'text'  => is_wp_error( $r ) ? $r->get_error_message() : $r,
			),
			120
		);
		wp_safe_redirect( admin_url( 'admin.php?page=sdp-engine' ) );
		exit;
	}
	private static function form() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp_engine">';
		wp_nonce_field( 'sdp_engine' );}
	private static function consent() {
		echo '<p><label><input type="checkbox" name="authorized" value="1"> Saya telah memeriksa ketentuan sumber, izin akses otomatis, penggunaan ulang dan publikasi data.</label></p>';}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}$uid = get_current_user_id();
		$s    = self::state();
		$p    = get_transient( 'sdp60_preview_' . $uid );
		$n    = get_transient( 'sdp60_notice_' . $uid );
		delete_transient( 'sdp60_notice_' . $uid );
		echo '<div class="wrap"><h1>Mesin Pengayaan Profil v6.0</h1><p>Eksperimental — lengkapi kolom kosong sekolah yang sudah terdaftar melalui Referensi Data Pendidikan. Tidak mencari daftar NPSN baru. Tidak memaksa akses yang diblokir.</p>';
		if ( $n ) {
			echo '<div class="notice ' . ( ! empty( $n['error'] ) ? 'notice-warning' : 'notice-success' ) . '"><p>' . esc_html( $n['text'] ) . '</p></div>';
		}
		echo '<h2>1. Impor identitas terlebih dahulu</h2><p><a href="https://data.kemendikdasmen.go.id/data-induk/satpen/daftar" target="_blank" rel="noopener noreferrer">Portal Data Induk Satuan Pendidikan</a> · <a href="' . esc_url( admin_url( 'admin.php?page=sdp-sources' ) ) . '">Impor sumber resmi</a>. Gunakan dataset yang diizinkan, simpan NPSN sebagai teks, gunakan draf untuk uji pertama.</p><h2>2. Pilih kandidat</h2><p>Nama wilayah harus sama dengan database lokal. Minimal provinsi. Pratinjau hanya melakukan kueri database, tanpa koneksi eksternal.</p>';
		self::form();
		$f = $p['filters'] ?? $s['filters'] ?? array();
		foreach ( array(
			'provinsi'  => 'Provinsi',
			'kabupaten' => 'Kabupaten / kota',
			'kecamatan' => 'Kecamatan',
			'after'     => 'Mulai setelah NPSN (opsional)',
		) as $k => $v ) {
			echo '<p><label>' . esc_html( $v ) . ' <input name="filter[' . esc_attr( $k ) . ']" value="' . esc_attr( $f[ $k ] ?? '' ) . '" ' . ( 'provinsi' === $k ? 'required' : '' ) . '></label></p>';
		}
		echo '<p><label>Jenjang <select name="filter[jenjang]"><option value="">Semua</option>';
		foreach ( SDP_Directory::levels() as $v ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( $f['jenjang'] ?? '', $v, false ) . '>' . esc_html( $v ) . '</option>';
		}echo '</select></label></p><p><label>Batas <input type="number" name="filter[limit]" min="1" max="100" value="' . esc_attr( $f['limit'] ?? 20 ) . '"></label></p><button class="button" name="op" value="preview">Pratinjau kandidat</button></form>';
		if ( is_array( $p ) ) {
			echo '<h3>' . count( $p['rows'] ) . ' kandidat</h3><table class="widefat striped"><thead><tr><th>NPSN</th><th>Nama</th><th>Jenjang</th><th>Kabupaten</th><th>Kecamatan</th></tr></thead><tbody>';
			foreach ( $p['rows'] as $r ) {
				echo '<tr>';
				foreach ( array( 'npsn', 'nama', 'jenjang', 'kabupaten', 'kecamatan' ) as $k ) {
					echo '<td>' . esc_html( $r[ $k ] ) . '</td>';
				}echo '</tr>';
			}echo '</tbody></table>';
			if ( $p['rows'] ) {
				self::form();
				self::consent();
				echo '<p><label><input type="checkbox" name="auto" value="1"> Jalankan otomatis melalui WP-Cron, maksimal satu profil setiap putaran 5 menit</label></p><button class="button button-primary" name="op" value="start">Buat kampanye</button></form>';}
		}
		echo '<h2>3. Status kampanye</h2>';
		if ( ! $s ) {
			echo '<p>Belum ada kampanye.</p></div>';
			return;}
		echo '<p>Status: <strong>' . esc_html( $s['status'] ) . '</strong> · ' . (int) $s['pos'] . ' / ' . count( $s['ids'] ) . ' · Otomatis: ' . ( ! empty( $s['auto'] ) ? 'Ya' : 'Tidak' ) . '</p><p>Checkpoint berikut: ' . esc_html( $s['ids'][ $s['pos'] ] ?? 'Selesai' ) . '</p>';
		if ( ! empty( $s['next'] ) ) {
			echo '<p>Untuk kelompok berikutnya setelah kampanye selesai, masukkan cursor <code>' . esc_html( $s['next'] ) . '</code> dengan filter yang sama.</p>';
		}if ( ! empty( $s['error'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $s['error'] ) . ' — Tidak ada percobaan ulang otomatis. Periksa penyebab dan izin sebelum melanjutkan.</p></div>';
		}
		if ( in_array( $s['status'], array( 'running', 'paused' ), true ) ) {
			self::form();
			self::consent();
			if ( 'running' === $s['status'] ) {
				echo '<button class="button button-primary" name="op" value="step">Proses 1 profil</button> <button class="button" name="op" value="pause">Jeda</button>';
			} else {
				echo '<button class="button button-primary" name="op" value="resume">Lanjutkan / coba ulang</button> <button class="button" name="op" value="skip">Lewati kandidat</button>';
			}
			echo ' <button class="button" name="op" value="cancel">Batalkan kampanye</button></form>';}
		echo '<h3>Log kampanye</h3><table class="widefat striped"><thead><tr><th>NPSN</th><th>Status</th><th>Detail</th><th>UTC</th></tr></thead><tbody>';
		foreach ( array_reverse( $s['events'] ) as $e ) {
			echo '<tr>';
			foreach ( array( 'npsn', 'status', 'message', 'time' ) as $k ) {
				echo '<td>' . esc_html( $e[ $k ] ) . '</td>';
			}echo '</tr>';
		}echo '</tbody></table><p>Log hanya menyimpan kampanye terakhir (maksimal 100 kandidat). Artikel terbit tetap terbit; draf tetap draf. Gunakan menu Sumber Resmi untuk pratinjau satu profil tanpa menyimpan.</p></div>';
	}
}
