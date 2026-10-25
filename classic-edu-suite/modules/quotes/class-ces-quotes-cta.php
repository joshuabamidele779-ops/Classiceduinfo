<?php
/**
 * Builds the "invite someone" call to action attached to the daily quote.
 *
 * Referrals are how students earn the coins that unlock videos, so the quote
 * popup doubles as the growth-loop nudge. The referral link itself is owned by
 * the wallet module, which may not be installed yet: every cross-module lookup
 * here is guarded and degrades to a plain link to the dashboard.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves referral links and renders-ready CTA data.
 */
class CES_Quotes_Cta {

	/**
	 * Class/method pairs that might expose a ready-made referral URL.
	 *
	 * Probed in order; the first one that exists and returns a URL wins.
	 *
	 * @return array[] Each entry: array( class, method ).
	 */
	private static function link_providers() {
		return array(
			// The name the wallet module actually ships. Listed first so the
			// canonical builder wins and the `ces_referral_link` filter still
			// receives the referral code as its third argument.
			array( 'CES_Referral', 'link' ),
			array( 'CES_Referral', 'referral_link' ),
			array( 'CES_Referral', 'link_for_user' ),
			array( 'CES_Referral', 'get_referral_link' ),
			array( 'CES_Wallet_Referral', 'referral_link' ),
			array( 'CES_Wallet_Referral', 'link_for_user' ),
			array( 'CES_Wallet', 'referral_link' ),
		);
	}

	/**
	 * Class/method pairs that might expose just the referral code.
	 *
	 * @return array[] Each entry: array( class, method ).
	 */
	private static function code_providers() {
		return array(
			array( 'CES_Referral', 'code_for_user' ),
			array( 'CES_Referral', 'get_code' ),
			array( 'CES_Referral', 'user_code' ),
			array( 'CES_Wallet_Referral', 'code_for_user' ),
			array( 'CES_Wallet', 'referral_code' ),
		);
	}

	/**
	 * The student's personal referral URL.
	 *
	 * @param int $user_id User ID, 0 for the current user.
	 * @return string URL, or '' when no referral system is available.
	 */
	public static function referral_link( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return '';
		}

		$link = '';

		foreach ( self::link_providers() as $provider ) {
			list( $class_name, $method ) = $provider;

			if ( ! class_exists( $class_name ) || ! method_exists( $class_name, $method ) ) {
				continue;
			}

			$result = call_user_func( array( $class_name, $method ), $user_id );

			if ( is_string( $result ) && '' !== trim( $result ) ) {
				$link = $result;
				break;
			}
		}

		if ( '' === $link ) {
			$code = self::referral_code( $user_id );

			if ( '' !== $code ) {
				$link = add_query_arg( 'ref', $code, home_url( '/' ) );
			}
		}

		/**
		 * Filter the referral link used by the quote popup and the dashboard.
		 *
		 * The wallet module can short-circuit every lookup above by hooking
		 * this filter instead of exposing a specific method name.
		 *
		 * @param string $link    Resolved link, '' when none was found.
		 * @param int    $user_id User the link belongs to.
		 */
		$link = (string) apply_filters( 'ces_referral_link', $link, $user_id );

		return $link ? esc_url_raw( $link ) : '';
	}

	/**
	 * The student's referral code.
	 *
	 * @param int $user_id User ID, 0 for the current user.
	 * @return string Code, or '' when none is available.
	 */
	public static function referral_code( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return '';
		}

		foreach ( self::code_providers() as $provider ) {
			list( $class_name, $method ) = $provider;

			if ( ! class_exists( $class_name ) || ! method_exists( $class_name, $method ) ) {
				continue;
			}

			$result = call_user_func( array( $class_name, $method ), $user_id );

			if ( is_string( $result ) && '' !== trim( $result ) ) {
				return sanitize_text_field( $result );
			}
		}

		// Last resort: the wallet module may simply have stored it in meta.
		$meta = get_user_meta( $user_id, 'ces_referral_code', true );

		return is_string( $meta ) ? sanitize_text_field( $meta ) : '';
	}

	/**
	 * Coins paid per qualified referral.
	 *
	 * @return int
	 */
	public static function coins_per_referral() {
		return max( 0, CES_Helpers::setting_int( 'coins_per_referral', 25 ) );
	}

	/**
	 * The CTA text with the `{coins}` placeholder resolved.
	 *
	 * @return string
	 */
	public static function cta_text() {
		$raw = (string) CES_Helpers::setting( 'quotes_cta_text', '' );

		if ( '' === trim( $raw ) ) {
			$raw = __( 'Invite a friend and earn {coins} coins', 'classic-edu-suite' );
		}

		$coins = CES_Helpers::format_coins( self::coins_per_referral() );

		return str_replace( array( '{coins}', '{COINS}' ), $coins, $raw );
	}

	/**
	 * The WhatsApp share URL for a given message.
	 *
	 * @param string $message Plain-text message, already including the link.
	 * @return string
	 */
	public static function whatsapp_url( $message ) {
		return 'https://wa.me/?text=' . rawurlencode( (string) $message );
	}

	/**
	 * Everything the popup and the dashboard need to render the invite task.
	 *
	 * @param int $user_id User ID, 0 for the current user.
	 * @return array{
	 *     enabled:bool, is_guest:bool, text:string, link:string,
	 *     button_label:string, whatsapp_url:string, share_message:string,
	 *     copy_link:string, coins:int
	 * }
	 */
	public static function data( $user_id = 0 ) {
		$user_id  = $user_id ? (int) $user_id : get_current_user_id();
		$is_guest = ! $user_id;

		$defaults = array(
			'enabled'       => (bool) CES_Helpers::enabled( 'quotes_referral_cta' ),
			'is_guest'      => $is_guest,
			'text'          => '',
			'link'          => '',
			'button_label'  => '',
			'whatsapp_url'  => '',
			'share_message' => '',
			'copy_link'     => '',
			'coins'         => self::coins_per_referral(),
		);

		$cta = $defaults;

		if ( $is_guest ) {
			$cta['text']         = __( 'Learn free, earn coins, unlock the videos.', 'classic-edu-suite' );
			$cta['button_label'] = __( 'Create a free account', 'classic-edu-suite' );
			$cta['link']         = CES_Helpers::login_url( CES_Helpers::dashboard_url() );

			/** This filter documented below. */
			return apply_filters( 'ces_quotes_cta_data', $cta, $user_id );
		}

		$cta['text'] = self::cta_text();
		$link        = self::referral_link( $user_id );

		if ( '' !== $link ) {
			$cta['link']         = $link;
			$cta['copy_link']    = $link;
			$cta['button_label'] = __( 'Share on WhatsApp', 'classic-edu-suite' );

			$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

			$message = sprintf(
				/* translators: 1: site name, 2: referral link. */
				__( "I'm using %1\$s to practise past questions and watch syllabus videos. Join me free here: %2\$s", 'classic-edu-suite' ),
				$site,
				$link
			);

			$cta['share_message'] = $message;
			$cta['whatsapp_url']  = self::whatsapp_url( $message );
		} else {
			// Wallet / referral module not available yet: keep the nudge but
			// send the student to the dashboard instead of a dead link.
			$cta['link']         = CES_Helpers::dashboard_url( 'referrals' );
			$cta['button_label'] = __( 'Invite friends', 'classic-edu-suite' );
		}

		/**
		 * Filter the invite CTA data.
		 *
		 * @param array $cta     CTA data.
		 * @param int   $user_id User the CTA is for.
		 */
		return apply_filters( 'ces_quotes_cta_data', $cta, $user_id );
	}
}
