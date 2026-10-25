<?php
/**
 * CBT module entry point.
 *
 * Called once from `CES_Plugin::load_modules()` on `plugins_loaded` at
 * priority 20. Everything the module does is hooked from here.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Module bootstrap.
 */
class CES_Cbt_Module {

	/**
	 * Cron hook that sweeps up abandoned attempts.
	 */
	const CRON_HOOK = 'ces_expire_stale_attempts';

	/**
	 * Custom cron interval slug.
	 */
	const CRON_SCHEDULE = 'ces_five_minutes';

	/**
	 * Wire up the module.
	 *
	 * @return void
	 */
	public static function init() {
		CES_Cbt_Cpt::init();
		CES_Cbt_Rest::init();
		CES_Cbt_Shortcode::init();
		CES_Cbt_Dashboard::init();

		// Cron: auto-submit papers whose clock ran out while nobody was looking.
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( 'init', array( __CLASS__, 'schedule_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );

		if ( is_admin() ) {
			CES_Cbt_Admin::init();
		}
	}

	/**
	 * Add a five minute interval for the attempt sweeper.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (Classic EDU)', 'classic-edu-suite' ),
			);
		}

		return $schedules;
	}

	/**
	 * Make sure the sweeper is scheduled.
	 *
	 * `CES_Deactivator` already clears this hook on deactivation.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run_cron() {
		CES_Cbt_Attempt::expire_stale();
	}
}
