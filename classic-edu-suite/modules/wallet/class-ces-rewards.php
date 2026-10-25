<?php
/**
 * Earning paths other than referrals: signup bonus, daily login, streaks and
 * quiz passes.
 *
 * Every award goes through `CES_Wallet::credit()` with a deterministic
 * idempotency key, so replaying a request, a double-fired hook or a cron
 * overlap can never mint extra coins.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coin earning rules.
 */
class CES_Rewards {

	/**
	 * User meta key holding the last date daily-login coins were paid (Y-m-d).
	 */
	const META_LAST_LOGIN_DAY = 'ces_last_daily_login';

	/**
	 * User meta key holding the current consecutive-day streak.
	 */
	const META_STREAK = 'ces_login_streak';

	/**
	 * User meta key holding the best streak ever reached.
	 */
	const META_STREAK_BEST = 'ces_login_streak_best';

	/**
	 * Days needed for the streak bonus.
	 */
	const STREAK_TARGET = 7;

	/**
	 * Guard so the daily check runs at most once per request.
	 *
	 * @var bool
	 */
	private static $daily_checked = false;

	/**
	 * Pay the signup bonus for a brand-new account.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public static function on_user_register( $user_id ) {
		$user_id = (int) $user_id;
		$amount  = CES_Helpers::setting_int( 'coins_signup_bonus', 20 );

		if ( $user_id <= 0 || $amount <= 0 || ! class_exists( 'CES_Wallet' ) ) {
			return;
		}

		CES_Wallet::credit(
			$user_id,
			$amount,
			'signup_bonus',
			array(
				'ref_type'        => 'user',
				'ref_id'          => $user_id,
				'note'            => __( 'Welcome bonus', 'classic-edu-suite' ),
				'idempotency_key' => 'signup_bonus:' . $user_id,
			)
		);
	}

	/**
	 * Hook target for `wp_login`.
	 *
	 * @param string       $user_login Username.
	 * @param WP_User|null $user       User object.
	 * @return void
	 */
	public static function on_login( $user_login, $user = null ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_user_by( 'login', $user_login );
		}

