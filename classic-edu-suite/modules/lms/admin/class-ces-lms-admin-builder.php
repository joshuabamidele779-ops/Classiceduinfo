<?php
/**
 * Course builder: the panel on the course editor that decides which lessons
 * belong to a course and in what order.
 *
 * Ordering is stored in the lesson's `menu_order`, which is what
 * `CES_Lms_Cpt::course_lesson_ids()` already sorts by, and membership is the
 * `CES_Lms_Meta::COURSE` meta on the lesson. Keeping both on the lesson side
 * means a lesson can never appear in two courses at once, and a course can be
 * deleted without orphaning anything.
 *
 * The up/down buttons are deliberately plain buttons rather than a drag
 * handle. Dragging a 40-lesson list on a phone browser in the WordPress admin
 * is miserable, and the site owner does edit on a phone.
 *
 * @package ClassicEduSuite\Lms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Course lesson builder.
 */
class CES_Lms_Admin_Builder {

	/**
	 * Capability required to rearrange a course.
	 */
	const CAP = 'ces_manage_lms';

	/**
	 * Register the AJAX endpoints.
	 *
	 * The nonce action is shared with the uploader because both are driven by
	 * the same `cesLmsAdmin.nonce` value handed to `lms-admin.js`.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_ces_lms_save_order', array( __CLASS__, 'ajax_save_order' ) );
		add_action( 'wp_ajax_ces_lms_attach_lesson', array( __CLASS__, 'ajax_attach' ) );
		add_action( 'wp_ajax_ces_lms_detach_lesson', array( __CLASS__, 'ajax_detach' ) );
	}

	/**
	 * Check the nonce and capability, or end the request.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! check_ajax_referer( CES_Lms_Admin_Upload::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'classic-edu-suite' ) ), 403 );
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit courses.', 'classic-edu-suite' ) ), 403 );
		}
	}

	/**
	 * Validate a course id the current user may edit.
	 *
	 * @param int $course_id Candidate id.
	 * @return int The id, or 0.
	 */
	private static function valid_course( $course_id ) {
		$course_id = (int) $course_id;

		if ( $course_id <= 0 || CES_Lms_Cpt::COURSE !== get_post_type( $course_id ) ) {
			return 0;
		}

		return current_user_can( 'edit_post', $course_id ) ? $course_id : 0;
	}

	/**
	 * Validate a lesson id the current user may edit.
	 *
	 * @param int $lesson_id Candidate id.
	 * @return int The id, or 0.
	 */
	private static function valid_lesson( $lesson_id ) {
		$lesson_id = (int) $lesson_id;

		if ( $lesson_id <= 0 || CES_Lms_Cpt::LESSON !== get_post_type( $lesson_id ) ) {
			return 0;
		}

		return current_user_can( 'edit_post', $lesson_id ) ? $lesson_id : 0;
	}

	// ------------------------------------------------------------------
	// AJAX.
	// ------------------------------------------------------------------

