<?php
/**
 * The two post types the classroom adds, and every meta key it owns.
 *
 * A **class** (`ces_class`) is one consultant's group of students: a title, a
 * description, an ordered list of lessons, a join code and a roster. It is
 * publicly queryable so that `/class/jss3-physics/` is a link a consultant
 * can paste into WhatsApp — but the page itself is guarded, and a visitor who
 * is not a member sees a join screen rather than the lessons.
 *
 * A **session** (`ces_session`) is one scheduled lesson for that class: a
 * time, the lesson being taught, and the live room state while it runs.
 *
 * Both hang their capabilities off `ces_class` / `ces_classes`, which the
 * Educational Consultant role carries without the `*_others_*` variants. That
 * is what confines a consultant to their own classes: WordPress's own
 * `map_meta_cap()` does the work, so there is no ownership check of ours to
 * get wrong.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types, taxonomies and meta keys.
 */
class CES_Classroom_Cpt {

	/**
	 * A consultant's class or cohort.
	 */
	const CLASS_TYPE = 'ces_class';

	/**
	 * One scheduled live session of a class.
	 */
	const SESSION = 'ces_session';

	/* -------------------------------------------------------------------
	 * Class meta.
	 * ---------------------------------------------------------------- */

	/**
	 * Join code, the thing a student types or arrives with.
	 */
	const CODE = '_ces_class_code';

	/**
	 * `private` (link or code only) or `public` (listed in the directory).
	 */
	const VISIBILITY = '_ces_class_visibility';

	/**
	 * `open` (joining is immediate) or `approve` (the consultant lets them in).
	 */
	const APPROVAL = '_ces_class_approval';

	/**
	 * Whether this class has a chat room.
	 */
	const CHAT = '_ces_class_chat';

	/**
	 * Ordered list of lesson post IDs.
	 */
	const LESSONS = '_ces_class_lessons';

	/**
	 * Cached active member count.
	 */
	const MEMBERS = '_ces_class_members';

	/**
	 * Message shown to a student the moment they join.
	 */
	const WELCOME = '_ces_class_welcome';

	/**
	 * Optional external video-call link for genuinely live teaching.
	 */
	const MEET_URL = '_ces_class_meet_url';

	/**
	 * Set when a class is finished with but kept for its records.
	 */
	const ARCHIVED = '_ces_class_archived';

	/* -------------------------------------------------------------------
	 * Session meta.
	 * ---------------------------------------------------------------- */

	/**
	 * Which class this session belongs to.
	 */
	const SESSION_CLASS = '_ces_session_class';

	/**
	 * The lesson being taught, if any.
	 */
	const SESSION_LESSON = '_ces_session_lesson';

	/**
	 * When it starts, MySQL datetime in site time.
	 */
	const SESSION_START = '_ces_session_start';

	/**
	 * How long it is expected to run, in minutes.
	 */
	const SESSION_MINUTES = '_ces_session_minutes';

	/**
	 * `scheduled` | `live` | `ended`.
	 */
	const SESSION_STATE = '_ces_session_state';

	/**
	 * Shared playback state: `{ position, playing, updated }`.
	 */
	const SESSION_PLAYBACK = '_ces_session_playback';

	/**
	 * Raised hands: `{ user_id: timestamp }`.
	 */
	const SESSION_HANDS = '_ces_session_hands';

	/**
	 * The live poll, if one is running.
	 */
	const SESSION_POLL = '_ces_session_poll';

	/**
	 * Attendance: `{ user_id: seconds_present }`.
	 */
	const SESSION_ATTENDANCE = '_ces_session_attendance';

	/* -------------------------------------------------------------------
	 * Extra meta this module puts on an LMS lesson.
	 * ---------------------------------------------------------------- */

	/**
	 * Chapter markers: `[ { t: seconds, label: string } ]`.
	 */
	const LESSON_CHAPTERS = '_ces_lesson_chapters';

	/**
	 * Comprehension checkpoints: `[ { t: seconds, question_id: int } ]`.
	 */
	const LESSON_CHECKPOINTS = '_ces_lesson_checkpoints';

	/**
	 * The lesson plan the consultant wrote before recording.
	 */
	const LESSON_PLAN = '_ces_lesson_plan';