		if ( $user instanceof WP_User ) {
			self::award_daily_login( (int) $user->ID );
		}
	}

	/**
	 * Hook target for `init`: catches students returning on a remembered
	 * cookie, where `wp_login` never fires.
	 *
	 * @return void
	 */
	public static function maybe_award_daily_login() {
		if ( self::$daily_checked || wp_doing_cron() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			self::award_daily_login( $user_id );
		}
	}

	/**
	 * Award daily-login coins once per calendar day and maintain the streak.
	 *
	 * @param int $user_id User ID.
	 * @return bool True when coins were paid on this call.
	 */
	public static function award_daily_login( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 || ! class_exists( 'CES_Wallet' ) ) {
			return false;
		}

		self::$daily_checked = true;

		$today = current_time( 'Y-m-d' );
		$last  = (string) get_user_meta( $user_id, self::META_LAST_LOGIN_DAY, true );

		if ( $today === $last ) {
			return false;
		}

		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
		$streak    = (int) get_user_meta( $user_id, self::META_STREAK, true );

		if ( $last === $yesterday && $streak > 0 ) {
			$streak++;
		} else {
			// First visit, or a day was missed: the streak restarts at 1.
			$streak = 1;
		}

		// Record the day before paying, so a failed credit cannot be retried
		// into a double payment later in the same day.
		update_user_meta( $user_id, self::META_LAST_LOGIN_DAY, $today );
		update_user_meta( $user_id, self::META_STREAK, $streak );

		if ( $streak > (int) get_user_meta( $user_id, self::META_STREAK_BEST, true ) ) {
			update_user_meta( $user_id, self::META_STREAK_BEST, $streak );
		}

		$amount = CES_Helpers::setting_int( 'coins_daily_login', 2 );

		if ( $amount > 0 ) {
			CES_Wallet::credit(
				$user_id,
				$amount,
				'daily_login',
				array(
					'ref_type'        => 'day',
					'ref_id'          => 0,
					'note'            => sprintf(
						/* translators: %d: consecutive day number. */
						__( 'Daily login (day %d)', 'classic-edu-suite' ),
						$streak
					),
					'idempotency_key' => 'daily_login:' . $user_id . ':' . $today,
				)
			);
		}

		$bonus = CES_Helpers::setting_int( 'coins_streak_bonus', 10 );

		if ( $bonus > 0 && 0 === $streak % self::STREAK_TARGET ) {
			CES_Wallet::credit(
				$user_id,
				$bonus,
				'streak_bonus',
				array(
					'ref_type'        => 'streak',
					'ref_id'          => $streak,
					'note'            => sprintf(
						/* translators: %d: streak length in days. */
						__( '%d-day login streak bonus', 'classic-edu-suite' ),
						$streak
					),
					'idempotency_key' => 'streak_bonus:' . $user_id . ':' . $today,
				)
			);

			/**
			 * Fires when a student completes a login streak.
			 *
			 * @param int $user_id User ID.
			 * @param int $streak  Streak length in days.
			 */
			do_action( 'ces_streak_completed', $user_id, $streak );
		}

		return true;
	}

	/**
	 * Current streak information for display.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function streak( $user_id ) {
		$user_id = (int) $user_id;
		$streak  = max( 0, (int) get_user_meta( $user_id, self::META_STREAK, true ) );
		$last    = (string) get_user_meta( $user_id, self::META_LAST_LOGIN_DAY, true );
		$today   = current_time( 'Y-m-d' );

		// A streak that was not continued yesterday or today is already dead;
		// report it as zero rather than showing a stale number.
		if ( '' === $last ) {
			$streak = 0;
		} elseif ( $last !== $today && $last !== gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) ) {
			$streak = 0;
		}

		// Days left until the next bonus. A streak of 0 or an exact multiple of
		// the target both leave a full target to climb.
		$remaining = self::STREAK_TARGET - ( $streak % self::STREAK_TARGET );

		return array(
			'days'      => $streak,
			'best'      => max( 0, (int) get_user_meta( $user_id, self::META_STREAK_BEST, true ) ),
			'target'    => self::STREAK_TARGET,
			'remaining' => $remaining,
			'claimed'   => ( $last === $today ),
		);
	}

	/**
	 * Pay quiz-pass coins, subject to the daily cap.
	 *
	 * Hooked to `ces_quiz_completed`, fired by the CBT module.
	 *
	 * @param int   $user_id    User ID.
	 * @param int   $attempt_id Attempt ID.
	 * @param float $score      Score percentage.
	 * @param bool  $passed     Whether the attempt passed.
	 * @return void
	 */
	public static function on_quiz_completed( $user_id, $attempt_id = 0, $score = 0, $passed = false ) {
		$user_id    = (int) $user_id;
		$attempt_id = (int) $attempt_id;

		if ( $user_id <= 0 || ! $passed || ! class_exists( 'CES_Wallet' ) ) {
			return;
		}

		$amount = CES_Helpers::setting_int( 'coins_per_quiz_pass', 5 );

		if ( $amount <= 0 ) {
			return;
		}

		$cap = CES_Helpers::setting_int( 'coins_quiz_daily_cap', 20 );

		if ( $cap > 0 ) {
			$already = CES_Wallet::sum_for_day( $user_id, 'quiz_pass' );
			$left    = $cap - max( 0, $already );

			if ( $left <= 0 ) {
				return;
			}

			$amount = min( $amount, $left );
		}

		// Keyed on the attempt so re-submitting the same attempt pays once.
		// Attempts with no ID fall back to a per-day key.
		$key = $attempt_id > 0
			? 'quiz_pass:' . $attempt_id
			: 'quiz_pass:' . $user_id . ':' . current_time( 'Y-m-d-H-i-s' );

		CES_Wallet::credit(
			$user_id,
			$amount,
			'quiz_pass',
			array(
				'ref_type'        => 'attempt',
				'ref_id'          => $attempt_id,
				'note'            => sprintf(
					/* translators: %s: score percentage. */
					__( 'Passed a quiz (%s%%)', 'classic-edu-suite' ),
					number_format_i18n( (float) $score, 1 )
				),
				'idempotency_key' => $key,
			)
		);
	}

	/**
	 * The "how to earn coins" list shown on the dashboard.
	 *
	 * @return array List of `array( 'label' => '', 'coins' => int )`.
	 */
	public static function earning_methods() {
		$rows = array(
			array(
				'label' => __( 'Invite a friend who verifies and takes one quiz', 'classic-edu-suite' ),
				'coins' => CES_Helpers::setting_int( 'coins_per_referral', 25 ),
			),
			array(
				'label' => sprintf(
					/* translators: %d: number of referrals needed. */
					__( 'Milestone bonus every %d qualified invites', 'classic-edu-suite' ),
					CES_Helpers::setting_int( 'coins_milestone_every', 5 )
				),
				'coins' => CES_Helpers::setting_int( 'coins_referral_milestone', 100 ),
			),
			array(
				'label' => __( 'Log in each day', 'classic-edu-suite' ),
				'coins' => CES_Helpers::setting_int( 'coins_daily_login', 2 ),
			),
			array(
				'label' => sprintf(
					/* translators: %d: streak length in days. */
					__( 'Keep a %d-day login streak', 'classic-edu-suite' ),
					self::STREAK_TARGET
				),
				'coins' => CES_Helpers::setting_int( 'coins_streak_bonus', 10 ),
			),
			array(
				'label' => sprintf(
					/* translators: %d: daily cap in coins. */
					__( 'Pass a CBT quiz (up to %d coins a day)', 'classic-edu-suite' ),
					CES_Helpers::setting_int( 'coins_quiz_daily_cap', 20 )
				),
				'coins' => CES_Helpers::setting_int( 'coins_per_quiz_pass', 5 ),
			),
		);

		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return (int) $row['coins'] > 0;
				}
			)
		);

		/**
		 * Filter the list of ways to earn coins.
		 *
		 * @param array $rows Earning methods.
		 */
		return (array) apply_filters( 'ces_coin_earning_methods', $rows );
	}
}
