<?php
/**
 * REST API for the exam runner.
 *
 * Every route lives under `ces/v1/cbt/`. Reads require a logged-in student;
 * writes additionally require a valid `wp_rest` nonce and pass through a rate
 * limiter. Failures come back as `WP_Error` with a real HTTP status, never as
 * a `wp_die()`.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route registration and handlers.
 */
class CES_Cbt_Rest {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Route namespace.
	 *
	 * @return string
	 */
	private static function ns() {
		return defined( 'CES_REST_NAMESPACE' ) ? CES_REST_NAMESPACE : 'ces/v1';
	}

	/**
	 * Register every CBT route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = self::ns();

		register_rest_route(
			$ns,
			'/cbt/exams',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'callback'            => array( __CLASS__, 'list_exams' ),
				'args'                => array(
					'subject'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'exam_type' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'     => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/cbt/exams/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'callback'            => array( __CLASS__, 'get_exam' ),
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
			'/cbt/attempts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'callback'            => array( __CLASS__, 'start_attempt' ),
				'args'                => array(
					'exam_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/cbt/attempts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'callback'            => array( __CLASS__, 'get_attempt' ),
				'args'                => self::attempt_arg(),
			)
		);

		register_rest_route(
			$ns,
			'/cbt/attempts/(?P<id>\d+)/questions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'callback'            => array( __CLASS__, 'get_questions' ),
				'args'                => array_merge(
					self::attempt_arg(),
					array(
						'question_id' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/cbt/attempts/(?P<id>\d+)/answer',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'callback'            => array( __CLASS__, 'save_answer' ),
				'args'                => array_merge(
					self::attempt_arg(),
					array(
						'question_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'answer'      => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/cbt/attempts/(?P<id>\d+)/flag',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'callback'            => array( __CLASS__, 'flag_question' ),
				'args'                => array_merge(
					self::attempt_arg(),
					array(
						'question_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'flagged'     => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => true,
						),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/cbt/attempts/(?P<id>\d+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'callback'            => array( __CLASS__, 'submit_attempt' ),
				'args'                => self::attempt_arg(),
			)
		);

		register_rest_route(
			$ns,
			'/cbt/attempts/(?P<id>\d+)/event',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'callback'            => array( __CLASS__, 'log_event' ),
				'args'                => array_merge(
					self::attempt_arg(),
					array(
						'type' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'blur',
							'sanitize_callback' => 'sanitize_key',
						),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/cbt/attempts/(?P<id>\d+)/result',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'callback'            => array( __CLASS__, 'get_result' ),
				'args'                => self::attempt_arg(),
			)
		);

		register_rest_route(
			$ns,
			'/cbt/results',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'callback'            => array( __CLASS__, 'my_results' ),
				'args'                => array(
					'limit'  => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'offset' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/cbt/leaderboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'callback'            => array( __CLASS__, 'leaderboard' ),
				'args'                => array(
					'exam_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit'   => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * The shared `id` path argument for attempt routes.
	 *
	 * @return array
	 */
	private static function attempt_arg() {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Permissions.
	 * ------------------------------------------------------------------ */

	/**
	 * Read permission: any logged-in student.
	 *
	 * @return true|WP_Error
	 */
	public static function can_read() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'ces_cbt_login_required',
				__( 'Please log in to continue.', 'classic-edu-suite' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Write permission: a logged-in student with a valid REST nonce.
	 *
	 * WordPress already requires a good nonce for cookie-authenticated REST
	 * requests, but the contract asks for an explicit check on every write and
	 * a missing nonce should be a clear 403 rather than a confusing 401.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function can_write( $request ) {
		$allowed = self::can_read();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$nonce = $request->get_header( 'x_wp_nonce' );

		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce || ! wp_verify_nonce( sanitize_text_field( (string) $nonce ), 'wp_rest' ) ) {
			return new WP_Error(
				'ces_cbt_bad_nonce',
				__( 'Your session has expired. Please reload the page.', 'classic-edu-suite' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Apply a rate limit, as a WP_Error when exceeded.
	 *
	 * @param string $action Action slug.
	 * @param int    $limit  Allowed hits.
	 * @param int    $window Window in seconds.
	 * @return true|WP_Error
	 */
	private static function throttle( $action, $limit, $window ) {
		if ( CES_Helpers::rate_limit( 'cbt_' . $action, $limit, $window ) ) {
			return true;
		}

		return new WP_Error(
			'ces_cbt_rate_limited',
			__( 'Too many requests. Please slow down and try again shortly.', 'classic-edu-suite' ),
			array( 'status' => 429 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Handlers.
	 * ------------------------------------------------------------------ */

	/**
	 * GET /cbt/exams
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_exams( $request ) {
		$throttled = self::throttle( 'list', 60, MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$exams = CES_Cbt_Exam::list_exams(
			array(
				'subject'   => (string) $request->get_param( 'subject' ),
				'exam_type' => (string) $request->get_param( 'exam_type' ),
				'limit'     => (int) $request->get_param( 'limit' ),
			)
		);

		$out = array();

		foreach ( $exams as $exam ) {
			$summary = CES_Cbt_Exam::summary( $exam->ID );

			if ( $summary ) {
				$out[] = $summary;
			}
		}

		return rest_ensure_response( array( 'exams' => $out ) );
	}

	/**
	 * GET /cbt/exams/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_exam( $request ) {
		$exam_id = absint( $request->get_param( 'id' ) );
		$summary = CES_Cbt_Exam::summary( $exam_id );

		if ( ! $summary ) {
			return new WP_Error(
				'ces_cbt_exam_not_found',
				__( 'That exam is not available.', 'classic-edu-suite' ),
				array( 'status' => 404 )
			);
		}

		$config  = CES_Cbt_Exam::config( $exam_id );
		$user_id = get_current_user_id();

		$summary['instructions'] = CES_Cbt_Question::sanitize_rich_text( wpautop( $config['instructions'] ) );
		$summary['attemptsUsed'] = CES_Cbt_Attempt::completed_count( $user_id, $exam_id );
		$summary['maxAttempts']  = (int) $config['max_attempts'];

		$open = CES_Cbt_Attempt::find_in_progress( $user_id, $exam_id );

		$summary['resumeAttemptId'] = ( $open && ! CES_Cbt_Attempt::is_expired( $open ) ) ? (int) $open->id : 0;

		$allowed = CES_Cbt_Attempt::retake_check( $user_id, $exam_id, $config );

		$summary['canStart'] = ! is_wp_error( $allowed ) || $summary['resumeAttemptId'] > 0;
		$summary['blocker']  = is_wp_error( $allowed ) ? $allowed->get_error_message() : '';

		return rest_ensure_response( $summary );
	}

	/**
	 * POST /cbt/attempts
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start_attempt( $request ) {
		$throttled = self::throttle( 'start', 15, 5 * MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$result = CES_Cbt_Attempt::start( get_current_user_id(), absint( $request->get_param( 'exam_id' ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /cbt/attempts/{id}
	 *
	 * Resyncs the runner: the clock, the saved answers and the flags. The
	 * browser calls this on reload and after a long offline stretch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_attempt( $request ) {
		$throttled = self::throttle( 'get_attempt', 120, MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$row = CES_Cbt_Attempt::get_for_user( absint( $request->get_param( 'id' ) ), get_current_user_id() );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( CES_Cbt_Attempt::STATUS_IN_PROGRESS !== (string) $row->status ) {
			return rest_ensure_response(
				array(
					'attemptId' => (int) $row->id,
					'status'    => (string) $row->status,
					'finished'  => true,
				)
			);
		}

		// An attempt whose clock ran out while nobody was looking gets closed
		// here, so the student is shown a result rather than a dead paper.
		if ( CES_Cbt_Attempt::is_expired( $row ) ) {
			CES_Cbt_Attempt::submit( (int) $row->id, (int) $row->user_id, 'expired' );

			return rest_ensure_response(
				array(
					'attemptId' => (int) $row->id,
					'status'    => CES_Cbt_Attempt::STATUS_SUBMITTED,
					'finished'  => true,
					'expired'   => true,
				)
			);
		}

		return rest_ensure_response( CES_Cbt_Attempt::payload( $row ) );
	}

	/**
	 * GET /cbt/attempts/{id}/questions
	 *
	 * The whole set, or one question when `question_id` is supplied. Answers
	 * are never included, whatever is asked for.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_questions( $request ) {
		$throttled = self::throttle( 'questions', 200, MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$row = CES_Cbt_Attempt::get_for_user( absint( $request->get_param( 'id' ) ), get_current_user_id() );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$wanted       = absint( $request->get_param( 'question_id' ) );
		$question_ids = CES_Cbt_Attempt::question_ids( $row );
		$meta         = CES_Cbt_Attempt::meta( $row );
		$orders       = isset( $meta['option_order'] ) && is_array( $meta['option_order'] ) ? $meta['option_order'] : array();

		if ( $wanted ) {
			if ( ! in_array( $wanted, $question_ids, true ) ) {
				return new WP_Error(
					'ces_cbt_bad_question',
					__( 'That question is not part of this paper.', 'classic-edu-suite' ),
					array( 'status' => 404 )
				);
			}

			$question_ids = array( $wanted );
		}

		$out = array();

		foreach ( $question_ids as $qid ) {
			$question = CES_Cbt_Question::get( $qid );

			if ( ! $question ) {
				continue;
			}

			$order = isset( $orders[ (string) $qid ] ) && is_array( $orders[ (string) $qid ] ) ? $orders[ (string) $qid ] : array();

			$out[] = CES_Cbt_Question::for_client( $question, $order );
		}

		return rest_ensure_response(
			array(
				'attemptId' => (int) $row->id,
				'questions' => $out,
				'remaining' => CES_Cbt_Attempt::remaining_seconds( $row ),
			)
		);
	}

	/**
	 * POST /cbt/attempts/{id}/answer
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_answer( $request ) {
		// Generous: a 60 question paper being re-answered plus an offline
		// queue flush must not trip the limiter.
		$throttled = self::throttle( 'answer', 400, 5 * MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$result = CES_Cbt_Attempt::save_answer(
			absint( $request->get_param( 'id' ) ),
			absint( $request->get_param( 'question_id' ) ),
			(string) $request->get_param( 'answer' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /cbt/attempts/{id}/flag
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function flag_question( $request ) {
		$throttled = self::throttle( 'flag', 200, 5 * MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$result = CES_Cbt_Attempt::set_flag(
			absint( $request->get_param( 'id' ) ),
			absint( $request->get_param( 'question_id' ) ),
			(bool) $request->get_param( 'flagged' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /cbt/attempts/{id}/submit
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit_attempt( $request ) {
		$throttled = self::throttle( 'submit', 20, 5 * MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$result = CES_Cbt_Attempt::submit( absint( $request->get_param( 'id' ) ), get_current_user_id(), 'user' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /cbt/attempts/{id}/event
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function log_event( $request ) {
		$throttled = self::throttle( 'event', 60, MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$result = CES_Cbt_Attempt::log_event(
			absint( $request->get_param( 'id' ) ),
			(string) $request->get_param( 'type' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /cbt/attempts/{id}/result
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_result( $request ) {
		$throttled = self::throttle( 'result', 60, MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$result = CES_Cbt_Attempt::result( absint( $request->get_param( 'id' ) ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /cbt/results
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function my_results( $request ) {
		$throttled = self::throttle( 'results', 60, MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$user_id = get_current_user_id();

		return rest_ensure_response(
			array(
				'total'    => CES_Cbt_Attempt::user_result_count( $user_id ),
				'attempts' => CES_Cbt_Attempt::user_results(
					$user_id,
					(int) $request->get_param( 'limit' ),
					(int) $request->get_param( 'offset' )
				),
			)
		);
	}

	/**
	 * GET /cbt/leaderboard
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function leaderboard( $request ) {
		if ( ! CES_Helpers::enabled( 'cbt_leaderboard_enabled' ) ) {
			return new WP_Error(
				'ces_cbt_leaderboard_off',
				__( 'The leaderboard is turned off.', 'classic-edu-suite' ),
				array( 'status' => 404 )
			);
		}

		$throttled = self::throttle( 'leaderboard', 30, MINUTE_IN_SECONDS );

		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$exam_id = absint( $request->get_param( 'exam_id' ) );

		return rest_ensure_response(
			array(
				'examId' => $exam_id,
				'rows'   => CES_Cbt_Attempt::leaderboard( $exam_id, (int) $request->get_param( 'limit' ) ),
			)
		);
	}
}
