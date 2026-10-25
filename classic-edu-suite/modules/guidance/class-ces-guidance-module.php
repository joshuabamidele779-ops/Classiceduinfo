<?php
/**
 * Guidance module entry point.
 *
 * School Finder, Department Guidance and Career Guide — the "what do I do
 * next" half of the site, as opposed to the "how do I pass" half.
 *
 * Called once from `CES_Plugin::load_modules()` on `plugins_loaded` at
 * priority 20.
 *
 * @package ClassicEduSuite\Guidance
 */

defined( 'ABSPATH' ) || exit;

/**
 * Module bootstrap.
 */
class CES_Guidance_Module {

	/**
	 * Wire up the module.
	 *
	 * @return void
	 */
	public static function init() {
		CES_Guidance_Cpt::init();
		CES_Guidance_Shortcodes::init();

		add_filter( 'ces_settings_sections', array( __CLASS__, 'settings_section' ) );
		add_filter( 'ces_dashboard_quick_links', array( __CLASS__, 'quick_link' ) );

		// CES_Assets decides at priority 20 whether this page carries one of
		// our shortcodes; if it does, the styles belong in the head.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_style' ), 25 );

		if ( is_admin() ) {
			CES_Guidance_Admin::init();
		}
	}

	/**
	 * Load the guidance stylesheet in the head on pages that need it.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_style() {
		if ( wp_style_is( 'ces-base', 'enqueued' ) ) {
			CES_Guidance_Shortcodes::enqueue_style();
		}
	}

	/**
	 * The page carrying `[ces_guidance]`, with arguments applied.
	 *
	 * @param array $args Query arguments.
	 * @return string URL, or '' when no page has been chosen.
	 */
	public static function page_url( array $args = array() ) {
		$page_id = CES_Helpers::setting_int( 'guidance_page_id' );

		if ( $page_id < 1 ) {
			return '';
		}

		$url = get_permalink( $page_id );

		if ( ! $url ) {
			return '';
		}

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Offer Guidance from the dashboard Overview.
	 *
	 * @param array $links Links so far.
	 * @return array
	 */
	public static function quick_link( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}

		$url = self::page_url();

		if ( ! $url ) {
			return $links;
		}

		$links[] = array(
			'label' => __( 'Guidance: schools, departments, careers', 'classic-edu-suite' ),
			'url'   => $url,
			'icon'  => '🧭',
		);

		return $links;
	}

	/**
	 * Settings fields for this module.
	 *
	 * @param array $sections Sections so far.
	 * @return array
	 */
	public static function settings_section( $sections ) {
		if ( ! is_array( $sections ) ) {
			$sections = array();
		}

		$sections[] = array(
			'title'  => __( 'Guidance', 'classic-edu-suite' ),
			'fields' => array(
				'guidance_page_id' => array(
					'label' => __( 'Guidance page ID', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'The numeric ID of the page containing [ces_guidance]. The dashboard links to it.', 'classic-edu-suite' ),
				),
			),
		);

		return $sections;
	}
}
