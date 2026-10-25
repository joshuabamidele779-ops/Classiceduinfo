<?php
/**
 * Shared front-end asset layer.
 *
 * Owns one style handle and one script handle, both named `ces-base`. Every
 * other module declares `ces-base` as a dependency rather than shipping its
 * own copy of the design tokens or re-deriving the REST root:
 *
 *     wp_enqueue_style( 'ces-cbt-exam', $url, array( 'ces-base' ), CES_VERSION );
 *     wp_enqueue_script( 'ces-cbt-exam', $url, array( 'ces-base' ), CES_VERSION, true );
 *
 * A dependency is enough: WordPress enqueues `ces-base` automatically, and the
 * `window.CES` object is printed before it. A module that needs the handle
 * without a dependency can call `CES_Assets::enqueue_base()`.
 *
 * The handles are only ever *registered* on every request, which costs
 * nothing; they are *enqueued* when something on the page actually needs them.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and conditionally enqueues the shared front-end assets.
 */
class CES_Assets {

	/**
	 * Shared style and script handle.
	 */
	const HANDLE = 'ces-base';

	/**
	 * Default brand colour, the site's existing blue.
	 */
	const DEFAULT_PRIMARY = '#2c62cd';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 20 );
	}

	/**
	 * Register the shared handles. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function register() {
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style(
			self::HANDLE,
			CES_URL . 'public/assets/ces-base.css',
			array(),
			CES_VERSION
		);

		wp_add_inline_style( self::HANDLE, self::tokens_css() );

		wp_register_script(
			self::HANDLE,
			CES_URL . 'public/assets/ces-base.js',
			array(),
			CES_VERSION,
			true
		);

		// `before` guarantees window.CES exists for ces-base.js and for every
		// script that depends on this handle.
		wp_add_inline_script(
			self::HANDLE,
			'window.CES = window.CES || {}; Object.assign( window.CES, ' . wp_json_encode( self::config() ) . ' );',
			'before'
		);
	}

	/**
	 * Enqueue the shared handles.
	 *
	 * @return void
	 */
	public static function enqueue_base() {
		self::register();
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Enqueue the shared assets when this page needs them.
	 *
	 * @return void
	 */
	public static function maybe_enqueue() {
		if ( ! self::needed() ) {
			return;
		}

		self::enqueue_base();
	}

	/**
	 * Whether anything on this page needs the shared assets.
	 *
	 * @return bool
	 */
	public static function needed() {
		$needed = false;

		if ( class_exists( 'CES_Quotes_Popup' ) && method_exists( 'CES_Quotes_Popup', 'should_show' ) && CES_Quotes_Popup::should_show() ) {
			$needed = true;
		}

		if ( ! $needed && self::post_has_our_shortcode() ) {
			$needed = true;
		}

		/**
		 * Force the shared `ces-base` assets onto a page.
		 *
		 * Modules that render outside the post content (widgets, Elementor
		 * templates, hooks) can use this instead of enqueueing directly.
		 *
		 * @param bool $needed Decision so far.
		 */
		return (bool) apply_filters( 'ces_needs_base_assets', $needed );
	}

	/**
	 * Whether the queried post contains one of the shortcodes we own.
	 *
	 * @return bool
	 */
	private static function post_has_our_shortcode() {
		if ( ! is_singular() && ! is_page() ) {
			return false;
		}

		$post = get_post();

		if ( ! $post || '' === (string) $post->post_content ) {
			return false;
		}

		/**
		 * Shortcodes whose presence pulls in the shared assets.
		 *
		 * @param string[] $shortcodes Shortcode tags.
		 */
		$shortcodes = apply_filters(
			'ces_base_asset_shortcodes',
			array(
				'ces_dashboard',
				'ces_quote_of_the_day',
				'ces_random_quote',
				'ces_login_required',
				'ces_if_subscriber',
				'ces_if_not_subscriber',
			)
		);

		foreach ( (array) $shortcodes as $tag ) {
			if ( has_shortcode( $post->post_content, (string) $tag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The `window.CES` payload.
	 *
	 * Shape, relied on by every module:
	 *   restUrl      string  Namespaced REST root, trailing slash included.
	 *   nonce        string  `wp_rest` nonce for the X-WP-Nonce header.
	 *   isLoggedIn   bool
	 *   userId       int     0 for a guest.
	 *   dashboardUrl string
	 *   loginUrl     string
	 *   version      string  CES_VERSION.
	 *
	 * @return array
	 */
	public static function config() {
		return array(
			'restUrl'      => esc_url_raw( trailingslashit( rest_url( CES_REST_NAMESPACE ) ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'isLoggedIn'   => is_user_logged_in(),
			'userId'       => (int) get_current_user_id(),
			'dashboardUrl' => esc_url_raw( CES_Helpers::dashboard_url() ),
			'loginUrl'     => esc_url_raw( CES_Helpers::login_url() ),
			'version'      => CES_VERSION,
		);
	}

	/**
	 * The brand colour as a validated hex string.
	 *
	 * @return string
	 */
	public static function brand_primary() {
		$raw = trim( (string) CES_Helpers::setting( 'brand_primary', self::DEFAULT_PRIMARY ) );

		if ( function_exists( 'sanitize_hex_color' ) ) {
			$colour = sanitize_hex_color( $raw );

			return $colour ? $colour : self::DEFAULT_PRIMARY;
		}

		return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $raw ) ? $raw : self::DEFAULT_PRIMARY;
	}

	/**
	 * Design tokens, emitted inline so the brand colour stays editable.
	 *
	 * @return string CSS.
	 */
	public static function tokens_css() {
		$primary = self::brand_primary();
		$rgb     = self::hex_to_rgb( $primary );
		$dark    = self::shade( $primary, -0.22 );

		$tokens = array(
			'--ces-primary'         => $primary,
			'--ces-primary-dark'    => $dark,
			'--ces-primary-soft'    => sprintf( 'rgba( %d, %d, %d, 0.08 )', $rgb[0], $rgb[1], $rgb[2] ),
			'--ces-primary-border'  => sprintf( 'rgba( %d, %d, %d, 0.28 )', $rgb[0], $rgb[1], $rgb[2] ),
			'--ces-surface'         => '#ffffff',
			'--ces-surface-alt'     => '#f8fafc',
			'--ces-text'            => '#1f2937',
			'--ces-text-muted'      => '#475569',
			'--ces-border'          => '#cbd5e1',
			'--ces-muted-bg'        => '#f1f5f9',
			'--ces-muted-bg-strong' => '#e2e8f0',
			'--ces-radius'          => '12px',
			'--ces-coin'            => '#d9a300',
			'--ces-accent'          => '#f59e0b',
			'--ces-ink'             => '#0f172a',
		);

		/**
		 * Filter the shared design tokens.
		 *
		 * @param array  $tokens  Custom property => value.
		 * @param string $primary Resolved brand colour.
		 */
		$tokens = apply_filters( 'ces_design_tokens', $tokens, $primary );

		$css = '';

		foreach ( (array) $tokens as $name => $value ) {
			$name  = preg_replace( '/[^a-z0-9\-]/i', '', (string) $name );
			$value = str_replace( array( ';', '}', '{' ), '', (string) $value );

			if ( '' === $name || '' === $value ) {
				continue;
			}

			$css .= $name . ':' . $value . ';';
		}

		return ':root{' . $css . '}';
	}

	/**
	 * Split a hex colour into RGB components.
	 *
	 * @param string $hex Hex colour, with or without the leading hash.
	 * @return int[] [ r, g, b ]
	 */
	public static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 44, 98, 205 );
		}

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Lighten or darken a hex colour.
	 *
	 * @param string $hex    Hex colour.
	 * @param float  $amount -1 to 1. Negative darkens.
	 * @return string Hex colour.
	 */
	public static function shade( $hex, $amount ) {
		$rgb    = self::hex_to_rgb( $hex );
		$amount = max( -1.0, min( 1.0, (float) $amount ) );
		$out    = '#';

		foreach ( $rgb as $channel ) {
			if ( $amount >= 0 ) {
				$value = $channel + ( ( 255 - $channel ) * $amount );
			} else {
				$value = $channel * ( 1 + $amount );
			}

			$value = (int) round( max( 0, min( 255, $value ) ) );
			$out  .= str_pad( dechex( $value ), 2, '0', STR_PAD_LEFT );
		}

		return $out;
	}
}
