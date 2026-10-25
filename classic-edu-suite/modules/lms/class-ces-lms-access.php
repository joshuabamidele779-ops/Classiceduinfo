<?php
/**
 * Who may watch what, and what it costs them.
 *
 * The owner's rules, in priority order:
 *
 *   1. Guests get a login prompt. Never a token, never a URL.
 *   2. YITH subscribers/members skip coins entirely when
 *      `subscriber_unlimited` is on. That is the whole point of a
 *      subscription: it must never feel like it still charges.
 *   3. LMS managers and the lesson's own author can always watch, so staff
 *      can check an upload without buying it.
 *   4. The first `video_free_lessons` lessons of each course are free
 *      previews, plus any lesson explicitly flagged as a preview.
 *   5. Everyone else spends coins once per `video_unlock_hours` window. A
 *      re-watch inside that window is free; that is enforced in the database,
 *      not in the browser.
 *
 * Coin arithmetic is never done here. `CES_Wallet::debit()` owns it, and every
 * call is guarded with `class_exists()` so a missing wallet degrades to a
 * clear message rather than a fatal.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lesson access evaluation and coin unlocks.
 */
class CES_Lms_Access {

	/**
	 * Access granted by an active subscription or membership.
	 */
	const SOURCE_SUBSCRIPTION = 'subscription';

	/**
	 * Access granted because the lesson is a free preview.
	 */
	const SOURCE_FREE = 'free';

	/**
	 * Access bought with coins.
	 */
	const SOURCE_COIN = 'coin';

	/**
	 * Access granted because the viewer administers the site.
	 */
	const SOURCE_STAFF = 'staff';

	/**
	 * Wallet transaction type for a video unlock, per the shared contract.
	 */
	const TXN_TYPE = 'video_unlock';

	// ------------------------------------------------------------------
	// Gate.
	// ------------------------------------------------------------------

