<?php
/**
 * Referral programme: attribution, qualification and anti-fraud.
 *
 * A referral is created `pending` at registration and only becomes
 * `qualified` once the referred student has a verified account AND has
 * finished at least one CBT attempt. The referrer is paid at that moment,
 * never at signup, which removes the incentive to farm dead accounts.
 *
 * Attribution sources, in priority order:
 *
 *   1. A referral code posted with the registration form (Profile Builder Pro
 *      owns `/login/`, so we read a plain POST field rather than touching any
 *      Profile Builder internals).
 *   2. The `ces_ref` cookie, set for 30 days whenever a visitor lands on any
 *      page with `?ref=CODE`.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Referral codes, attribution, qualification and fraud screening.
 */
class CES_Referral {

	/**
	 * User meta key holding the referral code.
	 */
	const META_CODE = 'ces_referral_code';

	/**
	 * User meta key holding the referring user ID.
	 */
	const META_REFERRED_BY = 'ces_referred_by';

	/**
	 * User meta key holding the last IP seen for an account.
	 */
	const META_LAST_IP = 'ces_last_ip';

	/**
	 * User meta key holding the IP recorded at registration.
	 */
	const META_SIGNUP_IP = 'ces_signup_ip';

	/**
	 * User meta key counting completed CBT attempts.
	 */
	const META_ATTEMPTS = 'ces_completed_attempts';

	/**
	 * Cookie holding a captured referral code.
	 */
	const COOKIE_REF = 'ces_ref';

	/**
	 * Cookie holding the pseudonymous device identifier.
	 */
	const COOKIE_DEVICE = 'ces_dev';

	/**
	 * Referral cookie lifetime in seconds (30 days).
	 */
	const REF_TTL = 2592000;

	/**
	 * Characters used in referral codes. No 0, O, 1, I or L.
	 */
	const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

	/**
	 * Reasons that only delay a payout instead of killing it.
	 *
	 * @var string[]
	 */
	private static $deferrable = array( 'daily_cap' );

	// -----------------------------------------------------------------
	// Codes and links.
	// -----------------------------------------------------------------

	/**
	 * The user's referral code, generated lazily on first request.
	 *
	 * @param int $user_id User ID.
	 * @return string Code, or '' for an invalid user.
	 */
	public static function get_code( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return '';
		}

		$code = (string) get_user_meta( $user_id, self::META_CODE, true );

		if ( '' !== $code ) {
			return $code;
		}

		if ( ! get_userdata( $user_id ) ) {
			return '';
		}

		$code = self::generate_code( $user_id );

		update_user_meta( $user_id, self::META_CODE, $code );

