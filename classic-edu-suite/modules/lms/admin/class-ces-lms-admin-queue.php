<?php
/**
 * Transcode queue screen.
 *
 * Packaging happens on WP-Cron, which on a quiet site means "whenever someone
 * visits". That makes the queue genuinely confusing to watch, so this screen
 * exists to answer three questions honestly: what is waiting, whether a worker
 * is running right now, and what to do when something is stuck.
 *
 * Every action here is a POST through `admin-post.php` with its own nonce, so
 * nothing destructive can be triggered by a link in an email.
 *
 * @package ClassicEduSuite\Lms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queue monitoring and manual control.
 */
class CES_Lms_Admin_Queue {

	/**
	 * Capability required for every action on this screen.
	 */
	const CAP = 'ces_manage_lms';

	/**
	 * admin-post action name.
	 */
	const ACTION = 'ces_lms_queue_action';

	/**
	 * Nonce action for this screen's forms.
	 */
	const NONCE = 'ces_lms_queue';

	/**
	 * Register the form handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle a posted queue action.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'classic-edu-suite' ) );
		}

		check_admin_referer( self::NONCE );

		$task      = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( (string) $_POST['task'] ) ) : '';
		$lesson_id = isset( $_POST['lesson_id'] ) ? (int) $_POST['lesson_id'] : 0;
		$notice    = '';

		switch ( $task ) {
			case 'run':
				/*
				 * Run the worker in this request. The screen then shows the
				 * real outcome instead of "queued" and a shrug, which is the
				 * only way to debug a broken ffmpeg on shared hosting.
				 */
				CES_Lms_Transcode::clear_lock();
				CES_Lms_Transcode::process_queue();
				$notice = 'ran';
				break;

			case 'unlock':
				CES_Lms_Transcode::clear_lock();
				$notice = 'unlocked';
				break;

			case 'remove':
				if ( $lesson_id > 0 ) {
					CES_Lms_Transcode::dequeue( $lesson_id );
					$notice = 'removed';
				}
				break;

			case 'requeue':
				if ( $lesson_id > 0 && CES_Lms_Cpt::LESSON === get_post_type( $lesson_id ) ) {
					CES_Lms_Transcode::enqueue( $lesson_id, true );
					$notice = 'requeued';
				}
				break;

