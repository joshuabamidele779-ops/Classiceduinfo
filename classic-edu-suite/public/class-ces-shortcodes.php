<?php
/**
 * Cross-module shortcodes owned by the shared public layer.
 *
 *   [ces_login_required]…[/ces_login_required]       Content behind a login.
 *   [ces_if_subscriber]…[/ces_if_subscriber]         Only for YITH members.
 *   [ces_if_not_subscriber]…[/ces_if_not_subscriber] Only for everyone else.
 *
 * The dashboard and the quote shortcodes are registered by their own classes.
 * Nothing owned by the wallet, CBT or LMS modules is registered here.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared shortcodes.
 */
class CES_Shortcodes {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'ces_login_required', array( __CLASS__, 'login_required' ) );
		add_shortcode( 'ces_if_subscriber', array( __CLASS__, 'if_subscriber' ) );
		add_shortcode( 'ces_if_not_subscriber', array( __CLASS__, 'if_not_subscriber' ) );
	}

	/**
	 * `[ces_login_required]` — show the inner content only to signed-in users.
	 *
	 * Attributes:
	 *   message  Replaces the default prompt text.
	 *   label    Replaces the default button label.
	 *
	 * @param array  $atts    Attributes.
	 * @param string $content Enclosed content.
	 * @return string
	 */
	public static function login_required( $atts = array(), $content = '' ) {
		$atts = shortcode_atts(
			array(
				'message' => '',
				'label'   => '',
			),
			$atts,
			'ces_login_required'
		);

		if ( is_user_logged_in() ) {
			return do_shortcode( (string) $content );
		}

		if ( class_exists( 'CES_Assets' ) && method_exists( 'CES_Assets', 'enqueue_base' ) ) {
			CES_Assets::enqueue_base();
		}

		$message = '' !== trim( (string) $atts['message'] )
			? (string) $atts['message']
			: __( 'Please sign in to see this.', 'classic-edu-suite' );

		$label = '' !== trim( (string) $atts['label'] )
			? (string) $atts['label']
			: __( 'Sign in', 'classic-edu-suite' );

		$url = CES_Helpers::login_url( self::current_url() );

		$html  = '<div class="ces-gate">';
		$html .= '<p class="ces-gate__message">' . esc_html( $message ) . '</p>';
		$html .= '<p class="ces-gate__action"><a class="ces-btn ces-btn--primary" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * `[ces_if_subscriber]` — content for members and subscribers only.
	 *
	 * @param array  $atts    Attributes (unused).
	 * @param string $content Enclosed content.
	 * @return string
	 */
	public static function if_subscriber( $atts = array(), $content = '' ) {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return '';
		}

		return CES_Helpers::is_subscriber() ? do_shortcode( (string) $content ) : '';
	}

	/**
	 * `[ces_if_not_subscriber]` — content for everyone without a membership,
	 * including logged-out visitors.
	 *
	 * @param array  $atts    Attributes (unused).
	 * @param string $content Enclosed content.
	 * @return string
	 */
	public static function if_not_subscriber( $atts = array(), $content = '' ) {
		unset( $atts );

		if ( is_user_logged_in() && CES_Helpers::is_subscriber() ) {
			return '';
		}

		return do_shortcode( (string) $content );
	}

	/**
	 * The current front-end URL, for login redirects.
	 *
	 * @return string
	 */
	public static function current_url() {
		$post_id = get_queried_object_id();

		if ( $post_id && is_singular() ) {
			$permalink = get_permalink( $post_id );

			if ( $permalink ) {
				return $permalink;
			}
		}

		return esc_url_raw( home_url( add_query_arg( array() ) ) );
	}
}
