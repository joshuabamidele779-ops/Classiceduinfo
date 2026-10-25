<?php
/**
 * REST routes for the video library, under `ces/v1/lms/`.
 *
 * Reads are public but reveal nothing privileged: a lesson's detail response
 * carries its access *state*, never a playback URL, unless the caller has
 * actually earned access. Writes all require a logged-in user, a valid
 * `X-WP-Nonce`, and pass through `CES_Helpers::rate_limit()`.
 *
 * No route ever returns a filesystem path, an API key, the signing secret, or
 * a playback URL for a lesson the caller cannot watch.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * LMS REST controller.
 */
class CES_Lms_Rest {

	/**
	 * Route namespace prefix within CES_REST_NAMESPACE.
	 */
	const PREFIX = 'lms';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = CES_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/' . self::PREFIX . '/courses',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_courses' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 12,
						'sanitize_callback' => 'absint',
					),
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'syllabus' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_title',
					),
					'level'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_title',
					),
					'subject'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::PREFIX . '/courses/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_course' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::PREFIX . '/lessons/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_lesson' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::PREFIX . '/lessons/(?P<id>\d+)/unlock',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'unlock_lesson' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::PREFIX . '/lessons/(?P<id>\d+)/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'issue_token' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'sid' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::PREFIX . '/lessons/(?P<id>\d+)/progress',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'report_progress' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'id'       => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'position' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'delta'    => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::PREFIX . '/lessons/(?P<id>\d+)/release',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'release_session' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'sid' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::PREFIX . '/my-courses',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'my_courses' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			)
		);
	}

	// ------------------------------------------------------------------
	// Permissions.
	// ------------------------------------------------------------------

	/**
	 * Read permission: open, but the payloads are access-aware.
	 *
	 * @return true
	 */
	public static function can_read() {
		return true;
	}

	/**
	 * Write permission: logged in, with a valid REST nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function can_write( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'ces_login_required',
				__( 'Please log in first.', 'classic-edu-suite' ),
				array( 'status' => 401 )
			);
		}

		$nonce = $request instanceof WP_REST_Request ? (string) $request->get_header( 'X-WP-Nonce' ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'ces_bad_nonce',
				__( 'Your session expired. Reload the page and try again.', 'classic-edu-suite' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Read routes.
	// ------------------------------------------------------------------

	/**
	 * List published courses.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_courses( $request ) {
		$per_page = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$args = array(
			'post_type'      => CES_Lms_Cpt::COURSE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
		);

		$search = (string) $request->get_param( 'search' );

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$tax_query = array();

		foreach (
			array(
				'syllabus' => CES_Lms_Cpt::TAX_SYLLABUS,
				'level'    => CES_Lms_Cpt::TAX_LEVEL,
				'subject'  => CES_Lms_Cpt::TAX_SUBJECT,
			) as $param => $taxonomy
		) {
			$slug = (string) $request->get_param( $param );

			if ( '' !== $slug && taxonomy_exists( $taxonomy ) ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $slug,
				);
			}
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query   = new WP_Query( $args );
		$courses = array();

		foreach ( $query->posts as $post ) {
			$courses[] = self::prepare_course( $post );
		}

		return new WP_REST_Response(
			array(
				'courses' => $courses,
				'total'   => (int) $query->found_posts,
				'pages'   => (int) $query->max_num_pages,
				'page'    => $page,
			),
			200
		);
	}

	/**
	 * One course with its lesson list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_course( $request ) {
		$course_id = (int) $request->get_param( 'id' );
		$course    = get_post( $course_id );

		if ( ! $course || CES_Lms_Cpt::COURSE !== $course->post_type || 'publish' !== $course->post_status ) {
			return new WP_Error( 'ces_course_missing', __( 'That course does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$data            = self::prepare_course( $course );
		$data['lessons'] = self::course_lessons_payload( $course_id );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * One lesson with its access state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_lesson( $request ) {
		$lesson_id = (int) $request->get_param( 'id' );
		$lesson    = get_post( $lesson_id );

		if ( ! $lesson || CES_Lms_Cpt::LESSON !== $lesson->post_type ) {
			return new WP_Error( 'ces_lesson_missing', __( 'That lesson does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		if ( 'publish' !== $lesson->post_status && ! CES_Lms_Access::is_staff( $lesson_id, get_current_user_id() ) ) {
			return new WP_Error( 'ces_lesson_missing', __( 'That lesson does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( self::prepare_lesson( $lesson, true ), 200 );
	}

	/**
	 * Courses the current student has touched.
	 *
	 * @return WP_REST_Response
	 */
	public static function my_courses() {
		$user_id = get_current_user_id();
		$courses = array();

		foreach ( self::course_ids_for_user( $user_id ) as $course_id ) {
			$course = get_post( $course_id );

			if ( ! $course || 'publish' !== $course->post_status ) {
				continue;
			}

			$data             = self::prepare_course( $course );
			$data['progress'] = CES_Lms_Progress::course_progress( $course_id, $user_id );
			$courses[]        = $data;
		}

		return new WP_REST_Response( array( 'courses' => $courses ), 200 );
	}

	// ------------------------------------------------------------------
	// Write routes.
	// ------------------------------------------------------------------

	/**
	 * Spend coins to unlock a lesson.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function unlock_lesson( $request ) {
		$lesson_id = (int) $request->get_param( 'id' );
		$result    = CES_Lms_Access::unlock( $lesson_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'access' => $result,
				'lesson' => self::prepare_lesson( get_post( $lesson_id ), true ),
			),
			200
		);
	}

	/**
	 * Issue or refresh a stream token.
	 *
	 * The player calls this on a timer while the lesson plays, which is what
	 * keeps a two-hour lesson running on a two-minute token. The session id is
	 * carried across refreshes so the concurrent-stream slot is reused rather
	 * than a new one being claimed every couple of minutes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function issue_token( $request ) {
		$lesson_id = (int) $request->get_param( 'id' );
		$user_id   = get_current_user_id();

		if ( ! CES_Helpers::rate_limit( 'lms_token', 120, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ces_rate_limited', __( 'Too many requests. Please slow down.', 'classic-edu-suite' ), array( 'status' => 429 ) );
		}

		$allowed = CES_Lms_Access::can_watch( $lesson_id, $user_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! CES_Lms_Meta::is_playable( $lesson_id ) ) {
			return new WP_Error( 'ces_not_playable', __( 'This lesson is not ready to play yet.', 'classic-edu-suite' ), array( 'status' => 409 ) );
		}

		$sid = CES_Lms_Token::sanitize_sid( (string) $request->get_param( 'sid' ) );

		if ( '' === $sid ) {
			$sid = CES_Lms_Token::new_sid();
		}

		$slots = CES_Lms_Concurrency::touch( $user_id, $sid, true );

		if ( is_wp_error( $slots ) ) {
			return $slots;
		}

		$driver = CES_Lms_Storage_Factory::for_lesson( $lesson_id );

		if ( ! $driver ) {
			return new WP_Error( 'ces_storage_missing', __( 'Video storage is not configured.', 'classic-edu-suite' ), array( 'status' => 500 ) );
		}

		$playback = $driver->get_playlist_url( $lesson_id, array( 'user_id' => $user_id, 'sid' => $sid ) );

		if ( is_wp_error( $playback ) ) {
			return $playback;
		}

		return new WP_REST_Response(
			array(
				'sid'       => isset( $playback['sid'] ) ? (string) $playback['sid'] : $sid,
				'kind'      => (string) $playback['kind'],
				'src'       => (string) $playback['url'],
				'mime'      => (string) $playback['mime'],
				'useHls'    => ! empty( $playback['use_hlsjs'] ),
				'expires'   => (int) $playback['expires'],
				'refreshIn' => CES_Lms_Token::refresh_interval(),
			),
			200
		);
	}

	/**
	 * Record a watch heartbeat.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function report_progress( $request ) {
		$result = CES_Lms_Progress::report(
			(int) $request->get_param( 'id' ),
			get_current_user_id(),
			(int) $request->get_param( 'position' ),
			(int) $request->get_param( 'delta' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Give up a concurrent-stream slot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function release_session( $request ) {
		$sid = CES_Lms_Token::sanitize_sid( (string) $request->get_param( 'sid' ) );

		if ( '' !== $sid ) {
			CES_Lms_Concurrency::release( get_current_user_id(), $sid );
		}

		return new WP_REST_Response( array( 'released' => '' !== $sid ), 200 );
	}

	// ------------------------------------------------------------------
	// Payload builders.
	// ------------------------------------------------------------------

	/**
	 * Shape a course for the API.
	 *
	 * @param WP_Post $course Course post.
	 * @return array
	 */
	public static function prepare_course( $course ) {
		$course_id = (int) $course->ID;
		$lessons   = CES_Lms_Cpt::course_lesson_ids( $course_id );
		$seconds   = 0;

		foreach ( $lessons as $lesson_id ) {
			$seconds += CES_Lms_Meta::duration( $lesson_id );
		}

		return array(
			'id'            => $course_id,
			'title'         => get_the_title( $course_id ),
			'permalink'     => (string) get_permalink( $course_id ),
			'excerpt'       => wp_strip_all_tags( (string) get_the_excerpt( $course_id ) ),
			'thumbnail'     => (string) get_the_post_thumbnail_url( $course_id, 'medium_large' ),
			'lesson_count'  => count( $lessons ),
			'total_seconds' => $seconds,
			'duration'      => CES_Lms_Meta::format_duration( $seconds ),
			'terms'         => self::terms_for( $course_id ),
		);
	}

	/**
	 * Shape a lesson for the API.
	 *
	 * @param WP_Post $lesson       Lesson post.
	 * @param bool    $with_access  Include the viewer's access state.
	 * @return array
	 */
	public static function prepare_lesson( $lesson, $with_access = false ) {
		if ( ! $lesson instanceof WP_Post ) {
			return array();
		}

		$lesson_id = (int) $lesson->ID;
		$duration  = CES_Lms_Meta::duration( $lesson_id );

		$data = array(
			'id'          => $lesson_id,
			'title'       => get_the_title( $lesson_id ),
			'permalink'   => (string) get_permalink( $lesson_id ),
			'excerpt'     => wp_strip_all_tags( (string) get_the_excerpt( $lesson_id ) ),
			'thumbnail'   => (string) get_the_post_thumbnail_url( $lesson_id, 'medium_large' ),
			'course_id'   => CES_Lms_Cpt::lesson_course_id( $lesson_id ),
			'duration'    => $duration,
			'duration_h'  => $duration ? CES_Lms_Meta::format_duration( $duration ) : '',
			'free'        => CES_Lms_Access::is_free_preview( $lesson_id ),
			'cost'        => CES_Lms_Access::cost( $lesson_id ),
			'playable'    => CES_Lms_Meta::is_playable( $lesson_id ),
			'has_notes'   => '' !== (string) get_post_meta( $lesson_id, CES_Lms_Meta::NOTES, true ),
			'terms'       => self::terms_for( $lesson_id ),
		);

		if ( $with_access ) {
			$user_id = get_current_user_id();

			$data['access'] = CES_Lms_Access::evaluate( $lesson_id, $user_id );

			$progress = CES_Lms_Progress::for_lessons( $user_id, array( $lesson_id ) );

			$data['progress'] = isset( $progress[ $lesson_id ] ) ? $progress[ $lesson_id ] : null;
		}

		return $data;
	}

	/**
	 * Lesson payloads for one course, with per-lesson access and progress.
	 *
	 * @param int $course_id Course post id.
	 * @return array[]
	 */
	public static function course_lessons_payload( $course_id ) {
		$user_id = get_current_user_id();
		$ids     = CES_Lms_Cpt::course_lesson_ids( (int) $course_id );

		if ( empty( $ids ) ) {
			return array();
		}

		$progress = CES_Lms_Progress::for_lessons( $user_id, $ids );
		$out      = array();

		foreach ( $ids as $index => $lesson_id ) {
			$lesson = get_post( $lesson_id );

			if ( ! $lesson ) {
				continue;
			}

			$data              = self::prepare_lesson( $lesson );
			$data['position']  = (int) $index + 1;
			$data['access']    = CES_Lms_Access::evaluate( $lesson_id, $user_id );
			$data['progress']  = isset( $progress[ $lesson_id ] ) ? $progress[ $lesson_id ] : null;

			$out[] = $data;
		}

		return $out;
	}

	/**
	 * Term names grouped by taxonomy for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private static function terms_for( $post_id ) {
		$out = array();

		foreach (
			array(
				CES_Lms_Cpt::TAX_SYLLABUS,
				CES_Lms_Cpt::TAX_LEVEL,
				CES_Lms_Cpt::TAX_SUBJECT,
				CES_Lms_Cpt::TAX_EXAM_TYPE,
			) as $taxonomy
		) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( (int) $post_id, $taxonomy );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$out[ $taxonomy ] = array();

			foreach ( $terms as $term ) {
				$out[ $taxonomy ][] = array(
					'slug' => $term->slug,
					'name' => $term->name,
				);
			}
		}

		return $out;
	}

	/**
	 * Course ids a student has any record against.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public static function course_ids_for_user( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return array();
		}

		$table = CES_Helpers::table( 'video_views' );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// GROUP BY, not DISTINCT: MAX() needs a group, and strict
				// ONLY_FULL_GROUP_BY mode rejects the DISTINCT form outright.
				"SELECT course_id FROM {$table}
				WHERE user_id = %d AND course_id > 0
				GROUP BY course_id
				ORDER BY MAX( last_seen_at ) DESC
				LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}
}
