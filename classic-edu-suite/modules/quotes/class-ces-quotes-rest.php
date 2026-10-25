<?php
/**
 * REST routes for the quotes module: `ces/v1/quotes/*`.
 *
 * Read is safe for logged-out visitors (when guests are allowed) and never
 * writes. Every write route verifies the `wp_rest` nonce explicitly — core
 * only enforces it when one is supplied, which would leave anonymous writes
 * unprotected — and is rate limited.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the quote endpoints.
 */
class CES_Quotes_Rest {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$quote_arg = array(
			'quote_id' => array(
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => array( __CLASS__, 'validate_id' ),
			),
		);

		register_rest_route(
			CES_REST_NAMESPACE,
			'/quotes/today',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'today' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			CES_REST_NAMESPACE,
			'/quotes/impression',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'impression' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => $quote_arg,
			)
		);

		register_rest_route(
			CES_REST_NAMESPACE,
			'/quotes/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'dismiss' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => $quote_arg,
			)
		);

		register_rest_route(
			CES_REST_NAMESPACE,
			'/quotes/cta',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cta' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => $quote_arg,
			)
		);
	}

	/**
	 * Validate an optional post ID argument.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function validate_id( $value ) {
		return is_numeric( $value ) && (int) $value >= 0;
	}

	/**
	 * Read permission: logged-in always, guests only when allowed.
	 *
	 * @return bool|WP_Error
	 */
	public static function can_read() {
		if ( ! is_user_logged_in() && ! CES_Helpers::enabled( 'quotes_show_to_guests' ) ) {
			return new WP_Error(
				'ces_quotes_forbidden',
				__( 'Please sign in to see your daily quote.', 'classic-edu-suite' ),
				array( 'status' => 401 )
			);
		}

		if ( ! CES_Helpers::rate_limit( 'quotes_read', 60, MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'ces_quotes_rate_limited',
				__( 'Too many requests. Please slow down.', 'classic-edu-suite' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Write permission: valid REST nonce, allowed audience, rate limited.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function can_write( $request ) {
		if ( ! self::verify_nonce( $request ) ) {
			return new WP_Error(
				'ces_quotes_bad_nonce',
				__( 'Security check failed. Please reload the page.', 'classic-edu-suite' ),
				array( 'status' => 403 )
			);
		}

		if ( ! is_user_logged_in() && ! CES_Helpers::enabled( 'quotes_show_to_guests' ) ) {
			return new WP_Error(
				'ces_quotes_forbidden',
				__( 'You cannot do that.', 'classic-edu-suite' ),
				array( 'status' => 403 )
			);
		}

		if ( ! CES_Helpers::rate_limit( 'quotes_write', 20, MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'ces_quotes_rate_limited',
				__( 'Too many requests. Please slow down.', 'classic-edu-suite' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Verify the `wp_rest` nonce from the header or the request body.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	private static function verify_nonce( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}

		$nonce = (string) $request->get_header( 'X-WP-Nonce' );

		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		if ( '' === $nonce ) {
			return false;
		}

		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * GET /quotes/today — the current viewer's quote plus the invite CTA.
	 *
	 * Does not log an impression: the popup reports that separately, once it
	 * has actually been displayed.
	 *
	 * @return WP_REST_Response
	 */
	public static function today() {
		if ( ! CES_Helpers::enabled( 'quotes_enabled' ) ) {
			return rest_ensure_response(
				array(
					'enabled' => false,
					'quote'   => null,
				)
			);
		}

		$quote_id = (int) CES_Quotes_Scheduler::quote_for_viewer();

		if ( $quote_id <= 0 ) {
			return rest_ensure_response(
				array(
					'enabled' => true,
					'quote'   => null,
				)
			);
		}

		$quote = CES_Quotes_Cpt::to_array( $quote_id );
		$cta   = CES_Quotes_Cta::data();

		return rest_ensure_response(
			array(
				'enabled' => true,
				'quote'   => array(
					'id'         => $quote['id'],
					'text'       => $quote['text'],
					'author'     => $quote['author'],
					'categories' => $quote['categories'],
				),
				'cta'     => array(
					'enabled'      => ! empty( $cta['enabled'] ),
					'is_guest'     => ! empty( $cta['is_guest'] ),
					'text'         => isset( $cta['text'] ) ? $cta['text'] : '',
					'link'         => isset( $cta['link'] ) ? $cta['link'] : '',
					'button_label' => isset( $cta['button_label'] ) ? $cta['button_label'] : '',
					'whatsapp_url' => isset( $cta['whatsapp_url'] ) ? $cta['whatsapp_url'] : '',
				),
			)
		);
	}

	/**
	 * POST /quotes/impression — the popup was displayed.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function impression( $request ) {
		$quote_id = self::resolve_quote_id( $request );

		if ( $quote_id <= 0 ) {
			return rest_ensure_response(
				array(
					'logged' => false,
					'reason' => 'no_quote',
				)
			);
		}

		$logged = CES_Quotes_Scheduler::log_impression( $quote_id, get_current_user_id(), self::visitor_hash() );

		return rest_ensure_response(
			array(
				'logged'   => (bool) $logged,
				'quote_id' => $quote_id,
			)
		);
	}

	/**
	 * POST /quotes/dismiss — the student closed the popup.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function dismiss( $request ) {
		return self::flag( 'dismissed', $request );
	}

	/**
	 * POST /quotes/cta — the student clicked the invite task.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function cta( $request ) {
		return self::flag( 'cta_clicked', $request );
	}

	/**
	 * Shared handler for the two engagement flags.
	 *
	 * @param string          $column  'dismissed' or 'cta_clicked'.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	private static function flag( $column, $request ) {
		$quote_id = self::resolve_quote_id( $request );

		if ( $quote_id <= 0 ) {
			return rest_ensure_response(
				array(
					'recorded' => false,
					'reason'   => 'no_quote',
				)
			);
		}

		$done = CES_Quotes_Scheduler::mark( $column, $quote_id, get_current_user_id(), self::visitor_hash() );

		/**
		 * Fires after a popup engagement event is recorded.
		 *
		 * @param string $column   'dismissed' or 'cta_clicked'.
		 * @param int    $quote_id Quote involved.
		 * @param int    $user_id  User, 0 for a guest.
		 */
		do_action( 'ces_quote_engagement', $column, $quote_id, get_current_user_id() );

		return rest_ensure_response(
			array(
				'recorded' => (bool) $done,
				'quote_id' => $quote_id,
			)
		);
	}

	/**
	 * Trust the server, not the client: a supplied ID is only accepted when
	 * it is genuinely an eligible quote, otherwise we re-resolve it.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return int
	 */
	private static function resolve_quote_id( $request ) {
		$claimed = 0;

		if ( $request instanceof WP_REST_Request ) {
			$claimed = absint( $request->get_param( 'quote_id' ) );
		}

		if ( $claimed > 0 && CES_Quotes_Scheduler::is_eligible( $claimed ) ) {
			return $claimed;
		}

		return (int) CES_Quotes_Scheduler::quote_for_viewer();
	}

	/**
	 * Guest key for the current request, '' for logged-in users.
	 *
	 * @return string
	 */
	private static function visitor_hash() {
		return get_current_user_id() ? '' : CES_Helpers::visitor_hash();
	}
}
