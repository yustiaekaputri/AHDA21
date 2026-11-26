<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** SEO based on recorded facts; optional AI output is a private editorial preview. */
final class SDP_SEO {
	const SCORE_FIELDS = array( 'alamat', 'desa', 'kode_pos', 'akreditasi', 'tahun_akreditasi', 'sk_operasional', 'tanggal_pendirian', 'website', 'visi', 'misi', 'sejarah', 'kurikulum', 'tahun_data', 'jumlah_siswa', 'jumlah_guru', 'jumlah_rombel', 'luas_tanah', 'ruang_kelas', 'perpustakaan', 'internet' );
	const SCOPE        = array( 'provinsi', 'kabupaten', 'kecamatan', 'jenjang' );
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_sdp_seo', array( __CLASS__, 'action' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'title_parts' ) );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 5 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 99 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'append' ), 20 );
	}
	public static function config() {
		return array_merge(
			array(
				'guard'   => 1,
				'faq'     => 1,
				'related' => 1,
				'schema'  => 1,
				'style'   => 'full',
			),
			(array) get_option( 'sdp_seo_config', array() )
		);}
	public static function present( $d, $k ) {
		return isset( $d[ $k ] ) && is_scalar( $d[ $k ] ) && trim( (string) $d[ $k ] ) !== '' && ! in_array( strtolower( trim( (string) $d[ $k ] ) ), array( '-', 'n/a', 'null', 'data belum tersedia', 'tidak diketahui', 'belum tersedia' ), true );}
	public static function score( $d ) {
		$n = 0;
		foreach ( self::SCORE_FIELDS as $k ) {
			if ( self::present( $d, $k ) ) {
				++$n;
			}
		}return (int) round( 100 * $n / count( self::SCORE_FIELDS ) );}
	public static function demo( $d ) {
		return preg_match( '/^(DEMO|SIMU|TEST)/i', $d['npsn'] ?? '' ) || preg_match( '/\bfiktif\b|\bsimulasi\b/i', ( $d['nama'] ?? '' ) . ' ' . ( $d['sumber'] ?? '' ) );}
	public static function ready( $d ) {
		foreach ( array( 'npsn', 'nama', 'jenjang', 'status', 'provinsi', 'kabupaten', 'kecamatan' ) as $k ) {
			if ( ! self::present( $d, $k ) ) {
					return false;
			}
		}return self::score( $d ) >= 35 && ! self::demo( $d );}
	public static function status( $d, $old, $publish ) {
		if ( in_array( $old, array( 'private', 'trash' ), true ) ) {
			return $old;
		}if ( self::demo( $d ) && ! ( wp_get_environment_type() === 'local' && ! get_option( 'blog_public' ) ) ) {
			return 'draft';
		}if ( 'future' === $old ) {
			return $old;
		}if ( ! $publish ) {
			return $old ?: 'draft';
		}if ( 'publish' === $old ) {
			return 'publish';
		}return self::config()['guard'] && ! self::ready( $d ) ? ( $old && 'future' !== $old ? $old : 'draft' ) : 'publish';}
	public static function sync( $id, $d ) {
		update_post_meta( $id, '_sdp_seo_ready', self::ready( $d ) ? '1' : '0' );
		update_post_meta( $id, '_sdp_seo_score', self::score( $d ) );}
	public static function lookup( $id ) {
		global $wpdb;
		static $cache = array();
		$id           = (int) $id;
		if ( ! isset( $cache[ $id ] ) ) {
			$raw          = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . SDP_Directory::table() . ' WHERE post_id=%d LIMIT 1', $id ) );
			$cache[ $id ] = json_decode( (string) $raw, true ) ?: array();
		}return $cache[ $id ];}
	public static function by_npsn( $n ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SDP_Directory::table() . ' WHERE npsn=%s', strtoupper( $n ) ), ARRAY_A );}
	public static function current() {
		return is_singular( SDP_Directory::TYPE ) && ! post_password_required() ? self::lookup( get_queried_object_id() ) : array();}
	public static function short( $s, $n ) {
		$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ) ?? '' );
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $s, 'UTF-8' ) > $n ? rtrim( mb_substr( $s, 0, $n - 1, 'UTF-8' ) ) . '…' : $s;
		}preg_match_all( '/./us', $s, $chars );
		return count( $chars[0] ) > $n ? rtrim( implode( '', array_slice( $chars[0], 0, $n - 1 ) ) ) . '…' : $s;}
	public static function title( $d ) {
		return self::short( 'Profil ' . ( $d['nama'] ?? 'Sekolah' ) . ' – NPSN ' . ( $d['npsn'] ?? '' ) . ( self::present( $d, 'kabupaten' ) ? ' | ' . $d['kabupaten'] : '' ), 90 );}
	public static function summary( $d ) {
		$place = implode( ', ', array_filter( array_map( static fn( $k )=>self::present( $d, $k ) ? $d[ $k ] : '', array( 'kecamatan', 'kabupaten', 'provinsi' ) ) ) );
		$s     = ( $d['nama'] ?? 'Sekolah' ) . ' (NPSN ' . ( $d['npsn'] ?? '' ) . ')';
		if ( $place ) {
			$s .= ' berlokasi di ' . $place;
		}$s   .= '.';
		$facts = array();
		foreach ( array(
			'jenjang'      => 'Jenjang',
			'status'       => 'Status',
			'akreditasi'   => 'Akreditasi',
			'jumlah_siswa' => 'Jumlah siswa',
			'jumlah_guru'  => 'Jumlah guru',
			'luas_tanah'   => 'Luas tanah',
		) as $k => $label ) {
			if ( self::present( $d, $k ) ) {
				$facts[] = $label . ': ' . $d[ $k ] . ( 'luas_tanah' === $k ? ' m²' : '' ) . SDP_Intelligence::period_note( $d, $k );
			}
		}if ( $facts ) {
			$s .= ' ' . implode( '; ', $facts ) . '.';
		}if ( self::present( $d, 'tahun_data' ) ) {
			$s .= ' Periode umum tercatat: ' . $d['tahun_data'] . ' (atribut dapat berbeda periode).';
		}return $s;}
	public static function description( $d ) {
		return self::short( self::summary( $d ), 160 );}
	public static function faq( $d ) {
		$out  = array();
		$name = $d['nama'] ?? 'sekolah ini';
		foreach ( array(
			'npsn'         => 'Berapa NPSN',
			'akreditasi'   => 'Apa akreditasi',
			'alamat'       => 'Di mana alamat',
			'jumlah_siswa' => 'Berapa jumlah siswa',
			'jumlah_guru'  => 'Berapa jumlah guru',
			'luas_tanah'   => 'Berapa luas tanah',
			'visi'         => 'Apa visi',
		) as $k => $q ) {
			if ( self::present( $d, $k ) ) {
				$a = (string) $d[ $k ];
				if ( 'luas_tanah' === $k ) {
					$a .= ' m²';
				}$note = SDP_Intelligence::period_note( $d, $k );
				if ( '' !== $note ) {
					$a .= $note;
				} elseif ( 'akreditasi' === $k && self::present( $d, 'tahun_akreditasi' ) ) {
					$a .= ' (tahun umum tercatat ' . $d['tahun_akreditasi'] . ')';
				} elseif ( in_array( $k, array( 'jumlah_siswa', 'jumlah_guru' ), true ) && self::present( $d, 'tahun_data' ) ) {
					$a .= ' (periode umum tercatat ' . $d['tahun_data'] . ')';
				}$out[ $q . ' ' . $name . '?' ] = $a;
			}
		}return $out;}
	public static function external_seo() {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );}
	public static function title_parts( $parts ) {
		$d = self::current();
		if ( $d && ! self::external_seo() ) {
			$parts['title'] = self::title( $d );
		}return $parts;}
	public static function schema( $d, $url ) {
		$type = in_array( $d['jenjang'] ?? '', array( 'SD', 'MI', 'SMP', 'MTs', 'SMA', 'MA', 'SMK', 'MAK', 'SLB' ), true ) ? 'School' : 'EducationalOrganization';
		$o    = array(
			'@context'         => 'https://schema.org',
			'@type'            => $type,
			'@id'              => $url . '#school',
			'name'             => $d['nama'],
			'identifier'       => array(
				'@type'      => 'PropertyValue',
				'propertyID' => 'NPSN',
				'value'      => $d['npsn'],
			),
			'mainEntityOfPage' => $url,
			'description'      => self::description( $d ),
		);
		$addr = array(
			'@type'          => 'PostalAddress',
			'addressCountry' => 'ID',
		);
		foreach ( array(
			'alamat'    => 'streetAddress',
			'kabupaten' => 'addressLocality',
			'provinsi'  => 'addressRegion',
			'kode_pos'  => 'postalCode',
		) as $k => $v ) {
			if ( self::present( $d, $k ) ) {
				$addr[ $v ] = $d[ $k ];
			}
		}$o['address'] = $addr;
		if ( self::present( $d, 'website' ) ) {
			$o['url'] = $d['website'];
		}if ( self::present( $d, 'latitude' ) && self::present( $d, 'longitude' ) && is_numeric( $d['latitude'] ) && is_numeric( $d['longitude'] ) && abs( (float) $d['latitude'] ) <= 90 && abs( (float) $d['longitude'] ) <= 180 ) {
			$o['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $d['latitude'],
				'longitude' => (float) $d['longitude'],
			);
		}return $o;}
	public static function head() {
		$d = self::current();
		if ( ! $d || self::external_seo() ) {
			return;
		}echo '<meta name="description" content="' . esc_attr( self::description( $d ) ) . '">' . "\n";
		if ( self::config()['schema'] && self::ready( $d ) ) {
			echo '<script type="application/ld+json">' . wp_json_encode( self::schema( $d, get_permalink( get_queried_object_id() ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";}}
	public static function scope( $id ) {
		$d = get_post_meta( (int) $id, '_sdp_region_scope', true );
		return is_array( $d ) ? array_intersect_key( $d, array_flip( self::SCOPE ) ) : array();}
	public static function is_directory() {
		return is_page() && ( get_queried_object_id() === (int) get_option( 'sdp_page_id' ) || self::scope( get_queried_object_id() ) || has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), SDP_Directory::SHORTCODE ) || has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'portal_sekolah' ) );}
	public static function robots( $robots ) {
		$d    = self::current();
		$thin = is_singular( SDP_Directory::TYPE ) && ! self::ready( $d );
		if ( self::is_directory() ) {
			foreach ( array( 'q', 'provinsi', 'kabupaten', 'kecamatan', 'jenjang', 'status', 'akreditasi', 'fasilitas', 'jurusan', 'p' ) as $k ) {
				if ( isset( $_GET[ 'sdp_' . $k ] ) && '' !== $_GET[ 'sdp_' . $k ] ) {
					$thin = true;
				}
			}$scope = self::scope( get_queried_object_id() );
			if ( $scope && self::count_scope( $scope ) < 3 ) {
				$thin = true;
			}
		}if ( $thin ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
		}return $robots;}
	public static function sitemap( $args, $type ) {
		if ( SDP_Directory::TYPE !== $type ) {
			return $args;
		}$existing          = $args['meta_query'] ?? array();
		$args['meta_query'] = array(
			'relation' => 'AND',
			array(
				'key'   => '_sdp_seo_ready',
				'value' => '1',
			),
		);
		if ( $existing ) {
			$args['meta_query'][] = $existing;
		}return $args;}
	public static function scope_sql( $scope ) {
		global $wpdb;
		$where  = array( "p.post_status='publish'", "p.post_password=''", "p.post_type='" . SDP_Directory::TYPE . "'" );
		$params = array();
		foreach ( self::SCOPE as $k ) {
			if ( isset( $scope[ $k ] ) && '' !== $scope[ $k ] ) {
				$where[]  = "s.$k=%s";
				$params[] = $scope[ $k ];
			}
		}return array( ' FROM ' . SDP_Directory::table() . " s JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE " . implode( ' AND ', $where ), $params );}
	public static function count_scope( $scope ) {
		global $wpdb;
		[$sql, $args] = self::scope_sql( $scope );
		return (int) $wpdb->get_var( $args ? $wpdb->prepare( 'SELECT COUNT(*)' . $sql, $args ) : 'SELECT COUNT(*)' . $sql );}
	public static function region_slug( $scope ) {
		ksort( $scope );
		return 'direktori-' . substr( sanitize_title( implode( '-', $scope ) ), 0, 110 ) . '-' . substr( hash( 'sha256', wp_json_encode( $scope ) ), 0, 12 );}
	public static function region_title( $scope ) {
		return 'Daftar sekolah' . ( ! empty( $scope['jenjang'] ) ? ' ' . $scope['jenjang'] : '' ) . ' di ' . implode( ', ', array_filter( array( $scope['kecamatan'] ?? '', $scope['kabupaten'] ?? '', $scope['provinsi'] ?? '' ) ) );}
	public static function valid_scope( $s ) {
		if ( empty( $s['provinsi'] ) ) {
			return false;
		}if ( ! empty( $s['kecamatan'] ) && empty( $s['kabupaten'] ) ) {
			return false;
		}return true;}
	public static function append( $content ) {
		if ( ! is_singular( SDP_Directory::TYPE ) || ! in_the_loop() || ! is_main_query() || post_password_required() ) {
			return $content;
		}$d = self::current();
		if ( ! $d ) {
			return $content;
		}$c    = self::config();
		$extra = '<section class="sdp-profile sdp-seo"><h2>Ringkasan profil</h2><p>' . esc_html( self::summary( $d ) ) . '</p><p class="sdp-note">Kelengkapan data inti: ' . self::score( $d ) . '% (bukan penilaian mutu sekolah).</p>';
		if ( $c['faq'] && 'full' === $c['style'] ) {
			$faq = self::faq( $d );
			if ( $faq ) {
				$extra .= '<h2>Pertanyaan umum</h2>';
				foreach ( $faq as $q => $a ) {
					$extra .= '<details><summary>' . esc_html( $q ) . '</summary><p>' . nl2br( esc_html( $a ) ) . '</p></details>';
				}
			}
		}if ( $c['related'] && self::present( $d, 'provinsi' ) && self::present( $d, 'kabupaten' ) ) {
			$scope = array(
				'provinsi'  => $d['provinsi'],
				'kabupaten' => $d['kabupaten'],
			);
			global $wpdb;
			[$sql, $args] = self::scope_sql( $scope );
			$rows         = $wpdb->get_results( $wpdb->prepare( 'SELECT s.post_id,s.nama' . $sql . ' AND s.npsn<>%s ORDER BY s.nama,s.npsn LIMIT 5', array_merge( $args, array( $d['npsn'] ) ) ), ARRAY_A );
			$extra       .= '<h2>Sekolah lain di wilayah ini</h2>';
			if ( $rows ) {
				$extra .= '<ul>';
				foreach ( $rows as $r ) {
					$extra .= '<li><a href="' . esc_url( get_permalink( $r['post_id'] ) ) . '">' . esc_html( $r['nama'] ) . '</a></li>';
				}$extra .= '</ul>';
			}$page = get_page_by_path( self::region_slug( $scope ) );
			$pid   = (int) get_option( 'sdp_page_id' );
			if ( $page && get_post_status( $page->ID ) === 'publish' && self::scope( $page->ID ) == $scope ) {
				$link = get_permalink( $page->ID );
			} elseif ( $pid && get_post_status( $pid ) === 'publish' ) {
				$link = add_query_arg(
					array(
						'sdp_provinsi'  => $scope['provinsi'],
						'sdp_kabupaten' => $scope['kabupaten'],
					),
					get_permalink( $pid )
				);
			} else {
				$link = '';
			}
			if ( $link ) {
				$extra .= '<p><a href="' . esc_url( $link ) . '">Lihat direktori sekolah ' . esc_html( $scope['kabupaten'] ) . '</a></p>';
			}
		}return $content . $extra . '</section>';}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'SEO & AI', 'SEO & AI', 'manage_options', 'sdp-seo', array( __CLASS__, 'page' ) );}
	private static function report( $msg, $error = false ) {
		set_transient( 'sdp_seo_notice_' . get_current_user_id(), array( $msg, $error ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=sdp-seo' ) );
		exit;}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp_seo' );
		$op = sanitize_key( $_POST['op'] ?? '' );
		if ( 'settings' === $op ) {
			$c = array();
			foreach ( array( 'guard', 'faq', 'related', 'schema' ) as $k ) {
				$c[ $k ] = isset( $_POST[ $k ] ) ? 1 : 0;
			}$c['style'] = ( $_POST['style'] ?? '' ) === 'compact' ? 'compact' : 'full';
			update_option( 'sdp_seo_config', $c, false );
			self::report( 'Pengaturan disimpan.' );}
		if ( 'reindex' === $op ) {
			global $wpdb;
			$cursor = isset( $_POST['reset'] ) ? '' : (string) get_option( 'sdp_seo_cursor', '' );
			$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT npsn,post_id,payload FROM ' . SDP_Directory::table() . ' WHERE npsn>%s ORDER BY npsn LIMIT 100', $cursor ), ARRAY_A );
			if ( ! is_array( $rows ) ) {
				self::report( 'Gagal membaca database.', true );
			}$n = 0;
			foreach ( $rows as $r ) {
				$d = json_decode( $r['payload'], true );
				if ( is_array( $d ) && get_post_type( $r['post_id'] ) === SDP_Directory::TYPE ) {
					self::sync( $r['post_id'], $d );
					++$n;
				}$cursor = $r['npsn'];
			}update_option( 'sdp_seo_cursor', $cursor, false );
			self::report( "$n profil diindeks ulang. " . ( count( $rows ) < 100 ? 'Sampai akhir. Centang mulai ulang untuk memeriksa semuanya lagi.' : 'Klik ulang untuk 100 berikutnya.' ) );}
		if ( 'region' === $op ) {
			$scope = array();
			foreach ( self::SCOPE as $k ) {
				$v = sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) );
				if ( '' !== $v ) {
					$scope[ $k ] = $v;
				}
			}if ( ! self::valid_scope( $scope ) ) {
				self::report( 'Provinsi wajib; kecamatan membutuhkan kabupaten.', true );
			}if ( self::count_scope( $scope ) < 3 ) {
				self::report( 'Butuh minimal 3 profil terbit sesuai wilayah.', true );
			}$slug = self::region_slug( $scope );
			if ( get_page_by_path( $slug ) ) {
				self::report( 'Halaman dengan identitas wilayah ini sudah ada.', true );
			}$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'page',
						'post_status'  => 'draft',
						'post_title'   => self::region_title( $scope ),
						'post_name'    => $slug,
						'post_content' => '[direktori_sekolah]',
						'meta_input'   => array( '_sdp_region_scope' => $scope ),
					)
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				self::report( $id->get_error_message(), true );
			}self::report( 'Draf halaman wilayah dibuat. Periksa dan terbitkan melalui Pages.' );}
		if ( 'claude_check' === $op ) {
			$model = sanitize_text_field( wp_unslash( $_POST['claude_model'] ?? '' ) );
			$key   = 'sdp_claude_check_' . get_current_user_id();
			if ( get_transient( $key ) ) {
				self::report( 'Tunggu satu menit sebelum memeriksa model Claude kembali.', true );
			}
			set_transient( $key, 1, 60 );
			if ( isset( $_POST['list_claude_models'] ) ) {
				$list_key = 'sdp_claude_models_' . get_current_user_id();
				delete_transient( $list_key );
				$models = SDP_AI::list_claude_models();
				if ( is_wp_error( $models ) ) {
					self::report( $models->get_error_message(), true );
				}
				if ( ! $models ) {
					self::report( 'Anthropic tidak mengembalikan ID model yang tersedia.', true );
				}
				set_transient( $list_key, $models, 600 );
				self::report( 'Daftar model Claude tersedia di kolom ID model selama 10 menit.' );
			}
			$result = SDP_AI::check_claude_model( $model );
			if ( is_wp_error( $result ) ) {
				self::report( $result->get_error_message(), true );
			}
			self::report( 'Model Claude tersedia untuk kunci ini. Belum ada narasi yang dibuat.' );
		}
		if ( 'ai' === $op ) {
			if ( empty( $_POST['consent'] ) ) {
				self::report( 'Konfirmasi izin pemrosesan data terlebih dahulu.', true );
			}$npsn = strtoupper( sanitize_text_field( wp_unslash( $_POST['npsn'] ?? '' ) ) );
			if ( ! preg_match( '/^[A-Z0-9]{8}$/D', $npsn ) ) {
				self::report( 'NPSN tidak valid.', true );
			}$r = self::by_npsn( $npsn );
			$d  = $r ? json_decode( $r['payload'], true ) : array();
			if ( ! $d ) {
				self::report( 'Profil tidak ditemukan.', true );
			}$provider = sanitize_key( $_POST['provider'] ?? '' );
			$model     = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
			$key       = 'sdp_ai_rate_' . get_current_user_id();
			if ( get_transient( $key ) ) {
				self::report( 'Permintaan AI dibatasi satu kali per menit.', true );
			}set_transient( $key, 1, 60 );
			$res = SDP_AI::generate( $provider, $model, $d );
			if ( is_wp_error( $res ) ) {
				self::report( $res->get_error_message(), true );
			}set_transient(
				'sdp_ai_preview_' . get_current_user_id(),
				array(
					'npsn'     => $npsn,
					'text'     => $res,
					'provider' => $provider,
				),
				600
			);
			self::report( 'Usulan AI tersedia selama 10 menit. Belum diterbitkan.' );
		}self::report( 'Tindakan tidak dikenal.', true );
	}
	private static function start( $op ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp_seo"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		wp_nonce_field( 'sdp_seo' );}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}$c = self::config();
		echo '<div class="wrap"><h1>SEO & AI — Sekolah Directory Pro</h1>';
		$k = 'sdp_seo_notice_' . get_current_user_id();
		$n = get_transient( $k );
		if ( $n ) {
			delete_transient( $k );
			echo '<div class="notice ' . ( $n[1] ? 'notice-error' : 'notice-info' ) . '"><p>' . esc_html( $n[0] ) . '</p></div>';
		}if ( self::external_seo() ) {
			echo '<div class="notice notice-warning"><p>Plugin SEO lain terdeteksi. Meta description dan schema SDP dimatikan untuk mencegah duplikasi. Aturan robots/sitemap plugin SEO lain harus dikonfigurasi dan diuji tersendiri; gunakan SEO bawaan WordPress untuk cakupan penuh SDP.</p></div>';
		}
		echo '<h2>Pengaturan otomatis</h2>';
		self::start( 'settings' );
		foreach ( array(
			'guard'   => 'Tahan permintaan publikasi dari impor jika profil belum layak',
			'faq'     => 'FAQ berbasis fakta',
			'related' => 'Tautan sekolah satu wilayah',
			'schema'  => 'Schema School / EducationalOrganization',
		) as $k => $label ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( $k ) . '" ' . checked( $c[ $k ], 1, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}echo '<label>Template tambahan <select name="style"><option value="full" ' . selected( $c['style'], 'full', false ) . '>Lengkap</option><option value="compact" ' . selected( $c['style'], 'compact', false ) . '>Ringkas (tanpa FAQ)</option></select></label>';
		submit_button( 'Simpan pengaturan' );
		echo '</form><p>Skor = jumlah dari 20 atribut inti terisi / 20. Minimal 35%, identitas dan lokasi inti lengkap; data simulasi tidak layak indeks. Bukan verifikasi fakta. Profil lama terbit tidak diubah statusnya. Kontrol publikasi berlaku untuk impor/generator, bukan editor WP manual.</p><p>Sitemap bawaan: <a href="' . esc_url( home_url( '/wp-sitemap.xml' ) ) . '">wp-sitemap.xml</a>. Profil lama harus diindeks ulang terlebih dahulu.</p><h2>Indeks ulang profil lama</h2>';
		self::start( 'reindex' );
		echo '<p>Kursor terakhir: <code>' . esc_html( get_option( 'sdp_seo_cursor', '' ) ?: 'awal' ) . '</code></p><label><input type="checkbox" name="reset"> Mulai ulang dari awal</label>';
		submit_button( 'Indeks ulang 100 profil', 'secondary' );
		echo '</form><h2>Halaman wilayah</h2><p>Gunakan nama wilayah persis seperti database. Minimal 3 sekolah terbit. Halaman dibuat sebagai draf.</p>';
		self::start( 'region' );
		foreach ( self::SCOPE as $k ) {
			echo '<p><label>' . esc_html( ucfirst( $k ) ) . ' <input name="' . esc_attr( $k ) . '" ' . ( 'provinsi' === $k ? 'required' : '' ) . '></label></p>';
		}submit_button( 'Buat draf halaman wilayah', 'secondary' );
		echo '</form><h2>Asisten narasi AI (opsional)</h2><p>Hanya mengirim fakta institusi yang dipilih ke penyedia. Hasil berupa pratinjau privat, tidak disimpan dalam artikel dan wajib diperiksa. API berbayar dapat menimbulkan biaya. Kunci disediakan melalui wp-config.php (SDP_OPENAI_API_KEY / SDP_GEMINI_API_KEY / SDP_CLAUDE_API_KEY). Claude juga membaca ANTHROPIC_API_KEY dari environment server. Ollama memakai 127.0.0.1:11434.</p>';
		self::start( 'claude_check' );
		echo '<p><label>ID model untuk pemeriksaan Claude <input name="claude_model" list="sdp-claude-models" maxlength="128" required></label></p>';
		submit_button( 'Periksa model Claude', 'secondary' );
		echo '<p><button type="submit" class="button" name="list_claude_models" value="1" formnovalidate>Ambil daftar model Claude</button></p>';
		echo '</form>';
		$models = get_transient( 'sdp_claude_models_' . get_current_user_id() );
		echo '<datalist id="sdp-claude-models">';
		foreach ( is_array( $models ) ? $models : array() as $model_id ) {
			echo '<option value="' . esc_attr( $model_id ) . '"></option>';
		}
		echo '</datalist>';
		self::start( 'ai' );
		echo '<p><label>NPSN <input name="npsn" maxlength="8" required></label></p><p><label>Penyedia <select name="provider"><option value="ollama">Ollama lokal</option><option value="gemini">Gemini</option><option value="openai">OpenAI</option><option value="claude">Claude (Anthropic)</option></select></label></p><p><label>ID model <input name="model" list="sdp-claude-models" required placeholder="ID model yang tersedia"></label></p><p><label><input type="checkbox" name="consent" required> Saya berhak memproses data ini dan memahami pengiriman data serta potensi biaya.</label></p>';
		submit_button( 'Buat usulan narasi', 'secondary' );
		echo '</form>';
		$a = get_transient( 'sdp_ai_preview_' . get_current_user_id() );
		if ( $a ) {
			echo '<h3>Pratinjau AI — ' . esc_html( $a['npsn'] ) . ' / ' . esc_html( $a['provider'] ) . '</h3><p>Periksa setiap klaim. Tidak ada tombol publikasi otomatis.</p><textarea readonly class="large-text" rows="10">' . esc_textarea( $a['text'] ) . '</textarea>';
		}echo '</div>';
	}
}
