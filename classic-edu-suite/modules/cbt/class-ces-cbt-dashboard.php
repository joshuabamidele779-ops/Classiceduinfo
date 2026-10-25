<?php
/**
 * The "My Results" student dashboard tab.
 *
 * Contributed to the shared dashboard through `ces_dashboard_tabs` at
 * position 20, so the dashboard file itself never needs editing.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard tab renderer.
 */
class CES_Cbt_Dashboard {

	/**
	 * Tab slug, also used as `?tab=`.
	 */
	const SLUG = 'cbt-results';

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
			'label'    => __( 'My Results', 'classic-edu-suite' ),
			'icon'     => '📝',
			'callback' => array( __CLASS__, 'render' ),
			'position' => 20,
		);

		return $tabs;
	}

	/**
	 * Echo the tab contents.
	 *
	 * Headline numbers first, then the full attempt history, which is the
	 * `[ces_cbt_results]` shortcode so a single result deep link keeps working
	 * inside the dashboard.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		$rows    = CES_Cbt_Attempt::user_results( $user_id, 100, 0 );

		$submitted = 0;
		$passes    = 0;
		$total     = 0.0;
		$best      = 0.0;

		foreach ( $rows as $row ) {
			if ( CES_Cbt_Attempt::STATUS_IN_PROGRESS === $row['status'] ) {
				continue;
			}

			++$submitted;
			$total += (float) $row['score'];

			if ( $row['passed'] ) {
				++$passes;
			}

			if ( (float) $row['score'] > $best ) {
				$best = (float) $row['score'];
			}
		}

		$average = $submitted ? round( $total / $submitted, 1 ) : 0.0;

		CES_Cbt_Shortcode::enqueue_style();

		echo '<div class="ces-cbt ces-cbt--dashboard">';

		if ( $submitted ) {
			echo '<ul class="ces-cbt__stats ces-cbt__stats--wide">';

			echo '<li><strong>' . esc_html( number_format_i18n( $submitted ) ) . '</strong><span>'
				. esc_html__( 'Exams sat', 'classic-edu-suite' ) . '</span></li>';

			echo '<li><strong>' . esc_html( number_format_i18n( $average, 1 ) ) . '%</strong><span>'
				. esc_html__( 'Average score', 'classic-edu-suite' ) . '</span></li>';

			echo '<li><strong>' . esc_html( number_format_i18n( $best, 1 ) ) . '%</strong><span>'
				. esc_html__( 'Best score', 'classic-edu-suite' ) . '</span></li>';

			echo '<li><strong>' . esc_html( number_format_i18n( $passes ) ) . '</strong><span>'
				. esc_html__( 'Passes', 'classic-edu-suite' ) . '</span></li>';

			echo '</ul>';
		}

		echo '</div>';

		// The shortcode handles both the history table and the single-result
		// deep link at ?attempt=123.
		echo do_shortcode( '[ces_cbt_results limit="50"]' );
	}
}
