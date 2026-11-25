<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Administrator-only discovery. No frontend hooks, cron, or automatic publication. */
final class SDP_Discovery {
	const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';
	const FIELDS   = array( 'npsn', 'nama', 'jenjang', 'status', 'provinsi', 'kabupaten', 'kecamatan', 'website' );
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 75 );
		add_action( 'admin_post_sdp_discovery', array( __CLASS__, 'action' ) );}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Penemuan Website', 'Penemuan Website', 'manage_options', 'sdp-discovery', array( __CLASS__, 'page' ) );}
	public static function limits() {
		return array(
			'results' => SDP_Resource::cap( 10, 5 ),
			'bytes'   => SDP_Resource::cap( 524288, 262144 ),
			'timeout' => SDP_Resource::cap( 12, 8 ),
			'ttl'     => 900,
		);}
	public static function url( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 1000 || ! filter_var( $url, FILTER_VALIDATE_URL ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return '';
		}
		$p = parse_url( $url );
		if ( ! $p || strtolower( $p['scheme'] ?? '' ) !== 'https' || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) || isset( $p['query'] ) || isset( $p['fragment'] ) ) {
			return '';
		}
		$h = strtolower( $p['host'] ?? '' );
		if ( ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $h ) || filter_var( $h, FILTER_VALIDATE_IP ) || preg_match( '/\.(?:local|localhost|internal|test|invalid|onion|example|home|lan)$/D', $h ) ) {
			return '';
		}
		return 'https://' . $h . ( $p['path'] ?? '/' );
	}
	public static function kind( $url ) {
		$h = parse_url( $url, PHP_URL_HOST ) ?: '';
		foreach ( array( 'facebook.com', 'instagram.com', 'youtube.com', 'youtu.be', 'tiktok.com', 'x.com', 'twitter.com' ) as $s ) {
			if ( $h === $s || str_ends_with( $h, '.' . $s ) ) {
				return 'social';
			}
		}return str_ends_with( $h, '.sch.id' ) ? 'school-domain' : 'website';}
	public static function fingerprint( $d ) {
		$a = array();
		foreach ( self::FIELDS as $f ) {
			$a[ $f ] = $d[ $f ] ?? '';
		}return hash( 'sha256', json_encode( $a ) );}
	public static function load( $id ) {
		if ( ! SDP_Engine::valid_npsn( $id ) ) {
			return new WP_Error( 'identity', 'NPSN tidak valid atau demo.' );
		}
		global $wpdb;
		$t = SDP_Directory::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT s.*,p.post_status,p.post_password,p.post_type FROM $t s JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE s.npsn=%s", $id ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'database', 'Gagal membaca database.' );
		}
		if ( ! $r || ( $r['post_type'] ?? '' ) !== SDP_Directory::TYPE || ! in_array( $r['post_status'] ?? '', array( 'publish', 'draft', 'pending' ), true ) || ( $r['post_password'] ?? null ) !== '' ) {
			return new WP_Error( 'identity', 'Profil tidak tersedia atau terlindungi.' );
		}
		$d = json_decode( $r['payload'] ?? '', true );
		if ( ! is_array( $d ) || ( $d['npsn'] ?? null ) !== $id || empty( $d['nama'] ) || SDP_SEO::demo( $d ) ) {
			return new WP_Error( 'identity', 'Identitas tidak valid.' );
		}return $d;
	}
	private static function key( $s ) {
		return strtolower( trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', (string) $s ) ?? '' ) );}
	private static function match( $text, $v ) {
		$v = self::key( $v );
		return strlen( $v ) >= 3 && str_contains( ' ' . $text . ' ', ' ' . $v . ' ' );}
	public static function query( $d, $mode = 'identity' ) {
		$parts = array();
		foreach ( array( 'nama', 'kabupaten', 'provinsi' ) as $k ) {
			$v = self::key( $d[ $k ] ?? '' );
			if ( '' !== $v ) {
				$parts[] = '"' . substr( $v, 0, 100 ) . '"';
			}
		}
		if ( 'npsn' === $mode ) {
			$parts[] = '"' . $d['npsn'] . '"';
		}
		return implode( ' ', $parts ) . ' website resmi sekolah';
	}
	public static function candidates( $body ) {
		if ( ! is_string( $body ) || strlen( $body ) > self::limits()['bytes'] ) {
			return new WP_Error( 'body', 'Respons pencarian terlalu besar.' );
		}
		$j = json_decode( $body, true );
		if ( ! is_array( $j ) || ! isset( $j['web']['results'] ) || ! is_array( $j['web']['results'] ) ) {
			return new WP_Error( 'body', 'Format respons pencarian tidak dikenali.' );
		}
		$out = array();
		foreach ( array_slice( $j['web']['results'], 0, 20 ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}$u = self::url( $r['url'] ?? '' );
			if ( ! $u || isset( $out[ $u ] ) ) {
				continue;
			}$out[ $u ] = array(
				'url'  => $u,
				'kind' => self::kind( $u ),
			);
			if ( count( $out ) >= self::limits()['results'] ) {
				break;
			}
		}return array_values( $out );
	}
	private static function rate() {
		if ( get_transient( 'sdp84_network_rate' ) ) {
			return new WP_Error( 'rate', 'Tunggu 60 detik sebelum permintaan jaringan berikutnya.' );
		}if ( ! set_transient( 'sdp84_network_rate', 1, 60 ) ) {
			return new WP_Error( 'rate', 'Pembatas permintaan tidak tersedia.' );
		}return true;}
	public static function search( $d, $mode ) {
		if ( ! defined( 'SDP_BRAVE_SEARCH_KEY' ) || ! is_string( SDP_BRAVE_SEARCH_KEY ) || ! preg_match( '/^[A-Za-z0-9_\-]{10,200}$/D', SDP_BRAVE_SEARCH_KEY ) ) {
			return new WP_Error( 'key', 'API pencarian opsional belum dikonfigurasi.' );
		}
		$l    = self::limits();
		$rate = self::rate();
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		$u = self::ENDPOINT . '?' . http_build_query(
			array(
				'q'          => self::query( $d, $mode ),
				'count'      => $l['results'],
				'safesearch' => 'strict',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$r = wp_safe_remote_get(
			$u,
			array(
				'timeout'             => $l['timeout'],
				'redirection'         => 0,
				'limit_response_size' => $l['bytes'] + 1,
				'sslverify'           => true,
				'headers'             => array(
					'Accept'               => 'application/json',
					'X-Subscription-Token' => SDP_BRAVE_SEARCH_KEY,
				),
			)
		);
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'network', 'Permintaan pencarian gagal. Tidak ada retry otomatis.' );
		}$c = wp_remote_retrieve_response_code( $r );
		if ( 200 !== $c ) {
			return new WP_Error( 'http', 'Pencarian HTTP ' . (int) $c . '. Periksa kuota/izin; tidak ada retry otomatis.' );
		}return self::candidates( wp_remote_retrieve_body( $r ) );
	}
	public static function public_ip( $ip ) {
		if ( ! is_string( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		if ( defined( 'FILTER_FLAG_GLOBAL_RANGE' ) && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE ) ) {
			return false;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$a           = array_map( 'intval', explode( '.', $ip ) );
			[$x, $y, $z] = $a;
			return $x > 0 && $x < 224 && ! ( 100 === $x && $y >= 64 && $y <= 127 ) && ! ( 169 === $x && 254 === $y ) && ! ( 192 === $x && ( 0 === $y || ( 88 === $y && 99 === $z ) ) ) && ! ( 198 === $x && ( 18 === $y || 19 === $y || ( 51 === $y && 100 === $z ) ) ) && ! ( 203 === $x && 0 === $y && 113 === $z );
		}
		return preg_match( '/^[23][0-9a-f]{3}:/i', $ip ) === 1 && ! preg_match( '/^(?:2001:db8:|2001:0:|2002:)/i', $ip );
	}
	private static function dns_public( $host ) {
		$rs = @dns_get_record( $host, DNS_A | DNS_AAAA );
		if ( ! is_array( $rs ) || ! $rs ) {
			return false;
		}$found = false;
		foreach ( $rs as $r ) {
			$ip = $r['ip'] ?? $r['ipv6'] ?? null;
			if ( null === $ip ) {
				continue;
			}$found = true;
			if ( ! self::public_ip( $ip ) ) {
				return false;
			}
		}return $found;}
	public static function assess( $html, $d, $url ) {
		if ( ! is_string( $html ) || strlen( $html ) > self::limits()['bytes'] || ! preg_match( '//u', $html ) ) {
			return new WP_Error( 'body', 'HTML terlalu besar atau bukan UTF-8.' );
		}
		$html = preg_replace( '~<!--.*?-->|<(script|style|noscript|template|iframe|svg)\b[^>]*>.*?</\1\s*>~is', ' ', $html );
		if ( null === $html || preg_match( '~<(?:script|style|template|iframe)\b~i', $html ) ) {
			return new WP_Error( 'body', 'Struktur HTML tidak didukung.' );
		}
		$plain    = html_entity_decode( strip_tags( preg_replace( '~<[^>]+>~', ' ', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text     = self::key( $plain );
		$exact    = false;
		$conflict = false;
		preg_match_all( '/\bNPSN\s*[:=\-]?\s*([A-Z0-9]{8})\b/i', $plain, $m );
		foreach ( $m[1] as $id ) {
			if ( strtoupper( $id ) === $d['npsn'] ) {
				$exact = true;
			} else {
				$conflict = true;
			}
		}
		$name   = self::match( $text, $d['nama'] ?? '' );
		$region = self::match( $text, $d['kabupaten'] ?? '' ) || self::match( $text, $d['kecamatan'] ?? '' );
		$school = self::kind( $url ) === 'school-domain';
		$score  = ( $exact ? 50 : 0 ) + ( $name ? 30 : 0 ) + ( $region ? 15 : 0 ) + ( $school ? 5 : 0 );
		return array(
			'score'         => $conflict ? 0 : $score,
			'npsn'          => $exact,
			'name'          => $name,
			'region'        => $region,
			'school_domain' => $school,
			'conflict'      => $conflict,
			'eligible'      => ! $conflict && ( $exact || ( $name && $region ) ),
		);
	}
	public static function verify( $url, $d ) {
		$u = self::url( $url );
		if ( ! $u || self::kind( $u ) === 'social' ) {
			return new WP_Error( 'url', 'Gunakan URL HTTPS situs sekolah, bukan media sosial, tanpa query, port atau kredensial.' );
		}
		if ( ! wp_http_validate_url( $u ) || ! self::dns_public( parse_url( $u, PHP_URL_HOST ) ) ) {
			return new WP_Error( 'url', 'Alamat DNS tidak dapat diverifikasi sebagai publik.' );
		}
		$l    = self::limits();
		$rate = self::rate();
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		$r = wp_safe_remote_get(
			$u,
			array(
				'timeout'             => $l['timeout'],
				'redirection'         => 0,
				'limit_response_size' => $l['bytes'] + 1,
				'sslverify'           => true,
				'headers'             => array( 'Accept' => 'text/html' ),
				'user-agent'          => 'SekolahDirectoryPro/8.4 (manual identity review)',
			)
		);
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'network', 'Koneksi gagal; bukan bukti situs tutup.' );
		}$c = wp_remote_retrieve_response_code( $r );
		if ( 200 !== $c ) {
			return new WP_Error( 'http', 'HTTP ' . (int) $c . '. Tidak mengikuti pengalihan; periksa alamat secara manual.' );
		}
		$t = wp_remote_retrieve_header( $r, 'content-type' );
		if ( ! is_string( $t ) || ! preg_match( '~^text/html(?:\s*;|$)~i', $t ) ) {
			return new WP_Error( 'type', 'Sumber bukan HTML.' );
		}return self::assess( wp_remote_retrieve_body( $r ), $d, $u );
	}
	public static function approval( $p, $d ) {
		if ( ! is_array( $p ) || ( $p['fingerprint'] ?? '' ) !== self::fingerprint( $d ) || empty( $p['assessment']['eligible'] ) || ! empty( $p['assessment']['conflict'] ) ) {
			return new WP_Error( 'review', 'Pratinjau tidak valid, konflik, atau identitas berubah.' );
		}
		$u = self::url( $p['url'] ?? '' );
		if ( ! $u || self::kind( $u ) === 'social' ) {
			return new WP_Error( 'url', 'URL tidak valid.' );
		}if ( ( $d['website'] ?? '' ) !== '' ) {
			return new WP_Error( 'existing', 'Website lama dipertahankan. Gunakan Validasi & Konflik untuk menggantinya.' );
		}return array(
			'npsn'    => $d['npsn'],
			'website' => $u,
		);
	}
	private static function input( $key ) {
		$v = $_POST[ $key ] ?? '';
		return is_string( $v ) ? trim( wp_unslash( $v ) ) : '';}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp_discovery' );
		$uid = get_current_user_id();
		$key = 'sdp84_preview_' . $uid;
		$op  = self::input( 'op' );
		$id  = strtoupper( self::input( 'npsn' ) );
		$r   = SDP_Collector::lock(
			function () use ( $key, $op, $id ) {
				if ( 'clear' === $op ) {
					delete_transient( $key );
					return 'Pratinjau dihapus.';}
				$d = self::load( $id );
				if ( is_wp_error( $d ) ) {
					return $d;
				}$fp = self::fingerprint( $d );
				$p   = get_transient( $key );
				if ( 'load' === $op ) {
					delete_transient( $key );
					return 'Profil ditemukan.';}
				if ( self::input( 'authorized' ) !== '1' ) {
					return new WP_Error( 'permission', 'Konfirmasi izin penggunaan dan akses sumber.' );
				}
				if ( 'search' === $op || 'verify' === $op ) {
					$u    = self::url( self::input( 'url' ) );
					$mode = self::input( 'mode' ) === 'npsn' ? 'npsn' : 'identity';
					if ( is_array( $p ) && ( $p['fingerprint'] ?? '' ) === $fp && ( $p['op'] ?? '' ) === $op && ( 'verify' === $op ? ( $p['url'] ?? '' ) === $u : ( $p['mode'] ?? '' ) === $mode ) ) {
						return 'Menggunakan pratinjau tersimpan (maksimal 15 menit).';
					}
					delete_transient( $key );
					$v = 'search' === $op ? self::search( $d, $mode ) : self::verify( $u, $d );
					if ( is_wp_error( $v ) ) {
						return $v;
					}
					$p = array(
						'op'          => $op,
						'fingerprint' => $fp,
						'npsn'        => $id,
						'url'         => $u,
						'mode'        => $mode,
						'search' === $op ? 'candidates' : 'assessment' => $v,
					);
					if ( ! set_transient( $key, $p, self::limits()['ttl'] ) ) {
						return new WP_Error( 'database', 'Pratinjau gagal disimpan.' );
					}return 'Pratinjau tersedia; belum ada perubahan profil.';
				}
				if ( 'save' === $op ) {
					if ( self::input( 'reviewed' ) !== '1' ) {
						return new WP_Error( 'review', 'Periksa bukti kepemilikan resmi sebelum menyimpan.' );
					}$add = self::approval( $p, $d );
					if ( is_wp_error( $add ) ) {
						return $add;
					}
					$expected = array_intersect_key( $d, array_flip( self::FIELDS ) );
					$res      = SDP_Directory::save(
						$add,
						false,
						'fill',
						array(
							'label' => 'Website sekolah (diperiksa admin)',
							'url'   => $add['website'],
							'type'  => 'website',
						),
						$expected
					);
					if ( is_wp_error( $res ) ) {
						return $res;
					}
					delete_transient( $key );
					return 'Penyimpanan diproses dengan mode isi kosong. Periksa URL akhir profil; website lama tidak ditimpa.';
				}
				return new WP_Error( 'op', 'Operasi tidak dikenal.' );
			}
		);
		set_transient(
			'sdp84_notice_' . $uid,
			array(
				'error' => is_wp_error( $r ),
				'text'  => is_wp_error( $r ) ? $r->get_error_message() : $r,
			),
			120
		);
		wp_safe_redirect( admin_url( 'admin.php?page=sdp-discovery&npsn=' . rawurlencode( $id ) ) );
		exit;
	}
	private static function form( $id ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp_discovery"><input type="hidden" name="npsn" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'sdp_discovery' );}
	private static function consent() {
		echo '<p><label><input type="checkbox" required name="authorized" value="1"> Saya telah memeriksa izin akses, robots.txt, ketentuan sumber/penyedia, dan izin penyimpanan hasil selama 15 menit.</label></p>';}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}$uid = get_current_user_id();
		$n    = get_transient( 'sdp84_notice_' . $uid );
		delete_transient( 'sdp84_notice_' . $uid );
		$p  = get_transient( 'sdp84_preview_' . $uid );
		$id = $_GET['npsn'] ?? '';
		$id = is_string( $id ) ? strtoupper( trim( $id ) ) : '';
		echo '<div class="wrap"><h1>Penemuan Website Sekolah — v8.4</h1><p>Manual dan opsional API. Tidak ada cron baru. Skor adalah petunjuk, bukan bukti situs resmi.</p>';
		if ( is_array( $n ) ) {
			echo '<div class="notice ' . ( $n['error'] ? 'notice-warning' : 'notice-success' ) . '"><p>' . esc_html( $n['text'] ) . '</p></div>';
		}
		echo '<form method="get"><input type="hidden" name="page" value="sdp-discovery"><label>NPSN <input name="npsn" required maxlength="8" value="' . esc_attr( $id ) . '"></label> <button class="button">Tampilkan sekolah</button></form>';
		if ( '' === $id ) {
			echo '</div>';
			return;
		}$d = self::load( $id );
		if ( is_wp_error( $d ) ) {
			echo '<p>' . esc_html( $d->get_error_message() ) . '</p></div>';
			return;}
		if ( ! is_array( $p ) || ( $p['fingerprint'] ?? '' ) !== self::fingerprint( $d ) ) {
			$p = array();
		}
		echo '<h2>' . esc_html( $d['nama'] ) . ' — ' . esc_html( $id ) . '</h2><p>Website tersimpan: ' . esc_html( $d['website'] ?? 'Belum tersedia' ) . '</p><p>Penelusuran manual (dibuka di browser, bukan server): ';
		foreach ( array(
			'identity' => 'Nama dan wilayah',
			'npsn'     => 'Nama dan NPSN',
		) as $m => $label ) {
			echo '<a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://www.google.com/search?q=' . rawurlencode( self::query( $d, $m ) ) ) . '">' . esc_html( $label ) . '</a> ';
		}echo '</p>';
		self::form( $id );
		echo '<h3>API pencarian opsional</h3><p>Konfigurasi SDP_BRAVE_SEARCH_KEY pada wp-config.php. Hanya nama, NPSN (opsional), dan wilayah dikirim. Biaya/kuota mengikuti penyedia.</p><select name="mode"><option value="identity">Nama dan wilayah</option><option value="npsn">Nama dan NPSN</option></select>';
		self::consent();
		echo '<button class="button" name="op" value="search">Cari kandidat</button></form>';
		if ( ( $p['op'] ?? '' ) === 'search' ) {
			echo '<h3>Kandidat (belum diverifikasi)</h3>';
			if ( ! $p['candidates'] ) {
				echo '<p>Tidak ada kandidat HTTPS yang sesuai. Coba pencarian manual.</p>';
			}echo '<ul>';
			foreach ( $p['candidates'] as $c ) {
				echo '<li><a target="_blank" rel="noopener noreferrer" href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['url'] ) . '</a> — ' . esc_html( $c['kind'] ) . '</li>';
			}echo '</ul>';}
		self::form( $id );
		echo '<h3>Periksa satu URL</h3><p><input type="url" required class="large-text" name="url" maxlength="1000" value="' . esc_attr( ( $p['url'] ?? '' ) !== '' ? $p['url'] : ( $d['website'] ?? '' ) ) . '"></p>';
		self::consent();
		echo '<button class="button button-primary" name="op" value="verify">Periksa identitas dan akses</button></form>';
		if ( ( $p['op'] ?? '' ) === 'verify' ) {
			$a = $p['assessment'];
			echo '<h3>Hasil pemeriksaan</h3><p>Skor indikasi: ' . esc_html( (string) $a['score'] ) . '/100. ' . ( $a['conflict'] ? 'Konflik NPSN.' : 'Tidak ada konflik NPSN berlabel yang terdeteksi.' ) . '</p><ul>';
			foreach ( array(
				'npsn'          => 'NPSN cocok',
				'name'          => 'Nama cocok',
				'region'        => 'Wilayah cocok',
				'school_domain' => 'Domain sch.id',
			) as $k => $label ) {
				echo '<li>' . esc_html( $label ) . ': ' . ( $a[ $k ] ? 'Ya' : 'Tidak terdeteksi' ) . '</li>';
			}echo '</ul>';
			if ( $a['eligible'] && ( $d['website'] ?? '' ) === '' ) {
				self::form( $id );
				self::consent();
				echo '<p><label><input required type="checkbox" name="reviewed" value="1"> Saya memeriksa kepemilikan resmi dan URL ini memang milik sekolah tersebut.</label></p><button class="button button-primary" name="op" value="save">Isi website kosong</button></form>';
			} else {
				echo '<p>Belum dapat disimpan otomatis melalui modul ini. Untuk mengganti URL lama, gunakan Validasi &amp; Konflik.</p>';
			}
		}
		self::form( $id );
		echo '<p><button class="button" name="op" value="clear">Hapus pratinjau</button> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=sdp-website&npsn=' . rawurlencode( $id ) ) ) . '">Buka Website Sekolah</a></p></form></div>';
	}
}
