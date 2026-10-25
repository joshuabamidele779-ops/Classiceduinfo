<?php
/**
 * Activation routine: database schema, roles, capabilities, defaults.
 *
 * This file owns the canonical database schema for every module. Module
 * agents must NOT create their own tables — add columns here instead so a
 * single `dbDelta()` pass stays authoritative.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation and schema upgrades.
 */
class CES_Activator {

	/**
	 * Run on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::add_roles();
		self::set_defaults();
		self::create_protected_storage();

		update_option( 'ces_db_version', CES_DB_VERSION );
		update_option( 'ces_activated_at', current_time( 'mysql' ) );

		// Custom post types are registered on `init`, which has not run during
		// activation, so defer the rewrite flush to the next page load.
		update_option( 'ces_flush_rewrite', 1 );
	}

	/**
	 * Run the schema through dbDelta. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix . 'ces_';

		$schema = array();

		// ---------------------------------------------------------------
		// CBT: one row per exam sitting.
		// ---------------------------------------------------------------
		$schema[] = "CREATE TABLE {$prefix}attempts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			exam_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'in_progress',
			question_ids longtext NULL,
			total_questions smallint(5) unsigned NOT NULL DEFAULT 0,
			correct_count smallint(5) unsigned NOT NULL DEFAULT 0,
			score decimal(6,2) NOT NULL DEFAULT 0.00,
			passed tinyint(1) NOT NULL DEFAULT 0,
			duration_limit int(10) unsigned NOT NULL DEFAULT 0,
			time_spent int(10) unsigned NOT NULL DEFAULT 0,
			started_at datetime NULL,
			expires_at datetime NULL,
			submitted_at datetime NULL,
			ip_address varchar(100) NOT NULL DEFAULT '',
			meta longtext NULL,
			PRIMARY KEY  (id),
			KEY user_exam (user_id,exam_id),
			KEY status_idx (status),
			KEY submitted_idx (submitted_at),
			KEY score_idx (exam_id,score)
		) $charset;";

		// ---------------------------------------------------------------
		// CBT: one row per answered question.
		// ---------------------------------------------------------------
		$schema[] = "CREATE TABLE {$prefix}attempt_answers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attempt_id bigint(20) unsigned NOT NULL DEFAULT 0,
			question_id bigint(20) unsigned NOT NULL DEFAULT 0,
			given_answer varchar(255) NOT NULL DEFAULT '',
			is_correct tinyint(1) NOT NULL DEFAULT 0,
			flagged tinyint(1) NOT NULL DEFAULT 0,
			answered_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attempt_question (attempt_id,question_id),
			KEY question_idx (question_id)
		) $charset;";

		// ---------------------------------------------------------------
		// Wallet: append-only coin ledger. Balance is the latest row's
		// balance_after, cached in user meta `ces_coin_balance`.
		// ---------------------------------------------------------------
		$schema[] = "CREATE TABLE {$prefix}wallet_ledger (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			delta int(11) NOT NULL DEFAULT 0,
			balance_after int(11) NOT NULL DEFAULT 0,
			type varchar(40) NOT NULL DEFAULT '',
			ref_type varchar(40) NOT NULL DEFAULT '',
			ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
			idempotency_key varchar(100) NOT NULL DEFAULT '',
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idem (idempotency_key),
			KEY user_created (user_id,created_at),
			KEY type_idx (type)
		) $charset;";

		// ---------------------------------------------------------------
		// Referrals: attribution plus anti-fraud signals.
		// status: pending | qualified | rejected
		// ---------------------------------------------------------------
		$schema[] = "CREATE TABLE {$prefix}referrals (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			referrer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			referred_id bigint(20) unsigned NOT NULL DEFAULT 0,
			code varchar(40) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			reward_paid tinyint(1) NOT NULL DEFAULT 0,
			signup_ip varchar(100) NOT NULL DEFAULT '',
			signup_ua varchar(255) NOT NULL DEFAULT '',
			device_hash varchar(64) NOT NULL DEFAULT '',
			reject_reason varchar(100) NOT NULL DEFAULT '',
			qualified_at datetime NULL,
			created_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY referred (referred_id),
			KEY referrer_status (referrer_id,status),
			KEY code_idx (code),
			KEY ip_idx (signup_ip)
		) $charset;";

		// ---------------------------------------------------------------
		// LMS: per-user watch records, also the coin-unlock record.
		// A row with unlocked_until in the future means access is granted.
		// ---------------------------------------------------------------
		$schema[] = "CREATE TABLE {$prefix}video_views (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			lesson_id bigint(20) unsigned NOT NULL DEFAULT 0,
			course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			access_source varchar(20) NOT NULL DEFAULT 'coin',
			coins_spent int(11) NOT NULL DEFAULT 0,
			seconds_watched int(10) unsigned NOT NULL DEFAULT 0,
			furthest_position int(10) unsigned NOT NULL DEFAULT 0,
			completed tinyint(1) NOT NULL DEFAULT 0,
			unlocked_at datetime NULL,
			unlocked_until datetime NULL,
			last_seen_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_lesson (user_id,lesson_id),
			KEY lesson_idx (lesson_id),
			KEY course_idx (course_id),
			KEY unlocked_until_idx (unlocked_until)
		) $charset;";

		// ---------------------------------------------------------------
		// Quotes: which quote a user has already been shown, so the popup
		// fires once per day per user and never repeats the same quote.
		// ---------------------------------------------------------------
		$schema[] = "CREATE TABLE {$prefix}quote_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			visitor_hash varchar(64) NOT NULL DEFAULT '',
			quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
			shown_on date NULL,
			dismissed tinyint(1) NOT NULL DEFAULT 0,
			cta_clicked tinyint(1) NOT NULL DEFAULT 0,
			shown_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_day (user_id,shown_on),
			KEY visitor_day (visitor_hash,shown_on),
			KEY quote_idx (quote_id)
		) $charset;";

		// ---------------------------------------------------------------
		// Accounts: one row per parent-approval request. The code and the
		// approval link are stored only as HMAC hashes, so a database leak
		// does not let anyone approve an account.
		// status: pending | verified | declined | expired | cancelled
		// ---------------------------------------------------------------
		$schema[] = "CREATE TABLE {$prefix}parent_verifications (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			channel varchar(20) NOT NULL DEFAULT 'whatsapp',
			destination varchar(190) NOT NULL DEFAULT '',
			code_hash varchar(64) NOT NULL DEFAULT '',
			token_hash varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			send_count smallint(5) unsigned NOT NULL DEFAULT 0,
			decided_by varchar(20) NOT NULL DEFAULT '',
			decline_reason varchar(190) NOT NULL DEFAULT '',
			ip_address varchar(100) NOT NULL DEFAULT '',
			created_at datetime NULL,
			expires_at datetime NULL,
			last_sent_at datetime NULL,
			reminded_at datetime NULL,
			decided_at datetime NULL,
			meta longtext NULL,
			PRIMARY KEY  (id),
			KEY user_status (user_id,status),
			KEY token_idx (token_hash),
			KEY expires_idx (expires_at),
			KEY destination_idx (destination)
		) $charset;";

		// ---------------------------------------------------------------
		// Accounts: append-only audit trail of who signed up, who approved
		// them and when. A safeguarding record, so nothing here is updated
		// or deleted except when the account itself is erased.
		// ---------------------------------------------------------------
		$schema[] = "CREATE TABLE {$prefix}account_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event varchar(40) NOT NULL DEFAULT '',
			channel varchar(20) NOT NULL DEFAULT '',
			detail varchar(255) NOT NULL DEFAULT '',
			ip_address varchar(100) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			created_at datetime NULL,
			PRIMARY KEY  (id),
			KEY user_created (user_id,created_at),
			KEY event_idx (event)
		) $charset;";

		// ---------------------------------------------------------------
		// Classroom: who is in whose class. One row per student per class,
		// so a roster is one indexed read and "my classes" is another.
		// role:   student | assistant | consultant
		// status: active | pending | blocked | left
		// ---------------------------------------------------------------
		$schema[] = "CREATE TABLE {$prefix}class_members (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			class_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			role varchar(20) NOT NULL DEFAULT 'student',
			status varchar(20) NOT NULL DEFAULT 'active',
			source varchar(20) NOT NULL DEFAULT 'link',
			lessons_done smallint(5) unsigned NOT NULL DEFAULT 0,
			minutes_watched int(10) unsigned NOT NULL DEFAULT 0,
			joined_at datetime NULL,
			approved_at datetime NULL,
			last_seen_at datetime NULL,
			meta longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY class_user (class_id,user_id),
			KEY user_status (user_id,status),
			KEY class_status (class_id,status),
			KEY last_seen_idx (last_seen_at)
		) $charset;";

		// ---------------------------------------------------------------
		// Classroom: chat. One table for every room, keyed by a room string
		// such as `class:12`, `session:34` or `lobby:physics`, because the
		// read pattern is identical for all of them: "everything in this
		// room after message N".
		// author_kind: student | consultant | staff | bot | system
		// status:      visible | pending | hidden | deleted
		// ---------------------------------------------------------------
		$schema[] = "CREATE TABLE {$prefix}chat_messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			room varchar(60) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			author_kind varchar(20) NOT NULL DEFAULT 'student',
			author_name varchar(100) NOT NULL DEFAULT '',
			body text NULL,
			status varchar(20) NOT NULL DEFAULT 'visible',
			reply_to bigint(20) unsigned NOT NULL DEFAULT 0,
			flags smallint(5) unsigned NOT NULL DEFAULT 0,
			ip_address varchar(100) NOT NULL DEFAULT '',
			created_at datetime NULL,
			PRIMARY KEY  (id),
			KEY room_id_idx (room,id),
			KEY room_created (room,created_at),
			KEY user_created (user_id,created_at),
			KEY status_idx (status)
		) $charset;";

		foreach ( $schema as $table_sql ) {
			dbDelta( $table_sql );
		}
	}

	/**
	 * Create the instructor role and grant capabilities.
	 *
	 * Lets site staff manage questions and syllabus videos without handing
	 * them a full administrator account.
	 *
	 * @return void
	 */
	public static function add_roles() {
		$caps = array(
			'read'                => true,
			'upload_files'        => true,
			'ces_manage'          => true,
			'ces_manage_cbt'      => true,
			'ces_manage_lms'      => true,
			'ces_manage_quotes'   => true,
			'ces_manage_hub'      => true,
			'ces_manage_guidance' => true,
			'ces_view_reports'    => true,
		);

		remove_role( 'ces_instructor' );
		add_role( 'ces_instructor', __( 'EDU Instructor', 'classic-edu-suite' ), $caps );

		self::add_consultant_role();

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( $caps ) as $cap ) {
				$admin->add_cap( $cap );
			}
			$admin->add_cap( 'ces_manage_wallet' );
			$admin->add_cap( 'ces_manage_settings' );

