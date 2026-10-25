<?php
/**
 * The Educational Consultant record.
 *
 * A consultant is a tutor from outside the school who uses this site to host
 * their own video lessons and share them with their own students. They apply,
 * a member of staff approves, and they get the Studio.
 *
 * Approval adds the `ces_consultant` role **alongside** whatever the account
 * already had, so a student who is also a tutor keeps their own progress,
 * coins and classes. Declining or suspending takes the role away again and
 * leaves their classes in place but closed.
 *
 * Why it is gated at all: a consultant can upload video that students watch,
 * run a chat room, and message a roster of children. That is not something to
 * hand out on a form submission, which is why `classroom_auto_approve` is off
 * by default and the application asks who they are.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consultant applications, profiles and limits.
 */
class CES_Consultant {

	/**
	 * The role granted on approval.
	 */
	const ROLE = 'ces_consultant';

	/**
	 * The capability everything in the Studio checks.
	 */
	const CAP = 'ces_consultant';

	/**
	 * `pending` | `approved` | `declined` | `suspended`.
	 */
	const META_STATUS = 'ces_consultant_status';

	/**
	 * When the status last changed.
	 */
	const META_STATUS_AT = 'ces_consultant_status_at';

	/**
	 * Public display name, which may differ from the WordPress one.
	 */
	const META_NAME = 'ces_consultant_name';

	/**
	 * Short public biography.
	 */
	const META_BIO = 'ces_consultant_bio';

	/**
	 * Subjects they teach, free text.
	 */
	const META_SUBJECTS = 'ces_consultant_subjects';

	/**
	 * School, centre or company, if any.
	 */
	const META_ORG = 'ces_consultant_org';

	/**
	 * Contact number, staff-only.
	 */
	const META_PHONE = 'ces_consultant_phone';

	/**
	 * Website or social link.
	 */
	const META_SITE = 'ces_consultant_site';

	/**
	 * What they wrote about their experience, staff-only.
	 */
	const META_EXPERIENCE = 'ces_consultant_experience';

	/**
	 * Why an application was refused.
	 */
	const META_REASON = 'ces_consultant_reason';

	/**
	 * Per-consultant override of the class limit.
	 */
	const META_CLASS_LIMIT = 'ces_consultant_class_limit';

	/**
	 * Every status with a label.
	 *
	 * @return array Map of slug => label.
	 */
	public static function statuses() {
		return array(
			'pending'   => __( 'Waiting for review', 'classic-edu-suite' ),
			'approved'  => __( 'Approved', 'classic-edu-suite' ),
			'declined'  => __( 'Not approved', 'classic-edu-suite' ),
			'suspended' => __( 'Suspended', 'classic-edu-suite' ),
		);
	}

