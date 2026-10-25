<?php
/**
 * Wallet module entry point.
 *
 * Loaded first by CES_Plugin, so `CES_Wallet` is guaranteed to exist by the
 * time the CBT, LMS and Quotes modules register their own hooks.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires every wallet, referral and reward hook.
 */
class CES_Wallet_Module {

	/**
	 * Shared asset handle.
	 */
	const HANDLE = 'ces-wallet';

	/**
	 * Cron hook used for the daily referral sweep.
	 *
	 * Deliberately the shared `ces_daily_maintenance` event, which
	 * CES_Deactivator already unschedules on deactivation.
	 */
	const CRON_HOOK = 'ces_daily_maintenance';

	/**
	 * Whether the front-end assets have been enqueued this request.
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Register every hook the module owns.
	 *
	 * @return void
	 */
	public static function init() {
		// ---------------- Referral capture and attribution ----------------
		add_action( 'init', array( 'CES_Referral', 'capture' ), 5 );
		add_action( 'register_form', array( 'CES_Referral', 'registration_field' ) );
		add_action( 'user_register', array( 'CES_Referral', 'on_user_register' ), 20, 1 );
		add_action( 'ces_quiz_completed', array( 'CES_Referral', 'on_quiz_completed' ), 20, 4 );
		add_action( 'wp_login', array( 'CES_Referral', 'on_login' ), 20, 2 );

		// ---------------- Earning paths ----------------
		add_action( 'user_register', array( 'CES_Rewards', 'on_user_register' ), 10, 1 );
		add_action( 'wp_login', array( 'CES_Rewards', 'on_login' ), 10, 2 );
		add_action( 'init', array( 'CES_Rewards', 'maybe_award_daily_login' ), 20 );
		add_action( 'ces_quiz_completed', array( 'CES_Rewards', 'on_quiz_completed' ), 10, 4 );

		// ---------------- Student surfaces ----------------
		add_filter( 'ces_dashboard_tabs', array( 'CES_Wallet_Dashboard', 'tabs' ) );
		add_action( 'rest_api_init', array( 'CES_Wallet_Rest', 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );

		CES_Wallet_Shortcodes::register();

		// ---------------- Admin ----------------
		if ( is_admin() ) {
			CES_Wallet_Admin::init();
		}

		// Overview cards and settings fields are needed on the admin screens
		// owned by CES_Admin_Menu, which runs in the admin only.
		add_filter( 'ces_overview_cards', array( 'CES_Wallet_Admin', 'overview_cards' ) );
		add_filter( 'ces_settings_sections', array( 'CES_Wallet_Admin', 'settings_sections' ) );
		add_filter( 'ces_admin_submenus', array( 'CES_Wallet_Admin', 'submenus' ) );

		// ---------------- Cron ----------------
		add_action( 'init', array( __CLASS__, 'schedule_cron' ), 30 );
		add_action( self::CRON_HOOK, array( 'CES_Referral', 'sweep' ) );
	}

	/**
	 * Make sure the daily maintenance event exists.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Register the front-end assets, and enqueue them when we can already
	 * tell the current page will need them.
	 *
	 * @return void
	 */
	public static function register_assets() {
		/*
		 * `ces-base` carries the design tokens and the coin illustration the
		 * balance hero and the earn list draw from, so it is a dependency
		 * rather than something this module duplicates. It also guarantees
		 * wallet.css loads after it, keeping these rules on top.
		 */
		CES_Assets::register();

		wp_register_style(
			self::HANDLE,
			CES_URL . 'modules/wallet/assets/wallet.css',
			array( 'ces-base' ),
			CES_VERSION
		);

		wp_register_script(
			self::HANDLE,
			CES_URL . 'modules/wallet/assets/wallet.js',
			array(),
			CES_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'cesWallet',
			array(
				'root'     => esc_url_raw( rest_url( CES_REST_NAMESPACE . '/wallet/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'loggedIn' => is_user_logged_in() ? 1 : 0,
				'i18n'     => array(
					'copied'     => __( 'Copied!', 'classic-edu-suite' ),
					'copy'       => __( 'Copy', 'classic-edu-suite' ),
					'copyFailed' => __( 'Press and hold to copy the link.', 'classic-edu-suite' ),
					'loading'    => __( 'Loading…', 'classic-edu-suite' ),
					'loadMore'   => __( 'Load more', 'classic-edu-suite' ),
					'noMore'     => __( 'That is everything.', 'classic-edu-suite' ),
					'error'      => __( 'Could not load that. Please try again.', 'classic-edu-suite' ),
				),
			)
		);

		$primary = (string) CES_Helpers::setting( 'brand_primary', '#2c62cd' );

		if ( ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $primary ) ) {
			$primary = '#2c62cd';
		}

		wp_add_inline_style( self::HANDLE, ':root{--ces-primary:' . $primary . ';}' );

		if ( self::page_needs_assets() ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Enqueue the wallet CSS and JS. Safe to call repeatedly and safe to call
	 * from inside a shortcode, where WordPress prints late assets in the
	 * footer.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( self::$enqueued || is_admin() ) {
			return;
		}

		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			// Shortcode rendered before `wp_enqueue_scripts`, e.g. from a
			// header template. Register on the spot. register_assets() may
			// enqueue for us, which is why the flag is re-checked below.
			self::register_assets();
		}

		if ( self::$enqueued ) {
			return;
		}

		self::$enqueued = true;

		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Whether the current page is already known to need the wallet assets.
	 *
	 * @return bool
	 */
	private static function page_needs_assets() {
		$page_id = CES_Helpers::setting_int( 'dashboard_page_id' );

		if ( $page_id > 0 && is_page( $page_id ) ) {
			return true;
		}

		$post = get_post();

		if ( ! $post instanceof WP_Post || '' === (string) $post->post_content ) {
			return false;
		}

		$shortcodes = array( 'ces_dashboard', 'ces_coin_balance', 'ces_referral_link', 'ces_referral_stats' );

		foreach ( $shortcodes as $shortcode ) {
			if ( has_shortcode( $post->post_content, $shortcode ) ) {
				return true;
			}
		}

		return false;
	}
}
