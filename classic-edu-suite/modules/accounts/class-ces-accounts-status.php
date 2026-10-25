<?php
/**
 * Where a student sits in the approval process, and the audit trail.
 *
 * One status per account, held in user meta so it travels with the user and
 * WordPress removes it when the account is deleted. Every change is also
 * written to the `account_events` table, which is append-only: who signed up,
 * which parent approved them, which member of staff let them in and when. A
 * school needs to be able to answer those questions a year later.
 *
 * The status ladder:
 *
 *   onboarding      Account exists, no parent details given yet.
 *   awaiting_parent Request sent to the parent, nobody has answered.
 *   awaiting_admin  Parent approved; staff review is switched on.
 *   approved        Full access.
 *   declined        The parent said no, or staff refused.
 *   suspended       Access withdrawn after approval.
 *
 * Staff are never in the ladder: anyone who can manage part of the plugin is
 * always treated as approved, so an administrator cannot lock themselves out
 * of their own site by turning the gate on.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Account status machine and event log.
 */
class CES_Accounts_Status {

	/**
	 * Current status slug.
	 */
	const META_STATUS = 'ces_account_status';

	/**
	 * When the status last changed, MySQL datetime.
	 */
	const META_STATUS_AT = 'ces_account_status_at';

	/**
	 * When the account was approved.
	 */
	const META_APPROVED_AT = 'ces_account_approved_at';

	/**
	 * User ID of the member of staff who approved, or 0 for a parent.
	 */
	const META_APPROVED_BY = 'ces_account_approved_by';

	/**
	 * Why an account was declined, shown back to the student.
	 */
	const META_DECLINE_REASON = 'ces_account_decline_reason';

	/**
	 * When the parent confirmed, MySQL datetime.
	 */
	const META_PARENT_VERIFIED_AT = 'ces_parent_verified_at';

	/**
	 * Which channel the parent confirmed through.
	 */
	const META_PARENT_CHANNEL = 'ces_parent_verified_channel';

	/**
	 * Review flags, an array of slugs.
	 */
	const META_FLAGS = 'ces_account_flags';

	/**
	 * Class or level the student is in.
	 */
	const META_CLASS = 'ces_student_class';

	/**
	 * Date of birth, Y-m-d, only when the setting asks for it.
	 */
	const META_DOB = 'ces_student_dob';

	/**
	 * How the account signs in: google | password.
	 */
	const META_PROVIDER = 'ces_login_provider';

	/**
	 * Option recording when this module first ran on the site.
	 */
	const OPTION_INSTALLED = 'ces_accounts_installed_at';

	/**
	 * Cached status for the current request, keyed by user ID.
	 *
	 * @var array<int,string>
	 */
	private static $cache = array();

	/**
	 * Every status slug with a student-facing label.
	 *
	 * @return array Map of slug => label.
	 */
	public static function statuses() {
		return array(
			'onboarding'      => __( 'Needs parent details', 'classic-edu-suite' ),
			'awaiting_parent' => __( 'Waiting for the parent', 'classic-edu-suite' ),
			'awaiting_admin'  => __( 'Waiting for staff review', 'classic-edu-suite' ),
			'approved'        => __( 'Approved', 'classic-edu-suite' ),
			'declined'        => __( 'Declined', 'classic-edu-suite' ),
			'suspended'       => __( 'Suspended', 'classic-edu-suite' ),
		);
	}

	/**
	 * Human label for one status slug.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function label( $status ) {
		$all = self::statuses();

		return isset( $all[ $status ] ) ? $all[ $status ] : $status;
	}

	/**
	 * The class and level options offered at onboarding.
	 *
	 * @return array Map of slug => label.
	 */
	public static function class_options() {
		$options = array(
			'jss1'      => __( 'JSS 1', 'classic-edu-suite' ),
			'jss2'      => __( 'JSS 2', 'classic-edu-suite' ),
			'jss3'      => __( 'JSS 3 (BECE)', 'classic-edu-suite' ),
			'ss1'       => __( 'SS 1', 'classic-edu-suite' ),
			'ss2'       => __( 'SS 2', 'classic-edu-suite' ),
			'ss3'       => __( 'SS 3 (WAEC / NECO / UTME)', 'classic-edu-suite' ),
			'post_utme' => __( 'Post-UTME / awaiting admission', 'classic-edu-suite' ),
			'undergrad' => __( 'Undergraduate', 'classic-edu-suite' ),
			'other'     => __( 'Other', 'classic-edu-suite' ),
		);

		/**
		 * Filter the class / level list shown at onboarding.
		 *
		 * @param array $options Slug => label.
		 */
		return (array) apply_filters( 'ces_accounts_class_options', $options );
	}

