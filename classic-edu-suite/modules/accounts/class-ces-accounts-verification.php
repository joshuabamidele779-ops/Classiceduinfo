<?php
/**
 * Proving that the number a student gave really is their parent's.
 *
 * A student types a number; that on its own proves nothing. So the parent is
 * asked, and the account stays shut until they answer. There are two ways for
 * them to answer, because a Nigerian parent on a cheap phone will manage one
 * of them even if the other fails:
 *
 *   1. **Tap the link.** They receive a message with a one-tap approval page
 *      showing their ward's name and exactly what the school will send them.
 *      No account, no password, two buttons: *Yes, that is my child* and
 *      *No, I do not know this person*.
 *   2. **Read back the code.** The same message carries a six-digit code. The
 *      parent reads it to the student, who types it on their own screen. This
 *      is the path that works when the parent will not tap links, which is
 *      more parents than you would hope.
 *
 * How the message gets there, in order of what is actually configured:
 *
 *   - The WhatsApp Cloud API, when the school has one (`parents_wa_*`).
 *   - Email, when the student gave a parent email address.
 *   - Otherwise the student forwards it themselves: we hand them a WhatsApp
 *      link already addressed to the parent's number with the message
 *      written. This is the default path and it costs nothing. The student
 *      cannot forge the answer, because approving still means tapping the
 *      link or reading the code from the parent's own phone.
 *
 * What is stored, and what is not:
 *
 *   - The six-digit code is kept **only** as an HMAC hash. The plain code
 *     exists for the length of one request and is never written anywhere.
 *   - The approval link's token is a bearer credential, like a password-reset
 *     link. Its hash is what the table is searched by, and the plain token is
 *     kept in the student's own user meta so the "send to my parent" button
 *     can still be drawn after a page reload. It is deleted the moment the
 *     parent decides.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create, send, check and expire parent approval requests.
 */
class CES_Accounts_Verification {

	/**
	 * Plain approval token, kept on the student so the share button survives
	 * a page reload. Deleted as soon as the parent decides.
	 */
	const META_TOKEN = 'ces_parent_approval_token';

	/**
	 * Long-lived, revocable token for the parent's progress page, issued
	 * once the account is approved.
	 */
	const META_PORTAL_TOKEN = 'ces_parent_portal_token';

	/**
	 * Parent email address, when the student gave one.
	 */
	const META_PARENT_EMAIL = 'ces_parent_email';

	/**
	 * Set when a parent asks not to be messaged again.
	 */
	const META_OPT_OUT = 'ces_parent_opt_out';

	/**
	 * Query argument carrying an approval token.
	 */
	const QUERY_TOKEN = 'ces_parent_approve';

	/**
	 * The table holding the requests.
	 *
	 * @return string
	 */
	public static function table() {
		return CES_Helpers::table( 'parent_verifications' );
	}

	/**
	 * HMAC a code or token with the per-install secret.
	 *
	 * @param string $value Plain value.
	 * @return string 64 hex characters.
	 */
	public static function hash( $value ) {
		return hash_hmac( 'sha256', (string) $value, CES_Helpers::stream_secret() );
	}

	/**
	 * "Now" as a scalar that can be compared with our own stored datetimes.
	 *
	 * Every datetime in this plugin's tables is site-local, written by
	 * `CES_Helpers::now()`. Parsing one with the server's own timezone would
	 * shift it, so they are read as if they were UTC. Both ends of every
	 * comparison in this class use this same convention, which makes the
	 * arithmetic correct whatever the server and site timezones are.
	 *
	 * @return int
	 */
	private static function local_ts() {
		return (int) strtotime( CES_Helpers::now() . ' UTC' );
	}

	/**
	 * Turn one of those scalars back into a storable datetime.
	 *
	 * @param int $timestamp Scalar from local_ts().
	 * @return string MySQL datetime.
	 */
	private static function local_mysql( $timestamp ) {
		return gmdate( 'Y-m-d H:i:s', (int) $timestamp );
	}

	/**
	 * Read a stored datetime as a comparable scalar.
	 *
	 * @param string $datetime MySQL datetime from one of our tables.
	 * @return int 0 when unparseable.
	 */
	private static function read_ts( $datetime ) {
		if ( '' === trim( (string) $datetime ) ) {
			return 0;
		}

		return (int) strtotime( (string) $datetime . ' UTC' );
	}

	/* ---------------------------------------------------------------------
	 * Starting a request.
	 * ------------------------------------------------------------------ */

