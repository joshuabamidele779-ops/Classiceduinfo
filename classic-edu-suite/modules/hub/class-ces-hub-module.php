<?php
/**
 * Study hub module entry point.
 *
 * The Study section of the app: the tile grid, the resource library, the
 * past-question browser, study mode and the leaderboard.
 *
 * Called once from `CES_Plugin::load_modules()` on `plugins_loaded` at
 * priority 20, after the CBT module, because the question bank it draws from
 * belongs to CBT.
 *
 * @package ClassicEduSuite\Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Module bootstrap.
 */
class CES_Hub_Module {

	/**
	 * Wire up the module.
	 *
	 * @return void
	 */
	public static function init() {
		CES_Hub_Resources::init();
		CES_Hub_Rest::init();
		CES_Hub_Shortcodes::init();
		CES_Hub_Dashboard::init();

		add_filter( 'ces_settings_sections', array( __CLASS__, 'settings_section' ) );

		// CES_Assets decides at priority 20 whether this page carries one of
		// our shortcodes; if it does, put the hub styles in the head rather
		// than leaving them to be printed late from inside the shortcode.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_style' ), 25 );

		// Keep the cached per-subject question counts honest. The CBT module
		// owns the question bank, so this only applies when it is present.
		if ( class_exists( 'CES_Cbt_Cpt' ) ) {
			add_action( 'save_post_' . CES_Cbt_Cpt::QUESTION, array( __CLASS__, 'bump_bank_version' ) );
			add_action( 'deleted_post', array( __CLASS__, 'bump_bank_version' ), 10, 2 );
		}

		if ( is_admin() ) {
			CES_Hub_Admin::init();
		}
	}

	/**
	 * A counter that changes whenever the question bank does.
	 *
	 * Used as part of the cache key for the per-subject counts, so the
	 * caches expire by being ignored rather than by being hunted down.
	 *
	 * @return int
	 */
	public static function bank_version() {
		return (int) get_option( 'ces_hub_bank_version', 1 );
	}

	/**
	 * Move the bank version on.
	 *
	 * Also hooked to `deleted_post`, which fires for every post type, so a
	 * post object is checked where WordPress provides one.
	 *
	 * @param int          $post_id Post being saved or deleted.
	 * @param WP_Post|null $post    Post object, on `deleted_post`.
	 * @return void
	 */
	public static function bump_bank_version( $post_id = 0, $post = null ) {
		if ( $post instanceof WP_Post && CES_Cbt_Cpt::QUESTION !== $post->post_type ) {
			return;
		}

		update_option( 'ces_hub_bank_version', self::bank_version() + 1, false );
	}

	/**
	 * Load the hub stylesheet in the head on pages that need it.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_style() {
		if ( wp_style_is( 'ces-base', 'enqueued' ) ) {
			CES_Hub_Shortcodes::enqueue_style();
		}
	}

	/**
	 * The page carrying `[ces_study_hub]`, with arguments applied.
	 *
	 * @param array $args Query arguments.
	 * @return string URL, or '' when no page has been chosen.
	 */
	public static function hub_page_url( array $args = array() ) {
		$page_id = CES_Helpers::setting_int( 'hub_page_id' );

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
			'title'  => __( 'Study Hub', 'classic-edu-suite' ),
			'fields' => array(
				'hub_page_id'                  => array(
					'label' => __( 'Study page ID', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'The numeric ID of the page containing [ces_study_hub]. The dashboard links to it.', 'classic-edu-suite' ),
				),
				'hub_study_questions'          => array(
					'label' => __( 'Questions per study session', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'The default when a student does not choose. Maximum 100.', 'classic-edu-suite' ),
				),
				'hub_resource_coin_cost'       => array(
					'label' => __( 'Default coin cost for a locked resource', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Used when a resource is set to "costs coins" but carries no price of its own.', 'classic-edu-suite' ),
				),
				'hub_points_time_weight'       => array(
					'label' => __( 'Leaderboard time bonus', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Points added for finishing with the whole clock unused. 15 means a perfect fast paper scores about 115.', 'classic-edu-suite' ),
				),
				'hub_leaderboard_members_only' => array(
					'label' => __( 'Leaderboard for signed-in students only', 'classic-edu-suite' ),
					'type'  => 'checkbox',
				),
			),
		);

		return $sections;
	}
}
