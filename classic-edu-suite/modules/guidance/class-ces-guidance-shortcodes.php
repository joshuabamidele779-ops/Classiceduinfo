<?php
/**
 * The Guidance section: School Finder, Department Guidance, Career Guide.
 *
 * `[ces_guidance]` is the whole section on one page, exactly like the study
 * hub: no query string draws the three cards, `?g=` draws the chosen panel,
 * and `?item=` drills into one record. Each panel is also available as its
 * own shortcode.
 *
 * Everything is rendered server-side from GET parameters, so the section
 * works with JavaScript switched off, survives a shared link, and is indexed
 * sensibly if the page itself is public.
 *
 * @package ClassicEduSuite\Guidance
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end rendering for guidance content.
 */
class CES_Guidance_Shortcodes {

	/**
	 * Results per page.
	 */
	const PER_PAGE = 12;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'ces_guidance', array( __CLASS__, 'guidance' ) );
		add_shortcode( 'ces_school_finder', array( __CLASS__, 'school_finder' ) );
		add_shortcode( 'ces_department_guide', array( __CLASS__, 'department_guide' ) );
		add_shortcode( 'ces_career_guide', array( __CLASS__, 'career_guide' ) );

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

		return array_merge( $tags, array( 'ces_guidance', 'ces_school_finder', 'ces_department_guide', 'ces_career_guide' ) );
	}

	/**
	 * Enqueue the guidance stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue_style() {
		CES_Assets::enqueue_base();

		if ( ! wp_style_is( 'ces-guidance', 'registered' ) ) {
			wp_register_style(
				'ces-guidance',
				CES_URL . 'modules/guidance/assets/guidance.css',
				array( 'ces-base' ),
				CES_VERSION
			);
		}

		wp_enqueue_style( 'ces-guidance' );
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

	/**
	 * This page with guidance arguments applied.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		$base = get_permalink();

		if ( ! $base ) {
			$base = home_url( add_query_arg( array() ) );
		}

		// When guidance is being rendered inside the study hub, every link has
		// to keep `view=guidance` or the next click falls back to the tiles.
		$view = self::arg( 'view' );

		if ( '' !== $view && ! array_key_exists( 'view', $args ) ) {
			$args['view'] = $view;
		}

		$args = array_filter(
			$args,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		return empty( $args ) ? $base : add_query_arg( $args, $base );
	}

	/* ---------------------------------------------------------------------
	 * [ces_guidance]
	 * ------------------------------------------------------------------ */

	/**
	 * The guidance hub.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function guidance( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title' => '',
			),
			$atts,
			'ces_guidance'
		);

		self::enqueue_style();

		$panel = sanitize_key( self::arg( 'g' ) );

		if ( ! in_array( $panel, array( 'finder', 'departments', 'careers' ), true ) ) {
			return self::render_menu( (string) $atts['title'] );
		}

		$body = '';

		switch ( $panel ) {
			case 'finder':
				$body = self::school_finder( array() );
				break;

			case 'departments':
				$body = self::department_guide( array() );
				break;

			case 'careers':
				$body = self::career_guide( array() );
				break;
		}

		return '<div class="ces-guide">'
			. '<p class="ces-hub__back"><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'Back to Guidance', 'classic-edu-suite' ) . '</a></p>'
			. $body
			. '</div>';
	}

	/**
	 * The three-card menu.
	 *
	 * @param string $title Optional heading.
	 * @return string
	 */
	private static function render_menu( $title = '' ) {
		$cards = array(
			array(
				'label' => __( 'School Finder', 'classic-edu-suite' ),
				'hint'  => __( 'Find institutions that offer the course you want to study.', 'classic-edu-suite' ),
				'icon'  => '🎓',
				'url'   => self::url( array( 'g' => 'finder' ) ),
			),
			array(
				'label' => __( 'Department Guidance', 'classic-edu-suite' ),
				'hint'  => __( 'Review department summaries and entry requirements.', 'classic-edu-suite' ),
				'icon'  => '📖',
				'url'   => self::url( array( 'g' => 'departments' ) ),
			),
			array(
				'label' => __( 'Career Guide', 'classic-edu-suite' ),
				'hint'  => __( 'Explore career options and narrow down your direction.', 'classic-edu-suite' ),
				'icon'  => '💼',
				'url'   => self::url( array( 'g' => 'careers' ) ),
			),
		);

		ob_start();

		echo '<div class="ces-guide">';

		echo '<h2 class="ces-guide__title">' . esc_html( '' !== trim( (string) $title ) ? $title : __( 'Plan your next step with confidence', 'classic-edu-suite' ) ) . '</h2>';
		echo '<p class="ces-guide__intro">' . esc_html__( 'Explore institutions, understand a department, or discover career paths.', 'classic-edu-suite' ) . '</p>';

		echo '<div class="ces-guide__menu">';

		foreach ( $cards as $card ) {
			echo '<a class="ces-guide__card" href="' . esc_url( $card['url'] ) . '">';
			echo '<span class="ces-guide__card-icon" aria-hidden="true">' . esc_html( $card['icon'] ) . '</span>';
			echo '<span class="ces-guide__card-body">';
			echo '<span class="ces-guide__card-title">' . esc_html( $card['label'] ) . '</span>';
			echo '<span class="ces-guide__card-hint">' . esc_html( $card['hint'] ) . '</span>';
			echo '</span>';
			echo '<span class="ces-guide__card-arrow" aria-hidden="true">›</span>';
			echo '</a>';
		}

		echo '</div></div>';

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * School Finder
	 * ------------------------------------------------------------------ */

	/**
	 * `[ces_school_finder]`
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function school_finder( $atts = array() ) {
		self::enqueue_style();

		$item = absint( self::arg( 'item' ) );

		if ( $item ) {
			return self::render_institution( $item );
		}

		$programme = sanitize_title( self::arg( 'programme' ) );
		$type      = sanitize_title( self::arg( 'type' ) );
		$state     = sanitize_title( self::arg( 'state' ) );
		$ownership = sanitize_key( self::arg( 'ownership' ) );
		$search    = self::arg( 's' );
		$paged     = max( 1, absint( self::arg( 'gpage', '1' ) ) );

		$tax = array();

		if ( $programme ) {
			$tax[ CES_Guidance_Cpt::TAX_PROGRAMME ] = $programme;
		}

		if ( $type ) {
			$tax[ CES_Guidance_Cpt::TAX_INST_TYPE ] = $type;
		}

		if ( $state ) {
			$tax[ CES_Guidance_Cpt::TAX_STATE ] = $state;
		}

		$meta = array();

		if ( $ownership && array_key_exists( $ownership, CES_Guidance_Cpt::ownerships() ) ) {
			$meta[] = array(
				'key'   => CES_Guidance_Cpt::META_OWNERSHIP,
				'value' => $ownership,
			);
		}

		$result = self::query( CES_Guidance_Cpt::INSTITUTION, $tax, $meta, $search, $paged );

		ob_start();

		echo '<div class="ces-guide-panel ces-guide-panel--finder">';
		echo '<h2 class="ces-guide__title">' . esc_html__( 'School Finder', 'classic-edu-suite' ) . '</h2>';
		echo '<p class="ces-guide__intro">' . esc_html__( 'Choose the course you want to study and see where you can study it.', 'classic-edu-suite' ) . '</p>';

		// Filters.
		echo '<form class="ces-guide-filters" method="get" action="' . esc_url( self::url() ) . '">';

		if ( self::arg( 'g' ) ) {
			echo '<input type="hidden" name="g" value="finder" />';
		}

		self::term_select( 'programme', CES_Guidance_Cpt::TAX_PROGRAMME, __( 'Any course', 'classic-edu-suite' ), $programme );
		self::term_select( 'type', CES_Guidance_Cpt::TAX_INST_TYPE, __( 'Any institution type', 'classic-edu-suite' ), $type );
		self::term_select( 'state', CES_Guidance_Cpt::TAX_STATE, __( 'Any state', 'classic-edu-suite' ), $state );

		echo '<select name="ownership">';
		echo '<option value="">' . esc_html__( 'Any ownership', 'classic-edu-suite' ) . '</option>';

		foreach ( CES_Guidance_Cpt::ownerships() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $ownership, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';

		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'School name', 'classic-edu-suite' ) . '" />';
		echo '<button type="submit" class="ces-btn ces-btn--primary">' . esc_html__( 'Search', 'classic-edu-suite' ) . '</button>';
		echo '</form>';

		if ( empty( $result['posts'] ) ) {
			echo '<p class="ces-hub-empty">' . esc_html__( 'No institutions match that search yet.', 'classic-edu-suite' ) . '</p>';
		} else {
			echo '<p class="ces-guide__count">' . esc_html(
				sprintf(
					/* translators: %s: result count. */
					_n( '%s institution', '%s institutions', $result['total'], 'classic-edu-suite' ),
					number_format_i18n( $result['total'] )
				)
			) . '</p>';

			echo '<ul class="ces-guide-list">';

			foreach ( $result['posts'] as $post ) {
				$institution = CES_Guidance_Cpt::institution( $post->ID );

				if ( ! $institution ) {
					continue;
				}

				$meta_line = array_filter( array( $institution['type'], $institution['state'], $institution['ownership_label'] ) );

				echo '<li class="ces-guide-item">';
				echo '<a class="ces-guide-item__link" href="' . esc_url( self::url( array( 'g' => self::arg( 'g' ), 'item' => $institution['id'] ) ) ) . '">';
				echo '<span class="ces-guide-item__title">' . esc_html( $institution['title'] );

				if ( $institution['abbr'] ) {
					echo ' <small>(' . esc_html( $institution['abbr'] ) . ')</small>';
				}

				echo '</span>';

				if ( ! empty( $meta_line ) ) {
					echo '<span class="ces-guide-item__meta">' . esc_html( implode( ' · ', $meta_line ) ) . '</span>';
				}

				if ( $institution['cutoff'] ) {
					echo '<span class="ces-guide-item__meta">' . esc_html(
						sprintf(
							/* translators: %d: cut-off mark. */
							__( 'Cut-off: %d', 'classic-edu-suite' ),
							$institution['cutoff']
						)
					) . '</span>';
				}

				echo '</a></li>';
			}

			echo '</ul>';

			self::render_pager( $paged, $result['pages'] );
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * One institution.
	 *
	 * @param int $post_id Institution ID.
	 * @return string
	 */
	private static function render_institution( $post_id ) {
		$institution = CES_Guidance_Cpt::institution( $post_id );

		if ( ! $institution ) {
			return '<p class="ces-hub-empty">' . esc_html__( 'That institution is no longer listed.', 'classic-edu-suite' ) . '</p>';
		}

		ob_start();

		echo '<article class="ces-guide-single">';
		echo '<p class="ces-hub__back"><a href="' . esc_url( self::url( array( 'g' => self::arg( 'g', 'finder' ) ) ) ) . '">&larr; ' . esc_html__( 'Back to results', 'classic-edu-suite' ) . '</a></p>';

		echo '<h2 class="ces-guide__title">' . esc_html( $institution['title'] ) . '</h2>';

		$facts = array(
			__( 'Type', 'classic-edu-suite' )       => $institution['type'],
			__( 'State', 'classic-edu-suite' )      => $institution['state'],
			__( 'Ownership', 'classic-edu-suite' )  => $institution['ownership_label'],
			__( 'Short name', 'classic-edu-suite' ) => $institution['abbr'],
			__( 'Cut-off', 'classic-edu-suite' )    => $institution['cutoff'] ? (string) $institution['cutoff'] : '',
		);

		self::render_facts( $facts );

		if ( $institution['body'] ) {
			echo '<div class="ces-guide-single__body">';
			echo wp_kses_post( apply_filters( 'the_content', $institution['body'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		}

		if ( ! empty( $institution['programmes'] ) ) {
			echo '<h3 class="ces-guide-single__heading">' . esc_html__( 'Courses offered', 'classic-edu-suite' ) . '</h3>';
			echo '<div class="ces-chiprow ces-chiprow--wrap">';

			foreach ( $institution['programmes'] as $programme ) {
				echo '<a class="ces-chip" href="' . esc_url(
					self::url(
						array(
							'g'         => 'departments',
							'programme' => $programme['slug'],
						)
					)
				) . '">' . esc_html( $programme['name'] ) . '</a>';
			}

			echo '</div>';
		}

		if ( $institution['website'] ) {
			echo '<p class="ces-guide-single__actions"><a class="ces-btn" href="' . esc_url( $institution['website'] ) . '" target="_blank" rel="noopener nofollow">'
				. esc_html__( 'Visit the school website', 'classic-edu-suite' ) . '</a></p>';
		}

		echo '</article>';

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Department Guidance
	 * ------------------------------------------------------------------ */

	/**
	 * `[ces_department_guide]`
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function department_guide( $atts = array() ) {
		self::enqueue_style();

		$item = absint( self::arg( 'item' ) );

		if ( $item ) {
			return self::render_department( $item );
		}

		$programme = sanitize_title( self::arg( 'programme' ) );
		$search    = self::arg( 's' );
		$paged     = max( 1, absint( self::arg( 'gpage', '1' ) ) );

		$tax = array();

		if ( $programme ) {
			$tax[ CES_Guidance_Cpt::TAX_PROGRAMME ] = $programme;
		}

		$result = self::query( CES_Guidance_Cpt::DEPARTMENT, $tax, array(), $search, $paged );

		ob_start();

		echo '<div class="ces-guide-panel">';
		echo '<h2 class="ces-guide__title">' . esc_html__( 'Department Guidance', 'classic-edu-suite' ) . '</h2>';
		echo '<p class="ces-guide__intro">' . esc_html__( 'What a department actually studies, and what you need to get in.', 'classic-edu-suite' ) . '</p>';

		echo '<form class="ces-guide-filters" method="get" action="' . esc_url( self::url() ) . '">';

		if ( self::arg( 'g' ) ) {
			echo '<input type="hidden" name="g" value="departments" />';
		}

		self::term_select( 'programme', CES_Guidance_Cpt::TAX_PROGRAMME, __( 'Any course', 'classic-edu-suite' ), $programme );
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Department name', 'classic-edu-suite' ) . '" />';
		echo '<button type="submit" class="ces-btn ces-btn--primary">' . esc_html__( 'Search', 'classic-edu-suite' ) . '</button>';
		echo '</form>';

		if ( empty( $result['posts'] ) ) {
			echo '<p class="ces-hub-empty">' . esc_html__( 'No departments have been written up yet.', 'classic-edu-suite' ) . '</p>';
		} else {
			echo '<ul class="ces-guide-list">';

			foreach ( $result['posts'] as $post ) {
				$department = CES_Guidance_Cpt::department( $post->ID );

				if ( ! $department ) {
					continue;
				}

				echo '<li class="ces-guide-item">';
				echo '<a class="ces-guide-item__link" href="' . esc_url( self::url( array( 'g' => self::arg( 'g' ), 'item' => $department['id'] ) ) ) . '">';
				echo '<span class="ces-guide-item__title">' . esc_html( $department['title'] ) . '</span>';

				if ( $department['duration'] ) {
					echo '<span class="ces-guide-item__meta">' . esc_html(
						sprintf(
							/* translators: %s: course length. */
							__( '%s years', 'classic-edu-suite' ),
							$department['duration']
						)
					) . '</span>';
				}

				if ( $department['excerpt'] ) {
					echo '<span class="ces-guide-item__meta">' . esc_html( wp_html_excerpt( $department['excerpt'], 120, '…' ) ) . '</span>';
				}

				echo '</a></li>';
			}

			echo '</ul>';

			self::render_pager( $paged, $result['pages'] );
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * One department.
	 *
	 * @param int $post_id Department ID.
	 * @return string
	 */
	private static function render_department( $post_id ) {
		$department = CES_Guidance_Cpt::department( $post_id );

		if ( ! $department ) {
			return '<p class="ces-hub-empty">' . esc_html__( 'That department is no longer listed.', 'classic-edu-suite' ) . '</p>';
		}

		ob_start();

		echo '<article class="ces-guide-single">';
		echo '<p class="ces-hub__back"><a href="' . esc_url( self::url( array( 'g' => self::arg( 'g', 'departments' ) ) ) ) . '">&larr; ' . esc_html__( 'Back to departments', 'classic-edu-suite' ) . '</a></p>';
		echo '<h2 class="ces-guide__title">' . esc_html( $department['title'] ) . '</h2>';

		self::render_facts(
			array(
				__( 'Course length', 'classic-edu-suite' )     => $department['duration'] ? $department['duration'] . ' ' . __( 'years', 'classic-edu-suite' ) : '',
				__( 'UTME subjects', 'classic-edu-suite' )     => $department['utme'],
				__( 'O’level requirement', 'classic-edu-suite' ) => $department['olevel'],
				__( 'Direct entry', 'classic-edu-suite' )      => $department['direct_entry'],
			)
		);

		if ( $department['body'] ) {
			echo '<div class="ces-guide-single__body">';
			echo wp_kses_post( apply_filters( 'the_content', $department['body'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		}

		if ( ! empty( $department['careers'] ) ) {
			echo '<h3 class="ces-guide-single__heading">' . esc_html__( 'Where it can lead', 'classic-edu-suite' ) . '</h3>';
			echo '<ul class="ces-guide-bullets">';

			foreach ( $department['careers'] as $career ) {
				echo '<li>' . esc_html( $career ) . '</li>';
			}

			echo '</ul>';
		}

		// Institutions offering this programme.
		if ( ! empty( $department['programmes'] ) ) {
			$slugs = wp_list_pluck( $department['programmes'], 'slug' );

			$schools = self::query(
				CES_Guidance_Cpt::INSTITUTION,
				array( CES_Guidance_Cpt::TAX_PROGRAMME => $slugs ),
				array(),
				'',
				1,
				8
			);

			if ( ! empty( $schools['posts'] ) ) {
				echo '<h3 class="ces-guide-single__heading">' . esc_html__( 'Where you can study it', 'classic-edu-suite' ) . '</h3>';
				echo '<ul class="ces-guide-list">';

				foreach ( $schools['posts'] as $school ) {
					echo '<li class="ces-guide-item"><a class="ces-guide-item__link" href="' . esc_url( self::url( array( 'g' => 'finder', 'item' => $school->ID ) ) ) . '">';
					echo '<span class="ces-guide-item__title">' . esc_html( get_the_title( $school->ID ) ) . '</span>';
					echo '</a></li>';
				}

				echo '</ul>';

				echo '<p><a class="ces-btn" href="' . esc_url(
					self::url(
						array(
							'g'         => 'finder',
							'programme' => (string) reset( $slugs ),
						)
					)
				) . '">' . esc_html__( 'See every school offering this', 'classic-edu-suite' ) . '</a></p>';
			}
		}

		echo '</article>';

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Career Guide
	 * ------------------------------------------------------------------ */

	/**
	 * `[ces_career_guide]`
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function career_guide( $atts = array() ) {
		self::enqueue_style();

		$item = absint( self::arg( 'item' ) );

		if ( $item ) {
			return self::render_career( $item );
		}

		$programme = sanitize_title( self::arg( 'programme' ) );
		$search    = self::arg( 's' );
		$paged     = max( 1, absint( self::arg( 'gpage', '1' ) ) );

		$tax = array();

		if ( $programme ) {
			$tax[ CES_Guidance_Cpt::TAX_PROGRAMME ] = $programme;
		}

		$result = self::query( CES_Guidance_Cpt::CAREER, $tax, array(), $search, $paged );

		ob_start();

		echo '<div class="ces-guide-panel">';
		echo '<h2 class="ces-guide__title">' . esc_html__( 'Career Guide', 'classic-edu-suite' ) . '</h2>';
		echo '<p class="ces-guide__intro">' . esc_html__( 'What the work is really like, what it pays, and what to study for it.', 'classic-edu-suite' ) . '</p>';

		echo '<form class="ces-guide-filters" method="get" action="' . esc_url( self::url() ) . '">';

		if ( self::arg( 'g' ) ) {
			echo '<input type="hidden" name="g" value="careers" />';
		}

		self::term_select( 'programme', CES_Guidance_Cpt::TAX_PROGRAMME, __( 'Any course', 'classic-edu-suite' ), $programme );
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Career', 'classic-edu-suite' ) . '" />';
		echo '<button type="submit" class="ces-btn ces-btn--primary">' . esc_html__( 'Search', 'classic-edu-suite' ) . '</button>';
		echo '</form>';

		if ( empty( $result['posts'] ) ) {
			echo '<p class="ces-hub-empty">' . esc_html__( 'No careers have been added yet.', 'classic-edu-suite' ) . '</p>';
		} else {
			echo '<ul class="ces-guide-list">';

			foreach ( $result['posts'] as $post ) {
				$career = CES_Guidance_Cpt::career( $post->ID );

				if ( ! $career ) {
					continue;
				}

				echo '<li class="ces-guide-item">';
				echo '<a class="ces-guide-item__link" href="' . esc_url( self::url( array( 'g' => self::arg( 'g' ), 'item' => $career['id'] ) ) ) . '">';
				echo '<span class="ces-guide-item__title">' . esc_html( $career['title'] ) . '</span>';

				if ( $career['salary'] ) {
					echo '<span class="ces-guide-item__meta">' . esc_html( $career['salary'] ) . '</span>';
				}

				if ( $career['excerpt'] ) {
					echo '<span class="ces-guide-item__meta">' . esc_html( wp_html_excerpt( $career['excerpt'], 120, '…' ) ) . '</span>';
				}

				echo '</a></li>';
			}

			echo '</ul>';

			self::render_pager( $paged, $result['pages'] );
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * One career.
	 *
	 * @param int $post_id Career ID.
	 * @return string
	 */
	private static function render_career( $post_id ) {
		$career = CES_Guidance_Cpt::career( $post_id );

		if ( ! $career ) {
			return '<p class="ces-hub-empty">' . esc_html__( 'That career is no longer listed.', 'classic-edu-suite' ) . '</p>';
		}

		ob_start();

		echo '<article class="ces-guide-single">';
		echo '<p class="ces-hub__back"><a href="' . esc_url( self::url( array( 'g' => self::arg( 'g', 'careers' ) ) ) ) . '">&larr; ' . esc_html__( 'Back to careers', 'classic-edu-suite' ) . '</a></p>';
		echo '<h2 class="ces-guide__title">' . esc_html( $career['title'] ) . '</h2>';

		self::render_facts(
			array(
				__( 'Typical pay', 'classic-edu-suite' )  => $career['salary'],
				__( 'What to study', 'classic-edu-suite' ) => $career['study'],
			)
		);

		if ( $career['body'] ) {
			echo '<div class="ces-guide-single__body">';
			echo wp_kses_post( apply_filters( 'the_content', $career['body'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		}

		if ( ! empty( $career['skills'] ) ) {
			echo '<h3 class="ces-guide-single__heading">' . esc_html__( 'Skills that matter', 'classic-edu-suite' ) . '</h3>';
			echo '<ul class="ces-guide-bullets">';

			foreach ( $career['skills'] as $skill ) {
				echo '<li>' . esc_html( $skill ) . '</li>';
			}

			echo '</ul>';
		}

		if ( ! empty( $career['programmes'] ) ) {
			echo '<h3 class="ces-guide-single__heading">' . esc_html__( 'Courses that lead here', 'classic-edu-suite' ) . '</h3>';
			echo '<div class="ces-chiprow ces-chiprow--wrap">';

			foreach ( $career['programmes'] as $programme ) {
				echo '<a class="ces-chip" href="' . esc_url(
					self::url(
						array(
							'g'         => 'departments',
							'programme' => $programme['slug'],
						)
					)
				) . '">' . esc_html( $programme['name'] ) . '</a>';
			}

			echo '</div>';
		}

		echo '</article>';

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Shared pieces.
	 * ------------------------------------------------------------------ */

	/**
	 * Query one guidance post type.
	 *
	 * @param string $post_type  Post type.
	 * @param array  $tax        Map of taxonomy => term slug or slugs.
	 * @param array  $meta       Meta query clauses.
	 * @param string $search     Free text.
	 * @param int    $paged      1-based page.
	 * @param int    $per_page   Page size.
	 * @return array `array( 'posts' => WP_Post[], 'total' => int, 'pages' => int )`
	 */
	private static function query( $post_type, array $tax = array(), array $meta = array(), $search = '', $paged = 1, $per_page = self::PER_PAGE ) {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 60, (int) $per_page ) ),
			'paged'          => max( 1, (int) $paged ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( '' !== trim( (string) $search ) ) {
			$args['s']       = sanitize_text_field( (string) $search );
			$args['orderby'] = 'relevance';
		}

		$tax_query = array();

		foreach ( $tax as $taxonomy => $terms ) {
			$terms = array_filter( array_map( 'sanitize_title', (array) $terms ) );

			if ( empty( $terms ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $terms,
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		if ( ! empty( $meta ) ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new WP_Query( $args );

		wp_reset_postdata();

		return array(
			'posts' => (array) $query->posts,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Print a definition list of facts, skipping the empty ones.
	 *
	 * @param array $facts Map of label => value.
	 * @return void
	 */
	private static function render_facts( array $facts ) {
		$facts = array_filter(
			$facts,
			static function ( $value ) {
				return '' !== trim( (string) $value );
			}
		);

		if ( empty( $facts ) ) {
			return;
		}

		echo '<dl class="ces-guide-facts">';

		foreach ( $facts as $label => $value ) {
			echo '<div class="ces-guide-facts__row">';
			echo '<dt>' . esc_html( $label ) . '</dt>';
			echo '<dd>' . esc_html( $value ) . '</dd>';
			echo '</div>';
		}

		echo '</dl>';
	}

	/**
	 * Print a term dropdown.
	 *
	 * @param string $name     Field name.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $any      Empty option label.
	 * @param string $selected Selected slug.
	 * @return void
	 */
	private static function term_select( $name, $taxonomy, $any, $selected = '' ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 500,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		echo '<select name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html( $any ) . '</option>';

		foreach ( $terms as $term ) {
			echo '<option value="' . esc_attr( $term->slug ) . '" ' . selected( $selected, $term->slug, false ) . '>' . esc_html( $term->name ) . '</option>';
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
			echo '<a class="ces-btn" href="' . esc_url( add_query_arg( 'gpage', $paged - 1 ) ) . '">&larr; ' . esc_html__( 'Previous', 'classic-edu-suite' ) . '</a>';
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
			echo '<a class="ces-btn" href="' . esc_url( add_query_arg( 'gpage', $paged + 1 ) ) . '">' . esc_html__( 'Next', 'classic-edu-suite' ) . ' &rarr;</a>';
		}

		echo '</nav>';
	}
}
