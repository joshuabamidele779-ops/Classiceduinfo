<?php
/**
 * Video analytics screen.
 *
 * Reads the `video_views` table through `CES_Lms_Progress` rather than
 * querying it here, so the definition of "finished" stays in one place — the
 * same place that decides whether a lesson earns coins.
 *
 * The numbers are deliberately plain. "Viewers" is how many students have a
 * row for the lesson, "finished" is how many crossed the real watch-time
 * threshold, and "average reach" is how far into the video they got. A high
 * viewer count with a low average reach is the signal worth acting on: the
 * lesson is being opened and abandoned.
 *
 * @package ClassicEduSuite\Lms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin analytics for the video library.
 */
class CES_Lms_Admin_Analytics {

	/**
	 * Capability required to view analytics.
	 */
	const CAP = 'ces_manage_lms';

	/**
	 * Lessons listed per page.
	 */
	const PER_PAGE = 30;

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'classic-edu-suite' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$lesson_id = isset( $_GET['lesson'] ) ? absint( $_GET['lesson'] ) : 0;
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap ces-wrap ces-lms-admin">';

		if ( $lesson_id > 0 && CES_Lms_Cpt::LESSON === get_post_type( $lesson_id ) ) {
			self::render_lesson( $lesson_id );
			echo '</div>';

			return;
		}

		echo '<h1>' . esc_html__( 'Video Analytics', 'classic-edu-suite' ) . '</h1>';

		self::render_totals();
		self::render_table( $paged );