	/**
	 * Coerce a class value to a known slug.
	 *
	 * @param string $value Raw value.
	 * @return string Slug, or '' when unrecognised.
	 */
	public static function sanitize_class( $value ) {
		$value = sanitize_key( (string) $value );

		return array_key_exists( $value, self::class_options() ) ? $value : '';
	}

	/**
	 * Label for a stored class slug.
	 *
	 * @param string $slug Class slug.
	 * @return string
	 */
	public static function class_label( $slug ) {
		$options = self::class_options();

		return isset( $options[ $slug ] ) ? $options[ $slug ] : '';
	}

	/**
	 * Whether the approval gate is switched on at all.
	 *
	 * @return bool
	 */
	public static function approval_enabled() {
		return CES_Helpers::enabled( 'accounts_approval_enabled' );
	}

	/**
	 * Whether this account belongs to a member of staff.
	 *
	 * Checked by capability rather than by role, so a custom role with any
	 * `ces_manage_*` capability is also exempt.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_staff( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		// Deliberately not `edit_posts`: a contributor or a membership plugin
		// can hand that to students, and it would be a way past the gate.
		// `edit_others_posts` is an editor and above, which students never are.
		foreach ( array( 'manage_options', 'edit_others_posts', 'ces_manage', 'ces_manage_cbt', 'ces_manage_accounts', 'ces_view_reports' ) as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * When this module first ran here, as a Unix timestamp.
	 *
	 * Accounts that existed before that moment are grandfathered in: a school
	 * switching this on mid-term must not lock out every student it already
	 * has. Switch `accounts_grandfather_existing` off to put them through the
	 * process as well.
	 *
	 * @return int
	 */
	public static function installed_at() {
		$stamp = get_option( self::OPTION_INSTALLED );

		if ( ! $stamp ) {
			$stamp = time();
			update_option( self::OPTION_INSTALLED, $stamp, false );
		}

		return (int) $stamp;
	}

