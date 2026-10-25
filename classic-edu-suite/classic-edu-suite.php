<?php
/**
 * Plugin Name:       Classic EDU Suite
 * Plugin URI:        https://classiceduinfo.com.ng/
 * Description:       Gmail sign-in with parent approval, consultant classrooms with live group teaching and chat, CBT exam engine, study hub and resource library, protected syllabus video library, school and career guidance, coin wallet with referrals, parent contact book, and daily motivational quotes for Classic Edu Info.
 * Version:           1.3.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Classic Edu Info
 * Author URI:        https://classiceduinfo.com.ng/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       classic-edu-suite
 * Domain Path:       /languages
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

define( 'CES_VERSION', '1.3.0' );
define( 'CES_DB_VERSION', '1.3.0' );
define( 'CES_PLUGIN_FILE', __FILE__ );
define( 'CES_PATH', plugin_dir_path( __FILE__ ) );
define( 'CES_URL', plugin_dir_url( __FILE__ ) );
define( 'CES_BASENAME', plugin_basename( __FILE__ ) );
define( 'CES_REST_NAMESPACE', 'ces/v1' );

require_once CES_PATH . 'includes/class-ces-autoloader.php';
CES_Autoloader::register();

register_activation_hook( __FILE__, array( 'CES_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CES_Deactivator', 'deactivate' ) );

/**
 * Main plugin instance.
 *
 * @return CES_Plugin
 */
function ces() {
	return CES_Plugin::instance();
}

ces()->boot();
