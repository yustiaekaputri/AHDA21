<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Opt-in, single-page enrichment with human review. Never follows external links. */
final class SDP_Website {
	const MAX_BYTES = 1048576;
	const FIELDS    = array( 'visi', 'misi', 'tujuan', 'sejarah', 'ekstrakurikuler', 'prestasi', 'spmb', 'jurusan', 'mitra_industri', 'biaya', 'beasiswa' );
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 29 );
		add_action( 'admin_post_sdp_website', array( __CLASS__, 'action' ) );
	}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Website Sekolah', 'Website Sekolah', 'manage_options', 'sdp-website', array( __CLASS__, 'page' ) );}
	private static function host( $url, $https = false ) {
		if ( ! is_string( $url ) || strlen( $url ) > 1000 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$p = parse_url( $url );
		if ( ! $p || ! in_array( strtolower( $p['scheme'] ?? '' ), $https ? array( 'https' ) : array( 'https', 'http' ), true ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) || isset( $p['port'] ) || isset( $p['query'] ) ) {
			return '';
		}
		$h = strtolower( $p['host'] ?? '' );
		if ( ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $h ) || filter_var( $h, FILTER_VALIDATE_IP ) || preg_match( '/\.(?:local|localhost|internal|test|invalid|onion|example|home|lan)$/D', $h ) ) {
			return '';
		}
		return $h;
	}
	public static function validate_url( $url, $website ) {
		$host = self::host( $url, true );
		if ( ! $host || $host !== self::host( $website ) ) {
			return new WP_Error( 'url', 'Gunakan URL HTTPS publik, tanpa port, query, fragmen atau kredensial, dengan domain persis website yang tercatat.' );
		}return $host;
	}
	public static function load( $id ) {
		if ( ! SDP_Engine::valid_npsn( $id ) ) {
			return new WP_Error( 'identity', 'NPSN tidak valid atau data demo.' );
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
			return new WP_Error( 'identity', 'Identitas profil tidak valid.' );
		}
		if ( ! self::host( $d['website'] ?? '' ) ) {
			return new WP_Error( 'url', 'Isi dahulu website resmi sekolah yang valid di menu Tambah / perbarui.' );
		}return $d;
	}
	private static function plain( $s ) {
		$s = preg_replace( '~<br\s*/?>~i', "\n", $s );
		$s = preg_replace( '~</?(?:h[1-6]|p|li|div|section|article|td|tr)\b[^>]*>~i', ' ', $s );
		$s = html_entity_decode( strip_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/[\s\p{Z}]+/u', ' ', $s ) ?? '' );
	}
	private static function key( $s ) {
		return strtolower( trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s ) ?? '' ) );}
	public static function heading( $s ) {
		$s   = self::key( $s );
		$map = array(
			'visi'                          => 'visi',
			'visi sekolah'                  => 'visi',
			'visi madrasah'                 => 'visi',
			'misi'                          => 'misi',
			'misi sekolah'                  => 'misi',
			'misi madrasah'                 => 'misi',
			'tujuan'                        => 'tujuan',
			'tujuan sekolah'                => 'tujuan',
			'tujuan madrasah'               => 'tujuan',
			'sejarah'                       => 'sejarah',
			'sejarah sekolah'               => 'sejarah',
			'sejarah madrasah'              => 'sejarah',
			'sejarah singkat'               => 'sejarah',
			'ekstrakurikuler'               => 'ekstrakurikuler',
			'kegiatan ekstrakurikuler'      => 'ekstrakurikuler',
			'prestasi'                      => 'prestasi',
			'prestasi sekolah'              => 'prestasi',
			'prestasi akademik'             => 'prestasi',
			'prestasi nonakademik'          => 'prestasi',
			'spmb'                          => 'spmb',
			'ppdb'                          => 'spmb',
			'penerimaan murid baru'         => 'spmb',
			'penerimaan peserta didik baru' => 'spmb',
			'kompetensi keahlian'           => 'jurusan',
			'program keahlian'              => 'jurusan',
			'jurusan'                       => 'jurusan',
			'mitra industri'                => 'mitra_industri',
			'biaya pendidikan'              => 'biaya',
			'beasiswa'                      => 'beasiswa',
			'program beasiswa'              => 'beasiswa',
		);
		return $map[ $s ] ?? '';
	}
	public static function parse( $html, $d ) {
		if ( ! is_string( $html ) || strlen( $html ) > self::MAX_BYTES || ! preg_match( '//u', $html ) ) {
			return new WP_Error( 'body', 'HTML terlalu besar atau bukan UTF-8.' );
		}
		if ( ! is_string( $d['nama'] ?? null ) || ! SDP_Engine::valid_npsn( $d['npsn'] ?? null ) ) {
			return new WP_Error( 'identity', 'Identitas tidak valid.' );
		}
		// Recognize explicit paired heading/paragraph/list blocks only; no generic whole-page copying.
		$html = preg_replace( '~<!--.*?-->|<(script|style|noscript|template|iframe|svg|nav|footer|aside|form|head)\b[^>]*>.*?</\1\s*>~is', ' ', $html );
		if ( $html === null || preg_match( '~<(?:script|style|template|iframe|nav|footer|aside|form|head)\b~i', $html ) ) {
			return new WP_Error( 'body', 'Struktur HTML tidak didukung.' );
		}
		$visible = self::plain( $html );
		$name    = self::key( $d['nama'] );
		$text    = self::key( $visible );
		if ( preg_match_all( '/\bNPSN\s*[:=]?\s*([A-Z0-9]{8})\b/i', $visible, $m ) ) {
			foreach ( $m[1] as $id ) {
				if ( strtoupper( $id ) !== $d['npsn'] ) {
								return new WP_Error( 'identity', 'Halaman menyebut NPSN lain.' );
				}
			}
		}
		$matched = strlen( $name ) >= 5 && str_contains( ' ' . $text . ' ', ' ' . $name . ' ' );
		if ( ! $matched && ! preg_match( '/\b' . preg_quote( $d['npsn'], '/' ) . '\b/i', $visible ) ) {
			return new WP_Error( 'identity', 'Nama atau NPSN sekolah tidak ditemukan pada halaman.' );
		}
		$out    = array();
		$active = '';
		$count  = 0;
		preg_match_all( '~<(h[1-6]|p|li)\b[^>]*>(.*?)</\1\s*>~is', $html, $blocks, PREG_SET_ORDER );
		foreach ( $blocks as $b ) {
			if ( ++$count > 2000 ) {
				break;
			}
			if ( preg_match( '~\b(?:hidden|aria-hidden\s*=\s*[\x22\x27]?true|display\s*:\s*none)\b~i', substr( $b[0], 0, strpos( $b[0], '>' ) + 1 ) ) ) {
				$active = '';
				continue; }
			$s = self::plain( $b[2] );
			if ( strtolower( $b[1][0] ) === 'h' ) {
				$active = self::heading( $s );
				continue;}
			if ( $active === '' || $s === '' || strlen( $s ) > 1000 ) {
				continue;
			}
			// Small candidate snippets only; administrator must rewrite or confirm reuse rights.
			$old  = $out[ $active ] ?? '';
			$next = $old === '' ? $s : $old . "\n" . $s;
			if ( strlen( $next ) <= 1800 ) {
				$out[ $active ] = $next;
			} else {
				$active = '';
			}
		}
		if ( ! $out ) {
			return new WP_Error( 'empty', 'Tidak ada bagian berjudul yang dikenali. Coba URL halaman profil/visi misi yang lebih spesifik.' );
		}
		return $out;
	}
	public static function fetch( $url, $d ) {
		$host = self::validate_url( $url, $d['website'] ?? '' );
		if ( is_wp_error( $host ) ) {
			return $host;
		}
		$rate = 'sdp61_rate_' . md5( $host );
		if ( get_transient( $rate ) ) {
			return new WP_Error( 'rate', 'Tunggu 60 detik sebelum meminta halaman pada domain yang sama.' );
		}
		set_transient( $rate, 1, 60 );
		if ( ! get_transient( $rate ) ) {
			return new WP_Error( 'rate', 'Pembatas permintaan tidak tersedia.' );
		}
		$r = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES + 1,
				'sslverify'           => true,
				'headers'             => array( 'Accept' => 'text/html' ),
				'user-agent'          => 'SekolahDirectoryPro/6.1 (manual school profile review)',
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}$code = wp_remote_retrieve_response_code( $r );
		if ( $code !== 200 ) {
			return new WP_Error( 'http', 'HTTP ' . (int) $code . '. Tidak ada pengalihan atau percobaan ulang otomatis.' );
		}
		$type = wp_remote_retrieve_header( $r, 'content-type' );
		if ( ! is_string( $type ) || ! preg_match( '~^text/html(?:\s*;|$)~i', $type ) ) {
			return new WP_Error( 'type', 'Sumber bukan HTML.' );
		}
		if ( preg_match( '/charset\s*=\s*[\x22\x27]?([^;\s\x22\x27]+)/i', $type, $m ) && strtolower( $m[1] ) !== 'utf-8' ) {
			return new WP_Error( 'type', 'Encoding bukan UTF-8.' );
		}
		return self::parse( wp_remote_retrieve_body( $r ), $d );
	}
	public static function preview( $d, $url, $values ) {
		$expected = array_intersect_key( $d, array_flip( array( 'npsn', 'nama', 'jenjang', 'status', 'provinsi', 'kabupaten', 'kecamatan', 'website' ) ) );
		return array(
			'npsn'     => $d['npsn'],
			'expected' => $expected,
			'url'      => $url,
			'values'   => $values,
			'old'      => array_intersect_key( $d, array_flip( self::FIELDS ) ),
		);
	}
	public static function additions( $p, $d, $selected, $edited ) {
		if ( ! is_array( $p ) || ! is_array( $selected ) || ! is_array( $edited ) || ( $p['npsn'] ?? null ) !== ( $d['npsn'] ?? null ) ) {
			return new WP_Error( 'preview', 'Pratinjau tidak valid.' );
		}
		foreach ( $p['expected'] as $k => $v ) {
			if ( ( $d[ $k ] ?? null ) !== $v ) {
				return new WP_Error( 'identity', 'Identitas atau website berubah. Buat pratinjau lagi.' );
			}
		}
		if ( is_wp_error( self::validate_url( $p['url'], $d['website'] ?? '' ) ) ) {
			return new WP_Error( 'url', 'URL sumber berubah.' );
		}
		$out = array( 'npsn' => $d['npsn'] );foreach ( self::FIELDS as $k ) {
			if ( ! isset( $selected[ $k ] ) || ! isset( $p['values'][ $k ] ) ) {
				continue;
			}
			if ( isset( $d[ $k ] ) && $d[ $k ] !== '' ) {
				continue;
			}
			if ( ! isset( $edited[ $k ] ) || ! is_string( $edited[ $k ] ) || strlen( $edited[ $k ] ) > 1800 ) {
				return new WP_Error( 'field', 'Nilai usulan tidak valid.' );
			}
			$v = sanitize_textarea_field( $edited[ $k ] );
			if ( trim( $v ) !== '' ) {
				$out[ $k ] = $v;
			}
		}
		if ( count( $out ) === 1 ) {
			return new WP_Error( 'empty', 'Tidak ada kolom kosong terpilih untuk disimpan.' );
		}return SDP_Directory::normalize( $out );
	}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp_website' );
		$uid = get_current_user_id();
		$key = 'sdp61_preview_' . $uid;
		$op  = is_string( $_POST['op'] ?? null ) ? $_POST['op'] : '';
		$r   = SDP_Collector::lock(
			function () use ( $key, $op ) {
				if ( $op === 'discard' ) {
					delete_transient( $key );
					return 'Pratinjau dibuang.';}
				if ( ( $_POST['authorized'] ?? '' ) !== '1' ) {
					return new WP_Error( 'permission', 'Konfirmasi izin akses dan penggunaan ulang sumber.' );
				}
				if ( $op === 'preview' ) {
					delete_transient( $key );
					$id  = $_POST['npsn'] ?? '';
					$url = $_POST['url'] ?? '';
					if ( ! is_string( $id ) || ! is_string( $url ) ) {
						return new WP_Error( 'input', 'Input tidak valid.' );
					}
					$d = self::load( strtoupper( trim( wp_unslash( $id ) ) ) );
					if ( is_wp_error( $d ) ) {
						return $d;
					}$url = trim( wp_unslash( $url ) );
					$v    = self::fetch( $url, $d );
					if ( is_wp_error( $v ) ) {
						return $v;
					}
					if ( ! set_transient( $key, self::preview( $d, $url, $v ), 900 ) ) {
						return new WP_Error( 'database', 'Pratinjau gagal disimpan.' );
					}return 'Pratinjau tersedia 15 menit. Belum mengubah profil.';
				}
				if ( $op === 'save' ) {
					$p = get_transient( $key );
					if ( ! is_array( $p ) ) {
						return new WP_Error( 'preview', 'Pratinjau kedaluwarsa.' );
					}
					if ( ( $_POST['reviewed'] ?? '' ) !== '1' ) {
						return new WP_Error( 'review', 'Periksa identitas, fakta, periode, privasi, dan hak publikasi dahulu.' );
					}
					$d = self::load( $p['npsn'] );
					if ( is_wp_error( $d ) ) {
						return $d;
					}
					$add = self::additions( $p, $d, wp_unslash( $_POST['selected'] ?? array() ), wp_unslash( $_POST['edited'] ?? array() ) );
					if ( is_wp_error( $add ) ) {
						return $add;
					}
					$r = SDP_Directory::save(
						$add,
						false,
						'fill',
						array(
							'label' => 'Website resmi sekolah (ditinjau admin)',
							'url'   => $p['url'],
						),
						$p['expected']
					);
					if ( is_wp_error( $r ) ) {
						return $r;
					}delete_transient( $key );
					return 'Profil diperbarui. Kolom lama dipertahankan; status publikasi tidak diubah.';
				}
				return new WP_Error( 'op', 'Operasi tidak dikenal.' );
			}
		);
		set_transient(
			'sdp61_notice_' . $uid,
			array(
				'error' => is_wp_error( $r ),
				'text'  => is_wp_error( $r ) ? $r->get_error_message() : $r,
			),
			120
		);
		wp_safe_redirect( admin_url( 'admin.php?page=sdp-website' ) );
		exit;
	}
	private static function form() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp_website">';
		wp_nonce_field( 'sdp_website' );}
	private static function consent() {
		echo '<p><label><input type="checkbox" required name="authorized" value="1"> Saya telah memeriksa ketentuan akses otomatis, robots.txt, izin penggunaan ulang dan hak publikasi sumber.</label></p>';}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}$uid = get_current_user_id();
		$p    = get_transient( 'sdp61_preview_' . $uid );
		$n    = get_transient( 'sdp61_notice_' . $uid );
		delete_transient( 'sdp61_notice_' . $uid );
		echo '<div class="wrap"><h1>Website Sekolah — Kolektor v6.1</h1><p>Eksperimental. Isi dahulu website resmi pada profil sekolah. Masukkan URL halaman spesifik pada domain yang sama (HTTPS). Tidak mengikuti pengalihan, tidak merayapi seluruh website, dan tidak memproses JavaScript.</p>';
		if ( is_array( $n ) ) {
			echo '<div class="notice ' . ( ! empty( $n['error'] ) ? 'notice-warning' : 'notice-success' ) . '"><p>' . esc_html( $n['text'] ) . '</p></div>';
		}
		$prefill = $_GET['npsn'] ?? '';
		$prefill = is_string( $prefill ) && SDP_Engine::valid_npsn( $prefill ) ? $prefill : '';
		self::form();
		echo '<p><label>NPSN <input required name="npsn" maxlength="8" value="' . esc_attr( $prefill !== '' ? $prefill : ( $p['npsn'] ?? '' ) ) . '"></label></p><p><label>URL halaman resmi <input required type="url" class="large-text" name="url" maxlength="1000" value="' . esc_attr( $prefill !== '' && ( $p['npsn'] ?? '' ) !== $prefill ? '' : ( $p['url'] ?? '' ) ) . '" placeholder="https://domain-sekolah.sch.id/visi-misi/"></label></p>';
		self::consent();
		echo '<button class="button button-primary" name="op" value="preview">Ambil dan pratinjau</button></form>';
		if ( is_array( $p ) ) {
			echo '<h2>Pratinjau untuk ' . esc_html( $p['expected']['nama'] ) . ' (' . esc_html( $p['npsn'] ) . ')</h2><p><a href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener noreferrer">Buka sumber untuk verifikasi</a></p><p>Hasil ekstraksi belum terverifikasi. Gunakan ringkasan faktual yang Anda tulis sendiri, atau teks yang memiliki izin publikasi. Hapus nama siswa, data pribadi, informasi kadaluwarsa dan klaim yang belum terbukti. Hanya kolom benar-benar kosong yang bisa diisi.</p>';
			self::form();
			$labels = SDP_Directory::labels();
			foreach ( $p['values'] as $k => $v ) {
				$has = isset( $p['old'][ $k ] ) && $p['old'][ $k ] !== '';
				echo '<fieldset style="margin:1em 0"><legend><strong>' . esc_html( $labels[ $k ] ) . '</strong></legend><p>' . ( $has ? 'Data lama akan dipertahankan.' : '<label><input type="checkbox" name="selected[' . esc_attr( $k ) . ']" value="1"> Pilih untuk disimpan</label>' ) . '</p><textarea class="large-text" rows="4" maxlength="1800" name="edited[' . esc_attr( $k ) . ']" ' . ( $has ? 'disabled' : '' ) . '>' . esc_textarea( $v ) . '</textarea></fieldset>';}
			self::consent();
			echo '<p><label><input type="checkbox" required name="reviewed" value="1"> Saya telah meninjau identitas, ketepatan fakta, periode, privasi, dan hak publikasi setiap usulan terpilih. Saya memahami profil yang sudah terbit akan langsung diperbarui.</label></p><button class="button button-primary" name="op" value="save">Simpan kolom kosong terpilih</button></form>';
			self::form();
			echo '<p><button class="button" name="op" value="discard">Buang pratinjau</button></p></form>';
		}
		echo '</div>';
	}
}
