<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Bounded aggregate snapshots of the public directory; not national census estimates. */
final class SDP_Education {
	const JOB        = 'sdp80_job';
	const BATCH      = 100;
	const MAX_GROUPS = 1000;
	const SLOTS      = 10;
	const FAC        = array(
		'ruang_kelas'  => 'Ruang kelas',
		'laboratorium' => 'Laboratorium',
		'perpustakaan' => 'Perpustakaan',
		'toilet'       => 'Toilet',
	);
	const STATS      = array(
		'jumlah_siswa' => 'Siswa',
		'jumlah_guru'  => 'Guru',
	);
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 65 );
		add_action( 'admin_post_sdp80_action', array( __CLASS__, 'action' ) );
		add_shortcode( 'statistik_pendidikan', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'perbandingan_periode_pendidikan', array( __CLASS__, 'period_comparison' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'sdp education', array( __CLASS__, 'cli' ), array( 'synopsis' => '[--rounds=<number>]' ) );
		}
	}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Statistik Pendidikan', 'Statistik Pendidikan', 'manage_options', 'sdp-education', array( __CLASS__, 'page' ) );}
	public static function slot( $v ) {
		return is_scalar( $v ) && preg_match( '/^(?:[1-9]|10)$/D', (string) $v ) ? (int) $v : 0;}
	public static function key( $slot ) {
		return 'sdp80_snapshot_' . $slot;}
	public static function filters( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'scope', 'Filter tidak valid.' );
		}$f = array();
		foreach ( array( 'provinsi', 'kabupaten', 'kecamatan', 'jenjang', 'period' ) as $k ) {
			$v = $raw[ $k ] ?? '';
			if ( ! is_string( $v ) || strlen( $v ) > 120 ) {
				return new WP_Error( 'scope', 'Filter tidak valid.' );
			}$f[ $k ] = sanitize_text_field( trim( $v ) );}
		if ( ( '' !== $f['kabupaten'] && '' === $f['provinsi'] ) || ( '' !== $f['kecamatan'] && '' === $f['kabupaten'] ) ) {
			return new WP_Error( 'scope', 'Pilih wilayah dari provinsi ke kabupaten lalu kecamatan.' );
		}
		if ( '' !== $f['jenjang'] && ! in_array( $f['jenjang'], SDP_Directory::levels(), true ) ) {
			return new WP_Error( 'scope', 'Jenjang tidak valid.' );
		}
		if ( ! self::period_key( $f['period'] ) ) {
			return new WP_Error( 'period', 'Periode wajib: YYYY, YYYY/YYYY atau YYYY/YYYY ganjil/genap.' );
		}
		$f['period'] = self::period_key( $f['period'] );
		return $f;
	}
	public static function period_key( $p ) {
		$v = SDP_Intelligence::period( $p );
		if ( ! $v ) {
			return '';
		}return (string) $v['year'] . ( 'academic' === $v['kind'] ? '/' . ( $v['year'] + 1 ) . ( 1 === $v['semester'] ? ' ganjil' : ( 2 === $v['semester'] ? ' genap' : '' ) ) : '' );}
	public static function group_field( $f ) {
		return '' === $f['provinsi'] ? 'provinsi' : ( '' === $f['kabupaten'] ? 'kabupaten' : ( '' === $f['kecamatan'] ? 'kecamatan' : 'jenjang' ) );}
	public static function blank() {
		return array(
			'schools'        => 0,
			'filled'         => 0,
			'possible'       => 0,
			'sum'            => array_fill_keys( array_keys( self::STATS ), 0 ),
			'known'          => array_fill_keys( array_keys( self::STATS ), 0 ),
			'ratio_students' => 0,
			'ratio_teachers' => 0,
			'ratio_n'        => 0,
			'facility'       => array_fill_keys(
				array_keys( self::FAC ),
				array(
					'known'    => 0,
					'positive' => 0,
				)
			),
			'levels'         => array(),
			'accreditation'  => array(),
			'status'         => array(),
		);}
	public static function initial( $f, $slot ) {
		return array(
			'slot'        => $slot,
			'filter'      => $f,
			'group_field' => self::group_field( $f ),
			'status'      => 'running',
			'cursor'      => '',
			'scanned'     => 0,
			'invalid'     => 0,
			'demo'        => 0,
			'start'       => current_time( 'mysql', true ),
			'end'         => '',
			'total'       => self::blank(),
			'groups'      => array(),
		);}
	public static function category( $value, $allowed ) {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : 'Tidak diketahui / lainnya';}
	public static function count_category( &$a, $key ) {
		$a[ $key ] = ( $a[ $key ] ?? 0 ) + 1;}
	public static function add( &$a, $d, $period ) {
		++$a['schools'];
		$c              = SDP_Intelligence::completeness( $d );
		$a['filled']   += $c['filled'];
		$a['possible'] += $c['total'];
		self::count_category( $a['levels'], self::category( $d['jenjang'] ?? '', SDP_Directory::levels() ) );
		self::count_category( $a['accreditation'], self::category( $d['akreditasi'] ?? '', array( 'A', 'B', 'C', 'UNGGUL', 'BAIK', 'BAIK SEKALI', 'TIDAK TERAKREDITASI' ) ) );
		self::count_category( $a['status'], self::category( $d['status'] ?? '', array( 'Negeri', 'Swasta' ) ) );
		$vals = array();
		foreach ( self::STATS as $k => $label ) {
			$n = SDP_Portal::num( $d, $k );
			$p = self::period_key( SDP_Intelligence::field_period( $d, $k ) );
			if ( null !== $n && '' !== $p && $p === $period ) {
				++$a['known'][ $k ];
				$a['sum'][ $k ] += $n;
				$vals[ $k ]      = $n;}
		}
		if ( isset( $vals['jumlah_siswa'], $vals['jumlah_guru'] ) && $vals['jumlah_guru'] > 0 ) {
			++$a['ratio_n'];
			$a['ratio_students'] += $vals['jumlah_siswa'];
			$a['ratio_teachers'] += $vals['jumlah_guru'];}
		foreach ( self::FAC as $k => $label ) {
			$n = SDP_Portal::num( $d, $k );
			if ( null !== $n ) {
				++$a['facility'][ $k ]['known'];
				if ( $n > 0 ) {
					++$a['facility'][ $k ]['positive'];
				}
			}
		}
	}
	public static function ingest( $job, $rows ) {
		foreach ( $rows as $r ) {
			$id = $r['npsn'] ?? '';
			if ( ! is_string( $id ) || ! preg_match( '/^[A-Z0-9]{8}$/D', $id ) || strcmp( $id, $job['cursor'] ) <= 0 ) {
				return new WP_Error( 'cursor', 'Indeks NPSN tidak berurutan/valid. Perbaiki sebelum melanjutkan.' );
			}$job['cursor'] = $id;
			++$job['scanned'];
			$d = json_decode( $r['payload'] ?? '', true );
			if ( ! is_array( $d ) || ( $d['npsn'] ?? null ) !== $id || ! is_string( $d['nama'] ?? null ) ) {
				++$job['invalid'];
				continue;
			}if ( ! is_string( $d['sumber'] ?? '' ) ) {
				++$job['invalid'];
				continue;
			}if ( SDP_SEO::demo( $d ) ) {
				++$job['demo'];
				continue;}
			$valid = true;
			foreach ( SDP_Directory::indexed() as $k ) {
				if ( ! is_string( $r[ $k ] ?? null ) || ( $d[ $k ] ?? '' ) !== $r[ $k ] ) {
					$valid = false;
							break;
				}
			}if ( ! $valid ) {
				++$job['invalid'];
				continue;}
			$key = $r[ $job['group_field'] ];
			if ( ! SDP_SEO::present( $r, $job['group_field'] ) ) {
				$key = 'Tidak diketahui / lainnya';
			}$key = 'g:' . $key;
			if ( ! isset( $job['groups'][ $key ] ) ) {
				if ( count( $job['groups'] ) >= self::MAX_GROUPS ) {
					return new WP_Error( 'groups', 'Terlalu banyak kelompok wilayah; gunakan cakupan lebih sempit.' );
				}$job['groups'][ $key ] = self::blank();}
			self::add( $job['total'], $d, $job['filter']['period'] );
			self::add( $job['groups'][ $key ], $d, $job['filter']['period'] );
		}return $job;
	}
	public static function select( $j ) {
		global $wpdb;
		[$from, $args] = SDP_Portal::from( $j['filter'] );
		$sql           = 'SELECT s.npsn,s.payload,s.nama,s.provinsi,s.kabupaten,s.kecamatan,s.jenjang,s.status,s.akreditasi' . $from . ' AND s.npsn>%s ORDER BY s.npsn LIMIT %d';
		$args[]        = $j['cursor'];
		$args[]        = SDP_Resource::cap( self::BATCH, 25 ) + 1;
		$rows          = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'database', 'Gagal membaca indeks; checkpoint tidak berubah.' );
		}return $rows;}
	public static function store( $key, $value ) {
		if ( update_option( $key, $value, false ) || get_option( $key ) === $value ) {
			return true;
		}return new WP_Error( 'database', 'Gagal menyimpan checkpoint/snapshot.' );}
	public static function lock( $fn ) {
		if ( SDP_Resource::enabled() ) {
			return SDP_Collector::lock( $fn );
		}global $wpdb;
		$key = 'sdp80_' . md5( SDP_Directory::table() );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $key ) ) !== '1' ) {
			return new WP_Error( 'busy', 'Proses statistik lain sedang berjalan.' );
		}try {
			return $fn();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );}}
	public static function step() {
		return self::lock(
			function () {
				$j = get_option( self::JOB, array() );
				if ( ( $j['status'] ?? '' ) !== 'running' ) {
					return new WP_Error( 'job', 'Tidak ada proses berjalan.' );
				}$rows = self::select( $j );
				if ( is_wp_error( $rows ) ) {
					return $rows;
				}$batch = SDP_Resource::cap( self::BATCH, 25 );
				$more   = count( $rows ) > $batch;
				$j      = self::ingest( $j, array_slice( $rows, 0, $batch ) );
				if ( is_wp_error( $j ) ) {
					return $j;
				}
				if ( ! $more ) {
					$j['status'] = 'complete';
					$j['end']    = current_time( 'mysql', true );
					$s           = $j;
					unset( $s['cursor'] );
					$r = self::store( self::key( $j['slot'] ), $s );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
				}
				$r = self::store( self::JOB, $j );
				return is_wp_error( $r ) ? $r : $j;
			}
		);}
	public static function start( $raw, $slot ) {
		$f = self::filters( $raw );
		if ( is_wp_error( $f ) ) {
			return $f;
		}if ( ! self::slot( $slot ) ) {
			return new WP_Error( 'slot', 'Slot 1–10 wajib.' );
		}return self::lock(
			function () use ( $f, $slot ) {
				$j = get_option( self::JOB, array() );
				if ( ( $j['status'] ?? '' ) === 'running' ) {
					return new WP_Error( 'job', 'Selesaikan atau batalkan proses yang berjalan.' );
				}return self::store( self::JOB, self::initial( $f, (int) $slot ) );
			}
		);}
	public static function ratio( $a ) {
		return $a['ratio_teachers'] > 0 ? $a['ratio_students'] / $a['ratio_teachers'] : null;}
	public static function completeness( $a ) {
		return $a['possible'] > 0 ? 100 * $a['filled'] / $a['possible'] : null;}
	public static function fmt( $n, $digits = 0 ) {
		return null === $n ? '—' : number_format( $n, $digits, ',', '.' );}
	public static function scope( $f ) {
		$s = array_filter( array( $f['provinsi'], $f['kabupaten'], $f['kecamatan'], $f['jenjang'] ), static fn( $v )=>'' !== $v );
		return $s ? implode( ' / ', $s ) : 'Seluruh cakupan direktori';}
	public static function render( $s ) {
		$a  = $s['total'];
		$f  = $s['filter'];
		$h  = '<section class="sdp80"><h2>Statistik pendidikan: ' . esc_html( self::scope( $f ) ) . '</h2><p>Snapshot direktori publik ' . esc_html( $s['start'] . ' – ' . $s['end'] ) . ' UTC. Bukan sensus resmi; perubahan setelah atau selama pemindaian mungkin belum tercakup. Periode statistik siswa/guru: ' . esc_html( $f['period'] ) . '.</p>';
		$h .= '<div class="sdp80-grid">';
		foreach ( array(
			'Profil sekolah'              => $a['schools'],
			'Siswa tercatat (' . $a['known']['jumlah_siswa'] . ' sekolah)' => $a['known']['jumlah_siswa'] ? $a['sum']['jumlah_siswa'] : null,
			'Guru tercatat (' . $a['known']['jumlah_guru'] . ' sekolah)' => $a['known']['jumlah_guru'] ? $a['sum']['jumlah_guru'] : null,
			'Kelengkapan atribut relevan' => self::completeness( $a ),
		) as $label => $v ) {
			$pct = 'Kelengkapan atribut relevan' === $label;
			$h  .= '<div class="sdp80-card"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( self::fmt( $v, $pct ? 1 : 0 ) . ( $pct && null !== $v ? '%' : '' ) ) . '</strong></div>';
		}$h .= '</div><p>Rasio siswa/guru: ' . esc_html( self::fmt( self::ratio( $a ), 1 ) ) . ' (' . $a['ratio_n'] . ' sekolah dengan kedua nilai pada periode yang sama dan guru &gt; 0). Data tanpa periode per atribut tidak dihitung dalam total siswa/guru.</p>';
		foreach ( array(
			'levels'        => 'Jenjang',
			'accreditation' => 'Akreditasi tercatat (berbagai periode)',
			'status'        => 'Status sekolah',
		) as $k => $label ) {
			$h     .= '<h3>' . esc_html( $label ) . '</h3>';
			$groups = $a[ $k ];
			arsort( $groups );
			foreach ( $groups as $name => $n ) {
				$pct = $a['schools'] ? 100 * $n / $a['schools'] : 0;
				$h  .= '<div class="sdp80-bar"><span>' . esc_html( $name ) . ' — ' . esc_html( self::fmt( $n ) ) . '</span><progress max="100" value="' . esc_attr( (string) $pct ) . '" aria-label="' . esc_attr( $name ) . '"></progress></div>';}
		}
		$h .= '<h3>Fasilitas tercatat (periode bervariasi)</h3><table><thead><tr><th>Fasilitas</th><th>Memiliki (&gt;0)</th><th>Jumlah diketahui</th><th>Belum diketahui</th></tr></thead><tbody>';
		foreach ( self::FAC as $k => $label ) {
			$v  = $a['facility'][ $k ];
			$h .= '<tr><th>' . esc_html( $label ) . '</th><td>' . $v['positive'] . '</td><td>' . $v['known'] . '</td><td>' . ( $a['schools'] - $v['known'] ) . '</td></tr>';
		}$h    .= '</tbody></table><h3>Perbandingan menurut ' . esc_html( $s['group_field'] ) . '</h3><div class="sdp80-scroll"><table><thead><tr><th>Wilayah / jenjang</th><th>Profil</th><th>Siswa (cakupan)</th><th>Guru (cakupan)</th><th>Rasio (cakupan)</th><th>Kelengkapan</th></tr></thead><tbody>';
		$groups = $s['groups'];
		ksort( $groups, SORT_NATURAL | SORT_FLAG_CASE );
		foreach ( $groups as $name => $g ) {
			$h .= '<tr><th>' . esc_html( substr( $name, 2 ) ) . '</th><td>' . $g['schools'] . '</td><td>' . esc_html( self::fmt( $g['known']['jumlah_siswa'] ? $g['sum']['jumlah_siswa'] : null ) ) . ' (' . $g['known']['jumlah_siswa'] . ')</td><td>' . esc_html( self::fmt( $g['known']['jumlah_guru'] ? $g['sum']['jumlah_guru'] : null ) ) . ' (' . $g['known']['jumlah_guru'] . ')</td><td>' . esc_html( self::fmt( self::ratio( $g ), 1 ) ) . ' (' . $g['ratio_n'] . ')</td><td>' . esc_html( self::fmt( self::completeness( $g ), 1 ) ) . '%</td></tr>';
		}$h .= '</tbody></table></div><p>— berarti tidak tersedia. Rekaman fiktif terdeteksi dan indeks tidak valid dikecualikan (' . $s['demo'] . ' fiktif, ' . $s['invalid'] . ' tidak valid). Kelengkapan bukan skor mutu sekolah.</p></section>';
		return $h;
	}
	public static function shortcode( $atts = array() ) {
		$slot = self::slot( is_array( $atts ) ? ( $atts['slot'] ?? 1 ) : 1 );
		if ( ! $slot ) {
			return '<p>Slot statistik tidak valid.</p>';
		}$s = get_option( self::key( $slot ), array() );
		if ( ( $s['status'] ?? '' ) !== 'complete' ) {
			return '<p>Statistik belum tersedia. Administrator perlu menyelesaikan snapshot.</p>';
		}wp_enqueue_style( 'sdp80-education', plugins_url( 'assets/education.css', dirname( __DIR__ ) . '/sekolah-directory-pro.php' ), array(), SDP_Directory::VERSION );
		return self::render( $s );}
	/** Compare stored snapshots only, not a matched historical cohort. */
	public static function compare_snapshots( $snapshots ) {
		if ( count( $snapshots ) < 2 ) {
			return new WP_Error( 'compare', 'Minimal dua snapshot selesai.' );
		}$base   = null;
		$kind    = null;
		$sem     = null;
		$periods = array();
		foreach ( $snapshots as $s ) {
			if ( ( $s['status'] ?? '' ) !== 'complete' ) {
				return new WP_Error( 'compare', 'Seluruh snapshot harus selesai.' );
			}$f  = $s['filter'];
			$p   = SDP_Intelligence::period( $f['period'] );
			$key = self::period_key( $f['period'] );
			unset( $f['period'] );
			if ( ! $p || isset( $periods[ $key ] ) ) {
				return new WP_Error( 'compare', 'Periode harus valid dan berbeda.' );
			}if ( null === $base ) {
				$base = $f;
				$kind = $p['kind'];
				$sem  = 0 !== $p['semester'];
			} elseif ( $base !== $f || $kind !== $p['kind'] || $sem !== ( 0 !== $p['semester'] ) ) {
				return new WP_Error( 'compare', 'Cakupan dan jenis periode harus sama.' );
			}$periods[ $key ] = $s;}
		ksort( $periods, SORT_STRING );
		return $periods;
	}
	public static function period_comparison( $atts = array() ) {
		$raw = is_array( $atts ) ? ( $atts['slots'] ?? '' ) : '';
		if ( ! is_string( $raw ) || strlen( $raw ) > 40 ) {
			return '<p>Slot perbandingan tidak valid.</p>';
		}$slots = array_map( 'trim', explode( ',', $raw ) );
		if ( count( $slots ) > self::SLOTS || count( array_unique( $slots ) ) !== count( $slots ) ) {
			return '<p>Slot perbandingan tidak valid.</p>';
		}$snapshots = array();
		foreach ( $slots as $slot ) {
			$n = self::slot( $slot );
			if ( ! $n ) {
				return '<p>Slot perbandingan tidak valid.</p>';
			}$snapshots[] = get_option( self::key( $n ), array() );
		}$p = self::compare_snapshots( $snapshots );
		if ( is_wp_error( $p ) ) {
			return '<p>' . esc_html( $p->get_error_message() ) . '</p>';
		}
		wp_enqueue_style( 'sdp80-education', plugins_url( 'assets/education.css', dirname( __DIR__ ) . '/sekolah-directory-pro.php' ), array(), SDP_Directory::VERSION );
		$first = reset( $p );
		$h     = '<section class="sdp80"><h2>Perbandingan snapshot periode: ' . esc_html( self::scope( $first['filter'] ) ) . '</h2><p>Perbandingan agregat dari snapshot tersimpan. Cakupan sekolah dan waktu pemindaian dapat berbeda; bukan tren historis dari kohort sekolah yang sama. Perhatikan jumlah sekolah dengan data yang diketahui.</p>';
		foreach ( self::STATS as $k => $label ) {
			$h  .= '<h3>' . esc_html( $label ) . ' tercatat</h3>';
			$max = 0;
			foreach ( $p as $s ) {
				if ( $s['total']['known'][ $k ] ) {
					$max = max( $max, $s['total']['sum'][ $k ] );
				}
			}foreach ( $p as $period => $s ) {
				$a     = $s['total'];
				$known = $a['known'][ $k ];
				$v     = $known ? $a['sum'][ $k ] : null;
				$h    .= '<div class="sdp80-bar"><span>' . esc_html( $period . ' — ' . self::fmt( $v ) . ' (' . $known . '/' . $a['schools'] . ' sekolah)' ) . '</span>';
				if ( null !== $v ) {
					$h .= '<progress max="' . esc_attr( (string) max( 1, $max ) ) . '" value="' . esc_attr( (string) $v ) . '" aria-label="' . esc_attr( $label . ' ' . $period ) . '"></progress>';
				}$h .= '</div>';}
		}
		$h .= '<p>Waktu selesai (UTC): ';
		foreach ( $p as $period => $s ) {
			$h .= esc_html( $period . ': ' . $s['end'] ) . '; ';
		}return $h . '</p></section>';
	}
	public static function distribution_keys() {
		return array(
			'levels'        => array_merge( SDP_Directory::levels(), array( 'Tidak diketahui / lainnya' ) ),
			'accreditation' => array( 'A', 'B', 'C', 'UNGGUL', 'BAIK', 'BAIK SEKALI', 'TIDAK TERAKREDITASI', 'Tidak diketahui / lainnya' ),
			'status'        => array( 'Negeri', 'Swasta', 'Tidak diketahui / lainnya' ),
		);}
	public static function csv( $fp, $s ) {
		$head = array( 'cakupan', 'periode_siswa_guru', 'mulai_utc', 'selesai_utc', 'kelompok', 'profil', 'siswa_total', 'siswa_cakupan', 'guru_total', 'guru_cakupan', 'rasio_siswa_guru', 'rasio_cakupan', 'atribut_terisi', 'atribut_relevan' );
		foreach ( self::FAC as $k => $label ) {
			$head[] = $k . '_memiliki';
			$head[] = $k . '_diketahui';
		}foreach ( self::distribution_keys() as $k => $labels ) {
			foreach ( $labels as $label ) {
				$head[] = $k . ':' . $label;
			}
		}fputcsv( $fp, $head, ',', '"', '' );
		foreach ( array( 'total' => $s['total'] ) + $s['groups'] as $k => $a ) {
			$row = array( self::scope( $s['filter'] ), $s['filter']['period'], $s['start'], $s['end'], 'total' === $k ? 'TOTAL' : substr( $k, 2 ), $a['schools'], $a['known']['jumlah_siswa'] ? $a['sum']['jumlah_siswa'] : '', $a['known']['jumlah_siswa'], $a['known']['jumlah_guru'] ? $a['sum']['jumlah_guru'] : '', $a['known']['jumlah_guru'], self::ratio( $a ) ?? '', $a['ratio_n'], $a['filled'], $a['possible'] );
			foreach ( self::FAC as $field => $label ) {
				$row[] = $a['facility'][ $field ]['positive'];
				$row[] = $a['facility'][ $field ]['known'];
			}foreach ( self::distribution_keys() as $dist => $labels ) {
				foreach ( $labels as $label ) {
					$row[] = $a[ $dist ][ $label ] ?? 0;
				}
			}fputcsv( $fp, array_map( array( SDP_Missing::class, 'csv_cell' ), $row ), ',', '"', '' );}
	}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp80_action' );
		$op   = $_POST['op'] ?? '';
		$slot = self::slot( $_POST['slot'] ?? 1 );
		if ( 'start' === $op ) {
			$r = self::start( wp_unslash( $_POST['filter'] ?? array() ), $slot );
		} elseif ( 'step' === $op ) {
			$r = self::step();
		} elseif ( 'cancel' === $op ) {
			$r = self::lock( static fn()=>self::store( self::JOB, array() ) );
		} elseif ( 'delete' === $op && $slot ) {
			$r = self::lock( static fn()=>self::store( self::key( $slot ), array() ) );
		} elseif ( 'export' === $op && $slot ) {
			$s = get_option( self::key( $slot ), array() );
			if ( ( $s['status'] ?? '' ) !== 'complete' ) {
				wp_die( 'Snapshot belum tersedia.' );
			}nocache_headers();
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="statistik-pendidikan-' . $slot . '.csv"' );
			$fp = fopen( 'php://output', 'w' );
			self::csv( $fp, $s );
			fclose( $fp );
			exit;
		} else {
			wp_die( 'Operasi tidak valid.' );
		}if ( is_wp_error( $r ) ) {
			wp_die( esc_html( $r->get_error_message() ) );
		}wp_safe_redirect( admin_url( 'admin.php?page=sdp-education' ) );
		exit;
	}
	public static function form() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp80_action">';
		wp_nonce_field( 'sdp80_action' );}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}$j = get_option( self::JOB, array() );
		echo '<div class="wrap"><h1>Statistik Pendidikan — v8.0</h1><p>Bangun snapshot dari profil publik. Satu pekerjaan aktif, ' . SDP_Resource::cap( self::BATCH, 25 ) . ' rekaman per putaran. Maksimal 10 snapshot tersimpan. Pilih periode eksplisit; data tanpa periode per atribut tidak masuk jumlah siswa/guru. Jangan jalankan impor atau perubahan profil saat pemindaian jika membutuhkan konsistensi.</p>';
		if ( ( $j['status'] ?? '' ) === 'running' ) {
			echo '<p>Proses: ' . esc_html( self::scope( $j['filter'] ) ) . ' | Slot ' . (int) $j['slot'] . ' | Dipindai ' . (int) $j['scanned'] . ' | Profil lolos ' . (int) $j['total']['schools'] . '</p>';
			self::form();
			echo '<button class="button button-primary" name="op" value="step">Proses ' . SDP_Resource::cap( self::BATCH, 25 ) . ' berikutnya</button> <button class="button" name="op" value="cancel">Batalkan pekerjaan</button></form>';
		} else {
			self::form();
				echo '<h2>Mulai snapshot</h2><label>Slot <input type="number" min="1" max="10" name="slot" value="1" required></label>';
			foreach ( array(
				'provinsi'  => 'Provinsi',
				'kabupaten' => 'Kabupaten / kota',
				'kecamatan' => 'Kecamatan',
				'jenjang'   => 'Jenjang (kosong = semua)',
				'period'    => 'Periode siswa / guru',
			) as $k => $label ) {
				echo '<p><label>' . esc_html( $label ) . ' <input name="filter[' . esc_attr( $k ) . ']" value="" ' . ( 'period' === $k ? 'required placeholder="2025/2026 ganjil"' : 'placeholder="Nama persis / kosong"' ) . '></label></p>';
			}echo '<button class="button button-primary" name="op" value="start">Mulai</button></form>';}
		echo '<h2>Snapshot selesai</h2>';for ( $i = 1;$i <= self::SLOTS;$i++ ) {
			$s = get_option( self::key( $i ), array() );
			if ( ( $s['status'] ?? '' ) !== 'complete' ) {
				continue;
			}echo '<h3>Slot ' . $i . ' — ' . esc_html( self::scope( $s['filter'] ) ) . '</h3><p>Periode ' . esc_html( $s['filter']['period'] ) . ' | ' . (int) $s['total']['schools'] . ' sekolah | ' . esc_html( $s['end'] ) . ' UTC | <code>[statistik_pendidikan slot="' . $i . '"]</code></p>';
			self::form();
			echo '<input type="hidden" name="slot" value="' . $i . '"><button class="button" name="op" value="export">Unduh CSV</button> <button class="button" name="op" value="delete">Hapus snapshot</button></form>';}
		echo '<p>Buat halaman WordPress dan masukkan shortcode slot. Jalankan <code>wp sdp education --rounds=10</code> dari Site Shell untuk memproses hingga ' . ( SDP_Resource::cap( 10, 5 ) * SDP_Resource::cap( self::BATCH, 25 ) ) . ' rekaman per panggilan. Statistik lama tetap tampil sampai snapshot baru selesai. Bandingkan slot yang cakupannya sama dengan <code>[perbandingan_periode_pendidikan slots="1,2"]</code>.</p></div>';
	}
	public static function cli( $args, $assoc ) {
		$v = $assoc['rounds'] ?? 10;
		if ( ! is_scalar( $v ) || ! preg_match( '/^\d{1,3}$/D', (string) $v ) || (int) $v < 1 || (int) $v > 100 ) {
			WP_CLI::error( 'Rounds 1–100.' );
		}for ( $i = 0;$i < SDP_Resource::cap( (int) $v, 5 );$i++ ) {
			$r = self::step();
			if ( is_wp_error( $r ) ) {
				WP_CLI::error( $r->get_error_message() );
			}WP_CLI::log( $r['status'] . ' | dipindai ' . $r['scanned'] );
			if ( 'complete' === $r['status'] ) {
				break;
			}
		}}
}
