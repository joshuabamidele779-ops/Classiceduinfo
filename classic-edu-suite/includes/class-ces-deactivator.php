<?php
/**
 * Deactivation routine.
 *
 * Clears scheduled events and rewrite rules. Deliberately leaves data,
 * roles and uploaded media alone: deactivation is not uninstallation.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin deactivation.
 */
class CES_Deactivator {

	/**
	 * Cron hooks owned by the plugin.
	 *
	 * @var string[]
	 */
	private static $cron_hooks = array(
		'ces_daily_maintenance',
		'ces_process_video_queue',
		'ces_rotate_daily_quote',
		'ces_expire_stale_attempts',
		'ces_accounts_maintenance',
		'ces_classroom_maintenance',
	);

	/**
	 * Run on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( self::$cron_hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		flush_rewrite_rules( false );
	}
}
