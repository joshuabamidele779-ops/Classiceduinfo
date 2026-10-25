<?php
/**
 * Concurrent-stream limiter.
 *
 * This is the module's answer to the *realistic* leak on a site like this.
 * Nobody is going to defeat the token chain with a download tool; what
 * actually happens is that one paying account's login gets passed round a
 * WhatsApp group and twenty students watch on it. Capping simultaneous
 * streams per account (`video_concurrent_limit`) makes that sharing visibly
 * annoying without inconveniencing a student who legitimately watches on a
 * phone and a laptop.
 *
 * State lives in a single transient per user holding `sid => last_seen`. No new
 * database table, and it self-expires, so an abandoned tab frees its slot
 * automatically after SLOT_TTL seconds.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-account simultaneous stream accounting.
 */
class CES_Lms_Concurrency {

	/**
	 * Seconds of silence after which a session is considered gone.
	 *
	 * Must comfortably exceed the player's heartbeat interval so a student on
	 * a slow mobile connection is not evicted mid-lesson.
	 */
	const SLOT_TTL = 150;

	/**
	 * Minimum seconds between transient writes for the same session.
	 *
	 * Segment requests arrive every few seconds. Writing the transient on each
	 * one would mean a `wp_options` write per segment on a host with no object
	 * cache, which is exactly the kind of thing that gets an account
	 * throttled. Reads stay cheap; writes are coalesced.
	 */
	const TOUCH_INTERVAL = 20;

	/**
	 * Transient key for a user's session map.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function key( $user_id ) {
		return 'ces_lms_streams_' . (int) $user_id;
	}

	/**
	 * Read and prune a user's live session map.
	 *
	 * @param int $user_id User id.
	 * @return array sid => last-seen unix timestamp.
	 */
	public static function sessions( $user_id ) {
		$map = get_transient( self::key( $user_id ) );

		if ( ! is_array( $map ) ) {
			return array();
		}

		$cutoff = time() - self::SLOT_TTL;
		$live   = array();

		foreach ( $map as $sid => $seen ) {
			$sid = CES_Lms_Token::sanitize_sid( $sid );

			if ( '' !== $sid && (int) $seen >= $cutoff ) {
				$live[ $sid ] = (int) $seen;
			}
		}

		return $live;
	}

	/**
	 * Number of live streams for a user.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function count( $user_id ) {
		return count( self::sessions( $user_id ) );
	}

	/**
	 * Effective limit, 0 meaning unlimited.
	 *
	 * @return int
	 */
	public static function limit() {
		return max( 0, CES_Helpers::setting_int( 'video_concurrent_limit', 2 ) );
	}

	/**
	 * Claim or refresh a session slot.
	 *
	 * @param int    $user_id User id.
	 * @param string $sid     Stream session id.
	 * @param bool   $write   Whether this call may persist (false = read-only check).
	 * @return true|WP_Error True when the stream may proceed.
	 */
	public static function touch( $user_id, $sid, $write = true ) {
		$user_id = (int) $user_id;
		$sid     = CES_Lms_Token::sanitize_sid( $sid );
		$limit   = self::limit();

		if ( $user_id <= 0 || '' === $sid ) {
			return new WP_Error( 'ces_stream_session', __( 'Your video session is not recognised. Reload the lesson page.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		if ( 0 === $limit ) {
			return true;
		}

		$sessions = self::sessions( $user_id );
		$known    = isset( $sessions[ $sid ] );

		if ( ! $known && count( $sessions ) >= $limit ) {
			return new WP_Error(
				'ces_stream_limit',
				sprintf(
					/* translators: %d: number of allowed simultaneous streams. */
					_n(
						'This account is already watching on another device. Only %d video can play at a time. Close the other tab and try again.',
						'This account is already watching on other devices. Only %d videos can play at a time. Close the other tabs and try again.',
						$limit,
						'classic-edu-suite'
					),
					$limit
				),
				array( 'status' => 429 )
			);
		}

		if ( ! $write ) {
			return true;
		}

		$now = time();

		// Coalesce writes: only persist when this slot is new or stale.
		if ( $known && ( $now - (int) $sessions[ $sid ] ) < self::TOUCH_INTERVAL ) {
			return true;
		}

		$sessions[ $sid ] = $now;

		set_transient( self::key( $user_id ), $sessions, self::SLOT_TTL + 60 );

		return true;
	}

	/**
	 * Release a slot, e.g. when the player page unloads.
	 *
	 * @param int    $user_id User id.
	 * @param string $sid     Stream session id.
	 * @return void
	 */
	public static function release( $user_id, $sid ) {
		$user_id  = (int) $user_id;
		$sid      = CES_Lms_Token::sanitize_sid( $sid );
		$sessions = self::sessions( $user_id );

		if ( '' === $sid || ! isset( $sessions[ $sid ] ) ) {
			return;
		}

		unset( $sessions[ $sid ] );

		if ( empty( $sessions ) ) {
			delete_transient( self::key( $user_id ) );
			return;
		}

		set_transient( self::key( $user_id ), $sessions, self::SLOT_TTL + 60 );
	}

	/**
	 * Drop every slot for a user and revoke their tokens.
	 *
	 * Used by the admin "end all sessions" action.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function reset( $user_id ) {
		delete_transient( self::key( (int) $user_id ) );
		CES_Lms_Token::revoke_all( $user_id );
	}
}
