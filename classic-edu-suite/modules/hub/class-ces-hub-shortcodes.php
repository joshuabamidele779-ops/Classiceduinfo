<?php
/**
 * The Study hub and everything inside it.
 *
 * `[ces_study_hub]` is a whole section on one page. With no query string it
 * draws the tile grid from the app's Study tab; with `?view=` it draws the
 * matching panel on the same page. That means one WordPress page, no settings
 * to wire up, and a back link that always goes somewhere sensible.
 *
 * The inner panels are also available on their own, for an admin who would
 * rather give each one its own page:
 *
 *   [ces_resource_library type="novel"]
 *   [ces_past_questions]
 *   [ces_study]
 *   [ces_leaderboard]
 *
 * @package ClassicEduSuite\Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end rendering for the hub.
 */
class CES_Hub_Shortcodes {

	/**
	 * Views the hub knows how to draw.
	 *
	 * @var string[]
	 */
	private static $views = array( 'library', 'resource', 'past-questions', 'study', 'leaderboard', 'videos', 'guidance' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'ces_study_hub', array( __CLASS__, 'hub' ) );
		add_shortcode( 'ces_resource_library', array( __CLASS__, 'library' ) );
		add_shortcode( 'ces_past_questions', array( __CLASS__, 'past_questions' ) );
		add_shortcode( 'ces_study', array( __CLASS__, 'study' ) );
		add_shortcode( 'ces_leaderboard', array( __CLASS__, 'leaderboard' ) );

