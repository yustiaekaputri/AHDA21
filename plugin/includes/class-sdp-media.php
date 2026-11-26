<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Public, explicitly authorized school media. No crawling or image search. */
final class SDP_Media {
	const MAX_BYTES  = 5242880;
	const MAX_EDGE   = 5000;
	const MAX_PIXELS = 12000000;
	const LIMIT      = 12;
	const KEY        = '_sdp64_media';
	const ROLES      = array(
		'logo'     => 'Logo sekolah',
		'cover'    => 'Foto utama',
		'building' => 'Gedung sekolah',
		'facility' => 'Sarana prasarana',
		'gallery'  => 'Galeri',
	);
	const RIGHTS     = array(
		'permission' => 'Izin pemegang hak',
		'own'        => 'Karya sendiri / milik sekolah dengan izin',
		'cc0'        => 'CC0 1.0',
		'cc-by'      => 'CC BY 4.0',
		'cc-by-sa'   => 'CC BY-SA 4.0',
	);
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 35 );
		add_action( 'admin_post_sdp64_media', array( __CLASS__, 'action' ) );
		add_filter( 'the_content', array( __CLASS__, 'profile' ), 24 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'init', array( __CLASS__, 'thumbnail_support' ), 20 );
	}
	public static function thumbnail_support() {
		add_post_type_support( SDP_Directory::TYPE, 'thumbnail' );}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Media Sekolah', 'Media Sekolah', 'manage_options', 'sdp-media', array( __CLASS__, 'page' ) );}
	public static function assets() {
		if ( is_singular( SDP_Directory::TYPE ) ) {
			wp_enqueue_style( 'sdp-media', plugins_url( 'assets/media.css', dirname( __DIR__ ) . '/sekolah-directory-pro.php' ), array(), SDP_Directory::VERSION );
		}}
	public static function clean( $v, $max ) {
		return is_string( $v ) ? SDP_SEO::short( sanitize_text_field( $v ), $max ) : '';}
	public static function evidence_url( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 1000 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$p = parse_url( $url );
		return $p && strtolower( $p['scheme'] ?? '' ) === 'https' && ! empty( $p['host'] ) && ! isset( $p['user'] ) && ! isset( $p['pass'] ) ? esc_url_raw( $url, array( 'https' ) ) : '';
	}
	public static function validate( $raw ) {
		if ( ! is_array( $raw ) || ( $raw['authorized'] ?? null ) !== '1' || ( $raw['privacy'] ?? null ) !== '1' ) {
			return new WP_Error( 'consent', 'Konfirmasi izin penggunaan dan privasi wajib.' );
		}
		$role   = $raw['role'] ?? '';
		$rights = $raw['rights'] ?? '';
		$mode   = $raw['mode'] ?? '';
		if ( ! is_string( $role ) || ! isset( self::ROLES[ $role ] ) || ! is_string( $rights ) || ! isset( self::RIGHTS[ $rights ] ) || ! in_array( $mode, array( 'file', 'url' ), true ) ) {
			return new WP_Error( 'input', 'Jenis, metode atau izin gambar tidak valid.' );
		}
		$alt    = self::clean( $raw['alt'] ?? '', 180 );
		$credit = self::clean( $raw['credit'] ?? '', 180 );
		$proof  = self::clean( $raw['proof'] ?? '', 500 );
		$source = $raw['source'] ?? '';
		$url    = $raw['url'] ?? '';
		if ( ! $alt || ! $credit || ! $proof || ! is_string( $source ) || ! is_string( $url ) ) {
			return new WP_Error( 'input', 'Isi alt text, kredit pencipta dan catatan izin.' );
		}
		if ( '' !== $source && ! self::evidence_url( $source ) ) {
			return new WP_Error( 'source', 'URL referensi harus HTTPS tanpa kredensial.' );
		}
		if ( 'url' === $mode && ! self::evidence_url( $url ) ) {
			return new WP_Error( 'source', 'URL gambar harus HTTPS.' );
		}
		$licenses = array(
			'cc0'      => 'https://creativecommons.org/publicdomain/zero/1.0/',
			'cc-by'    => 'https://creativecommons.org/licenses/by/4.0/',
			'cc-by-sa' => 'https://creativecommons.org/licenses/by-sa/4.0/',
		);
		$license  = $licenses[ $rights ] ?? '';
		if ( $license && ( '' === $source || ( $raw['license_confirm'] ?? '' ) !== '1' ) ) {
			return new WP_Error( 'source', 'Creative Commons membutuhkan URL sumber dan konfirmasi versi lisensi yang tepat (CC0 1.0 atau CC BY/BY-SA 4.0).' );
		}
		return array(
			'role'    => $role,
			'rights'  => $rights,
			'license' => $license,
			'mode'    => $mode,
			'alt'     => $alt,
			'credit'  => $credit,
			'proof'   => $proof,
			'source'  => '' === $source ? '' : self::evidence_url( $source ),
			'url'     => 'url' === $mode ? $url : '',
			'date'    => gmdate( 'Y-m-d H:i:s' ),
		);
	}
	public static function load( $npsn ) {
		if ( ! SDP_Engine::valid_npsn( $npsn ) ) {
			return new WP_Error( 'identity', 'NPSN tidak valid atau data demo.' );
		}
		global $wpdb;
		$t = SDP_Directory::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT s.*,p.post_status,p.post_password,p.post_type FROM $t s JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE s.npsn=%s", $npsn ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'db', 'Database tidak tersedia.' );
		}
		$d = json_decode( $r['payload'] ?? '', true );
		if ( ! $r || ( $r['post_status'] ?? '' ) !== 'publish' || ( $r['post_password'] ?? null ) !== '' || ( $r['post_type'] ?? '' ) !== SDP_Directory::TYPE || ! is_array( $d ) || ( $d['npsn'] ?? '' ) !== $npsn || empty( $d['nama'] ) || SDP_SEO::demo( $d ) ) {
			return new WP_Error( 'identity', 'Hanya profil nyata yang terbit dan tanpa sandi yang dapat menerima media publik.' );
		}
		return array(
			'post_id' => (int) $r['post_id'],
			'data'    => $d,
		);
	}
	public static function inspect( $path ) {
		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'file', 'File tidak dapat dibaca.' );
		}
		$size = filesize( $path );
		if ( false === $size || $size < 1 || $size > SDP_Resource::cap( self::MAX_BYTES, 1048576 ) ) {
			return new WP_Error( 'size', 'Ukuran file melebihi batas mode aktif.' );
		}
		$info    = @getimagesize( $path );
		$allowed = array(
			IMAGETYPE_JPEG => 'image/jpeg',
			IMAGETYPE_PNG  => 'image/png',
			IMAGETYPE_WEBP => 'image/webp',
		);
		if ( ! $info || ! isset( $allowed[ $info[2] ] ) || ( $info['mime'] ?? '' ) !== $allowed[ $info[2] ] ) {
			return new WP_Error( 'format', 'Hanya JPEG, PNG, dan WebP.' );
		}
		if ( $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_EDGE || $info[1] > self::MAX_EDGE || $info[0] * $info[1] > SDP_Resource::cap( self::MAX_PIXELS, 4000000 ) ) {
			return new WP_Error( 'pixels', 'Resolusi melebihi batas mode aktif.' );
		}
		// Reject animated PNG/WebP; no multi-frame image processing.
		$bytes = file_get_contents( $path );
		if ( false === $bytes ) {
			return new WP_Error( 'file', 'File tidak dapat dibaca.' );
		}
		if ( ( IMAGETYPE_PNG === $info[2] && strpos( $bytes, 'acTL' ) !== false ) || ( IMAGETYPE_WEBP === $info[2] && ( strpos( $bytes, 'ANIM' ) !== false || strpos( $bytes, 'ANMF' ) !== false ) ) ) {
			return new WP_Error( 'animated', 'Gambar animasi tidak didukung.' );
		}
		return array(
			'width'  => $info[0],
			'height' => $info[1],
			'mime'   => $info['mime'],
			'hash'   => hash( 'sha256', $bytes ),
		);
	}
	public static function dimensions( $w, $h, $role ) {
		$edge  = 'logo' === $role ? SDP_Resource::cap( 800, 400 ) : SDP_Resource::cap( 1600, 1000 );
		$ratio = min( 1, $edge / max( 1, $w, $h ) );
		return array( max( 1, (int) round( $w * $ratio ) ), max( 1, (int) round( $h * $ratio ) ) );}
	public static function encode( $path, $info, $role ) {
		// New raster file: no EXIF/IPTC copied; source bytes never placed in uploads.
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			return new WP_Error( 'gd', 'PHP GD diperlukan untuk pemrosesan gambar aman. Aktifkan GD pada LocalWP.' );
		}
		$bytes = file_get_contents( $path );
		$im    = false === $bytes ? false : @imagecreatefromstring( $bytes );
		unset( $bytes );
		if ( ! $im ) {
			return new WP_Error( 'decode', 'Gambar tidak dapat didekode.' );
		}
		$canvas = null;
		$temp   = null;
		$ok     = false;
		try {
			if ( imagesx( $im ) !== $info['width'] || imagesy( $im ) !== $info['height'] ) {
				return new WP_Error( 'decode', 'Dimensi gambar tidak sesuai.' );
			}
			// Preserve standard JPEG EXIF orientation where PHP EXIF is available.
			if ( 'image/jpeg' === $info['mime'] && function_exists( 'exif_read_data' ) ) {
				$exif = @exif_read_data( $path, 'IFD0' );
				$o    = (int) ( $exif['Orientation'] ?? 1 );
				if ( in_array( $o, array( 2, 4, 5, 7 ), true ) ) {
					imageflip( $im, in_array( $o, array( 2, 5, 7 ), true ) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL );
				}
				$angle = array(
					3 => 180,
					5 => 90,
					6 => -90,
					7 => -90,
					8 => 90,
				)[ $o ] ?? 0;
				if ( $angle ) {
					$rot = imagerotate( $im, $angle, 0 );
					if ( ! $rot ) {
						return new WP_Error( 'decode', 'Orientasi gambar gagal diproses.' );
					}imagedestroy( $im );
					$im = $rot;}
			}
			[$w, $h] = self::dimensions( imagesx( $im ), imagesy( $im ), $role );
			$canvas  = imagecreatetruecolor( $w, $h );
			if ( ! $canvas ) {
				return new WP_Error( 'encode', 'Gagal membuat raster.' );
			}
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			imagefill( $canvas, 0, 0, imagecolorallocatealpha( $canvas, 0, 0, 0, 127 ) );
			if ( ! imagecopyresampled( $canvas, $im, 0, 0, 0, 0, $w, $h, imagesx( $im ), imagesy( $im ) ) ) {
				return new WP_Error( 'encode', 'Gagal mengubah ukuran.' );
			}
			$webp = function_exists( 'imagewebp' ) && defined( 'IMG_WEBP' ) && ( imagetypes() & IMG_WEBP );
			$ext  = $webp ? 'webp' : ( 'image/jpeg' === $info['mime'] ? 'jpg' : 'png' );
			$temp = wp_tempnam( 'sdp-image.' . $ext );
			if ( ! $temp ) {
				return new WP_Error( 'temp', 'Gagal membuat file sementara.' );
			}
			$saved = $webp ? @imagewebp( $canvas, $temp, 82 ) : ( 'jpg' === $ext ? @imagejpeg( $canvas, $temp, 85 ) : @imagepng( $canvas, $temp, 6 ) );
			clearstatcache( true, $temp );
			$out = $saved ? self::inspect( $temp ) : new WP_Error( 'encode', 'Gagal menyimpan raster.' );
			if ( is_wp_error( $out ) ) {
				return $out;
			}
			if ( $out['mime'] !== array(
				'jpg'  => 'image/jpeg',
				'png'  => 'image/png',
				'webp' => 'image/webp',
			)[ $ext ] ) {
				return new WP_Error( 'encode', 'Format keluaran berbeda.' );
			}
			$ok = true;
			return array(
				'path' => $temp,
				'ext'  => $ext,
			);
		} finally {
			if ( $canvas ) {
				imagedestroy( $canvas );
			}imagedestroy( $im );
			if ( ! $ok && $temp && is_file( $temp ) ) {
				@unlink( $temp );
			}
		}
	}
	public static function fetch( $url, $website ) {
		$host = SDP_Website::validate_url( $url, $website );
		if ( is_wp_error( $host ) ) {
			return $host;
		}
		$rate = 'sdp61_rate_' . md5( $host );
		if ( get_transient( $rate ) ) {
			return new WP_Error( 'rate', 'Tunggu 60 detik untuk domain ini.' );
		}
		set_transient( $rate, 1, 60 );
		$r = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => SDP_Resource::cap( self::MAX_BYTES, 1048576 ) + 1,
				'sslverify'           => true,
				'headers'             => array( 'Accept' => 'image/jpeg,image/png,image/webp' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( (int) wp_remote_retrieve_response_code( $r ) !== 200 ) {
			return new WP_Error( 'http', 'Sumber tidak mengembalikan HTTP 200. Pengambilan dihentikan.' );
		}
		$mime = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $r, 'content-type' ) )[0] ) );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return new WP_Error( 'format', 'Respons bukan tipe gambar yang diizinkan.' );
		}
		$body = wp_remote_retrieve_body( $r );
		if ( ! is_string( $body ) || strlen( $body ) < 1 || strlen( $body ) > SDP_Resource::cap( self::MAX_BYTES, 1048576 ) ) {
			return new WP_Error( 'size', 'Respons melebihi batas atau kosong.' );
		}
		$tmp = wp_tempnam( 'sdp-source' );
		if ( ! $tmp ) {
			return new WP_Error( 'temp', 'Gagal membuat file sementara.' );
		}
		if ( file_put_contents( $tmp, $body ) !== strlen( $body ) ) {
			@unlink( $tmp );
			return new WP_Error( 'temp', 'Gagal menulis file sementara.' );}
		$i = self::inspect( $tmp );
		if ( is_wp_error( $i ) || $i['mime'] !== $mime ) {
			@unlink( $tmp );
			return is_wp_error( $i ) ? $i : new WP_Error( 'format', 'Tipe gambar tidak cocok.' );
		}return $tmp;
	}
	public static function items( $pid, $npsn ) {
		global $wpdb;
		$out = array();
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'post_parent'      => $pid,
				'posts_per_page'   => self::LIMIT + 1,
				'fields'           => 'ids',
				'meta_key'         => self::KEY,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'db', 'Gagal membaca daftar media.' );
		}
		foreach ( $ids as $id ) {
			$m = get_post_meta( $id, self::KEY, true );
			if ( is_array( $m ) && ( $m['npsn'] ?? '' ) === $npsn && isset( self::ROLES[ $m['role'] ?? '' ] ) && isset( self::RIGHTS[ $m['rights'] ?? '' ] ) && wp_attachment_is_image( $id ) ) {
				$out[ (int) $id ] = $m;
			}
		}
		return $out;
	}
	public static function check_add( $items, $meta, $hash, $thumb = 0 ) {
		if ( count( $items ) >= self::LIMIT ) {
			return new WP_Error( 'limit', 'Maksimal 12 gambar per sekolah.' );
		}
		if ( 'cover' === $meta['role'] && $thumb ) {
			return new WP_Error( 'cover', 'Foto utama sudah tersedia. Hapus atau lepaskan featured image lama terlebih dahulu.' );
		}
		foreach ( $items as $m ) {
			if ( ( $m['hash'] ?? '' ) === $hash ) {
				return new WP_Error( 'duplicate', 'Gambar identik sudah tersimpan pada sekolah ini.' );
			}if ( in_array( $meta['role'], array( 'logo', 'cover' ), true ) && $meta['role'] === ( $m['role'] ?? '' ) ) {
				return new WP_Error( 'role', 'Logo atau foto utama sudah ada. Hapus dahulu apabila ingin menggantinya.' );
			}
		}return true;
	}
	public static function store( $school, $path, $meta ) {
		global $wpdb;
		$npsn = $school['data']['npsn'];
		$pid  = $school['post_id'];
		$lock = 'sdp_' . md5( SDP_Directory::table() . $npsn );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) !== '1' ) {
			return new WP_Error( 'busy', 'Profil sedang diproses. Coba kembali.' );
		}
		$encoded   = null;
		$id        = 0;
		$committed = false;
		try {
			$current = self::load( $npsn );
			if ( is_wp_error( $current ) || $current['post_id'] !== $pid ) {
				return new WP_Error( 'identity', 'Profil berubah. Buka ulang halaman.' );
			}
			if ( 'url' === $meta['mode'] && is_wp_error( SDP_Website::validate_url( $meta['url'], $current['data']['website'] ?? '' ) ) ) {
				return new WP_Error( 'identity', 'Website resmi berubah.' );
			}
			$info = self::inspect( $path );
			if ( is_wp_error( $info ) ) {
				return $info;
			}
			$items = self::items( $pid, $npsn );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			$check = self::check_add( $items, $meta, $info['hash'], (int) get_post_thumbnail_id( $pid ) );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$encoded = self::encode( $path, $info, $meta['role'] );
			if ( is_wp_error( $encoded ) ) {
				return $encoded;
			}
			$id = media_handle_sideload(
				array(
					'name'     => 'sdp-' . strtolower( $npsn ) . '-' . $meta['role'] . '.' . $encoded['ext'],
					'tmp_name' => $encoded['path'],
					'error'    => 0,
					'size'     => filesize( $encoded['path'] ),
				),
				$pid,
				null,
				array(
					'post_title'   => $meta['alt'],
					'post_content' => '',
					'post_excerpt' => '',
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}if ( ! $id ) {
				return new WP_Error( 'media', 'Media gagal disimpan.' );
			}
			$meta['npsn'] = $npsn;
			$meta['hash'] = $info['hash'];
			// Proof and hash remain protected post meta, not returned by public API.
			if ( ! update_post_meta( $id, self::KEY, wp_slash( $meta ) ) || ! update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $meta['alt'] ) ) ) {
				return new WP_Error( 'meta', 'Metadata gagal disimpan.' );
			}
			if ( 'cover' === $meta['role'] && ! set_post_thumbnail( $pid, $id ) ) {
				return new WP_Error( 'cover', 'Featured image gagal ditetapkan.' );
			}
			$committed = true;
			return $id;
		} finally {
			if ( ! $committed && is_int( $id ) && $id > 0 ) {
				if ( (int) get_post_thumbnail_id( $pid ) === $id ) {
					delete_post_thumbnail( $pid );
				}wp_delete_attachment( $id, true );}
			if ( is_array( $encoded ) && is_file( $encoded['path'] ) ) {
				@unlink( $encoded['path'] );
			}
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
	public static function remove( $pid, $npsn, $id ) {
		$p = get_post( $id );
		$m = get_post_meta( $id, self::KEY, true );
		if ( ! $p || 'attachment' !== $p->post_type || (int) $p->post_parent !== $pid || ! is_array( $m ) || ( $m['npsn'] ?? '' ) !== $npsn ) {
			return new WP_Error( 'ownership', 'Media bukan milik profil ini.' );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'access', 'Tidak berhak menghapus media.' );
		}
		if ( ! wp_delete_attachment( $id, true ) ) {
			return new WP_Error( 'delete', 'Gagal menghapus media.' );
		}return true;
	}
	public static function render( $items ) {
		if ( is_wp_error( $items ) ) {
			return '<section class="sdp64-media"><h2>Foto sekolah</h2><p>Media sementara tidak tersedia.</p></section>';
		}
		if ( ! $items ) {
			return '<section class="sdp64-media"><h2>Foto sekolah</h2><p class="sdp64-placeholder">Belum ada foto sekolah yang berizin dan terverifikasi administrator.</p></section>';
		}
		$html = '<section class="sdp64-media"><h2>Foto dan logo sekolah</h2><div class="sdp64-gallery">';
		foreach ( $items as $id => $m ) {
			$img = wp_get_attachment_image(
				$id,
				'medium_large',
				false,
				array(
					'alt'      => $m['alt'] ?? '',
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			);
			if ( ! $img ) {
				continue;
			}
			$url   = self::evidence_url( $m['source'] ?? '' ) ?: self::evidence_url( $m['url'] ?? '' );
			$html .= '<figure>' . $img . '<figcaption><strong>' . esc_html( self::ROLES[ $m['role'] ] ) . '</strong> — ' . esc_html( $m['credit'] ?? '' ) . ' · ' . esc_html( self::RIGHTS[ $m['rights'] ] ) . ( ! empty( $m['license'] ) && self::evidence_url( $m['license'] ) ? ' · <a href="' . esc_url( $m['license'] ) . '" rel="nofollow noopener noreferrer">Lisensi</a>' : '' ) . ' · Gambar diubah ukuran/format' . ( $url ? ' · <a href="' . esc_url( $url ) . '" rel="nofollow noopener noreferrer">Sumber / izin</a>' : '' ) . '</figcaption></figure>';}
		return $html . '</div></section>';
	}
	public static function profile( $content ) {
		if ( ! is_singular( SDP_Directory::TYPE ) || ! is_main_query() || ! in_the_loop() || post_password_required() ) {
			return $content;
		}
		$pid = get_queried_object_id();
		if ( ! SDP_Portal::context( $pid ) ) {
			return $content;
		}$d = SDP_SEO::current();
		if ( ! $d || SDP_SEO::demo( $d ) ) {
			return $content;
		}
		return $content . self::render( self::items( $pid, $d['npsn'] ) );
	}
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) {
			wp_die( 'Akses ditolak.' );
		}}
	private static function finish( $n, $result ) {
		set_transient( 'sdp64_notice_' . get_current_user_id(), is_wp_error( $result ) ? $result->get_error_message() : 'Perubahan media berhasil.', 60 );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'sdp-media',
					'npsn' => substr( $n, 0, 8 ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;}
	public static function action() {
		self::guard();
		check_admin_referer( 'sdp64_media' );
		$raw    = wp_unslash( $_POST );
		$n      = is_string( $raw['npsn'] ?? null ) ? strtoupper( trim( $raw['npsn'] ) ) : '';
		$school = self::load( $n );
		if ( is_wp_error( $school ) ) {
			self::finish( $n, $school );
		}
		if ( ( $raw['op'] ?? '' ) === 'remove' ) {
			if ( ( $raw['confirm_delete'] ?? '' ) !== '1' ) {
				self::finish( $n, new WP_Error( 'confirm', 'Konfirmasi penghapusan permanen wajib.' ) );
			}
			self::finish( $n, self::remove( $school['post_id'], $n, absint( $raw['id'] ?? 0 ) ) );
		}
		$meta = self::validate( $raw );
		if ( is_wp_error( $meta ) ) {
			self::finish( $n, $meta );
		}
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			self::finish( $n, new WP_Error( 'gd', 'PHP GD diperlukan sebelum mengambil atau menyimpan media.' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$temp = false;
		if ( 'url' === $meta['mode'] ) {
			$path = self::fetch( $meta['url'], $school['data']['website'] ?? '' );
			if ( is_wp_error( $path ) ) {
				self::finish( $n, $path );
			}$temp = true;
		} else {
			$f    = $_FILES['image'] ?? array();
			$path = $f['tmp_name'] ?? '';
			if ( ! is_string( $path ) || ( $f['error'] ?? 1 ) !== UPLOAD_ERR_OK || ! is_uploaded_file( $path ) ) {
				self::finish( $n, new WP_Error( 'upload', 'Pilih satu file gambar yang valid.' ) );
			}
		}
		try {
			$result = self::store( $school, $path, $meta );
		} finally {
			if ( $temp && is_file( $path ) ) {
				@unlink( $path );
			}
		}
			self::finish( $n, $result );
	}
	public static function page() {
		self::guard();
		$raw    = wp_unslash( $_GET['npsn'] ?? '' );
		$n      = is_string( $raw ) ? strtoupper( trim( $raw ) ) : '';
		$notice = get_transient( 'sdp64_notice_' . get_current_user_id() );
		delete_transient( 'sdp64_notice_' . get_current_user_id() );
		echo '<div class="wrap"><h1>Media Sekolah</h1><p>Unggah foto yang sah atau ambil satu URL gambar dari domain resmi tercatat. File WordPress bersifat publik, termasuk bila profil kemudian diprivatkan. Jangan gunakan foto anak yang dapat dikenali tanpa dasar izin yang sesuai. Periksa isi gambar sebelum menyimpan. Jika profil kemudian menjadi privat atau dihapus, hapus file publik yang tidak lagi boleh tersedia melalui Media Library.</p>';
		if ( $notice ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>';
		}
		echo '<form method="get"><input type="hidden" name="page" value="sdp-media"><label>NPSN <input name="npsn" maxlength="8" value="' . esc_attr( $n ) . '" required></label> <button class="button">Buka profil</button></form>';
		if ( ! $n ) {
			echo '</div>';
			return;
		}$s = self::load( $n );
		if ( is_wp_error( $s ) ) {
			echo '<p>' . esc_html( $s->get_error_message() ) . '</p></div>';
			return;}
		$d     = $s['data'];
		$pid   = $s['post_id'];
		$items = self::items( $pid, $n );
		if ( is_wp_error( $items ) ) {
			echo '<p>' . esc_html( $items->get_error_message() ) . '</p></div>';
			return;
		}echo '<h2>' . esc_html( $d['nama'] ) . '</h2><p>Website tercatat: ' . esc_html( $d['website'] ?? 'Belum ada' ) . '</p><p>' . count( $items ) . ' / ' . (int) self::LIMIT . ' media. PHP GD: ' . ( function_exists( 'imagecreatefromstring' ) ? 'tersedia' : 'tidak tersedia' ) . '.</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp64_media"><input type="hidden" name="npsn" value="' . esc_attr( $n ) . '">';
		wp_nonce_field( 'sdp64_media' );
		echo '<table class="form-table"><tr><th><label for="sdp64-mode">Metode</label></th><td><select id="sdp64-mode" name="mode"><option value="file">Unggah file</option><option value="url">URL gambar langsung</option></select></td></tr><tr><th><label for="sdp64-file">File</label></th><td><input id="sdp64-file" name="image" type="file" accept="image/jpeg,image/png,image/webp"></td></tr><tr><th><label for="sdp64-url">URL gambar</label></th><td><input class="regular-text" id="sdp64-url" name="url" type="url" placeholder="https://..."> <p class="description">HTTPS, domain persis website tercatat, tanpa query, redirect, port atau kredensial.</p></td></tr><tr><th><label for="sdp64-role">Jenis</label></th><td><select id="sdp64-role" name="role">';
		foreach ( self::ROLES as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
		}echo '</select></td></tr><tr><th><label for="sdp64-rights">Izin</label></th><td><select id="sdp64-rights" name="rights">';
		foreach ( self::RIGHTS as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
		}echo '</select></td></tr>';
		foreach ( array(
			'alt'    => 'Alt text (deskripsi faktual)',
			'credit' => 'Kredit pencipta / pemegang hak',
			'source' => 'URL bukti / lisensi publik (HTTPS)',
			'proof'  => 'Catatan bukti izin privat, versi lisensi dan syarat',
		) as $k => $label ) {
			echo '<tr><th><label for="sdp64-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="sdp64-' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" ' . ( 'source' === $k ? 'type="url"' : 'required' ) . ' maxlength="' . ( 'source' === $k ? 1000 : ( 'proof' === $k ? 500 : 180 ) ) . '"></td></tr>';
		}
		echo '</table><p><label><input type="checkbox" name="license_confirm" value="1"> Jika Creative Commons: saya memastikan lisensi CC0 1.0 atau CC BY/BY-SA 4.0 berlaku pada gambar sumber, dan hasil olahan memakai lisensi yang sama.</label></p><p><label><input type="checkbox" name="authorized" value="1" required> Saya telah memeriksa hak penggunaan, izin perubahan ukuran/format, atribusi dan syarat lisensi.</label></p><p><label><input type="checkbox" name="privacy" value="1" required> Saya telah meninjau gambar, tidak memuat informasi pribadi yang tidak diizinkan, dan memahami file akan langsung publik.</label></p><button class="button button-primary">Simpan media publik</button></form><hr><h2>Media tersimpan</h2>' . self::render( $items );
		foreach ( $items as $id => $m ) {
			echo '<div style="margin:12px 0"><strong>#' . (int) $id . ' — ' . esc_html( $m['alt'] ?? '' ) . '</strong><p>Catatan izin: ' . esc_html( $m['proof'] ?? '' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp64_media"><input type="hidden" name="op" value="remove"><input type="hidden" name="npsn" value="' . esc_attr( $n ) . '"><input type="hidden" name="id" value="' . (int) $id . '">';
			wp_nonce_field( 'sdp64_media' );
			echo '<label><input type="checkbox" name="confirm_delete" value="1" required> Hapus permanen file dan turunan dari Media Library (dapat memengaruhi halaman lain).</label> <button class="button">Hapus</button></form></div>';}
		echo '</div>';
	}
}
