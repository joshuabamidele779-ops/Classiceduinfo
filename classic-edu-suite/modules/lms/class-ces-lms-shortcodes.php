<?php
/**
 * Front-end shortcodes for the video library.
 *
 * Phone-first throughout. Most of this site's students are on small Android
 * screens on metered data, so the markup is a single-column stack that only
 * becomes a grid when there is room, thumbnails are lazy-loaded, and nothing
 * loads the player assets unless a player is actually on the page.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode handlers.
 */
class CES_Lms_Shortcodes {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'ces_course_list', array( __CLASS__, 'course_list' ) );
		add_shortcode( 'ces_course', array( __CLASS__, 'course' ) );
		add_shortcode( 'ces_lesson', array( __CLASS__, 'lesson' ) );
		add_shortcode( 'ces_my_courses', array( __CLASS__, 'my_courses' ) );

		add_filter( 'the_content', array( __CLASS__, 'inject_single_course' ), 20 );
	}

	/**
	 * Append the lesson list to a single course view.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function inject_single_course( $content ) {
		if ( ! is_singular( CES_Lms_Cpt::COURSE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return $content . self::render_course( (int) get_the_ID(), false );
	}

	// ------------------------------------------------------------------
	// [ces_course_list]
	// ------------------------------------------------------------------

	/**
	 * Browsable course grid.
	 *
	 * Attributes: level, subject, syllabus, exam_type, per_page, filters,
	 * orderby, order.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function course_list( $atts ) {
		$atts = shortcode_atts(
			array(
				'level'     => '',
				'subject'   => '',
				'syllabus'  => '',
				'exam_type' => '',
				'per_page'  => 12,
				'filters'   => 'yes',
				'orderby'   => 'menu_order',
				'order'     => 'ASC',
			),
			$atts,
			'ces_course_list'
		);

		CES_Lms_Player::enqueue();

		$per_page = max( 1, min( 48, (int) $atts['per_page'] ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page     = isset( $_GET['ces_page'] ) ? max( 1, absint( wp_unslash( $_GET['ces_page'] ) ) ) : 1;
		$search   = isset( $_GET['ces_q'] ) ? sanitize_text_field( wp_unslash( $_GET['ces_q'] ) ) : '';
		$g_level  = isset( $_GET['ces_level'] ) ? sanitize_title( wp_unslash( $_GET['ces_level'] ) ) : '';
		$g_subj   = isset( $_GET['ces_subject'] ) ? sanitize_title( wp_unslash( $_GET['ces_subject'] ) ) : '';
		// phpcs:enable

		$level   = '' !== $g_level ? $g_level : sanitize_title( (string) $atts['level'] );
		$subject = '' !== $g_subj ? $g_subj : sanitize_title( (string) $atts['subject'] );

		$args = array(
			'post_type'      => CES_Lms_Cpt::COURSE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title' === $atts['orderby'] ? 'title' : array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'order'          => 'DESC' === strtoupper( (string) $atts['order'] ) ? 'DESC' : 'ASC',
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$tax_query = array();

		foreach (
			array(
				CES_Lms_Cpt::TAX_LEVEL     => $level,
				CES_Lms_Cpt::TAX_SUBJECT   => $subject,
				CES_Lms_Cpt::TAX_SYLLABUS  => sanitize_title( (string) $atts['syllabus'] ),
				CES_Lms_Cpt::TAX_EXAM_TYPE => sanitize_title( (string) $atts['exam_type'] ),
			) as $taxonomy => $slug
		) {
			if ( '' !== $slug && taxonomy_exists( $taxonomy ) ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $slug,
				);
			}
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query = new WP_Query( $args );

		$html = '<div class="ces-lms ces-courses">';

		if ( 'yes' === $atts['filters'] ) {
			$html .= self::render_filters( $search, $level, $subject );
		}

		if ( ! $query->have_posts() ) {
			$html .= '<p class="ces-note ces-note--info">' . esc_html__( 'No courses found yet. Check back soon.', 'classic-edu-suite' ) . '</p>';
			$html .= '</div>';

			return $html;
		}

		$html .= '<ul class="ces-courses__grid">';

		foreach ( $query->posts as $course ) {
			$html .= self::course_card( (int) $course->ID );
		}

		$html .= '</ul>';
		$html .= self::render_pagination( $page, (int) $query->max_num_pages );
		$html .= '</div>';

		return $html;
	}

	/**
	 * Filter bar for the course list.
	 *
	 * @param string $search  Current search term.
	 * @param string $level   Current level slug.
	 * @param string $subject Current subject slug.
	 * @return string
	 */
	private static function render_filters( $search, $level, $subject ) {
		$html = '<form class="ces-filters" method="get" action="">';

		$html .= '<label class="ces-filters__field"><span class="screen-reader-text">'
			. esc_html__( 'Search courses', 'classic-edu-suite' ) . '</span>';
		$html .= '<input type="search" name="ces_q" value="' . esc_attr( $search ) . '" placeholder="'
			. esc_attr__( 'Search courses…', 'classic-edu-suite' ) . '" /></label>';

		foreach (
			array(
				'ces_level'   => array( CES_Lms_Cpt::TAX_LEVEL, __( 'All levels', 'classic-edu-suite' ), $level ),
				'ces_subject' => array( CES_Lms_Cpt::TAX_SUBJECT, __( 'All subjects', 'classic-edu-suite' ), $subject ),
			) as $name => $spec
		) {
			list( $taxonomy, $label, $current ) = $spec;

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => 100,
				)
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$html .= '<label class="ces-filters__field"><span class="screen-reader-text">' . esc_html( $label ) . '</span>';
			$html .= '<select name="' . esc_attr( $name ) . '">';
			$html .= '<option value="">' . esc_html( $label ) . '</option>';

			foreach ( $terms as $term ) {
				$html .= '<option value="' . esc_attr( $term->slug ) . '" ' . selected( $current, $term->slug, false ) . '>'
					. esc_html( $term->name ) . '</option>';
			}

			$html .= '</select></label>';
		}

		$html .= '<button type="submit" class="ces-btn ces-btn--ghost">' . esc_html__( 'Filter', 'classic-edu-suite' ) . '</button>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * One course card.
	 *
	 * @param int $course_id Course post id.
	 * @return string
	 */
	private static function course_card( $course_id ) {
		$lessons  = CES_Lms_Cpt::course_lesson_ids( $course_id );
		$seconds  = 0;
		$user_id  = get_current_user_id();
		$progress = $user_id ? CES_Lms_Progress::course_progress( $course_id, $user_id ) : null;

		foreach ( $lessons as $lesson_id ) {
			$seconds += CES_Lms_Meta::duration( $lesson_id );
		}

		$subject = '';
		$terms   = get_the_terms( $course_id, CES_Lms_Cpt::TAX_SUBJECT );

		if ( $terms && ! is_wp_error( $terms ) ) {
			$subject = (string) $terms[0]->name;
		}

		$html = '<li class="ces-card-course">';
		$html .= '<a class="ces-card-course__link" href="' . esc_url( (string) get_permalink( $course_id ) ) . '">';

		$thumb = get_the_post_thumbnail( $course_id, 'medium_large', array( 'loading' => 'lazy', 'class' => 'ces-card-course__img' ) );

		if ( $thumb ) {
			$html .= '<span class="ces-card-course__media">' . $thumb . '</span>';
		} else {
			/*
			 * No featured image. Rather than an empty grey box, fall back to
			 * the subject artwork carried in ces-base.css, so a library put
			 * together in a hurry still looks like a library.
			 */
			$html .= '<span class="ces-card-course__media ces-card-course__media--art" data-ces-art="'
				. esc_attr( CES_Helpers::subject_art( $subject ) ) . '" aria-hidden="true"></span>';
		}

		$html .= '<span class="ces-card-course__body">';
		$html .= '<span class="ces-card-course__title">' . esc_html( get_the_title( $course_id ) ) . '</span>';

		$meta = array();

		if ( '' !== $subject ) {
			$meta[] = $subject;
		}

		$meta[] = sprintf(
			/* translators: %d: number of lessons. */
			_n( '%d lesson', '%d lessons', count( $lessons ), 'classic-edu-suite' ),
			count( $lessons )
		);

		if ( $seconds > 0 ) {
			$meta[] = CES_Lms_Meta::format_duration( $seconds );
		}

		$html .= '<span class="ces-card-course__meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>';

		if ( $progress && $progress['total'] > 0 && $progress['completed'] > 0 ) {
			$html .= '<span class="ces-progress" role="img" aria-label="'
				. esc_attr(
					sprintf(
						/* translators: %d: completion percentage. */
						__( '%d%% complete', 'classic-edu-suite' ),
						(int) $progress['percent']
					)
				) . '">';
			$html .= '<span class="ces-progress__bar" style="width:' . esc_attr( (int) $progress['percent'] ) . '%"></span>';
			$html .= '</span>';
		}

		$html .= '</span></a></li>';

		return $html;
	}

	/**
	 * Simple previous/next pagination that preserves the active filters.
	 *
	 * @param int $page  Current page.
	 * @param int $pages Total pages.
	 * @return string
	 */
	private static function render_pagination( $page, $pages ) {
		if ( $pages <= 1 ) {
			return '';
		}

		$html = '<nav class="ces-pager" aria-label="' . esc_attr__( 'Course pages', 'classic-edu-suite' ) . '">';

		if ( $page > 1 ) {
			$html .= '<a class="ces-btn ces-btn--ghost" href="' . esc_url( add_query_arg( 'ces_page', $page - 1 ) ) . '">'
				. esc_html__( 'Previous', 'classic-edu-suite' ) . '</a>';
		}

		$html .= '<span class="ces-pager__count">' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: total pages. */
				__( 'Page %1$d of %2$d', 'classic-edu-suite' ),
				$page,
				$pages
			)
		) . '</span>';

		if ( $page < $pages ) {
			$html .= '<a class="ces-btn ces-btn--ghost" href="' . esc_url( add_query_arg( 'ces_page', $page + 1 ) ) . '">'
				. esc_html__( 'Next', 'classic-edu-suite' ) . '</a>';
		}

		$html .= '</nav>';

		return $html;
	}

	// ------------------------------------------------------------------
	// [ces_course]
	// ------------------------------------------------------------------

	/**
	 * One course and its lesson list.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function course( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'title' => 'yes',
			),
			$atts,
			'ces_course'
		);

		$course_id = (int) $atts['id'];

		if ( $course_id <= 0 && is_singular( CES_Lms_Cpt::COURSE ) ) {
			$course_id = (int) get_the_ID();
		}

		return self::render_course( $course_id, 'yes' === $atts['title'] );
	}

	/**
	 * Build the course view.
	 *
	 * @param int  $course_id  Course post id.
	 * @param bool $show_title Whether to print the course title.
	 * @return string
	 */
	private static function render_course( $course_id, $show_title = true ) {
		$course_id = (int) $course_id;
		$course    = get_post( $course_id );

		if ( ! $course || CES_Lms_Cpt::COURSE !== $course->post_type ) {
			return '';
		}

		CES_Lms_Player::enqueue();

		$user_id  = get_current_user_id();
		$ids      = CES_Lms_Cpt::course_lesson_ids( $course_id );
		$progress = $user_id ? CES_Lms_Progress::for_lessons( $user_id, $ids ) : array();

		$html = '<div class="ces-lms ces-course" id="ces-course-' . esc_attr( $course_id ) . '">';

		if ( $show_title ) {
			$html .= '<h2 class="ces-course__title">' . esc_html( get_the_title( $course_id ) ) . '</h2>';
		}

		if ( $user_id ) {
			$overall = CES_Lms_Progress::course_progress( $course_id, $user_id );

			if ( $overall['total'] > 0 ) {
				$html .= '<p class="ces-course__progress">' . esc_html(
					sprintf(
						/* translators: 1: completed lessons, 2: total lessons, 3: percentage. */
						__( '%1$d of %2$d lessons finished (%3$d%%)', 'classic-edu-suite' ),
						$overall['completed'],
						$overall['total'],
						$overall['percent']
					)
				) . '</p>';
			}
		}

		if ( empty( $ids ) ) {
			$html .= '<p class="ces-note ces-note--info">' . esc_html__( 'No lessons have been added to this course yet.', 'classic-edu-suite' ) . '</p>';
			$html .= '</div>';

			return $html;
		}

		$html .= '<ol class="ces-lessons">';

		foreach ( $ids as $index => $lesson_id ) {
			$html .= self::lesson_row( (int) $lesson_id, (int) $index + 1, $user_id, $progress );
		}

		$html .= '</ol></div>';

		return $html;
	}

	/**
	 * One row in a course's lesson list.
	 *
	 * @param int   $lesson_id Lesson post id.
	 * @param int   $position  1-based position.
	 * @param int   $user_id   Viewer id.
	 * @param array $progress  Progress rows keyed by lesson id.
	 * @return string
	 */
	private static function lesson_row( $lesson_id, $position, $user_id, array $progress ) {
		$state    = CES_Lms_Access::evaluate( $lesson_id, $user_id );
		$duration = CES_Lms_Meta::duration( $lesson_id );
		$row      = isset( $progress[ $lesson_id ] ) ? $progress[ $lesson_id ] : null;
		$done     = $row && ! empty( $row['completed'] );

		$classes = array( 'ces-lesson-row' );

		if ( ! empty( $state['allowed'] ) ) {
			$classes[] = 'is-open';
		} else {
			$classes[] = 'is-locked';
		}

		if ( $done ) {
			$classes[] = 'is-done';
		}

		$html = '<li class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		$html .= '<a class="ces-lesson-row__link" href="' . esc_url( (string) get_permalink( $lesson_id ) ) . '">';
		$html .= '<span class="ces-lesson-row__num">' . esc_html( (string) $position ) . '</span>';
		$html .= '<span class="ces-lesson-row__main">';
		$html .= '<span class="ces-lesson-row__title">' . esc_html( get_the_title( $lesson_id ) ) . '</span>';

		$bits = array();

		if ( $duration > 0 ) {
			$bits[] = CES_Lms_Meta::format_duration( $duration );
		}

		if ( ! empty( $state['allowed'] ) ) {
			if ( 'free_preview' === $state['reason'] ) {
				$bits[] = __( 'Free preview', 'classic-edu-suite' );
			} elseif ( 'subscriber' === $state['reason'] ) {
				$bits[] = __( 'Included', 'classic-edu-suite' );
			} elseif ( 'unlocked' === $state['reason'] ) {
				$bits[] = __( 'Unlocked', 'classic-edu-suite' );
			}
		} else {
			$bits[] = sprintf(
				/* translators: %s: coin cost. */
				__( '%s coins', 'classic-edu-suite' ),
				CES_Helpers::format_coins( (int) $state['cost'] )
			);
		}

		if ( $done ) {
			$bits[] = __( 'Finished', 'classic-edu-suite' );
		}

		$html .= '<span class="ces-lesson-row__meta">' . esc_html( implode( ' · ', $bits ) ) . '</span>';
		$html .= '</span>';

		$html .= '<span class="ces-lesson-row__icon" aria-hidden="true">'
			. ( empty( $state['allowed'] ) ? '&#128274;' : ( $done ? '&#10003;' : '&#9654;' ) )
			. '</span>';

		$html .= '</a></li>';

		return $html;
	}

	// ------------------------------------------------------------------
	// [ces_lesson]
	// ------------------------------------------------------------------

	/**
	 * One lesson player.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function lesson( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'title' => 'no',
				'notes' => 'yes',
			),
			$atts,
			'ces_lesson'
		);

		$lesson_id = (int) $atts['id'];

		if ( $lesson_id <= 0 && is_singular( CES_Lms_Cpt::LESSON ) ) {
			$lesson_id = (int) get_the_ID();
		}

		if ( $lesson_id <= 0 ) {
			return '';
		}

		return CES_Lms_Player::render(
			$lesson_id,
			array(
				'show_title' => 'yes' === $atts['title'],
				'show_notes' => 'yes' === $atts['notes'],
			)
		);
	}

	// ------------------------------------------------------------------
	// [ces_my_courses]
	// ------------------------------------------------------------------

	/**
	 * Courses the logged-in student has started.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function my_courses( $atts ) {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return '<div class="ces-lms"><p class="ces-note ces-note--info">'
				. esc_html__( 'Log in to see the courses you have started.', 'classic-edu-suite' )
				. ' <a href="' . esc_url( CES_Helpers::login_url() ) . '">' . esc_html__( 'Log in', 'classic-edu-suite' ) . '</a>'
				. '</p></div>';
		}

		CES_Lms_Player::enqueue();

		$user_id    = get_current_user_id();
		$course_ids = CES_Lms_Rest::course_ids_for_user( $user_id );

		$html = '<div class="ces-lms ces-my-courses">';

		if ( empty( $course_ids ) ) {
			$html .= '<p class="ces-note ces-note--info">'
				. esc_html__( 'You have not started any video lessons yet.', 'classic-edu-suite' ) . '</p>';
			$html .= '</div>';

			return $html;
		}

		$html .= '<ul class="ces-courses__grid">';

		foreach ( $course_ids as $course_id ) {
			if ( 'publish' !== get_post_status( $course_id ) ) {
				continue;
			}

			$html .= self::course_card( (int) $course_id );
		}

		$html .= '</ul></div>';

		return $html;
	}
}
