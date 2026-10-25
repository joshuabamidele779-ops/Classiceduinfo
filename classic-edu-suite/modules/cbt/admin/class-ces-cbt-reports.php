<?php
/**
 * Results and analytics screen.
 *
 * The useful question this screen answers is not "what did students score"
 * but "which of my questions are broken". A question with a 4% correct rate
 * across 200 sittings almost always has the wrong answer key, not 200 weak
 * students, so the hardest-questions table links straight to the editor.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin reporting.
 */
class CES_Cbt_Reports {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'ces-cbt-results';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Nothing to hook: the screen is registered through
		// `ces_admin_submenus` in CES_Cbt_Admin.
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! CES_Helpers::can_manage( CES_Cbt_Cpt::CAP ) && ! CES_Helpers::can_manage( 'ces_view_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to view CBT results.', 'classic-edu-suite' ), 403 );
		}

		$exam_id = isset( $_GET['exam_id'] ) ? absint( wp_unslash( $_GET['exam_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per     = 30;

		echo '<div class="wrap ces-wrap ces-cbt-admin">';
		echo '<h1>' . esc_html__( 'CBT Results', 'classic-edu-suite' ) . '</h1>';

		self::render_exam_picker( $exam_id );

		$stats = $exam_id ? CES_Cbt_Attempt::stats_for_exam( $exam_id ) : null;

		if ( $exam_id && $stats ) {
			self::render_stats( $stats );
		} else {
			$global = CES_Cbt_Attempt::global_stats();

			self::render_stats(
				array(
					'attempts'    => $global['attempts'],
					'students'    => 0,
					'avg_score'   => $global['avg_score'],
					'passes'      => $global['passes'],
					'avg_time'    => 0,
					'in_progress' => 0,
				),
				false
			);
		}

		self::render_hardest( $exam_id );
		self::render_attempts( $exam_id, $paged, $per );

		echo '</div>';
	}

	/**
	 * Render the exam filter form.
	 *
	 * @param int $exam_id Currently selected exam.
	 * @return void
	 */
	private static function render_exam_picker( $exam_id ) {
		$exams = get_posts(
			array(
				'post_type'      => CES_Cbt_Cpt::EXAM,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ces-cbt-filterbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />

			<label for="ces-report-exam" class="screen-reader-text"><?php esc_html_e( 'Exam', 'classic-edu-suite' ); ?></label>
			<select id="ces-report-exam" name="exam_id">
				<option value="0"><?php esc_html_e( 'All exams', 'classic-edu-suite' ); ?></option>
				<?php foreach ( $exams as $exam ) : ?>
					<option value="<?php echo absint( $exam->ID ); ?>" <?php selected( $exam_id, (int) $exam->ID ); ?>>
						<?php echo esc_html( $exam->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'classic-edu-suite' ), 'secondary', '', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render the headline stat cards.
	 *
	 * @param array $stats     Stats array.
	 * @param bool  $per_exam  Whether these are per-exam numbers.
	 * @return void
	 */
	private static function render_stats( array $stats, $per_exam = true ) {
		$cards = array(
			array(
				'label' => __( 'Attempts submitted', 'classic-edu-suite' ),
				'value' => number_format_i18n( (int) $stats['attempts'] ),
			),
			array(
				'label' => __( 'Average score', 'classic-edu-suite' ),
				'value' => number_format_i18n( (float) $stats['avg_score'], 1 ) . '%',
			),
			array(
				'label' => __( 'Passes', 'classic-edu-suite' ),
				'value' => number_format_i18n( (int) $stats['passes'] ),
			),
		);

		if ( $per_exam ) {
			$cards[] = array(
				'label' => __( 'Distinct students', 'classic-edu-suite' ),
				'value' => number_format_i18n( (int) $stats['students'] ),
			);

			$cards[] = array(
				'label' => __( 'Average time', 'classic-edu-suite' ),
				'value' => CES_Cbt_Attempt::format_duration( (int) $stats['avg_time'] ),
			);

			$cards[] = array(
				'label' => __( 'In progress now', 'classic-edu-suite' ),
				'value' => number_format_i18n( (int) $stats['in_progress'] ),
			);
		}

		echo '<div class="ces-cards">';

		foreach ( $cards as $card ) {
			echo '<div class="ces-card">';
			echo '<span class="ces-card__value">' . esc_html( $card['value'] ) . '</span>';
			echo '<span class="ces-card__label">' . esc_html( $card['label'] ) . '</span>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render the hardest-questions table.
	 *
	 * @param int $exam_id Exam filter, or 0.
	 * @return void
	 */
	private static function render_hardest( $exam_id ) {
		$rows = CES_Cbt_Attempt::hardest_questions( $exam_id, 15, 5 );

		echo '<h2>' . esc_html__( 'Hardest questions', 'classic-edu-suite' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Lowest correct rate, counting only questions answered at least five times. A rate near zero usually means the answer key is wrong.', 'classic-edu-suite' ) . '</p>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Not enough data yet.', 'classic-edu-suite' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Question', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Answered', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Correct', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Correct rate', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$edit = get_edit_post_link( $row['question_id'] );

			echo '<tr>';
			echo '<td>';

			if ( $edit ) {
				echo '<a href="' . esc_url( $edit ) . '">' . esc_html( $row['title'] ? $row['title'] : '#' . $row['question_id'] ) . '</a>';
			} else {
				echo esc_html( $row['title'] ? $row['title'] : '#' . $row['question_id'] );
			}

			echo '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['seen'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['correct'] ) ) . '</td>';
			echo '<td>';

			$class = ( $row['rate'] < 20 ) ? 'ces-flag-bad' : '';

			echo '<span class="' . esc_attr( $class ) . '">' . esc_html( number_format_i18n( $row['rate'], 1 ) ) . '%</span>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the attempts table.
	 *
	 * @param int $exam_id Exam filter, or 0.
	 * @param int $paged   Current page, 1-based.
	 * @param int $per     Rows per page.
	 * @return void
	 */
	private static function render_attempts( $exam_id, $paged, $per ) {
		$rows = CES_Cbt_Attempt::recent_attempts(
			array(
				'exam_id' => $exam_id,
				'limit'   => $per,
				'offset'  => ( $paged - 1 ) * $per,
			)
		);

		echo '<h2>' . esc_html__( 'Recent attempts', 'classic-edu-suite' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No attempts recorded yet.', 'classic-edu-suite' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Student', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Exam', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Score', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Result', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Time', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Submitted', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Flags', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row->user_id );
			$meta = json_decode( (string) $row->meta, true );
			$meta = is_array( $meta ) ? $meta : array();

			$blur   = isset( $meta['lockdown']['blur'] ) ? (int) $meta['lockdown']['blur'] : 0;
			$reason = isset( $meta['submit_reason'] ) ? (string) $meta['submit_reason'] : 'user';

			echo '<tr>';

			echo '<td>';
			if ( $user ) {
				echo esc_html( $user->display_name );
				echo '<br /><span class="ces-muted">' . esc_html( $user->user_email ) . '</span>';
			} else {
				echo esc_html__( 'Deleted user', 'classic-edu-suite' );
			}
			echo '</td>';

			echo '<td>' . esc_html( get_the_title( (int) $row->exam_id ) ) . '</td>';

			echo '<td>' . esc_html( number_format_i18n( (float) $row->score, 1 ) ) . '%<br /><span class="ces-muted">'
				. esc_html( (int) $row->correct_count . ' / ' . (int) $row->total_questions ) . '</span></td>';

			echo '<td>';
			if ( CES_Cbt_Attempt::STATUS_IN_PROGRESS === (string) $row->status ) {
				echo '<span class="ces-muted">' . esc_html__( 'In progress', 'classic-edu-suite' ) . '</span>';
			} elseif ( (int) $row->passed ) {
				echo '<span class="ces-flag-good">' . esc_html__( 'Passed', 'classic-edu-suite' ) . '</span>';
			} else {
				echo '<span class="ces-flag-bad">' . esc_html__( 'Not passed', 'classic-edu-suite' ) . '</span>';
			}
			echo '</td>';

			echo '<td>' . esc_html( CES_Cbt_Attempt::format_duration( (int) $row->time_spent ) ) . '</td>';

			echo '<td>';
			if ( $row->submitted_at ) {
				echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', (string) $row->submitted_at ) );

				if ( 'user' !== $reason ) {
					echo '<br /><span class="ces-muted">' . esc_html__( 'auto-submitted', 'classic-edu-suite' ) . '</span>';
				}
			} else {
				echo '&mdash;';
			}
			echo '</td>';

			echo '<td>';
			if ( $blur > 0 ) {
				echo '<span class="ces-flag-bad">' . esc_html(
					sprintf(
						/* translators: %d: number of times the student left the tab. */
						_n( '%d tab switch', '%d tab switches', $blur, 'classic-edu-suite' ),
						$blur
					)
				) . '</span>';
			} else {
				echo '&mdash;';
			}
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';

		// Simple prev/next, since an exact total would cost another COUNT.
		$base = add_query_arg(
			array(
				'page'    => self::PAGE,
				'exam_id' => $exam_id,
			),
			admin_url( 'admin.php' )
		);

		echo '<p class="ces-cbt-pager">';

		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $base ) ) . '">'
				. esc_html__( '&larr; Newer', 'classic-edu-suite' ) . '</a> ';
		}

		if ( count( $rows ) >= $per ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $base ) ) . '">'
				. esc_html__( 'Older &rarr;', 'classic-edu-suite' ) . '</a>';
		}

		echo '</p>';
	}
}
