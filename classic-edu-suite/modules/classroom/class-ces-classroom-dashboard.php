<?php
/**
 * The student's dashboard tabs: "My classes" and "Chat".
 *
 * Both are thin: they render what the shortcodes already build, so the class
 * page a student reaches from a WhatsApp link and the one they reach from
 * their dashboard are the same page.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard tabs.
 */
class CES_Classroom_Dashboard {

	/**
	 * "My classes" tab slug.
	 */
	const SLUG = 'classes';

	/**
	 * Chat tab slug.
	 */
	const CHAT_SLUG = 'chat';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_dashboard_tabs', array( __CLASS__, 'register_tabs' ), 20 );
		add_filter( 'ces_accounts_allowed_tabs', array( __CLASS__, 'allow_classes_tab' ) );
	}

	/**
	 * Add the two tabs.
	 *
	 * @param array $tabs Tabs so far.
	 * @return array
	 */
	public static function register_tabs( $tabs ) {
		if ( ! is_array( $tabs ) || ! CES_Helpers::enabled( 'classroom_enabled' ) || ! is_user_logged_in() ) {
			return $tabs;
		}

		$user_id = get_current_user_id();
		$classes = CES_Classroom_Enrolment::classes_for( $user_id );
		$pending = CES_Classroom_Enrolment::classes_for( $user_id, CES_Classroom_Enrolment::PENDING );
		$live    = CES_Classroom_Session::live_for_user( $user_id );

		// The tab is always offered: a student arriving with a code needs
		// somewhere to type it, and that somewhere is this tab.
		$label = __( 'My classes', 'classic-edu-suite' );

		if ( ! empty( $live ) ) {
			$label = __( 'My classes · LIVE', 'classic-edu-suite' );
		} elseif ( empty( $classes ) && empty( $pending ) ) {
			$label = __( 'Join a class', 'classic-edu-suite' );
		}

		$tabs[] = array(
			'slug'     => self::SLUG,
			'label'    => $label,
			'icon'     => empty( $live ) ? '🏫' : '🔴',
			'callback' => array( __CLASS__, 'render_classes' ),
			'position' => 25,
		);

		if ( CES_Helpers::enabled( 'classroom_chat_enabled' ) ) {
			$tabs[] = array(
				'slug'     => self::CHAT_SLUG,
				'label'    => __( 'Chat', 'classic-edu-suite' ),
				'icon'     => '💬',
				'callback' => array( __CLASS__, 'render_chat' ),
				'position' => 55,
			);
		}

		return $tabs;
	}

	/**
	 * Let an unapproved student still reach their classes.
	 *
	 * A student held at the parent-approval gate can still be in a tutor's
	 * class, and the tutor's lessons are not the school's to withhold. The
	 * chat tab is deliberately not on this list.
	 *
	 * @param array $slugs Allowed tab slugs.
	 * @return array
	 */
	public static function allow_classes_tab( $slugs ) {
		if ( ! is_array( $slugs ) ) {
			return $slugs;
		}

		$slugs[] = self::SLUG;

		return $slugs;
	}

	/**
	 * The classes tab.
	 *
	 * @return void
	 */
	public static function render_classes() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo CES_Classroom_Shortcodes::my_classes();
	}

	/**
	 * The chat tab.
	 *
	 * @return void
	 */
	public static function render_chat() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo CES_Classroom_Shortcodes::chat();
	}
}
