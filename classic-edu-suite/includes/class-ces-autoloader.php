<?php
/**
 * Class autoloader.
 *
 * Maps `CES_Cbt_Attempt` to `class-ces-cbt-attempt.php` and probes a fixed
 * list of plugin directories. Keeps file lookups to a handful of
 * `file_exists()` calls with no directory recursion per request.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader for CES_* classes.
 */
class CES_Autoloader {

	/**
	 * Directories searched for class files, relative to the plugin root.
	 *
	 * Module agents: add your own subdirectories here if you create any
	 * beyond the ones listed.
	 *
	 * @var string[]
	 */
	private static $dirs = array(
		'includes/',
		'admin/',
		'public/',
		'modules/cbt/',
		'modules/cbt/admin/',
		'modules/lms/',
		'modules/lms/admin/',
		'modules/lms/storage/',
		'modules/wallet/',
		'modules/wallet/admin/',
		'modules/quotes/',
		'modules/quotes/admin/',
		'modules/hub/',
		'modules/hub/admin/',
		'modules/guidance/',
		'modules/guidance/admin/',
		'modules/parents/',
		'modules/parents/admin/',
		'modules/accounts/',
		'modules/accounts/admin/',
		'modules/classroom/',
		'modules/classroom/admin/',
	);

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Attempt to load a class.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, 'CES_' ) ) {
			return;
		}

		$file = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

		foreach ( self::$dirs as $dir ) {
			$path = CES_PATH . $dir . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