		echo '</div>';
	}

	/**
	 * The headline numbers across the whole library.
	 *
	 * @return void
	 */
	private static function render_totals() {
		$totals = CES_Lms_Progress::totals();
		$hours  = $totals['total_seconds'] > 0 ? round( $totals['total_seconds'] / 3600, 1 ) : 0;

		echo '<ul class="ces-lms-facts">';

		echo '<li><strong>' . esc_html__( 'Students who have watched', 'classic-edu-suite' ) . ':</strong> '
			. esc_html( number_format_i18n( $totals['students'] ) ) . '</li>';

		echo '<li><strong>' . esc_html__( 'Lessons finished', 'classic-edu-suite' ) . ':</strong> '
			. esc_html( number_format_i18n( $totals['completions'] ) ) . '</li>';

		echo '<li><strong>' . esc_html__( 'Total watch time', 'classic-edu-suite' ) . ':</strong> '
			. esc_html(
				sprintf(
					/* translators: %s: number of hours. */
					__( '%s hours', 'classic-edu-suite' ),
					number_format_i18n( $hours, 1 )
				)
			) . '</li>';

		echo '<li><strong>' . esc_html__( 'Coins spent on unlocks', 'classic-edu-suite' ) . ':</strong> '
			. esc_html( number_format_i18n( $totals['coins'] ) ) . '</li>';

		echo '</ul>';
	}

	/**
	 * The per-lesson table.
	 *
	 * @param int $paged Page number.
	 * @return void
	 */
	private static function render_table( $paged ) {
		$query = new WP_Query(
			array(
				'post_type'      => CES_Lms_Cpt::LESSON,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $paged,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( ! $query->have_posts() ) {
			echo '<p>' . esc_html__( 'No lessons yet.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped ces-lms-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Lesson', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Course', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Viewers', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Finished', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Average reach', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Watch time', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Coins', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $query->posts as $lesson ) {
			$lesson_id = (int) $lesson->ID;
			$stats     = CES_Lms_Progress::lesson_stats( $lesson_id );
			$course_id = CES_Lms_Cpt::lesson_course_id( $lesson_id );

			$detail = add_query_arg(
				array(
					'page'   => 'ces-lms-analytics',
					'lesson' => $lesson_id,
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';

			echo '<td><strong><a href="' . esc_url( $detail ) . '">'
				. esc_html( get_the_title( $lesson_id ) ? get_the_title( $lesson_id ) : sprintf( '#%d', $lesson_id ) )
				. '</a></strong></td>';

			echo '<td>' . ( $course_id ? esc_html( get_the_title( $course_id ) ) : '&mdash;' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $stats['viewers'] ) ) . '</td>';

			echo '<td>' . esc_html( number_format_i18n( $stats['completions'] ) );

			if ( $stats['viewers'] > 0 ) {
				echo ' <small>(' . esc_html(
					number_format_i18n( round( ( $stats['completions'] / $stats['viewers'] ) * 100 ) )
				) . '%)</small>';
			}

			echo '</td>';

			echo '<td>';

			if ( $stats['viewers'] > 0 ) {
				echo '<span class="ces-progress" style="min-width:70px;display:inline-block;vertical-align:middle">';
				echo '<span class="ces-progress__bar" style="width:' . esc_attr( (string) (int) $stats['avg_percent'] ) . '%"></span>';
				echo '</span> ';
				echo esc_html( $stats['avg_percent'] . '%' );
			} else {
				echo '&mdash;';
			}

			echo '</td>';

			echo '<td>' . esc_html( CES_Lms_Meta::format_duration( $stats['total_seconds'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $stats['coins'] ) ) . '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';

		self::render_pager( $query, $paged );

		wp_reset_postdata();
	}

	/**
	 * Pagination links.
	 *
	 * @param WP_Query $query The lesson query.
	 * @param int      $paged Current page.
	 * @return void
	 */
	private static function render_pager( $query, $paged ) {
		if ( (int) $query->max_num_pages < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => (int) $query->max_num_pages,
				'type'      => 'plain',
				'prev_text' => __( '&laquo; Previous', 'classic-edu-suite' ),
				'next_text' => __( 'Next &raquo;', 'classic-edu-suite' ),
			)
		);

		if ( $links ) {
			echo '<p class="ces-lms-pager">' . wp_kses_post( $links ) . '</p>';
		}
	}

	// ------------------------------------------------------------------
	// Single lesson detail.
	// ------------------------------------------------------------------

	/**
	 * Per-student detail for one lesson.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return void
	 */
	private static function render_lesson( $lesson_id ) {
		$stats    = CES_Lms_Progress::lesson_stats( $lesson_id );
		$duration = CES_Lms_Meta::duration( $lesson_id );
		$viewers  = CES_Lms_Progress::lesson_viewers( $lesson_id, 100 );

		echo '<h1>' . esc_html(
			sprintf(
				/* translators: %s: lesson title. */
				__( 'Analytics: %s', 'classic-edu-suite' ),
				get_the_title( $lesson_id )
			)
		) . '</h1>';

		echo '<p>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=ces-lms-analytics' ) ) . '">'
			. esc_html__( 'Back to all lessons', 'classic-edu-suite' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( (string) get_edit_post_link( $lesson_id, 'raw' ) ) . '">'
			. esc_html__( 'Edit this lesson', 'classic-edu-suite' ) . '</a>';
		echo '</p>';

		echo '<ul class="ces-lms-facts">';
		echo '<li><strong>' . esc_html__( 'Length', 'classic-edu-suite' ) . ':</strong> '
			. ( $duration > 0 ? esc_html( CES_Lms_Meta::format_duration( $duration ) ) : esc_html__( 'unknown', 'classic-edu-suite' ) ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Viewers', 'classic-edu-suite' ) . ':</strong> '
			. esc_html( number_format_i18n( $stats['viewers'] ) ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Finished', 'classic-edu-suite' ) . ':</strong> '
			. esc_html( number_format_i18n( $stats['completions'] ) ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Average reach', 'classic-edu-suite' ) . ':</strong> '
			. esc_html( $stats['avg_percent'] . '%' ) . '</li>';
		echo '</ul>';

		if ( empty( $viewers ) ) {
			echo '<p>' . esc_html__( 'Nobody has watched this lesson yet.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped ces-lms-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Student', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'How they got in', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Watched', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Reached', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Finished', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Coins', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $viewers as $row ) {
			$user = get_userdata( (int) $row['user_id'] );

			echo '<tr>';

			echo '<td>' . ( $user
				? esc_html( $user->display_name )
				: esc_html(
					sprintf(
						/* translators: %d: user id of a deleted account. */
						__( 'Deleted user #%d', 'classic-edu-suite' ),
						(int) $row['user_id']
					)
				) ) . '</td>';

			echo '<td>' . esc_html( self::source_label( (string) $row['access_source'] ) ) . '</td>';
			echo '<td>' . esc_html( CES_Lms_Meta::format_duration( (int) $row['seconds_watched'] ) ) . '</td>';
			echo '<td>' . esc_html( CES_Lms_Meta::format_duration( (int) $row['furthest_position'] ) ) . '</td>';
			echo '<td>' . ( (int) $row['completed'] ? esc_html__( 'Yes', 'classic-edu-suite' ) : '&mdash;' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['coins_spent'] ) ) . '</td>';

			echo '<td>' . ( ! empty( $row['last_seen_at'] ) && '0000-00-00 00:00:00' !== $row['last_seen_at']
				? esc_html(
					sprintf(
						/* translators: %s: human time difference. */
						__( '%s ago', 'classic-edu-suite' ),
						human_time_diff( (int) strtotime( (string) $row['last_seen_at'] ), time() )
					)
				)
				: '&mdash;' ) . '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Human label for an access source.
	 *
	 * @param string $source Stored source key.
	 * @return string
	 */
	private static function source_label( $source ) {
		$labels = array(
			CES_Lms_Access::SOURCE_FREE         => __( 'Free preview', 'classic-edu-suite' ),
			CES_Lms_Access::SOURCE_COIN         => __( 'Paid with coins', 'classic-edu-suite' ),
			CES_Lms_Access::SOURCE_SUBSCRIPTION => __( 'Subscription', 'classic-edu-suite' ),
			CES_Lms_Access::SOURCE_STAFF        => __( 'Staff', 'classic-edu-suite' ),
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}
}
