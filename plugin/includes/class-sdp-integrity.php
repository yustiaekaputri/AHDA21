<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Identity checks on writes; bounded, read-only diagnostics on demand. */
final class SDP_Integrity {
	public static function relation( $npsn, $existing, $old, $post, $meta, $owners, $orphans = array() ) {
		if ( ! $existing ) {
			return $orphans ? new WP_Error( 'orphan', 'Artikel dengan NPSN ini sudah ada tanpa indeks. Audit dan pulihkan cadangan sebelum impor.' ) : true;
		}
		if ( ! is_array( $old ) || ( $old['npsn'] ?? null ) !== $npsn || ! is_string( $old['nama'] ?? null ) || trim( $old['nama'] ) === '' ) {
			return new WP_Error( 'identity', 'Payload indeks tidak cocok dengan NPSN.' );
		}
		$id = (int) ( $existing['post_id'] ?? 0 );
		if ( ! $id || ! $post || (int) $post->ID !== $id || SDP_Directory::TYPE !== $post->post_type || ! in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), true ) ) {
			return new WP_Error( 'identity', 'Relasi artikel tidak valid. Audit sebelum memperbarui.' );
		}
		if ( $meta !== array( $npsn ) || array_values( $owners ) !== array( $npsn ) ) {
			return new WP_Error( 'identity', 'Metadata NPSN atau kepemilikan artikel bertentangan. Audit sebelum memperbarui.' );
		}
		return true;
	}
	public static function check_write( $npsn, $existing, $old ) {
		global $wpdb;
		$t = SDP_Directory::table();
		if ( ! $existing ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key='_sdp_npsn' AND m.meta_value=%s AND p.post_type=%s LIMIT 2", $npsn, SDP_Directory::TYPE ) );
			if ( $wpdb->last_error || ! is_array( $ids ) ) {
				return new WP_Error( 'database', 'Gagal memeriksa artikel tanpa indeks.' );
			}
			return self::relation( $npsn, null, array(), null, array(), array(), $ids );
		}
		$id   = (int) ( $existing['post_id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		$meta = $wpdb->get_col( $wpdb->prepare( "SELECT CASE WHEN OCTET_LENGTH(meta_value)<=8 THEN meta_value ELSE NULL END FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_sdp_npsn' LIMIT 2", $id ) );
		if ( $wpdb->last_error || ! is_array( $meta ) ) {
			return new WP_Error( 'database', 'Gagal memeriksa metadata artikel.' );
		}
		$owners = $wpdb->get_col( $wpdb->prepare( "SELECT npsn FROM $t WHERE post_id=%d LIMIT 2", $id ) );
		if ( $wpdb->last_error || ! is_array( $owners ) ) {
			return new WP_Error( 'database', 'Gagal memeriksa pemilik artikel.' );
		}
		return self::relation( $npsn, $existing, $old, $post, $meta, $owners );
	}
	public static function flags( $meta, $owners ) {
		$flags = array();
		if ( count( $meta ) !== 1 || ! is_string( $meta[0] ?? null ) || ! preg_match( '/^[A-Z0-9]{8}$/D', $meta[0] ) ) {
			$flags[] = 'invalid_post_npsn';
		}
		if ( ! $owners ) {
			$flags[] = 'orphan_post';
		} elseif ( count( $owners ) > 1 ) {
			$flags[] = 'shared_post_id';
		} elseif ( count( $meta ) === 1 && $meta[0] !== $owners[0] ) {
			$flags[] = 'post_index_npsn_mismatch';
		}
		return $flags;
	}
	public static function audit_posts( $after = 0 ) {
		if ( ! is_scalar( $after ) || ! preg_match( '/^\d{1,18}$/D', (string) $after ) ) {
			return new WP_Error( 'cursor', 'Cursor ID tidak valid.' );
		}
		global $wpdb;
		$t   = SDP_Directory::table();
		$n   = SDP_Resource::cap( 100, 25 );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_type=%s ORDER BY ID LIMIT %d", (int) $after, SDP_Directory::TYPE, $n + 1 ) );
		if ( $wpdb->last_error || ! is_array( $ids ) ) {
			return new WP_Error( 'database', 'Gagal membaca daftar artikel.' );
		}
		$more   = count( $ids ) > $n;
		$ids    = array_slice( $ids, 0, $n );
		$issues = array();
		foreach ( $ids as $id ) {
			// Limit reads even when corrupted metadata is unusually large or duplicated.
			$meta = $wpdb->get_col( $wpdb->prepare( "SELECT CASE WHEN OCTET_LENGTH(meta_value)<=8 THEN meta_value ELSE NULL END FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_sdp_npsn' LIMIT 2", $id ) );
			if ( $wpdb->last_error || ! is_array( $meta ) ) {
				return new WP_Error( 'database', 'Gagal membaca metadata.' );
			}
			$owners = $wpdb->get_col( $wpdb->prepare( "SELECT npsn FROM $t WHERE post_id=%d LIMIT 2", $id ) );
			if ( $wpdb->last_error || ! is_array( $owners ) ) {
				return new WP_Error( 'database', 'Gagal membaca indeks.' );
			}
			$f = self::flags( $meta, $owners );
			if ( $f ) {
				$issues[] = array(
					'post_id' => (int) $id,
					'flags'   => $f,
				);
			}
		}
		return array(
			'checked' => count( $ids ),
			'issues'  => $issues,
			'next'    => $more ? (int) end( $ids ) : null,
			'scope'   => 'artikel ke indeks, baca-saja',
		);
	}
	public static function cli( $args, $assoc ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Gunakan --user administrator.' );
		}
		$r = self::audit_posts( $assoc['after'] ?? 0 );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::log( wp_json_encode( $r, JSON_PRETTY_PRINT ) );
	}
}
