<?php
/**
 * The "Parent / Guardian" student dashboard tab.
 *
 * Contributed through `ces_dashboard_tabs` at position 45. When the student
 * has not given a number yet the tab jumps to position 1 — immediately after
 * Overview — and its quick link is marked, which is as much nagging as this
 * plugin does unless the blocking setting is switched on.
 *
 * @package ClassicEduSuite\Parents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard tab renderer.
 */
class CES_Parents_Dashboard {

	/**
	 * Tab slug, also used as `?tab=`.
	 */
	const SLUG = 'parent';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_dashboard_tabs', array( __CLASS__, 'register_tab' ) );
		add_filter( 'ces_dashboard_quick_links', array( __CLASS__, 'quick_link' ) );
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

		if ( ! CES_Helpers::enabled( 'parents_capture_enabled' ) ) {
			return $tabs;
		}

		$missing = is_user_logged_in() && ! CES_Parent_Contact::has_number( get_current_user_id() );

		$tabs[] = array(
			'slug'     => self::SLUG,
			'label'    => $missing
				? __( 'Add parent number', 'classic-edu-suite' )
				: __( 'Parent / Guardian', 'classic-edu-suite' ),
			'icon'     => $missing ? '⚠️' : '👪',
			'callback' => array( __CLASS__, 'render' ),
			'position' => $missing ? 1 : 45,
		);

		return $tabs;
	}

	/**
	 * Push the prompt to the front of the Overview quick links when missing.
	 *
	 * @param array $links Links so far.
	 * @return array
	 */
	public static function quick_link( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}

		if ( ! CES_Helpers::enabled( 'parents_capture_enabled' ) || ! is_user_logged_in() ) {
			return $links;
		}

		if ( CES_Parent_Contact::has_number( get_current_user_id() ) ) {
			return $links;
		}

		array_unshift(
			$links,
			array(
				'label' => __( 'Add your parent’s WhatsApp number', 'classic-edu-suite' ),
				'url'   => CES_Helpers::dashboard_url( self::SLUG ),
				'icon'  => '⚠️',
			)
		);

		return $links;
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
		$contact = CES_Parent_Contact::get( $user_id );
		$summary = CES_Parents_Report::summary( $user_id );

		echo '<div class="ces-parent-tab">';

		// What is shared, in plain words, before the form.
		echo '<div class="ces-parent-explainer">';
		echo '<h3>' . esc_html__( 'What your parent will see', 'classic-edu-suite' ) . '</h3>';
		echo '<p>' . esc_html__( 'A short WhatsApp update, no more than once a week, with these numbers and nothing else:', 'classic-edu-suite' ) . '</p>';

		echo '<ul class="ces-parent-explainer__list">';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %d: number of exams. */
				_n( '%d practice exam sat in the last month', '%d practice exams sat in the last month', $summary['exams'], 'classic-edu-suite' ),
				$summary['exams']
			)
		) . '</li>';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: average score. */
				__( 'Average score: %s%%', 'classic-edu-suite' ),
				number_format_i18n( $summary['average'], 1 )
			)
		) . '</li>';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: best score. */
				__( 'Best score: %s%%', 'classic-edu-suite' ),
				number_format_i18n( $summary['best'], 1 )
			)
		) . '</li>';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: human time difference. */
				__( 'When you last studied: %s', 'classic-edu-suite' ),
				$summary['last_active_h']
			)
		) . '</li>';
		echo '</ul>';

		echo '<p class="ces-parent-explainer__note">' . esc_html__( 'Your answers, messages and coin balance are never shared, and the number is never given to anyone outside this school.', 'classic-edu-suite' ) . '</p>';
		echo '</div>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_form().
		echo CES_Parents_Fields::render_form( $user_id );

		if ( $contact['phone'] ) {
			echo '<p class="ces-parent-tab__status">' . esc_html(
				sprintf(
					/* translators: 1: parent name, 2: formatted phone number. */
					__( 'On file: %1$s · %2$s', 'classic-edu-suite' ),
					$contact['name'],
					$contact['phone_pretty']
				)
			) . '</p>';
		}

		echo '</div>';
	}
}