	/**
	 * The effective status of one account.
	 *
	 * @param int  $user_id User. Defaults to the current user.
	 * @param bool $persist Whether a derived status may be written back.
	 *                      Only ever true for the user being served.
	 * @return string Status slug.
	 */
	public static function status( $user_id = 0, $persist = false ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $user_id ) {
			return 'onboarding';
		}

		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}

		$status = self::resolve( $user_id, $persist );

		self::$cache[ $user_id ] = $status;

		return $status;
	}

	/**
	 * Work out the status without the request cache.
	 *
	 * @param int  $user_id User.
	 * @param bool $persist Whether a derived status may be written back.
	 * @return string
	 */
	private static function resolve( $user_id, $persist = false ) {
		if ( self::is_staff( $user_id ) ) {
			return 'approved';
		}

		if ( ! self::approval_enabled() ) {
			return 'approved';
		}

		$stored = (string) get_user_meta( $user_id, self::META_STATUS, true );

		if ( '' !== $stored && array_key_exists( $stored, self::statuses() ) ) {
			return $stored;
		}

		// No record. Either an account from before this module was installed,
		// or one created by a route that did not set a status.
		$derived = 'onboarding';

		if ( CES_Helpers::enabled( 'accounts_grandfather_existing' ) ) {
			$user = get_userdata( $user_id );

			if ( $user ) {
				$registered = strtotime( (string) $user->user_registered );

				if ( $registered && $registered < self::installed_at() ) {
					$derived = 'approved';
				}
			}
		}

		if ( $persist ) {
			update_user_meta( $user_id, self::META_STATUS, $derived );
			update_user_meta( $user_id, self::META_STATUS_AT, CES_Helpers::now() );

			if ( 'approved' === $derived ) {
				update_user_meta( $user_id, self::META_APPROVED_AT, CES_Helpers::now() );
				update_user_meta( $user_id, self::META_APPROVED_BY, 0 );
				self::log( $user_id, 'grandfathered', array( 'detail' => __( 'Account existed before parent approval was switched on.', 'classic-edu-suite' ) ) );
			}
		}

		return $derived;
	}

	/**
	 * The status exactly as stored, with no staff or grandfather handling.
	 *
	 * Used by the admin screen, which must show what is really on file.
	 *
	 * @param int $user_id User.
	 * @return string Status slug, or '' when nothing is recorded.
	 */
	public static function raw_status( $user_id ) {
		$stored = (string) get_user_meta( absint( $user_id ), self::META_STATUS, true );

		return array_key_exists( $stored, self::statuses() ) ? $stored : '';
	}

	/**
	 * Whether this account has full access.
	 *
	 * @param int $user_id User. Defaults to the current user.
	 * @return bool
	 */
	public static function is_approved( $user_id = 0 ) {
		return 'approved' === self::status( $user_id );
	}

	/**
	 * Whether the student still has to give their parent's details.
	 *
	 * @param int $user_id User. Defaults to the current user.
	 * @return bool
	 */
	public static function needs_onboarding( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $user_id || ! self::approval_enabled() || self::is_staff( $user_id ) ) {
			return false;
		}

		if ( 'onboarding' !== self::status( $user_id ) ) {
			return false;
		}

		return ! CES_Parent_Contact::has_number( $user_id );
	}

	/**
	 * Whether a brand new student is still inside the look-around window.
	 *
	 * Gives a student a few minutes to see what the site is before being
	 * asked for a number — worth it, because a signup form is where most
	 * students give up. Zero minutes, the default, disables it.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function in_grace( $user_id ) {
		$minutes = CES_Helpers::setting_int( 'accounts_grace_minutes', 0 );

		if ( $minutes <= 0 ) {
			return false;
		}

		$user = get_userdata( absint( $user_id ) );

		if ( ! $user ) {
			return false;
		}

		$registered = strtotime( (string) $user->user_registered );

		if ( ! $registered ) {
			return false;
		}

		return ( time() - $registered ) < ( $minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Write a status, log it, and clear the cached counts.
	 *
	 * @param int    $user_id User.
	 * @param string $status  New status slug.
	 * @param array  $args    Optional. `actor_id`, `detail`, `channel`, `event`.
	 * @return bool True when the status changed.
	 */
	public static function set_status( $user_id, $status, array $args = array() ) {
		$user_id = absint( $user_id );
		$status  = sanitize_key( (string) $status );

		if ( ! $user_id || ! array_key_exists( $status, self::statuses() ) ) {
			return false;
		}

		$before = self::raw_status( $user_id );

		update_user_meta( $user_id, self::META_STATUS, $status );
		update_user_meta( $user_id, self::META_STATUS_AT, CES_Helpers::now() );

		unset( self::$cache[ $user_id ] );
		self::flush_counts();

		$event = isset( $args['event'] ) ? sanitize_key( (string) $args['event'] ) : 'status_' . $status;

		self::log(
			$user_id,
			$event,
			array(
				'actor_id' => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
				'channel'  => isset( $args['channel'] ) ? (string) $args['channel'] : '',
				'detail'   => isset( $args['detail'] ) ? (string) $args['detail'] : '',
			)
		);

		/**
		 * Fires after a student's account status changes.
		 *
		 * @param int    $user_id Student.
		 * @param string $status  New status.
		 * @param string $before  Previous status, '' when there was none.
		 */
		do_action( 'ces_account_status_changed', $user_id, $status, $before );

		return $status !== $before;
	}

	/**
	 * Record that the parent confirmed, and move the account on.
	 *
	 * Goes straight to approved unless staff review is switched on, in which
	 * case it parks at `awaiting_admin` and the Approvals screen picks it up.
	 *
	 * @param int    $user_id User.
	 * @param string $channel Channel the parent used: whatsapp, email, link, staff.
	 * @return string The status the account landed on.
	 */
	public static function mark_parent_verified( $user_id, $channel = '' ) {
		$user_id = absint( $user_id );
		$channel = sanitize_key( (string) $channel );

		update_user_meta( $user_id, self::META_PARENT_VERIFIED_AT, CES_Helpers::now() );
		update_user_meta( $user_id, self::META_PARENT_CHANNEL, $channel );

		self::log( $user_id, 'parent_verified', array( 'channel' => $channel, 'actor_id' => 0 ) );

		/**
		 * Fires when a parent has confirmed their ward's account.
		 *
		 * @param int    $user_id Student.
		 * @param string $channel Channel used.
		 */
		do_action( 'ces_parent_verified', $user_id, $channel );

		if ( CES_Helpers::enabled( 'accounts_require_admin_review' ) || self::flags( $user_id ) ) {
			self::set_status(
				$user_id,
				'awaiting_admin',
				array(
					'actor_id' => 0,
					'channel'  => $channel,
					'event'    => 'awaiting_admin',
				)
			);

			if ( class_exists( 'CES_Accounts_Notify' ) ) {
				CES_Accounts_Notify::admin_pending( $user_id );
			}

			return 'awaiting_admin';
		}

		self::approve( $user_id, array( 'actor_id' => 0, 'channel' => $channel ) );

		return 'approved';
	}

	/**
	 * Approve an account.
	 *
	 * @param int   $user_id User.
	 * @param array $args    Optional. `actor_id` (0 = the parent), `channel`, `notify`.
	 * @return bool
	 */
	public static function approve( $user_id, array $args = array() ) {
		$user_id  = absint( $user_id );
		$actor_id = isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		update_user_meta( $user_id, self::META_APPROVED_AT, CES_Helpers::now() );
		update_user_meta( $user_id, self::META_APPROVED_BY, $actor_id );
		delete_user_meta( $user_id, self::META_DECLINE_REASON );

		self::set_status(
			$user_id,
			'approved',
			array(
				'actor_id' => $actor_id,
				'channel'  => isset( $args['channel'] ) ? (string) $args['channel'] : '',
				'event'    => 'approved',
			)
		);

		if ( ! isset( $args['notify'] ) || $args['notify'] ) {
			if ( class_exists( 'CES_Accounts_Notify' ) ) {
				CES_Accounts_Notify::student_approved( $user_id );
			}
		}

		/**
		 * Fires when a student gains full access to the site.
		 *
		 * @param int $user_id  Student.
		 * @param int $actor_id Member of staff who approved, or 0 for a parent.
		 */
		do_action( 'ces_student_approved', $user_id, $actor_id );

		return true;
	}

	/**
	 * Decline an account.
	 *
	 * @param int    $user_id User.
	 * @param string $reason  Short reason, shown to the student.
	 * @param array  $args    Optional. `actor_id`, `channel`, `notify`.
	 * @return bool
	 */
	public static function decline( $user_id, $reason = '', array $args = array() ) {
		$user_id  = absint( $user_id );
		$actor_id = isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$reason = trim( sanitize_text_field( (string) $reason ) );

		if ( '' !== $reason ) {
			update_user_meta( $user_id, self::META_DECLINE_REASON, $reason );
		}

		self::set_status(
			$user_id,
			'declined',
			array(
				'actor_id' => $actor_id,
				'channel'  => isset( $args['channel'] ) ? (string) $args['channel'] : '',
				'detail'   => $reason,
				'event'    => 'declined',
			)
		);

		if ( ! isset( $args['notify'] ) || $args['notify'] ) {
			if ( class_exists( 'CES_Accounts_Notify' ) ) {
				CES_Accounts_Notify::student_declined( $user_id, $reason );
			}
		}

		/**
		 * Fires when a student is refused access.
		 *
		 * @param int    $user_id  Student.
		 * @param string $reason   Reason given.
		 * @param int    $actor_id Member of staff, or 0 for a parent.
		 */
		do_action( 'ces_student_declined', $user_id, $reason, $actor_id );

		return true;
	}

	/**
	 * Send an account back to the start of the process.
	 *
	 * Used when a number was mistyped, or a parent asks for the record to be
	 * redone. The parent contact itself is left alone; only the decision is
	 * cleared.
	 *
	 * @param int   $user_id User.
	 * @param array $args    Optional. `actor_id`, `detail`.
	 * @return bool
	 */
	public static function reset( $user_id, array $args = array() ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		foreach ( array( self::META_APPROVED_AT, self::META_APPROVED_BY, self::META_DECLINE_REASON, self::META_PARENT_VERIFIED_AT, self::META_PARENT_CHANNEL ) as $key ) {
			delete_user_meta( $user_id, $key );
		}

		self::clear_flags( $user_id );

		if ( class_exists( 'CES_Accounts_Verification' ) ) {
			CES_Accounts_Verification::cancel_open( $user_id );
		}

		return self::set_status(
			$user_id,
			'onboarding',
			array(
				'actor_id' => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
				'detail'   => isset( $args['detail'] ) ? (string) $args['detail'] : '',
				'event'    => 'reset',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Review flags.
	 * ------------------------------------------------------------------ */

	/**
	 * Every flag slug with an explanation for staff.
	 *
	 * @return array Map of slug => label.
	 */
	public static function flag_labels() {
		return array(
			'duplicate_phone' => __( 'This parent number is already on several accounts', 'classic-edu-suite' ),
			'minor'           => __( 'Student is under age, so a parent must consent', 'classic-edu-suite' ),
			'self_number'     => __( 'The parent number matches the student’s own number', 'classic-edu-suite' ),
			'parent_declined' => __( 'A parent has previously declined on this number', 'classic-edu-suite' ),
			'weak_proof'      => __( 'The student forwarded the request themselves, so ring the parent to be sure', 'classic-edu-suite' ),
			'manual'          => __( 'Held back for review by staff', 'classic-edu-suite' ),
		);
	}

	/**
	 * Flags currently on an account.
	 *
	 * @param int $user_id User.
	 * @return string[] Flag slugs.
	 */
	public static function flags( $user_id ) {
		$flags = get_user_meta( absint( $user_id ), self::META_FLAGS, true );

		if ( ! is_array( $flags ) ) {
			return array();
		}

		return array_values( array_intersect( array_map( 'sanitize_key', $flags ), array_keys( self::flag_labels() ) ) );
	}

	/**
	 * Add a review flag.
	 *
	 * @param int    $user_id User.
	 * @param string $flag    Flag slug.
	 * @param string $detail  Optional note for the log.
	 * @return void
	 */
	public static function add_flag( $user_id, $flag, $detail = '' ) {
		$user_id = absint( $user_id );
		$flag    = sanitize_key( (string) $flag );

		if ( ! $user_id || ! array_key_exists( $flag, self::flag_labels() ) ) {
			return;
		}

		$flags = self::flags( $user_id );

		if ( in_array( $flag, $flags, true ) ) {
			return;
		}

		$flags[] = $flag;

		update_user_meta( $user_id, self::META_FLAGS, $flags );
		self::flush_counts();
		self::log( $user_id, 'flagged', array( 'detail' => $detail ? $detail : $flag ) );
	}

	/**
	 * Remove every flag from an account.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function clear_flags( $user_id ) {
		delete_user_meta( absint( $user_id ), self::META_FLAGS );
		self::flush_counts();
	}

	/* ---------------------------------------------------------------------
	 * Event log.
	 * ------------------------------------------------------------------ */

	/**
	 * Append one line to the audit trail.
	 *
	 * Never throws and never blocks the caller: a missing table must not stop
	 * a student signing up.
	 *
	 * @param int    $user_id User the event is about.
	 * @param string $event   Short event slug.
	 * @param array  $args    Optional. `actor_id`, `channel`, `detail`.
	 * @return void
	 */
	public static function log( $user_id, $event, array $args = array() ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$event   = substr( sanitize_key( (string) $event ), 0, 40 );

		if ( ! $user_id || '' === $event ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CES_Helpers::table( 'account_events' ),
			array(
				'user_id'    => $user_id,
				'actor_id'   => isset( $args['actor_id'] ) ? absint( $args['actor_id'] ) : get_current_user_id(),
				'event'      => $event,
				// Not sanitize_key(): a send can go out on two channels at
				// once and is recorded as "whatsapp,email".
				'channel'    => isset( $args['channel'] ) ? substr( preg_replace( '/[^a-z0-9_,-]/', '', strtolower( (string) $args['channel'] ) ), 0, 20 ) : '',
				'detail'     => isset( $args['detail'] ) ? substr( sanitize_text_field( (string) $args['detail'] ), 0, 255 ) : '',
				'ip_address' => CES_Helpers::ip(),
				'user_agent' => CES_Helpers::user_agent(),
				'created_at' => CES_Helpers::now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * The audit trail for one account, newest first.
	 *
	 * @param int $user_id User.
	 * @param int $limit   How many rows.
	 * @return array[] Rows as associative arrays.
	 */
	public static function events( $user_id, $limit = 30 ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$limit   = max( 1, min( 200, (int) $limit ) );

		if ( ! $user_id ) {
			return array();
		}

		$table = CES_Helpers::table( 'account_events' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Erase the audit trail for one account.
	 *
	 * Only called from the privacy eraser and when an account is deleted.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function delete_events( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		$wpdb->delete( CES_Helpers::table( 'account_events' ), array( 'user_id' => $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ---------------------------------------------------------------------
	 * Counts for the admin screen.
	 * ------------------------------------------------------------------ */

	/**
	 * How many accounts sit in each status.
	 *
	 * Cached for five minutes, because it runs on every admin page load to
	 * draw the count on the menu.
	 *
	 * @return array Map of status slug => count, plus `flagged` and `none`.
	 */
	public static function counts() {
		$cached = get_transient( 'ces_account_counts' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$out = array( 'flagged' => 0, 'none' => 0 );

		foreach ( array_keys( self::statuses() ) as $slug ) {
			$out[ $slug ] = 0;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS status, COUNT(*) AS total
				 FROM {$wpdb->usermeta}
				 WHERE meta_key = %s
				 GROUP BY meta_value",
				self::META_STATUS
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$recorded = 0;

		foreach ( (array) $rows as $row ) {
			$slug = sanitize_key( (string) $row['status'] );

			if ( isset( $out[ $slug ] ) ) {
				$out[ $slug ] = (int) $row['total'];
			}

			$recorded += (int) $row['total'];
		}

		$out['flagged'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
				self::META_FLAGS
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$out['none']    = max( 0, $total - $recorded );
		$out['total']   = $total;
		$out['pending'] = $out['onboarding'] + $out['awaiting_parent'] + $out['awaiting_admin'];

		set_transient( 'ces_account_counts', $out, 5 * MINUTE_IN_SECONDS );

		return $out;
	}

	/**
	 * Drop the cached counts.
	 *
	 * @return void
	 */
	public static function flush_counts() {
		delete_transient( 'ces_account_counts' );
	}

	/**
	 * Everything the student-facing and admin screens need about one account.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function record( $user_id ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );

		$status = self::approval_enabled() ? self::status( $user_id ) : 'approved';

		return array(
			'user_id'        => $user_id,
			'login'          => $user ? $user->user_login : '',
			'email'          => $user ? $user->user_email : '',
			'name'           => $user ? $user->display_name : '',
			'registered'     => $user ? $user->user_registered : '',
			'status'         => $status,
			'status_label'   => self::label( $status ),
			'status_at'      => (string) get_user_meta( $user_id, self::META_STATUS_AT, true ),
			'approved_at'    => (string) get_user_meta( $user_id, self::META_APPROVED_AT, true ),
			'approved_by'    => (int) get_user_meta( $user_id, self::META_APPROVED_BY, true ),
			'decline_reason' => (string) get_user_meta( $user_id, self::META_DECLINE_REASON, true ),
			'verified_at'    => (string) get_user_meta( $user_id, self::META_PARENT_VERIFIED_AT, true ),
			'verified_via'   => (string) get_user_meta( $user_id, self::META_PARENT_CHANNEL, true ),
			'provider'       => (string) get_user_meta( $user_id, self::META_PROVIDER, true ),
			'class'          => (string) get_user_meta( $user_id, self::META_CLASS, true ),
			'class_label'    => self::class_label( (string) get_user_meta( $user_id, self::META_CLASS, true ) ),
			'dob'            => (string) get_user_meta( $user_id, self::META_DOB, true ),
			'flags'          => self::flags( $user_id ),
		);
	}
}
