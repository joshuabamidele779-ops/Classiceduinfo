<?php
/**
 * CBT admin layer.
 *
 * Owns the submenu registration, the settings fields, the overview cards and
 * the question-bank list table (columns and the subject / exam type / topic /
 * year filters). Metaboxes, import/export and analytics live in their own
 * classes, booted from here.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and hooks.
 */
class CES_Cbt_Admin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		CES_Cbt_Metaboxes::init();
		CES_Cbt_Importer::init();
		CES_Cbt_Reports::init();

		add_filter( 'ces_admin_submenus', array( __CLASS__, 'submenus' ) );
		add_filter( 'ces_settings_sections', array( __CLASS__, 'settings_sections' ) );
		add_filter( 'ces_overview_cards', array( __CLASS__, 'overview_cards' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		// Question bank list table.
		add_filter( 'manage_' . CES_Cbt_Cpt::QUESTION . '_posts_columns', array( __CLASS__, 'question_columns' ) );
		add_action( 'manage_' . CES_Cbt_Cpt::QUESTION . '_posts_custom_column', array( __CLASS__, 'question_column' ), 10, 2 );

		// Exam list table.
		add_filter( 'manage_' . CES_Cbt_Cpt::EXAM . '_posts_columns', array( __CLASS__, 'exam_columns' ) );
		add_action( 'manage_' . CES_Cbt_Cpt::EXAM . '_posts_custom_column', array( __CLASS__, 'exam_column' ), 10, 2 );

		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
		add_action( 'parse_query', array( __CLASS__, 'apply_filters_to_query' ) );
	}

	/**
	 * Short query-var name for a taxonomy filter dropdown.
	 *
	 * The taxonomies are registered with `query_var => false`, so the filters
	 * are applied by hand in `apply_filters_to_query()` rather than relying on
	 * core's taxonomy query vars.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	private static function filter_key( $taxonomy ) {
		return 'ces_f_' . str_replace( 'ces_', '', $taxonomy );
	}

	/**
	 * Add the CBT screens to the Classic EDU menu.
	 *
	 * @param array $submenus Existing submenus.
	 * @return array
	 */
	public static function submenus( $submenus ) {
		if ( ! is_array( $submenus ) ) {
			$submenus = array();
		}

		$cap = CES_Cbt_Cpt::CAP;

		$submenus[] = array(
			'slug'       => 'edit.php?post_type=' . CES_Cbt_Cpt::QUESTION,
			'title'      => __( 'Question Bank', 'classic-edu-suite' ),
			'menu_title' => __( 'Question Bank', 'classic-edu-suite' ),
			'capability' => $cap,
			'callback'   => '__return_null',
			'position'   => 20,
		);

		$submenus[] = array(
			'slug'       => 'edit.php?post_type=' . CES_Cbt_Cpt::EXAM,
			'title'      => __( 'Exams', 'classic-edu-suite' ),
			'menu_title' => __( 'Exams', 'classic-edu-suite' ),
			'capability' => $cap,
			'callback'   => '__return_null',
			'position'   => 21,
		);

		$submenus[] = array(
			'slug'       => CES_Cbt_Importer::PAGE,
			'title'      => __( 'CBT Import / Export', 'classic-edu-suite' ),
			'menu_title' => __( 'Import / Export', 'classic-edu-suite' ),
			'capability' => $cap,
			'callback'   => array( 'CES_Cbt_Importer', 'render_page' ),
			'position'   => 22,
		);

		$submenus[] = array(
			'slug'       => CES_Cbt_Reports::PAGE,
			'title'      => __( 'CBT Results', 'classic-edu-suite' ),
			'menu_title' => __( 'CBT Results', 'classic-edu-suite' ),
			'capability' => $cap,
			'callback'   => array( 'CES_Cbt_Reports', 'render_page' ),
			'position'   => 23,
		);

		return $submenus;
	}

	/**
	 * Register the `cbt_*` settings fields.
	 *
	 * Every key here already exists in `CES_Settings::defaults()`.
	 *
	 * @param array $sections Existing sections.
	 * @return array
	 */
	public static function settings_sections( $sections ) {
		if ( ! is_array( $sections ) ) {
			$sections = array();
		}

		$sections[] = array
		(
			'title'  => __( 'CBT Exam Engine', 'classic-edu-suite' ),
			'fields' => array(
				'cbt_default_duration'    => array(
					'label' => __( 'Default duration (minutes)', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Used when an exam does not set its own duration.', 'classic-edu-suite' ),
				),
				'cbt_default_questions'   => array(
					'label' => __( 'Default question count', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Used when an exam does not set its own question count.', 'classic-edu-suite' ),
				),
				'cbt_pass_mark'           => array(
					'label' => __( 'Default pass mark (%)', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'An exam can override this.', 'classic-edu-suite' ),
				),
				'cbt_shuffle_questions'   => array(
					'label' => __( 'Shuffle questions', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Each student gets the paper in a different order.', 'classic-edu-suite' ),
				),
				'cbt_shuffle_options'     => array(
					'label' => __( 'Shuffle options', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Option letters stay attached to their text, so marking is unaffected. True/False is never shuffled.', 'classic-edu-suite' ),
				),
				'cbt_show_answers_after'  => array(
					'label' => __( 'Show correct answers after submission', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Turn this off for live mock exams, on for practice.', 'classic-edu-suite' ),
				),
				'cbt_allow_retake'        => array(
					'label' => __( 'Allow retakes', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'When off, each student may sit each exam once.', 'classic-edu-suite' ),
				),
				'cbt_retake_cooldown'     => array(
					'label' => __( 'Retake cooldown (minutes)', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'How long a student must wait between attempts. 0 means no wait.', 'classic-edu-suite' ),
				),
				'cbt_leaderboard_enabled' => array(
					'label' => __( 'Enable the leaderboard', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Controls the [ces_cbt_leaderboard] shortcode and its REST route.', 'classic-edu-suite' ),
				),
				'cbt_lockdown_mode'       => array(
					'label' => __( 'Lockdown warnings', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Warn the student and log it when they leave the exam tab. A deterrent, not proctoring.', 'classic-edu-suite' ),
				),
			),
		);

		return $sections;
	}

	/**
	 * Contribute CBT stat cards to the overview screen.
	 *
	 * @param array $cards Existing cards.
	 * @return array
	 */
	public static function overview_cards( $cards ) {
		if ( ! is_array( $cards ) ) {
			$cards = array();
		}

		$questions = wp_count_posts( CES_Cbt_Cpt::QUESTION );
		$exams     = wp_count_posts( CES_Cbt_Cpt::EXAM );
		$stats     = CES_Cbt_Attempt::global_stats();

		$cards[] = array(
			'label' => __( 'Questions in the bank', 'classic-edu-suite' ),
			'value' => number_format_i18n( isset( $questions->publish ) ? (int) $questions->publish : 0 ),
			'href'  => admin_url( 'edit.php?post_type=' . CES_Cbt_Cpt::QUESTION ),
		);

		$cards[] = array(
			'label' => __( 'Published exams', 'classic-edu-suite' ),
			'value' => number_format_i18n( isset( $exams->publish ) ? (int) $exams->publish : 0 ),
			'href'  => admin_url( 'edit.php?post_type=' . CES_Cbt_Cpt::EXAM ),
		);

		$cards[] = array(
			'label' => __( 'Exams sat', 'classic-edu-suite' ),
			'value' => number_format_i18n( $stats['attempts'] ),
			'href'  => admin_url( 'admin.php?page=' . CES_Cbt_Reports::PAGE ),
		);

		$cards[] = array(
			'label' => __( 'Average score', 'classic-edu-suite' ),
			'value' => number_format_i18n( $stats['avg_score'], 1 ) . '%',
			'href'  => admin_url( 'admin.php?page=' . CES_Cbt_Reports::PAGE ),
		);

		return $cards;
	}

	/**
	 * Enqueue admin assets on CBT screens only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function assets( $hook ) {
		$screen    = get_current_screen();
		$post_type = $screen ? (string) $screen->post_type : '';

		$is_cbt_post = in_array( $post_type, array( CES_Cbt_Cpt::QUESTION, CES_Cbt_Cpt::EXAM ), true );
		$is_cbt_page = false !== strpos( (string) $hook, CES_Cbt_Importer::PAGE )
			|| false !== strpos( (string) $hook, CES_Cbt_Reports::PAGE );

		if ( ! $is_cbt_post && ! $is_cbt_page ) {
			return;
		}

		wp_enqueue_style(
			'ces-cbt-admin',
			CES_URL . 'modules/cbt/assets/cbt-admin.css',
			array(),
			CES_VERSION
		);

		if ( $is_cbt_post && $screen && 'post' === $screen->base ) {
			wp_enqueue_media();

			wp_enqueue_script(
				'ces-cbt-admin',
				CES_URL . 'modules/cbt/assets/cbt-admin.js',
				array(),
				CES_VERSION,
				true
			);

			wp_localize_script(
				'ces-cbt-admin',
				'cesCbtAdmin',
				array(
					'maxOptions' => CES_Cbt_Question::MAX_OPTIONS,
					'strings'    => array(
						'optionLabel' => __( 'Option', 'classic-edu-suite' ),
						'remove'      => __( 'Remove', 'classic-edu-suite' ),
						'chooseImage' => __( 'Choose image', 'classic-edu-suite' ),
						'useImage'    => __( 'Use this image', 'classic-edu-suite' ),
						'maxReached'  => __( 'That is the maximum number of options.', 'classic-edu-suite' ),
						'confirmType' => __( 'Switching to True / False replaces your options. Continue?', 'classic-edu-suite' ),
					),
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * List tables.
	 * ------------------------------------------------------------------ */

	/**
	 * Columns for the question bank.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function question_columns( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out['ces_type']       = __( 'Type', 'classic-edu-suite' );
				$out['ces_answer']     = __( 'Answer', 'classic-edu-suite' );
				$out['ces_difficulty'] = __( 'Difficulty', 'classic-edu-suite' );
				$out['ces_marks']      = __( 'Marks', 'classic-edu-suite' );
			}
		}

		unset( $out['date'] );
		$out['date'] = __( 'Date', 'classic-edu-suite' );

		return $out;
	}

	/**
	 * Render a question bank column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function question_column( $column, $post_id ) {
		if ( 0 !== strpos( $column, 'ces_' ) ) {
			return;
		}

		$question = CES_Cbt_Question::get( $post_id );

		if ( ! $question ) {
			echo '&mdash;';
			return;
		}

		switch ( $column ) {
			case 'ces_type':
				$types = CES_Cbt_Question::types();
				echo esc_html( isset( $types[ $question['type'] ] ) ? $types[ $question['type'] ] : $question['type'] );
				echo '<br /><span class="ces-muted">';
				echo esc_html(
					sprintf(
						/* translators: %d: number of options. */
						_n( '%d option', '%d options', count( $question['options'] ), 'classic-edu-suite' ),
						count( $question['options'] )
					)
				);
				echo '</span>';
				break;

			case 'ces_answer':
				if ( empty( $question['correct'] ) ) {
					echo '<span class="ces-flag-bad">' . esc_html__( 'Missing', 'classic-edu-suite' ) . '</span>';
				} else {
					echo '<code>' . esc_html( implode( ', ', $question['correct'] ) ) . '</code>';
				}
				break;

			case 'ces_difficulty':
				$levels = CES_Cbt_Question::difficulties();
				echo esc_html( isset( $levels[ $question['difficulty'] ] ) ? $levels[ $question['difficulty'] ] : $question['difficulty'] );
				break;

			case 'ces_marks':
				echo esc_html( number_format_i18n( $question['marks'], 2 ) );
				break;
		}
	}

	/**
	 * Columns for the exam list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function exam_columns( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out['ces_setup']    = __( 'Setup', 'classic-edu-suite' );
				$out['ces_pool']     = __( 'Pool', 'classic-edu-suite' );
				$out['ces_attempts'] = __( 'Attempts', 'classic-edu-suite' );
				$out['ces_short']    = __( 'Shortcode', 'classic-edu-suite' );
			}
		}

		return $out;
	}

	/**
	 * Render an exam list column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function exam_column( $column, $post_id ) {
		if ( 0 !== strpos( $column, 'ces_' ) ) {
			return;
		}

		$config = CES_Cbt_Exam::config( $post_id );

		if ( ! $config ) {
			echo '&mdash;';
			return;
		}

		switch ( $column ) {
			case 'ces_setup':
				echo esc_html(
					sprintf(
						/* translators: 1: question count, 2: duration in minutes, 3: pass mark. */
						__( '%1$d Q · %2$d min · %3$d%%', 'classic-edu-suite' ),
						(int) $config['question_count'],
						(int) $config['duration'],
						(int) $config['pass_mark']
					)
				);

				if ( ! $config['active'] ) {
					echo '<br /><span class="ces-flag-bad">' . esc_html__( 'Closed', 'classic-edu-suite' ) . '</span>';
				}
				break;

			case 'ces_pool':
				$available = CES_Cbt_Exam::available_count( $post_id );

				if ( $available < $config['question_count'] ) {
					echo '<span class="ces-flag-bad">' . esc_html( number_format_i18n( $available ) ) . '</span>';
					echo '<br /><span class="ces-muted">' . esc_html__( 'fewer than needed', 'classic-edu-suite' ) . '</span>';
				} else {
					echo esc_html( number_format_i18n( $available ) );
				}
				break;

			case 'ces_attempts':
				$stats = CES_Cbt_Attempt::stats_for_exam( $post_id );

				echo esc_html( number_format_i18n( $stats['attempts'] ) );
				echo '<br /><span class="ces-muted">' . esc_html( number_format_i18n( $stats['avg_score'], 1 ) ) . '% ' . esc_html__( 'avg', 'classic-edu-suite' ) . '</span>';
				break;

			case 'ces_short':
				echo '<code>[ces_exam id="' . absint( $post_id ) . '"]</code>';
				break;
		}
	}

	/**
	 * Output the filter dropdowns above the list table.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public static function filters( $post_type ) {
		if ( ! in_array( $post_type, array( CES_Cbt_Cpt::QUESTION, CES_Cbt_Cpt::EXAM ), true ) ) {
			return;
		}

		foreach ( CES_Cbt_Cpt::taxonomies() as $taxonomy => $label ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$key      = self::filter_key( $taxonomy );
			$selected = isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			wp_dropdown_categories(
				array(
					'show_option_all' => sprintf(
						/* translators: %s: taxonomy label, e.g. "Subject". */
						__( 'All %s', 'classic-edu-suite' ),
						$label
					),
					'taxonomy'        => $taxonomy,
					'name'            => $key,
					'id'              => $key,
					'value_field'     => 'term_id',
					'selected'        => $selected,
					'hierarchical'    => is_taxonomy_hierarchical( $taxonomy ),
					'hide_empty'      => false,
					'orderby'         => 'name',
					'show_count'      => false,
				)
			);
		}

		if ( CES_Cbt_Cpt::QUESTION !== $post_type ) {
			return;
		}

		$type = isset( $_GET['ces_f_type'] ) ? sanitize_key( wp_unslash( $_GET['ces_f_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<select name="ces_f_type" id="ces_f_type">';
		echo '<option value="">' . esc_html__( 'All types', 'classic-edu-suite' ) . '</option>';
		foreach ( CES_Cbt_Question::types() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $type, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		$difficulty = isset( $_GET['ces_f_difficulty'] ) ? sanitize_key( wp_unslash( $_GET['ces_f_difficulty'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<select name="ces_f_difficulty" id="ces_f_difficulty">';
		echo '<option value="">' . esc_html__( 'All difficulties', 'classic-edu-suite' ) . '</option>';
		foreach ( CES_Cbt_Question::difficulties() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $difficulty, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Translate the filter dropdowns into query clauses.
	 *
	 * The CBT taxonomies have no public query var, so the filtering is done
	 * here rather than by core.
	 *
	 * @param WP_Query $query Query being parsed.
	 * @return void
	 */
	public static function apply_filters_to_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		if ( ! in_array( $post_type, array( CES_Cbt_Cpt::QUESTION, CES_Cbt_Cpt::EXAM ), true ) ) {
			return;
		}

		$tax_query = (array) $query->get( 'tax_query' );

		foreach ( array_keys( CES_Cbt_Cpt::taxonomies() ) as $taxonomy ) {
			$key = self::filter_key( $taxonomy );

			$term_id = isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( ! $term_id ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => array( $term_id ),
				'include_children' => is_taxonomy_hierarchical( $taxonomy ),
			);
		}

		if ( ! empty( $tax_query ) ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}

			$query->set( 'tax_query', $tax_query );
		}

		if ( CES_Cbt_Cpt::QUESTION !== $post_type ) {
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' );

		$type = isset( $_GET['ces_f_type'] ) ? sanitize_key( wp_unslash( $_GET['ces_f_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $type && array_key_exists( $type, CES_Cbt_Question::types() ) ) {
			$meta_query[] = array(
				'key'   => CES_Cbt_Question::META_TYPE,
				'value' => $type,
			);
		}

		$difficulty = isset( $_GET['ces_f_difficulty'] ) ? sanitize_key( wp_unslash( $_GET['ces_f_difficulty'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $difficulty && array_key_exists( $difficulty, CES_Cbt_Question::difficulties() ) ) {
			$meta_query[] = array(
				'key'   => CES_Cbt_Question::META_DIFFICULTY,
				'value' => $difficulty,
			);
		}

		if ( ! empty( $meta_query ) ) {
			if ( count( $meta_query ) > 1 ) {
				$meta_query['relation'] = 'AND';
			}

			$query->set( 'meta_query', $meta_query );
		}
	}
}