			case 'source_only':
				if ( $lesson_id > 0 && CES_Lms_Cpt::LESSON === get_post_type( $lesson_id ) ) {
					CES_Lms_Transcode::dequeue( $lesson_id );
					CES_Lms_Transcode::adopt_source_only( $lesson_id, __( 'Switched by an administrator from the queue screen.', 'classic-edu-suite' ) );
					$notice = 'source_only';
				}
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'ces-lms-queue',
					'ces_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'classic-edu-suite' ) );
		}

		$queue  = CES_Lms_Transcode::queue();
		$caps   = CES_Lms_Transcode::capabilities();
		$locked = CES_Lms_Transcode::is_locked();
		$next   = wp_next_scheduled( CES_Lms_Transcode::CRON_HOOK );

		echo '<div class="wrap ces-wrap ces-lms-admin">';
		echo '<h1>' . esc_html__( 'Transcode Queue', 'classic-edu-suite' ) . '</h1>';

		self::render_notice();

		echo '<p class="ces-sub">' . esc_html( (string) $caps['note'] ) . '</p>';

		self::render_summary( $queue, $locked, $next );

		if ( empty( $queue ) ) {
			echo '<p>' . esc_html__( 'Nothing is waiting. Uploaded videos appear here until they have been packaged.', 'classic-edu-suite' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<table class="widefat striped ces-lms-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Position', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Lesson', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Waiting since', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $queue as $index => $lesson_id ) {
			$status   = CES_Lms_Meta::status( $lesson_id );
			$queued   = (int) get_post_meta( $lesson_id, CES_Lms_Meta::QUEUED_AT, true );
			$error    = (string) get_post_meta( $lesson_id, CES_Lms_Meta::ERROR, true );
			$attempts = (int) get_post_meta( $lesson_id, CES_Lms_Transcode::ATTEMPTS_META, true );

			echo '<tr>';
			echo '<td>' . esc_html( number_format_i18n( $index + 1 ) ) . '</td>';

			echo '<td><strong><a href="' . esc_url( (string) get_edit_post_link( $lesson_id, 'raw' ) ) . '">'
				. esc_html( get_the_title( $lesson_id ) ? get_the_title( $lesson_id ) : sprintf( '#%d', (int) $lesson_id ) )
				. '</a></strong>';

			if ( '' !== $error ) {
				echo '<br /><small class="ces-error">' . esc_html( wp_trim_words( $error, 24 ) ) . '</small>';
			}

			echo '</td>';

			echo '<td><span class="ces-pill ces-pill--' . esc_attr( $status ) . '">'
				. esc_html( CES_Lms_Meta::status_label( $status ) ) . '</span>';

			if ( $attempts > 0 ) {
				echo '<br /><small>' . esc_html(
					sprintf(
						/* translators: %d: number of failed attempts. */
						_n( '%d failed attempt', '%d failed attempts', $attempts, 'classic-edu-suite' ),
						$attempts
					)
				) . '</small>';
			}

			echo '</td>';

			echo '<td>' . ( $queued > 0
				? esc_html(
					sprintf(
						/* translators: %s: human time difference, e.g. "20 mins". */
						__( '%s ago', 'classic-edu-suite' ),
						human_time_diff( $queued, time() )
					)
				)
				: '&mdash;' ) . '</td>';

			echo '<td>';
			self::render_row_actions( $lesson_id );
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * The summary block and the global buttons.
	 *
	 * @param array    $queue  Queued lesson ids.
	 * @param bool     $locked Whether a worker holds the lock.
	 * @param int|false $next  Next cron timestamp.
	 * @return void
	 */
	private static function render_summary( array $queue, $locked, $next ) {
		echo '<ul class="ces-lms-facts">';

		echo '<li><strong>' . esc_html__( 'Waiting', 'classic-edu-suite' ) . ':</strong> '
			. esc_html( number_format_i18n( count( $queue ) ) ) . '</li>';

		echo '<li><strong>' . esc_html__( 'Worker', 'classic-edu-suite' ) . ':</strong> '
			. ( $locked
				? esc_html__( 'running now', 'classic-edu-suite' )
				: esc_html__( 'idle', 'classic-edu-suite' ) )
			. '</li>';

		echo '<li><strong>' . esc_html__( 'Next scheduled run', 'classic-edu-suite' ) . ':</strong> '
			. ( $next
				? esc_html(
					sprintf(
						/* translators: %s: human time difference. */
						__( 'in %s', 'classic-edu-suite' ),
						human_time_diff( time(), (int) $next )
					)
				)
				: esc_html__( 'not scheduled', 'classic-edu-suite' ) )
			. '</li>';

		echo '</ul>';

		echo '<p>';

		self::button( 'run', __( 'Run the queue now', 'classic-edu-suite' ), 0, 'button button-primary' );

		if ( $locked ) {
			echo ' ';
			self::button( 'unlock', __( 'Clear a stuck worker lock', 'classic-edu-suite' ) );
		}

		echo '</p>';

		if ( $locked ) {
			echo '<p class="description">' . esc_html__( 'A worker lock is held for fifteen minutes. If a packaging run was killed by the host mid-way, clearing the lock lets the next run start instead of waiting it out.', 'classic-edu-suite' ) . '</p>';
		}
	}

	/**
	 * Per-row buttons.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return void
	 */
	private static function render_row_actions( $lesson_id ) {
		self::button( 'requeue', __( 'Retry', 'classic-edu-suite' ), $lesson_id );
		echo ' ';
		self::button( 'source_only', __( 'Stream as one file', 'classic-edu-suite' ), $lesson_id );
		echo ' ';
		self::button( 'remove', __( 'Remove', 'classic-edu-suite' ), $lesson_id, 'button button-link-delete' );
	}

	/**
	 * One self-contained POST button.
	 *
	 * @param string $task      Task key.
	 * @param string $label     Button label.
	 * @param int    $lesson_id Optional lesson id.
	 * @param string $class     Button classes.
	 * @return void
	 */
	private static function button( $task, $label, $lesson_id = 0, $class = 'button' ) {
		echo '<form method="post" class="ces-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="task" value="' . esc_attr( $task ) . '" />';

		if ( $lesson_id > 0 ) {
			echo '<input type="hidden" name="lesson_id" value="' . esc_attr( (string) (int) $lesson_id ) . '" />';
		}

		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Show the result of the last action.
	 *
	 * @return void
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['ces_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['ces_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'ran'         => __( 'The queue was processed. Anything still listed below did not finish.', 'classic-edu-suite' ),
			'unlocked'    => __( 'The worker lock was cleared.', 'classic-edu-suite' ),
			'removed'     => __( 'That lesson was taken out of the queue.', 'classic-edu-suite' ),
			'requeued'    => __( 'That lesson was queued again from the start.', 'classic-edu-suite' ),
			'source_only' => __( 'That lesson will now stream as a single protected file.', 'classic-edu-suite' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
	}
}
