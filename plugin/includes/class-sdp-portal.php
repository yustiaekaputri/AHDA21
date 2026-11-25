<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Public portal: read-only queries, progressive enhancement, no schema migration. */
final class SDP_Portal {
	const FILTERS = array(
		'provinsi'   => 'Provinsi',
		'kabupaten'  => 'Kabupaten / kota',
		'kecamatan'  => 'Kecamatan',
		'jenjang'    => 'Jenjang',
		'status'     => 'Status',
		'akreditasi' => 'Akreditasi',
	);
	const LIMIT   = 20;
	public static function boot() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'the_content', array( __CLASS__, 'profile' ), 15 );
		add_shortcode( 'statistik_sekolah', array( __CLASS__, 'statistics_shortcode' ) );
		add_shortcode( 'portal_sekolah', array( __CLASS__, 'render' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
	}
	public static function assets( $force = false ) {
		if ( ! $force && ! SDP_Performance::assets_needed() ) {
			return;
		}
		SDP_Directory::assets( true );
		$root = dirname( __DIR__ ) . '/sekolah-directory-pro.php';
		wp_enqueue_style( 'sdp-portal', plugins_url( 'assets/portal.css', $root ), array( 'sdp-directory' ), SDP_Directory::VERSION );
		wp_enqueue_script( 'sdp-portal', plugins_url( 'assets/portal.js', $root ), array(), SDP_Directory::VERSION, true );
	}
	public static function routes() {
		register_rest_route(
			'sdp/v1',
			'/portal',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'api' ),
			)
		);
	}
	public static function clean( $v, $max = 190 ) {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$v = sanitize_text_field( (string) $v );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $v, 0, $max, 'UTF-8' );
		}
		preg_match_all( '/./us', $v, $m );
		return implode( '', array_slice( $m[0] ?? array(), 0, $max ) );
	}
	public static function state( $raw, $scope = array() ) {
		$state = array(
			'q' => self::clean( $raw['sdp_q'] ?? '' ),
			'p' => max( 1, min( 100000, (int) self::clean( $raw['sdp_p'] ?? '1', 7 ) ) ),
		);
		foreach ( self::FILTERS as $k => $label ) {
			$state[ $k ] = self::clean( $scope[ $k ] ?? $raw[ 'sdp_' . $k ] ?? '', 120 );
		}
		$state['fasilitas'] = self::clean( $raw['sdp_fasilitas'] ?? '', 40 );
		if ( ! isset( SDP_Analytics::FACILITIES[ $state['fasilitas'] ] ) ) {
			$state['fasilitas'] = '';
		}
		$state['jurusan'] = self::clean( $raw['sdp_jurusan'] ?? '', 100 );
		return $state;
	}
	private static function select_sql( $sql, $args ) {
		global $wpdb;
		return $args ? $wpdb->prepare( $sql, $args ) : $sql;}
	public static function from( $state, $keys = null, $prefix = false ) {
		global $wpdb;
		$keys  = $keys ?? array_keys( self::FILTERS );
		$where = array( "p.post_status='publish'", "p.post_password=''", 'p.post_type=%s' );
		$args  = array( SDP_Directory::TYPE );
		foreach ( self::FILTERS as $k => $label ) {
			if ( in_array( $k, $keys, true ) && ( $state[ $k ] ?? '' ) !== '' ) {
				$where[] = "s.$k=%s";
				$args[]  = $state[ $k ];}
		}
		if ( ( $state['q'] ?? '' ) !== '' ) {
			$like    = ( $prefix ? '' : '%' ) . $wpdb->esc_like( $state['q'] ) . '%';
			$where[] = '(s.nama LIKE %s OR s.npsn LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
		if ( $keys === array_keys( self::FILTERS ) ) {
			$facility = $state['fasilitas'] ?? '';
			if ( isset( SDP_Analytics::FACILITIES[ $facility ] ) ) {
				$v       = "JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(s.payload) THEN s.payload ELSE '{}' END, '$.$facility'))";
				$where[] = "($v REGEXP '^[0-9]{1,9}$' AND CAST($v AS UNSIGNED)>0)";
			}
			if ( ( $state['jurusan'] ?? '' ) !== '' ) {
				$where[] = "JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(s.payload) THEN s.payload ELSE '{}' END, '$.jurusan')) LIKE %s";
				$args[]  = '%' . $wpdb->esc_like( $state['jurusan'] ) . '%';
			}
		}
		return array( ' FROM ' . SDP_Directory::table() . " s JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE " . implode( ' AND ', $where ), $args );
	}
	public static function options( $state, $scope = array() ) {
		global $wpdb;
		$out = array();
		foreach ( self::FILTERS as $k => $label ) {
			if ( isset( $scope[ $k ] ) ) {
				$out[ $k ] = array( $scope[ $k ] );
				continue;}
			$parents  = $k === 'kabupaten' ? array( 'provinsi' ) : ( $k === 'kecamatan' ? array( 'provinsi', 'kabupaten' ) : array() );
			$required = $k === 'kabupaten' ? 'provinsi' : ( $k === 'kecamatan' ? 'kabupaten' : '' );
			if ( $required && empty( $state[ $required ] ) ) {
				$out[ $k ] = ! empty( $state[ $k ] ) ? array( $state[ $k ] ) : array();
				continue;}
			$base          = $state;
			$base['q']     = '';
			$keys          = array_values( array_unique( array_merge( $parents, array_keys( $scope ) ) ) );
			[$from, $args] = self::from( $base, $keys );
			$sql           = self::select_sql( "SELECT DISTINCT s.$k" . $from . " AND s.$k<>'' ORDER BY s.$k LIMIT 1000", $args );
			$out[ $k ]     = SDP_Performance::facet(
				$sql,
				static function () use ( $wpdb, $sql ) {
					$v = $wpdb->get_col( $sql );
					return empty( $wpdb->last_error ) && is_array( $v ) ? $v : null;
				}
			) ?? array();
			if ( $state[ $k ] !== '' && ! in_array( $state[ $k ], $out[ $k ], true ) ) {
				$out[ $k ][] = $state[ $k ];
			}
		}
		return $out;
	}
	public static function results( $state ) {
		global $wpdb;
		[$from, $args] = self::from( $state );
		$summary       = $wpdb->get_row( self::select_sql( "SELECT COUNT(*) total, SUM(s.status='Negeri') negeri, SUM(s.status='Swasta') swasta" . $from, $args ), ARRAY_A );
		if ( ! is_array( $summary ) || ! empty( $wpdb->last_error ) ) {
			return self::failed_results();
		}
		$total = (int) ( $summary['total'] ?? 0 );
		$pages = max( 1, (int) ceil( $total / self::LIMIT ) );
		$page  = min( $state['p'], $pages );
		$rows  = $wpdb->get_results( self::select_sql( 'SELECT s.npsn,s.post_id,s.nama,s.jenjang,s.status,s.akreditasi,s.provinsi,s.kabupaten,s.kecamatan,s.payload' . $from . ' ORDER BY s.nama,s.npsn LIMIT %d OFFSET %d', array_merge( $args, array( self::LIMIT, ( $page - 1 ) * self::LIMIT ) ) ), ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			return self::failed_results();
		}
		SDP_Performance::prime( $rows );
		return array(
			'total'  => $total,
			'negeri' => (int) ( $summary['negeri'] ?? 0 ),
			'swasta' => (int) ( $summary['swasta'] ?? 0 ),
			'pages'  => $pages,
			'page'   => $page,
			'rows'   => $rows ?: array(),
		);
	}
	public static function failed_results() {
		return array(
			'error'  => true,
			'total'  => 0,
			'negeri' => 0,
			'swasta' => 0,
			'pages'  => 1,
			'page'   => 1,
			'rows'   => array(),
		);}
	public static function geo( $d ) {
		if ( ! isset( $d['latitude'], $d['longitude'] ) || ! is_numeric( $d['latitude'] ) || ! is_numeric( $d['longitude'] ) ) {
			return null;
		}
		$lat = (float) $d['latitude'];
		$lon = (float) $d['longitude'];
		if ( ! is_finite( $lat ) || ! is_finite( $lon ) || abs( $lat ) > 90 || abs( $lon ) > 180 || ( $lat == 0 && $lon == 0 ) ) {
			return null;
		}
		return array( $lat, $lon );
	}
	public static function num( $d, $k ) {
		$v = $d[ $k ] ?? null;
		return is_scalar( $v ) && preg_match( '/^\d{1,9}$/D', (string) $v ) ? (int) $v : null;}
	public static function place( $r ) {
		return implode( ', ', array_filter( array( $r['kecamatan'] ?? '', $r['kabupaten'] ?? '', $r['provinsi'] ?? '' ) ) );}
	public static function url( $base, $state, $page = 1, $scope = array() ) {
		$params = array();
		foreach ( array_merge(
			array(
				'q'         => '',
				'fasilitas' => '',
				'jurusan'   => '',
			),
			self::FILTERS
		) as $k => $v ) {
			if ( ! isset( $scope[ $k ] ) && ( ! empty( $state[ $k ] ) || ( $state[ $k ] ?? '' ) === '0' ) ) {
				$params[ 'sdp_' . $k ] = $state[ $k ];
			}
		}if ( $page > 1 ) {
			$params['sdp_p'] = $page;
		}return $params ? add_query_arg( $params, $base ) : $base;}
	public static function map_url( $coords ) {
		return 'https://www.openstreetmap.org/?mlat=' . rawurlencode( (string) $coords[0] ) . '&mlon=' . rawurlencode( (string) $coords[1] ) . '#map=17/' . rawurlencode( (string) $coords[0] ) . '/' . rawurlencode( (string) $coords[1] );}
	public static function map( $rows ) {
		$points = array();
		foreach ( $rows as $r ) {
			$d   = json_decode( $r['payload'], true ) ?: array();
			$geo = self::geo( $d );
			if ( $geo ) {
				$points[] = array(
					'nama' => $r['nama'],
					'npsn' => $r['npsn'],
					'geo'  => $geo,
					'url'  => get_permalink( (int) $r['post_id'] ),
				);
			}
		}
		if ( ! $points ) {
			return '<p class="sdp4-muted">Koordinat belum tersedia pada hasil di halaman ini.</p>';
		}
		$lat    = array_column( array_column( $points, 'geo' ), 0 );
		$lon    = array_column( array_column( $points, 'geo' ), 1 );
		$minlat = min( $lat );
		$maxlat = max( $lat );
		$minlon = min( $lon );
		$maxlon = max( $lon );
		$html   = '<p class="sdp4-muted">' . count( $points ) . ' dari ' . count( $rows ) . ' profil pada halaman ini memiliki koordinat. Plot relatif lintang/bujur, tanpa peta dasar; bukan alat navigasi.</p><div class="sdp4-plot" aria-label="Plot koordinat sekolah"><span class="sdp4-north">↑ Utara</span>';
		foreach ( $points as $i => $p ) {
			$x     = $minlon === $maxlon ? 50 : 8 + 84 * ( $p['geo'][1] - $minlon ) / ( $maxlon - $minlon );
			$y     = $minlat === $maxlat ? 50 : 8 + 84 * ( $maxlat - $p['geo'][0] ) / ( $maxlat - $minlat );
			$html .= '<a class="sdp4-pin" style="left:' . esc_attr( number_format( $x, 3, '.', '' ) ) . '%;top:' . esc_attr( number_format( $y, 3, '.', '' ) ) . '%" href="' . esc_url( $p['url'] ) . '" title="' . esc_attr( $p['nama'] ) . '" aria-label="' . esc_attr( ( $i + 1 ) . '. ' . $p['nama'] ) . '">' . ( $i + 1 ) . '</a>';}
		$html .= '</div><ol class="sdp4-maplist">';
		foreach ( $points as $p ) {
			$html .= '<li><a href="' . esc_url( $p['url'] ) . '">' . esc_html( $p['nama'] ) . '</a> · <a href="' . esc_url( self::map_url( $p['geo'] ) ) . '" rel="noopener noreferrer">OpenStreetMap</a></li>';
		}
		return $html . '</ol>';
	}
	public static function result_html( $data, $state, $base, $scope = array() ) {
		if ( ! empty( $data['error'] ) ) {
			return '<div class="sdp4-empty" role="alert"><h3>Pencarian sementara tidak tersedia</h3><p>Coba kembali nanti.</p></div>';
		}
		$html = '<div class="sdp4-summary" role="status"><strong>' . esc_html( number_format_i18n( $data['total'] ) ) . ' sekolah ditemukan</strong><span>' . esc_html( number_format_i18n( $data['negeri'] ) ) . ' negeri · ' . esc_html( number_format_i18n( $data['swasta'] ) ) . ' swasta · ' . esc_html( number_format_i18n( max( 0, $data['total'] - $data['negeri'] - $data['swasta'] ) ) ) . ' lainnya / belum diketahui</span></div>';
		if ( ! $data['rows'] ) {
			return $html . '<div class="sdp4-empty"><h3>Belum ada hasil</h3><p>Coba kata kunci yang lebih pendek atau kurangi filter. Hanya profil terbit dan tidak dilindungi sandi yang ditampilkan.</p></div>';
		}
		$html .= '<div class="sdp-results sdp4-cards">';
		foreach ( $data['rows'] as $r ) {
			$d     = json_decode( $r['payload'], true ) ?: array();
			$url   = get_permalink( (int) $r['post_id'] );
			$html .= '<article class="sdp-result sdp4-card"><div class="sdp-tags"><span>' . esc_html( $r['jenjang'] ?: 'Jenjang belum tersedia' ) . '</span><span>' . esc_html( $r['status'] ?: 'Status belum tersedia' ) . '</span></div><h3><a href="' . esc_url( $url ) . '">' . esc_html( $r['nama'] ) . '</a></h3><p class="sdp4-muted">NPSN ' . esc_html( $r['npsn'] ) . '</p><p>' . esc_html( self::place( $r ) ?: 'Wilayah belum tersedia' ) . '</p><div class="sdp4-cardstats">';
			foreach ( array(
				'akreditasi'   => 'Akreditasi',
				'jumlah_siswa' => 'Siswa',
				'jumlah_guru'  => 'Guru',
			) as $k => $label ) {
				$v     = $k === 'akreditasi' ? ( $r['akreditasi'] ?: null ) : self::num( $d, $k );
				$html .= '<div><small>' . esc_html( $label ) . '</small><strong>' . esc_html( $v === null ? '—' : ( is_int( $v ) ? number_format_i18n( $v ) : $v ) ) . '</strong></div>';}
			$html .= '</div><p class="sdp4-muted">Periode umum tercatat: ' . esc_html( $d['tahun_data'] ?? 'belum tersedia' ) . '</p><a class="sdp4-link" href="' . esc_url( $url ) . '">Lihat profil lengkap →</a>' . SDP_Analytics::controls( $r ) . '</article>';}
		$html .= '</div><details class="sdp4-map"><summary>Jelajahi lokasi sekolah pada halaman ini</summary>' . self::map( $data['rows'] ) . '</details>';
		if ( $data['pages'] > 1 ) {
			$html .= '<nav class="sdp-pagination" aria-label="Halaman hasil">';
			if ( $data['page'] > 1 ) {
				$html .= '<a data-sdp-page="' . ( $data['page'] - 1 ) . '" href="' . esc_url( self::url( $base, $state, $data['page'] - 1, $scope ) ) . '">← Sebelumnya</a>';
			}$html .= '<span>Halaman ' . $data['page'] . ' / ' . $data['pages'] . '</span>';
			if ( $data['page'] < $data['pages'] ) {
				$html .= '<a data-sdp-page="' . ( $data['page'] + 1 ) . '" href="' . esc_url( self::url( $base, $state, $data['page'] + 1, $scope ) ) . '">Berikutnya →</a>';
			}$html .= '</nav>';}
		return $html;
	}
	public static function context( $id ) {
		$p = $id ? get_post( $id ) : null;
		return $p && $p->post_status === 'publish' && $p->post_password === '' && is_post_type_viewable( $p->post_type );}
	public static function api( $request ) {
		$id = absint( $request->get_param( 'context' ) );
		if ( $id && ! self::context( $id ) ) {
			return new WP_Error( 'sdp_context', 'Halaman tidak tersedia.', array( 'status' => 404 ) );
		}
		$scope = $id ? SDP_SEO::scope( $id ) : array();
		$state = self::state( $request->get_params(), $scope );
		$base  = $id ? get_permalink( $id ) : home_url( '/' );
		if ( $request->get_param( 'mode' ) === 'suggest' ) {
			if ( SDP_Resource::enabled() || strlen( $state['q'] ) < 2 ) {
				return rest_ensure_response( array( 'items' => array() ) );
			}global $wpdb;
			[$from, $args] = self::from( $state, null, true );
			$rows          = $wpdb->get_results( self::select_sql( 'SELECT s.nama,s.npsn,s.post_id,s.kabupaten' . $from . ' ORDER BY s.nama,s.npsn LIMIT 8', $args ), ARRAY_A );
			$items         = array();
			if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'sdp_db', 'Pencarian sementara tidak tersedia.', array( 'status' => 503 ) );
			}
			foreach ( $rows ?: array() as $r ) {
				$items[] = array(
					'name'   => $r['nama'],
					'npsn'   => $r['npsn'],
					'region' => $r['kabupaten'],
					'url'    => get_permalink( (int) $r['post_id'] ),
				);
			}
			return rest_ensure_response( array( 'items' => $items ) );
		}
		$data = self::results( $state );
		if ( ! empty( $data['error'] ) ) {
			return new WP_Error( 'sdp_db', 'Pencarian sementara tidak tersedia.', array( 'status' => 503 ) );
		}$state['p'] = $data['page'];
		return rest_ensure_response(
			array(
				'html'    => self::result_html( $data, $state, $base, $scope ),
				'options' => self::options( $state, $scope ),
				'state'   => $state,
				'url'     => self::url( $base, $state, $data['page'], $scope ),
			)
		);
	}
	public static function render( $atts = array() ) {
		self::assets( true );
		SDP_Analytics::assets( true );
		$id    = (int) get_queried_object_id();
		$scope = SDP_SEO::scope( $id );
		$state = self::state( wp_unslash( $_GET ), $scope );
		$base  = get_permalink( $id ) ?: home_url( '/' );
		$data  = self::results( $state );
		$opts  = self::options( $state, $scope );
		$live  = self::context( $id ) ? rest_url( 'sdp/v1/portal' ) : '';
		ob_start();?><section class="sdp-directory sdp4" data-sdp-endpoint="<?php echo esc_url( $live ); ?>" data-sdp-context="<?php echo $id; ?>" data-sdp-low="<?php echo SDP_Resource::enabled() ? '1' : '0'; ?>">
		<div class="sdp4-hero"><p class="sdp4-kicker">DIREKTORI PENDIDIKAN INDONESIA</p><h2>Temukan sekolah dan madrasah</h2><p>Telusuri identitas, akreditasi, statistik, fasilitas, dan lokasi sekolah.</p>
		<form method="get" action="<?php echo esc_url( $base ); ?>" class="sdp-filters sdp4-form">
		<?php
		if ( ! get_option( 'permalink_structure' ) && $id ) :
			?>
			<input type="hidden" name="<?php echo is_page() ? 'page_id' : 'p'; ?>" value="<?php echo $id; ?>"><?php endif; ?>
		<label class="sdp-search">Nama sekolah / NPSN<input name="sdp_q" value="<?php echo esc_attr( $state['q'] ); ?>" placeholder="Contoh: SMP Negeri, 12345678" maxlength="190" autocomplete="off"></label><div class="sdp4-suggestions" hidden aria-label="Saran sekolah"></div>
		<?php
		foreach ( self::FILTERS as $k => $label ) :
			?>
			<label><?php echo esc_html( $label ); ?>
			<?php
			if ( isset( $scope[ $k ] ) ) :
				?>
			<input name="sdp_<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $scope[ $k ] ); ?>" readonly disabled data-sdp-fixed="1">
				<?php
else :
	?>
	<select name="sdp_<?php echo esc_attr( $k ); ?>"><option value=""><?php echo $k === 'kabupaten' && ! $state['provinsi'] ? 'Pilih provinsi dahulu' : ( $k === 'kecamatan' && ! $state['kabupaten'] ? 'Pilih kabupaten dahulu' : 'Semua' ); ?></option>
	<?php
	foreach ( $opts[ $k ] as $v ) :
		?>
	<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $state[ $k ], $v ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select><?php endif; ?></label><?php endforeach; ?>
		<label>Fasilitas tercatat<select name="sdp_fasilitas"><option value="">Semua</option>
		<?php
		foreach ( SDP_Analytics::FACILITIES as $k => $v ) :
			?>
			<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $state['fasilitas'], $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></label>
		<label>Jurusan / kompetensi<input name="sdp_jurusan" value="<?php echo esc_attr( $state['jurusan'] ); ?>" maxlength="100" placeholder="Contoh: Teknik Komputer"></label>
		<div class="sdp-actions"><button type="submit">Cari sekolah</button><a class="sdp4-reset" href="<?php echo esc_url( $base ); ?>">Reset filter</a></div></form>
		<noscript><p>Pilih provinsi, klik Cari, kemudian pilih kabupaten dan kecamatan.</p></noscript><p class="sdp4-feedback" role="status" aria-live="polite"></p></div>
		<?php
		if ( ! isset( $scope['jenjang'] ) ) :
			?>
			<nav class="sdp4-levels" aria-label="Jelajahi jenjang">
			<?php
			foreach ( array( 'PAUD', 'TK', 'RA', 'SD', 'MI', 'SMP', 'MTs', 'SMA', 'MA', 'SMK', 'SLB', 'PKBM' ) as $level ) :
				?>
			<a data-sdp-level="<?php echo esc_attr( $level ); ?>" href="<?php echo esc_url( self::url( $base, array_merge( $state, array( 'jenjang' => $level ) ), 1, $scope ) ); ?>"><?php echo esc_html( $level ); ?></a><?php endforeach; ?></nav><?php endif; ?>
		<?php echo SDP_Analytics::toolbar(); ?>
		<div class="sdp4-output" aria-live="polite"><?php echo self::result_html( $data, $state, $base, $scope ); ?></div></section>
		<?php
		return ob_get_clean();
	}
	public static function statistics_shortcode( $atts = array() ) {
		self::assets( true );
		global $wpdb;
		$id    = (int) get_queried_object_id();
		$scope = SDP_SEO::scope( $id );
		$atts  = shortcode_atts(
			array(
				'provinsi'  => '',
				'kabupaten' => '',
				'kecamatan' => '',
				'jenjang'   => '',
			),
			$atts,
			'statistik_sekolah'
		);
		$state = array();
		foreach ( $atts as $k => $v ) {
			$state[ 'sdp_' . $k ] = $v;
		}$state        = self::state( $state, $scope );
		[$from, $args] = self::from( $state );
		$rows          = $wpdb->get_results( self::select_sql( 'SELECT s.jenjang, COUNT(*) n' . $from . ' GROUP BY s.jenjang ORDER BY n DESC,s.jenjang LIMIT 30', $args ), ARRAY_A ) ?: array();
		$total         = (int) $wpdb->get_var( self::select_sql( 'SELECT COUNT(*)' . $from, $args ) );
		$max           = max( 1, (int) ( $rows[0]['n'] ?? 0 ) );
		$place         = implode( ', ', array_filter( array( $state['kecamatan'], $state['kabupaten'], $state['provinsi'] ) ) );
		$html          = '<section class="sdp-profile sdp4-stat"><h2>Statistik direktori' . ( $place ? ' — ' . esc_html( $place ) : '' ) . '</h2><p><strong>' . esc_html( number_format_i18n( $total ) ) . '</strong> profil terbit' . ( $state['jenjang'] ? ' · ' . esc_html( $state['jenjang'] ) : '' ) . '. Bukan jumlah resmi seluruh sekolah.</p>';
		foreach ( $rows as $r ) {
			$html .= '<div class="sdp4-bar"><span>' . esc_html( $r['jenjang'] ?: 'Belum tersedia' ) . '</span><meter min="0" max="' . $max . '" value="' . (int) $r['n'] . '">' . (int) $r['n'] . '</meter><strong>' . esc_html( number_format_i18n( $r['n'] ) ) . '</strong></div>';
		}
		if ( count( $rows ) === 30 ) {
			$html .= '<p>Menampilkan hingga 30 kelompok jenjang.</p>';
		}return $html . '</section>';
	}
	public static function chart( $d ) {
		$html = '<section class="sdp4-stat"><h2>Statistik sekolah</h2><p class="sdp4-muted">Periode umum tercatat: ' . esc_html( $d['tahun_data'] ?? 'belum tersedia' ) . '. Atribut dapat berbeda periode; lihat tabel profil. Angka berdasarkan data tercatat; tidak diketahui bukan berarti nol.</p>';
		foreach ( array(
			array(
				'Peserta didik dan guru',
				array(
					'jumlah_siswa' => 'Jumlah siswa',
					'jumlah_guru'  => 'Jumlah guru',
				),
			),
			array(
				'Komposisi siswa',
				array(
					'siswa_laki_laki' => 'Laki-laki',
					'siswa_perempuan' => 'Perempuan',
				),
			),
			array(
				'Fasilitas tercatat',
				array(
					'ruang_kelas'  => 'Ruang kelas',
					'laboratorium' => 'Laboratorium',
					'perpustakaan' => 'Perpustakaan',
					'toilet'       => 'Toilet',
				),
			),
		) as [$title, $fields] ) {
			$nums = array();
			foreach ( $fields as $k => $label ) {
				$v = self::num( $d, $k );
				if ( $v !== null ) {
					$nums[ $label ] = $v;
				}
			}if ( ! $nums ) {
				continue;
			}$max  = max( 1, max( $nums ) );
			$html .= '<h3>' . esc_html( $title ) . '</h3>';
			foreach ( $nums as $label => $v ) {
				$html .= '<div class="sdp4-bar"><span>' . esc_html( $label ) . '</span><meter min="0" max="' . $max . '" value="' . $v . '">' . $v . '</meter><strong>' . esc_html( number_format_i18n( $v ) ) . '</strong></div>';
			}
		}
		$s = self::num( $d, 'jumlah_siswa' );
		$g = self::num( $d, 'jumlah_guru' );
		if ( $s !== null && $g !== null && $g > 0 ) {
			$html .= '<p>Rasio siswa per guru: <strong>' . esc_html( number_format_i18n( $s / $g, 1 ) ) . '</strong> (berdasarkan angka tercatat; periode dapat berbeda).</p>';
		}
		if ( $s === null && $g === null && ! array_filter( array( 'siswa_laki_laki', 'siswa_perempuan', 'ruang_kelas', 'laboratorium', 'perpustakaan', 'toilet' ), static fn( $k )=>self::num( $d, $k ) !== null ) ) {
			$html .= '<p>Statistik belum tersedia.</p>';
		}
		return $html . '</section>';
	}
	public static function profile( $content ) {
		if ( ! is_singular( SDP_Directory::TYPE ) || ! is_main_query() || ! in_the_loop() || post_password_required() ) {
			return $content;
		}
		$d = SDP_SEO::current();
		if ( ! $d ) {
			return $content;
		}$pid = (int) get_option( 'sdp_page_id' );
		$nav  = '<nav class="sdp4-breadcrumb" aria-label="Lokasi halaman"><a href="' . esc_url( home_url( '/' ) ) . '">Beranda</a>';
		if ( $pid && self::context( $pid ) ) {
			$base = get_permalink( $pid );
			$nav .= ' / <a href="' . esc_url( $base ) . '">Direktori</a>';
			$s    = array();
			foreach ( array( 'provinsi', 'kabupaten', 'kecamatan' ) as $k ) {
				if ( ! empty( $d[ $k ] ) ) {
					$s[ $k ]      = $d[ $k ];
							$nav .= ' / <a href="' . esc_url( self::url( $base, $s ) ) . '">' . esc_html( $d[ $k ] ) . '</a>';}
			}
		}
		$nav .= ' / <span>' . esc_html( $d['nama'] ?? 'Profil sekolah' ) . '</span></nav>';
		$html = '<div class="sdp-profile sdp4-profile">' . $nav . '<div class="sdp4-profilehead"><p class="sdp4-kicker">PROFIL SEKOLAH</p><h2>' . esc_html( $d['nama'] ) . '</h2><p>NPSN ' . esc_html( $d['npsn'] ) . ' · ' . esc_html( self::place( $d ) ) . '</p><div class="sdp-tags">';
		foreach ( array( 'jenjang', 'status', 'akreditasi' ) as $k ) {
			if ( SDP_SEO::present( $d, $k ) ) {
				$html .= '<span>' . esc_html( $k === 'akreditasi' ? 'Akreditasi ' . $d[ $k ] : $d[ $k ] ) . '</span>';
			}
		}$html .= '</div></div>' . self::chart( $d );
		$geo    = self::geo( $d );
		if ( $geo ) {
			$html .= '<p><a href="' . esc_url( self::map_url( $geo ) ) . '">Lihat lokasi sekolah di OpenStreetMap →</a></p>';
		}return $html . '</div>' . $content;
	}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Portal & Tampilan', 'Portal & Tampilan', 'manage_options', 'sdp-portal', array( __CLASS__, 'admin' ) );}
	public static function admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}echo '<div class="wrap"><h1>Portal & Tampilan</h1><p>Versi 4.0 mengaktifkan portal modern pada shortcode lama tanpa migrasi data.</p><h2>Shortcode</h2><p><code>[direktori_sekolah]</code> atau <code>[portal_sekolah]</code> — pencarian, filter bertingkat, kartu dan plot koordinat.</p><p><code>[statistik_sekolah]</code> — jumlah profil publik menurut jenjang.</p><p><code>[statistik_sekolah provinsi="Jawa Barat" kabupaten="Indramayu"]</code> — filter wilayah sesuai nama pada database.</p><p>Untuk beranda, buat halaman WordPress, tambahkan shortcode, lalu pilih halaman pada Settings → Reading. Tampilan luar tetap mengikuti tema. API publik read-only hanya mengembalikan hasil terbit tanpa sandi. Tidak perlu API key atau peta eksternal.</p><p>Uji LocalWP sebelum produksi. Plot lokasi bukan basemap, dan statistik direktori bukan jumlah resmi nasional.</p></div>';}
}