		add_filter( 'ces_base_asset_shortcodes', array( __CLASS__, 'asset_shortcodes' ) );
	}

	/**
	 * Pull the shared assets onto pages carrying our shortcodes.
	 *
	 * @param array $tags Tags so far.
	 * @return array
	 */
	public static function asset_shortcodes( $tags ) {
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}

		return array_merge(
			$tags,
			array( 'ces_study_hub', 'ces_resource_library', 'ces_past_questions', 'ces_study', 'ces_leaderboard' )
		);
	}

	/**
	 * Enqueue the hub stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue_style() {
		CES_Assets::enqueue_base();

		if ( ! wp_style_is( 'ces-hub', 'registered' ) ) {
			wp_register_style(
				'ces-hub',
				CES_URL . 'modules/hub/assets/hub.css',
				array( 'ces-base' ),
				CES_VERSION
			);
		}

		wp_enqueue_style( 'ces-hub' );
	}

	/**
	 * The current page URL with hub arguments applied.
	 *
	 * @param array $args Query arguments; a null value removes the key.
	 * @return string
	 */
	public static function hub_url( array $args = array() ) {
		$base = get_permalink();

		if ( ! $base ) {
			$base = home_url( add_query_arg( array() ) );
		}

		// Carry nothing across except what the caller asks for; a stale
		// `resource` or `type` leaking into another panel is confusing.
		return add_query_arg( array_filter( $args, static function ( $value ) {
			return null !== $value && '' !== $value;
		} ), $base );
	}

	/**
	 * Read a sanitised query argument.
	 *
	 * @param string $key     Query key.
	 * @param string $default Fallback.
	 * @return string
	 */
	private static function arg( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	/* ---------------------------------------------------------------------
	 * [ces_study_hub]
	 * ------------------------------------------------------------------ */

	/**
	 * The hub: tiles, or the requested panel.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function hub( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title' => '',
			),
			$atts,
			'ces_study_hub'
		);

		self::enqueue_style();

		$view = sanitize_key( str_replace( '_', '-', self::arg( 'view' ) ) );

		if ( ! in_array( $view, self::$views, true ) ) {
			return self::render_tiles( (string) $atts['title'] );
		}

		$panel = '';

		switch ( $view ) {
			case 'library':
				$panel = self::library( array( 'type' => self::arg( 'type' ) ) );
				break;

			case 'resource':
				$panel = self::render_resource( absint( self::arg( 'resource' ) ) );
				break;

			case 'past-questions':
				$panel = self::past_questions( array() );
				break;

			case 'study':
				$panel = self::study( array() );
				break;

			case 'leaderboard':
				$panel = self::leaderboard( array() );
				break;

			case 'videos':
				$panel = self::render_videos();
				break;

			case 'guidance':
				$panel = shortcode_exists( 'ces_guidance' )
					? do_shortcode( '[ces_guidance]' )
					: '<p>' . esc_html__( 'Guidance is not available yet.', 'classic-edu-suite' ) . '</p>';
				break;
		}

		return '<div class="ces-hub">'
			. '<p class="ces-hub__back"><a href="' . esc_url( self::hub_url() ) . '">&larr; ' . esc_html__( 'Back to Study', 'classic-edu-suite' ) . '</a></p>'
			. $panel
			. '</div>';
	}

	/**
	 * The tile grid.
	 *
	 * @param string $title Optional heading.
	 * @return string
	 */
	private static function render_tiles( $title = '' ) {
		$tiles = array(
			array(
				'label' => __( 'Study Past Questions', 'classic-edu-suite' ),
				'hint'  => __( 'One question at a time, answer revealed as you go.', 'classic-edu-suite' ),
				'icon'  => '⏱️',
				'url'   => self::hub_url( array( 'view' => 'study' ) ),
				'tone'  => 'green',
			),
			array(
				'label' => __( 'Past Questions', 'classic-edu-suite' ),
				'hint'  => __( 'Sit a full timed paper, year by year.', 'classic-edu-suite' ),
				'icon'  => '📝',
				'url'   => self::hub_url( array( 'view' => 'past-questions' ) ),
				'tone'  => 'green',
			),
			array(
				'label' => __( 'Topic Video', 'classic-edu-suite' ),
				'hint'  => __( 'Watch a topic explained.', 'classic-edu-suite' ),
				'icon'  => '🎬',
				'url'   => self::hub_url( array( 'view' => 'videos' ) ),
				'tone'  => 'violet',
			),
			array(
				'label' => __( 'Leaderboard', 'classic-edu-suite' ),
				'hint'  => __( 'See where you stand.', 'classic-edu-suite' ),
				'icon'  => '🏆',
				'url'   => self::hub_url( array( 'view' => 'leaderboard' ) ),
				'tone'  => 'amber',
			),
		);

		// One tile per resource type that actually has something in it.
		foreach ( CES_Hub_Resources::seed_types() as $slug => $type ) {
			$count = CES_Hub_Resources::count_by_type( $slug );

			if ( 'past-question-paper' === $slug && $count < 1 ) {
				continue;
			}

			$tiles[] = array(
				'label' => $type['label'],
				'hint'  => $count
					? sprintf(
						/* translators: %s: item count. */
						_n( '%s item', '%s items', $count, 'classic-edu-suite' ),
						number_format_i18n( $count )
					)
					: $type['desc'],
				'icon'  => $type['icon'],
				'url'   => self::hub_url(
					array(
						'view' => 'library',
						'type' => $slug,
					)
				),
				'tone'  => 'blue',
				'empty' => ( $count < 1 ),
			);
		}

		if ( shortcode_exists( 'ces_guidance' ) ) {
			$tiles[] = array(
				'label' => __( 'Guidance', 'classic-edu-suite' ),
				'hint'  => __( 'Schools, departments and careers.', 'classic-edu-suite' ),
				'icon'  => '🧭',
				'url'   => self::hub_url( array( 'view' => 'guidance' ) ),
				'tone'  => 'blue',
			);
		}

		/**
		 * Filter the study hub tiles.
		 *
		 * @param array $tiles Tile definitions.
		 */
		$tiles = (array) apply_filters( 'ces_hub_tiles', $tiles );

		ob_start();

		echo '<div class="ces-hub">';

		if ( '' !== trim( (string) $title ) ) {
			echo '<h2 class="ces-hub__title">' . esc_html( $title ) . '</h2>';
		}

		echo '<div class="ces-hub__grid">';

		foreach ( $tiles as $tile ) {
			if ( empty( $tile['label'] ) || empty( $tile['url'] ) ) {
				continue;
			}

			$classes = 'ces-tile ces-tile--' . sanitize_html_class( isset( $tile['tone'] ) ? $tile['tone'] : 'blue' );

			if ( ! empty( $tile['empty'] ) ) {
				$classes .= ' ces-tile--empty';
			}

			echo '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $tile['url'] ) . '">';
			echo '<span class="ces-tile__label">' . esc_html( $tile['label'] ) . '</span>';
			echo '<span class="ces-tile__icon" aria-hidden="true">' . esc_html( isset( $tile['icon'] ) ? $tile['icon'] : '📘' ) . '</span>';

			if ( ! empty( $tile['hint'] ) ) {
				echo '<span class="ces-tile__hint">' . esc_html( $tile['hint'] ) . '</span>';
			}

			echo '</a>';
		}

		echo '</div></div>';

		return (string) ob_get_clean();
	}

	/**
	 * The video panel, which is the LMS course list when that module is here.
	 *
	 * @return string
	 */
	private static function render_videos() {
		if ( shortcode_exists( 'ces_course_list' ) ) {
			return do_shortcode( '[ces_course_list]' );
		}

		return '<p>' . esc_html__( 'Video lessons are not available yet.', 'classic-edu-suite' ) . '</p>';
	}

	/* ---------------------------------------------------------------------
	 * [ces_resource_library]
	 * ------------------------------------------------------------------ */

	/**
	 * The resource listing.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function library( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'type'      => '',
				'subject'   => '',
				'exam_type' => '',
				'year'      => '',
				'limit'     => 12,
				'title'     => '',
			),
			$atts,
			'ces_resource_library'
		);

		self::enqueue_style();

		// A single resource deep link works from the standalone shortcode too.
		$single = absint( self::arg( 'resource' ) );

		if ( $single ) {
			return self::render_resource( $single );
		}

		$type    = $atts['type'] ? sanitize_title( (string) $atts['type'] ) : sanitize_title( self::arg( 'type' ) );
		$subject = $atts['subject'] ? sanitize_title( (string) $atts['subject'] ) : sanitize_title( self::arg( 'subject' ) );
		$exam    = $atts['exam_type'] ? sanitize_title( (string) $atts['exam_type'] ) : sanitize_title( self::arg( 'exam_type' ) );
		$year    = $atts['year'] ? sanitize_title( (string) $atts['year'] ) : sanitize_title( self::arg( 'year' ) );
		$search  = self::arg( 's' );
		$paged   = max( 1, absint( self::arg( 'rpage', '1' ) ) );

		$result = CES_Hub_Resources::query(
			array(
				'type'      => $type,
				'subject'   => $subject,
				'exam_type' => $exam,
				'year'      => $year,
				'search'    => $search,
				'limit'     => (int) $atts['limit'],
				'paged'     => $paged,
			)
		);

		$heading = $atts['title'];

		if ( '' === trim( (string) $heading ) ) {
			$term    = $type ? get_term_by( 'slug', $type, CES_Hub_Resources::TAX_TYPE ) : null;
			$heading = ( $term && ! is_wp_error( $term ) ) ? $term->name : __( 'Library', 'classic-edu-suite' );
		}

		ob_start();

		echo '<div class="ces-hub-library">';
		echo '<h2 class="ces-hub__title">' . esc_html( $heading ) . '</h2>';

		self::render_notice();

		// Filters.
		echo '<form class="ces-hub-filters" method="get" action="' . esc_url( self::hub_url() ) . '">';

		if ( self::arg( 'view' ) ) {
			echo '<input type="hidden" name="view" value="library" />';
		}

		if ( $type ) {
			echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '" />';
		}

		self::term_select( 'subject', 'ces_subject', __( 'All subjects', 'classic-edu-suite' ), $subject );
		self::term_select( 'exam_type', 'ces_exam_type', __( 'All exams', 'classic-edu-suite' ), $exam );
		self::term_select( 'year', 'ces_year', __( 'All years', 'classic-edu-suite' ), $year );

		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search', 'classic-edu-suite' ) . '" />';
		echo '<button type="submit" class="ces-btn">' . esc_html__( 'Filter', 'classic-edu-suite' ) . '</button>';
		echo '</form>';

		if ( empty( $result['items'] ) ) {
			echo '<p class="ces-hub-empty">' . esc_html__( 'Nothing here yet. Check back soon.', 'classic-edu-suite' ) . '</p>';
		} else {
			echo '<ul class="ces-res-list">';

			foreach ( $result['items'] as $resource ) {
				self::render_resource_card( $resource );
			}

			echo '</ul>';

			self::render_pager( $paged, (int) $result['pages'] );
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * One card in the resource list.
	 *
	 * @param array $resource Resource array.
	 * @return void
	 */
	private static function render_resource_card( array $resource ) {
		$access = CES_Hub_Resources::can_access( $resource, get_current_user_id() );

		$meta = array_filter(
			array(
				$resource['subject'],
				$resource['exam_type'],
				$resource['year'],
				$resource['author'],
				$resource['pages'] ? sprintf(
					/* translators: %d: page count. */
					_n( '%d page', '%d pages', $resource['pages'], 'classic-edu-suite' ),
					$resource['pages']
				) : '',
			)
		);

		echo '<li class="ces-res">';
		echo '<a class="ces-res__link" href="' . esc_url( self::hub_url( array( 'view' => 'resource', 'resource' => $resource['id'] ) ) ) . '">';
		echo '<span class="ces-res__icon" aria-hidden="true">' . esc_html( $resource['icon'] ) . '</span>';
		echo '<span class="ces-res__body">';
		echo '<span class="ces-res__title">' . esc_html( $resource['title'] ) . '</span>';

		if ( ! empty( $meta ) ) {
			echo '<span class="ces-res__meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
		}

		if ( ! $access['allowed'] ) {
			echo '<span class="ces-res__lock">' . esc_html( $access['message'] ) . '</span>';
		}

		echo '</span></a></li>';
	}

	/**
	 * One resource, read or downloaded.
	 *
	 * @param int $resource_id Resource ID.
	 * @return string
	 */
	public static function render_resource( $resource_id ) {
		self::enqueue_style();

		$resource = CES_Hub_Resources::get( $resource_id );

		if ( ! $resource ) {
			return '<p class="ces-hub-empty">' . esc_html__( 'That item is no longer available.', 'classic-edu-suite' ) . '</p>';
		}

		$user_id = get_current_user_id();
		$access  = CES_Hub_Resources::can_access( $resource, $user_id );

		ob_start();

		echo '<article class="ces-res-single">';
		echo '<h2 class="ces-res-single__title">' . esc_html( $resource['title'] ) . '</h2>';

		$meta = array_filter( array( $resource['type_label'], $resource['subject'], $resource['exam_type'], $resource['year'], $resource['author'] ) );

		if ( ! empty( $meta ) ) {
			echo '<p class="ces-res-single__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
		}

		self::render_notice();

		if ( $resource['excerpt'] ) {
			echo '<p class="ces-res-single__excerpt">' . esc_html( $resource['excerpt'] ) . '</p>';
		}

		if ( ! $access['allowed'] ) {
			echo '<div class="ces-res-gate">';
			echo '<p>' . esc_html( $access['message'] ) . '</p>';

			if ( 'login' === $access['reason'] ) {
				echo '<a class="ces-btn ces-btn--primary" href="' . esc_url( CES_Helpers::login_url( self::hub_url( array( 'view' => 'resource', 'resource' => $resource['id'] ) ) ) ) . '">'
					. esc_html__( 'Sign in', 'classic-edu-suite' ) . '</a>';
			} elseif ( 'coins' === $access['reason'] ) {
				$balance = ( class_exists( 'CES_Wallet' ) && method_exists( 'CES_Wallet', 'balance' ) ) ? (int) CES_Wallet::balance( $user_id ) : 0;

				echo '<p class="ces-res-gate__balance">' . esc_html(
					sprintf(
						/* translators: %s: coin balance. */
						__( 'You have %s coins.', 'classic-edu-suite' ),
						CES_Helpers::format_coins( $balance )
					)
				) . '</p>';

				if ( $balance >= (int) $access['cost'] ) {
					echo '<a class="ces-btn ces-btn--primary" href="' . esc_url(
						CES_Hub_Resources::unlock_url(
							$resource['id'],
							self::hub_url( array( 'view' => 'resource', 'resource' => $resource['id'] ) )
						)
					) . '">' . esc_html(
						sprintf(
							/* translators: %d: coin cost. */
							__( 'Unlock for %d coins', 'classic-edu-suite' ),
							(int) $access['cost']
						)
					) . '</a>';
				} else {
					echo '<p>' . esc_html__( 'Earn more coins by inviting a friend or passing a quiz.', 'classic-edu-suite' ) . '</p>';
					echo '<a class="ces-btn" href="' . esc_url( CES_Helpers::dashboard_url( 'coins' ) ) . '">' . esc_html__( 'How to earn coins', 'classic-edu-suite' ) . '</a>';
				}
			} else {
				echo '<a class="ces-btn ces-btn--primary" href="' . esc_url( CES_Helpers::dashboard_url() ) . '">' . esc_html__( 'See membership options', 'classic-edu-suite' ) . '</a>';
			}

			echo '</div>';
			echo '</article>';

			return (string) ob_get_clean();
		}

		if ( $resource['has_file'] ) {
			echo '<p class="ces-res-single__actions">';
			echo '<a class="ces-btn ces-btn--primary" href="' . esc_url( CES_Hub_Resources::open_url( $resource['id'] ) ) . '" target="_blank" rel="noopener">';
			echo esc_html__( 'Open the file', 'classic-edu-suite' );
			echo '</a></p>';
		}

		if ( $resource['has_reading'] ) {
			echo '<div class="ces-res-single__body">';
			// The body is author-written content from the admin; run it through
			// the same filters a post body gets.
			echo wp_kses_post( apply_filters( 'the_content', $resource['body'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		}

		if ( ! $resource['has_file'] && ! $resource['has_reading'] ) {
			echo '<p class="ces-hub-empty">' . esc_html__( 'This item has no content attached yet.', 'classic-edu-suite' ) . '</p>';
		}

		echo '</article>';

		return (string) ob_get_clean();
	}

	/**
	 * Print the unlock result notice, when there is one.
	 *
	 * @return void
	 */
	private static function render_notice() {
		$notice = self::arg( 'ces_res' );

		if ( 'unlocked' === $notice ) {
			echo '<div class="ces-notice ces-notice--success"><p>' . esc_html__( 'Unlocked. Enjoy.', 'classic-edu-suite' ) . '</p></div>';
		} elseif ( 'failed' === $notice ) {
			echo '<div class="ces-notice ces-notice--error"><p>' . esc_html__( 'That could not be unlocked. Check your coin balance and try again.', 'classic-edu-suite' ) . '</p></div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * [ces_past_questions]
	 * ------------------------------------------------------------------ */

	/**
	 * Browse the bank by exam type, then subject, then year.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function past_questions( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title' => '',
			),
			$atts,
			'ces_past_questions'
		);

		self::enqueue_style();

		if ( ! class_exists( 'CES_Cbt_Question' ) ) {
			return '<p class="ces-hub-empty">' . esc_html__( 'The question bank is not available.', 'classic-edu-suite' ) . '</p>';
		}

		$exam_slug    = sanitize_title( self::arg( 'exam_type' ) );
		$subject_slug = sanitize_title( self::arg( 'subject' ) );
		$year_slug    = sanitize_title( self::arg( 'year' ) );

		$exam_term    = $exam_slug ? get_term_by( 'slug', $exam_slug, 'ces_exam_type' ) : null;
		$subject_term = $subject_slug ? get_term_by( 'slug', $subject_slug, 'ces_subject' ) : null;
		$year_term    = $year_slug ? get_term_by( 'slug', $year_slug, 'ces_year' ) : null;

		$title = (string) $atts['title'];

		if ( '' === trim( $title ) ) {
			$title = __( 'Past Questions', 'classic-edu-suite' );
		}

		ob_start();

		echo '<div class="ces-hub-pq">';
		echo '<h2 class="ces-hub__title">' . esc_html( $title ) . '</h2>';

		// Exam type chips.
		echo '<div class="ces-chiprow" role="group" aria-label="' . esc_attr__( 'Exam type', 'classic-edu-suite' ) . '">';
		self::chip( __( 'All', 'classic-edu-suite' ), self::hub_url( array( 'view' => self::arg( 'view' ) ) ), '' === $exam_slug );

		foreach ( self::terms( 'ces_exam_type' ) as $term ) {
			self::chip(
				$term->name,
				self::hub_url(
					array(
						'view'      => self::arg( 'view' ),
						'exam_type' => $term->slug,
					)
				),
				$exam_slug === $term->slug
			);
		}

		echo '</div>';

		if ( ! $subject_term ) {
			self::render_subject_grid( $exam_term );
			echo '</div>';

			return (string) ob_get_clean();
		}

		// Subject chosen: show the years, then the papers.
		echo '<h3 class="ces-hub-pq__subject">' . esc_html( $subject_term->name ) . '</h3>';

		echo '<div class="ces-chiprow" role="group" aria-label="' . esc_attr__( 'Year', 'classic-edu-suite' ) . '">';
		self::chip(
			__( 'All years', 'classic-edu-suite' ),
			self::hub_url(
				array(
					'view'      => self::arg( 'view' ),
					'exam_type' => $exam_slug,
					'subject'   => $subject_slug,
				)
			),
			'' === $year_slug
		);

		foreach ( self::terms( 'ces_year' ) as $term ) {
			self::chip(
				$term->name,
				self::hub_url(
					array(
						'view'      => self::arg( 'view' ),
						'exam_type' => $exam_slug,
						'subject'   => $subject_slug,
						'year'      => $term->slug,
					)
				),
				$year_slug === $term->slug
			);
		}

		echo '</div>';

		// How many questions match, and the two ways to use them.
		$filters = array();

		if ( $subject_term ) {
			$filters['ces_subject'] = array( (int) $subject_term->term_id );
		}

		if ( $exam_term ) {
			$filters['ces_exam_type'] = array( (int) $exam_term->term_id );
		}

		if ( $year_term ) {
			$filters['ces_year'] = array( (int) $year_term->term_id );
		}

		$available = self::question_count( $filters );

		echo '<p class="ces-hub-pq__count">' . esc_html(
			sprintf(
				/* translators: %s: number of questions. */
				_n( '%s question available', '%s questions available', $available, 'classic-edu-suite' ),
				number_format_i18n( $available )
			)
		) . '</p>';

		if ( $available > 0 ) {
			$study_url = self::hub_url(
				array(
					'view'      => 'study',
					'subject'   => $subject_slug,
					'exam_type' => $exam_slug,
					'year'      => $year_slug,
					'auto'      => '1',
				)
			);

			echo '<p class="ces-hub-pq__actions">';
			echo '<a class="ces-btn ces-btn--primary" href="' . esc_url( $study_url ) . '">' . esc_html__( 'Study these questions', 'classic-edu-suite' ) . '</a>';
			echo '</p>';
		}

		// Timed papers for this subject, if the admin built any.
		self::render_exam_list( $subject_term, $exam_term, $year_term );

		// Matching downloads.
		$downloads = CES_Hub_Resources::query(
			array(
				'type'      => 'past-question-paper',
				'subject'   => $subject_slug,
				'exam_type' => $exam_slug,
				'year'      => $year_slug,
				'limit'     => 10,
			)
		);

		if ( ! empty( $downloads['items'] ) ) {
			echo '<h4 class="ces-hub-pq__heading">' . esc_html__( 'Papers you can download', 'classic-edu-suite' ) . '</h4>';
			echo '<ul class="ces-res-list">';

			foreach ( $downloads['items'] as $resource ) {
				self::render_resource_card( $resource );
			}

			echo '</ul>';
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * The subject grid shown before a subject is chosen.
	 *
	 * @param WP_Term|null $exam_term Chosen exam type, if any.
	 * @return void
	 */
	private static function render_subject_grid( $exam_term ) {
		$subjects = self::terms( 'ces_subject' );

		if ( empty( $subjects ) ) {
			echo '<p class="ces-hub-empty">' . esc_html__( 'No subjects have been set up yet.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		echo '<div class="ces-subject-grid">';

		foreach ( $subjects as $term ) {
			$filters = array( 'ces_subject' => array( (int) $term->term_id ) );

			if ( $exam_term ) {
				$filters['ces_exam_type'] = array( (int) $exam_term->term_id );
			}

			$count = self::question_count( $filters );

			if ( $count < 1 ) {
				continue;
			}

			$url = self::hub_url(
				array(
					'view'      => self::arg( 'view' ),
					'exam_type' => $exam_term ? $exam_term->slug : '',
					'subject'   => $term->slug,
				)
			);

			echo '<a class="ces-subject" data-ces-art="' . esc_attr( CES_Helpers::subject_art( $term->name ) ) . '" href="' . esc_url( $url ) . '">';
			echo '<span class="ces-subject__name">' . esc_html( $term->name ) . '</span>';
			echo '<span class="ces-subject__count">' . esc_html(
				sprintf(
					/* translators: %s: question count. */
					_n( '%s question', '%s questions', $count, 'classic-edu-suite' ),
					number_format_i18n( $count )
				)
			) . '</span>';
			echo '</a>';
		}

		echo '</div>';
	}

	/**
	 * Timed papers matching the current filters.
	 *
	 * @param WP_Term|null $subject Subject term.
	 * @param WP_Term|null $exam    Exam type term.
	 * @param WP_Term|null $year    Year term.
	 * @return void
	 */
	private static function render_exam_list( $subject, $exam, $year ) {
		if ( ! class_exists( 'CES_Cbt_Cpt' ) ) {
			return;
		}

		$tax_query = array();

		foreach ( array(
			'ces_subject'   => $subject,
			'ces_exam_type' => $exam,
			'ces_year'      => $year,
		) as $taxonomy => $term ) {
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => array( (int) $term->term_id ),
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		$args = array(
			'post_type'      => CES_Cbt_Cpt::EXAM,
			'post_status'    => 'publish',
			'posts_per_page' => 12,
			'no_found_rows'  => true,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$exams = get_posts( $args );

		if ( empty( $exams ) ) {
			return;
		}

		echo '<h4 class="ces-hub-pq__heading">' . esc_html__( 'Timed papers', 'classic-edu-suite' ) . '</h4>';
		echo '<ul class="ces-res-list">';

		foreach ( $exams as $paper ) {
			echo '<li class="ces-res"><a class="ces-res__link" href="' . esc_url( get_permalink( $paper->ID ) ) . '">';
			echo '<span class="ces-res__icon" aria-hidden="true">⏳</span>';
			echo '<span class="ces-res__body"><span class="ces-res__title">' . esc_html( get_the_title( $paper->ID ) ) . '</span>';
			echo '<span class="ces-res__meta">' . esc_html__( 'Timed CBT paper', 'classic-edu-suite' ) . '</span>';
			echo '</span></a></li>';
		}

		echo '</ul>';
	}

	/* ---------------------------------------------------------------------
	 * [ces_study]
	 * ------------------------------------------------------------------ */

	/**
	 * The study runner.
	 *
	 * The markup is a shell; `hub-study.js` fills it from the REST routes, so
	 * no question or answer is ever present in the page source.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function study( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'count' => 0,
			),
			$atts,
			'ces_study'
		);

		self::enqueue_style();

		if ( ! is_user_logged_in() ) {
			return '<div class="ces-notice"><p>'
				. esc_html__( 'Sign in to study past questions.', 'classic-edu-suite' ) . ' '
				. '<a href="' . esc_url( CES_Helpers::login_url( self::hub_url( array( 'view' => 'study' ) ) ) ) . '">'
				. esc_html__( 'Sign in', 'classic-edu-suite' ) . '</a></p></div>';
		}

		if ( ! class_exists( 'CES_Cbt_Question' ) ) {
			return '<p class="ces-hub-empty">' . esc_html__( 'The question bank is not available.', 'classic-edu-suite' ) . '</p>';
		}

		wp_enqueue_script(
			'ces-hub-study',
			CES_URL . 'modules/hub/assets/hub-study.js',
			array( 'ces-base' ),
			CES_VERSION,
			true
		);

		$count = absint( $atts['count'] );

		if ( $count < 1 ) {
			$count = CES_Helpers::setting_int( 'hub_study_questions', 20 );
		}

		$subject = get_term_by( 'slug', sanitize_title( self::arg( 'subject' ) ), 'ces_subject' );
		$exam    = get_term_by( 'slug', sanitize_title( self::arg( 'exam_type' ) ), 'ces_exam_type' );
		$year    = get_term_by( 'slug', sanitize_title( self::arg( 'year' ) ), 'ces_year' );

		wp_localize_script(
			'ces-hub-study',
			'CES_STUDY',
			array(
				'preset' => array(
					'subject'   => $subject ? (int) $subject->term_id : 0,
					'exam_type' => $exam ? (int) $exam->term_id : 0,
					'year'      => $year ? (int) $year->term_id : 0,
					'count'     => $count,
					'auto'      => ( '1' === self::arg( 'auto' ) ),
				),
				'i18n'   => array(
					'loading'     => __( 'Loading…', 'classic-edu-suite' ),
					'correct'     => __( 'Correct', 'classic-edu-suite' ),
					'wrong'       => __( 'Not quite', 'classic-edu-suite' ),
					'answerIs'    => __( 'The answer is', 'classic-edu-suite' ),
					'next'        => __( 'Next question', 'classic-edu-suite' ),
					'finish'      => __( 'Finish', 'classic-edu-suite' ),
					'check'       => __( 'Check answer', 'classic-edu-suite' ),
					'pickOne'     => __( 'Choose an answer first.', 'classic-edu-suite' ),
					'offline'     => __( 'No connection. Your answer was not saved — try again.', 'classic-edu-suite' ),
					'summary'     => __( 'Session finished', 'classic-edu-suite' ),
					'scoreLine'   => __( 'You got %1$s of %2$s right (%3$s%%).', 'classic-edu-suite' ),
					'again'       => __( 'Study another set', 'classic-edu-suite' ),
					'questionOf'  => __( 'Question %1$s of %2$s', 'classic-edu-suite' ),
					'explanation' => __( 'Why', 'classic-edu-suite' ),
				),
			)
		);

		ob_start();

		echo '<div class="ces-study" id="ces-study">';

		// The setup panel. JS hides it once a session starts.
		echo '<div class="ces-study__setup" data-ces-study-setup>';
		echo '<h2 class="ces-hub__title">' . esc_html__( 'Study Past Questions', 'classic-edu-suite' ) . '</h2>';
		echo '<p class="ces-study__intro">' . esc_html__( 'Pick what you want to work on. There is no clock — the answer and the reason show as soon as you choose.', 'classic-edu-suite' ) . '</p>';

		echo '<div class="ces-study__filters">';
		self::term_select( 'study_subject', 'ces_subject', __( 'Any subject', 'classic-edu-suite' ), $subject ? $subject->slug : '' );
		self::term_select( 'study_exam_type', 'ces_exam_type', __( 'Any exam', 'classic-edu-suite' ), $exam ? $exam->slug : '' );
		self::term_select( 'study_year', 'ces_year', __( 'Any year', 'classic-edu-suite' ), $year ? $year->slug : '' );

		echo '<label class="ces-study__count"><span>' . esc_html__( 'Questions', 'classic-edu-suite' ) . '</span>';
		echo '<select id="study_count" name="study_count">';

		foreach ( array( 10, 20, 30, 40, 50 ) as $option ) {
			echo '<option value="' . esc_attr( $option ) . '" ' . selected( $count, $option, false ) . '>' . esc_html( number_format_i18n( $option ) ) . '</option>';
		}

		echo '</select></label>';
		echo '</div>';

		echo '<p><button type="button" class="ces-btn ces-btn--primary" data-ces-study-start>' . esc_html__( 'Start studying', 'classic-edu-suite' ) . '</button></p>';
		echo '<p class="ces-study__error" data-ces-study-error hidden></p>';
		echo '</div>';

		// The runner, filled by JS.
		echo '<div class="ces-study__runner" data-ces-study-runner hidden>';
		echo '<div class="ces-study__bar"><span data-ces-study-progress></span><span data-ces-study-score></span></div>';
		echo '<div class="ces-study__question" data-ces-study-question aria-live="polite"></div>';
		echo '<div class="ces-study__options" data-ces-study-options></div>';
		echo '<div class="ces-study__feedback" data-ces-study-feedback hidden></div>';
		echo '<div class="ces-study__actions">';
		echo '<button type="button" class="ces-btn ces-btn--primary" data-ces-study-check>' . esc_html__( 'Check answer', 'classic-edu-suite' ) . '</button>';
		echo '<button type="button" class="ces-btn" data-ces-study-next hidden>' . esc_html__( 'Next question', 'classic-edu-suite' ) . '</button>';
		echo '<button type="button" class="ces-btn ces-btn--ghost" data-ces-study-end>' . esc_html__( 'End session', 'classic-edu-suite' ) . '</button>';
		echo '</div>';
		echo '</div>';

		// The summary, filled by JS.
		echo '<div class="ces-study__summary" data-ces-study-summary hidden></div>';

		echo '</div>';

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * [ces_leaderboard]
	 * ------------------------------------------------------------------ */

	/**
	 * The ranked board.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function leaderboard( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'tab'    => '',
				'period' => '',
				'limit'  => 20,
				'title'  => '',
			),
			$atts,
			'ces_leaderboard'
		);

		self::enqueue_style();

		if ( ! CES_Helpers::enabled( 'cbt_leaderboard_enabled' ) ) {
			return '';
		}

		if ( CES_Helpers::enabled( 'hub_leaderboard_members_only' ) && ! is_user_logged_in() ) {
			return '<div class="ces-notice"><p>'
				. esc_html__( 'Sign in to see the leaderboard.', 'classic-edu-suite' ) . ' '
				. '<a href="' . esc_url( CES_Helpers::login_url() ) . '">' . esc_html__( 'Sign in', 'classic-edu-suite' ) . '</a></p></div>';
		}

		$tab    = $atts['tab'] ? sanitize_title( (string) $atts['tab'] ) : sanitize_title( self::arg( 'lb', 'all' ) );
		$period = $atts['period'] ? sanitize_key( (string) $atts['period'] ) : sanitize_key( self::arg( 'lbp', 'all' ) );

		$board = CES_Hub_Leaderboard::board(
			array(
				'tab'    => $tab,
				'period' => $period,
				'limit'  => (int) $atts['limit'],
			)
		);

		$title = (string) $atts['title'];

		if ( '' === trim( $title ) ) {
			$title = __( 'Leaderboard', 'classic-edu-suite' );
		}

		$me = get_current_user_id();

		ob_start();

		echo '<div class="ces-lb">';
		echo '<h2 class="ces-hub__title">' . esc_html( $title ) . '</h2>';

		// Tabs.
		echo '<div class="ces-chiprow" role="tablist">';

		foreach ( CES_Hub_Leaderboard::tabs() as $item ) {
			self::chip(
				$item['label'],
				self::hub_url(
					array(
						'view' => self::arg( 'view' ),
						'lb'   => $item['slug'],
						'lbp'  => ( 'all' === $board['period'] ) ? '' : $board['period'],
					)
				),
				$board['tab'] === $item['slug']
			);
		}

		echo '</div>';

		// Period.
		echo '<div class="ces-chiprow ces-chiprow--small">';

		foreach ( array(
			'all'   => __( 'All time', 'classic-edu-suite' ),
			'month' => __( 'This month', 'classic-edu-suite' ),
			'week'  => __( 'This week', 'classic-edu-suite' ),
		) as $slug => $label ) {
			self::chip(
				$label,
				self::hub_url(
					array(
						'view' => self::arg( 'view' ),
						'lb'   => ( 'all' === $board['tab'] ) ? '' : $board['tab'],
						'lbp'  => ( 'all' === $slug ) ? '' : $slug,
					)
				),
				$board['period'] === $slug
			);
		}

		echo '</div>';

		if ( empty( $board['rows'] ) ) {
			echo '<p class="ces-hub-empty">' . esc_html__( 'Nobody has sat a paper here yet. Be the first.', 'classic-edu-suite' ) . '</p>';
			echo '</div>';

			return (string) ob_get_clean();
		}

		echo '<ol class="ces-lb__list">';

		foreach ( $board['rows'] as $row ) {
			$classes = 'ces-lb__row';

			if ( $row['rank'] <= 3 ) {
				$classes .= ' ces-lb__row--medal ces-lb__row--rank' . (int) $row['rank'];
			}

			if ( $me && (int) $row['user_id'] === $me ) {
				$classes .= ' ces-lb__row--me';
			}

			echo '<li class="' . esc_attr( $classes ) . '">';

			echo '<span class="ces-lb__rank">' . esc_html( self::medal( (int) $row['rank'] ) ) . '</span>';

			echo '<span class="ces-lb__who">';
			echo '<span class="ces-lb__name">' . esc_html( $row['name'] ) . '</span>';
			echo '<span class="ces-lb__chips">';
			echo '<span class="ces-lb__chip">' . esc_html(
				sprintf(
					/* translators: %s: points. */
					__( '%s points', 'classic-edu-suite' ),
					number_format_i18n( $row['points'], 2 )
				)
			) . '</span>';
			echo '<span class="ces-lb__chip">' . esc_html(
				sprintf(
					/* translators: %s: best score. */
					__( 'Best %s%%', 'classic-edu-suite' ),
					number_format_i18n( $row['best'], 1 )
				)
			) . '</span>';
			echo '<span class="ces-lb__chip">' . esc_html(
				sprintf(
					/* translators: %s: time saved. */
					__( 'Time adv %s', 'classic-edu-suite' ),
					CES_Hub_Leaderboard::format_time_adv( $row['time_adv'] )
				)
			) . '</span>';

			if ( $row['subject'] ) {
				echo '<span class="ces-lb__chip">' . esc_html( $row['subject'] ) . '</span>';
			}

			echo '</span>';

			echo '<span class="ces-lb__from">' . esc_html(
				sprintf(
					/* translators: %s: number of papers sat. */
					_n( 'from %s paper', 'from %s papers', $row['sat'], 'classic-edu-suite' ),
					number_format_i18n( $row['sat'] )
				)
			) . '</span>';

			echo '</span>';

			echo '<span class="ces-lb__score">' . esc_html( number_format_i18n( $row['best'], 1 ) ) . '<small>%</small></span>';

			echo '<details class="ces-lb__history"><summary>' . esc_html__( 'View history', 'classic-edu-suite' ) . '</summary>';

			$history = CES_Hub_Leaderboard::user_history( (int) $row['user_id'], 5 );

			if ( empty( $history ) ) {
				echo '<p>' . esc_html__( 'Nothing to show.', 'classic-edu-suite' ) . '</p>';
			} else {
				echo '<ul>';

				foreach ( $history as $item ) {
					echo '<li>' . esc_html(
						sprintf(
							/* translators: 1: exam name, 2: score, 3: time saved. */
							__( '%1$s — %2$s%% (time adv %3$s)', 'classic-edu-suite' ),
							$item['subject'] ? $item['subject'] : $item['exam'],
							number_format_i18n( $item['score'], 1 ),
							CES_Hub_Leaderboard::format_time_adv( $item['time_adv'] )
						)
					) . '</li>';
				}

				echo '</ul>';
			}

			echo '</details>';

			echo '</li>';
		}

		echo '</ol>';

		// Where the viewer sits, when they are not on the visible board.
		if ( $me ) {
			$standing = CES_Hub_Leaderboard::standing( $me, $board['tab'], $board['period'] );

			if ( $standing && $standing['rank'] > count( $board['rows'] ) ) {
				echo '<p class="ces-lb__you">' . esc_html(
					sprintf(
						/* translators: 1: rank, 2: points. */
						__( 'You are #%1$s with %2$s points.', 'classic-edu-suite' ),
						number_format_i18n( $standing['rank'] ),
						number_format_i18n( $standing['points'], 2 )
					)
				) . '</p>';
			}
		}

		echo '<p class="ces-lb__note">' . esc_html__( 'Ranked on your best paper, with a bonus for time you did not need. Updated every few minutes.', 'classic-edu-suite' ) . '</p>';

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * A medal or a plain number for a rank.
	 *
	 * @param int $rank Position.
	 * @return string
	 */
	private static function medal( $rank ) {
		$medals = array(
			1 => '🥇',
			2 => '🥈',
			3 => '🥉',
		);

		return isset( $medals[ $rank ] ) ? $medals[ $rank ] : (string) $rank;
	}

	/* ---------------------------------------------------------------------
	 * Small shared pieces.
	 * ------------------------------------------------------------------ */

	/**
	 * How many published questions match a set of taxonomy terms.
	 *
	 * `CES_Cbt_Question::count()` loads every matching ID to count them,
	 * which is fine for one call and expensive when the subject grid asks
	 * sixteen times on one page load. This asks the database for the count
	 * instead, and caches it for ten minutes.
	 *
	 * @param array $tax_filters Map of taxonomy slug => term IDs.
	 * @return int
	 */
	private static function question_count( array $tax_filters ) {
		static $memo = array();

		// The bank version is bumped whenever a question is saved or deleted,
		// so an import shows up immediately instead of ten minutes later.
		$key = md5( wp_json_encode( $tax_filters ) . '|' . CES_Hub_Module::bank_version() );

		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$transient = 'ces_qcount_' . $key;
		$cached    = get_transient( $transient );

		if ( false !== $cached ) {
			$memo[ $key ] = (int) $cached;

			return $memo[ $key ];
		}

		$args = array(
			'post_type'              => CES_Cbt_Cpt::QUESTION,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$tax_query = array();

		foreach ( $tax_filters as $taxonomy => $term_ids ) {
			$term_ids = array_filter( array_map( 'absint', (array) $term_ids ) );

			if ( empty( $term_ids ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => $term_ids,
				'include_children' => ( CES_Cbt_Cpt::TAX_TOPIC === $taxonomy ),
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query = new WP_Query( $args );
		$count = (int) $query->found_posts;

		wp_reset_postdata();

		set_transient( $transient, $count, 10 * MINUTE_IN_SECONDS );

		$memo[ $key ] = $count;

		return $count;
	}

	/**
	 * Terms of a taxonomy, ordered for display.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return WP_Term[]
	 */
	private static function terms( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => ( 'ces_year' === $taxonomy ) ? 'name' : 'name',
				'order'      => ( 'ces_year' === $taxonomy ) ? 'DESC' : 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return $terms;
	}

	/**
	 * Print one chip link.
	 *
	 * @param string $label  Chip text.
	 * @param string $url    Destination.
	 * @param bool   $active Whether it is the current choice.
	 * @return void
	 */
	private static function chip( $label, $url, $active = false ) {
		echo '<a class="ces-chip' . ( $active ? ' ces-chip--on' : '' ) . '" href="' . esc_url( $url ) . '"';

		if ( $active ) {
			echo ' aria-current="true"';
		}

		echo '>' . esc_html( $label ) . '</a>';
	}

	/**
	 * Print a term dropdown.
	 *
	 * @param string $name     Field name / id.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $any      Label for the empty option.
	 * @param string $selected Selected term slug.
	 * @return void
	 */
	private static function term_select( $name, $taxonomy, $any, $selected = '' ) {
		$terms = self::terms( $taxonomy );

		if ( empty( $terms ) ) {
			return;
		}

		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" data-ces-tax="' . esc_attr( $taxonomy ) . '">';
		echo '<option value="">' . esc_html( $any ) . '</option>';

		foreach ( $terms as $term ) {
			echo '<option value="' . esc_attr( $term->slug ) . '" data-term-id="' . esc_attr( $term->term_id ) . '" ' . selected( $selected, $term->slug, false ) . '>';
			echo esc_html( $term->name );
			echo '</option>';
		}

		echo '</select>';
	}

	/**
	 * Print a previous / next pager.
	 *
	 * @param int $paged Current page.
	 * @param int $pages Total pages.
	 * @return void
	 */
	private static function render_pager( $paged, $pages ) {
		if ( $pages < 2 ) {
			return;
		}

		echo '<nav class="ces-pager">';

		if ( $paged > 1 ) {
			echo '<a class="ces-btn" href="' . esc_url( add_query_arg( 'rpage', $paged - 1 ) ) . '">&larr; ' . esc_html__( 'Previous', 'classic-edu-suite' ) . '</a>';
		}

		echo '<span class="ces-pager__count">' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: total pages. */
				__( 'Page %1$s of %2$s', 'classic-edu-suite' ),
				number_format_i18n( $paged ),
				number_format_i18n( $pages )
			)
		) . '</span>';

		if ( $paged < $pages ) {
			echo '<a class="ces-btn" href="' . esc_url( add_query_arg( 'rpage', $paged + 1 ) ) . '">' . esc_html__( 'Next', 'classic-edu-suite' ) . ' &rarr;</a>';
		}

		echo '</nav>';
	}
}
