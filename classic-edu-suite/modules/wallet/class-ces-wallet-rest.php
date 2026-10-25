<?php
/**
 * REST endpoints for the coin wallet, under `/ces/v1/wallet/`.
 *
 * Read-only on purpose: coins are never spent or minted from the browser.
 * Spending lives in the module that owns the thing being bought (the LMS
 * unlock endpoint), so there is no generic "give me coins" surface.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wallet REST controller.
 */
class CES_Wallet_Rest {

	/**
	 * Register every route. Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$namespace = CES_REST_NAMESPACE;

		register_rest_route(
			$namespace,
			'/wallet/balance',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_balance' ),
				'permission_callback' => array( __CLASS__, 'permissions' ),
			)
		);

		register_rest_route(
			$namespace,
			'/wallet/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_history' ),
				'permission_callback' => array( __CLASS__, 'permissions' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_positive' ),
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_positive' ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/wallet/referrals',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_referrals' ),
				'permission_callback' => array( __CLASS__, 'permissions' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_positive' ),
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_positive' ),
					),
				),
			)
		);
	}

	/**
	 * Student endpoints: logged in, nonce-checked and rate limited.
	 *
	 * WordPress cookie authentication already rejects a browser request whose
	 * `X-WP-Nonce` header is missing or stale (the user resolves to 0), so the
	 * explicit check below is defence in depth.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function permissions( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'ces_not_logged_in',
				__( 'Please log in to view your coins.', 'classic-edu-suite' ),
				array( 'status' => 401 )
			);
		}

		$nonce = $request instanceof WP_REST_Request ? $request->get_header( 'X-WP-Nonce' ) : '';

		if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'ces_bad_nonce',
				__( 'Your session expired. Please reload the page.', 'classic-edu-suite' ),
				array( 'status' => 403 )
			);
		}

		if ( ! CES_Helpers::rate_limit( 'wallet_rest', 60, MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'ces_rate_limited',
				__( 'Too many requests. Please slow down.', 'classic-edu-suite' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Validate that a numeric argument is at least 1.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function validate_positive( $value ) {
		return is_numeric( $value ) && (int) $value >= 1;
	}

	/**
	 * GET /wallet/balance
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_balance( $request ) {
		unset( $request );

		$user_id = get_current_user_id();
		$balance = CES_Wallet::balance( $user_id );
		$streak  = CES_Rewards::streak( $user_id );

		return rest_ensure_response(
			array(
				'balance'   => $balance,
				'formatted' => CES_Helpers::format_coins( $balance ),
				'streak'    => $streak,
			)
		);
	}

	/**
	 * GET /wallet/history
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_history( $request ) {
		$user_id  = get_current_user_id();
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$rows  = CES_Wallet::history( $user_id, $per_page, $offset );
		$total = CES_Wallet::history_count( $user_id );

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'id'            => (int) $row['id'],
				'delta'         => (int) $row['delta'],
				'balance_after' => (int) $row['balance_after'],
				'type'          => (string) $row['type'],
				'label'         => (string) $row['label'],
				'note'          => (string) $row['note'],
				'created_at'    => (string) $row['created_at'],
				'created_human' => self::human_date( $row['created_at'] ),
			);
		}

		$response = rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
				'balance'     => CES_Wallet::balance( $user_id ),
			)
		);

		$response->header( 'X-WP-Total', (string) $total );

		return $response;
	}

	/**
	 * GET /wallet/referrals
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_referrals( $request ) {
		$user_id  = get_current_user_id();
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$stats = CES_Referral::stats( $user_id );
		$rows  = CES_Referral::list_for_referrer( $user_id, $per_page, $offset );

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'name'        => (string) $row['name'],
				'status'      => (string) $row['status'],
				'reward_paid' => (int) $row['reward_paid'],
				'reason'      => (string) $row['reason'],
				'created_at'  => (string) $row['created_at'],
				'joined'      => self::human_date( $row['created_at'] ),
			);
		}

		return rest_ensure_response(
			array(
				'stats'    => $stats,
				'items'    => $items,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * Format a MySQL datetime for display.
	 *
	 * @param string $datetime MySQL datetime.
	 * @return string
	 */
	private static function human_date( $datetime ) {
		$datetime = (string) $datetime;

		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime );

		if ( ! $timestamp ) {
			return '';
		}

		return date_i18n( (string) get_option( 'date_format', 'j M Y' ), $timestamp );
	}
}
