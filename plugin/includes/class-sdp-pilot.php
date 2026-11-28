<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Local non-indexed clone only. Read-only preflight; use existing importer after review. */
final class SDP_Pilot {
	const FIELDS = array(
		'identity'      => array( 'npsn', 'nama', 'jenjang', 'status', 'naungan', 'provinsi', 'kabupaten', 'kecamatan', 'desa', 'alamat' ),
		'statistics'    => array( 'npsn', 'tahun_data', 'jumlah_siswa', 'jumlah_guru', 'jumlah_tendik', 'jumlah_rombel' ),
		'accreditation' => array( 'npsn', 'akreditasi', 'tahun_akreditasi', 'nomor_sk_akreditasi' ),
	);
	public static function allowed( $env, $public, $admin ) {
		return 'local' === $env && ! $public && $admin;}
	public static function parse( $raw, $kind, $sha ) {
		if ( ! isset( self::FIELDS[ $kind ] ) || ! is_string( $raw ) || strlen( $raw ) > 2097152 || ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) || ! hash_equals( $sha, hash( 'sha256', $raw ) ) || ! preg_match( '//u', $raw ) ) {
			return new WP_Error( 'input', 'Jenis, ukuran, encoding atau checksum salah.' );
		}
		$f = fopen( 'php://temp', 'w+' );
		fwrite( $f, $raw );
		rewind( $f );
		$head = fgetcsv( $f, 0, ',', '"', '' );
		$rows = array();
		try {
			if ( $head !== self::FIELDS[ $kind ] ) {
				return new WP_Error( 'header', 'Gunakan CSV keluaran prepare_pilot.py.' );
			}
			while ( ( $line = fgetcsv( $f, 0, ',', '"', '' ) ) !== false ) {
				if ( count( $rows ) >= 100 || count( $line ) !== count( $head ) ) {
					return new WP_Error( 'limit', 'Batas 100 baris atau kolom tidak cocok.' );
				}
				$r = array_filter( array_combine( $head, $line ), fn( $v )=>'' !== $v );
				$n = $r['npsn'] ?? '';
				if ( ! SDP_Engine::valid_npsn( $n ) || isset( $rows[ $n ] ) || SDP_SEO::demo( $r ) ) {
					return new WP_Error( 'identity', 'NPSN tidak valid, uji, atau duplikat.' );
				}
				foreach ( $r as $v ) {
					if ( preg_match( '/^[=+@-]|[\x00-\x1f]/', $v ) ) {
						return new WP_Error( 'value', 'Formula atau karakter kontrol.' );
					}
				}
				$required = 'identity' === $kind ? array( 'nama', 'jenjang', 'status', 'provinsi', 'kabupaten', 'kecamatan' ) : ( 'statistics' === $kind ? array( 'tahun_data' ) : array( 'akreditasi', 'tahun_akreditasi', 'nomor_sk_akreditasi' ) );
				foreach ( $required as $k ) {
					if ( ! isset( $r[ $k ] ) ) {
						return new WP_Error( 'value', 'Atribut wajib kosong: ' . $k );
					}
				}
				if ( 'identity' === $kind && ( ! in_array( strtoupper( $r['jenjang'] ), array_map( 'strtoupper', SDP_Directory::levels() ), true ) || ! in_array( strtoupper( $r['status'] ), array( 'NEGERI', 'SWASTA' ), true ) ) ) {
					return new WP_Error( 'value', 'Bentuk atau status tidak didukung.' );
				}
				if ( 'statistics' === $kind && ( ! SDP_Intelligence::period( $r['tahun_data'] ) || count( $r ) < 3 ) ) {
					return new WP_Error( 'period', 'Periode statistik atau angka tidak valid.' );
				}
				if ( 'accreditation' === $kind && ( ! preg_match( '/^(19|20)\d\d$/D', $r['tahun_akreditasi'] ) || ! in_array( strtoupper( $r['akreditasi'] ), array( 'A', 'B', 'C', 'D', 'TERAKREDITASI', 'TIDAK TERAKREDITASI', 'TIDAK TERAKREDITASI SEMENTARA' ), true ) ) ) {
					return new WP_Error( 'value', 'Akreditasi atau tahun tidak valid.' );
				}
				$clean = SDP_Directory::normalize( $r );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}$rows[ $n ] = $clean;
			}
		} finally {
			fclose( $f );}
		return $rows ?: new WP_Error( 'empty', 'Tidak ada rekaman.' );
	}
	public static function classify( $kind, $new, $old, $post_status, $locked = array() ) {
		if ( null === $old ) {
			return 'identity' === $kind ? 'new_identity' : 'missing_identity';
		}
		if ( ! is_array( $old ) || ( $old['npsn'] ?? '' ) !== $new['npsn'] || empty( $old['nama'] ) ) {
			return 'broken_profile';
		}
		if ( ! in_array( $post_status, array( 'draft', 'pending' ), true ) ) {
			return 'non_draft_review';
		}
		$fill = false;
		foreach ( $new as $k => $v ) {
			if ( 'npsn' === $k ) {
				continue;
			}if ( isset( $old[ $k ] ) && '' !== $old[ $k ] ) {
				if ( (string) $old[ $k ] !== $v ) {
						return 'conflict_review';
				}
			} else {
				if ( isset( $locked[ $k ] ) ) {
					return 'locked_review';
				}$fill = true;}
		}
		if ( 'statistics' === $kind ) {
			foreach ( SDP_Intelligence::STATS as $k ) {
				if ( 'tahun_data' === $k || ! isset( $old[ $k ] ) || '' === $old[ $k ] ) {
					continue;
				}if ( SDP_Intelligence::field_period( $old, $k ) !== $new['tahun_data'] ) {
					return 'period_review';
				}
			}
		}
		if ( 'accreditation' === $kind ) {
			foreach ( SDP_Intelligence::ACC as $k ) {
				if ( ! isset( $old[ $k ] ) || '' === $old[ $k ] ) {
					continue;
				}if ( SDP_Intelligence::field_period( $old, $k ) !== $new['tahun_akreditasi'] ) {
					return 'period_review';
				}
			}
		}
		return $fill ? 'fill_candidate' : 'unchanged';
	}
	public static function cli( $args, $assoc ) {
		if ( ! self::allowed( wp_get_environment_type(), get_option( 'blog_public' ), current_user_can( 'manage_options' ) ) ) {
			WP_CLI::error( 'Hanya clone local nonindeks; gunakan --user administrator.' );
		}
		$p = realpath( $args[0] ?? '' );
		if ( ! $p || ! is_file( $p ) || ! is_readable( $p ) ) {
			WP_CLI::error( 'CSV lokal tidak tersedia.' );
		}$raw = file_get_contents( $p, false, null, 0, 2097153 );
		$kind = $assoc['kind'] ?? '';
		$sha  = $assoc['sha256'] ?? '';
		$rows = self::parse( $raw, $kind, $sha );
		if ( is_wp_error( $rows ) ) {
			WP_CLI::error( $rows->get_error_message() );
		}
		global $wpdb;
		$table  = SDP_Directory::table();
		$out    = array();
		$counts = array();
		foreach ( $rows as $n => $r ) {
			$rec = $wpdb->get_row( $wpdb->prepare( "SELECT s.payload,s.post_id,p.post_status,p.post_type,p.post_password FROM $table s LEFT JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE s.npsn=%s", $n ), ARRAY_A );
			if ( $wpdb->last_error ) {
				WP_CLI::error( 'Gagal membaca database.' );
			}
			$old = $rec ? json_decode( $rec['payload'], true ) : null;
			if ( ! $rec ) {
				$orphan = get_posts(
					array(
						'post_type'      => SDP_Directory::TYPE,
						'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
						'fields'         => 'ids',
						'posts_per_page' => 1,
						'meta_key'       => '_sdp_npsn',
						'meta_value'     => $n,
					)
				);
				$state  = $orphan ? 'broken_profile' : self::classify( $kind, $r, null, '' );
			} elseif ( ! is_array( $old ) || SDP_Directory::TYPE !== $rec['post_type'] || '' !== $rec['post_password'] ) {
				$state = 'broken_profile';
			} else {
				$state = self::classify( $kind, $r, $old, $rec['post_status'], SDP_Intelligence::state( $old )['locks'] );
			}
				$out[]            = array(
					'npsn'   => (string) $n,
					'status' => $state,
				);
				$counts[ $state ] = ( $counts[ $state ] ?? 0 ) + 1;
		}
		WP_CLI::log(
			wp_json_encode(
				array(
					'status' => 'preview_only',
					'kind'   => $kind,
					'sha256' => $sha,
					'counts' => $counts,
					'rows'   => $out,
					'note'   => 'Tidak menyimpan profil. Tinjau semua konflik; preview bukan pengunci snapshot atau verifikasi sumber.',
				),
				JSON_PRETTY_PRINT
			)
		);
	}
}
