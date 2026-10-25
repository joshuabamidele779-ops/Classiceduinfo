<?php
/**
 * REST routes for study mode.
 *
 * Namespace `ces/v1`, prefix `/hub`. Every route requires a logged-in student
 * and confirms the session belongs to them. Nothing here ever returns a
 * correct answer before the student has committed to one.
 *
 * @package ClassicEduSuite\Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route registration and handlers.
 */
class CES_Hub_Rest {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Only signed-in students may study.
	 *
	 * @return bool|WP_Error
	 */
	public static function require_login() {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'ces_hub_login',
			__( 'Please sign in to study.', 'classic-edu-suite' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$namespace = CES_REST_NAMESPACE;

		register_rest_route(
			$namespace,
			'/hub/study',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'subject'   => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'exam_type' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'topic'     => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'year'      => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'count'     => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/hub/study/(?P<session>[A-Za-z0-9]+)/question',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'question' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'session' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_session' ),
					),
					'index'   => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/hub/study/(?P<session>[A-Za-z0-9]+)/answer',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'answer' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'session'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_session' ),
					),
					'question' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'answer'   => array(
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/hub/study/(?P<session>[A-Za-z0-9]+)/finish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'finish' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'session' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_session' ),
					),
				),
			)
		);
	}

	/**
	 * Strip a session key to the characters it is generated from.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_session( $value ) {
		return preg_replace( '/[^A-Za-z0-9]/', '', (string) $value );
	}

	/**
	 * POST /hub/study
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( WP_REST_Request $request ) {
		if ( ! CES_Helpers::rate_limit( 'study_start', 30, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ces_hub_rate', __( 'Slow down a moment, then try again.', 'classic-edu-suite' ), array( 'status' => 429 ) );
		}

		$result = CES_Hub_Study::start(
			get_current_user_id(),
			array(
				'subject'   => (int) $request['subject'],
				'exam_type' => (int) $request['exam_type'],
				'topic'     => (int) $request['topic'],
				'year'      => (int) $request['year'],
				'count'     => (int) $request['count'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /hub/study/{session}/question
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function question( WP_REST_Request $request ) {
		$result = CES_Hub_Study::question(
			(string) $request['session'],
			get_current_user_id(),
			(int) $request['index']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /hub/study/{session}/answer
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function answer( WP_REST_Request $request ) {
		if ( ! CES_Helpers::rate_limit( 'study_answer', 300, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ces_hub_rate', __( 'Slow down a moment, then try again.', 'classic-edu-suite' ), array( 'status' => 429 ) );
		}

		$given = $request['answer'];

		// Accept "A", "A,B" or [ "A", "B" ]; the question class normalises it.
		if ( ! is_array( $given ) ) {
			$given = (string) $given;
		}

		$result = CES_Hub_Study::answer(
			(string) $request['session'],
			get_current_user_id(),
			(int) $request['question'],
			$given
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /hub/study/{session}/finish
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function finish( WP_REST_Request $request ) {
		$result = CES_Hub_Study::finish( (string) $request['session'], get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
