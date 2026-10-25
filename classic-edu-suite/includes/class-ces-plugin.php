<?php
/**
 * Plugin orchestrator.
 *
 * Boots the four modules. Each module exposes a static `init()` that
 * registers its own hooks; a missing module class is skipped rather than
 * fatal, so modules stay independently deployable.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton plugin container.
 */
class CES_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var CES_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Module classes, in load order.
	 *
	 * @var string[]
	 */
	private $modules = array(
		'CES_Wallet_Module',
		'CES_Cbt_Module',
		'CES_Lms_Module',
		'CES_Quotes_Module',
		// The hub draws on the CBT question bank and the wallet, so it loads
		// after both. Guidance and Parents stand alone.
		'CES_Hub_Module',
		'CES_Guidance_Module',
		'CES_Parents_Module',
		// Accounts reuses the parent contact record, and gates the other
		// modules through filters, so it loads last.
		'CES_Accounts_Module',
		// The classroom builds on the LMS for video and on accounts for who a
		// person is, so it loads after both.
		'CES_Classroom_Module',
	);

	/**
	 * Get the singleton.
	 *
	 * @return CES_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the plugin's hooks.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'load_modules' ), 20 );
		add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );
		add_action( 'admin_init', array( CES_Activator::class, 'maybe_upgrade' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'classic-edu-suite', false, dirname( CES_BASENAME ) . '/languages' );
	}

	/**
	 * Initialise each module plus the shared admin and public layers.
	 *
	 * @return void
	 */
	public function load_modules() {
		foreach ( $this->modules as $module ) {
			if ( class_exists( $module ) && method_exists( $module, 'init' ) ) {
				call_user_func( array( $module, 'init' ) );
			}
		}

		foreach ( array( 'CES_Admin_Menu', 'CES_Shortcodes', 'CES_Dashboard', 'CES_Assets' ) as $shared ) {
			if ( class_exists( $shared ) && method_exists( $shared, 'init' ) ) {
				call_user_func( array( $shared, 'init' ) );
			}
		}

		/**
		 * Fires once every module has registered its hooks.
		 */
		do_action( 'ces_loaded' );
	}

	/**
	 * Flush rewrite rules once, after activation registered the post types.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite() {
		if ( get_option( 'ces_flush_rewrite' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'ces_flush_rewrite' );
		}
	}
}