	/**
	 * Label for one status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function label( $status ) {
		$all = self::statuses();

		return isset( $all[ $status ] ) ? $all[ $status ] : '';
	}

	/**
	 * The stored application status, '' when they have never applied.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function status( $user_id ) {
		$status = (string) get_user_meta( absint( $user_id ), self::META_STATUS, true );

		return array_key_exists( $status, self::statuses() ) ? $status : '';
	}

	/**
	 * Whether this account may use the Studio.
	 *
	 * Capability first, so an administrator or instructor is a consultant
	 * without having to apply to themselves.
	 *
	 * @param int $user_id Optional user, defaults to the current one.
	 * @return bool
	 */
	public static function is_consultant( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'ces_manage_classroom' ) ) {
			return true;
		}

		if ( 'suspended' === self::status( $user_id ) ) {
			return false;
		}

		return user_can( $user_id, self::CAP );
	}

	/**
	 * Whether this account is staff rather than an outside tutor.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_staff( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		return $user_id && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'ces_manage_classroom' ) );
	}

	/**
	 * Whether applications are being accepted.
	 *
	 * @return bool
	 */
	public static function applications_open() {
		return CES_Helpers::enabled( 'classroom_enabled' ) && CES_Helpers::enabled( 'classroom_applications_open' );
	}

	/* ---------------------------------------------------------------------
	 * Applying.
	 * ------------------------------------------------------------------ */

	/**
	 * Validate an application.
	 *
	 * @param array $input Raw input.
	 * @return array Field => message. Empty when valid.
	 */
	public static function validate( array $input ) {
		$errors = array();

		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		$bio  = isset( $input['bio'] ) ? trim( (string) $input['bio'] ) : '';

		if ( strlen( $name ) < 3 ) {
			$errors['name'] = __( 'Please give the name your students will see.', 'classic-edu-suite' );
		}

		if ( strlen( $name ) > 80 ) {
			$errors['name'] = __( 'That name is too long.', 'classic-edu-suite' );
		}

		if ( strlen( $bio ) < 40 ) {
			$errors['bio'] = __( 'Tell us a little more — at least a couple of sentences about what you teach and to whom.', 'classic-edu-suite' );
		}

		if ( strlen( $bio ) > 1200 ) {
			$errors['bio'] = __( 'Please keep the description under 1,200 characters.', 'classic-edu-suite' );
		}

		if ( '' === trim( (string) ( isset( $input['subjects'] ) ? $input['subjects'] : '' ) ) ) {
			$errors['subjects'] = __( 'Which subjects do you teach?', 'classic-edu-suite' );
		}

		$phone = isset( $input['phone'] ) ? (string) $input['phone'] : '';

		if ( '' === trim( $phone ) ) {
			$errors['phone'] = __( 'A phone number lets us reach you about your account.', 'classic-edu-suite' );
		} elseif ( class_exists( 'CES_Parent_Contact' ) && '' === CES_Parent_Contact::normalize_phone( $phone ) ) {
			$errors['phone'] = __( 'That does not look like a working phone number.', 'classic-edu-suite' );
		}

		$site = isset( $input['site'] ) ? trim( (string) $input['site'] ) : '';

		if ( '' !== $site && ! wp_http_validate_url( $site ) ) {
			$errors['site'] = __( 'That link does not look right. Include the https:// at the front, or leave it empty.', 'classic-edu-suite' );
		}

		if ( empty( $input['terms'] ) ) {
			$errors['terms'] = __( 'Please agree to the rules for consultants.', 'classic-edu-suite' );
		}

		return $errors;
	}

	/**
	 * Record an application.
	 *
	 * @param int   $user_id Applicant.
	 * @param array $input   Validated-or-not input; validation is re-run here.
	 * @return true|WP_Error
	 */
	public static function apply( $user_id, array $input ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'ces_consultant_user', __( 'That account could not be found.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		if ( ! self::applications_open() ) {
			return new WP_Error( 'ces_consultant_closed', __( 'Consultant applications are closed at the moment.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		if ( 'pending' === self::status( $user_id ) ) {
			return new WP_Error( 'ces_consultant_pending', __( 'Your application is already with us. We will be in touch.', 'classic-edu-suite' ), array( 'status' => 409 ) );
		}

		$errors = self::validate( $input );

		if ( ! empty( $errors ) ) {
			$error = new WP_Error();

			foreach ( $errors as $field => $message ) {
				$error->add( 'ces_consultant_' . $field, $message, array( 'status' => 400 ) );
			}

			return $error;
		}

		update_user_meta( $user_id, self::META_NAME, sanitize_text_field( (string) $input['name'] ) );
		update_user_meta( $user_id, self::META_BIO, sanitize_textarea_field( (string) $input['bio'] ) );
		update_user_meta( $user_id, self::META_SUBJECTS, sanitize_text_field( (string) $input['subjects'] ) );
		update_user_meta( $user_id, self::META_ORG, sanitize_text_field( (string) ( isset( $input['org'] ) ? $input['org'] : '' ) ) );
		update_user_meta( $user_id, self::META_EXPERIENCE, sanitize_textarea_field( (string) ( isset( $input['experience'] ) ? $input['experience'] : '' ) ) );

		$phone = (string) $input['phone'];

		if ( class_exists( 'CES_Parent_Contact' ) ) {
			$normalised = CES_Parent_Contact::normalize_phone( $phone );
			$phone      = '' !== $normalised ? $normalised : $phone;
		}

		update_user_meta( $user_id, self::META_PHONE, sanitize_text_field( $phone ) );

		$site = isset( $input['site'] ) ? esc_url_raw( (string) $input['site'] ) : '';

		if ( '' !== $site ) {
			update_user_meta( $user_id, self::META_SITE, $site );
		}

		self::set_status( $user_id, 'pending', array( 'actor_id' => $user_id ) );

		self::notify_staff( $user_id );

		if ( CES_Helpers::enabled( 'classroom_auto_approve' ) ) {
			self::approve( $user_id, 0 );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Decisions.
	 * ------------------------------------------------------------------ */

	/**
	 * Write a status and log it.
	 *
	 * @param int    $user_id User.
	 * @param string $status  New status.
	 * @param array  $args    Optional. `actor_id`, `detail`.
	 * @return void
	 */
	public static function set_status( $user_id, $status, array $args = array() ) {
		$user_id = absint( $user_id );
		$status  = sanitize_key( (string) $status );

		if ( ! $user_id || ! array_key_exists( $status, self::statuses() ) ) {
			return;
		}

		update_user_meta( $user_id, self::META_STATUS, $status );
		update_user_meta( $user_id, self::META_STATUS_AT, CES_Helpers::now() );

		delete_transient( 'ces_consultant_counts' );

		// The accounts module owns the one audit trail in the plugin, and a
		// consultant decision belongs in it next to everything else about
		// that person.
		if ( class_exists( 'CES_Accounts_Status' ) ) {
			CES_Accounts_Status::log(
				$user_id,
				'consultant_' . $status,
				array(
					'actor_id' => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
					'channel'  => 'classroom',
					'detail'   => isset( $args['detail'] ) ? (string) $args['detail'] : '',
				)
			);
		}

		/**
		 * Fires when a consultant application changes status.
		 *
		 * @param int    $user_id Consultant.
		 * @param string $status  New status.
		 */
		do_action( 'ces_consultant_status_changed', $user_id, $status );
	}

	/**
	 * Approve an applicant and hand them the role.
	 *
	 * @param int $user_id  Applicant.
	 * @param int $actor_id Member of staff, 0 for an automatic approval.
	 * @return bool
	 */
	public static function approve( $user_id, $actor_id = null ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		// add_role() keeps whatever they already were, which matters: a
		// student who also tutors should not lose their own account.
		if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->add_role( self::ROLE );
		}

		self::set_status(
			$user_id,
			'approved',
			array( 'actor_id' => null === $actor_id ? get_current_user_id() : (int) $actor_id )
		);

		delete_user_meta( $user_id, self::META_REASON );

		self::notify_applicant( $user_id, 'approved' );

		/**
		 * Fires when a consultant is approved.
		 *
		 * @param int $user_id Consultant.
		 */
		do_action( 'ces_consultant_approved', $user_id );

		return true;
	}

	/**
	 * Refuse an application, or withdraw an approval.
	 *
	 * @param int    $user_id  Applicant.
	 * @param string $reason   Shown to them.
	 * @param string $status   `declined` or `suspended`.
	 * @param int    $actor_id Member of staff.
	 * @return bool
	 */
	public static function refuse( $user_id, $reason = '', $status = 'declined', $actor_id = null ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$status = 'suspended' === $status ? 'suspended' : 'declined';
		$reason = trim( sanitize_text_field( (string) $reason ) );

		if ( in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->remove_role( self::ROLE );

			// Never leave an account with no role at all, or they lose the
			// dashboard along with the Studio.
			if ( empty( $user->roles ) ) {
				$user->add_role( (string) get_option( 'default_role', 'subscriber' ) );
			}
		}

		if ( '' !== $reason ) {
			update_user_meta( $user_id, self::META_REASON, $reason );
		}

		self::set_status(
			$user_id,
			$status,
			array(
				'actor_id' => null === $actor_id ? get_current_user_id() : (int) $actor_id,
				'detail'   => $reason,
			)
		);

		// Their classes stay, but close to new students and to the chat.
		CES_Classroom_Enrolment::flush_counts();

		self::notify_applicant( $user_id, $status, $reason );

		/**
		 * Fires when a consultant is refused or suspended.
		 *
		 * @param int    $user_id Consultant.
		 * @param string $status  `declined` or `suspended`.
		 * @param string $reason  Reason given.
		 */
		do_action( 'ces_consultant_refused', $user_id, $status, $reason );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Reading a consultant.
	 * ------------------------------------------------------------------ */

	/**
	 * The public-facing profile, plus the staff-only fields.
	 *
	 * @param int  $user_id   Consultant.
	 * @param bool $with_private Include the phone number and application text.
	 * @return array
	 */
	public static function profile( $user_id, $with_private = false ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );

		$name = (string) get_user_meta( $user_id, self::META_NAME, true );

		if ( '' === trim( $name ) ) {
			$name = $user ? $user->display_name : '';
		}

		$profile = array(
			'user_id'    => $user_id,
			'name'       => $name,
			'bio'        => (string) get_user_meta( $user_id, self::META_BIO, true ),
			'subjects'   => (string) get_user_meta( $user_id, self::META_SUBJECTS, true ),
			'org'        => (string) get_user_meta( $user_id, self::META_ORG, true ),
			'site'       => (string) get_user_meta( $user_id, self::META_SITE, true ),
			'status'     => self::status( $user_id ),
			'status_at'  => (string) get_user_meta( $user_id, self::META_STATUS_AT, true ),
			'avatar'     => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			'email'      => $user ? $user->user_email : '',
			'registered' => $user ? $user->user_registered : '',
		);

		$profile['status_label'] = self::label( $profile['status'] );

		if ( $with_private ) {
			$profile['phone']      = (string) get_user_meta( $user_id, self::META_PHONE, true );
			$profile['experience'] = (string) get_user_meta( $user_id, self::META_EXPERIENCE, true );
			$profile['reason']     = (string) get_user_meta( $user_id, self::META_REASON, true );
		}

		return $profile;
	}

	/**
	 * How many classes this consultant may run.
	 *
	 * @param int $user_id Consultant.
	 * @return int
	 */
	public static function class_limit( $user_id ) {
		if ( self::is_staff( $user_id ) ) {
			return 1000;
		}

		$override = get_user_meta( absint( $user_id ), self::META_CLASS_LIMIT, true );

		if ( '' !== $override && null !== $override ) {
			return max( 1, (int) $override );
		}

		return max( 1, CES_Helpers::setting_int( 'classroom_max_classes', 10 ) );
	}

	/**
	 * Whether they can create another class.
	 *
	 * @param int $user_id Consultant.
	 * @return bool
	 */
	public static function can_add_class( $user_id ) {
		return count( CES_Classroom_Cpt::for_consultant( $user_id ) ) < self::class_limit( $user_id );
	}

	/**
	 * Headline numbers for one consultant.
	 *
	 * @param int $user_id Consultant.
	 * @return array {
	 *     @type int $classes
	 *     @type int $students
	 *     @type int $lessons
	 *     @type int $minutes
	 * }
	 */
	public static function stats( $user_id ) {
		$user_id = absint( $user_id );
		$classes = CES_Classroom_Cpt::for_consultant( $user_id );

		$students = 0;
		$lessons  = 0;
		$minutes  = 0;

		foreach ( $classes as $class_id ) {
			$students += CES_Classroom_Enrolment::count( $class_id );
			$lessons  += count( CES_Classroom_Cpt::lessons( $class_id ) );
			$minutes  += CES_Classroom_Enrolment::minutes( $class_id );
		}

		return array(
			'classes'  => count( $classes ),
			'students' => $students,
			'lessons'  => $lessons,
			'minutes'  => $minutes,
		);
	}

	/**
	 * Approved consultants, for the public directory.
	 *
	 * @param array $args Optional. `search`, `number`, `offset`.
	 * @return array `array( 'users' => WP_User[], 'total' => int )`
	 */
	public static function directory( array $args = array() ) {
		$args = array_merge(
			array(
				'search' => '',
				'number' => 24,
				'offset' => 0,
			),
			$args
		);

		$query_args = array(
			'role'        => self::ROLE,
			'number'      => max( 1, min( 100, (int) $args['number'] ) ),
			'offset'      => max( 0, (int) $args['offset'] ),
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'count_total' => true,
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::META_STATUS,
					'value' => 'approved',
				),
			),
		);

		$search = trim( (string) $args['search'] );

		if ( '' !== $search ) {
			$query_args['search']         = '*' . esc_attr( $search ) . '*';
			$query_args['search_columns'] = array( 'display_name', 'user_nicename' );
		}

		$query = new WP_User_Query( $query_args );

		return array(
			'users' => (array) $query->get_results(),
			'total' => (int) $query->get_total(),
		);
	}

	/**
	 * Applications waiting for review, and the other counts.
	 *
	 * @return array Map of status => count.
	 */
	public static function counts() {
		$cached = get_transient( 'ces_consultant_counts' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$out = array();

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

		foreach ( (array) $rows as $row ) {
			$slug = sanitize_key( (string) $row['status'] );

			if ( isset( $out[ $slug ] ) ) {
				$out[ $slug ] = (int) $row['total'];
			}
		}

		set_transient( 'ces_consultant_counts', $out, 5 * MINUTE_IN_SECONDS );

		return $out;
	}

	/**
	 * Applicants matching a status, for the admin screen.
	 *
	 * @param string $status Status slug, or 'all'.
	 * @param int    $number Page size.
	 * @param int    $offset Offset.
	 * @return array `array( 'users' => WP_User[], 'total' => int )`
	 */
	public static function query( $status = 'pending', $number = 25, $offset = 0 ) {
		$status = sanitize_key( (string) $status );

		$meta = array(
			array(
				'key'     => self::META_STATUS,
				'compare' => 'EXISTS',
			),
		);

		if ( 'all' !== $status && array_key_exists( $status, self::statuses() ) ) {
			$meta = array(
				array(
					'key'   => self::META_STATUS,
					'value' => $status,
				),
			);
		}

		$query = new WP_User_Query(
			array(
				'number'      => max( 1, min( 200, (int) $number ) ),
				'offset'      => max( 0, (int) $offset ),
				'orderby'     => 'registered',
				'order'       => 'DESC',
				'count_total' => true,
				'meta_query'  => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		return array(
			'users' => (array) $query->get_results(),
			'total' => (int) $query->get_total(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Messages.
	 * ------------------------------------------------------------------ */

	/**
	 * Tell staff an application has arrived.
	 *
	 * @param int $user_id Applicant.
	 * @return void
	 */
	private static function notify_staff( $user_id ) {
		if ( ! class_exists( 'CES_Accounts_Notify' ) ) {
			return;
		}

		$profile = self::profile( $user_id, true );

		$body  = '<p>' . esc_html__( 'Someone has applied to host classes on the site.', 'classic-edu-suite' ) . '</p>';
		$body .= '<table cellpadding="6" style="border-collapse:collapse;font-size:14px;">';
		$body .= '<tr><td><strong>' . esc_html__( 'Name', 'classic-edu-suite' ) . '</strong></td><td>' . esc_html( $profile['name'] ) . '</td></tr>';
		$body .= '<tr><td><strong>' . esc_html__( 'Email', 'classic-edu-suite' ) . '</strong></td><td>' . esc_html( $profile['email'] ) . '</td></tr>';
		$body .= '<tr><td><strong>' . esc_html__( 'Phone', 'classic-edu-suite' ) . '</strong></td><td>' . esc_html( $profile['phone'] ) . '</td></tr>';
		$body .= '<tr><td><strong>' . esc_html__( 'Subjects', 'classic-edu-suite' ) . '</strong></td><td>' . esc_html( $profile['subjects'] ) . '</td></tr>';
		$body .= '<tr><td><strong>' . esc_html__( 'Organisation', 'classic-edu-suite' ) . '</strong></td><td>' . esc_html( $profile['org'] ) . '</td></tr>';
		$body .= '</table>';
		$body .= '<p>' . esc_html( $profile['bio'] ) . '</p>';
		$body .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=ces-consultants' ) ) . '">' . esc_html__( 'Review the application', 'classic-edu-suite' ) . '</a></p>';

		CES_Accounts_Notify::email(
			CES_Accounts_Notify::admin_email(),
			sprintf(
				/* translators: %s: applicant name. */
				__( 'Consultant application: %s', 'classic-edu-suite' ),
				$profile['name']
			),
			CES_Accounts_Notify::wrap_email( __( 'New consultant application', 'classic-edu-suite' ), $body )
		);
	}

	/**
	 * Tell the applicant what was decided.
	 *
	 * @param int    $user_id Applicant.
	 * @param string $status  New status.
	 * @param string $reason  Reason, when refused.
	 * @return void
	 */
	private static function notify_applicant( $user_id, $status, $reason = '' ) {
		if ( ! class_exists( 'CES_Accounts_Notify' ) ) {
			return;
		}

		$user = get_userdata( absint( $user_id ) );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		if ( 'approved' === $status ) {
			$body  = '<p>' . esc_html__( 'Your consultant account is open. You can create your first class and upload a lesson now.', 'classic-edu-suite' ) . '</p>';
			$body .= '<p>' . esc_html__( 'Everything lives in the Studio tab on your dashboard: make a class, record or upload the video, then share the join link with your students.', 'classic-edu-suite' ) . '</p>';
			$body .= '<p style="margin:28px 0;"><a href="' . esc_url( CES_Helpers::dashboard_url( CES_Classroom_Studio::SLUG ) ) . '" style="background:#2c62cd;color:#ffffff;padding:13px 22px;border-radius:999px;text-decoration:none;font-weight:600;display:inline-block;">'
				. esc_html__( 'Open my Studio', 'classic-edu-suite' ) . '</a></p>';

			CES_Accounts_Notify::email(
				$user->user_email,
				__( 'You are approved to host classes', 'classic-edu-suite' ),
				CES_Accounts_Notify::wrap_email( __( 'Welcome aboard', 'classic-edu-suite' ), $body )
			);

			return;
		}

		$heading = 'suspended' === $status
			? __( 'Your consultant account is paused', 'classic-edu-suite' )
			: __( 'About your consultant application', 'classic-edu-suite' );

		$body = '<p>' . esc_html(
			'suspended' === $status
				? __( 'Your consultant account has been paused, so your classes are closed for now.', 'classic-edu-suite' )
				: __( 'We are not able to approve your consultant application at the moment.', 'classic-edu-suite' )
		) . '</p>';

		if ( '' !== trim( $reason ) ) {
			$body .= '<p><em>' . esc_html( $reason ) . '</em></p>';
		}

		$body .= '<p>' . esc_html__( 'If you think this is a mistake, reply to this email and a person will look at it.', 'classic-edu-suite' ) . '</p>';

		CES_Accounts_Notify::email(
			$user->user_email,
			$heading,
			CES_Accounts_Notify::wrap_email( $heading, $body )
		);
	}
}
