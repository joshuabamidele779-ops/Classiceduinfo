<?php
/**
 * The "My account" dashboard tab.
 *
 * Contributed through `ces_dashboard_tabs`. It sits at position 1 — right
 * after Overview — for a student who is not through the process yet, with a
 * warning icon, and drops to 60 once they are approved, where it becomes a
 * quiet record of who confirmed them and when.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard tab renderer.
 */
class CES_Accounts_Dashboard {

	/**
	 * Tab slug, also used as `?tab=`.
	 */
	const SLUG = 'account';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_dashboard_tabs', array( __CLASS__, 'register_tab' ), 10 );
		add_filter( 'ces_dashboard_quick_links', array( __CLASS__, 'quick_link' ), 5 );
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

		if ( ! CES_Accounts_Status::approval_enabled() ) {
			return $tabs;
		}

		if ( ! is_user_logged_in() ) {
			return $tabs;
		}

		$user_id = get_current_user_id();

		if ( CES_Accounts_Status::is_staff( $user_id ) ) {
			return $tabs;
		}

		$status  = CES_Accounts_Status::status( $user_id, true );
		$waiting = 'approved' !== $status;

		$labels = array(
			'onboarding'      => __( 'Finish signing up', 'classic-edu-suite' ),
			'awaiting_parent' => __( 'Waiting for your parent', 'classic-edu-suite' ),
			'awaiting_admin'  => __( 'Being checked', 'classic-edu-suite' ),
			'declined'        => __( 'Account not approved', 'classic-edu-suite' ),
			'suspended'       => __( 'Account paused', 'classic-edu-suite' ),
			'approved'        => __( 'My account', 'classic-edu-suite' ),
		);

		$icons = array(
			'onboarding'      => '⚠️',
			'awaiting_parent' => '⏳',
			'awaiting_admin'  => '⏳',
			'declined'        => '🚫',
			'suspended'       => '🚫',
			'approved'        => '✅',
		);

		$tabs[] = array(
			'slug'     => self::SLUG,
			'label'    => isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'My account', 'classic-edu-suite' ),
			'icon'     => isset( $icons[ $status ] ) ? $icons[ $status ] : '👤',
			'callback' => array( __CLASS__, 'render' ),
			'position' => $waiting ? 1 : 60,
		);

		return $tabs;
	}

	/**
	 * Put the outstanding step at the top of the Overview quick links.
	 *
	 * @param array $links Links so far.
	 * @return array
	 */
	public static function quick_link( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}

		if ( ! CES_Accounts_Status::approval_enabled() || ! is_user_logged_in() ) {
			return $links;
		}

		$user_id = get_current_user_id();

		if ( CES_Accounts_Status::is_staff( $user_id ) || CES_Accounts_Status::is_approved( $user_id ) ) {
			return $links;
		}

		$status = CES_Accounts_Status::status( $user_id );

		$label = 'onboarding' === $status
			? __( 'Add your parent’s details to unlock practice', 'classic-edu-suite' )
			: __( 'See what is left before practice unlocks', 'classic-edu-suite' );

		array_unshift(
			$links,
			array(
				'label' => $label,
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

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the renderers.
		echo CES_Accounts_Onboarding::render( get_current_user_id() );

		self::render_history( get_current_user_id() );
	}

	/**
	 * A short, student-readable history of their own account.
	 *
	 * Worth showing: a student who can see that the request went out at
	 * 14:02 to the right number stops asking whether it did.
	 *
	 * @param int $user_id Student.
	 * @return void
	 */
	private static function render_history( $user_id ) {
		$events = CES_Accounts_Status::events( $user_id, 8 );

		if ( empty( $events ) ) {
			return;
		}

		$readable = self::event_labels();

		echo '<div class="ces-onboard__card ces-onboard__card--quiet">';
		echo '<h3>' . esc_html__( 'What has happened so far', 'classic-edu-suite' ) . '</h3>';
		echo '<ul class="ces-onboard__log">';

		foreach ( $events as $event ) {
			$slug = (string) $event['event'];

			if ( ! isset( $readable[ $slug ] ) ) {
				continue;
			}

			echo '<li>';
			echo '<span class="ces-onboard__log-when">' . esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', (string) $event['created_at'] ) ) . '</span> ';
			echo '<span class="ces-onboard__log-what">' . esc_html( $readable[ $slug ] ) . '</span>';
			echo '</li>';
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Event slugs a student is allowed to see, in their own words.
	 *
	 * Anything not listed here — staff notes, flags, fraud signals — is
	 * deliberately left out of the student's view.
	 *
	 * @return array Map of slug => label.
	 */
	public static function event_labels() {
		return array(
			'signup_google'             => __( 'You signed in with Gmail for the first time', 'classic-edu-suite' ),
			'onboarding_submitted'      => __( 'You gave us your parent’s details', 'classic-edu-suite' ),
			'parent_requested'          => __( 'We asked your parent to confirm', 'classic-edu-suite' ),
			'parent_reminded'           => __( 'We reminded your parent', 'classic-edu-suite' ),
			'parent_verified'           => __( 'Your parent confirmed you', 'classic-edu-suite' ),
			'parent_confirmed_by_staff' => __( 'A member of staff confirmed with your parent', 'classic-edu-suite' ),
			'approved'                  => __( 'Your account was approved', 'classic-edu-suite' ),
			'declined'                  => __( 'Your account was not approved', 'classic-edu-suite' ),
			'request_expired'           => __( 'The request to your parent expired', 'classic-edu-suite' ),
			'student_restarted'         => __( 'You changed your parent’s details', 'classic-edu-suite' ),
			'consent_withdrawn'         => __( 'Your parent withdrew their consent', 'classic-edu-suite' ),
			'reset'                     => __( 'A member of staff restarted your signup', 'classic-edu-suite' ),
		);
	}
}
