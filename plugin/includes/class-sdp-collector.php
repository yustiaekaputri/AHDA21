<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Bounded, administrator-configured public dataset ingestion. */
final class SDP_Collector {
	const HOOK      = 'sdp_collector_tick';
	const MAX_BYTES = 5242880;
	const MAX_ROWS  = 10000;
	const BATCH     = 20;
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sdp_queue';}
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'upgrade' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_sdp_collector', array( __CLASS__, 'action' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
	}
	public static function schedules( $s ) {
		$s['sdp_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'SDP setiap 5 menit',
		);
		return $s;}
	public static function upgrade() {
		if ( get_option( 'sdp_queue_version' ) !== '2.0.0' ) {
			self::install();
		} SDP_Performance::upgrade();}
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		$c = $wpdb->get_charset_collate();dbDelta(
			"CREATE TABLE $t (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        npsn varchar(8) NOT NULL,
        payload longtext NOT NULL,
        source_label varchar(191) NOT NULL,
        source_url varchar(1000) NOT NULL DEFAULT '',
        publish tinyint(1) NOT NULL DEFAULT 0,
        policy varchar(16) NOT NULL DEFAULT 'fill',
        status varchar(16) NOT NULL DEFAULT 'pending',
        attempts int unsigned NOT NULL DEFAULT 0,
        message text NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY status_id (status,id),
        KEY npsn (npsn)
    ) $c;"
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) === $t ) {
			update_option( 'sdp_queue_version', '2.0.0' );
		}}
	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( SDP_Performance::MIGRATION );}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Pengumpul Data', 'Pengumpul Data', 'manage_options', 'sdp-collector', array( __CLASS__, 'page' ) );}
	public static function defaults() {
		return array(
			'url'              => '',
			'label'            => '',
			'format'           => 'csv',
			'provinsi'         => '',
			'kabupaten'        => '',
			'kecamatan'        => '',
			'jenjang'          => '',
			'default_provinsi' => '',
			'default_jenjang'  => '',
			'json_path'        => '',
			'mapping'          => '',
			'publish'          => 0,
			'policy'           => 'fill',
			'schedule'         => 0,
			'kind'             => 'general',
		);}
	public static function config() {
		return array_merge( self::defaults(), (array) get_option( 'sdp_collector_config', array() ) );}
	public static function key( $s ) {
		$s = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $s );
		return trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( trim( $s ) ) ), '_' );}
	public static function aliases() {
		return array(
			'npsn'                => array( 'nomor_pokok_sekolah_nasional', 'nomor_pokok_satuan_pendidikan_nasional', 'npsn_sekolah', 'npsn_madrasah' ),
			'nama'                => array( 'nama_sekolah', 'nama_satuan_pendidikan', 'satuan_pendidikan', 'nama_satpen', 'nama_madrasah' ),
			'jenjang'             => array( 'bentuk', 'bentuk_pendidikan', 'bentuk_sekolah', 'jenis_madrasah', 'jenjang_madrasah', 'jenjang_pendidikan' ),
			'status'              => array( 'status_sekolah', 'status_satuan_pendidikan' ),
			'naungan'             => array( 'pembina', 'kementerian' ),
			'provinsi'            => array( 'nama_provinsi', 'propinsi' ),
			'kabupaten'           => array( 'kota_kab', 'kab_kota', 'kabupaten_kota', 'nama_kabupaten_kota', 'nama_kabupaten', 'nama_kota' ),
			'kecamatan'           => array( 'nama_kecamatan', 'bps_nama_kecamatan', 'kemendagri_nama_kecamatan' ),
			'desa'                => array( 'kelurahan', 'desa_kelurahan', 'nama_desa', 'nama_kelurahan' ),
			'alamat'              => array( 'alamat_sekolah', 'alamat_jalan' ),
			'jumlah_siswa'        => array( 'peserta_didik', 'jumlah_peserta_didik', 'total_siswa', 'jumlah_pd', 'jumlah_murid' ),
			'jumlah_rombel'       => array( 'rombel', 'rombongan_belajar', 'jumlah_rombongan_belajar' ),
			'jumlah_guru'         => array( 'guru', 'total_guru' ),
			'jumlah_tendik'       => array( 'tendik', 'tenaga_kependidikan' ),
			'luas_tanah'          => array( 'luas_tanah_m2', 'luas_tanah_m' ),
			'akreditasi'          => array( 'peringkat_akreditasi', 'status_akreditasi', 'nilai_huruf' ),
			'tahun_data'          => array( 'tahun', 'semester' ),
			'nsm'                 => array( 'nomor_statistik_madrasah' ),
			'tahun_akreditasi'    => array( 'tahun_penilaian', 'tahun_akreditasi_sekolah' ),
			'nomor_sk_akreditasi' => array( 'no_sk_akreditasi', 'nomor_sk', 'no_sk' ),
			'sertifikat_url'      => array( 'url_sertifikat', 'link_sertifikat' ),
			'siswa_laki_laki'     => array( 'pd_laki_laki', 'jumlah_siswa_laki_laki', 'jumlah_peserta_didik_laki_laki' ),
			'siswa_perempuan'     => array( 'pd_perempuan', 'jumlah_siswa_perempuan', 'jumlah_peserta_didik_perempuan' ),
			'latitude'            => array( 'lintang' ),
			'longitude'           => array( 'bujur' ),
			'website'             => array( 'website_sekolah' ),
			'email'               => array( 'email_sekolah' ),
			'telepon'             => array( 'nomor_telepon' ),
		);}
	public static function map_row( $row, $c ) {
		$r = array();
		foreach ( $row as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$r[ self::key( $k ) ] = trim( (string) $v );
			}
		}
		$mapping = array();
		if ( '' !== $c['mapping'] ) {
			$mapping = json_decode( $c['mapping'], true );
			if ( ! is_array( $mapping ) ) {
				return new WP_Error( 'mapping', 'Mapping JSON tidak valid.' );
			}
		}
		$out     = array();
		$aliases = self::aliases();
		foreach ( SDP_Directory::labels() as $k => $label ) {
			if ( in_array( $k, array( 'sumber', 'sumber_url' ), true ) ) {
				continue;
			}
			// Explicit mapping overrides aliases; prefer specific school form over broad education level.
			$keys = isset( $mapping[ $k ] ) ? array( self::key( $mapping[ $k ] ) ) : ( 'jenjang' === $k ? array_merge( $aliases[ $k ], array( $k ) ) : array_merge( array( $k ), $aliases[ $k ] ?? array() ) );
			foreach ( $keys as $a ) {
				if ( ! isset( $r[ $a ] ) || in_array( strtolower( $r[ $a ] ), array( '', '-', 'null', 'n/a', 'tidak tersedia' ), true ) ) {
					continue;
				}$out[ $k ] = $r[ $a ];
				break;}
		}
		foreach ( array( 'provinsi', 'jenjang' ) as $k ) {
			if ( empty( $out[ $k ] ) && '' !== $c[ 'default_' . $k ] ) {
				$out[ $k ] = $c[ 'default_' . $k ];
			}
		}
		$kind = $c['kind'] ?? 'general';
		if ( in_array( $kind, array( 'statistics', 'accreditation' ), true ) ) {
			$n = strtoupper( $out['npsn'] ?? '' );
			if ( ! preg_match( '/^[A-Z0-9]{8}$/D', $n ) ) {
				return new WP_Error( 'npsn', 'Dataset pelengkap wajib memuat NPSN per sekolah.' );
			}
			global $wpdb;
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT provinsi,kabupaten,kecamatan,jenjang FROM ' . SDP_Directory::table() . ' WHERE npsn=%s', $n ), ARRAY_A );
			if ( ! $existing ) {
				return null;
			}
			foreach ( array( 'provinsi', 'kabupaten', 'kecamatan', 'jenjang' ) as $k ) {
				if ( '' !== $c[ $k ] && ! self::region_match( $existing[ $k ] ?? '', $c[ $k ], $k ) ) {
					return null;
				}
			}
			$allowed = 'statistics' === $kind ? array( 'npsn', 'tahun_data', 'jumlah_siswa', 'siswa_laki_laki', 'siswa_perempuan', 'jumlah_guru', 'jumlah_tendik', 'guru_sertifikasi', 'jumlah_rombel', 'tanggal_data' ) : array( 'npsn', 'akreditasi', 'tahun_akreditasi', 'nomor_sk_akreditasi', 'sertifikat_url' );
			$out     = array_intersect_key( $out, array_flip( $allowed ) );
			if ( count( array_diff( array_keys( $out ), array( 'npsn', 'tahun_data', 'tanggal_data' ) ) ) === 0 ) {
				return new WP_Error( 'empty_fields', 'Tidak ada atribut pelengkap yang dikenali.' );
			}
		} else {
			foreach ( array( 'provinsi', 'kabupaten', 'kecamatan', 'jenjang' ) as $k ) {
				if ( '' !== $c[ $k ] && ! self::region_match( $out[ $k ] ?? '', $c[ $k ], $k ) ) {
							return null;
				}
			}
		}
		$out['sumber'] = $c['label'];
		if ( '' !== $c['url'] ) {
			$out['sumber_url'] = $c['url'];
		}
		return SDP_Directory::normalize( $out );
	}
	public static function region_match( $a, $b, $k ) {
		$norm = function ( $v ) use ( $k ) {
			$v = strtolower( trim( $v ) );
			if ( 'provinsi' === $k ) {
				$v = preg_replace( '/^(provinsi|propinsi|prov\.)\s+/', '', $v );
			}if ( 'kecamatan' === $k ) {
				$v = preg_replace( '/^(kecamatan|kec\.)\s+/', '', $v );
			}if ( 'kabupaten' === $k ) {
				$v = preg_replace( '/^(kabupaten|kab\.)\s+/', 'kab ', $v );
			}return preg_replace( '/\s+/', ' ', $v );
		};
		return $norm( $a ) === $norm( $b );
	}
	public static function parse( $body, $c ) {
		if ( ! is_string( $body ) || strlen( $body ) > SDP_Resource::cap( self::MAX_BYTES, 2097152 ) ) {
			return new WP_Error( 'size', 'Dataset melebihi batas mode aktif. Pecah per wilayah.' );
		}
		$rows    = array();
		$errors  = array();
		$skipped = 0;
		$seen    = 0;
		$accept  = function ( $raw ) use ( &$rows, &$errors, &$skipped, &$seen, $c ) {
			if ( ++$seen > SDP_Resource::cap( self::MAX_ROWS, 1000 ) ) {
				return false;
			}
			if ( ! is_array( $raw ) ) {
				$errors[] = "Rekaman $seen bukan objek.";
				return true;}
			$r = self::map_row( $raw, $c );
			if ( null === $r ) {
				++$skipped;
				return true;
			}if ( is_wp_error( $r ) ) {
				$errors[] = "Rekaman $seen: " . $r->get_error_message();
				return true;
			}$rows[] = $r;
			return true;
		};
		if ( 'json' === $c['format'] ) {
			$data = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $body ), true, 64, JSON_BIGINT_AS_STRING );
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'json', 'JSON tidak valid.' );
			}
			foreach ( array_filter( explode( '.', $c['json_path'] ), 'strlen' ) as $part ) {
				if ( ! is_array( $data ) || ! array_key_exists( $part, $data ) ) {
					return new WP_Error( 'path', 'Jalur JSON tidak ditemukan.' );
				}$data = $data[ $part ];}
			if ( ! is_array( $data ) || array_values( $data ) !== $data ) {
				return new WP_Error( 'json', 'JSON harus berupa daftar objek; isi jalur JSON jika diperlukan.' );
			}
			foreach ( $data as $raw ) {
				if ( ! $accept( $raw ) ) {
					return new WP_Error( 'limit', 'Maksimal ' . SDP_Resource::cap( self::MAX_ROWS, 1000 ) . ' rekaman per dataset.' );
				}
			}
		} else {
			$body = preg_replace( '/^\xEF\xBB\xBF/', '', $body );
			if ( ! preg_match( '//u', $body ) ) {
				return new WP_Error( 'encoding', 'CSV harus UTF-8.' );
			}
			$fp = fopen( 'php://temp', 'w+' );
			fwrite( $fp, $body );
			rewind( $fp );
			$first = fgets( $fp );
			rewind( $fp );
			$delim  = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',';
			$header = fgetcsv( $fp, 0, $delim, '"', '' );
			if ( ! is_array( $header ) || count( $header ) < 2 ) {
				fclose( $fp );
				return new WP_Error( 'header', 'CSV tidak valid; gunakan tautan unduh langsung, bukan halaman HTML.' );}
			$header = array_map( array( __CLASS__, 'key' ), $header );
			if ( count( array_unique( $header ) ) !== count( $header ) ) {
				fclose( $fp );
				return new WP_Error( 'header', 'Kolom CSV bertabrakan setelah normalisasi.' );}
			while ( ( $v = fgetcsv( $fp, 0, $delim, '"', '' ) ) !== false ) {
				if ( count( $v ) === 1 && trim( (string) $v[0] ) === '' ) {
					continue;
				}if ( count( $v ) !== count( $header ) ) {
					fclose( $fp );
					return new WP_Error( 'columns', 'Jumlah kolom CSV tidak konsisten. Tidak ada data dimasukkan.' );
				}if ( ! $accept( array_combine( $header, $v ) ) ) {
					fclose( $fp );
					return new WP_Error( 'limit', 'Maksimal ' . SDP_Resource::cap( self::MAX_ROWS, 1000 ) . ' rekaman per dataset.' );}
			}
			fclose( $fp );
		}
		return array(
			'rows'    => $rows,
			'skipped' => $skipped,
			'errors'  => $errors,
			'seen'    => $seen,
		);
	}
	public static function fetch( $c ) {
		if ( ! preg_match( '~^https://~i', $c['url'] ) || ! wp_http_validate_url( $c['url'] ) ) {
			return new WP_Error( 'url', 'Gunakan URL HTTPS publik langsung, tanpa login.' );
		}
		$res = wp_safe_remote_get(
			$c['url'],
			array(
				'timeout'             => 25,
				'redirection'         => 0,
				'limit_response_size' => SDP_Resource::cap( self::MAX_BYTES, 2097152 ) + 1,
				'headers'             => array( 'Accept' => 'json' === $c['format'] ? 'application/json' : 'text/csv, text/plain, application/octet-stream' ),
				'user-agent'          => 'SekolahDirectoryPro/2.0',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}$status = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $status ) {
			return new WP_Error( 'http', 'Sumber mengembalikan HTTP ' . $status . '.' );
		}
		$type = wp_remote_retrieve_header( $res, 'content-type' );
		if ( is_string( $type ) && stripos( $type, 'text/html' ) !== false ) {
			return new WP_Error( 'html', 'URL mengembalikan halaman HTML, bukan dataset.' );
		}
		$body = wp_remote_retrieve_body( $res );
		if ( ! is_string( $body ) || strlen( $body ) > SDP_Resource::cap( self::MAX_BYTES, 2097152 ) ) {
			return new WP_Error( 'limit', 'Respons dataset melebihi batas mode aktif.' );
		}return $body;
	}
	public static function lock( $fn ) {
		global $wpdb;
		$key = 'sdp_collect_' . md5( self::table() );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) !== '1' ) {
			return new WP_Error( 'busy', 'Pengumpul sedang berjalan. Coba lagi.' );
		}try {
			return $fn();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );}}
	public static function pending() {
		global $wpdb;
		$n = $wpdb->get_var( 'SELECT 1 FROM ' . self::table() . " WHERE status='pending' LIMIT 1" );
		return ! empty( $wpdb->last_error ) ? 1 : (int) (bool) $n;}
	public static function stage( $body, $c ) {
		global $wpdb;
		$parsed = self::parse( $body, $c );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}if ( ! $parsed['rows'] ) {
			return new WP_Error( 'empty', 'Tidak ada data valid yang cocok. ' . implode( ' ', array_slice( $parsed['errors'], 0, 3 ) ) );
		}
		$count  = 0;
		$failed = 0;
		$now    = current_time( 'mysql', true );
		$t      = self::table();
		foreach ( array_chunk( $parsed['rows'], 50 ) as $chunk ) {
			$params = array();
			$values = array();
			foreach ( $chunk as $r ) {
				$values[] = '(%s,%s,%s,%s,%d,%s,%s,%s,%s,%s)';
				array_push( $params, $r['npsn'], wp_json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ), $c['label'], $c['url'], $c['publish'], $c['policy'], 'pending', '', $now, $now );
			} $sql = "INSERT INTO $t (npsn,payload,source_label,source_url,publish,policy,status,message,created_at,updated_at) VALUES " . implode( ',', $values );
			$ok    = $wpdb->query( $wpdb->prepare( $sql, $params ) );
			if ( false === $ok ) {
				++$failed;
				break;
			}$count += (int) $ok;}
		$msg = "$count masuk antrean, {$parsed['skipped']} tidak cocok filter, " . count( $parsed['errors'] ) . ' tidak valid.';
		if ( $failed ) {
			$msg .= ' Penyimpanan terhenti karena kesalahan database; sebagian data mungkin belum masuk.';
		}if ( $parsed['errors'] ) {
			$msg .= ' Contoh: ' . implode( ' | ', array_slice( $parsed['errors'], 0, 5 ) );
		}return $msg;
	}
	public static function process() {
		global $wpdb;
		$t    = self::table();
		$jobs = $wpdb->get_results( "SELECT * FROM $t WHERE status='pending' ORDER BY id LIMIT " . SDP_Resource::cap( self::BATCH, 5 ), ARRAY_A );
		if ( ! is_array( $jobs ) || ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'database', 'Antrean tidak dapat dibaca.' );
		}$done = 0;
		$start = microtime( true );
		foreach ( $jobs as $j ) {
			if ( microtime( true ) - $start > SDP_Resource::cap( 15, 5 ) ) {
				break;
			}$r       = json_decode( $j['payload'], true );
			$result   = is_array( $r ) ? SDP_Directory::save(
				$r,
				(bool) $j['publish'],
				$j['policy'],
				array(
					'label' => $j['source_label'],
					'url'   => $j['source_url'],
				)
			) : new WP_Error( 'payload', 'Payload tidak valid.' );
			$attempts = (int) $j['attempts'] + 1;
			$status   = is_wp_error( $result ) ? ( ( $result->get_error_code() === 'busy' && $attempts < 3 ) ? 'pending' : 'failed' ) : 'done';
			$msg      = is_wp_error( $result ) ? $result->get_error_message() : ( ! empty( $result['unchanged'] ) ? 'Tidak ada perubahan' : ( $result['updated'] ? 'Profil diperbarui' : 'Profil dibuat' ) );
			if ( ! is_wp_error( $result ) && ! empty( $result['held'] ) ) {
				$msg .= '; publikasi ditahan oleh aturan SEO';
			}$ok = $wpdb->update(
				$t,
				array(
					'status'     => $status,
					'attempts'   => $attempts,
					'message'    => $msg,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $j['id'] )
			);
			if ( false === $ok ) {
				break;
			}++$done;}
		return "$done rekaman diproses.";
	}
	public static function tick() {
		if ( ! SDP_Performance::guard_legacy() ) {
			return;
		}self::lock(
			function () {
				$processed = self::pending() ? self::process() : 'Tidak ada antrean.';
				if ( is_wp_error( $processed ) ) {
					update_option( 'sdp_collector_last_message', $processed->get_error_message(), false );
						return;
				}$c = self::config();
				if ( self::pending() || ! SDP_Resource::scheduled_network() ) {
					return;
				}if ( ! $c['schedule'] || ! $c['url'] || time() - (int) get_option( 'sdp_collector_last_attempt', 0 ) < DAY_IN_SECONDS ) {
					SDP_Sources::tick();
					return;
				}update_option( 'sdp_collector_last_attempt', time(), false );
				$body = self::fetch( $c );
				$r    = is_wp_error( $body ) ? $body : self::stage( $body, $c );
				update_option( 'sdp_collector_last_message', is_wp_error( $r ) ? $r->get_error_message() : $r, false );
			}
		);}
	public static function read_config() {
		$raw = wp_unslash( $_POST['cfg'] ?? array() );
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'config', 'Konfigurasi tidak valid.' );
		}$c = self::defaults();
		foreach ( $c as $k => $v ) {
			if ( isset( $raw[ $k ] ) && is_scalar( $raw[ $k ] ) ) {
				$c[ $k ] = sanitize_text_field( (string) $raw[ $k ] );
			}
		}
		$original_url = $c['url'];
		$c['url']     = esc_url_raw( $c['url'], array( 'https' ) );
		if ( '' !== $original_url && '' === $c['url'] ) {
			return new WP_Error( 'url', 'URL HTTPS tidak valid.' );
		}if ( '' !== $c['url'] && ( ! preg_match( '~^https://~i', $c['url'] ) || ! wp_http_validate_url( $c['url'] ) || strlen( $c['url'] ) > 1000 ) ) {
			return new WP_Error( 'url', 'URL HTTPS publik tidak valid.' );
		}
		if ( trim( $c['label'] ) === '' || strlen( $c['label'] ) > 191 ) {
			return new WP_Error( 'label', 'Nama sumber wajib, maksimal 191 byte.' );
		}
		$c['kind']     = in_array( $c['kind'], array( 'general', 'statistics', 'accreditation', 'madrasah' ), true ) ? $c['kind'] : 'general';
		$c['format']   = 'json' === $c['format'] ? 'json' : 'csv';
		$c['policy']   = 'overwrite' === $c['policy'] ? 'overwrite' : 'fill';
		$c['publish']  = ! empty( $raw['publish'] ) && current_user_can( 'publish_posts' ) ? 1 : 0;
		$c['schedule'] = ! empty( $raw['schedule'] ) ? 1 : 0;
		if ( $c['schedule'] && ! $c['url'] ) {
			return new WP_Error( 'schedule', 'Jadwal membutuhkan URL dataset.' );
		}
		if ( '' !== $c['mapping'] ) {
			$m = json_decode( $c['mapping'], true );
			if ( ! is_array( $m ) ) {
				return new WP_Error( 'mapping', 'Mapping harus objek JSON.' );
			}foreach ( $m as $k => $v ) {
				if ( ! isset( SDP_Directory::labels()[ $k ] ) || ! is_string( $v ) ) {
					return new WP_Error( 'mapping', 'Kolom mapping tidak valid.' );
				}
			}
		}
		return $c;
	}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp_collector' );
		$op = sanitize_key( $_POST['op'] ?? '' );
		$r  = self::lock(
			function () use ( $op ) {
				global $wpdb;
				$t = self::table();
				if ( 'process' === $op ) {
					return self::process();
				}
				if ( 'retry' === $op ) {
					$n = $wpdb->query( "UPDATE $t SET status='pending', attempts=0 WHERE status='failed'" );
					return false === $n ? new WP_Error( 'db', 'Gagal mengulang.' ) : "$n rekaman gagal diantrekan ulang.";}
				if ( 'clear' === $op ) {
					$n = $wpdb->query( "DELETE FROM $t WHERE status IN ('done','failed')" );
					return false === $n ? new WP_Error( 'db', 'Gagal membersihkan log.' ) : "$n log selesai/gagal dihapus.";}
				if ( 'cancel' === $op ) {
					$n = $wpdb->query( "DELETE FROM $t WHERE status='pending'" );
					return false === $n ? new WP_Error( 'db', 'Gagal membatalkan.' ) : "$n antrean dibatalkan; profil yang sudah tersimpan tidak dihapus.";}
				$c = self::read_config();
				if ( is_wp_error( $c ) ) {
					return $c;
				}
				if ( 'save' === $op ) {
					if ( $c['schedule'] && empty( $_POST['authorized'] ) ) {
						return new WP_Error( 'permission', 'Konfirmasi izin penggunaan dataset sebelum mengaktifkan jadwal.' );
					}update_option( 'sdp_collector_config', $c, false );
					return 'Konfigurasi tersimpan.';}
				if ( ! in_array( $op, array( 'remote', 'upload', 'preview' ), true ) ) {
					return new WP_Error( 'op', 'Operasi tidak dikenal.' );
				}
				if ( empty( $_POST['authorized'] ) ) {
					return new WP_Error( 'permission', 'Konfirmasi izin penggunaan dataset terlebih dahulu.' );
				}
				if ( 'preview' !== $op && self::pending() ) {
					return new WP_Error( 'pending', 'Selesaikan atau batalkan antrean saat ini sebelum menambah dataset.' );
				}
				$f       = $_FILES['dataset'] ?? array();
				$hasfile = isset( $f['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $f['error'];
				if ( 'upload' === $op || ( 'preview' === $op && $hasfile ) ) {
					if ( ( $f['error'] ?? 1 ) !== UPLOAD_ERR_OK || ! is_uploaded_file( $f['tmp_name'] ?? '' ) || ( $f['size'] ?? 0 ) > SDP_Resource::cap( self::MAX_BYTES, 2097152 ) ) {
						return new WP_Error( 'file', 'File tidak valid atau melebihi batas mode aktif.' );
					}$body = file_get_contents( $f['tmp_name'] );
					if ( false === $body ) {
						return new WP_Error( 'file', 'Gagal membaca file.' );
					}
				} else {
					$body = self::fetch( $c );
					if ( is_wp_error( $body ) ) {
						return $body;
					}
				}
				if ( 'preview' === $op ) {
					$p = self::parse( $body, $c );
					if ( is_wp_error( $p ) ) {
						return $p;
					}set_transient(
						'sdp_preview_' . get_current_user_id(),
						array(
							'seen'    => $p['seen'],
							'valid'   => count( $p['rows'] ),
							'skipped' => $p['skipped'],
							'errors'  => array_slice( $p['errors'], 0, 10 ),
							'samples' => array_slice( $p['rows'], 0, 3 ),
						),
						300
					);
					return 'Pratinjau siap; belum ada profil yang diubah.';}
				if ( 'remote' === $op ) {
					update_option( 'sdp_collector_last_attempt', time(), false );
				}return self::stage( $body, $c );
			}
		);
		if ( ! is_wp_error( $r ) ) {
			SDP_Performance::reconcile();
		}set_transient(
			'sdp_collector_notice_' . get_current_user_id(),
			array(
				'error'   => is_wp_error( $r ),
				'message' => is_wp_error( $r ) ? $r->get_error_message() : $r,
			),
			120
		);
		wp_safe_redirect( admin_url( 'admin.php?page=sdp-collector' ) );
		exit;
	}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}global $wpdb;
		$c      = self::config();
		$t      = self::table();
		$counts = array();
		foreach ( $wpdb->get_results( "SELECT status,COUNT(*) n FROM $t GROUP BY status", ARRAY_A ) ?: array() as $row ) {
			$counts[ $row['status'] ] = $row['n'];
		}$key   = 'sdp_collector_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );
		echo '<div class="wrap"><h1>Automatic Data Collector</h1><p>Impor dataset publik CSV/JSON, validasi, lalu buat atau lengkapi profil berdasarkan NPSN. Gunakan sumber dengan izin penggunaan ulang.</p>';
		if ( $notice ) {
			echo '<div class="notice ' . ( $notice['error'] ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
		foreach ( array(
			'pending' => 'Antrean',
			'done'    => 'Selesai',
			'failed'  => 'Gagal',
		) as $k => $label ) {
			echo '<div style="background:white;border:1px solid #ccd0d4;padding:16px;min-width:140px"><strong>' . esc_html( $label ) . '</strong><h2>' . esc_html( $counts[ $k ] ?? 0 ) . '</h2></div>';
		}echo '</div><p>Sinkronisasi harian: ' . ( $c['schedule'] ? 'aktif' : 'nonaktif' ) . '. Antrean diproses maksimal sesuai preset tiap putaran WP-Cron; jadwal berhenti ketika tidak diperlukan.</p><p>Sinkronisasi terakhir: ' . esc_html( get_option( 'sdp_collector_last_message', 'Belum ada' ) ) . '</p>';
		echo '<h2>Konfigurasi sumber dan filter</h2><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp_collector">';
		wp_nonce_field( 'sdp_collector' );
		echo '<table class="form-table">';
		foreach ( array(
			'label'            => 'Nama sumber (wajib)',
			'url'              => 'URL HTTPS dataset langsung',
			'json_path'        => 'Jalur daftar JSON (mis. data.items)',
			'mapping'          => 'Mapping kolom JSON (opsional)',
			'default_provinsi' => 'Provinsi bawaan jika tidak ada kolom',
			'default_jenjang'  => 'Jenjang bawaan jika tidak ada kolom',
			'provinsi'         => 'Filter provinsi',
			'kabupaten'        => 'Filter kabupaten/kota',
			'kecamatan'        => 'Filter kecamatan',
			'jenjang'          => 'Filter jenjang',
		) as $k => $label ) {
			echo '<tr><th><label for="sdp-c-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input class="large-text" id="sdp-c-' . esc_attr( $k ) . '" name="cfg[' . esc_attr( $k ) . ']" value="' . esc_attr( $c[ $k ] ) . '"></td></tr>';
		}
		echo '<tr><th>Format</th><td><select name="cfg[format]"><option value="csv" ' . selected( $c['format'], 'csv', false ) . '>CSV</option><option value="json" ' . selected( $c['format'], 'json', false ) . '>JSON</option></select></td></tr><tr><th>Aturan gabung</th><td><select name="cfg[policy]"><option value="fill" ' . selected( $c['policy'], 'fill', false ) . '>Isi kolom kosong (aman)</option><option value="overwrite" ' . selected( $c['policy'], 'overwrite', false ) . '>Perbarui kolom yang dikirim</option></select></td></tr></table><p>Filter mencocokkan nama tanpa membedakan huruf besar/kecil, dengan normalisasi awalan wilayah tertentu; nilai bawaan hanya untuk dataset dengan cakupan yang telah dipastikan. Mapping misalnya <code>{"nama":"Nama Satuan Pendidikan","jenjang":"Bentuk"}</code>.</p>';
		echo '<p><label><input type="checkbox" name="cfg[publish]" value="1" ' . checked( $c['publish'], 1, false ) . '> Terbitkan profil baru langsung (default draf)</label></p><p><label><input type="checkbox" name="cfg[schedule]" value="1" ' . checked( $c['schedule'], 1, false ) . '> Sinkronisasi URL setiap 24 jam, setelah antrean kosong</label></p><p><label><input type="checkbox" name="authorized" value="1"> Saya berhak menggunakan dan memublikasikan dataset ini</label></p><p>File lokal: <input type="file" name="dataset" accept=".csv,.json"></p><p><button class="button" name="op" value="save">Simpan konfigurasi</button> <button class="button" name="op" value="preview">Pratinjau</button> <button class="button button-primary" name="op" value="remote">Ambil URL dan antrekan</button> <button class="button" name="op" value="upload">Unggah dan antrekan</button></p></form><p>Pratinjau dan antrekan menggunakan konfigurasi formulir saat ini. Simpan konfigurasi terlebih dahulu agar tetap tersedia setelah halaman dimuat ulang. Batas 5 MB / 10.000 rekaman per dataset.</p>';
		$p = get_transient( 'sdp_preview_' . get_current_user_id() );
		if ( $p ) {
			echo '<h2>Pratinjau terakhir (belum disimpan)</h2><p>' . esc_html( $p['seen'] . ' dibaca, ' . $p['valid'] . ' valid, ' . $p['skipped'] . ' tidak cocok.' ) . '</p><pre style="white-space:pre-wrap">' . esc_html( wp_json_encode( $p['samples'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
			foreach ( $p['errors'] as $error ) {
				echo '<p>' . esc_html( $error ) . '</p>';
			}
		}
		echo '<h2>Kontrol antrean</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp_collector">';
		wp_nonce_field( 'sdp_collector' );
		foreach ( array(
			'process' => 'Proses ' . SDP_Resource::cap( 20, 5 ) . ' sekarang',
			'retry'   => 'Ulangi gagal',
			'cancel'  => 'Batalkan antrean',
			'clear'   => 'Bersihkan log selesai/gagal',
		) as $k => $v ) {
			echo '<button class="button" name="op" value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</button> ';
		}echo '</form><h2>Log 50 rekaman terakhir</h2><table class="widefat striped"><thead><tr><th>NPSN</th><th>Sumber</th><th>Status</th><th>Pesan</th><th>Waktu UTC</th></tr></thead><tbody>';
		foreach ( $wpdb->get_results( "SELECT npsn,source_label,status,message,updated_at FROM $t ORDER BY id DESC LIMIT 50", ARRAY_A ) ?: array() as $r ) {
			echo '<tr>';
			foreach ( $r as $v ) {
				echo '<td>' . esc_html( $v ) . '</td>';
			}echo '</tr>';
		}echo '</tbody></table><h2>Sumber resmi</h2><p><a href="https://data.kemendikdasmen.go.id/data-induk/satpen/daftar">Data induk satuan pendidikan</a> | <a href="https://data.kemendikdasmen.go.id/data-induk/pd/jumlah-per-satuan-pendidikan">Data peserta didik</a>. Unduh dataset yang diizinkan; halaman portal bukan URL feed langsung.</p></div>';
	}
}
