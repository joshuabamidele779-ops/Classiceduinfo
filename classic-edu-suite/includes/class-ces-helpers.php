<?php
/**
 * Shared helpers used by every module.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static utility methods.
 */
class CES_Helpers {

	/**
	 * Cached settings array for this request.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Full settings array, defaults merged in.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( null === self::$settings ) {
			$stored = get_option( CES_Settings::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			self::$settings = array_merge( CES_Settings::defaults(), $stored );
		}

		return self::$settings;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function setting( $key, $default = null ) {
		$settings = self::settings();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		return $default;
	}

	/**
	 * Read one setting as an integer.
	 *
	 * @param string $key     Setting key.
	 * @param int    $default Fallback.
	 * @return int
	 */
	public static function setting_int( $key, $default = 0 ) {
		$value = self::setting( $key, $default );
		return (int) $value;
	}

	/**
	 * Whether a setting is truthy.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function enabled( $key ) {
		return (bool) self::setting_int( $key, 0 );
	}

	/**
	 * Prefixed table name.
	 *
	 * @param string $name Short table name, e.g. 'attempts'.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'ces_' . $name;
	}

	/**
	 * Current UTC-corrected MySQL datetime.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Client IP address, best effort behind a proxy or Cloudflare.
	 *
	 * @return string
	 */
	public static function ip() {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$raw  = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$part = trim( explode( ',', $raw )[0] );

			if ( filter_var( $part, FILTER_VALIDATE_IP ) ) {
				return $part;
			}
		}

