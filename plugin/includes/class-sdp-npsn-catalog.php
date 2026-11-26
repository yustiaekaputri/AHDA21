<?php
/**
 * Admin-only lookup against a locally uploaded dataset.
 *
 * @package SekolahDirectoryPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores one bounded dataset and creates drafts from reviewed records. */
final class SDP_NPSN_Catalog {
	const PAGE     = 'sdp-npsn-catalog';
	const OPTION   = 'sdp_npsn_catalog';
	const MAX_ROWS = 1000;

	private const LIST_MAX_BYTES = 8192;

	/** Register the menu and authenticated form handlers. */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 23 );
		add_action( 'admin_post_sdp_npsn_upload', array( __CLASS__, 'upload' ) );
		add_action( 'admin_post_sdp_npsn_create', array( __CLASS__, 'create' ) );
		add_action( 'admin_post_sdp_npsn_batch', array( __CLASS__, 'create_batch' ) );
		add_action( 'admin_post_sdp_npsn_confirm_batch', array( __CLASS__, 'confirm_batch' ) );
	}

	/** Add the catalog under the directory menu. */
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Cari NPSN dari katalog', 'Cari NPSN dari katalog', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/** Require access to directory settings. */
	private static function require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.', '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Return rejected form feedback.
	 *
	 * @param string $message Validation error.
	 */
	private static function fail( $message ) {
		wp_die(
			esc_html( $message ),
			'',
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}

	/** Validate the entire CSV before atomically replacing the catalog. */
	public static function upload() {
		self::require_admin();
		check_admin_referer( 'sdp_npsn_upload' );

		$label  = sanitize_text_field( wp_unslash( $_POST['source_label'] ?? '' ) );
		$date   = sanitize_text_field( wp_unslash( $_POST['source_date'] ?? '' ) );
		$period = sanitize_text_field( wp_unslash( $_POST['source_period'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Upload metadata is type-checked and the path must be an HTTP upload.
		$file = $_FILES['catalog'] ?? array();
		$path = $file['tmp_name'] ?? null;
		$name = $file['name'] ?? null;
		$size = $file['size'] ?? null;

		if ( '' === $label || strlen( $label ) > 191 || strlen( $period ) > 80 || empty( $_POST['authorized'] ) ) {
			self::fail( 'Isi nama sumber dan konfirmasi izin penggunaan.' );
		}
		if ( '' !== $date && ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts )
			|| ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) ) {
			self::fail( 'Tanggal data tidak valid.' );
		}
		if ( ! is_string( $path ) || ! is_string( $name ) || ! is_int( $size )
			|| UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE )
			|| 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) )
			|| $size < 1 || $size > 524288 || ! is_uploaded_file( $path ) ) {
			self::fail( 'Pilih CSV yang valid dengan ukuran maksimal 512 KiB.' );
		}

		$rows = SDP_Directory::parse_csv( $path, false, self::MAX_ROWS );
		if ( is_wp_error( $rows ) ) {
			self::fail( $rows->get_error_message() );
		}
		if ( ! $rows ) {
			self::fail( 'CSV katalog tidak berisi profil.' );
		}

		$records = array();
		$source  = '' === $period ? $label : $label . ' — ' . $period;
		foreach ( $rows as $line => $row ) {
			// The source name and data period describe the dataset. Links may identify individual school records.
			$row['sumber']       = $source;
			$row['tanggal_data'] = $date;
			$record              = SDP_Directory::normalize( $row );
			if ( is_wp_error( $record ) ) {
				self::fail( "Rekaman $line: " . $record->get_error_message() );
			}
			$npsn = $record['npsn'];
			if ( isset( $records[ $npsn ] ) ) {
				self::fail( "Rekaman $line: NPSN duplikat dalam katalog." );
			}
			$records[ $npsn ] = $record;
		}

		if ( ! update_option( self::OPTION, $records, false ) && get_option( self::OPTION ) !== $records ) {
			self::fail( 'Katalog gagal disimpan. Coba kembali.' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Bind the confirmation to the exact normalized record that was shown.
	 *
	 * @param array $record Catalog record.
	 * @return string Nonce action.
	 */
	private static function confirmation( $record ) {
		return 'sdp_npsn_create_' . hash( 'sha256', (string) wp_json_encode( $record ) );
	}

	/** Create a new draft only after confirming the current record. */
	public static function create() {
		self::require_admin();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The submitted key selects the record used to validate the nonce. No writes precede validation.
		$npsn    = strtoupper( sanitize_text_field( wp_unslash( $_POST['npsn'] ?? '' ) ) );
		$catalog = get_option( self::OPTION, array() );
		$record  = $catalog[ $npsn ] ?? null;
		if ( ! is_array( $record ) ) {
			self::fail( 'Data katalog berubah. Cari dan tinjau NPSN kembali.' );
		}
		check_admin_referer( self::confirmation( $record ) );
		$result = SDP_Directory::save( $record, false, 'fill', null, null, null, true );
		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message() );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'sdp-edit',
					'npsn' => $npsn,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Validate a one-column list before allowing any writes.
	 *
	 * @param string $contents Plain text or one-column CSV contents.
	 * @param int    $limit    Maximum number of data lines.
	 * @param bool   $csv      Whether CSV quoting is allowed.
	 * @return string[]|WP_Error Validated identifiers or an error.
	 */
	private static function parse_list( $contents, $limit, $csv ) {
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents );
		$lines    = preg_split( '/\r\n|\r|\n/', $contents );
		$ids      = array();
		foreach ( $lines as $index => $line ) {
			$line = trim( $line, " \t" );
			if ( $csv && strlen( $line ) >= 2 && str_starts_with( $line, '"' ) && str_ends_with( $line, '"' ) ) {
				$line = trim( substr( $line, 1, -1 ), " \t" );
			}
			if ( '' === $line || ( 0 === $index && 'npsn' === strtolower( $line ) ) ) {
				continue;
			}
			if ( count( $ids ) >= $limit ) {
				return new WP_Error( 'limit', "Daftar NPSN melebihi $limit baris data." );
			}
			$npsn = SDP_National::npsn( $line );
			if ( '' === $npsn ) {
				return new WP_Error( 'npsn', 'NPSN tidak valid pada baris ' . ( $index + 1 ) . '. Gunakan 8 huruf atau angka.' );
			}
			$ids[] = $npsn;
		}
		return $ids ? $ids : new WP_Error( 'empty', 'Daftar tidak memuat NPSN.' );
	}

	/** Create drafts for valid NPSNs using the catalog currently loaded. */
	public static function create_batch() {
		self::require_admin();
		check_admin_referer( 'sdp_npsn_batch' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Upload metadata is type-checked and the path must be an HTTP upload.
		$file = $_FILES['npsn_list'] ?? array();
		$path = $file['tmp_name'] ?? null;
		$name = $file['name'] ?? null;
		$size = $file['size'] ?? null;
		$type = is_string( $name ) ? strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) : '';
		$max  = 'xlsx' === $type ? SDP_Excel::limits()['file'] : self::LIST_MAX_BYTES;
		if ( ! is_string( $path ) || ! is_string( $name ) || ! is_int( $size )
			|| UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE )
			|| ! in_array( $type, array( 'txt', 'csv', 'xlsx' ), true )
			|| $size < 1 || $size > $max || ! is_uploaded_file( $path ) ) {
			SDP_Directory::redirect( self::PAGE, 'Impor daftar dibatalkan.', array( 'Pilih TXT, CSV, atau XLSX yang valid sesuai batas ukuran.' ) );
		}

		if ( 'xlsx' === $type ) {
			$contents = SDP_Excel::convert( $path );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the verified PHP upload with an explicit byte limit.
			$contents = file_get_contents( $path, false, null, 0, self::LIST_MAX_BYTES + 1 );
		}
		if ( is_wp_error( $contents ) ) {
			SDP_Directory::redirect( self::PAGE, 'Impor daftar dibatalkan.', array( $contents->get_error_message() ) );
		}
		if ( false === $contents || strlen( $contents ) > self::LIST_MAX_BYTES ) {
			SDP_Directory::redirect( self::PAGE, 'Impor daftar dibatalkan.', array( 'Berkas tidak dapat dibaca atau melebihi 8 KiB.' ) );
		}
		$ids = self::parse_list( $contents, SDP_Resource::cap( SDP_Directory::MAX_ROWS, 50 ), 'txt' !== $type );
		if ( is_wp_error( $ids ) ) {
			SDP_Directory::redirect( self::PAGE, 'Impor daftar dibatalkan.', array( $ids->get_error_message() ) );
		}
		$catalog = get_option( self::OPTION, array() );
		if ( ! $catalog ) {
			SDP_Directory::redirect( self::PAGE, 'Impor daftar dibatalkan.', array( 'Unggah katalog sekolah terlebih dahulu.' ) );
		}
		if ( isset( $_POST['preview_batch'] ) ) {
			self::preview_batch( $ids, $catalog );
		}
		self::save_batch( $ids, $catalog );
	}

	/**
	 * Build a read-only summary after validating the upload.
	 *
	 * @param string[] $ids Validated identifiers.
	 * @param array    $catalog Current catalog.
	 */
	private static function preview_batch( $ids, $catalog ) {
		global $wpdb;
		$unique  = array_unique( $ids );
		$matches = array_values( array_intersect( $unique, array_keys( $catalog ) ) );
		$exists  = array();
		if ( $matches ) {
			$table        = SDP_Directory::table();
			$placeholders = implode( ',', array_fill( 0, count( $matches ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fresh indexed lookup with one bound value per NPSN.
			$exists = $wpdb->get_col( $wpdb->prepare( "SELECT npsn FROM $table WHERE npsn IN ($placeholders)", $matches ) );
			if ( ! empty( $wpdb->last_error ) ) {
				SDP_Directory::redirect( self::PAGE, 'Pratinjau daftar dibatalkan.', array( 'Profil tersimpan tidak dapat diperiksa. Coba lagi.' ) );
			}
		}
		$token   = wp_generate_uuid4();
		$preview = array(
			'ids'         => $ids,
			'fingerprint' => self::batch_fingerprint( $ids, $catalog ),
			'new'         => count( $matches ) - count( $exists ),
			'existing'    => count( $exists ),
			'missing'     => array_values( array_diff( $unique, $matches ) ),
			'duplicates'  => count( $ids ) - count( $unique ),
		);
		if ( ! set_transient( self::preview_key( $token ), $preview, 5 * MINUTE_IN_SECONDS ) ) {
			SDP_Directory::redirect( self::PAGE, 'Pratinjau daftar dibatalkan.', array( 'Pratinjau tidak dapat disimpan. Coba lagi.' ) );
		}
		wp_safe_redirect( add_query_arg( 'npsn_preview', $token, admin_url( 'admin.php?page=' . self::PAGE ) ) . '#sdp-npsn-batch' );
		exit;
	}

	/**
	 * Bind a preview to its administrator and token.
	 *
	 * @param string $token Token from the preview form.
	 * @return string Transient key.
	 */
	private static function preview_key( $token ) {
		return 'sdp_npsn_preview_' . get_current_user_id() . '_' . $token;
	}

	/**
	 * Bind confirmation to the relevant catalog records as they were previewed.
	 *
	 * @param string[] $ids Validated identifiers.
	 * @param array    $catalog Current catalog.
	 * @return string Content hash.
	 */
	private static function batch_fingerprint( $ids, $catalog ) {
		return hash( 'sha256', (string) wp_json_encode( array_intersect_key( $catalog, array_fill_keys( $ids, true ) ) ) );
	}

	/** Apply a reviewed list while checking for intervening catalog changes. */
	public static function confirm_batch() {
		self::require_admin();
		check_admin_referer( 'sdp_npsn_confirm_batch' );
		$token   = sanitize_text_field( wp_unslash( $_POST['npsn_preview'] ?? '' ) );
		$preview = wp_is_uuid( $token, 4 ) ? get_transient( self::preview_key( $token ) ) : false;
		if ( ! $preview ) {
			SDP_Directory::redirect( self::PAGE, 'Impor daftar dibatalkan.', array( 'Pratinjau sudah kedaluwarsa. Unggah daftar kembali.' ) );
		}
		delete_transient( self::preview_key( $token ) );
		$ids     = $preview['ids'];
		$catalog = get_option( self::OPTION, array() );
		if ( count( $ids ) > SDP_Resource::cap( SDP_Directory::MAX_ROWS, 50 ) || self::batch_fingerprint( $ids, $catalog ) !== $preview['fingerprint'] ) {
			SDP_Directory::redirect( self::PAGE, 'Impor daftar dibatalkan.', array( 'Data katalog atau batas impor berubah. Buat pratinjau baru.' ) );
		}
		self::save_batch( $ids, $catalog );
	}

	/**
	 * Create only new drafts from the selected catalog entries.
	 *
	 * @param string[] $ids Validated identifiers.
	 * @param array    $catalog Current catalog.
	 */
	private static function save_batch( $ids, $catalog ) {
		$unique = array_unique( $ids );
		$run    = static function () use ( $catalog, $unique ) {
			$created = 0;
			$exists  = 0;
			$missing = 0;
			$failed  = 0;
			$details = array();
			foreach ( $unique as $npsn ) {
				$record = $catalog[ $npsn ] ?? null;
				if ( ! is_array( $record ) ) {
					++$missing;
					$details[] = "$npsn tidak ditemukan di katalog.";
					continue;
				}
				$result = SDP_Directory::save( $record, false, 'fill', null, null, null, true );
				if ( ! is_wp_error( $result ) ) {
					++$created;
				} elseif ( 'exists' === $result->get_error_code() ) {
					++$exists;
				} else {
					++$failed;
					$details[] = "$npsn: " . $result->get_error_message();
				}
			}
			return array( $created, $exists, $missing, $failed, $details );
		};
		$result = SDP_Resource::enabled() ? SDP_Collector::lock( $run ) : $run();
		if ( is_wp_error( $result ) ) {
			SDP_Directory::redirect( self::PAGE, 'Impor daftar dibatalkan.', array( $result->get_error_message() ) );
		}
		list( $created, $exists, $missing, $failed, $details ) = $result;
		$duplicates = count( $ids ) - count( $unique );
		SDP_Directory::redirect( self::PAGE, "Selesai: $created draf baru, $exists profil sudah ada, $duplicates baris berulang, $missing NPSN di luar katalog, $failed gagal. Maksimal 30 detail ditampilkan.", $details );
	}

	/** Render a stored preview and the explicit confirmation form. */
	private static function show_batch_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only retrieval scoped to the requesting administrator.
		$token = sanitize_text_field( wp_unslash( $_GET['npsn_preview'] ?? '' ) );
		if ( '' === $token ) {
			return;
		}
		$preview = wp_is_uuid( $token, 4 ) ? get_transient( self::preview_key( $token ) ) : false;
		if ( ! $preview ) {
			echo '<p>Pratinjau sudah kedaluwarsa. Unggah daftar kembali.</p>';
			return;
		}

		echo '<h3>Ringkasan pratinjau</h3><p>Pratinjau berlaku 5 menit. Belum ada profil yang disimpan.</p>';
		printf(
			'<p>%d calon draf baru, %d profil sudah ada, %d baris berulang, %d NPSN di luar katalog.</p>',
			(int) $preview['new'],
			(int) $preview['existing'],
			(int) $preview['duplicates'],
			count( $preview['missing'] )
		);
		if ( $preview['missing'] ) {
			echo '<p>NPSN di luar katalog (maksimal 30): ' . esc_html( implode( ', ', array_slice( $preview['missing'], 0, 30 ) ) ) . '</p>';
		}
		if ( $preview['new'] > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp_npsn_confirm_batch"><input type="hidden" name="npsn_preview" value="' . esc_attr( $token ) . '">';
			wp_nonce_field( 'sdp_npsn_confirm_batch' );
			submit_button( 'Konfirmasi dan buat draf', 'primary', 'confirm_batch' );
			echo '</form>';
		}
	}

	/** Render the dataset form, lookup, and optional preview. */
	public static function page() {
		self::require_admin();
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lookup is read-only and restricted to administrators.
		$npsn    = strtoupper( sanitize_text_field( wp_unslash( $_GET['npsn'] ?? '' ) ) );
		$catalog = get_option( self::OPTION, array() );
		$record  = $catalog[ $npsn ] ?? null;
		$action  = admin_url( 'admin-post.php' );
		echo '<div class="wrap"><h1>Cari NPSN dari katalog</h1>';
		SDP_Directory::notice();
		echo '<h2>Katalog lokal</h2><p>Unggah CSV berizin dengan kolom wajib <code>npsn,nama</code>, maksimal ' . (int) self::MAX_ROWS . ' rekaman dan 512 KiB. Unggahan valid mengganti katalog saat ini. Profil WordPress tetap tersimpan.</p>';
		echo '<p>Rekaman katalog: ' . count( $catalog ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $action ) . '"><input type="hidden" name="action" value="sdp_npsn_upload">';
		wp_nonce_field( 'sdp_npsn_upload' );
		echo '<p><label>CSV katalog <input type="file" name="catalog" accept=".csv,text/csv" required></label></p>';
		echo '<p><label>Nama sumber <input name="source_label" maxlength="191" required></label></p>';
		echo '<p><label>Periode data <input name="source_period" maxlength="80"></label></p>';
		echo '<p><label>Tanggal data <input name="source_date" type="date"></label></p>';
		echo '<p>Kosongkan periode dan tanggal data jika sumber tidak mencantumkannya.</p>';
		echo '<p><label><input name="authorized" type="checkbox" value="1" required> Saya berhak menggunakan dataset ini.</label></p>';
		submit_button( 'Simpan katalog' );
		echo '</form><h2>Pratinjau profil</h2><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<label>NPSN <input name="npsn" maxlength="8" pattern="[A-Za-z0-9]{8}" value="' . esc_attr( $npsn ) . '" required></label> ';
		echo '<button class="button">Cari NPSN</button></form>';
		if ( '' !== $npsn && '' === SDP_National::npsn( $npsn ) ) {
			echo '<p>Masukkan NPSN dengan 8 huruf atau angka.</p>';
		} elseif ( '' !== $npsn ) {
			$existing     = SDP_SEO::by_npsn( $npsn );
			$lookup_error = ! empty( $wpdb->last_error );
			$manual       = add_query_arg(
				array(
					'page' => 'sdp-edit',
					'npsn' => $npsn,
				),
				admin_url( 'admin.php' )
			);
			if ( $lookup_error ) {
				echo '<p>Profil tersimpan tidak dapat diperiksa. Coba lagi.</p>';
			} elseif ( $existing ) {
				echo '<p>NPSN ini sudah memiliki profil. <a href="' . esc_url( $manual ) . '">Buka profil tersimpan</a>.</p>';
			} else {
				echo '<p><a href="' . esc_url( $manual ) . '">Isi profil secara manual</a>.</p>';
			}
			if ( is_array( $record ) ) {
				echo '<h3>Data kandidat</h3><dl style="overflow-wrap:anywhere">';
				$labels = SDP_Directory::labels();
				foreach ( $record as $key => $value ) {
					echo '<dt>' . esc_html( $labels[ $key ] ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
				}
				echo '</dl>';
				if ( ! $lookup_error && ! $existing ) {
					echo '<p>Profil baru akan disimpan sebagai draf.</p>';
					echo '<form method="post" action="' . esc_url( $action ) . '"><input type="hidden" name="action" value="sdp_npsn_create"><input type="hidden" name="npsn" value="' . esc_attr( $npsn ) . '">';
					wp_nonce_field( self::confirmation( $record ) );
					submit_button( 'Simpan draf dari katalog' );
					echo '</form>';
				}
			} else {
				echo '<p>NPSN tidak ditemukan di katalog lokal.</p>';
			}
		}
		echo '<h2 id="sdp-npsn-batch">Impor daftar NPSN dari katalog</h2>';
		self::show_batch_preview();
		if ( $catalog ) {
			$limit = SDP_Resource::cap( SDP_Directory::MAX_ROWS, 50 );
			echo '<p>Gunakan satu NPSN per baris dalam TXT atau satu kolom dalam CSV/XLSX. TXT dan CSV mendukung judul <code>npsn</code> opsional dan maksimal 8 KiB. Untuk XLSX, gunakan sheet pertama dengan judul <code>npsn</code> dan simpan NPSN sebagai teks. Batas ' . (int) $limit . ' baris data. Profil baru menjadi draf dan profil lama dilewati.</p>';
			echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $action ) . '"><input type="hidden" name="action" value="sdp_npsn_batch">';
			wp_nonce_field( 'sdp_npsn_batch' );
			echo '<p><label>Daftar NPSN (TXT, CSV, atau XLSX) <input type="file" name="npsn_list" accept=".txt,.csv,.xlsx,text/plain,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label></p>';
			submit_button( 'Pratinjau daftar NPSN', 'primary', 'preview_batch' );
			submit_button( 'Buat draf dari daftar NPSN', 'secondary', 'save_batch' );
			echo '</form>';
		} else {
			echo '<p>Unggah katalog sekolah sebelum menggunakan daftar NPSN.</p>';
		}
		echo '</div>';
	}
}
