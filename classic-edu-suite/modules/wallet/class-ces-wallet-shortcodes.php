<?php
/**
 * Wallet shortcodes: `[ces_coin_balance]`, `[ces_referral_link]` and
 * `[ces_referral_stats]`.
 *
 * All three are safe to drop into an Elementor widget or a page. Each one
 * renders nothing useful for a logged-out visitor beyond a login link, and
 * each returns a string rather than echoing.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode handlers owned by the wallet module.
 */
class CES_Wallet_Shortcodes {

	/**
	 * Register every shortcode.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( 'ces_coin_balance', array( __CLASS__, 'coin_balance' ) );
		add_shortcode( 'ces_referral_link', array( __CLASS__, 'referral_link' ) );
		add_shortcode( 'ces_referral_stats', array( __CLASS__, 'referral_stats' ) );
	}

	/**
	 * `[ces_coin_balance label="My coins" link="1"]`
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function coin_balance( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'label' => '',
				'link'  => '1',
			),
			is_array( $atts ) ? $atts : array(),
			'ces_coin_balance'
		);

		if ( ! is_user_logged_in() ) {
			return self::login_link( __( 'Log in to see your coins', 'classic-edu-suite' ) );
		}

		CES_Wallet_Module::enqueue_assets();

		$balance = CES_Wallet::balance( get_current_user_id() );

		$inner = '<span class="ces-chip__icon" aria-hidden="true">🪙</span>'
			. '<span class="ces-chip__value" data-ces-balance>' . esc_html( CES_Helpers::format_coins( $balance ) ) . '</span>';

		if ( '' !== (string) $atts['label'] ) {
			$inner .= '<span class="ces-chip__label">' . esc_html( $atts['label'] ) . '</span>';
		}

		if ( '1' === (string) $atts['link'] ) {
			return '<a class="ces-chip ces-chip--coins" href="' . esc_url( CES_Helpers::dashboard_url( 'coins' ) ) . '">' . $inner . '</a>';
		}

		return '<span class="ces-chip ces-chip--coins">' . $inner . '</span>';
	}

	/**
	 * `[ces_referral_link whatsapp="1"]`
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function referral_link( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'whatsapp' => '1',
			),
			is_array( $atts ) ? $atts : array(),
			'ces_referral_link'
		);

		if ( ! is_user_logged_in() ) {
			return self::login_link( __( 'Log in to get your invite link', 'classic-edu-suite' ) );
		}

		CES_Wallet_Module::enqueue_assets();

		$user_id = get_current_user_id();
		$link    = CES_Referral::link( $user_id );

		if ( '' === $link ) {
			return '';
		}

		$html  = '<div class="ces-wallet ces-invite-widget">';
		$html .= '<div class="ces-invite__link-row">';
		$html .= '<input type="text" class="ces-invite__link" readonly value="' . esc_attr( $link ) . '" aria-label="' . esc_attr__( 'Your referral link', 'classic-edu-suite' ) . '" data-ces-link />';
		$html .= '<button type="button" class="ces-btn ces-btn--ghost ces-invite__copy" data-ces-copy="' . esc_attr( $link ) . '">' . esc_html__( 'Copy', 'classic-edu-suite' ) . '</button>';
		$html .= '</div>';

		if ( '1' === (string) $atts['whatsapp'] ) {
			$whatsapp = CES_Referral::whatsapp_url( $user_id );

			if ( $whatsapp ) {
				$html .= '<p class="ces-invite__share"><a class="ces-btn ces-btn--whatsapp" href="' . esc_url( $whatsapp ) . '" target="_blank" rel="noopener noreferrer">'
					. esc_html__( 'Share on WhatsApp', 'classic-edu-suite' ) . '</a></p>';
			}
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * `[ces_referral_stats]`
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function referral_stats( $atts = array() ) {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return self::login_link( __( 'Log in to see your invites', 'classic-edu-suite' ) );
		}

		CES_Wallet_Module::enqueue_assets();

		$stats = CES_Referral::stats( get_current_user_id() );

		$tiles = array(
			__( 'Qualified', 'classic-edu-suite' )    => $stats['qualified'],
			__( 'Pending', 'classic-edu-suite' )      => $stats['pending'],
			__( 'Coins earned', 'classic-edu-suite' ) => $stats['coins_earned'],
		);

		$html = '<div class="ces-wallet ces-invite__stats">';

		foreach ( $tiles as $label => $value ) {
			$html .= '<div class="ces-stat">';
			$html .= '<span class="ces-stat__value">' . esc_html( CES_Helpers::format_coins( (int) $value ) ) . '</span>';
			$html .= '<span class="ces-stat__label">' . esc_html( $label ) . '</span>';
			$html .= '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * A login link used by every shortcode for logged-out visitors.
	 *
	 * @param string $text Link text.
	 * @return string
	 */
	private static function login_link( $text ) {
		$redirect = CES_Helpers::dashboard_url( 'coins' );

		return '<a class="ces-login-link" href="' . esc_url( CES_Helpers::login_url( $redirect ) ) . '">' . esc_html( $text ) . '</a>';
	}
}
