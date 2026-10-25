<?php
/**
 * The "Study" student dashboard tab.
 *
 * Contributed through `ces_dashboard_tabs` at position 15, between the wallet
 * and the CBT results, because it is the tab a student actually comes back
 * for. It carries their study numbers and sends them into the hub page.
 *
 * @package ClassicEduSuite\Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard tab renderer.
 */
class CES_Hub_Dashboard {

	/**
	 * Tab slug, also used as `?tab=`.
	 */
	const SLUG = 'study';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_dashboard_tabs', array( __CLASS__, 'register_tab' ) );
	}

	/**
	 * Add the tab.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public static function register_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}

		$tabs[] = array(
			'slug'     => self::SLUG,
			'label'    => __( 'Study', 'classic-edu-suite' ),
			'icon'     => '📚',
			'callback' => array( __CLASS__, 'render' ),
			'position' => 15,
		);

		return $tabs;
	}

	/**
	 * Echo the tab contents.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		$stats   = CES_Hub_Study::stats( $user_id );
		$hub     = CES_Hub_Module::hub_page_url();

		CES_Hub_Shortcodes::enqueue_style();

		echo '<div class="ces-hub ces-hub--dashboard">';

		// Numbers first. The class is our own rather than the CBT module's,
		// so the tab does not depend on that module's stylesheet being on
		// the page.
		echo '<ul class="ces-hub__stats">';
		echo '<li><strong>' . esc_html( number_format_i18n( $stats['answered'] ) ) . '</strong><span>' . esc_html__( 'Questions studied', 'classic-edu-suite' ) . '</span></li>';
		echo '<li><strong>' . esc_html( number_format_i18n( $stats['correct'] ) ) . '</strong><span>' . esc_html__( 'Got right', 'classic-edu-suite' ) . '</span></li>';
		echo '<li><strong>' . esc_html( number_format_i18n( CES_Hub_Study::accuracy( $user_id ), 1 ) ) . '%</strong><span>' . esc_html__( 'Accuracy', 'classic-edu-suite' ) . '</span></li>';
		echo '</ul>';

		$standing = CES_Hub_Leaderboard::standing( $user_id );

		if ( $standing ) {
			echo '<p class="ces-lb__you">' . esc_html(
				sprintf(
					/* translators: 1: rank, 2: points. */
					__( 'You are #%1$s on the leaderboard with %2$s points.', 'classic-edu-suite' ),
					number_format_i18n( $standing['rank'] ),
					number_format_i18n( $standing['points'], 2 )
				)
			) . '</p>';
		}

		if ( ! $hub ) {
			echo '<p class="ces-hub-empty">' . esc_html__( 'The Study page has not been set up yet. An administrator needs to create a page with the [ces_study_hub] shortcode and choose it in Classic EDU → Settings.', 'classic-edu-suite' ) . '</p>';
			echo '</div>';

			return;
		}

		// Shortcuts into the hub.
		echo '<div class="ces-hub__grid">';

		$links = array(
			array(
				'label' => __( 'Study Past Questions', 'classic-edu-suite' ),
				'icon'  => '⏱️',
				'tone'  => 'green',
				'args'  => array( 'view' => 'study' ),
			),
			array(
				'label' => __( 'Past Questions', 'classic-edu-suite' ),
				'icon'  => '📝',
				'tone'  => 'green',
				'args'  => array( 'view' => 'past-questions' ),
			),
			array(
				'label' => __( 'Library', 'classic-edu-suite' ),
				'icon'  => '📚',
				'tone'  => 'blue',
				'args'  => array( 'view' => 'library' ),
			),
			array(
				'label' => __( 'Leaderboard', 'classic-edu-suite' ),
				'icon'  => '🏆',
				'tone'  => 'amber',
				'args'  => array( 'view' => 'leaderboard' ),
			),
		);

		foreach ( $links as $link ) {
			echo '<a class="ces-tile ces-tile--' . esc_attr( $link['tone'] ) . '" href="' . esc_url( CES_Hub_Module::hub_page_url( $link['args'] ) ) . '">';
			echo '<span class="ces-tile__label">' . esc_html( $link['label'] ) . '</span>';
			echo '<span class="ces-tile__icon" aria-hidden="true">' . esc_html( $link['icon'] ) . '</span>';
			echo '</a>';
		}

		echo '</div>';
		echo '</div>';
	}
}
