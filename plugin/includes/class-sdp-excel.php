<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** XLSX only: bounded ZIP parts, no formula execution, no external relationships. */
final class SDP_Excel {
	const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	public static function available() {
		return class_exists( 'ZipArchive' ) && class_exists( 'XMLReader' );}
	public static function limits() {
		return array(
			'file'    => SDP_Resource::cap( 5 * 1048576, 2 * 1048576 ),
			'xml'     => SDP_Resource::cap( 12 * 1048576, 4 * 1048576 ),
			'csv'     => SDP_Resource::cap( 5 * 1048576, 2 * 1048576 ),
			'rows'    => SDP_Resource::cap( 10000, 1000 ),
			'strings' => SDP_Resource::cap( 100000, 30000 ),
		);}
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 75 );
		add_action( 'admin_post_sdp82_excel', array( __CLASS__, 'action' ) );}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Impor Excel', 'Impor Excel', 'manage_options', 'sdp-excel', array( __CLASS__, 'page' ) );}
	public static function column( $ref ) {
		if ( ! preg_match( '/^([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $ref, $m ) ) {
			throw new RuntimeException( 'Referensi sel tidak valid.' );
		}$n = 0;
		foreach ( str_split( $m[1] ) as $c ) {
			$n = $n * 26 + ord( $c ) - 64;
		}if ( $n > 150 || (int) $m[2] > 1048576 ) {
			throw new RuntimeException( 'Batas 150 kolom atau nomor baris Excel terlampaui.' );
		}return array( $n - 1, (int) $m[2] );}
	public static function check_text( $v ) {
		if ( strlen( $v ) > 32768 || ! preg_match( '//u', $v ) || strpos( $v, "\0" ) !== false ) {
			throw new RuntimeException( 'Isi sel terlalu panjang atau bukan UTF-8.' );
		}return $v;}
	private static function part( $zip, $name, $max, $optional = false ) {
		$s = $zip->statName( $name );
		if ( $s === false ) {
			if ( $optional ) {
				return null;
			}throw new RuntimeException( 'Bagian XLSX tidak ditemukan: ' . $name );
		}if ( $s['size'] > $max ) {
			throw new RuntimeException( 'Bagian XML melebihi batas mode aktif. Pecah workbook.' );
		}$v = $zip->getFromName( $name, $max + 1 );
		if ( ! is_string( $v ) || strlen( $v ) !== (int) $s['size'] || strlen( $v ) > $max ) {
			throw new RuntimeException( 'Bagian XLSX rusak atau terlalu besar.' );
		}if ( ! preg_match( '//u', $v ) || strpos( $v, "\0" ) !== false || preg_match( '/<!\s*(?:DOCTYPE|ENTITY)\b/i', $v ) ) {
			throw new RuntimeException( 'XML tidak aman atau bukan UTF-8.' );
		}return $v;}
	private static function scan( $xml, $callback ) {
		$old = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$r = new XMLReader();
		try {
			if ( ! $r->XML( $xml, null, LIBXML_NONET ) ) {
				throw new RuntimeException( 'XML tidak valid.' );
			}$r->setParserProperty( XMLReader::LOADDTD, false );
			$r->setParserProperty( XMLReader::SUBST_ENTITIES, false );
			$out = $callback( $r );
			foreach ( libxml_get_errors() as $e ) {
				if ( $e->level >= LIBXML_ERR_ERROR ) {
					throw new RuntimeException( 'XML rusak.' );
				}
			}return $out;
		} finally {
			$r->close();
			libxml_clear_errors();
			libxml_use_internal_errors( $old );}}
	private static function element( $r, $name, $ns = self::NS ) {
		return $r->nodeType === XMLReader::ELEMENT && $r->localName === $name && $r->namespaceURI === $ns;}
	private static function end( $r, $name, $depth ) {
		return $r->nodeType === XMLReader::END_ELEMENT && $r->localName === $name && $r->depth === $depth;}
	private static function text_group( $r ) {
		$depth = $r->depth;
		$name  = $r->localName;
		$v     = '';
		if ( $r->isEmptyElement ) {
			return '';
		}while ( $r->read() && ! self::end( $r, $name, $depth ) ) {
			if ( self::element( $r, 't' ) && ( $r->depth === $depth + 1 || ( $r->depth === $depth + 2 && $r->namespaceURI === self::NS ) ) ) { // exclude phonetic annotations by checking rPh in the traversal below
				$v .= $r->readString();
				self::check_text( $v );
			} elseif ( self::element( $r, 'rPh' ) && ! $r->isEmptyElement ) {
				$d = $r->depth;while ( $r->read() && ! self::end( $r, 'rPh', $d ) ) {
				}
			}
		}return self::check_text( $v );}
	private static function strings( $xml, $max ) {
		if ( $xml === null ) {
			return array();
		}return self::scan(
			$xml,
			function ( $r ) use ( $max ) {
				$a = array();
				while ( $r->read() ) {
					if ( self::element( $r, 'si' ) ) {
						if ( count( $a ) >= $max ) {
								throw new RuntimeException( 'Terlalu banyak shared strings. Pecah workbook.' );
						}$a[] = self::text_group( $r );
					}
				}return $a;
			}
		);}
	private static function cell( $r, $strings ) {
		$type    = $r->getAttribute( 't' ) ?? 'n';
		$depth   = $r->depth;
		$value   = '';
		$formula = false;
		if ( ! $r->isEmptyElement ) {
			while ( $r->read() && ! self::end( $r, 'c', $depth ) ) {
				if ( self::element( $r, 'f' ) ) {
					$formula = true;
				} elseif ( self::element( $r, 'v' ) ) {
					$value = $r->readString();
				} elseif ( self::element( $r, 'is' ) ) {
					$value = self::text_group( $r );
				}
			}
		}
		if ( $formula || in_array( $type, array( 'e', 'str' ), true ) ) {
			throw new RuntimeException( 'Formula atau sel error tidak didukung. Salin sebagai nilai di Excel sebelum impor.' );
		}
		if ( $type === 's' ) {
			if ( ! preg_match( '/^\d{1,9}$/D', $value ) || ! array_key_exists( (int) $value, $strings ) ) {
				throw new RuntimeException( 'Indeks shared string tidak valid.' );
			}$value = $strings[ (int) $value ];
		} elseif ( ! in_array( $type, array( 'n', 'inlineStr', 'b', 'd' ), true ) ) {
			throw new RuntimeException( 'Tipe sel tidak didukung.' );
		}return self::check_text( $value );}
	private static function row( $r, $strings ) {
		$d    = $r->depth;
		$a    = array();
		$last = -1;
		$row  = (int) $r->getAttribute( 'r' );
		if ( $r->isEmptyElement ) {
			return array();
		}while ( $r->read() && ! self::end( $r, 'row', $d ) ) {
			if ( ! self::element( $r, 'c' ) ) {
				continue;
			}[$col, $nr] = self::column( (string) $r->getAttribute( 'r' ) );
			if ( $col <= $last || ( $row && $nr !== $row ) ) {
				throw new RuntimeException( 'Urutan atau referensi sel tidak valid.' );
			}$last     = $col;
			$a[ $col ] = self::cell( $r, $strings );
		}return $a;}
	public static function dense( $a, $width ) {
		$out = array_fill( 0, $width, '' );
		foreach ( $a as $i => $v ) {
			if ( $i >= $width ) {
				if ( trim( $v ) !== '' ) {
					throw new RuntimeException( 'Ada nilai di luar kolom header.' );
				}continue;
			}$out[ $i ] = $v;
		}return $out;}
	private static function worksheet( $xml, $strings, $limits ) {
		return self::scan(
			$xml,
			function ( $r ) use ( $strings, $limits ) {
				$f = fopen( 'php://temp/maxmemory:262144', 'w+b' );
				if ( ! $f ) {
					throw new RuntimeException( 'Buffer CSV gagal.' );
				}try {
					$keys    = null;
					$seen    = 0;
					$scanned = 0;
					while ( $r->read() ) {
						if ( ! self::element( $r, 'row' ) ) {
							continue;
						}if ( ++$scanned > $limits['rows'] + 1000 ) {
							throw new RuntimeException( 'Terlalu banyak baris kosong/terformat.' );
						}$a = self::row( $r, $strings );
						if ( ! array_filter( $a, fn( $v )=>trim( $v ) !== '' ) ) {
								continue;
						}if ( $keys === null ) {
							$line = self::dense( $a, max( array_keys( $a ) ) + 1 );
							while ( count( $line ) && trim( end( $line ) ) === '' ) {
								array_pop( $line );
							}
							$keys = SDP_National::header( $line );
							if ( is_wp_error( $keys ) ) {
								throw new RuntimeException( $keys->get_error_message() );
							}$line = $keys;
						} else {
							if ( ++$seen > $limits['rows'] ) {
								throw new RuntimeException( 'Maksimal ' . $limits['rows'] . ' rekaman per workbook.' );
							}$line = self::dense( $a, count( $keys ) );
						}if ( fputcsv( $f, $line, ',', '"', '' ) === false || ftell( $f ) > $limits['csv'] ) {
							throw new RuntimeException( 'CSV hasil terlalu besar atau gagal ditulis.' );
						}
					}if ( ! $keys || ! $seen ) {
						throw new RuntimeException( 'Header dan minimal satu rekaman wajib.' );
					}rewind( $f );
					$body = stream_get_contents( $f );
					if ( ! is_string( $body ) ) {
						throw new RuntimeException( 'CSV gagal dibaca.' );
					}return $body;
				} finally {
					fclose( $f );
				}}
		);}
	public static function convert( $path, $sheet = 1 ) {
		if ( ! self::available() ) {
			return new WP_Error( 'extensions', 'PHP ZipArchive dan XMLReader diperlukan. Gunakan konverter lokal Python atau simpan sebagai CSV UTF-8.' );
		}$limits = self::limits();
		if ( ! is_file( $path ) || filesize( $path ) > $limits['file'] ) {
			return new WP_Error( 'size', 'File XLSX tidak valid atau terlalu besar.' );
		}if ( $sheet < 1 || $sheet > 100 ) {
			return new WP_Error( 'sheet', 'Nomor sheet 1–100.' );
		}$zip = new ZipArchive();
		if ( $zip->open( $path, ZipArchive::RDONLY ) !== true ) {
			return new WP_Error( 'zip', 'XLSX bukan ZIP yang valid.' );
		}try {
			if ( $zip->numFiles > 512 ) {
				throw new RuntimeException( 'Terlalu banyak bagian ZIP.' );
			}$names = array();for ( $i = 0;$i < $zip->numFiles;$i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( isset( $names[ $name ] ) || preg_match( '/(?:^|\/)\.\.(?:\/|$)|\\\\/', $name ) ) {
					throw new RuntimeException( 'Nama bagian ZIP tidak aman/duplikat.' );
				}$names[ $name ] = true;
			}if ( isset( $names['xl/vbaProject.bin'] ) ) {
				throw new RuntimeException( 'Workbook bermakro tidak didukung.' );
			}$wb = self::part( $zip, 'xl/workbook.xml', 262144 );
			$id  = self::scan(
				$wb,
				function ( $r ) use ( $sheet ) {
					$n  = 0;
					$id = null;
					while ( $r->read() ) {
						if ( self::element( $r, 'sheet' ) && ++$n === $sheet ) {
									$id = $r->getAttributeNs( 'id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
						}
					}return $id;
				}
			);
			if ( ! $id ) {
				throw new RuntimeException( 'Sheet tidak ditemukan.' );
			}$rels  = self::part( $zip, 'xl/_rels/workbook.xml.rels', 262144 );
			$target = self::scan(
				$rels,
				function ( $r ) use ( $id ) {
					$target = null;
					while ( $r->read() ) {
						if ( self::element( $r, 'Relationship', 'http://schemas.openxmlformats.org/package/2006/relationships' ) && $r->getAttribute( 'Id' ) === $id ) {
							if ( $target !== null || $r->getAttribute( 'TargetMode' ) === 'External' || $r->getAttribute( 'Type' ) !== 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet' ) {
								throw new RuntimeException( 'Relasi sheet tidak aman.' );
							}$target = $r->getAttribute( 'Target' );
						}
					}return $target;
				}
			);
			if ( ! is_string( $target ) || ! preg_match( '~^(?:/xl/)?worksheets/[A-Za-z0-9_.-]+\.xml$~D', $target ) ) {
				throw new RuntimeException( 'Lokasi worksheet tidak didukung.' );
			}$target = $target[0] === '/' ? substr( $target, 1 ) : 'xl/' . $target;
			$shared  = self::strings( self::part( $zip, 'xl/sharedStrings.xml', $limits['xml'], true ), $limits['strings'] );
			return self::worksheet( self::part( $zip, $target, $limits['xml'] ), $shared, $limits );
		} catch ( Throwable $e ) {
			return new WP_Error( 'xlsx', $e->getMessage() );
		} finally {
			$zip->close();}}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}$l = self::limits();
		echo '<div class="wrap"><h1>Impor Excel (XLSX)</h1><p>Konversi satu sheet, tanpa menjalankan formula. Header pada baris pertama yang tidak kosong, NPSN sebagai teks 8 karakter. Tanggal sebagai teks ISO. File XLS lama tidak didukung.</p><p>Mode aktif: maksimal ' . esc_html( $l['rows'] ) . ' baris dan ' . esc_html( $l['file'] / 1048576 ) . ' MiB. ' . ( self::available() ? 'Ekstensi tersedia.' : 'ZipArchive/XMLReader tidak tersedia; gunakan CSV atau konverter lokal.' ) . '</p><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp82_excel">';
		wp_nonce_field( 'sdp82_excel' );
		echo '<p><label>File XLSX <input type="file" name="workbook" accept=".xlsx" required></label></p><p><label>Nomor sheet <input type="number" min="1" max="100" name="sheet" value="1" required></label></p><p><label><input type="checkbox" name="authorized" value="1" required> Saya berhak menggunakan dataset ini.</label></p>';
		submit_button( 'Konversi dan unduh CSV' );
		echo '</form><p>Periksa CSV, lalu unggah ke Pengumpul Data untuk pratinjau, filter wilayah, dan antrean. Tidak ada perubahan profil pada langkah konversi.</p></div>';}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp82_excel' );
		$f     = $_FILES['workbook'] ?? array();
		$path  = $f['tmp_name'] ?? null;
		$name  = $f['name'] ?? null;
		$sheet = filter_var(
			$_POST['sheet'] ?? 1,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => 1,
					'max_range' => 100,
				),
			)
		);
		if ( empty( $_POST['authorized'] ) || ( $f['error'] ?? 1 ) !== UPLOAD_ERR_OK || ! is_string( $path ) || ! is_string( $name ) || $sheet === false || ! is_uploaded_file( $path ) || strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== 'xlsx' ) {
			wp_die( 'Izin atau file XLSX tidak valid.' );
		}
		$body = self::convert( $path, $sheet );
		if ( is_wp_error( $body ) ) {
			wp_die( esc_html( $body->get_error_message() ) ); // CSV is for machine import, prevent spreadsheet formula interpretation by rejecting dangerous leading characters in text cells before download.
		}
		$fp = fopen( 'php://temp', 'w+b' );
		fwrite( $fp, $body );
		rewind( $fp );
		while ( ( $line = fgetcsv( $fp, 0, ',', '"', '' ) ) !== false ) {
			foreach ( $line as $v ) {
				if ( preg_match( '/^[\s\x{FEFF}]*[=+@-]/u', (string) $v ) && ! preg_match( '/^-?\d+(?:\.\d+)?$/D', (string) $v ) ) {
					fclose( $fp );
						wp_die( 'Teks berawalan karakter formula terdeteksi. Bersihkan sel tersebut sebelum konversi.' );
				}
			}
		}fclose( $fp );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="sdp-excel.csv"' );
		echo $body;
		exit;}
}