			// Parent phone numbers are personal data, so instructors do not
			// get this one — only full administrators.
			$admin->add_cap( 'ces_manage_parents' );

			// Approving a student is a safeguarding decision and the screen
			// shows parent numbers, so this one is administrators only too.
			$admin->add_cap( 'ces_manage_accounts' );

			// Consultant applications, class oversight and chat moderation.
			$admin->add_cap( 'ces_manage_classroom' );
			$admin->add_cap( 'ces_moderate_chat' );
			$admin->add_cap( 'ces_consultant' );
		}

		$instructor = get_role( 'ces_instructor' );
		if ( $instructor ) {
			// An instructor is staff, so they run classes and moderate chat
			// as a matter of course. They do not get the application screens.
			$instructor->add_cap( 'ces_consultant' );
			$instructor->add_cap( 'ces_moderate_chat' );
		}
	}

	/**
	 * Create the Educational Consultant role.
	 *
	 * A consultant is a tutor from outside the school who hosts their own
	 * video lessons here and shares a link with their own students. The role
	 * is deliberately narrow:
	 *
	 *   - It carries the lesson and course capabilities **without** the
	 *     `*_others_*` ones, so WordPress's own `map_meta_cap()` confines a
	 *     consultant to the posts they authored. That is what stops one
	 *     consultant editing another's lessons.
	 *   - It does **not** carry `ces_manage_lms`. That capability means
	 *     "runs the video library", and `CES_Lms_Access::is_staff()` reads it
	 *     as free access to every video on the site.
	 *   - It carries no `edit_posts`, so there is no route into the site's
	 *     own content. Consultants never need wp-admin: the whole workspace
	 *     is the front-end Studio.
	 *
	 * @return void
	 */
	public static function add_consultant_role() {
		$caps = array(
			'read'                         => true,
			'upload_files'                 => true,
			'ces_consultant'               => true,

			// Their own lessons and courses, and nobody else's.
			'create_ces_lessons'           => true,
			'edit_ces_lessons'             => true,
			'edit_published_ces_lessons'   => true,
			'publish_ces_lessons'          => true,
			'delete_ces_lessons'           => true,
			'delete_published_ces_lessons' => true,
			'read_ces_lesson'              => true,
			'create_ces_courses'           => true,
			'edit_ces_courses'             => true,
			'edit_published_ces_courses'   => true,
			'publish_ces_courses'          => true,
			'delete_ces_courses'           => true,
			'delete_published_ces_courses' => true,
			'read_ces_course'              => true,

			// Their own classes and live sessions.
			'create_ces_classes'           => true,
			'edit_ces_classes'             => true,
			'edit_published_ces_classes'   => true,
			'publish_ces_classes'          => true,
			'delete_ces_classes'           => true,
			'delete_published_ces_classes' => true,
			'read_ces_class'               => true,
		);

		$existing = get_role( 'ces_consultant' );

		if ( $existing ) {
			// Add, never replace: an administrator may have granted extra
			// capabilities to this role deliberately.
			foreach ( array_keys( $caps ) as $cap ) {
				$existing->add_cap( $cap );
			}

			return;
		}

		add_role( 'ces_consultant', __( 'Educational Consultant', 'classic-edu-suite' ), $caps );
	}

	/**
	 * Seed default settings without clobbering existing values.
	 *
	 * @return void
	 */
	public static function set_defaults() {
		$existing = get_option( 'ces_settings', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$defaults = CES_Settings::defaults();

		update_option( 'ces_settings', array_merge( $defaults, $existing ) );

		// A per-install secret used to sign video stream tokens. Never
		// regenerate this casually: it invalidates every issued token.
		if ( ! get_option( 'ces_stream_secret' ) ) {
			update_option( 'ces_stream_secret', wp_generate_password( 64, true, true ) );
		}
	}

	/**
	 * Create the private video storage directory and lock it down.
	 *
	 * Videos live in wp-content/uploads/ces-private/, which is denied to
	 * direct HTTP requests. Playback only happens through the signed stream
	 * endpoint, so a student never sees a fetchable media URL.
	 *
	 * @return void
	 */
	public static function create_protected_storage() {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$base = trailingslashit( $uploads['basedir'] ) . 'ces-private';

		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}

		foreach ( array( '', '/hls', '/source', '/tmp' ) as $sub ) {
			$dir = $base . $sub;
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		// Apache / LiteSpeed: refuse every direct request into this tree.
		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Classic EDU Suite - private media. Do not remove.\n"
				. "<IfModule mod_authz_core.c>\n"
				. "\tRequire all denied\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "\tOrder deny,allow\n"
				. "\tDeny from all\n"
				. "</IfModule>\n";
			@file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Belt and braces for servers that ignore .htaccess.
		$index = $base . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Upgrade routine, fired from CES_Plugin when the stored version differs.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'ces_db_version' ) === CES_DB_VERSION ) {
			return;
		}

		self::create_tables();
		self::add_roles();
		self::set_defaults();
		self::create_protected_storage();

		update_option( 'ces_db_version', CES_DB_VERSION );
	}
}