		return '';
	}

	/**
	 * Truncated user agent string.
	 *
	 * @return string
	 */
	public static function user_agent() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		return substr( $ua, 0, 255 );
	}

	/**
	 * Stable pseudonymous hash for a visitor, for guest-side rate limiting.
	 *
	 * Deliberately coarse: IP plus user agent, salted per install. Not a
	 * fingerprint and not stored against a person's identity.
	 *
	 * @return string
	 */
	public static function visitor_hash() {
		return hash_hmac( 'sha256', self::ip() . '|' . self::user_agent(), self::stream_secret() );
	}

	/**
	 * The per-install secret used for signing tokens.
	 *
	 * @return string
	 */
	public static function stream_secret() {
		$secret = get_option( 'ces_stream_secret' );

		if ( ! $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'ces_stream_secret', $secret );
		}

		return $secret;
	}

	/**
	 * Whether the user holds an active YITH subscription or membership.
	 *
	 * Checked in this order: YITH Membership, YITH Subscription, then the
	 * `ces_unlimited_access` user meta flag as a manual override. Falls back
	 * to false when neither plugin is active, so the coin path stays in play.
	 *
	 * @param int $user_id Optional user ID, defaults to the current user.
	 * @return bool
	 */
	public static function is_subscriber( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		if ( '1' === (string) get_user_meta( $user_id, 'ces_unlimited_access', true ) ) {
			return true;
		}

		// YITH WooCommerce Membership.
		if ( function_exists( 'YITH_WCMBS_Members' ) ) {
			$members = YITH_WCMBS_Members();
			if ( is_object( $members ) && method_exists( $members, 'get_member_memberships' ) ) {
				$memberships = $members->get_member_memberships( $user_id, array( 'status' => 'active' ) );
				if ( ! empty( $memberships ) ) {
					return true;
				}
			}
		}

		// YITH WooCommerce Subscription.
		if ( class_exists( 'YWSBS_Subscription_Helper' ) || post_type_exists( 'ywsbs_subscription' ) ) {
			$subs = get_posts(
				array(
					'post_type'      => 'ywsbs_subscription',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'   => 'user_id',
							'value' => $user_id,
						),
						array(
							'key'     => 'status',
							'value'   => array( 'active', 'paused', 'pending-cancel', 'trial' ),
							'compare' => 'IN',
						),
					),
				)
			);

			if ( ! empty( $subs ) ) {
				return true;
			}
		}

		/**
		 * Filter the unlimited-access decision.
		 *
		 * @param bool $is_subscriber Result so far.
		 * @param int  $user_id       User being checked.
		 */
		return (bool) apply_filters( 'ces_is_subscriber', false, $user_id );
	}

	/**
	 * Whether the current user may administer the plugin.
	 *
	 * @param string $cap Specific capability to test.
	 * @return bool
	 */
	public static function can_manage( $cap = 'ces_manage' ) {
		return current_user_can( $cap ) || current_user_can( 'manage_options' );
	}

	/**
	 * Build the student dashboard URL.
	 *
	 * @param string $tab Optional tab slug.
	 * @return string
	 */
	public static function dashboard_url( $tab = '' ) {
		$page_id = self::setting_int( 'dashboard_page_id' );
		$url     = $page_id ? get_permalink( $page_id ) : home_url( '/student-dashboard/' );

		if ( ! $url ) {
			$url = home_url( '/student-dashboard/' );
		}

		return $tab ? add_query_arg( 'tab', sanitize_key( $tab ), $url ) : $url;
	}

	/**
	 * Login page URL, respecting the Profile Builder login page in use.
	 *
	 * @param string $redirect_to Optional URL to return to after login.
	 * @return string
	 */
	public static function login_url( $redirect_to = '' ) {
		$path = self::setting( 'login_page_url', '/login/' );
		$url  = ( 0 === strpos( (string) $path, 'http' ) ) ? $path : home_url( $path );

		if ( $redirect_to ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url );
		}

		return $url;
	}

	/**
	 * Format a coin amount for display.
	 *
	 * @param int $amount Coin count.
	 * @return string
	 */
	public static function format_coins( $amount ) {
		return number_format_i18n( (int) $amount );
	}

	/**
	 * Map a subject to one of the artwork keys the stylesheet knows about.
	 *
	 * ces-base.css carries a `--ces-art-<key>` illustration for each key
	 * below, and the course and exam cards set `data-ces-art` from this, so a
	 * course with no featured image still shows subject-appropriate science
	 * artwork instead of a grey box.
	 *
	 * Matching is on the human-readable term name, which is what the CBT and
	 * LMS modules already have in hand, and is deliberately loose: "Maths",
	 * "Mathematics" and "Further Maths" all land on the same drawing. An
	 * unrecognised subject falls back to `general`, never to nothing.
	 *
	 * @param string $subject Subject term name, or any free text.
	 * @return string One of maths, english, biology, chemistry, physics,
	 *                geography, history, general.
	 */
	public static function subject_art( $subject ) {
		$text = strtolower( (string) $subject );

		$map = array(
			'maths'     => array( 'math', 'algebra', 'geometr', 'calculus', 'statistic', 'arithmetic', 'quantitative' ),
			'chemistry' => array( 'chemi' ),
			'physics'   => array( 'physic', 'electric', 'mechanic' ),
			'biology'   => array( 'biolog', 'agric', 'health', 'anatomy', 'botan', 'zoolog', 'science' ),
			'english'   => array( 'english', 'literature', 'comprehension', 'grammar', 'verbal', 'language', 'french' ),
			'geography' => array( 'geograph', 'econom', 'commerce', 'account', 'business' ),
			'history'   => array( 'histor', 'government', 'civic', 'religio', 'crk', 'irs', 'literacy' ),
		);

		/**
		 * Filter the subject-to-artwork keyword map.
		 *
		 * Add a key here and a matching `--ces-art-<key>` custom property in
		 * your theme to ship artwork for a subject of your own.
		 *
		 * @param array  $map  Artwork key => keywords to look for.
		 * @param string $text Lower-cased subject text being matched.
		 */
		$map = apply_filters( 'ces_subject_art_map', $map, $text );

		foreach ( (array) $map as $key => $needles ) {
			foreach ( (array) $needles as $needle ) {
				if ( '' !== (string) $needle && false !== strpos( $text, (string) $needle ) ) {
					return (string) $key;
				}
			}
		}

		return 'general';
	}

	/**
	 * Write to the debug log when WP_DEBUG is on.
	 *
	 * @param string $message Message.
	 * @param mixed  $context Optional context, var_export'ed.
	 * @return void
	 */
	public static function log( $message, $context = null ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$line = '[Classic EDU Suite] ' . $message;

		if ( null !== $context ) {
			$line .= ' ' . var_export( $context, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Simple per-user/per-IP rate limiter backed by transients.
	 *
	 * @param string $action  Action slug.
	 * @param int    $limit   Allowed hits in the window.
	 * @param int    $window  Window length in seconds.
	 * @return bool True when the call is allowed, false when throttled.
	 */
	public static function rate_limit( $action, $limit = 30, $window = 60 ) {
		$actor = get_current_user_id() ? 'u' . get_current_user_id() : 'h' . substr( self::visitor_hash(), 0, 16 );
		$key   = 'ces_rl_' . md5( $action . '|' . $actor );
		$hits  = (int) get_transient( $key );

		if ( $hits >= $limit ) {
			return false;
		}

		set_transient( $key, $hits + 1, $window );

		return true;
	}

	/**
	 * Resolve a template override, falling back to the plugin's own file.
	 *
	 * Themes can override any template by placing it in
	 * `wp-content/themes/<theme>/classic-edu-suite/<relative>`.
	 *
	 * @param string $relative Template path relative to the templates dir.
	 * @return string Absolute path, or '' when nothing matched.
	 */
	public static function locate_template( $relative ) {
		$relative = ltrim( $relative, '/' );
		$override = locate_template( 'classic-edu-suite/' . $relative );

		if ( $override ) {
			return $override;
		}

		$path = CES_PATH . 'templates/' . $relative;

		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Render a template with variables extracted into scope.
	 *
	 * @param string $relative Template path relative to the templates dir.
	 * @param array  $vars     Variables made available to the template.
	 * @return string Rendered markup.
	 */
	public static function render( $relative, array $vars = array() ) {
		$path = self::locate_template( $relative );

		if ( ! $path ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );

		ob_start();
		include $path;

		return (string) ob_get_clean();
	}
}
