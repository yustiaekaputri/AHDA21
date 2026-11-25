<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Opt-in, single-event scheduler for the v8.6 campaign. No frontend polling. */
final class SDP_Scheduler {
	const OPTION = 'sdp87_scheduler';
	const HOOK   = 'sdp87_single_tick';
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 78 );
		add_action( 'admin_post_sdp_scheduler', array( __CLASS__, 'action' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );}
	public static function menu() {
		add_submenu_page( 'sdp-directory', 'Scheduler Otomatisasi', 'Scheduler Otomatisasi', 'manage_options', 'sdp-scheduler', array( __CLASS__, 'page' ) );}
	public static function disabled() {
		return ( defined( 'SDP_SAFE_MODE' ) && SDP_SAFE_MODE === true ) || ( defined( 'SDP_SCHEDULER_DISABLED' ) && SDP_SCHEDULER_DISABLED );}
	public static function cap() {
		return SDP_Resource::cap( 40, 12 );}
	public static function state() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array(); }
	public static function store( $s ) {
		return update_option( self::OPTION, $s, false ) || get_option( self::OPTION, array() ) === $s;}
	public static function settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'settings', 'Pengaturan tidak valid.' );
		}
		$s = array();
		foreach ( array(
			'start'  => array( 0, 23, 1 ),
			'end'    => array( 0, 23, 5 ),
			'budget' => array( 1, self::cap(), self::cap() ),
			'load'   => array( 1, SDP_Resource::cap( 160, 10 ), SDP_Resource::cap( 20, 8 ) ),
		) as $k => $v ) {
			$x = $raw[ $k ] ?? $v[2];
			if ( ! is_scalar( $x ) || ! preg_match( '/^\d{1,3}$/D', (string) $x ) || (int) $x < $v[0] || (int) $x > $v[1] ) {
				return new WP_Error( 'settings', 'Batas pengaturan tidak valid: ' . $k );
			}$s[ $k ] = (int) $x;}
		$s['mode'] = $raw['mode'] ?? 'manual';
		if ( ! in_array( $s['mode'], array( 'manual', 'scheduled' ), true ) ) {
			return new WP_Error( 'settings', 'Mode tidak valid.' );
		}
		$s['network'] = ( $raw['network'] ?? '' ) === '1';
		$s['unknown'] = ( $raw['unknown'] ?? '' ) === '1';
		return $s;
	}
	public static function window( $hour, $start, $end ) {
		return $start === $end || ( $start < $end ? ( $hour >= $start && $hour < $end ) : ( $hour >= $start || $hour < $end ) );}
	public static function delay( $attempt ) {
		return min( 1800, 60 * ( 2 ** max( 0, min( 5, (int) $attempt ) ) ) );}
	public static function mem_bytes( $v ) {
		$v = trim( (string) $v );
		if ( '-1' === $v ) {
			return -1;
		}if ( ! preg_match( '/^(\d+)\s*([KMG]?)$/iD', $v, $m ) ) {
			return 0;
		}return (int) min(
			PHP_INT_MAX,
			(float) $m[1] * ( 1024 ** ( array(
				''  => 0,
				'K' => 1,
				'M' => 2,
				'G' => 3,
			)[ strtoupper( $m[2] ) ] ) )
		);}
	public static function metrics() {
		$load      = function_exists( 'sys_getloadavg' ) ? @sys_getloadavg() : false;
		$available = null;
		if ( is_readable( '/proc/meminfo' ) ) {
			$h = @fopen( '/proc/meminfo', 'r' );if ( $h ) {
				for ( $i = 0;$i < 32 && ( $line = fgets( $h, 256 ) ) !== false;$i++ ) {
					if ( preg_match( '/^MemAvailable:\s+(\d+)\s+kB/i', $line, $m ) ) {
							$available = (float) $m[1] * 1024;
						break;
					}
				}fclose( $h );}
		}
		return array(
			'load'      => is_array( $load ) && isset( $load[0] ) ? (float) $load[0] : null,
			'available' => $available,
			'limit'     => self::mem_bytes( ini_get( 'memory_limit' ) ),
			'used'      => memory_get_usage( true ),
		);
	}
	public static function guard( $cfg, $m ) {
		if ( 0 === $m['limit'] ) {
			return 'Batas memori PHP tidak dapat dibaca.';
		}
		if ( $m['limit'] > 0 && $m['limit'] - $m['used'] < SDP_Resource::cap( 33554432, 16777216 ) ) {
			return 'Sisa memori PHP terlalu rendah.';
		}
		if ( null !== $m['load'] && $m['load'] > min( $cfg['load'], SDP_Resource::cap( 160, 10 ) ) / 10 ) {
			return 'Load average melebihi ambang.';
		}
		if ( null !== $m['available'] && $m['available'] < SDP_Resource::cap( 268435456, 134217728 ) ) {
			return 'MemAvailable terlalu rendah.';
		}
		if ( ( null === $m['load'] || null === $m['available'] ) && ! $cfg['unknown'] ) {
			return 'Metrik OS tidak tersedia; gunakan langkah manual biasa atau izinkan mode tanpa metrik OS.';
		}
		return '';
	}
	public static function active( $s ) {
		return ( $s['status'] ?? '' ) === 'running';}
	public static function network_step( $c, $d ) {
		$p = $c['phase'] ?? '';
		$n = SDP_Automation::needs( $d );
		return ( 'reference' === $p && ! empty( $n['reference'] ) ) || ( 'crawler' === $p && ! empty( $n['website'] ) && is_array( $c['crawler'] ?? null ) );}
	public static function needs_consent( $c, $d ) {
		$p = $c['phase'] ?? '';
		$n = SDP_Automation::needs( $d );
		return ( 'reference' === $p && ! empty( $n['reference'] ) ) || ( 'crawler' === $p && ! empty( $n['website'] ) );}
	public static function reset_day( $s, $now ) {
		$day = gmdate( 'Y-m-d', $now );
		if ( ( $s['day'] ?? '' ) !== $day ) {
			$s['day']  = $day;
			$s['used'] = 0;
		}return $s;}
	public static function build( $old, $c, $d, $cfg, $uid, $now ) {
		if ( ( $c['status'] ?? '' ) !== 'running' || empty( $c['rev'] ) || ( $c['ids'][ $c['pos'] ] ?? null ) !== ( $d['npsn'] ?? null ) || ! SDP_Automation::matches( $d, $c['filters'] ) || SDP_SEO::demo( $d ) ) {
			return new WP_Error( 'state', 'Buat atau lanjutkan kampanye v8.6 yang valid terlebih dahulu.' );
		}
		$s = self::reset_day( $old, $now );
		return array(
			'status'     => 'running',
			'token'      => wp_generate_uuid4(),
			'rev'        => $c['rev'],
			'owner'      => $uid,
			'cfg'        => $cfg,
			'grant'      => $cfg['network'] ? SDP_Discovery::fingerprint( $d ) : '',
			'grant_npsn' => $d['npsn'],
			'day'        => $s['day'],
			'used'       => (int) ( $s['used'] ?? 0 ),
			'created'    => $now,
			'expires'    => $now + 86400,
			'attempt'    => 0,
			'note'       => 'Scheduler siap.',
			'next'       => 0,
			'steps'      => 0,
		);
	}
	public static function schedule( $s, $seconds ) {
		if ( 'scheduled' !== $s['cfg']['mode'] ) {
			return true;
		}
		$args = array( $s['token'] );
		if ( wp_next_scheduled( self::HOOK, $args ) ) {
			return true;
		}
		return wp_schedule_single_event( time() + max( 60, (int) $seconds ), self::HOOK, $args, true ) === true;
	}
	public static function finish( $s, $note ) {
		$s['status'] = 'stopped';
		$s['note']   = $note;
		$s['next']   = 0;
		if ( ! self::store( $s ) ) {
			return new WP_Error( 'database', 'Status scheduler gagal disimpan.' );
		}wp_clear_scheduled_hook( self::HOOK, array( $s['token'] ) );
		return $note;}
	public static function defer( $s, $note, $seconds = null ) {
		++$s['attempt'];
		if ( $s['attempt'] > 5 ) {
			return self::finish( $s, 'Batas penundaan tercapai: ' . $note );
		}
		$seconds   = $seconds ?? self::delay( $s['attempt'] );
		$s['next'] = time() + $seconds;
		$s['note'] = $note;
		if ( ! self::store( $s ) ) {
			return new WP_Error( 'database', 'Checkpoint scheduler gagal disimpan.' );
		}if ( ! self::schedule( $s, $seconds ) ) {
			return self::finish( $s, 'WP-Cron gagal menjadwalkan langkah.' );
		}return $note;
	}
	public static function run( $s, $now, $metrics = null ) {
		if ( self::disabled() || $now >= $s['expires'] || ! user_can( $s['owner'], 'manage_options' ) ) {
			return self::finish( $s, 'Scheduler dinonaktifkan, izin admin berubah, atau sesi 24 jam berakhir.' );
		}
		$c = SDP_Automation::state();
		if ( ( $c['status'] ?? '' ) !== 'running' || ( $c['rev'] ?? '' ) !== $s['rev'] ) {
			return self::finish( $s, 'Kampanye selesai, dijeda, atau berubah melalui tindakan lain. Mulai sesi baru bila diperlukan.' );
		}
		if ( in_array( $c['phase'], array( 'discovery', 'review' ), true ) ) {
			return self::finish( $s, 'Perlu tindakan administrator di Otomatisasi Terpadu: ' . $c['phase'] );
		}
		$s = self::reset_day( $s, $now );
		if ( $now < ( $s['next'] ?? 0 ) ) {
			if ( ! self::schedule( $s, $s['next'] - $now ) ) {
				return self::finish( $s, 'Gagal menjadwalkan kembali.' );
			}return 'Masih dalam masa jeda.';}
		$cfg = $s['cfg'];if ( ! self::window( (int) wp_date( 'G', $now ), $cfg['start'], $cfg['end'] ) ) {
			// No busy polling outside the window; next check at most hourly, bounded by lease.
			$s['next'] = $now + 3600;
			$s['note'] = 'Di luar jam kerja.';
			if ( ! self::store( $s ) ) {
				return new WP_Error( 'database', 'Checkpoint gagal.' );
			}if ( ! self::schedule( $s, 3600 ) ) {
				return self::finish( $s, 'Gagal menjadwalkan kembali.' );
			}return $s['note'];}
		$guard = self::guard( $cfg, $metrics ?? self::metrics() );
		if ( '' !== $guard ) {
			return self::defer( $s, $guard );
		}
		if ( SDP_Collector::pending() ) {
			return self::defer( $s, 'Antrean dataset masih aktif.' );
		}$e = SDP_Engine::state();
		if ( ( $e['status'] ?? '' ) === 'running' && ! empty( $e['auto'] ) ) {
			return self::defer( $s, 'Mesin Pengayaan lama masih otomatis.' );
		}
		$d = SDP_Discovery::load( $c['ids'][ $c['pos'] ] ?? '' );
		if ( is_wp_error( $d ) || ! SDP_Automation::matches( $d, $c['filters'] ) ) {
			return self::finish( $s, 'Profil tidak tersedia atau identitas wilayah berubah.' );
		}
		$consent    = self::needs_consent( $c, $d );
		$network    = self::network_step( $c, $d );
		$authorized = $cfg['network'] && SDP_Resource::scheduled_network() && $s['grant_npsn'] === $d['npsn'] && hash_equals( $s['grant'], SDP_Discovery::fingerprint( $d ) );
		if ( $consent && ! $authorized ) {
			return self::finish( $s, 'Sumber memerlukan persetujuan untuk NPSN dan identitas saat ini. Gunakan langkah manual atau mulai sesi berizin.' );
		}
		if ( $network ) {
			if ( get_transient( 'sdp_reference_rate' ) || get_transient( 'sdp85_rate' ) || get_transient( 'sdp84_network_rate' ) ) {
				return self::defer( $s, 'Jeda permintaan jaringan belum selesai.' );
			}if ( $s['used'] >= min( $cfg['budget'], self::cap() ) ) {
				return self::finish( $s, 'Anggaran langkah jaringan UTC hari ini habis.' );
			}++$s['used'];
			if ( ! self::store( $s ) ) {
				return new WP_Error( 'database', 'Anggaran gagal disimpan; jaringan dibatalkan.' );
			}
		}
		$r = SDP_Automation::step( $c, $d, $authorized );
		if ( is_wp_error( $r ) ) {
			if ( in_array( $r->get_error_code(), array( 'rate', 'busy' ), true ) ) {
				return self::defer( $s, $r->get_error_message() );
			}return self::finish( $s, $r->get_error_message() );}
		$saved = SDP_Automation::store( $r );
		if ( is_wp_error( $saved ) ) {
			return self::finish( $s, $saved->get_error_message() );
		}$new     = SDP_Automation::state();
		$s['rev'] = $new['rev'] ?? '';
		++$s['steps'];
		$s['attempt'] = 0;
		$s['note']    = $r['note'];
		if ( ( $r['status'] ?? '' ) !== 'running' || in_array( $r['phase'], array( 'discovery', 'review' ), true ) ) {
			return self::finish( $s, $r['note'] . ' — Scheduler berhenti; periksa kampanye.' );
		}
		$seconds   = max( 60, min( 3600, (int) ( $r['crawler']['next'] ?? 0 ) - $now ) );
		$s['next'] = $now + $seconds;
		if ( ! self::store( $s ) ) {
			return new WP_Error( 'database', 'Checkpoint scheduler gagal.' );
		}if ( ! self::schedule( $s, $seconds ) ) {
			return self::finish( $s, 'WP-Cron gagal menjadwalkan langkah.' );
		}return $s['note'];
	}
	public static function tick( $token ) {
		$r = SDP_Collector::lock(
			function () use ( $token ) {
				$s = self::state();
				if ( ! self::active( $s ) || ( $s['token'] ?? '' ) !== $token || ( $s['cfg']['mode'] ?? '' ) !== 'scheduled' ) {
					return 'Tidak ada pekerjaan.';
				}return self::run( $s, time() );
			}
		);
		// A failed lock must not mutate another worker's state. One delayed wakeup, no recurring event.
		if ( is_wp_error( $r ) && $r->get_error_code() === 'busy' ) {
			$s = self::state();
			if ( self::active( $s ) && ( $s['token'] ?? '' ) === $token && time() < $s['expires'] && ! self::disabled() ) {
				self::schedule( $s, 300 );
			}
		}return $r;
	}
	public static function deactivate() {
		wp_unschedule_hook( self::HOOK );
		$s = self::state();
		if ( self::active( $s ) ) {
			$s['status'] = 'stopped';
			$s['note']   = 'Plugin dinonaktifkan.';
			$s['next']   = 0;
			self::store( $s );}}
	private static function input( $k ) {
		$x = $_POST[ $k ] ?? '';
		return is_string( $x ) ? wp_unslash( $x ) : '';}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}check_admin_referer( 'sdp_scheduler' );
		$uid = get_current_user_id();
		$op  = self::input( 'op' );
		$r   = SDP_Collector::lock(
			function () use ( $op, $uid ) {
				$s = self::state();
				if ( self::input( 'token' ) !== ( $s['token'] ?? '' ) ) {
					return new WP_Error( 'stale', 'Formulir lama. Muat ulang.' );
				}
				if ( 'stop' === $op ) {
					if ( ! self::active( $s ) ) {
						return 'Tidak ada sesi aktif.';
					}return self::finish( $s, 'Dihentikan administrator.' );}
				if ( self::disabled() ) {
					return new WP_Error( 'disabled', 'Scheduler dinonaktifkan melalui wp-config.php.' );
				}
				if ( 'run' === $op ) {
					if ( ! self::active( $s ) || 'manual' !== $s['cfg']['mode'] ) {
						return new WP_Error( 'state', 'Hanya sesi manual yang dapat dijalankan di sini.' );
					}return self::run( $s, time() );}
				if ( 'recover' === $op ) {
					if ( ! self::active( $s ) || 'scheduled' !== $s['cfg']['mode'] ) {
						return new WP_Error( 'state', 'Tidak ada sesi terjadwal.' );
					}if ( time() >= $s['expires'] ) {
						return self::finish( $s, 'Sesi kedaluwarsa.' );
					}return self::schedule( $s, max( 60, ( $s['next'] ?? 0 ) - time() ) ) ? 'Jadwal tersedia.' : new WP_Error( 'cron', 'Gagal memulihkan jadwal.' );}
				if ( 'start' !== $op || self::active( $s ) ) {
					return new WP_Error( 'state', 'Hentikan sesi aktif terlebih dahulu.' );
				}$cfg = self::settings( wp_unslash( $_POST['cfg'] ?? array() ) );
				if ( is_wp_error( $cfg ) ) {
					return $cfg;
				}
				$c = SDP_Automation::state();
				if ( self::input( 'rev' ) !== ( $c['rev'] ?? '' ) ) {
					return new WP_Error( 'stale', 'Kampanye berubah.' );
				}$d = SDP_Discovery::load( $c['ids'][ $c['pos'] ?? 0 ] ?? '' );
				if ( is_wp_error( $d ) ) {
					return $d;
				}
				if ( $cfg['network'] && ( ! SDP_Resource::scheduled_network() || self::input( 'consent' ) !== '1' ) ) {
					return new WP_Error( 'permission', 'Izin sumber dan pengaturan jaringan terjadwal diperlukan.' );
				}
				$n = self::build( $s, $c, $d, $cfg, $uid, time() );
				if ( is_wp_error( $n ) ) {
					return $n;
				}wp_unschedule_hook( self::HOOK );
				if ( ! self::store( $n ) ) {
					return new WP_Error( 'database', 'Sesi gagal disimpan.' );
				}if ( ! self::schedule( $n, 60 ) ) {
					return self::finish( $n, 'WP-Cron gagal dijadwalkan.' );
				}return 'Sesi dibuat. Tidak ada permintaan jaringan saat konfigurasi.';
			}
		);
		set_transient( 'sdp87_notice_' . $uid, is_wp_error( $r ) ? $r->get_error_message() : $r, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=sdp-scheduler' ) );
		exit;
	}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}$s     = self::state();
		$c      = SDP_Automation::state();
		$uid    = get_current_user_id();
		$notice = get_transient( 'sdp87_notice_' . $uid );
		delete_transient( 'sdp87_notice_' . $uid );
		$m = self::metrics();
		echo '<div class="wrap"><h1>Scheduler Otomatisasi — v8.7</h1><p>Eksperimental. Satu sesi 24 jam, satu langkah per putaran. Tidak menjalankan impor besar, statistik, atau pencarian API secara otomatis. Berhenti di tahap discovery/review. <a href="' . esc_url( admin_url( 'admin.php?page=sdp-automation' ) ) . '">Buka Otomatisasi Terpadu</a>.</p>';
		if ( $notice ) {
			echo '<p>' . esc_html( $notice ) . '</p>';
		}echo '<p>Status: ' . esc_html( $s['status'] ?? 'Belum aktif' ) . ' — ' . esc_html( $s['note'] ?? '' ) . '</p><p>Langkah sesi: ' . esc_html( (string) ( $s['steps'] ?? 0 ) ) . '; anggaran terpakai UTC hari ini: ' . esc_html( (string) ( self::reset_day( $s, time() )['used'] ?? 0 ) ) . '; metrik load 1 menit: ' . esc_html( (string) ( $m['load'] ?? 'Tidak tersedia' ) ) . '; MemAvailable: ' . esc_html( $m['available'] === null ? 'Tidak tersedia' : round( $m['available'] / 1048576 ) . ' MiB' ) . '</p>';
		echo '<p>Kampanye: ' . esc_html( $c['status'] ?? 'Belum dibuat' ) . ' — NPSN ' . esc_html( $c['ids'][ $c['pos'] ?? 0 ] ?? '—' ) . ' — ' . esc_html( $c['phase'] ?? '—' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdp_scheduler"><input type="hidden" name="token" value="' . esc_attr( $s['token'] ?? '' ) . '"><input type="hidden" name="rev" value="' . esc_attr( $c['rev'] ?? '' ) . '">';
		wp_nonce_field( 'sdp_scheduler' );
		if ( self::active( $s ) ) {
			echo '<p>Jadwal berikut: ' . esc_html( ( $t = wp_next_scheduled( self::HOOK, array( $s['token'] ) ) ) ? wp_date( 'Y-m-d H:i:s', $t ) : 'Tidak terjadwal' ) . '</p>';
			if ( 'manual' === $s['cfg']['mode'] ) {
				echo '<button class="button" name="op" value="run">Proses satu langkah</button> ';
			} else {
				echo '<button class="button" name="op" value="recover">Pulihkan jadwal jika hilang</button> ';
			}
			echo '<button class="button" name="op" value="stop">Hentikan sesi</button>';
		} else {
			echo '<p><label>Mode <select name="cfg[mode]"><option value="manual">Manual (tanpa cron)</option><option value="scheduled">Terjadwal (opt-in)</option></select></label></p>';
			foreach ( array(
				'start'  => array( 'Jam mulai (zona waktu WordPress)', 0, 23, 1 ),
				'end'    => array( 'Jam akhir (eksklusif; sama dengan mulai = 24 jam)', 0, 23, 5 ),
				'budget' => array( 'Anggaran langkah berpotensi jaringan per hari UTC', 1, self::cap(), self::cap() ),
				'load'   => array( 'Ambang load average ×10 (8 = 0,8)', 1, SDP_Resource::cap( 160, 10 ), SDP_Resource::cap( 20, 8 ) ),
			) as $k => $v ) {
				echo '<p><label>' . esc_html( $v[0] ) . ' <input type="number" name="cfg[' . esc_attr( $k ) . ']" min="' . $v[1] . '" max="' . $v[2] . '" value="' . $v[3] . '" required></label></p>';
			}
			echo '<p><label><input type="checkbox" name="cfg[unknown]" value="1"> Izinkan jika metrik OS tidak tersedia (mis. Windows LocalWP; pemeriksaan PHP tetap berlaku).</label></p><p><label><input type="checkbox" name="cfg[network]" value="1"> Izinkan langkah jaringan hanya untuk NPSN dan identitas yang tampil di atas.</label></p><p><label><input type="checkbox" name="consent" value="1"> Saya memeriksa izin akses otomatis, robots.txt portal referensi, dan izin publikasi untuk sumber sekolah ini.</label></p><p>Jaringan terjadwal pada preset ini: ' . ( SDP_Resource::scheduled_network() ? 'diizinkan dengan persetujuan' : 'nonaktif; perlu SDP_LOW_RESOURCE_NETWORK_CRON=true' ) . '.</p><button class="button button-primary" name="op" value="start">Mulai sesi</button>';}
			echo '</form><p>Load average dan MemAvailable hanyalah indikator; bukan pengukuran CPU per pengunjung, RAM cgroup, atau bandwidth. Jeda 60 detik minimum. Data hasil crawler tetap harus ditinjau.</p></div>';
	}
}
