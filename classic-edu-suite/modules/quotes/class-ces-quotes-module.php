<?php
/**
 * Quotes module entry point.
 *
 * Wires the post type, scheduler, popup, REST routes, shortcodes, seeder and
 * admin screen. Called once from `CES_Plugin` on `plugins_loaded` (20).
 *
 * Shortcodes registered here:
 *   [ces_quote_of_the_day]
 *   [ces_random_quote]
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the quotes module.
 */
class CES_Quotes_Module {

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register every hook this module owns.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		CES_Quotes_Cpt::init();
		CES_Quotes_Scheduler::init();
		CES_Quotes_Rest::init();
		CES_Quotes_Shortcodes::init();
		CES_Quotes_Seeder::init();

		if ( CES_Helpers::enabled( 'quotes_enabled' ) ) {
			CES_Quotes_Popup::init();
		}

		// The admin screen owns the settings fields and the overview card, so
		// it is wired up regardless of whether the popup itself is enabled.
		if ( is_admin() ) {
			CES_Quotes_Admin::init();
		}
	}
}
