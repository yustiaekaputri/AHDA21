<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Manual orchestration; no new cron, frontend hooks or network providers. */
final class SDP_Automation {
	const OPTION = 'sdp86_campaign';
	const STATS  = array( 'tahun_data', 'jumlah_siswa', 'siswa_laki_laki', 'siswa_perempuan', 'jumlah_guru', 'jumlah_tendik', 'guru_sertifikasi', 'jumlah_rombel' );
	const ACC    = array( 'akreditasi', 'tahun_akreditasi', 'nomor_sk_akreditasi', 'sertifikat_url' );
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 77 );
		add_action( 'admin_post_sdp_automation', array( __CLASS__, 'action' ) );}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Otomatisasi Terpadu', 'Otomatisasi Terpadu', 'manage_options', 'sdp-automation', array( __CLASS__, 'page' ) );}
	public static function cap() {
		return SDP_Resource::cap( 30, 10 );}
	public static function state() {
		$s = get_option( self::OPTION, array() );
		if ( ! is_array( $s ) ) {
			return array();
		}
		if ( ! empty( $s['crawler_key'] ) ) {
			$j                    = get_transient( $s['crawler_key'] );
			$s['crawler']         = is_array( $j ) ? $j : null;
			$s['crawler_expired'] = ! is_array( $j );}
		return $s;
	}
	public static function store( $s ) {
		$old      = get_option( self::OPTION, array() );
		$oldkey   = $old['crawler_key'] ?? '';
		$s['rev'] = wp_generate_uuid4();
		$newkey   = '';
		if ( is_array( $s['crawler'] ?? null ) ) {
			$ttl = SDP_Crawler::TTL - ( time() - $s['crawler']['created'] );
			if ( $ttl > 0 ) {
				$newkey = 'sdp86_crawl_' . str_replace( '-', '', $s['rev'] );
				if ( ! set_transient( $newkey, $s['crawler'], $ttl ) ) {
					return new WP_Error( 'database', 'Checkpoint crawler gagal disimpan.' );
				}
			}
		}
		// Do not store raw website excerpts in the campaign option.
		if ( $newkey !== '' ) {
			$s['crawler_key'] = $newkey;
		} elseif ( empty( $s['crawler_expired'] ) && ! is_array( $s['crawler'] ?? null ) ) {
			unset( $s['crawler_key'] );
		}
		if ( ! in_array( $s['phase'] ?? '', array( 'crawler', 'review' ), true ) || ( $s['status'] ?? '' ) === 'cancelled' ) {
			unset( $s['crawler_key'] );
		}
		$s['crawler'] = null;
		unset( $s['crawler_expired'] );
		if ( ! update_option( self::OPTION, $s, false ) ) {
			if ( $newkey !== '' ) {
				delete_transient( $newkey );
			}return new WP_Error( 'database', 'Checkpoint gagal disimpan. Muat ulang dan periksa profil sebelum melanjutkan.' );}
		if ( $oldkey !== '' && $oldkey !== ( $s['crawler_key'] ?? '' ) ) {
			delete_transient( $oldkey );
		}return true;
	}
	public static function filters( $raw ) {
		$f = SDP_Engine::filters( $raw );
		if ( is_wp_error( $f ) ) {
			return $f;
		}if ( $f['limit'] > self::cap() ) {
			return new WP_Error( 'limit', 'Batas kampanye sesuai mode: ' . self::cap() );
		}return $f;}
	public static function needs( $d ) {
		$relevant = array_keys( SDP_Intelligence::relevant( $d ) );
		$missing  = array();
		$blank    = array();
		foreach ( $relevant as $k ) {
			if ( ! SDP_SEO::present( $d, $k ) ) {
				$missing[] = $k;
				if ( SDP_Missing::blank( $d, $k ) ) {
					$blank[] = $k;
				}
			}
		}
		return array(
			'reference'     => array_values( array_intersect( SDP_Engine::FIELDS, $blank ) ),
			'website'       => array_values( array_intersect( SDP_Website::FIELDS, $blank ) ),
			'statistics'    => array_values( array_intersect( self::STATS, $missing ) ),
			'accreditation' => array_values( array_intersect( self::ACC, $missing ) ),
			'review'        => array_values( array_diff( $missing, $blank ) ),
		);
	}
	public static function make( $report ) {
		$rows = $report['rows'] ?? array();
		if ( ! $rows || count( $rows ) > self::cap() ) {
			return new WP_Error( 'empty', 'Tidak ada kandidat valid atau batas terlampaui.' );
		}
		$ids = array();
		foreach ( $rows as $row ) {
			$id = $row['npsn'] ?? null;
			if ( ! SDP_Engine::valid_npsn( $id ) || in_array( $id, $ids, true ) ) {
				return new WP_Error( 'identity', 'Kandidat NPSN tidak valid atau duplikat.' );
			}$ids[] = $id;}
		return array(
			'status'  => 'running',
			'filters' => $report['filters'],
			'ids'     => $ids,
			'next'    => $report['next'],
			'pos'     => 0,
			'phase'   => 'reference',
			'crawler' => null,
			'events'  => array(),
			'note'    => 'Kampanye dibuat; tidak ada permintaan jaringan.',
		);
	}
	public static function matches( $d, $f ) {
		foreach ( array( 'provinsi', 'kabupaten', 'kecamatan', 'jenjang' ) as $k ) {
			if ( ( $f[ $k ] ?? '' ) !== '' && strcasecmp( trim( (string) ( $d[ $k ] ?? '' ) ), trim( $f[ $k ] ) ) !== 0 ) {
				return false;
			}
		}return true;}
	public static function advance( $s, $message ) {
		$s['events'][] = array(
			'npsn'    => $s['ids'][ $s['pos'] ],
			'message' => $message,
			'time'    => current_time( 'mysql', true ),
		);
		$s['events']   = array_slice( $s['events'], -30 );
		++$s['pos'];
		$s['phase']   = 'reference';
		$s['crawler'] = null;
		unset( $s['crawler_key'], $s['crawler_expired'] );
		$s['note'] = $message;
		if ( $s['pos'] >= count( $s['ids'] ) ) {
			$s['status'] = 'done';
		}return $s;}
	public static function route_website( $s, $d ) {
		$n            = self::needs( $d );
		$s['crawler'] = null;
		unset( $s['crawler_key'], $s['crawler_expired'] );
		if ( ! $n['website'] ) {
			$s['phase'] = 'handoff';
			$s['note']  = 'Tidak ada atribut naratif kosong yang relevan.';
		} elseif ( ! SDP_Crawler::url( $d['website'] ?? '', $d['website'] ?? '' ) ) {
			$s['phase'] = 'discovery';
			$s['note']  = 'Perlu website HTTPS yang telah diperiksa. Buka Penemuan Website; tidak ada pencarian otomatis.';
		} else {
			$s['phase'] = 'crawler';
			$s['note']  = 'Periksa izin website sebelum memulai crawler.';
		}return $s;}
	public static function transition( $s, $op ) {
		$status = $s['status'] ?? '';
		if ( $op === 'pause' && $status === 'running' ) {
			$s['status'] = 'paused';
			$s['note']   = 'Dijeda administrator.';
		} elseif ( $op === 'resume' && $status === 'paused' ) {
			$s['status'] = 'running';
			$s['note']   = 'Dilanjutkan; tidak ada permintaan otomatis.';
		} elseif ( $op === 'skip' && in_array( $status, array( 'running', 'paused' ), true ) ) {
			$s = self::advance( $s, 'Dilewati administrator.' );
		} elseif ( $op === 'cancel' && in_array( $status, array( 'running', 'paused' ), true ) ) {
			$s['status']      = 'cancelled';
				$s['crawler'] = null;
				$s['note']    = 'Dibatalkan.';
		} else {
			return new WP_Error( 'state', 'Operasi tidak sesuai status.' );
		}return $s;
	}
	private static function pause( $s, $msg ) {
		$s['status'] = 'paused';
		$s['note']   = $msg;
		return $s;}
	public static function step( $s, $d, $authorized ) {
		if ( ( $s['status'] ?? '' ) !== 'running' || ( $s['ids'][ $s['pos'] ] ?? null ) !== ( $d['npsn'] ?? null ) || ! self::matches( $d, $s['filters'] ) || SDP_SEO::demo( $d ) ) {
			return new WP_Error( 'state', 'Kampanye atau profil berubah. Jeda dan lewati bila perlu.' );
		}
		$phase = $s['phase'];
		$n     = self::needs( $d );
		if ( $phase === 'reference' ) {
			if ( ! $n['reference'] ) {
				return self::route_website( $s, $d );
			}
			if ( ! $authorized ) {
				return new WP_Error( 'permission', 'Konfirmasi izin portal referensi untuk sekolah ini.' );
			}
			if ( get_transient( 'sdp_reference_rate' ) || get_transient( 'sdp85_rate' ) || get_transient( 'sdp84_network_rate' ) ) {
				return new WP_Error( 'rate', 'Jeda permintaan belum selesai.' );
			}
			if ( ! set_transient( 'sdp_reference_rate', 1, 60 ) || ! set_transient( 'sdp84_network_rate', 1, 60 ) ) {
				return new WP_Error( 'rate', 'Pembatas permintaan tidak tersedia.' );
			}
			$id     = $d['npsn'];
			$remote = SDP_Sources::fetch_reference( $id );
			if ( is_wp_error( $remote ) ) {
				return self::pause( $s, $remote->get_error_message() );
			}
			$add = SDP_Engine::additions( $d, $remote );
			if ( is_wp_error( $add ) ) {
				return self::pause( $s, $add->get_error_message() );
			}
			$add = array_intersect_key( $add, array_flip( array_merge( array( 'npsn' ), $n['reference'] ) ) );
			if ( count( $add ) > 1 ) {
				$expected = array_intersect_key( $d, array_flip( SDP_Discovery::FIELDS ) );
				$res      = SDP_Directory::save(
					$add,
					false,
					'fill',
					array(
						'label' => 'Referensi Data Pendidikan',
						'url'   => SDP_Sources::BASE . rawurlencode( $id ),
						'type'  => 'referensi',
					),
					$expected
				);
				if ( is_wp_error( $res ) ) {
					return self::pause( $s, $res->get_error_message() );
				}
			}
			$s['phase'] = 'website_route';
			$s['note']  = 'Referensi diperiksa. Kolom kosong saja diproses; profil tidak dipublikasikan secara baru.';
			return $s;
		}
		if ( in_array( $phase, array( 'website_route', 'discovery' ), true ) ) {
			return self::route_website( $s, $d );
		}
		if ( $phase === 'crawler' ) {
			if ( ! $n['website'] ) {
				$s['phase']   = 'handoff';
				$s['crawler'] = null;
				$s['note']    = 'Atribut naratif sudah terisi.';
				return $s;}
			if ( ! $authorized ) {
				return new WP_Error( 'permission', 'Konfirmasi izin penelusuran dan penggunaan ulang website ini.' );
			}
			if ( get_transient( 'sdp_reference_rate' ) ) {
				return new WP_Error( 'rate', 'Jeda permintaan belum selesai.' );
			}
			if ( ! empty( $s['crawler_expired'] ) ) {
				return self::pause( $s, 'Checkpoint crawler kedaluwarsa. Lewati sumber atau sekolah.' );
			}
			if ( $s['crawler'] === null ) {
				$j = SDP_Crawler::initial( $d );
				if ( is_wp_error( $j ) ) {
					return self::pause( $s, $j->get_error_message() );
				}$s['crawler'] = $j;
				$s['note']     = 'Crawler siap. Klik lagi untuk memeriksa robots.txt.';
				return $s;}
			$j = SDP_Crawler::step( $s['crawler'], $d );
			if ( is_wp_error( $j ) ) {
				return $j;
			}
			$s['crawler'] = $j;
			$s['note']    = $j['note'];
			if ( $j['state'] === 'stopped' ) {
				return self::pause( $s, $j['note'] );
			}
			if ( $j['state'] === 'done' ) {
				$s['phase'] = $j['results'] ? 'review' : 'handoff';
			}return $s;
		}
		if ( $phase === 'review' ) {
			return new WP_Error( 'review', 'Tinjau usulan per halaman, lalu klik Selesai tinjau.' );
		}
		if ( $phase === 'handoff' ) {
			return self::advance( $s, 'Pemeriksaan selesai. Dataset statistik/akreditasi dan kekurangan lain tetap perlu ditangani bila tersedia.' );
		}
		return new WP_Error( 'phase', 'Tahap tidak dikenal.' );
	}
	private static function input( $k ) {
		$v = $_POST[ $k ] ?? '';
		return is_string( $v ) ? trim( wp_unslash( $v ) ) : '';}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp_automation' );
		$op  = self::input( 'op' );
		$uid = get_current_user_id();
		$r   = SDP_Collector::lock(
			function () use ( $op, $uid ) {
				$s = self::state();
				if ( self::input( 'rev' ) !== ( $s['rev'] ?? '' ) ) {
					return new WP_Error( 'stale', 'Formulir lama. Muat ulang dashboard.' );
				}
				if ( $op === 'start' ) {
					if ( in_array( $s['status'] ?? '', array( 'running', 'paused' ), true ) ) {
						return new WP_Error( 'busy', 'Selesaikan atau batalkan kampanye dahulu.' );
					}
					$f = self::filters( wp_unslash( $_POST['filter'] ?? array() ) );
					if ( is_wp_error( $f ) ) {
						return $f;
					}$report = SDP_Missing::report( $f );
					if ( is_wp_error( $report ) ) {
						return $report;
					}$s = self::make( $report );
				} elseif ( in_array( $op, array( 'pause', 'resume', 'skip', 'cancel' ), true ) ) {
					$s = self::transition( $s, $op );} else {
					if ( ( $s['status'] ?? '' ) !== 'running' ) {
						return new WP_Error( 'state', 'Tidak ada kampanye berjalan.' );
					}
					$d = SDP_Discovery::load( $s['ids'][ $s['pos'] ] ?? '' );
					if ( is_wp_error( $d ) ) {
						return $d;
					}if ( ! self::matches( $d, $s['filters'] ) ) {
						return new WP_Error( 'identity', 'Wilayah atau jenjang berubah. Lewati atau batalkan.' );
					}
					if ( $op === 'review' ) {
						if ( $s['phase'] !== 'review' || self::input( 'authorized' ) !== '1' ) {
							return new WP_Error( 'review', 'Konfirmasi izin dan tahap tinjau diperlukan.' );
						}
						if ( ! is_array( $s['crawler'] ) ) {
							return new WP_Error( 'review', 'Checkpoint crawler kedaluwarsa. Tutup tahap tinjau.' );
						}
						$p = SDP_Crawler::review( $s['crawler'], $d, self::input( 'url' ) );
						if ( is_wp_error( $p ) ) {
							return $p;
						}
						if ( get_transient( 'sdp61_preview_' . $uid ) ) {
							return new WP_Error( 'review', 'Selesaikan atau buang pratinjau Website Sekolah yang masih aktif.' );
						}
						if ( ! set_transient( 'sdp61_preview_' . $uid, $p, 900 ) ) {
							return new WP_Error( 'database', 'Gagal menyimpan pratinjau.' );
						}return 'Usulan tersedia di Website Sekolah selama 15 menit. Kembali ke sini setelah ditinjau.';
					}
					if ( $op === 'finish_review' && $s['phase'] === 'review' ) {
						if ( get_transient( 'sdp61_preview_' . $uid ) ) {
							return new WP_Error( 'review', 'Selesaikan atau buang pratinjau yang masih aktif.' );
						}$s['phase']  = 'handoff';
						$s['crawler'] = null;
						$s['note']    = 'Tahap tinjau ditutup oleh administrator; tidak berarti semua usulan disetujui.';
					} elseif ( $op === 'skip_source' && in_array( $s['phase'], array( 'reference', 'discovery', 'crawler' ), true ) ) {
						if ( $s['phase'] === 'reference' ) {
							$s = self::route_website( $s, $d );
						} else {
							$s['phase']                   = 'handoff';
											$s['crawler'] = null;
											$s['note']    = 'Sumber website dilewati.';}
					} elseif ( $op === 'step' ) {
						if ( SDP_Collector::pending() ) {
									return new WP_Error( 'pending', 'Selesaikan antrean dataset dahulu.' );
						}$engine = SDP_Engine::state();
						if ( ( $engine['status'] ?? '' ) === 'running' && ! empty( $engine['auto'] ) ) {
							return new WP_Error( 'busy', 'Jeda kampanye Mesin Pengayaan otomatis dahulu.' );
						}
						$s = self::step( $s, $d, self::input( 'authorized' ) === '1' );
					} else {
						return new WP_Error( 'op', 'Operasi tidak dikenal.' );
					}
					}
					if ( is_wp_error( $s ) ) {
						return $s;
					}$saved = self::store( $s );
					return is_wp_error( $saved ) ? $saved : $s['note'];
			}
		);
		set_transient(
			'sdp86_notice_' . $uid,
			array(
				'error' => is_wp_error( $r ),
				'text'  => is_wp_error( $r ) ? $r->get_error_message() : $r,
			),
			120
		);
		wp_safe_redirect( admin_url( 'admin.php?page=sdp-automation' ) );
		exit;
	}
	private static function form( $s ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp_automation"><input type="hidden" name="rev" value="' . esc_attr( $s['rev'] ?? '' ) . '">';
		wp_nonce_field( 'sdp_automation' );}
	private static function button( $op, $label ) {
		echo '<button class="button" name="op" value="' . esc_attr( $op ) . '">' . esc_html( $label ) . '</button> ';}
	private static function consent() {
		echo '<p><label><input type="checkbox" name="authorized" value="1"> Saya telah memeriksa izin akses (termasuk robots.txt untuk portal referensi), penggunaan ulang, dan publikasi sumber untuk sekolah ini.</label></p>';}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}$s     = self::state();
		$uid    = get_current_user_id();
		$notice = get_transient( 'sdp86_notice_' . $uid );
		delete_transient( 'sdp86_notice_' . $uid );
		echo '<div class="wrap"><h1>Otomatisasi Terpadu — v8.6</h1><p>Satu kampanye, manual per langkah, tanpa cron baru. Pilih provinsi dan maksimal ' . esc_html( (string) self::cap() ) . ' sekolah per batch. Statistik dan akreditasi lengkap tetap melalui dataset berizin.</p>';
		if ( is_array( $notice ) ) {
			echo '<div class="notice ' . ( $notice['error'] ? 'notice-warning' : 'notice-success' ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
		}
		if ( ! in_array( $s['status'] ?? '', array( 'running', 'paused' ), true ) ) {
			self::form( $s );
			foreach ( array(
				'provinsi'  => 'Provinsi (wajib)',
				'kabupaten' => 'Kabupaten/kota',
				'kecamatan' => 'Kecamatan',
				'jenjang'   => 'Jenjang',
				'after'     => 'Setelah NPSN (cursor)',
			) as $k => $label ) {
				echo '<p><label>' . esc_html( $label ) . ' <input name="filter[' . esc_attr( $k ) . ']" maxlength="120" value="' . esc_attr( $k === 'after' ? ( $s['next'] ?? '' ) : ( $s['filters'][ $k ] ?? '' ) ) . '" ' . ( $k === 'provinsi' ? 'required' : '' ) . '></label></p>';
			}
			echo '<p><label>Batas <input type="number" name="filter[limit]" min="1" max="' . esc_attr( (string) self::cap() ) . '" value="' . esc_attr( (string) self::cap() ) . '"></label></p>';
			self::button( 'start', 'Buat kampanye (tanpa jaringan)' );
			echo '</form>';
		}
		if ( ! empty( $s['ids'] ) ) {
			echo '<h2>Status: ' . esc_html( $s['status'] ) . ' — ' . esc_html( (string) $s['pos'] ) . ' / ' . esc_html( (string) count( $s['ids'] ) ) . '</h2><p>' . esc_html( $s['note'] ) . '</p><p>Cursor batch berikutnya: ' . esc_html( $s['next'] ?: 'Tidak ada' ) . '</p><details><summary>Daftar kandidat NPSN (prioritas dalam batch)</summary><p>' . esc_html( implode( ', ', $s['ids'] ) ) . '</p></details>';}
		if ( in_array( $s['status'] ?? '', array( 'running', 'paused' ), true ) ) {
			$id = $s['ids'][ $s['pos'] ];
			$d  = SDP_Discovery::load( $id );
			echo '<h3>' . esc_html( $id ) . ' — ' . esc_html( $s['phase'] ) . '</h3>';
			if ( ! is_wp_error( $d ) ) {
				$n = self::needs( $d );
				echo '<p><strong>' . esc_html( $d['nama'] ) . '</strong> — ' . esc_html( $d['website'] ?? 'Website belum ada' ) . '</p><ul>';
				foreach ( $n as $k => $v ) {
					echo '<li>' . esc_html( $k ) . ': ' . esc_html( implode( ', ', $v ) ?: '—' ) . '</li>';
				}echo '</ul>';
				foreach ( array(
					'sdp-discovery&npsn=' . rawurlencode( $id ) => 'Penemuan Website',
					'sdp-website'      => 'Website Sekolah (pratinjau)',
					'sdp-sources'      => 'Sumber Resmi / dataset',
					'sdp-intelligence' => 'Validasi & Konflik',
				) as $page => $label ) {
					echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '">' . esc_html( $label ) . '</a> ';
				}
			} else {
				echo '<p>' . esc_html( $d->get_error_message() ) . '</p>';
			}
				self::form( $s );
			if ( $s['status'] === 'paused' ) {
				self::button( 'resume', 'Lanjutkan' );
			} else {
				self::button( 'pause', 'Jeda' );
			}self::button( 'skip', 'Lewati sekolah' );
				self::button( 'cancel', 'Batalkan kampanye' );
				echo '</form>';
			if ( $s['status'] === 'running' && ! is_wp_error( $d ) ) {
				self::form( $s );
				self::consent();
				if ( $s['phase'] === 'review' ) {
					self::button( 'finish_review', 'Selesai tinjau' );
				} else {
					self::button( 'step', 'Proses satu langkah' );
				}
				if ( in_array( $s['phase'], array( 'reference', 'discovery', 'crawler' ), true ) ) {
					self::button( 'skip_source', 'Lewati sumber ini' );
				}echo '</form>';
				if ( $s['phase'] === 'review' ) {
					foreach ( ( $s['crawler']['results'] ?? array() ) as $url => $values ) {
						self::form( $s );
							echo '<p>' . esc_html( $url ) . ' — ' . esc_html( implode( ', ', array_keys( $values ) ) ) . '</p><input type="hidden" name="url" value="' . esc_attr( $url ) . '">';
							self::consent();
							self::button( 'review', 'Kirim ke pratinjau Website Sekolah' );
							echo '</form>';}
				}
				if ( ! empty( $s['crawler_expired'] ) ) {
					echo '<p>Checkpoint crawler kedaluwarsa; lewati sumber atau tutup tahap tinjau.</p>';
				}
				if ( is_array( $s['crawler'] ) ) {
					echo '<p>Halaman crawler: ' . esc_html( (string) $s['crawler']['pages'] ) . '; antrean: ' . esc_html( (string) count( $s['crawler']['queue'] ) ) . '</p>';
				}
			}
		}
		if ( ! empty( $s['events'] ) ) {
			echo '<h2>Riwayat kampanye</h2><ul>';
			foreach ( array_reverse( $s['events'] ) as $e ) {
				echo '<li>' . esc_html( $e['time'] . ' ' . $e['npsn'] . ' — ' . $e['message'] ) . '</li>';
			}echo '</ul>';}
		echo '</div>';
	}
}