	/**
	 * Persist a new lesson order for one course.
	 *
	 * @return void
	 */
	public static function ajax_save_order() {
		self::guard();

		$course_id = self::valid_course( isset( $_POST['course_id'] ) ? (int) $_POST['course_id'] : 0 );

		if ( ! $course_id ) {
			wp_send_json_error( array( 'message' => __( 'That course does not exist.', 'classic-edu-suite' ) ), 404 );
		}

		$raw = isset( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'The new order could not be read.', 'classic-edu-suite' ) ), 400 );
		}

		// Only lessons that already belong to this course may be reordered by
		// this call; attaching is a separate, explicit action.
		$existing = CES_Lms_Cpt::course_lesson_ids( $course_id, self::statuses() );
		$position = 0;
		$seen     = array();

		foreach ( $raw as $candidate ) {
			$lesson_id = (int) $candidate;

			if ( ! in_array( $lesson_id, $existing, true ) || isset( $seen[ $lesson_id ] ) ) {
				continue;
			}

			$seen[ $lesson_id ] = true;

			wp_update_post(
				array(
					'ID'         => $lesson_id,
					'menu_order' => $position,
				)
			);

			$position++;
		}

		wp_send_json_success( array( 'saved' => count( $seen ) ) );
	}

	/**
	 * Add a lesson to a course.
	 *
	 * @return void
	 */
	public static function ajax_attach() {
		self::guard();

		$course_id = self::valid_course( isset( $_POST['course_id'] ) ? (int) $_POST['course_id'] : 0 );
		$lesson_id = self::valid_lesson( isset( $_POST['lesson_id'] ) ? (int) $_POST['lesson_id'] : 0 );

		if ( ! $course_id || ! $lesson_id ) {
			wp_send_json_error( array( 'message' => __( 'That course or lesson does not exist.', 'classic-edu-suite' ) ), 404 );
		}

		$current = CES_Lms_Cpt::course_lesson_ids( $course_id, self::statuses() );

		update_post_meta( $lesson_id, CES_Lms_Meta::COURSE, $course_id );

		wp_update_post(
			array(
				'ID'         => $lesson_id,
				'menu_order' => count( $current ),
			)
		);

		wp_send_json_success(
			array(
				'lessonId' => $lesson_id,
				'title'    => get_the_title( $lesson_id ),
				'html'     => self::rows_html( $course_id ),
			)
		);
	}

	/**
	 * Remove a lesson from a course, without deleting the lesson.
	 *
	 * @return void
	 */
	public static function ajax_detach() {
		self::guard();

		$course_id = self::valid_course( isset( $_POST['course_id'] ) ? (int) $_POST['course_id'] : 0 );
		$lesson_id = self::valid_lesson( isset( $_POST['lesson_id'] ) ? (int) $_POST['lesson_id'] : 0 );

		if ( ! $course_id || ! $lesson_id ) {
			wp_send_json_error( array( 'message' => __( 'That course or lesson does not exist.', 'classic-edu-suite' ) ), 404 );
		}

		if ( (int) CES_Lms_Cpt::lesson_course_id( $lesson_id ) !== $course_id ) {
			wp_send_json_error( array( 'message' => __( 'That lesson is not part of this course.', 'classic-edu-suite' ) ), 400 );
		}

		delete_post_meta( $lesson_id, CES_Lms_Meta::COURSE );

		wp_send_json_success(
			array(
				'lessonId' => $lesson_id,
				'html'     => self::rows_html( $course_id ),
			)
		);
	}

	// ------------------------------------------------------------------
	// Rendering.
	// ------------------------------------------------------------------

	/**
	 * Post statuses the builder shows. Drafts included: a course is usually
	 * assembled before any of its lessons are published.
	 *
	 * @return string[]
	 */
	private static function statuses() {
		return array( 'publish', 'draft', 'pending', 'private' );
	}

	/**
	 * The builder panel for the course editor.
	 *
	 * @param int $course_id Course post id.
	 * @return void
	 */
	public static function render_panel( $course_id ) {
		$course_id = (int) $course_id;

		if ( ! current_user_can( self::CAP ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to edit this course.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		echo '<div class="ces-builder" data-ces-builder="' . esc_attr( (string) wp_json_encode( array( 'courseId' => $course_id ) ) ) . '">';

		echo '<div class="ces-builder__list" data-role="rows">';
		echo self::rows_html( $course_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in rows_html().
		echo '</div>';

		echo '<p class="ces-builder__msg" data-role="msg" aria-live="polite"></p>';

		self::render_attach( $course_id );

		echo '<p class="ces-builder__foot">';
		echo '<a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . CES_Lms_Cpt::LESSON . '&ces_course=' . $course_id ) ) . '">'
			. esc_html__( 'Add a new lesson', 'classic-edu-suite' ) . '</a>';
		echo '</p>';

		echo '</div>';
	}

	/**
	 * The lesson rows for a course.
	 *
	 * Every dynamic value is escaped here, so the caller can echo the result
	 * directly and the AJAX handlers can hand it back to the browser.
	 *
	 * @param int $course_id Course post id.
	 * @return string Markup.
	 */
	public static function rows_html( $course_id ) {
		$course_id = (int) $course_id;
		$lessons   = CES_Lms_Cpt::course_lesson_ids( $course_id, self::statuses() );

		if ( empty( $lessons ) ) {
			return '<p class="ces-builder__empty">'
				. esc_html__( 'No lessons in this course yet. Attach an existing lesson below, or create a new one.', 'classic-edu-suite' )
				. '</p>';
		}

		$total = count( $lessons );
		$html  = '<ol class="ces-builder__rows">';

		foreach ( $lessons as $index => $lesson_id ) {
			$status   = CES_Lms_Meta::status( $lesson_id );
			$duration = CES_Lms_Meta::duration( $lesson_id );
			$post     = get_post( $lesson_id );

			$html .= '<li class="ces-builder__row" data-lesson="' . esc_attr( (string) $lesson_id ) . '">';

			$html .= '<span class="ces-builder__move">';
			$html .= '<button type="button" class="button ces-builder__btn" data-role="up"'
				. ( 0 === $index ? ' disabled' : '' )
				. ' aria-label="' . esc_attr__( 'Move up', 'classic-edu-suite' ) . '">&uarr;</button>';
			$html .= '<button type="button" class="button ces-builder__btn" data-role="down"'
				. ( $index === $total - 1 ? ' disabled' : '' )
				. ' aria-label="' . esc_attr__( 'Move down', 'classic-edu-suite' ) . '">&darr;</button>';
			$html .= '</span>';

			$html .= '<span class="ces-builder__main">';
			$html .= '<a class="ces-builder__title" href="' . esc_url( (string) get_edit_post_link( $lesson_id, 'raw' ) ) . '">'
				. esc_html( get_the_title( $lesson_id ) ? get_the_title( $lesson_id ) : sprintf( '#%d', $lesson_id ) )
				. '</a>';

			$meta = array( CES_Lms_Meta::status_label( $status ) );

			if ( $duration > 0 ) {
				$meta[] = CES_Lms_Meta::format_duration( $duration );
			}

			if ( $post && 'publish' !== $post->post_status ) {
				$meta[] = $post->post_status;
			}

			if ( CES_Lms_Access::is_free_preview( $lesson_id ) ) {
				$meta[] = __( 'free preview', 'classic-edu-suite' );
			}

			$html .= '<span class="ces-builder__meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
			$html .= '</span>';

			$html .= '<button type="button" class="button-link ces-builder__remove" data-role="detach">'
				. esc_html__( 'Remove', 'classic-edu-suite' ) . '</button>';

			$html .= '</li>';
		}

		$html .= '</ol>';

		return $html;
	}

	/**
	 * The "attach an existing lesson" control.
	 *
	 * Only lessons with no course are offered: moving a lesson out of another
	 * course should be a deliberate act on that course's screen, not a
	 * side-effect of a dropdown here.
	 *
	 * @param int $course_id Course post id.
	 * @return void
	 */
	private static function render_attach( $course_id ) {
		$free = get_posts(
			array(
				'post_type'      => CES_Lms_Cpt::LESSON,
				'post_status'    => self::statuses(),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => CES_Lms_Meta::COURSE,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => CES_Lms_Meta::COURSE,
						'value' => '',
					),
					array(
						'key'   => CES_Lms_Meta::COURSE,
						'value' => '0',
					),
				),
			)
		);

		if ( empty( $free ) ) {
			return;
		}

		echo '<p class="ces-builder__attach">';
		echo '<label class="screen-reader-text" for="ces-builder-attach-' . esc_attr( (string) $course_id ) . '">'
			. esc_html__( 'Attach an existing lesson', 'classic-edu-suite' ) . '</label>';
		echo '<select id="ces-builder-attach-' . esc_attr( (string) $course_id ) . '" data-role="attach-select">';
		echo '<option value="0">' . esc_html__( '— Attach an existing lesson —', 'classic-edu-suite' ) . '</option>';

		foreach ( $free as $lesson ) {
			$label = get_the_title( $lesson ) ? get_the_title( $lesson ) : sprintf( '#%d', (int) $lesson->ID );

			if ( 'publish' !== $lesson->post_status ) {
				$label .= ' (' . $lesson->post_status . ')';
			}

			echo '<option value="' . esc_attr( (string) (int) $lesson->ID ) . '">' . esc_html( $label ) . '</option>';
		}

		echo '</select> ';
		echo '<button type="button" class="button" data-role="attach">' . esc_html__( 'Attach', 'classic-edu-suite' ) . '</button>';
		echo '</p>';
	}
}