	/**
	 * Hard gate used by the stream endpoint and the REST layer.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $user_id   User id.
	 * @return true|WP_Error
	 */
	public static function can_watch( $lesson_id, $user_id ) {
		$lesson_id = (int) $lesson_id;
		$user_id   = (int) $user_id;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'ces_login_required', __( 'Please log in to watch this lesson.', 'classic-edu-suite' ), array( 'status' => 401 ) );
		}

		$lesson = get_post( $lesson_id );

		if ( ! $lesson || CES_Lms_Cpt::LESSON !== $lesson->post_type ) {
			return new WP_Error( 'ces_lesson_missing', __( 'That lesson does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		if ( self::is_staff( $lesson_id, $user_id ) ) {
			return true;
		}

		if ( 'publish' !== $lesson->post_status ) {
			return new WP_Error( 'ces_lesson_unpublished', __( 'That lesson is not published yet.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		if ( self::has_unlimited( $user_id ) ) {
			return true;
		}

		if ( self::is_free_preview( $lesson_id ) ) {
			return true;
		}

		if ( self::is_unlocked( $lesson_id, $user_id ) ) {
			return true;
		}

		return new WP_Error(
			'ces_lesson_locked',
			sprintf(
				/* translators: %s: coin cost. */
				__( 'Unlock this lesson for %s coins to watch it.', 'classic-edu-suite' ),
				CES_Helpers::format_coins( self::cost( $lesson_id ) )
			),
			array( 'status' => 402 )
		);
	}

	/**
	 * Full access state, for UI and the REST lesson detail route.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $user_id   User id, 0 for a guest.
	 * @return array
	 */
	public static function evaluate( $lesson_id, $user_id = 0 ) {
		$lesson_id = (int) $lesson_id;
		$user_id   = $user_id ? (int) $user_id : get_current_user_id();
		$cost      = self::cost( $lesson_id );

		$state = array(
			'lesson_id'      => $lesson_id,
			'allowed'        => false,
			'reason'         => 'need_login',
			'source'         => '',
			'cost'           => $cost,
			'unlocked_until' => '',
			'balance'        => null,
			'message'        => __( 'Please log in to watch this lesson.', 'classic-edu-suite' ),
			'action'         => 'login',
			'login_url'      => CES_Helpers::login_url( get_permalink( $lesson_id ) ),
			'referral_url'   => CES_Helpers::dashboard_url( 'referrals' ),
			'playable'       => CES_Lms_Meta::is_playable( $lesson_id ),
			'status'         => CES_Lms_Meta::status( $lesson_id ),
		);

		if ( $user_id <= 0 ) {
			return $state;
		}

		$state['balance'] = self::balance( $user_id );

		if ( self::is_staff( $lesson_id, $user_id ) ) {
			return array_merge(
				$state,
				array(
					'allowed' => true,
					'reason'  => 'staff',
					'source'  => self::SOURCE_STAFF,
					'message' => __( 'You can watch this lesson because you manage the video library.', 'classic-edu-suite' ),
					'action'  => 'watch',
				)
			);
		}

		if ( self::has_unlimited( $user_id ) ) {
			return array_merge(
				$state,
				array(
					'allowed' => true,
					'reason'  => 'subscriber',
					'source'  => self::SOURCE_SUBSCRIPTION,
					'cost'    => 0,
					'message' => __( 'Included with your subscription. No coins needed.', 'classic-edu-suite' ),
					'action'  => 'watch',
				)
			);
		}

		if ( self::is_free_preview( $lesson_id ) ) {
			return array_merge(
				$state,
				array(
					'allowed' => true,
					'reason'  => 'free_preview',
					'source'  => self::SOURCE_FREE,
					'cost'    => 0,
					'message' => __( 'Free preview lesson.', 'classic-edu-suite' ),
					'action'  => 'watch',
				)
			);
		}

		$row = self::view_row( $lesson_id, $user_id );

		if ( $row && self::row_is_active( $row ) ) {
			return array_merge(
				$state,
				array(
					'allowed'        => true,
					'reason'         => 'unlocked',
					'source'         => isset( $row['access_source'] ) ? (string) $row['access_source'] : self::SOURCE_COIN,
					'unlocked_until' => (string) $row['unlocked_until'],
					'message'        => sprintf(
						/* translators: %s: human-readable time remaining, e.g. "2 days". */
						__( 'Unlocked. Access ends in %s.', 'classic-edu-suite' ),
						human_time_diff( strtotime( (string) $row['unlocked_until'] ), current_time( 'timestamp' ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
					),
					'action'         => 'watch',
				)
			);
		}

		$affordable = null === $state['balance'] || $state['balance'] >= $cost;

		return array_merge(
			$state,
			array(
				'allowed' => false,
				'reason'  => $affordable ? 'need_unlock' : 'insufficient',
				'action'  => $affordable ? 'unlock' : 'earn',
				'message' => $affordable
					? sprintf(
						/* translators: 1: coin cost, 2: hours of access. */
						__( 'Spend %1$s coins to unlock this lesson for %2$d hours.', 'classic-edu-suite' ),
						CES_Helpers::format_coins( $cost ),
						self::unlock_hours()
					)
					: sprintf(
						/* translators: 1: coin cost, 2: current balance. */
						__( 'This lesson costs %1$s coins and you have %2$s. Invite a friend to earn more coins.', 'classic-edu-suite' ),
						CES_Helpers::format_coins( $cost ),
						CES_Helpers::format_coins( (int) $state['balance'] )
					),
			)
		);
	}

	// ------------------------------------------------------------------
	// Unlock.
	// ------------------------------------------------------------------

	/**
	 * Spend coins to unlock a lesson.
	 *
	 * Idempotent against double-clicks in two independent ways: a short
	 * transient mutex, and a deterministic wallet idempotency key bucketed to
	 * five minutes. Because `video_unlock_hours` is clamped to at least one
	 * hour, a legitimate re-unlock after expiry can never land in the same
	 * bucket as the original, so the key is safe to derive from the clock.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $user_id   User id.
	 * @return array|WP_Error Access state on success.
	 */
	public static function unlock( $lesson_id, $user_id ) {
		$lesson_id = (int) $lesson_id;
		$user_id   = (int) $user_id;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'ces_login_required', __( 'Please log in to unlock this lesson.', 'classic-edu-suite' ), array( 'status' => 401 ) );
		}

		$lesson = get_post( $lesson_id );

		if ( ! $lesson || CES_Lms_Cpt::LESSON !== $lesson->post_type || 'publish' !== $lesson->post_status ) {
			return new WP_Error( 'ces_lesson_missing', __( 'That lesson does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		// Anyone who already has access must never be charged.
		$already = self::can_watch( $lesson_id, $user_id );

		if ( ! is_wp_error( $already ) ) {
			return self::evaluate( $lesson_id, $user_id );
		}

		if ( ! CES_Helpers::rate_limit( 'lms_unlock', 20, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ces_rate_limited', __( 'Too many unlock attempts. Please wait a few minutes.', 'classic-edu-suite' ), array( 'status' => 429 ) );
		}

		$mutex = 'ces_lms_unlocking_' . $user_id . '_' . $lesson_id;

		if ( get_transient( $mutex ) ) {
			return new WP_Error( 'ces_unlock_in_progress', __( 'That unlock is already being processed. Give it a moment.', 'classic-edu-suite' ), array( 'status' => 409 ) );
		}

		set_transient( $mutex, 1, 15 );

		$cost = self::cost( $lesson_id );

		if ( $cost <= 0 ) {
			self::record( $lesson_id, $user_id, self::SOURCE_FREE, 0 );
			delete_transient( $mutex );

			return self::evaluate( $lesson_id, $user_id );
		}

		if ( ! class_exists( 'CES_Wallet' ) ) {
			delete_transient( $mutex );

			return new WP_Error(
				'ces_wallet_missing',
				__( 'The coin wallet is not available right now, so lessons cannot be unlocked. Please try again later.', 'classic-edu-suite' ),
				array( 'status' => 503 )
			);
		}

		$result = CES_Wallet::debit(
			$user_id,
			$cost,
			self::TXN_TYPE,
			array(
				'ref_type'        => 'lesson',
				'ref_id'          => $lesson_id,
				'note'            => sprintf(
					/* translators: %s: lesson title. */
					__( 'Unlocked lesson: %s', 'classic-edu-suite' ),
					get_the_title( $lesson_id )
				),
				'idempotency_key' => self::idempotency_key( $lesson_id, $user_id ),
			)
		);

		delete_transient( $mutex );

		if ( is_wp_error( $result ) ) {
			if ( 'ces_insufficient_funds' === $result->get_error_code() ) {
				return new WP_Error(
					'ces_insufficient_funds',
					sprintf(
						/* translators: 1: coin cost, 2: current balance. */
						__( 'You need %1$s coins to unlock this lesson but you have %2$s. Earn coins by inviting friends — every friend who signs up and takes a quiz pays you.', 'classic-edu-suite' ),
						CES_Helpers::format_coins( $cost ),
						CES_Helpers::format_coins( (int) self::balance( $user_id ) )
					),
					array(
						'status'       => 402,
						'cost'         => $cost,
						'balance'      => self::balance( $user_id ),
						'referral_url' => CES_Helpers::dashboard_url( 'referrals' ),
					)
				);
			}

			return $result;
		}

		self::record( $lesson_id, $user_id, self::SOURCE_COIN, $cost );

		/**
		 * Fires after a lesson is unlocked with coins.
		 *
		 * @param int $user_id   Student.
		 * @param int $lesson_id Lesson.
		 * @param int $cost      Coins spent.
		 */
		do_action( 'ces_video_unlocked', $user_id, $lesson_id, $cost );

		return self::evaluate( $lesson_id, $user_id );
	}

	/**
	 * Deterministic wallet idempotency key for an unlock.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $user_id   User id.
	 * @return string
	 */
	private static function idempotency_key( $lesson_id, $user_id ) {
		$bucket = (int) floor( time() / ( 5 * MINUTE_IN_SECONDS ) );

		return 'lms_unlock:' . (int) $user_id . ':' . (int) $lesson_id . ':' . $bucket;
	}

	// ------------------------------------------------------------------
	// Rules.
	// ------------------------------------------------------------------

	/**
	 * Whether unlimited subscriber access applies to this user.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function has_unlimited( $user_id ) {
		if ( ! CES_Helpers::enabled( 'subscriber_unlimited' ) ) {
			return false;
		}

		return CES_Helpers::is_subscriber( (int) $user_id );
	}

	/**
	 * Whether the viewer administers the library or wrote the lesson.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $user_id   User id.
	 * @return bool
	 */
	public static function is_staff( $lesson_id, $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'ces_manage_lms' ) || user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return (int) get_post_field( 'post_author', (int) $lesson_id ) === $user_id;
	}

	/**
	 * Whether a lesson is a free preview.
	 *
	 * Either the lesson carries the explicit flag, or it sits within the
	 * first `video_free_lessons` positions of its course.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return bool
	 */
	public static function is_free_preview( $lesson_id ) {
		$lesson_id = (int) $lesson_id;

		if ( '1' === (string) get_post_meta( $lesson_id, CES_Lms_Meta::FREE_PREVIEW, true ) ) {
			return true;
		}

		if ( self::cost( $lesson_id ) <= 0 ) {
			return true;
		}

		$free = CES_Helpers::setting_int( 'video_free_lessons', 1 );

		if ( $free <= 0 ) {
			return false;
		}

		$course_id = CES_Lms_Cpt::lesson_course_id( $lesson_id );

		if ( $course_id <= 0 ) {
			return false;
		}

		$position = CES_Lms_Cpt::lesson_position( $lesson_id, $course_id );

		return $position > -1 && $position < $free;
	}

	/**
	 * Coin cost for a lesson, honouring the per-lesson override.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return int
	 */
	public static function cost( $lesson_id ) {
		$override = get_post_meta( (int) $lesson_id, CES_Lms_Meta::COIN_COST, true );

		if ( '' !== $override && null !== $override ) {
			return max( 0, (int) $override );
		}

		return max( 0, CES_Helpers::setting_int( 'video_coin_cost', 10 ) );
	}

	/**
	 * How long an unlock lasts, clamped to at least one hour.
	 *
	 * @return int Hours.
	 */
	public static function unlock_hours() {
		return max( 1, CES_Helpers::setting_int( 'video_unlock_hours', 72 ) );
	}

	/**
	 * Current coin balance, or null when the wallet is unavailable.
	 *
	 * @param int $user_id User id.
	 * @return int|null
	 */
	public static function balance( $user_id ) {
		if ( ! class_exists( 'CES_Wallet' ) ) {
			return null;
		}

		return (int) CES_Wallet::balance( (int) $user_id );
	}

	// ------------------------------------------------------------------
	// video_views table access.
	// ------------------------------------------------------------------

	/**
	 * Read a student's row for one lesson.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $user_id   User id.
	 * @return array|null
	 */
	public static function view_row( $lesson_id, $user_id ) {
		global $wpdb;

		$table = CES_Helpers::table( 'video_views' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND lesson_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $lesson_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether a row's unlock window is still open.
	 *
	 * @param array $row Row from video_views.
	 * @return bool
	 */
	private static function row_is_active( array $row ) {
		if ( empty( $row['unlocked_until'] ) || '0000-00-00 00:00:00' === $row['unlocked_until'] ) {
			return false;
		}

		return strtotime( (string) $row['unlocked_until'] ) > strtotime( CES_Helpers::now() );
	}

	/**
	 * Whether a paid unlock is currently active.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $user_id   User id.
	 * @return bool
	 */
	public static function is_unlocked( $lesson_id, $user_id ) {
		$row = self::view_row( $lesson_id, $user_id );

		return $row ? self::row_is_active( $row ) : false;
	}

	/**
	 * Create or extend the access record for a lesson.
	 *
	 * Written with INSERT ... ON DUPLICATE KEY UPDATE against the unique
	 * `(user_id, lesson_id)` index, so two concurrent unlocks cannot create
	 * two rows and `coins_spent` accumulates rather than being overwritten.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param int    $user_id   User id.
	 * @param string $source    One of the SOURCE_* constants.
	 * @param int    $coins     Coins spent on this unlock.
	 * @return bool
	 */
	public static function record( $lesson_id, $user_id, $source, $coins = 0 ) {
		global $wpdb;

		$lesson_id = (int) $lesson_id;
		$user_id   = (int) $user_id;
		$course_id = CES_Lms_Cpt::lesson_course_id( $lesson_id );
		$now       = CES_Helpers::now();
		$until     = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + ( self::unlock_hours() * HOUR_IN_SECONDS ) );
		$table     = CES_Helpers::table( 'video_views' );

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT INTO {$table}
					( user_id, lesson_id, course_id, access_source, coins_spent, unlocked_at, unlocked_until, last_seen_at )
				VALUES ( %d, %d, %d, %s, %d, %s, %s, %s )
				ON DUPLICATE KEY UPDATE
					course_id = VALUES( course_id ),
					access_source = VALUES( access_source ),
					coins_spent = coins_spent + VALUES( coins_spent ),
					unlocked_at = VALUES( unlocked_at ),
					unlocked_until = VALUES( unlocked_until ),
					last_seen_at = VALUES( last_seen_at )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$lesson_id,
				$course_id,
				(string) $source,
				max( 0, (int) $coins ),
				$now,
				$until,
				$now
			)
		);

		return false !== $result;
	}
}