		return $code;
	}

	/**
	 * Build a referral code that no other account is using.
	 *
	 * @param int $user_id User the code belongs to, used for the fallback.
	 * @return string
	 */
	public static function generate_code( $user_id ) {
		/**
		 * Filter the referral code length.
		 *
		 * @param int $length Number of characters.
		 */
		$length   = max( 4, min( 12, (int) apply_filters( 'ces_referral_code_length', 7 ) ) );
		$alphabet = self::ALPHABET;
		$max      = strlen( $alphabet ) - 1;

		for ( $attempt = 0; $attempt < 12; $attempt++ ) {
			$code = '';

			for ( $i = 0; $i < $length; $i++ ) {
				$code .= $alphabet[ wp_rand( 0, $max ) ];
			}

			if ( ! self::resolve_code( $code ) ) {
				return $code;
			}
		}

		// Statistically unreachable; guarantee uniqueness with the user ID.
		return 'U' . strtoupper( base_convert( (string) (int) $user_id, 10, 32 ) ) . substr( (string) time(), -4 );
	}

	/**
	 * Find the user who owns a referral code.
	 *
	 * @param string $code Referral code.
	 * @return int User ID, or 0 when unknown.
	 */
	public static function resolve_code( $code ) {
		$code = self::sanitize_code( $code );

		if ( '' === $code ) {
			return 0;
		}

		$users = get_users(
			array(
				'meta_key'    => self::META_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);

		return empty( $users ) ? 0 : (int) $users[0];
	}

	/**
	 * Normalise a referral code from untrusted input.
	 *
	 * @param string $code Raw code.
	 * @return string Uppercase alphanumeric code, max 40 chars.
	 */
	public static function sanitize_code( $code ) {
		$code = strtoupper( sanitize_text_field( (string) $code ) );
		$code = preg_replace( '/[^A-Z0-9]/', '', $code );

		return substr( (string) $code, 0, 40 );
	}

	/**
	 * The shareable referral link for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function link( $user_id ) {
		$code = self::get_code( $user_id );

		if ( '' === $code ) {
			return '';
		}

		$link = add_query_arg( 'ref', $code, home_url( '/' ) );

		/**
		 * Filter the referral link.
		 *
		 * @param string $link    Referral URL.
		 * @param int    $user_id Referrer ID.
		 * @param string $code    Referral code.
		 */
		return (string) apply_filters( 'ces_referral_link', $link, (int) $user_id, $code );
	}

	/**
	 * Pre-filled WhatsApp share URL. Most of our students live on WhatsApp.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function whatsapp_url( $user_id ) {
		$link = self::link( $user_id );

		if ( '' === $link ) {
			return '';
		}

		$message = sprintf(
			/* translators: 1: site name, 2: referral link. */
			__( "I'm using %1\$s to practise CBT exams and watch syllabus videos. Join with my link and we both earn coins: %2\$s", 'classic-edu-suite' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$link
		);

		/**
		 * Filter the WhatsApp invite message.
		 *
		 * @param string $message Share text.
		 * @param int    $user_id Referrer ID.
		 */
		$message = (string) apply_filters( 'ces_referral_share_message', $message, (int) $user_id );

		return 'https://wa.me/?text=' . rawurlencode( $message );
	}

	// -----------------------------------------------------------------
	// Capture and attribution.
	// -----------------------------------------------------------------

	/**
	 * Capture `?ref=CODE` into a 30-day cookie on any page load.
	 *
	 * First-touch attribution: an existing cookie is not overwritten, which
	 * stops a second referrer from stealing an invite that is already in
	 * flight.
	 *
	 * @return void
	 */
	public static function capture() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['ref'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = self::sanitize_code( wp_unslash( $_GET['ref'] ) );

		if ( '' === $code || headers_sent() ) {
			return;
		}

		if ( ! empty( $_COOKIE[ self::COOKIE_REF ] ) ) {
			// Already attributed; still make sure a device id exists.
			self::ensure_device_cookie();
			return;
		}

		setcookie(
			self::COOKIE_REF,
			$code,
			time() + self::REF_TTL,
			defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
			defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			is_ssl(),
			true
		);

		$_COOKIE[ self::COOKIE_REF ] = $code;

		self::ensure_device_cookie();
	}

	/**
	 * Make sure a long-lived pseudonymous device identifier exists.
	 *
	 * Used only to spot several signups from one browser. It is a random
	 * value, not a fingerprint, and never leaves this install.
	 *
	 * @return string The device identifier, or '' when it could not be set.
	 */
	public static function ensure_device_cookie() {
		if ( ! empty( $_COOKIE[ self::COOKIE_DEVICE ] ) ) {
			$existing = preg_replace( '/[^a-f0-9]/', '', (string) $_COOKIE[ self::COOKIE_DEVICE ] );

			if ( 32 === strlen( (string) $existing ) ) {
				return (string) $existing;
			}
		}

		if ( headers_sent() ) {
			return '';
		}

		$device = md5( wp_generate_password( 32, true, true ) . microtime( true ) );

		setcookie(
			self::COOKIE_DEVICE,
			$device,
			time() + ( 365 * DAY_IN_SECONDS ),
			defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
			defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			is_ssl(),
			true
		);

		$_COOKIE[ self::COOKIE_DEVICE ] = $device;

		return $device;
	}

	/**
	 * Hash of the current device signals, safe to store.
	 *
	 * @return string 64-character hex digest, or '' when no device id exists.
	 */
	public static function device_hash() {
		$device = isset( $_COOKIE[ self::COOKIE_DEVICE ] )
			? preg_replace( '/[^a-f0-9]/', '', (string) $_COOKIE[ self::COOKIE_DEVICE ] )
			: '';

		if ( 32 !== strlen( (string) $device ) ) {
			return '';
		}

		$lang = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ), 0, 60 )
			: '';

		return hash_hmac( 'sha256', $device . '|' . CES_Helpers::user_agent() . '|' . $lang, CES_Helpers::stream_secret() );
	}

	/**
	 * Render the optional referral-code field on the stock WordPress
	 * registration form.
	 *
	 * Profile Builder owns the real form, so sites using it should add a
	 * custom field whose name is `ces_referral_code` instead.
	 *
	 * @return void
	 */
	public static function registration_field() {
		$prefill = isset( $_COOKIE[ self::COOKIE_REF ] ) ? self::sanitize_code( $_COOKIE[ self::COOKIE_REF ] ) : '';

		echo '<p><label for="ces_referral_code">' . esc_html__( 'Referral code (optional)', 'classic-edu-suite' ) . '<br />';
		echo '<input type="text" name="ces_referral_code" id="ces_referral_code" class="input" value="' . esc_attr( $prefill ) . '" size="20" autocapitalize="characters" /></label></p>';
	}

	/**
	 * Read a referral code out of the registration request.
	 *
	 * @return string
	 */
	private static function posted_code() {
		$keys = array( 'ces_referral_code', 'referral_code', 'ref_code', 'ref' );

		/**
		 * Filter the request keys scanned for a referral code at signup.
		 *
		 * Lets a site point us at whatever a Profile Builder custom field is
		 * actually named, without us depending on Profile Builder internals.
		 *
		 * @param string[] $keys Request keys.
		 */
		$keys = (array) apply_filters( 'ces_referral_request_keys', $keys );

		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! empty( $_POST[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$code = self::sanitize_code( wp_unslash( $_POST[ $key ] ) );

				if ( '' !== $code ) {
					return $code;
				}
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$code = self::sanitize_code( wp_unslash( $_GET[ $key ] ) );

				if ( '' !== $code ) {
					return $code;
				}
			}
		}

		if ( ! empty( $_COOKIE[ self::COOKIE_REF ] ) ) {
			return self::sanitize_code( $_COOKIE[ self::COOKIE_REF ] );
		}

		return '';
	}

	/**
	 * Record the referral when a new account is created.
	 *
	 * Runs on `user_register`, so it covers Profile Builder, WooCommerce and
	 * the stock WordPress form alike.
	 *
	 * @param int $user_id Newly created user ID.
	 * @return void
	 */
	public static function on_user_register( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		$ip = CES_Helpers::ip();

		update_user_meta( $user_id, self::META_SIGNUP_IP, $ip );
		update_user_meta( $user_id, self::META_LAST_IP, $ip );

		$code = self::posted_code();

		if ( '' === $code ) {
			return;
		}

		$referrer_id = self::resolve_code( $code );

		if ( $referrer_id <= 0 ) {
			return;
		}

		$table = CES_Helpers::table( 'referrals' );

		// `referred_id` is unique, so a double-fired hook cannot duplicate.
		$suppress = $wpdb->suppress_errors( true );

		$wpdb->insert(
			$table,
			array(
				'referrer_id'   => $referrer_id,
				'referred_id'   => $user_id,
				'code'          => $code,
				'status'        => 'pending',
				'reward_paid'   => 0,
				'signup_ip'     => substr( $ip, 0, 100 ),
				'signup_ua'     => CES_Helpers::user_agent(),
				'device_hash'   => self::device_hash(),
				'reject_reason' => '',
				'qualified_at'  => null,
				'created_at'    => CES_Helpers::now(),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$wpdb->suppress_errors( $suppress );

		update_user_meta( $user_id, self::META_REFERRED_BY, $referrer_id );

		// Clear the cookie so the next signup on this browser is not
		// attributed to the same referrer for free.
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE_REF,
				'',
				time() - 3600,
				defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				is_ssl(),
				true
			);
			unset( $_COOKIE[ self::COOKIE_REF ] );
		}

		// Screen immediately: obvious abuse is rejected before it can ripen.
		// Only ever touch a row that is still pending and unpaid, so a
		// re-fired `user_register` cannot reject an already-paid referral.
		$row = self::get_row_for_referred( $user_id );

		if ( $row && 'pending' === $row->status && ! (int) $row->reward_paid ) {
			$reason = self::fraud_check( $row );

			if ( '' !== $reason && ! in_array( $reason, self::$deferrable, true ) ) {
				self::reject( (int) $row->id, $reason );
			}
		}

		/**
		 * Fires once a referral has been recorded as pending.
		 *
		 * @param int $referrer_id Referrer user ID.
		 * @param int $referred_id Referred user ID.
		 */
		do_action( 'ces_referral_recorded', $referrer_id, $user_id );
	}

	// -----------------------------------------------------------------
	// Qualification.
	// -----------------------------------------------------------------

	/**
	 * Remember progress and re-check qualification after a quiz.
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
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		$count = (int) get_user_meta( $user_id, self::META_ATTEMPTS, true );
		update_user_meta( $user_id, self::META_ATTEMPTS, $count + 1 );

		self::maybe_qualify( $user_id );
	}

	/**
	 * Keep the last-seen IP fresh and retry qualification on login.
	 *
	 * A Profile Builder email confirmation often lands after the first quiz,
	 * so logging in is a good moment to look again.
	 *
	 * @param string       $user_login Username.
	 * @param WP_User|null $user       User object.
	 * @return void
	 */
	public static function on_login( $user_login, $user = null ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_user_by( 'login', $user_login );
		}

		if ( ! $user instanceof WP_User ) {
			return;
		}

		update_user_meta( $user->ID, self::META_LAST_IP, CES_Helpers::ip() );

		self::maybe_qualify( (int) $user->ID );
	}

	/**
	 * Qualify a referral if the referred user now meets every condition.
	 *
	 * @param int $referred_id The referred (invited) user ID.
	 * @return bool True when a payout happened on this call.
	 */
	public static function maybe_qualify( $referred_id ) {
		$referred_id = (int) $referred_id;

		if ( $referred_id <= 0 ) {
			return false;
		}

		$row = self::get_row_for_referred( $referred_id );

		if ( ! $row || 'pending' !== $row->status || (int) $row->reward_paid ) {
			return false;
		}

		// Condition (a): a verified / confirmed account.
		if ( ! self::is_account_verified( $referred_id ) ) {
			return false;
		}

		// Condition (b): at least one completed CBT attempt.
		if ( ! self::has_completed_attempt( $referred_id ) ) {
			return false;
		}

		$reason = self::fraud_check( $row );

		if ( '' !== $reason ) {
			if ( in_array( $reason, self::$deferrable, true ) ) {
				// Not abuse, just throttled. Stay pending and note why; the
				// daily cron sweep will try again tomorrow.
				self::set_reason( (int) $row->id, $reason );
				return false;
			}

			self::reject( (int) $row->id, $reason );
			return false;
		}

		return self::pay( $row );
	}

	/**
	 * Pay the referrer and mark the referral qualified.
	 *
	 * @param object $row Referral row.
	 * @return bool
	 */
	private static function pay( $row ) {
		global $wpdb;

		$referrer_id = (int) $row->referrer_id;
		$referred_id = (int) $row->referred_id;

		if ( ! get_userdata( $referrer_id ) ) {
			self::reject( (int) $row->id, 'referrer_missing' );
			return false;
		}

		$amount = CES_Helpers::setting_int( 'coins_per_referral', 25 );

		if ( $amount > 0 && class_exists( 'CES_Wallet' ) ) {
			$result = CES_Wallet::credit(
				$referrer_id,
				$amount,
				'referral_reward',
				array(
					'ref_type'        => 'referral',
					'ref_id'          => $referred_id,
					'note'            => sprintf(
						/* translators: %s: referred user's display name. */
						__( 'Referral reward for %s', 'classic-edu-suite' ),
						self::display_name( $referred_id )
					),
					'idempotency_key' => 'referral_reward:' . $referred_id,
				)
			);

			if ( is_wp_error( $result ) ) {
				CES_Helpers::log(
					'Referral payout failed',
					array(
						'referrer_id' => $referrer_id,
						'referred_id' => $referred_id,
						'error'       => $result->get_error_code(),
					)
				);

				return false;
			}
		}

		$table = CES_Helpers::table( 'referrals' );

		$wpdb->update(
			$table,
			array(
				'status'        => 'qualified',
				'reward_paid'   => 1,
				'reject_reason' => '',
				'qualified_at'  => CES_Helpers::now(),
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		/**
		 * Fires when a referral has been qualified and paid.
		 *
		 * @param int $referrer_id Referrer user ID.
		 * @param int $referred_id Referred user ID.
		 */
		do_action( 'ces_referral_qualified', $referrer_id, $referred_id );

		self::maybe_pay_milestone( $referrer_id );

		return true;
	}

	/**
	 * Pay the milestone bonus when the referrer hits a multiple of N.
	 *
	 * @param int $referrer_id Referrer user ID.
	 * @return void
	 */
	public static function maybe_pay_milestone( $referrer_id ) {
		$referrer_id = (int) $referrer_id;
		$every       = CES_Helpers::setting_int( 'coins_milestone_every', 5 );
		$bonus       = CES_Helpers::setting_int( 'coins_referral_milestone', 100 );

		if ( $referrer_id <= 0 || $every <= 0 || $bonus <= 0 || ! class_exists( 'CES_Wallet' ) ) {
			return;
		}

		$qualified = self::count_by_status( $referrer_id, 'qualified' );

		if ( $qualified <= 0 || 0 !== $qualified % $every ) {
			return;
		}

		CES_Wallet::credit(
			$referrer_id,
			$bonus,
			'referral_milestone',
			array(
				'ref_type'        => 'referral_milestone',
				'ref_id'          => $qualified,
				'note'            => sprintf(
					/* translators: %d: number of qualified referrals. */
					__( 'Milestone bonus for %d qualified referrals', 'classic-edu-suite' ),
					$qualified
				),
				'idempotency_key' => 'referral_milestone:' . $referrer_id . ':' . $qualified,
			)
		);
	}

	/**
	 * Whether the account is verified / confirmed.
	 *
	 * Written defensively: we look for the signals Profile Builder and
	 * similar plugins leave in user meta and treat "no signal at all" as
	 * verified, so a site without email confirmation still pays referrals.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_account_verified( $user_id ) {
		$user_id = (int) $user_id;
		$user    = $user_id ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			return false;
		}

		$verified = true;

		// Manual override, also set by the admin screen.
		if ( '1' === (string) get_user_meta( $user_id, 'ces_email_verified', true ) ) {
			return (bool) apply_filters( 'ces_referral_account_verified', true, $user_id );
		}

		// Profile Builder admin approval.
		$approved = get_user_meta( $user_id, 'wppb_approved', true );

		if ( '' !== $approved && null !== $approved ) {
			$approved = strtolower( (string) $approved );

			if ( ! in_array( $approved, array( '1', 'approved', 'yes', 'true' ), true ) ) {
				$verified = false;
			}
		}

		// Profile Builder email confirmation: a confirmation key that is still
		// present and not already marked confirmed means the link is unclicked.
		foreach ( array( 'wppb_ec_password', 'wppb_email_confirmation_key' ) as $meta_key ) {
			$pending = get_user_meta( $user_id, $meta_key, true );

			if ( ! empty( $pending ) && 'true' !== strtolower( (string) $pending ) ) {
				$verified = false;
			}
		}

		/**
		 * Filter whether a referred account counts as verified.
		 *
		 * @param bool $verified Result so far.
		 * @param int  $user_id  User being checked.
		 */
		return (bool) apply_filters( 'ces_referral_account_verified', $verified, $user_id );
	}

	/**
	 * Whether the user has finished at least one CBT attempt.
	 *
	 * The meta counter is maintained from `ces_quiz_completed`; the table
	 * query is a fallback for accounts that pre-date the wallet module.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has_completed_attempt( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( (int) get_user_meta( $user_id, self::META_ATTEMPTS, true ) > 0 ) {
			return true;
		}

		$table    = CES_Helpers::table( 'attempts' );
		$suppress = $wpdb->suppress_errors( true );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE user_id = %d
				AND ( submitted_at IS NOT NULL OR status IN ( 'submitted', 'completed', 'graded' ) )",
				$user_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$wpdb->suppress_errors( $suppress );

		return ( null !== $count && (int) $count > 0 );
	}

	// -----------------------------------------------------------------
	// Anti-fraud.
	// -----------------------------------------------------------------

	/**
	 * Screen a referral for abuse.
	 *
	 * @param object $row Referral row.
	 * @return string Empty string when clean, otherwise a reason slug.
	 */
	public static function fraud_check( $row ) {
		global $wpdb;

		$referrer_id = (int) $row->referrer_id;
		$referred_id = (int) $row->referred_id;

		// 1. Self-referral by user ID.
		if ( $referrer_id === $referred_id ) {
			return 'self_referral';
		}

		$referrer = get_userdata( $referrer_id );
		$referred = get_userdata( $referred_id );

		if ( ! $referrer || ! $referred ) {
			return 'account_missing';
		}

		// 2. Self-referral by email, ignoring dots and +tags.
		if ( self::normalize_email( $referrer->user_email ) === self::normalize_email( $referred->user_email ) ) {
			return 'same_email';
		}

		// 3. Disposable / throwaway mailbox.
		if ( self::is_disposable_email( $referred->user_email ) ) {
			return 'disposable_email';
		}

		$signup_ip = (string) $row->signup_ip;

		// 4. Signed up from the referrer's own IP address.
		if ( '' !== $signup_ip ) {
			$referrer_ips = array(
				(string) get_user_meta( $referrer_id, self::META_SIGNUP_IP, true ),
				(string) get_user_meta( $referrer_id, self::META_LAST_IP, true ),
			);

			if ( in_array( $signup_ip, array_filter( $referrer_ips ), true ) ) {
				return 'same_ip_as_referrer';
			}
		}

		$table = CES_Helpers::table( 'referrals' );

		// 5. Too many signups from one IP inside the window.
		/**
		 * Filter how many referral signups one IP may produce in the window.
		 *
		 * @param int $limit Maximum signups per IP.
		 */
		$ip_limit = max( 1, (int) apply_filters( 'ces_referral_ip_limit', 3 ) );

		/**
		 * Filter the IP velocity window in seconds.
		 *
		 * @param int $window Window length in seconds.
		 */
		$ip_window = max( HOUR_IN_SECONDS, (int) apply_filters( 'ces_referral_ip_window', DAY_IN_SECONDS ) );

		if ( '' !== $signup_ip ) {
			$since = gmdate( 'Y-m-d H:i:s', strtotime( (string) $row->created_at ) - $ip_window );

			$from_ip = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE signup_ip = %s AND created_at >= %s",
					$signup_ip,
					$since
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( $from_ip > $ip_limit ) {
				return 'ip_velocity';
			}
		}

		// 6. The same browser has already produced a referral signup.
		$device_hash = (string) $row->device_hash;

		/**
		 * Filter how many referral signups may share one device hash.
		 *
		 * @param int $limit Maximum referrals per device.
		 */
		$device_limit = max( 1, (int) apply_filters( 'ces_referral_device_limit', 1 ) );

		if ( '' !== $device_hash ) {
			$same_device = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE device_hash = %s AND referred_id <> %d",
					$device_hash,
					$referred_id
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( $same_device >= $device_limit ) {
				return 'duplicate_device';
			}
		}

		// 7. Daily payout cap per referrer. Deferrable, not fatal.
		/**
		 * Filter how many referrals one account may be paid for per day.
		 *
		 * @param int $cap Maximum paid referrals per day.
		 */
		$daily_cap = max( 1, (int) apply_filters( 'ces_referral_daily_cap', 10 ) );

		$paid_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE referrer_id = %d AND reward_paid = 1 AND DATE( qualified_at ) = %s",
				$referrer_id,
				current_time( 'Y-m-d' )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $paid_today >= $daily_cap ) {
			return 'daily_cap';
		}

		/**
		 * Filter the fraud verdict for a referral.
		 *
		 * Return a non-empty reason slug to block the payout.
		 *
		 * @param string $reason Reason so far (empty means clean).
		 * @param object $row    Referral row.
		 */
		return (string) apply_filters( 'ces_referral_fraud_reason', '', $row );
	}

	/**
	 * Human label for a reject reason slug.
	 *
	 * @param string $reason Reason slug.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		$labels = array(
			'self_referral'       => __( 'Self-referral', 'classic-edu-suite' ),
			'same_email'          => __( 'Same email address as the referrer', 'classic-edu-suite' ),
			'disposable_email'    => __( 'Disposable email domain', 'classic-edu-suite' ),
			'same_ip_as_referrer' => __( 'Signed up from the referrer IP', 'classic-edu-suite' ),
			'ip_velocity'         => __( 'Too many signups from one IP', 'classic-edu-suite' ),
			'duplicate_device'    => __( 'Device already used for a referral', 'classic-edu-suite' ),
			'daily_cap'           => __( 'Daily referral cap reached (will retry)', 'classic-edu-suite' ),
			'account_missing'     => __( 'Account no longer exists', 'classic-edu-suite' ),
			'referrer_missing'    => __( 'Referrer account no longer exists', 'classic-edu-suite' ),
			'manual'              => __( 'Rejected by an administrator', 'classic-edu-suite' ),
		);

		$reason = (string) $reason;

		if ( isset( $labels[ $reason ] ) ) {
			return $labels[ $reason ];
		}

		return '' === $reason ? '' : ucwords( str_replace( '_', ' ', $reason ) );
	}

	/**
	 * Normalise an email for comparison: lowercase, no +tag, Gmail dots
	 * collapsed.
	 *
	 * @param string $email Raw email address.
	 * @return string
	 */
	public static function normalize_email( $email ) {
		$email = strtolower( trim( (string) $email ) );

		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return $email;
		}

		$parts  = explode( '@', $email, 2 );
		$local  = $parts[0];
		$domain = $parts[1];

		$local = explode( '+', $local )[0];

		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}

		return $local . '@' . $domain;
	}

	/**
	 * Whether an email uses a known throwaway domain.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	public static function is_disposable_email( $email ) {
		$email = strtolower( trim( (string) $email ) );

		if ( false === strpos( $email, '@' ) ) {
			return false;
		}

		$domain = substr( $email, strpos( $email, '@' ) + 1 );

		return in_array( $domain, self::disposable_domains(), true );
	}

	/**
	 * Disposable email domains we refuse to pay referrals on.
	 *
	 * @return string[]
	 */
	public static function disposable_domains() {
		$domains = array(
			'mailinator.com',
			'guerrillamail.com',
			'guerrillamail.info',
			'sharklasers.com',
			'10minutemail.com',
			'10minutemail.net',
			'tempmail.com',
			'temp-mail.org',
			'yopmail.com',
			'yopmail.fr',
			'throwawaymail.com',
			'trashmail.com',
			'getnada.com',
			'dispostable.com',
			'maildrop.cc',
			'fakeinbox.com',
			'mytemp.email',
			'moakt.com',
			'emailondeck.com',
			'tempr.email',
			'mail-temp.com',
			'inboxkitten.com',
			'spam4.me',
			'mohmal.com',
			'linshiyouxiang.net',
		);

		/**
		 * Filter the disposable email domain blocklist.
		 *
		 * @param string[] $domains Lowercase domains.
		 */
		return array_map( 'strtolower', (array) apply_filters( 'ces_disposable_email_domains', $domains ) );
	}

	// -----------------------------------------------------------------
	// Row helpers and reporting.
	// -----------------------------------------------------------------

	/**
	 * The referral row for a referred user.
	 *
	 * @param int $referred_id Referred user ID.
	 * @return object|null
	 */
	public static function get_row_for_referred( $referred_id ) {
		global $wpdb;

		$referred_id = (int) $referred_id;

		if ( $referred_id <= 0 ) {
			return null;
		}

		$table = CES_Helpers::table( 'referrals' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE referred_id = %d LIMIT 1", $referred_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $row ? $row : null;
	}

	/**
	 * One referral row by its primary key.
	 *
	 * @param int $row_id Referral row ID.
	 * @return object|null
	 */
	public static function get_row( $row_id ) {
		global $wpdb;

		$row_id = (int) $row_id;

		if ( $row_id <= 0 ) {
			return null;
		}

		$table = CES_Helpers::table( 'referrals' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $row_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $row ? $row : null;
	}

	/**
	 * Administrator override: qualify and pay a referral regardless of the
	 * fraud screen and the qualification conditions.
	 *
	 * The caller is responsible for the capability and nonce checks.
	 *
	 * @param int $row_id Referral row ID.
	 * @return bool True when the payout happened.
	 */
	public static function force_qualify( $row_id ) {
		$row = self::get_row( $row_id );

		if ( ! $row || (int) $row->reward_paid ) {
			return false;
		}

		return self::pay( $row );
	}

	/**
	 * Mark a referral rejected with a reason.
	 *
	 * @param int    $row_id Referral row ID.
	 * @param string $reason Reason slug.
	 * @return void
	 */
	public static function reject( $row_id, $reason ) {
		global $wpdb;

		$table = CES_Helpers::table( 'referrals' );

		$wpdb->update(
			$table,
			array(
				'status'        => 'rejected',
				'reject_reason' => substr( sanitize_key( (string) $reason ), 0, 100 ),
			),
			array( 'id' => (int) $row_id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		/**
		 * Fires when a referral is rejected by the fraud screen.
		 *
		 * @param int    $row_id Referral row ID.
		 * @param string $reason Reason slug.
		 */
		do_action( 'ces_referral_rejected', (int) $row_id, (string) $reason );
	}

	/**
	 * Store a non-fatal reason without changing the status.
	 *
	 * @param int    $row_id Referral row ID.
	 * @param string $reason Reason slug.
	 * @return void
	 */
	private static function set_reason( $row_id, $reason ) {
		global $wpdb;

		$table = CES_Helpers::table( 'referrals' );

		$wpdb->update(
			$table,
			array( 'reject_reason' => substr( sanitize_key( (string) $reason ), 0, 100 ) ),
			array( 'id' => (int) $row_id ),
			array( '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Count a referrer's referrals in one status.
	 *
	 * @param int    $referrer_id Referrer user ID.
	 * @param string $status      pending | qualified | rejected.
	 * @return int
	 */
	public static function count_by_status( $referrer_id, $status ) {
		global $wpdb;

		$referrer_id = (int) $referrer_id;

		if ( $referrer_id <= 0 ) {
			return 0;
		}

		$table = CES_Helpers::table( 'referrals' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE referrer_id = %d AND status = %s",
				$referrer_id,
				(string) $status
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A referrer's referrals, newest first.
	 *
	 * @param int $referrer_id Referrer user ID.
	 * @param int $limit       Rows to return, 1-100.
	 * @param int $offset      Rows to skip.
	 * @return array List of rows as associative arrays.
	 */
	public static function list_for_referrer( $referrer_id, $limit = 25, $offset = 0 ) {
		global $wpdb;

		$referrer_id = (int) $referrer_id;

		if ( $referrer_id <= 0 ) {
			return array();
		}

		$limit  = max( 1, min( 100, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$table  = CES_Helpers::table( 'referrals' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, referred_id, status, reward_paid, reject_reason, qualified_at, created_at
				FROM {$table}
				WHERE referrer_id = %d
				ORDER BY id DESC
				LIMIT %d OFFSET %d",
				$referrer_id,
				$limit,
				$offset
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['id']          = (int) $row['id'];
			$rows[ $index ]['referred_id'] = (int) $row['referred_id'];
			$rows[ $index ]['reward_paid'] = (int) $row['reward_paid'];
			$rows[ $index ]['name']        = self::display_name( (int) $row['referred_id'] );
			$rows[ $index ]['reason']      = self::reason_label( $row['reject_reason'] );
		}

		return $rows;
	}

	/**
	 * Headline referral numbers for one user.
	 *
	 * @param int $user_id Referrer user ID.
	 * @return array
	 */
	public static function stats( $user_id ) {
		$user_id = (int) $user_id;

		$pending   = self::count_by_status( $user_id, 'pending' );
		$qualified = self::count_by_status( $user_id, 'qualified' );
		$rejected  = self::count_by_status( $user_id, 'rejected' );

		$every = CES_Helpers::setting_int( 'coins_milestone_every', 5 );
		$every = $every > 0 ? $every : 0;

		$next_milestone = $every ? ( ( (int) floor( $qualified / $every ) + 1 ) * $every ) : 0;

		$earned = class_exists( 'CES_Wallet' )
			? CES_Wallet::sum_for_types( $user_id, array( 'referral_reward', 'referral_milestone' ) )
			: 0;

		return array(
			'code'               => self::get_code( $user_id ),
			'link'               => self::link( $user_id ),
			'whatsapp'           => self::whatsapp_url( $user_id ),
			'pending'            => $pending,
			'qualified'          => $qualified,
			'rejected'           => $rejected,
			'total'              => $pending + $qualified + $rejected,
			'coins_earned'       => max( 0, (int) $earned ),
			'coins_per_referral' => CES_Helpers::setting_int( 'coins_per_referral', 25 ),
			'milestone_every'    => $every,
			'milestone_bonus'    => CES_Helpers::setting_int( 'coins_referral_milestone', 100 ),
			'next_milestone'     => $next_milestone,
			'to_next_milestone'  => $next_milestone ? max( 0, $next_milestone - $qualified ) : 0,
		);
	}

	/**
	 * Qualified referrals recorded in the current calendar month.
	 *
	 * @return int
	 */
	public static function qualified_this_month() {
		global $wpdb;

		$table = CES_Helpers::table( 'referrals' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE status = 'qualified' AND qualified_at >= %s",
				current_time( 'Y-m-01 00:00:00' )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Admin referral report rows with fraud flags.
	 *
	 * @param array $args Optional `status`, `referrer_id`, `limit`, `offset`.
	 * @return array
	 */
	public static function report( array $args = array() ) {
		global $wpdb;

		$status      = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$referrer_id = isset( $args['referrer_id'] ) ? (int) $args['referrer_id'] : 0;
		$limit       = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$offset      = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$table = CES_Helpers::table( 'referrals' );

		$where  = array( '1=1' );
		$params = array();

		if ( in_array( $status, array( 'pending', 'qualified', 'rejected' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( $referrer_id > 0 ) {
			$where[]  = 'referrer_id = %d';
			$params[] = $referrer_id;
		}

		$params[] = $limit;
		$params[] = $offset;

		$clause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d",
			$params
		);

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB

		if ( empty( $rows ) ) {
			return array();
		}

		foreach ( $rows as $row ) {
			$row->referrer_name = self::display_name( (int) $row->referrer_id );
			$row->referred_name = self::display_name( (int) $row->referred_id );
			$row->reason_label  = self::reason_label( $row->reject_reason );
			$row->flag          = ( 'pending' === $row->status ) ? self::fraud_check( $row ) : (string) $row->reject_reason;
			$row->flag_label    = self::reason_label( $row->flag );
		}

		return $rows;
	}

	/**
	 * Display name for a user ID, falling back to a deleted-account label.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function display_name( $user_id ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return __( '(deleted account)', 'classic-edu-suite' );
		}

		return $user->display_name ? $user->display_name : $user->user_login;
	}

	/**
	 * Daily cron sweep: retry pending referrals.
	 *
	 * Catches referrals that were held back by the daily cap, or whose email
	 * confirmation arrived after their last page view.
	 *
	 * @return void
	 */
	public static function sweep() {
		global $wpdb;

		$table = CES_Helpers::table( 'referrals' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT referred_id FROM {$table}
				WHERE status = 'pending' AND reward_paid = 0 AND created_at >= %s
				ORDER BY id ASC
				LIMIT 500",
				gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $referred_id ) {
			self::maybe_qualify( (int) $referred_id );
		}
	}
}
