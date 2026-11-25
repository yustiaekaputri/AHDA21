<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Local evidence review. A declared source type is not independent verification. */
final class SDP_Intelligence {
	const LIMIT = 40;
	const KEY   = '_sdp_intelligence';
	const TYPES = array(
		'akreditasi' => 'BAN-PDM / akreditasi',
		'statistik'  => 'Dataset statistik resmi',
		'referensi'  => 'Referensi Data Pendidikan',
		'madrasah'   => 'Kemenag / madrasah',
		'website'    => 'Website resmi sekolah',
		'operator'   => 'Operator sekolah',
		'lain'       => 'Sumber lain',
	);
	const STATS = array( 'tahun_data', 'jumlah_siswa', 'siswa_laki_laki', 'siswa_perempuan', 'jumlah_guru', 'jumlah_tendik', 'guru_sertifikasi', 'jumlah_rombel' );
	const ACC   = array( 'akreditasi', 'tahun_akreditasi', 'nomor_sk_akreditasi', 'sertifikat_url' );
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_post_sdp_intelligence', array( __CLASS__, 'action' ) );}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Validasi & Konflik', 'Validasi & Konflik', 'manage_options', 'sdp-intelligence', array( __CLASS__, 'page' ) );}
	public static function fields() {
		return array_diff_key( SDP_Directory::labels(), array_flip( array( 'npsn', 'sumber', 'sumber_url', 'tanggal_data' ) ) );}
	public static function state( $d ) {
		$s = $d[ self::KEY ] ?? array();
		return array(
			'locks'  => is_array( $s['locks'] ?? null ) ? $s['locks'] : array(),
			'events' => is_array( $s['events'] ?? null ) ? $s['events'] : array(),
		);}
	public static function snapshot( $d ) {
		return hash( 'sha256', serialize( $d ) );}
	public static function period( $v ) {
		if ( ! is_string( $v ) || ! preg_match( '/^(19\d\d|20\d\d)(?:\/(19\d\d|20\d\d)(?:\s+(ganjil|genap))?)?$/iD', trim( $v ), $m ) ) {
			return null;
		}
		$y = (int) $m[1];
		if ( isset( $m[2] ) && (int) $m[2] !== $y + 1 ) {
			return null;
		}
		// Calendar years and academic years are distinct, not comparable by default.
		return array(
			'kind'     => isset( $m[2] ) ? 'academic' : 'calendar',
			'year'     => $y,
			'semester' => isset( $m[3] ) ? ( strtolower( $m[3] ) === 'ganjil' ? 1 : 2 ) : 0,
		);
	}
	public static function compare_period( $a, $b ) {
		$a = self::period( $a );
		$b = self::period( $b );
		if ( ! $a || ! $b || $a['kind'] !== $b['kind'] ) {
			return null;
		}if ( $a['year'] !== $b['year'] ) {
			return $a['year'] <=> $b['year'];
		}if ( ! $a['semester'] || ! $b['semester'] ) {
			return $a['semester'] === $b['semester'] ? 0 : null;
		}return $a['semester'] <=> $b['semester'];}
	public static function field_period( $d, $k ) {
		$p = $d['_field_sources'][ $k ]['period'] ?? '';
		return is_string( $p ) && self::period( $p ) ? $p : '';}
	public static function period_note( $d, $k ) {
		$p = self::field_period( $d, $k );
		if ( '' !== $p ) {
			return ' (periode atribut: ' . $p . ')';
		}if ( isset( self::state( $d )['locks'][ $k ] ) && in_array( $k, array_merge( self::STATS, self::ACC ), true ) ) {
			return ' (periode atribut belum diketahui)';
		}return '';}
	public static function priority( $field, $type ) {
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return 0;
		}
		if ( in_array( $field, self::ACC, true ) ) {
			return 'akreditasi' === $type ? 3 : ( in_array( $type, array( 'referensi', 'madrasah' ), true ) ? 2 : 1 );
		}
		if ( in_array( $field, self::STATS, true ) ) {
			return 'statistik' === $type ? 3 : ( in_array( $type, array( 'referensi', 'madrasah' ), true ) ? 2 : 1 );
		}
		if ( in_array( $field, SDP_Website::FIELDS, true ) ) {
			return in_array( $type, array( 'website', 'operator' ), true ) ? 3 : 1;
		}
		return in_array( $type, array( 'referensi', 'madrasah' ), true ) ? 3 : ( 'operator' === $type ? 2 : 1 );
	}
	public static function relevant( $d ) {
		$fields = SDP_Directory::labels();
		$level  = $d['jenjang'] ?? '';
		if ( in_array( $level, SDP_Directory::levels(), true ) && ! in_array( $level, array( 'LAINNYA', 'PESANTREN' ), true ) ) {
			if ( ! in_array( $level, array( 'SMK', 'MAK' ), true ) ) {
				unset( $fields['jurusan'], $fields['mitra_industri'] );
			}if ( ! in_array( $level, array( 'RA', 'MI', 'MTs', 'MA', 'MAK' ), true ) ) {
				unset( $fields['nsm'] );
			}
		}return $fields;}
	public static function completeness( $d ) {
		$f = self::relevant( $d );
		$n = 0;
		foreach ( $f as $k => $label ) {
			if ( SDP_SEO::present( $d, $k ) ) {
				++$n;
			}
		}return array(
			'filled' => $n,
			'total'  => count( $f ),
			'score'  => (int) round( 100 * $n / count( $f ) ),
		);}
	public static function validate( $raw, $id ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'review', 'Input tidak valid.' );
		}
		foreach ( array( 'field', 'value', 'type', 'url', 'period', 'note' ) as $k ) {
			if ( ! is_string( $raw[ $k ] ?? null ) ) {
				return new WP_Error( 'review', 'Input harus teks.' );
			}
		}
		$f = $raw['field'];
		if ( ! isset( self::fields()[ $f ] ) || ! isset( self::TYPES[ $raw['type'] ] ) ) {
			return new WP_Error( 'review', 'Atribut atau kategori sumber tidak valid.' );
		}
		$d = SDP_Directory::normalize(
			array(
				'npsn' => $id,
				$f     => $raw['value'],
			)
		);
		if ( is_wp_error( $d ) ) {
			return $d;
		}if ( ! SDP_SEO::present( $d, $f ) ) {
			return new WP_Error( 'review', 'Nilai kandidat kosong atau placeholder.' );
		}
		$url   = trim( $raw['url'] );
		$parts = parse_url( $url );
		if ( strlen( $url ) > 1000 || ! filter_var( $url, FILTER_VALIDATE_URL ) || ! $parts || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'https', 'http' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'review', 'URL bukti HTTP/HTTPS wajib tanpa kredensial.' );
		}
		$p = trim( $raw['period'] );
		if ( '' !== $p && ! self::period( $p ) ) {
			return new WP_Error( 'review', 'Gunakan YYYY atau YYYY/YYYY ganjil/genap; kosongkan bila tidak diketahui.' );
		}
		$note = sanitize_textarea_field( $raw['note'] );
		if ( strlen( $note ) > 1000 ) {
			return new WP_Error( 'review', 'Catatan maksimal 1000 byte.' );
		}
		return array(
			'field'  => $f,
			'value'  => $d[ $f ],
			'type'   => $raw['type'],
			'url'    => $url,
			'period' => $p,
			'note'   => $note,
		);
	}
	public static function assess( $d, $c ) {
		$f         = $c['field'];
		$s         = self::state( $d );
		$old       = $d[ $f ] ?? '';
		$src       = $d['_field_sources'][ $f ] ?? array();
		$p         = self::compare_period( $c['period'], $src['period'] ?? '' );
		$a         = self::priority( $f, $c['type'] );
		$b         = self::priority( $f, $src['type'] ?? '' );
		$locked    = isset( $s['locks'][ $f ] );
		$different = (string) $old !== $c['value'];
		$hint      = ! SDP_SEO::present( $d, $f ) ? 'Kolom belum lengkap' : ( ! $different ? 'Nilai sama' : ( $locked ? 'Terkunci — tinjau bukti sebelum mengganti' : ( $p === -1 ? 'Periode kandidat lebih lama' : ( $p === 1 ? 'Periode kandidat lebih baru' : ( $p === null ? 'Periode belum dapat dibandingkan' : 'Periode sama, nilai berbeda' ) ) ) ) );
		return array(
			'old'          => $old,
			'source'       => $src,
			'locked'       => $locked,
			'conflict'     => SDP_SEO::present( $d, $f ) && $different,
			'period_order' => $p,
			'priority'     => $a,
			'old_priority' => $b,
			'hint'         => $hint,
		);}
	/** Called only under the existing per-NPSN SQL lock. */
	public static function guard( $old, $clean, $policy, $review ) {
		$s = self::state( $old );
		if ( null !== $review ) {
			if ( ! is_array( $review ) || ! isset( $review['snapshot'], $review['candidate'] ) || ! hash_equals( self::snapshot( $old ), (string) $review['snapshot'] ) ) {
				return new WP_Error( 'review', 'Profil berubah; pratinjau ulang.' );
			}$c = $review['candidate'];
			$f  = $c['field'];
			if ( count( $clean ) !== 2 || ! isset( $clean[ $f ] ) || $clean[ $f ] !== $c['value'] ) {
				return new WP_Error( 'review', 'Kandidat tidak sesuai.' );
			}return true;}
		if ( 'fill' === $policy ) {
			return true;
		}
		foreach ( array(
			'tahun_data'       => self::STATS,
			'tahun_akreditasi' => self::ACC,
		) as $period => $group ) {
			if ( isset( $clean[ $period ] ) && ( $old[ $period ] ?? null ) !== $clean[ $period ] && array_intersect( $group, array_keys( $s['locks'] ) ) ) {
				return new WP_Error( 'verified', 'Periode terkait atribut terkunci: ' . $period . '. Gunakan Validasi & Konflik.' );
			}
		}
		foreach ( $s['locks'] as $k => $lock ) {
			if ( isset( $clean[ $k ] ) && ( $old[ $k ] ?? null ) !== $clean[ $k ] ) {
				return new WP_Error( 'verified', 'Atribut terverifikasi terkunci: ' . $k . '. Gunakan Validasi & Konflik.' );
			}
		}
		return true;
	}
	public static function record( $old, $d, $review, $provenance, $clean = array(), $policy = 'overwrite' ) {
		$s      = self::state( $old );
		$events = array();
		$now    = current_time( 'mysql', true );
		if ( ! $review ) {
			foreach ( self::fields() as $k => $label ) {
				if ( ! isset( $clean[ $k ], $d['_field_sources'][ $k ] ) || $clean[ $k ] !== ( $d[ $k ] ?? null ) || ( $old[ $k ] ?? null ) === $d[ $k ] ) {
					continue;
				}$p = in_array( $k, self::STATS, true ) ? ( $clean['tahun_data'] ?? '' ) : ( in_array( $k, self::ACC, true ) ? ( $clean['tahun_akreditasi'] ?? '' ) : '' );
				if ( self::period( $p ) ) {
					$d['_field_sources'][ $k ]['period'] = $p;
				}
			}
		}
		foreach ( self::fields() as $k => $label ) {
			if ( ! isset( $old[ $k ], $d[ $k ] ) || $old[ $k ] === $d[ $k ] ) {
				continue;
			}$events[] = array(
				'field'      => $k,
				'before'     => $old[ $k ],
				'after'      => $d[ $k ],
				'old_source' => $old['_field_sources'][ $k ] ?? array(),
				'new_source' => $d['_field_sources'][ $k ] ?? array(),
				'at'         => $now,
				'mode'       => $review ? 'review' : 'update',
			);}
		if ( $review ) {
			$c                         = $review['candidate'];
			$k                         = $c['field'];
			$d['_field_sources'][ $k ] = array(
				'label'     => self::TYPES[ $c['type'] ] . ' (ditinjau admin)',
				'url'       => $c['url'],
				'retrieved' => $now,
				'type'      => $c['type'],
				'period'    => $c['period'],
			);
			$s['locks'][ $k ]          = array(
				'at'   => $now,
				'by'   => get_current_user_id(),
				'note' => $c['note'],
			);
			if ( ! $events ) {
				$events[] = array(
					'field'      => $k,
					'before'     => $old[ $k ] ?? '',
					'after'      => $d[ $k ],
					'old_source' => $old['_field_sources'][ $k ] ?? array(),
					'new_source' => $d['_field_sources'][ $k ],
					'at'         => $now,
					'mode'       => 'review',
				);
			} else {
				foreach ( $events as &$e ) {
					if ( $e['field'] === $k ) {
						$e['new_source'] = $d['_field_sources'][ $k ];
					}
				}
			}unset( $e );}
		if ( ! $review && 'fill' === $policy ) {
			foreach ( self::fields() as $k => $label ) {
				if ( ! isset( $clean[ $k ] ) || ! SDP_SEO::present( $old, $k ) || $old[ $k ] === $clean[ $k ] || ! SDP_SEO::present( $clean, $k ) ) {
					continue;
				}$candidate = array(
					'label' => $provenance['label'] ?? ( $clean['sumber'] ?? 'Input admin / CSV' ),
					'url'   => $provenance['url'] ?? ( $clean['sumber_url'] ?? '' ),
				);
				$event      = array(
					'field'      => $k,
					'before'     => $old[ $k ],
					'after'      => $clean[ $k ],
					'old_source' => $old['_field_sources'][ $k ] ?? array(),
					'new_source' => $candidate,
					'at'         => $now,
					'mode'       => 'conflict_fill',
				);
				$seen       = false;
				foreach ( $s['events'] as $e ) {
					if ( ( $e['mode'] ?? '' ) === 'conflict_fill' && $e['field'] === $k && $e['before'] === $event['before'] && $e['after'] === $event['after'] && $e['new_source'] === $candidate ) {
						$seen = true;
							break;
					}
				}if ( ! $seen ) {
					$events[] = $event;
				}
			}
		}
		if ( $events || isset( $old[ self::KEY ] ) ) {
			$s['events']    = array_slice( array_merge( $s['events'], $events ), -self::LIMIT );
			$d[ self::KEY ] = $s;
		}return $d;
	}
	public static function load( $id ) {
		if ( ! SDP_Engine::valid_npsn( $id ) ) {
			return new WP_Error( 'identity', 'NPSN tidak valid atau demo.' );
		}global $wpdb;
		$t = SDP_Directory::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT s.*,p.post_status,p.post_password,p.post_type FROM $t s JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE s.npsn=%s", $id ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'database', 'Gagal membaca database.' );
		}if ( ! $r || ( $r['post_type'] ?? '' ) !== SDP_Directory::TYPE || ! in_array( $r['post_status'] ?? '', array( 'publish', 'draft', 'pending' ), true ) || ( $r['post_password'] ?? null ) !== '' ) {
			return new WP_Error( 'identity', 'Profil tidak tersedia atau terlindungi.' );
		}$d = json_decode( $r['payload'] ?? '', true );
		if ( ! is_array( $d ) || ( $d['npsn'] ?? null ) !== $id || empty( $d['nama'] ) || SDP_SEO::demo( $d ) ) {
			return new WP_Error( 'identity', 'Payload tidak valid.' );
		}return $d;
	}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp_intelligence' );
		$id = wp_unslash( $_POST['npsn'] ?? '' );
		$d  = self::load( $id );
		if ( is_wp_error( $d ) ) {
			wp_die( esc_html( $d->get_error_message() ) );
		}$key = 'sdp63_review_' . get_current_user_id();
		$op   = $_POST['op'] ?? '';
		if ( 'preview' === $op ) {
			$c = self::validate( wp_unslash( $_POST['candidate'] ?? array() ), $id );
			if ( is_wp_error( $c ) ) {
				wp_die( esc_html( $c->get_error_message() ) );
			}if ( ! set_transient(
				$key,
				array(
					'id'        => $id,
					'snapshot'  => self::snapshot( $d ),
					'candidate' => $c,
					'token'     => self::snapshot( array( $d, $c ) ),
				),
				600
			) ) {
				wp_die( 'Gagal menyimpan pratinjau.' );
			}
		} elseif ( 'approve' === $op ) {
			$p = get_transient( $key );
			if ( ! is_array( $p ) || ( $p['id'] ?? null ) !== $id || empty( $_POST['confirm'] ) || ! is_string( $_POST['token'] ?? null ) || ! hash_equals( $p['token'] ?? '', $_POST['token'] ) ) {
				wp_die( 'Pratinjau kedaluwarsa atau konfirmasi belum dicentang.' );
			}$c = $p['candidate'];
			$r  = SDP_Directory::save(
				array(
					'npsn'      => $id,
					$c['field'] => $c['value'],
				),
				false,
				'overwrite',
				array(
					'label' => self::TYPES[ $c['type'] ],
					'url'   => $c['url'],
				),
				array( 'npsn' => $id ),
				$p
			);
			if ( is_wp_error( $r ) ) {
				wp_die( esc_html( $r->get_error_message() ) );
			}delete_transient( $key );
		} elseif ( 'discard' === $op ) {
			delete_transient( $key );
		} else {
			wp_die( 'Operasi tidak dikenal.' );
		}
		wp_safe_redirect( add_query_arg( 'npsn', $id, admin_url( 'admin.php?page=sdp-intelligence' ) ) );
		exit;
	}
	private static function form( $id ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp_intelligence"><input type="hidden" name="npsn" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'sdp_intelligence' );}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}$id = wp_unslash( $_GET['npsn'] ?? '' );
		echo '<div class="wrap"><h1>Validasi &amp; Konflik — v6.3</h1><p>Bandingkan bukti per atribut. Prioritas kategori sumber bukan verifikasi independen. conflict_fill = kandidat berbeda, tidak disimpan sebagai nilai profil. update = perubahan impor biasa; review = persetujuan admin. Periode statistik per atribut tidak otomatis mengubah periode global. Tidak ada akses jaringan atau keputusan otomatis.</p><form method="get"><input type="hidden" name="page" value="sdp-intelligence"><label>NPSN <input name="npsn" maxlength="8" required value="' . esc_attr( is_string( $id ) ? $id : '' ) . '"></label> <button class="button">Buka profil</button></form>';
		if ( '' === $id ) {
			echo '</div>';
			return;
		}$d = self::load( $id );
		if ( is_wp_error( $d ) ) {
			echo '<p>' . esc_html( $d->get_error_message() ) . '</p></div>';
			return;
		}$s    = self::state( $d );
		$score = self::completeness( $d );
		echo '<h2>' . esc_html( $d['nama'] ) . '</h2><p>Kelengkapan relevan (heuristik): ' . (int) $score['filled'] . ' / ' . (int) $score['total'] . ' (' . (int) $score['score'] . '%). Atribut terkunci: ' . count( $s['locks'] ) . '.</p>';
		self::form( $id );
		echo '<h3>Kandidat informasi</h3><p><label>Atribut <select name="candidate[field]">';
		foreach ( self::fields() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}echo '</select></label></p><p><label>Nilai kandidat<br><textarea name="candidate[value]" rows="5" cols="70" required></textarea></label></p><p><label>Kategori sumber <select name="candidate[type]">';
		foreach ( self::TYPES as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}echo '</select></label></p><p><label>URL bukti <input type="url" name="candidate[url]" required class="regular-text"></label></p><p><label>Periode <input name="candidate[period]" placeholder="2025/2026 ganjil"></label></p><p><label>Catatan pemeriksaan (privat)<br><textarea name="candidate[note]" rows="3" cols="70"></textarea></label></p><button class="button button-primary" name="op" value="preview">Bandingkan</button></form>';
		$p = get_transient( 'sdp63_review_' . get_current_user_id() );
		if ( is_array( $p ) && ( $p['id'] ?? null ) === $id && hash_equals( self::snapshot( $d ), $p['snapshot'] ) ) {
			$c = $p['candidate'];
			$a = self::assess( $d, $c );
			echo '<h2>Pratinjau: ' . esc_html( self::fields()[ $c['field'] ] ) . '</h2><table class="widefat striped"><tbody>';
			foreach ( array(
				'Nilai sekarang'           => $a['old'],
				'Nilai kandidat'           => $c['value'],
				'Sumber sekarang'          => $a['source']['label'] ?? 'Tidak diketahui',
				'URL sekarang'             => $a['source']['url'] ?? '',
				'Periode sekarang'         => $a['source']['period'] ?? 'Tidak diketahui',
				'Sumber kandidat'          => self::TYPES[ $c['type'] ],
				'URL bukti'                => $c['url'],
				'Periode kandidat'         => $c['period'],
				'Prioritas kategori (1–3)' => $a['old_priority'] . ' → ' . $a['priority'],
				'Kesimpulan'               => $a['hint'],
			) as $k => $v ) {
				echo '<tr><th>' . esc_html( $k ) . '</th><td>' . nl2br( esc_html( $v ) ) . '</td></tr>';
			}echo '</tbody></table>';
			self::form( $id );
			echo '<input type="hidden" name="token" value="' . esc_attr( $p['token'] ) . '"><p><label><input type="checkbox" name="confirm" value="1"> Saya telah memeriksa bukti dan mengizinkan penggunaan data. Setujui nilai kandidat dan kunci atribut.</label></p><button class="button button-primary" name="op" value="approve">Setujui &amp; kunci</button> <button class="button" name="op" value="discard">Tolak kandidat</button></form>';}
		echo '<h2>Atribut terkunci</h2><ul>';
		foreach ( $s['locks'] as $k => $v ) {
			echo '<li>' . esc_html( ( self::fields()[ $k ] ?? $k ) . ' — ' . ( $v['at'] ?? '' ) . ' UTC — ' . ( $v['note'] ?? '' ) ) . '</li>';
		}echo '</ul><h2>Riwayat perubahan dan konflik (maksimal 40 terbaru)</h2><table class="widefat striped"><thead><tr><th>Atribut / waktu UTC</th><th>Sebelum</th><th>Sesudah / kandidat yang ditolak</th><th>Asal data</th></tr></thead><tbody>';
		foreach ( array_reverse( $s['events'] ) as $e ) {
			echo '<tr><td>' . esc_html( ( self::fields()[ $e['field'] ] ?? $e['field'] ) . ' / ' . $e['at'] . ' / ' . $e['mode'] ) . '</td><td>' . nl2br( esc_html( $e['before'] ) ) . '</td><td>' . nl2br( esc_html( $e['after'] ) ) . '</td><td>' . esc_html( ( $e['old_source']['label'] ?? 'Tidak diketahui' ) . ' → ' . ( $e['new_source']['label'] ?? 'Tidak diketahui' ) ) . '</td></tr>';
		}echo '</tbody></table></div>';
	}
}