	/**
	 * WebVTT captions, as text.
	 */
	const LESSON_VTT = '_ces_lesson_vtt';

	/**
	 * Which class a consultant's lesson belongs to.
	 */
	const LESSON_CLASS = '_ces_lesson_class';

	/**
	 * Set when a lesson came out of the in-browser recorder.
	 */
	const LESSON_RECORDED = '_ces_lesson_recorded';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 9 );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_caps' ), 10, 4 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * Register the class and session post types.
	 *
	 * @return void
	 */
	public static function register_post_types() {
		$caps = array( 'ces_class', 'ces_classes' );

		register_post_type(
			self::CLASS_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Classes', 'classic-edu-suite' ),
					'singular_name' => __( 'Class', 'classic-edu-suite' ),
					'add_new_item'  => __( 'Add Class', 'classic-edu-suite' ),
					'edit_item'     => __( 'Edit Class', 'classic-edu-suite' ),
					'search_items'  => __( 'Search Classes', 'classic-edu-suite' ),
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'has_archive'         => false,
				'exclude_from_search' => true,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'thumbnail', 'author' ),
				'capability_type'     => $caps,
				'map_meta_cap'        => true,
				'menu_icon'           => 'dashicons-groups',
				'rewrite'             => array(
					'slug'       => 'class',
					'with_front' => false,
				),
			)
		);

		register_post_type(
			self::SESSION,
			array(
				'labels'              => array(
					'name'          => __( 'Live Sessions', 'classic-edu-suite' ),
					'singular_name' => __( 'Live Session', 'classic-edu-suite' ),
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'has_archive'         => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title', 'author' ),
				'capability_type'     => $caps,
				'map_meta_cap'        => true,
				'rewrite'             => array(
					'slug'       => 'live',
					'with_front' => false,
				),
			)
		);

		// The subject and level tags the rest of the plugin already uses, so a
		// consultant's class can be found the same way a course is.
		foreach ( array( 'ces_subject', 'ces_level', 'ces_exam_type' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				register_taxonomy_for_object_type( $taxonomy, self::CLASS_TYPE );
			}
		}
	}

	/**
	 * Every primitive capability the two post types generate.
	 *
	 * @return string[]
	 */
	public static function post_type_caps() {
		$caps = array();

		foreach ( array(
			'edit_ces_classes',
			'edit_others_ces_classes',
			'edit_published_ces_classes',
			'edit_private_ces_classes',
			'publish_ces_classes',
			'read_private_ces_classes',
			'delete_ces_classes',
			'delete_others_ces_classes',
			'delete_published_ces_classes',
			'delete_private_ces_classes',
			'create_ces_classes',
			'read_ces_class',
			'edit_ces_class',
			'delete_ces_class',
		) as $cap ) {
			$caps[] = $cap;
		}

		return $caps;
	}

	/**
	 * Give staff the class capabilities, including `*_others_*`.
	 *
	 * Consultants get the narrow set from the role itself; this is for
	 * administrators and instructors, who oversee everybody's classes. It also
	 * covers an install upgraded by copying files, which never re-ran the
	 * activator.
	 *
	 * @param array $allcaps All capabilities for the user.
	 * @param array $caps    Required capabilities (unused).
	 * @param array $args    Context (unused).
	 * @param mixed $user    User being tested (unused).
	 * @return array
	 */
	public static function grant_caps( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args, $user );

		$staff = ! empty( $allcaps['manage_options'] )
			|| ! empty( $allcaps['ces_manage_classroom'] )
			|| ! empty( $allcaps['ces_manage_lms'] );

		if ( ! $staff ) {
			return $allcaps;
		}

		foreach ( self::post_type_caps() as $cap ) {
			$allcaps[ $cap ] = true;
		}

		$allcaps['ces_manage_classroom'] = true;
		$allcaps['ces_moderate_chat']    = true;
		$allcaps['ces_consultant']       = true;

		return $allcaps;
	}

	/* -------------------------------------------------------------------
	 * Reading a class.
	 * ---------------------------------------------------------------- */

	/**
	 * Whether a post ID is a class.
	 *
	 * @param int $class_id Candidate.
	 * @return bool
	 */
	public static function is_class( $class_id ) {
		return self::CLASS_TYPE === get_post_type( (int) $class_id );
	}

	/**
	 * The consultant who owns a class.
	 *
	 * @param int $class_id Class.
	 * @return int User ID, 0 when unknown.
	 */
	public static function owner( $class_id ) {
		return (int) get_post_field( 'post_author', (int) $class_id );
	}

	/**
	 * A class's join code, minted on first read so it always exists.
	 *
	 * @param int $class_id Class.
	 * @return string
	 */
	public static function code( $class_id ) {
		$class_id = (int) $class_id;
		$code     = (string) get_post_meta( $class_id, self::CODE, true );

		if ( '' !== $code ) {
			return $code;
		}

		$code = self::mint_code();
		update_post_meta( $class_id, self::CODE, $code );

		return $code;
	}

	/**
	 * Generate a join code that is not already in use.
	 *
	 * Uses the wallet's alphabet, which leaves out the characters students
	 * misread when copying a code off a phone screen (0/O, 1/I/l).
	 *
	 * @return string
	 */
	public static function mint_code() {
		$alphabet = class_exists( 'CES_Referral' ) && defined( 'CES_Referral::ALPHABET' )
			? CES_Referral::ALPHABET
			: '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

		$length = strlen( $alphabet );

		for ( $attempt = 0; $attempt < 12; $attempt++ ) {
			$code = '';

			for ( $i = 0; $i < 6; $i++ ) {
				$code .= $alphabet[ wp_rand( 0, $length - 1 ) ];
			}

			if ( ! self::by_code( $code ) ) {
				return $code;
			}
		}

		// Twelve collisions in a row means something is very wrong with the
		// random source; fall back to something certainly unique.
		return strtoupper( substr( md5( uniqid( '', true ) ), 0, 8 ) );
	}

	/**
	 * Find a class by its join code.
	 *
	 * @param string $code Code as typed.
	 * @return int Class ID, 0 when no match.
	 */
	public static function by_code( $code ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );

		if ( strlen( $code ) < 4 ) {
			return 0;
		}

		$found = get_posts(
			array(
				'post_type'      => self::CLASS_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::CODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $code,      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return empty( $found ) ? 0 : (int) $found[0];
	}

	/**
	 * The shareable link to a class, with the code attached.
	 *
	 * The code in the URL means a student who follows the link never has to
	 * type anything, which matters when the link arrives on WhatsApp.
	 *
	 * @param int $class_id Class.
	 * @return string
	 */
	public static function share_link( $class_id ) {
		$class_id = (int) $class_id;
		$page_id  = CES_Helpers::setting_int( 'classroom_class_page_id', 0 );

		$base = $page_id > 0 ? get_permalink( $page_id ) : get_permalink( $class_id );

		if ( ! $base ) {
			$base = home_url( '/' );
		}

		$args = array( 'ces_class' => self::code( $class_id ) );

		if ( $page_id > 0 ) {
			$args['class_id'] = $class_id;
		}

		return add_query_arg( $args, $base );
	}

	/**
	 * The ordered lesson IDs in a class, filtered to ones that still exist.
	 *
	 * @param int $class_id Class.
	 * @return int[]
	 */
	public static function lessons( $class_id ) {
		$stored = get_post_meta( (int) $class_id, self::LESSONS, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();

		foreach ( $stored as $lesson_id ) {
			$lesson_id = (int) $lesson_id;

			if ( $lesson_id > 0 && CES_Lms_Cpt::LESSON === get_post_type( $lesson_id ) ) {
				$out[] = $lesson_id;
			}
		}

		return $out;
	}

	/**
	 * Replace a class's lesson list.
	 *
	 * @param int   $class_id Class.
	 * @param int[] $lessons  Lesson IDs in display order.
	 * @return void
	 */
	public static function set_lessons( $class_id, array $lessons ) {
		$clean = array();

		foreach ( $lessons as $lesson_id ) {
			$lesson_id = (int) $lesson_id;

			if ( $lesson_id > 0 && ! in_array( $lesson_id, $clean, true ) ) {
				$clean[] = $lesson_id;
			}
		}

		update_post_meta( (int) $class_id, self::LESSONS, $clean );
	}

	/**
	 * Add one lesson to the end of a class's list.
	 *
	 * @param int $class_id  Class.
	 * @param int $lesson_id Lesson.
	 * @return void
	 */
	public static function add_lesson( $class_id, $lesson_id ) {
		$lessons = self::lessons( $class_id );

		if ( ! in_array( (int) $lesson_id, $lessons, true ) ) {
			$lessons[] = (int) $lesson_id;
		}

		self::set_lessons( $class_id, $lessons );
		update_post_meta( (int) $lesson_id, self::LESSON_CLASS, (int) $class_id );
	}

	/**
	 * Whether a class is listed in the public directory.
	 *
	 * @param int $class_id Class.
	 * @return bool
	 */
	public static function is_public( $class_id ) {
		return CES_Helpers::enabled( 'classroom_allow_public' )
			&& 'public' === (string) get_post_meta( (int) $class_id, self::VISIBILITY, true );
	}

	/**
	 * Whether joining this class needs the consultant's approval.
	 *
	 * @param int $class_id Class.
	 * @return bool
	 */
	public static function needs_approval( $class_id ) {
		$stored = (string) get_post_meta( (int) $class_id, self::APPROVAL, true );

		if ( 'open' === $stored ) {
			return false;
		}

		if ( 'approve' === $stored ) {
			return true;
		}

		return CES_Helpers::enabled( 'classroom_require_approval' );
	}

	/**
	 * Whether a class has its chat room switched on.
	 *
	 * @param int $class_id Class.
	 * @return bool
	 */
	public static function chat_enabled( $class_id ) {
		if ( ! CES_Helpers::enabled( 'classroom_chat_enabled' ) ) {
			return false;
		}

		$stored = (string) get_post_meta( (int) $class_id, self::CHAT, true );

		return '0' !== $stored;
	}

	/**
	 * Whether a class has been archived.
	 *
	 * @param int $class_id Class.
	 * @return bool
	 */
	public static function is_archived( $class_id ) {
		return '1' === (string) get_post_meta( (int) $class_id, self::ARCHIVED, true );
	}

	/**
	 * Classes belonging to one consultant.
	 *
	 * @param int  $user_id      Consultant.
	 * @param bool $include_archived Whether to include archived classes.
	 * @return int[]
	 */
	public static function for_consultant( $user_id, $include_archived = true ) {
		$args = array(
			'post_type'      => self::CLASS_TYPE,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'author'         => (int) $user_id,
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! $include_archived ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => self::ARCHIVED,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::ARCHIVED,
					'value'   => '1',
					'compare' => '!=',
				),
			);
		}

		return array_map( 'intval', (array) get_posts( $args ) );
	}

	/* -------------------------------------------------------------------
	 * JSON meta helpers.
	 * ---------------------------------------------------------------- */

	/**
	 * Read a JSON meta value as an array.
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 * @return array
	 */
	public static function json_meta( $post_id, $key ) {
		$raw = get_post_meta( (int) $post_id, $key, true );

		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Write an array into a meta key as JSON.
	 *
	 * Stored as JSON text rather than a serialised array so the values are
	 * readable in the database and safe to hand straight to the browser.
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 * @param array  $value   Value.
	 * @return void
	 */
	public static function set_json_meta( $post_id, $key, array $value ) {
		if ( empty( $value ) ) {
			delete_post_meta( (int) $post_id, $key );

			return;
		}

		update_post_meta( (int) $post_id, $key, wp_json_encode( $value ) );
	}

	/* -------------------------------------------------------------------
	 * Tidying up.
	 * ---------------------------------------------------------------- */

	/**
	 * Clean up the roster and chat when a class or session is deleted.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function on_delete( $post_id ) {
		$post_id = (int) $post_id;
		$type    = get_post_type( $post_id );

		if ( self::CLASS_TYPE === $type ) {
			CES_Classroom_Enrolment::delete_class( $post_id );
			CES_Chat::delete_room( CES_Chat::class_room( $post_id ) );

			// The class's sessions go with it.
			$sessions = get_posts(
				array(
					'post_type'      => self::SESSION,
					'post_status'    => 'any',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => self::SESSION_CLASS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $post_id,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			foreach ( $sessions as $session_id ) {
				wp_delete_post( (int) $session_id, true );
			}

			return;
		}

		if ( self::SESSION === $type ) {
			CES_Chat::delete_room( CES_Chat::session_room( $post_id ) );
		}
	}
}