	/**
	 * Ask a parent to approve their ward's account.
	 *
	 * @param int   $user_id Student.
	 * @param array $args    Optional. `silent` => true to create the request
	 *                       without sending anything (staff flows).
	 * @return array|WP_Error {
	 *     @type array  $row      The stored row.
	 *     @type string $code     Plain six-digit code. This request only.
	 *     @type string $token    Plain approval token.
	 *     @type string $link     The parent's approval URL.
	 *     @type string $wa_link  WhatsApp link the student can send.
	 *     @type array  $sent     Channels the message actually went out on.
	 * }
	 */
	public static function start( $user_id, array $args = array() ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'ces_pv_user', __( 'That account could not be found.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$contact = CES_Parent_Contact::get( $user_id );

		if ( '' === $contact['phone'] ) {
			return new WP_Error(
				'ces_pv_no_phone',
				__( 'Add your parent or guardian’s WhatsApp number first.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		// The daily cap is enforced here rather than only in resend(), so
		// every path to a parent's phone goes past it — including a student
		// who works out that resubmitting the onboarding form sends again.
		$cap = max( 1, CES_Helpers::setting_int( 'accounts_resend_daily_cap', 6 ) );

		if ( empty( $args['silent'] ) && self::sends_today( $user_id ) >= $cap ) {
			return new WP_Error(
				'ces_pv_capped',
				__( 'This request has gone out several times today already. Please ask a member of staff to help instead.', 'classic-edu-suite' ),
				array( 'status' => 429 )
			);
		}

		// Only one request may be open at a time, so a parent is never
		// holding two different codes for the same child.
		self::cancel_open( $user_id, 'replaced' );

		$code  = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$token = wp_generate_password( 48, false, false );

		$ttl_minutes = max( 5, CES_Helpers::setting_int( 'accounts_code_ttl_minutes', 60 ) );
		$link_hours  = max( 1, CES_Helpers::setting_int( 'accounts_link_ttl_hours', 72 ) );

		// The link is the slower path — a parent may not look at WhatsApp for
		// a day — so the row lives as long as the longer of the two.
		$lifetime = max( $ttl_minutes * MINUTE_IN_SECONDS, $link_hours * HOUR_IN_SECONDS );

		$channel = self::primary_channel( $user_id );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'user_id'      => $user_id,
				'channel'      => $channel,
				'destination'  => $contact['phone'],
				'code_hash'    => self::hash( $code ),
				'token_hash'   => self::hash( $token ),
				'status'       => 'pending',
				'attempts'     => 0,
				'send_count'   => 0,
				'ip_address'   => CES_Helpers::ip(),
				'created_at'   => CES_Helpers::now(),
				'expires_at'   => self::local_mysql( self::local_ts() + $lifetime ),
				'last_sent_at' => null,
				'meta'         => wp_json_encode(
					array(
						'code_expires'  => time() + ( $ttl_minutes * MINUTE_IN_SECONDS ),
						'parent_name'   => $contact['name'],
						'parent_email'  => self::parent_email( $user_id ),
						'student_login' => $user->user_login,
						// How the message reaches the parent, which is what
						// decides how much the answer proves. See delivery().
						'delivery'      => self::can_send_directly( $user_id ) ? 'direct' : 'courier',
					)
				),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			CES_Helpers::log( 'Could not store a parent verification row', $wpdb->last_error );

			return new WP_Error( 'ces_pv_store', __( 'We could not start that request. Please try again.', 'classic-edu-suite' ), array( 'status' => 500 ) );
		}

		$row_id = (int) $wpdb->insert_id;

		update_user_meta( $user_id, self::META_TOKEN, $token );

		CES_Accounts_Status::set_status(
			$user_id,
			'awaiting_parent',
			array(
				'actor_id' => $user_id,
				'channel'  => $channel,
				'event'    => 'verification_started',
			)
		);

		self::check_for_flags( $user_id, $contact['phone'] );

		$row  = self::get_row( $row_id );
		$sent = array();

		if ( ! is_array( $row ) ) {
			// The insert reported success, so this should not happen; a
			// replication lag on a read replica could still do it. The
			// request is live either way, so carry on without sending and
			// let the student press "send again".
			CES_Helpers::log( 'Parent verification row vanished after insert', $row_id );

			return new WP_Error( 'ces_pv_store', __( 'We could not start that request. Please try again.', 'classic-edu-suite' ), array( 'status' => 500 ) );
		}

		if ( empty( $args['silent'] ) ) {
			$sent = self::dispatch( $user_id, $row, $code, $token );
		}

		return array(
			'row'     => $row,
			'code'    => $code,
			'token'   => $token,
			'link'    => self::approval_link( $token ),
			'wa_link' => self::parent_wa_link( $user_id, $code, $token ),
			'sent'    => $sent,
		);
	}

	/**
	 * Send the request on every channel that is available.
	 *
	 * @param int    $user_id Student.
	 * @param array  $row     Stored row.
	 * @param string $code    Plain code.
	 * @param string $token   Plain token.
	 * @param bool   $reminder Whether this is the follow-up, not the first.
	 * @return array Channel slugs that reported success.
	 */
	private static function dispatch( $user_id, array $row, $code, $token, $reminder = false ) {
		global $wpdb;

		$sent = array();

		if ( class_exists( 'CES_Accounts_Notify' ) ) {
			$sent = CES_Accounts_Notify::parent_request( $user_id, $code, $token, $reminder );
		}

		$update = array(
			'send_count'   => (int) $row['send_count'] + 1,
			'last_sent_at' => CES_Helpers::now(),
		);

		$format = array( '%d', '%s' );

		if ( $reminder ) {
			$update['reminded_at'] = CES_Helpers::now();
			$format[]              = '%s';
		}

		$wpdb->update( self::table(), $update, array( 'id' => (int) $row['id'] ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		CES_Accounts_Status::log(
			$user_id,
			$reminder ? 'parent_reminded' : 'parent_requested',
			array(
				'actor_id' => get_current_user_id(),
				'channel'  => implode( ',', $sent ) ? implode( ',', $sent ) : 'link',
				'detail'   => CES_Parent_Contact::format_phone( (string) $row['destination'] ),
			)
		);

		return $sent;
	}

	/**
	 * Send the request again.
	 *
	 * A new code and a new link are issued, which is why the student is told
	 * that only the newest message works: the old code exists nowhere but in
	 * the parent's chat history, and we keep no copy to compare against.
	 *
	 * @param int $user_id Student.
	 * @return array|WP_Error Same shape as start().
	 */
	public static function resend( $user_id ) {
		$user_id = absint( $user_id );

		$cooldown = max( 0, CES_Helpers::setting_int( 'accounts_resend_cooldown', 120 ) );
		$row      = self::open_row( $user_id );

		if ( $row && $cooldown > 0 && ! empty( $row['last_sent_at'] ) ) {
			$last = self::read_ts( $row['last_sent_at'] );
			$wait = $last ? ( $last + $cooldown ) - self::local_ts() : 0;

			if ( $wait > 0 ) {
				return new WP_Error(
					'ces_pv_cooldown',
					sprintf(
						/* translators: %d: number of seconds to wait. */
						_n( 'Please wait %d second before sending again.', 'Please wait %d seconds before sending again.', (int) $wait, 'classic-edu-suite' ),
						(int) $wait
					),
					array( 'status' => 429 )
				);
			}
		}

		$cap = max( 1, CES_Helpers::setting_int( 'accounts_resend_daily_cap', 6 ) );

		if ( self::sends_today( $user_id ) >= $cap ) {
			return new WP_Error(
				'ces_pv_capped',
				__( 'You have sent this request several times today. Please ask a member of staff to help instead.', 'classic-edu-suite' ),
				array( 'status' => 429 )
			);
		}

		if ( ! CES_Helpers::rate_limit( 'parent_verify_send', 12, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'ces_pv_throttled', __( 'Too many attempts. Please wait a while and try again.', 'classic-edu-suite' ), array( 'status' => 429 ) );
		}

		return self::start( $user_id );
	}

	/**
	 * How many requests went out for this student today.
	 *
	 * @param int $user_id Student.
	 * @return int
	 */
	public static function sends_today( $user_id ) {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(send_count),0) FROM {$table} WHERE user_id = %d AND created_at >= %s",
				absint( $user_id ),
				gmdate( 'Y-m-d 00:00:00', self::local_ts() )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ---------------------------------------------------------------------
	 * Answering: the code.
	 * ------------------------------------------------------------------ */

	/**
	 * Check a code the student typed in.
	 *
	 * @param int    $user_id Student.
	 * @param string $code    What was typed.
	 * @return true|WP_Error
	 */
	public static function verify_code( $user_id, $code ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$code    = preg_replace( '/\D/', '', (string) $code );

		if ( 6 !== strlen( $code ) ) {
			return new WP_Error( 'ces_pv_format', __( 'The code is six digits. Please check and try again.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		if ( ! CES_Helpers::rate_limit( 'parent_verify_code', 15, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ces_pv_throttled', __( 'Too many tries. Please wait ten minutes.', 'classic-edu-suite' ), array( 'status' => 429 ) );
		}

		$row = self::open_row( $user_id );

		if ( ! $row ) {
			return new WP_Error( 'ces_pv_none', __( 'There is no code waiting. Send the request to your parent first.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$max = max( 1, CES_Helpers::setting_int( 'accounts_code_max_attempts', 6 ) );

		if ( (int) $row['attempts'] >= $max ) {
			self::close_row( (int) $row['id'], 'expired' );

			return new WP_Error(
				'ces_pv_attempts',
				__( 'That code has been tried too many times. Send your parent a new one.', 'classic-edu-suite' ),
				array( 'status' => 429 )
			);
		}

		$meta         = self::row_meta( $row );
		$code_expires = isset( $meta['code_expires'] ) ? (int) $meta['code_expires'] : 0;

		if ( $code_expires && $code_expires < time() ) {
			return new WP_Error(
				'ces_pv_code_expired',
				__( 'That code has expired. Send your parent a new one.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'attempts' => (int) $row['attempts'] + 1 ),
			array( 'id' => (int) $row['id'] ),
			array( '%d' ),
			array( '%d' )
		);

		if ( ! hash_equals( (string) $row['code_hash'], self::hash( $code ) ) ) {
			CES_Accounts_Status::log( $user_id, 'code_wrong', array( 'actor_id' => $user_id ) );

			$left = max( 0, $max - ( (int) $row['attempts'] + 1 ) );

			return new WP_Error(
				'ces_pv_mismatch',
				$left > 0
					? sprintf(
						/* translators: %d: remaining tries. */
						_n( 'That code is not right. %d try left.', 'That code is not right. %d tries left.', $left, 'classic-edu-suite' ),
						$left
					)
					: __( 'That code is not right, and there are no tries left. Send your parent a new one.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		self::settle( $user_id, (int) $row['id'], 'verified', 'code', 'parent' );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Answering: the link.
	 * ------------------------------------------------------------------ */

	/**
	 * Find the open request a token belongs to.
	 *
	 * @param string $token Plain token from the URL.
	 * @return array|null Row, or null when the token is unknown or spent.
	 */
	public static function find_by_token( $token ) {
		global $wpdb;

		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );

		if ( strlen( $token ) < 20 ) {
			return null;
		}

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s ORDER BY id DESC LIMIT 1",
				self::hash( $token )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Record the parent's decision made through the link.
	 *
	 * @param array  $row      Row found by its token.
	 * @param string $decision `approve` or `decline`.
	 * @param string $reason   Optional reason when declining.
	 * @return true|WP_Error
	 */
	public static function decide_by_token( array $row, $decision, $reason = '' ) {
		$decision = 'decline' === $decision ? 'decline' : 'approve';
		$user_id  = (int) $row['user_id'];

		if ( 'pending' !== (string) $row['status'] ) {
			return new WP_Error( 'ces_pv_spent', __( 'That link has already been used.', 'classic-edu-suite' ), array( 'status' => 410 ) );
		}

		if ( self::is_expired( $row ) ) {
			self::close_row( (int) $row['id'], 'expired' );

			return new WP_Error( 'ces_pv_expired', __( 'That link has expired. Ask your child to send it again.', 'classic-edu-suite' ), array( 'status' => 410 ) );
		}

		if ( 'approve' === $decision ) {
			self::settle( $user_id, (int) $row['id'], 'verified', 'link', 'parent' );

			return true;
		}

		self::settle( $user_id, (int) $row['id'], 'declined', 'link', 'parent', $reason );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Staff override.
	 * ------------------------------------------------------------------ */

	/**
	 * Mark a parent as confirmed because a member of staff spoke to them.
	 *
	 * The commonest real-world case: the parent rang the school. The event
	 * log records who vouched for it.
	 *
	 * @param int    $user_id  Student.
	 * @param string $detail   Short note, e.g. "spoke to mother on 0803…".
	 * @param int    $actor_id Member of staff.
	 * @return true|WP_Error
	 */
	public static function confirm_by_staff( $user_id, $detail = '', $actor_id = 0 ) {
		$user_id  = absint( $user_id );
		$actor_id = $actor_id ? absint( $actor_id ) : get_current_user_id();

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'ces_pv_user', __( 'That account could not be found.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$row = self::open_row( $user_id );

		if ( $row ) {
			self::close_row( (int) $row['id'], 'verified', 'staff' );
		}

		delete_user_meta( $user_id, self::META_TOKEN );

		CES_Accounts_Status::log(
			$user_id,
			'parent_confirmed_by_staff',
			array(
				'actor_id' => $actor_id,
				'channel'  => 'staff',
				'detail'   => $detail,
			)
		);

		CES_Accounts_Status::mark_parent_verified( $user_id, 'staff' );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Row plumbing.
	 * ------------------------------------------------------------------ */

	/**
	 * Close a row and move the account on.
	 *
	 * @param int    $user_id  Student.
	 * @param int    $row_id   Row.
	 * @param string $status   `verified` or `declined`.
	 * @param string $channel  How the parent answered.
	 * @param string $by       Who decided: parent | staff.
	 * @param string $reason   Reason given when declining.
	 * @return void
	 */
	private static function settle( $user_id, $row_id, $status, $channel, $by = 'parent', $reason = '' ) {
		$row = self::get_row( $row_id );

		self::close_row( $row_id, $status, $by, $reason );

		delete_user_meta( $user_id, self::META_TOKEN );

		// An answer that came back down a message the student carried proves
		// less than one we delivered ourselves — see delivery(). Where the
		// school has asked for the stronger guarantee, flag it, which sends
		// the account to the staff queue instead of opening it.
		if ( 'verified' === $status
			&& 'parent' === $by
			&& is_array( $row )
			&& 'courier' === self::delivery( $row )
			&& CES_Helpers::enabled( 'accounts_review_couriered' )
		) {
			CES_Accounts_Status::add_flag(
				$user_id,
				'weak_proof',
				__( 'The student forwarded the request themselves, so the answer needs checking.', 'classic-edu-suite' )
			);
		}

		if ( 'verified' === $status ) {
			// The parent now gets a permanent, revocable link to their
			// ward's progress — the thing they actually wanted.
			self::issue_portal_token( $user_id );

			CES_Accounts_Status::mark_parent_verified( $user_id, $channel );

			return;
		}

		CES_Accounts_Status::decline(
			$user_id,
			$reason ? $reason : __( 'Your parent or guardian did not confirm this account.', 'classic-edu-suite' ),
			array(
				'actor_id' => 0,
				'channel'  => $channel,
			)
		);
	}

	/**
	 * Write a terminal status onto a row.
	 *
	 * @param int    $row_id Row.
	 * @param string $status New status.
	 * @param string $by     Who decided.
	 * @param string $reason Reason when declining.
	 * @return void
	 */
	private static function close_row( $row_id, $status, $by = '', $reason = '' ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'         => sanitize_key( $status ),
				'decided_by'     => substr( sanitize_key( (string) $by ), 0, 20 ),
				'decline_reason' => substr( sanitize_text_field( (string) $reason ), 0, 190 ),
				'decided_at'     => CES_Helpers::now(),
			),
			array( 'id' => absint( $row_id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Cancel every open request for a student.
	 *
	 * @param int    $user_id Student.
	 * @param string $reason  Why.
	 * @return void
	 */
	public static function cancel_open( $user_id, $reason = '' ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'         => 'cancelled',
				'decline_reason' => substr( sanitize_text_field( (string) $reason ), 0, 190 ),
				'decided_at'     => CES_Helpers::now(),
			),
			array(
				'user_id' => absint( $user_id ),
				'status'  => 'pending',
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * One row by ID.
	 *
	 * @param int $row_id Row.
	 * @return array|null
	 */
	public static function get_row( $row_id ) {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $row_id ) ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The open request for a student, if there is one.
	 *
	 * @param int $user_id Student.
	 * @return array|null
	 */
	public static function open_row( $user_id ) {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1",
				absint( $user_id )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! is_array( $row ) ) {
			return null;
		}

		if ( self::is_expired( $row ) ) {
			self::close_row( (int) $row['id'], 'expired' );

			// Drop the plain token with it, so the "send to my parent" button
			// cannot hand a parent a link that will only tell them it has
			// expired. The student is shown the resend path instead.
			delete_user_meta( absint( $user_id ), self::META_TOKEN );

			return null;
		}

		return $row;
	}

	/**
	 * The most recent request for a student, whatever its status.
	 *
	 * @param int $user_id Student.
	 * @return array|null
	 */
	public static function last_row( $user_id ) {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
				absint( $user_id )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether a row is past its expiry.
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	public static function is_expired( array $row ) {
		if ( empty( $row['expires_at'] ) ) {
			return false;
		}

		$expires = self::read_ts( $row['expires_at'] );

		return $expires && $expires < self::local_ts();
	}

	/**
	 * A row's decoded meta column.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function row_meta( array $row ) {
		if ( empty( $row['meta'] ) ) {
			return array();
		}

		$meta = json_decode( (string) $row['meta'], true );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Delete every request belonging to a student.
	 *
	 * @param int $user_id Student.
	 * @return void
	 */
	public static function delete_for_user( $user_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'user_id' => absint( $user_id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ---------------------------------------------------------------------
	 * Links.
	 * ------------------------------------------------------------------ */

	/**
	 * The parent's one-tap approval URL.
	 *
	 * Points at the page carrying `[ces_parent_portal]` when one is
	 * configured, so the school's own header and footer wrap it; otherwise at
	 * a self-contained page this module renders itself, so the feature works
	 * on an install where nobody created the page.
	 *
	 * @param string $token Plain token.
	 * @return string
	 */
	public static function approval_link( $token ) {
		$page_id = CES_Helpers::setting_int( 'accounts_portal_page_id', 0 );

		if ( $page_id > 0 ) {
			$permalink = get_permalink( $page_id );

			if ( $permalink ) {
				return add_query_arg( self::QUERY_TOKEN, $token, $permalink );
			}
		}

		return add_query_arg( self::QUERY_TOKEN, $token, home_url( '/' ) );
	}

	/**
	 * A WhatsApp link, addressed to the parent, with the request written.
	 *
	 * The student taps this and presses send. It is the default delivery
	 * path: no API, no cost, and the parent still has to approve on their
	 * own phone, so nothing about it lets a student approve themselves.
	 *
	 * @param int    $user_id Student.
	 * @param string $code    Plain code.
	 * @param string $token   Plain token.
	 * @return string
	 */
	public static function parent_wa_link( $user_id, $code = '', $token = '' ) {
		$contact = CES_Parent_Contact::get( absint( $user_id ) );

		if ( '' === $contact['phone'] ) {
			return '';
		}

		if ( '' === $token ) {
			$token = (string) get_user_meta( absint( $user_id ), self::META_TOKEN, true );
		}

		if ( '' === $token ) {
			return '';
		}

		$message = CES_Accounts_Notify::parent_message( absint( $user_id ), $code, $token );

		return CES_Parent_Contact::wa_link( $contact['phone'], $message );
	}

	/**
	 * Issue (or re-issue) the parent's long-lived progress link token.
	 *
	 * @param int  $user_id Student.
	 * @param bool $force   Replace an existing token.
	 * @return string Plain token.
	 */
	public static function issue_portal_token( $user_id, $force = false ) {
		$user_id = absint( $user_id );
		$token   = (string) get_user_meta( $user_id, self::META_PORTAL_TOKEN, true );

		if ( '' === $token || $force ) {
			$token = wp_generate_password( 48, false, false );
			update_user_meta( $user_id, self::META_PORTAL_TOKEN, $token );
		}

		return $token;
	}

	/**
	 * The parent's progress page URL.
	 *
	 * @param int $user_id Student.
	 * @return string
	 */
	public static function portal_link( $user_id ) {
		$token = (string) get_user_meta( absint( $user_id ), self::META_PORTAL_TOKEN, true );

		return '' === $token ? '' : self::approval_link( $token );
	}

	/**
	 * Find a student by their parent's long-lived portal token.
	 *
	 * @param string $token Plain token.
	 * @return int User ID, 0 when unknown.
	 */
	public static function user_by_portal_token( $token ) {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );

		if ( strlen( $token ) < 20 ) {
			return 0;
		}

		$found = get_users(
			array(
				'meta_key'   => self::META_PORTAL_TOKEN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $token,                  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ids',
			)
		);

		return empty( $found ) ? 0 : (int) $found[0];
	}

	/* ---------------------------------------------------------------------
	 * Channels and contact details.
	 * ------------------------------------------------------------------ */

	/**
	 * The channel this request will mainly travel on.
	 *
	 * @param int $user_id Student.
	 * @return string whatsapp | email | link
	 */
	public static function primary_channel( $user_id ) {
		if ( class_exists( 'CES_Parents_Whatsapp' ) && CES_Parents_Whatsapp::enabled() ) {
			return 'whatsapp';
		}

		if ( '' !== self::parent_email( $user_id ) ) {
			return 'email';
		}

		return 'link';
	}

	/**
	 * The parent's email address, when one was given.
	 *
	 * @param int $user_id Student.
	 * @return string
	 */
	public static function parent_email( $user_id ) {
		$email = sanitize_email( (string) get_user_meta( absint( $user_id ), self::META_PARENT_EMAIL, true ) );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Whether this parent has asked not to be messaged.
	 *
	 * @param int $user_id Student.
	 * @return bool
	 */
	public static function opted_out( $user_id ) {
		return '1' === (string) get_user_meta( absint( $user_id ), self::META_OPT_OUT, true );
	}

	/* ---------------------------------------------------------------------
	 * Anti-fraud flags.
	 * ------------------------------------------------------------------ */

	/**
	 * Flag the obvious abuses of a parent-approval gate.
	 *
	 * @param int    $user_id Student.
	 * @param string $phone   Normalised parent number.
	 * @return void
	 */
	public static function check_for_flags( $user_id, $phone ) {
		$user_id = absint( $user_id );
		$phone   = preg_replace( '/\D/', '', (string) $phone );

		if ( '' === $phone ) {
			return;
		}

		$max = max( 1, CES_Helpers::setting_int( 'accounts_max_per_phone', 3 ) );
		$use = self::students_on_phone( $phone );

		if ( $use > $max ) {
			CES_Accounts_Status::add_flag(
				$user_id,
				'duplicate_phone',
				sprintf(
					/* translators: 1: number of accounts, 2: phone number. */
					__( '%1$d accounts share the number %2$s', 'classic-edu-suite' ),
					$use,
					CES_Parent_Contact::format_phone( $phone )
				)
			);
		}

		// A student who put their own number down as their parent's.
		$own = preg_replace( '/\D/', '', (string) get_user_meta( $user_id, CES_Accounts_Onboarding::META_OWN_PHONE, true ) );

		if ( '' !== $own && $own === $phone ) {
			CES_Accounts_Status::add_flag( $user_id, 'self_number' );
		}

		if ( self::phone_declined_before( $phone, $user_id ) ) {
			CES_Accounts_Status::add_flag( $user_id, 'parent_declined' );
		}

		$dob = (string) get_user_meta( $user_id, CES_Accounts_Status::META_DOB, true );

		if ( '' !== $dob && self::is_minor( $dob ) ) {
			CES_Accounts_Status::add_flag( $user_id, 'minor' );
		}
	}

	/**
	 * How many accounts carry this parent number.
	 *
	 * @param string $phone Normalised digits.
	 * @return int
	 */
	public static function students_on_phone( $phone ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
				CES_Parent_Contact::META_PHONE,
				preg_replace( '/\D/', '', (string) $phone )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether this number has refused an account before.
	 *
	 * @param string $phone   Normalised digits.
	 * @param int    $exclude Student to leave out.
	 * @return bool
	 */
	public static function phone_declined_before( $phone, $exclude = 0 ) {
		global $wpdb;

		$table = self::table();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE destination = %s AND status = 'declined' AND user_id <> %d",
				preg_replace( '/\D/', '', (string) $phone ),
				absint( $exclude )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $count > 0;
	}

	/**
	 * Whether a date of birth is under the configured age of majority.
	 *
	 * @param string $dob Y-m-d.
	 * @return bool
	 */
	public static function is_minor( $dob ) {
		$stamp = strtotime( (string) $dob );

		if ( ! $stamp ) {
			return false;
		}

		$age_limit = max( 1, CES_Helpers::setting_int( 'accounts_minor_age', 18 ) );
		$years     = (int) floor( ( time() - $stamp ) / YEAR_IN_SECONDS );

		return $years < $age_limit;
	}

	/* ---------------------------------------------------------------------
	 * Housekeeping, from cron.
	 * ------------------------------------------------------------------ */

	/**
	 * Expire, remind and give up, once an hour.
	 *
	 * @return void
	 */
	public static function maintenance() {
		self::expire_stale();
		self::send_reminders();
		self::auto_decline();
	}

	/**
	 * Close every request that has run out of time.
	 *
	 * @return int How many rows were closed.
	 */
	public static function expire_stale() {
		global $wpdb;

		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id FROM {$table} WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < %s LIMIT 200",
				self::local_mysql( self::local_ts() )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( (array) $rows as $row ) {
			self::close_row( (int) $row['id'], 'expired' );
			delete_user_meta( (int) $row['user_id'], self::META_TOKEN );
			CES_Accounts_Status::log( (int) $row['user_id'], 'request_expired', array( 'actor_id' => 0 ) );
		}

		return count( (array) $rows );
	}

	/**
	 * Nudge parents who have not answered, once each.
	 *
	 * One reminder only, ever. A school that messages a parent every day
	 * stops being read at all.
	 *
	 * @return int How many reminders went out.
	 */
	public static function send_reminders() {
		global $wpdb;

		$hours = CES_Helpers::setting_int( 'accounts_reminder_hours', 24 );

		if ( $hours <= 0 ) {
			return 0;
		}

		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = 'pending'
				   AND reminded_at IS NULL
				   AND last_sent_at IS NOT NULL
				   AND last_sent_at < %s
				 ORDER BY id ASC
				 LIMIT 25",
				self::local_mysql( self::local_ts() - ( $hours * HOUR_IN_SECONDS ) )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$sent = 0;

		foreach ( (array) $rows as $row ) {
			$user_id = (int) $row['user_id'];

			if ( self::opted_out( $user_id ) ) {
				continue;
			}

			// The reminder can only carry the link, never the code: the code
			// is not recoverable, by design.
			$token = (string) get_user_meta( $user_id, self::META_TOKEN, true );

			if ( '' === $token ) {
				continue;
			}

			// Nothing to remind them through unless we can send it ourselves.
			if ( ! self::can_send_directly( $user_id ) ) {
				// Tell the student instead, so they can forward it again.
				if ( class_exists( 'CES_Accounts_Notify' ) ) {
					CES_Accounts_Notify::student_nudge( $user_id );
				}

				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array( 'reminded_at' => CES_Helpers::now() ),
					array( 'id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);

				continue;
			}

			self::dispatch( $user_id, $row, '', $token, true );
			++$sent;
		}

		return $sent;
	}

	/**
	 * How a request reached the parent, and therefore what the answer proves.
	 *
	 * This is the one honest limit of the whole feature, and it is worth
	 * being plain about:
	 *
	 *   direct   The site messaged the parent itself, over the WhatsApp Cloud
	 *            API or by email. The link and the code never passed through
	 *            the student's hands, so an answer really does come from
	 *            whoever holds that number or inbox.
	 *   courier  The student forwarded the message. They can therefore read
	 *            the link and the code, which means the answer proves that
	 *            *someone* with that message approved — not provably the
	 *            parent. A student determined to get past the gate can open
	 *            their own link.
	 *
	 * The courier path is still worth having: it asks the student to name a
	 * real parent with a real number, it puts that number on file where staff
	 * can ring it, and it makes the parent the obvious route rather than an
	 * obstacle to work around. But it is a declaration, not a proof, and
	 * `accounts_review_couriered` exists for a school that needs the stronger
	 * guarantee: a couriered answer is then flagged and waits for staff.
	 *
	 * @param array $row Stored row.
	 * @return string `direct` or `courier`.
	 */
	public static function delivery( array $row ) {
		$meta = self::row_meta( $row );

		return isset( $meta['delivery'] ) && 'direct' === $meta['delivery'] ? 'direct' : 'courier';
	}

	/**
	 * Whether the site can message this parent without the student's help.
	 *
	 * @param int $user_id Student.
	 * @return bool
	 */
	public static function can_send_directly( $user_id ) {
		if ( class_exists( 'CES_Parents_Whatsapp' ) && CES_Parents_Whatsapp::enabled() ) {
			return true;
		}

		return '' !== self::parent_email( $user_id );
	}

	/**
	 * Give up on accounts nobody ever approved.
	 *
	 * Off by default: an abandoned signup is not a problem, and a student who
	 * comes back three weeks later should still be able to finish.
	 *
	 * @return int How many accounts were declined.
	 */
	public static function auto_decline() {
		$days = CES_Helpers::setting_int( 'accounts_auto_decline_days', 0 );

		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = self::local_mysql( self::local_ts() - ( $days * DAY_IN_SECONDS ) );

		$query = new WP_User_Query(
			array(
				'number'     => 25,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => CES_Accounts_Status::META_STATUS,
						'value'   => array( 'onboarding', 'awaiting_parent' ),
						'compare' => 'IN',
					),
					array(
						'key'     => CES_Accounts_Status::META_STATUS_AT,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		$ids = (array) $query->get_results();

		foreach ( $ids as $user_id ) {
			CES_Accounts_Status::decline(
				(int) $user_id,
				sprintf(
					/* translators: %d: number of days. */
					__( 'No parent confirmation was received within %d days.', 'classic-edu-suite' ),
					$days
				),
				array( 'actor_id' => 0, 'channel' => 'cron' )
			);
		}

		return count( $ids );
	}
}
