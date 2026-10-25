<?php
/**
 * The "My Courses" tab on the student dashboard.
 *
 * Contributed through the `ces_dashboard_tabs` filter at position 30, per the
 * build contract. The dashboard file itself belongs to another module and is
 * never touched from here.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Student dashboard integration.
 */
class CES_Lms_Dashboard {

	/**
	 * Tab slug, used as `?tab=my-courses`.
	 */
	const SLUG = 'my-courses';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_dashboard_tabs', array( __CLASS__, 'register_tab' ) );
	}

	/**
	 * Add our tab to the dashboard.
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
			'label'    => __( 'My Courses', 'classic-edu-suite' ),
			'icon'     => '🎬',
			'callback' => array( __CLASS__, 'render' ),
			'position' => 30,
		);

		return $tabs;
	}

	/**
	 * Render the tab. Echoes its own markup, per the dashboard contract.
	 *
	 * @return void
	 */
	public static function render() {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			echo '<p class="ces-note ces-note--info">' . esc_html__( 'Please log in to see your courses.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		CES_Lms_Player::enqueue();

		$course_ids = CES_Lms_Rest::course_ids_for_user( $user_id );
		$active     = self::active_unlocks( $user_id );

		echo '<div class="ces-lms ces-dash-lms">';

		if ( CES_Lms_Access::has_unlimited( $user_id ) ) {
			echo '<p class="ces-note ces-note--good">'
				. esc_html__( 'Your subscription is active, so every lesson is unlocked and no coins are spent.', 'classic-edu-suite' )
				. '</p>';
		}

		if ( ! empty( $active ) ) {
			echo '<h3>' . esc_html__( 'Unlocked right now', 'classic-edu-suite' ) . '</h3>';
			echo '<ul class="ces-dash-lms__unlocks">';

			foreach ( $active as $row ) {
				$lesson_id = (int) $row['lesson_id'];

				if ( 'publish' !== get_post_status( $lesson_id ) ) {
					continue;
				}

				$until = strtotime( (string) $row['unlocked_until'] );

				echo '<li><a href="' . esc_url( (string) get_permalink( $lesson_id ) ) . '">'
					. esc_html( get_the_title( $lesson_id ) ) . '</a> <span class="ces-muted">'
					. esc_html(
						sprintf(
							/* translators: %s: human readable time remaining. */
							__( 'ends in %s', 'classic-edu-suite' ),
							human_time_diff( $until, current_time( 'timestamp' ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
						)
					)
					. '</span></li>';
			}

			echo '</ul>';
		}

		echo '<h3>' . esc_html__( 'Courses you have started', 'classic-edu-suite' ) . '</h3>';

		if ( empty( $course_ids ) ) {
			echo '<p class="ces-note ces-note--info">'
				. esc_html__( 'You have not watched any lessons yet. Browse the video library to get started.', 'classic-edu-suite' )
				. '</p>';
			echo '</div>';

			return;
		}

		echo '<ul class="ces-dash-lms__courses">';

		foreach ( $course_ids as $course_id ) {
			if ( 'publish' !== get_post_status( $course_id ) ) {
				continue;
			}

			$progress = CES_Lms_Progress::course_progress( $course_id, $user_id );

			echo '<li class="ces-dash-lms__course">';
			echo '<a href="' . esc_url( (string) get_permalink( $course_id ) ) . '">' . esc_html( get_the_title( $course_id ) ) . '</a>';
			echo '<span class="ces-progress" role="img" aria-label="'
				. esc_attr(
					sprintf(
						/* translators: %d: completion percentage. */
						__( '%d%% complete', 'classic-edu-suite' ),
						(int) $progress['percent']
					)
				) . '">';
			echo '<span class="ces-progress__bar" style="width:' . esc_attr( (int) $progress['percent'] ) . '%"></span>';
			echo '</span>';
			echo '<span class="ces-muted">' . esc_html(
				sprintf(
					/* translators: 1: completed lessons, 2: total lessons. */
					__( '%1$d / %2$d lessons', 'classic-edu-suite' ),
					(int) $progress['completed'],
					(int) $progress['total']
				)
			) . '</span>';
			echo '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * A student's currently open coin unlocks.
	 *
	 * @param int $user_id User id.
	 * @return array[]
	 */
	public static function active_unlocks( $user_id ) {
		global $wpdb;

		$table = CES_Helpers::table( 'video_views' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT lesson_id, unlocked_until, access_source
				FROM {$table}
				WHERE user_id = %d AND unlocked_until > %s
				ORDER BY unlocked_until ASC
				LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				CES_Helpers::now()
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
