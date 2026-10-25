<?php
/**
 * Exam paper model.
 *
 * A `ces_exam` is the configuration for a sitting: how long it runs, how many
 * questions it draws, what counts as a pass, and how the question set is
 * chosen. Everything falls back to the global `cbt_*` settings so an exam can
 * be created with nothing but a title.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exam configuration and question selection.
 */
class CES_Cbt_Exam {

	/**
	 * Duration in minutes meta key.
	 */
	const META_DURATION = '_ces_exam_duration';

	/**
	 * Question count meta key.
	 */
	const META_COUNT = '_ces_exam_question_count';

	/**
	 * Pass mark percentage meta key.
	 */
	const META_PASS_MARK = '_ces_exam_pass_mark';

	/**
	 * Instructions meta key.
	 */
	const META_INSTRUCTIONS = '_ces_exam_instructions';

	/**
	 * Selection mode meta key.
	 */
	const META_SELECTION = '_ces_exam_selection';

	/**
	 * Manual question ID list meta key.
	 */
	const META_QUESTION_IDS = '_ces_exam_question_ids';

	/**
	 * Taxonomy filter map meta key.
	 */
	const META_FILTERS = '_ces_exam_filters';

	/**
	 * Difficulty filter meta key.
	 */
	const META_DIFFICULTY = '_ces_exam_difficulty';

	/**
	 * Shuffle questions override meta key. -1 inherits the global setting.
	 */
	const META_SHUFFLE_QUESTIONS = '_ces_exam_shuffle_questions';

	/**
	 * Shuffle options override meta key. -1 inherits the global setting.
	 */
	const META_SHUFFLE_OPTIONS = '_ces_exam_shuffle_options';

	/**
	 * Maximum attempts per student meta key. 0 is unlimited.
	 */
	const META_MAX_ATTEMPTS = '_ces_exam_max_attempts';

	/**
	 * Whether the exam is open meta key.
	 */
	const META_ACTIVE = '_ces_exam_active';

	/**
	 * Draw questions from the bank at random, using the taxonomy filters.
	 */
	const SELECT_RANDOM = 'random';

	/**
	 * Use exactly the questions the admin picked, in the order given.
	 */
	const SELECT_MANUAL = 'manual';

	/**
	 * Reserved for AI-generated practice papers. Currently behaves as random.
	 */
	const SELECT_AI = 'ai';

	/**
	 * Selection modes.
	 *
	 * @return array Map of slug => label.
	 */
	public static function selection_modes() {
		return array(
			self::SELECT_RANDOM => __( 'Random from the question bank (filtered)', 'classic-edu-suite' ),
			self::SELECT_MANUAL => __( 'Hand-picked question list', 'classic-edu-suite' ),
			self::SELECT_AI     => __( 'AI-generated practice (falls back to random)', 'classic-edu-suite' ),
		);
	}

	/**
	 * Resolve the full configuration for an exam, settings defaults merged in.
	 *
	 * @param int $exam_id Exam post ID.
	 * @return array|null Null when the exam does not exist.
	 */
	public static function config( $exam_id ) {
		$exam_id = absint( $exam_id );
		$post    = $exam_id ? get_post( $exam_id ) : null;

		if ( ! $post || CES_Cbt_Cpt::EXAM !== $post->post_type ) {
			return null;
		}

		$duration = (int) get_post_meta( $exam_id, self::META_DURATION, true );
		if ( $duration <= 0 ) {
			$duration = max( 1, CES_Helpers::setting_int( 'cbt_default_duration', 30 ) );
		}

		$count = (int) get_post_meta( $exam_id, self::META_COUNT, true );
		if ( $count <= 0 ) {
			$count = max( 1, CES_Helpers::setting_int( 'cbt_default_questions', 20 ) );
		}

		$pass_mark = get_post_meta( $exam_id, self::META_PASS_MARK, true );
		if ( '' === $pass_mark || null === $pass_mark ) {
			$pass_mark = CES_Helpers::setting_int( 'cbt_pass_mark', 50 );
		}
		$pass_mark = min( 100, max( 0, (int) $pass_mark ) );

		$selection = sanitize_key( (string) get_post_meta( $exam_id, self::META_SELECTION, true ) );
		if ( ! array_key_exists( $selection, self::selection_modes() ) ) {
			$selection = self::SELECT_RANDOM;
		}

		$filters = get_post_meta( $exam_id, self::META_FILTERS, true );
		$filters = is_array( $filters ) ? $filters : array();

		$clean_filters = array();
		foreach ( array_keys( CES_Cbt_Cpt::taxonomies() ) as $taxonomy ) {
			$terms = isset( $filters[ $taxonomy ] ) ? array_filter( array_map( 'absint', (array) $filters[ $taxonomy ] ) ) : array();
			if ( ! empty( $terms ) ) {
				$clean_filters[ $taxonomy ] = array_values( $terms );
			}
		}

		// When no explicit filter is stored, inherit the exam's own terms so a
		// "JAMB Mathematics" exam post naturally draws JAMB Mathematics.
		if ( empty( $clean_filters ) ) {
			foreach ( array( CES_Cbt_Cpt::TAX_SUBJECT, CES_Cbt_Cpt::TAX_EXAM_TYPE, CES_Cbt_Cpt::TAX_TOPIC, CES_Cbt_Cpt::TAX_YEAR ) as $taxonomy ) {
				$terms = get_the_terms( $exam_id, $taxonomy );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					$clean_filters[ $taxonomy ] = array_map( 'absint', wp_list_pluck( $terms, 'term_id' ) );
				}
			}
		}

		$manual_ids = get_post_meta( $exam_id, self::META_QUESTION_IDS, true );
		$manual_ids = is_array( $manual_ids ) ? array_values( array_filter( array_map( 'absint', $manual_ids ) ) ) : array();

		$shuffle_questions = get_post_meta( $exam_id, self::META_SHUFFLE_QUESTIONS, true );
		$shuffle_questions = ( '' === $shuffle_questions || null === $shuffle_questions ) ? -1 : (int) $shuffle_questions;
		if ( $shuffle_questions < 0 ) {
			$shuffle_questions = CES_Helpers::enabled( 'cbt_shuffle_questions' ) ? 1 : 0;
		}

		$shuffle_options = get_post_meta( $exam_id, self::META_SHUFFLE_OPTIONS, true );
		$shuffle_options = ( '' === $shuffle_options || null === $shuffle_options ) ? -1 : (int) $shuffle_options;
		if ( $shuffle_options < 0 ) {
			$shuffle_options = CES_Helpers::enabled( 'cbt_shuffle_options' ) ? 1 : 0;
		}

		$active = get_post_meta( $exam_id, self::META_ACTIVE, true );
		$active = ( '' === $active || null === $active ) ? 1 : (int) $active;

		return array(
			'id'                => $exam_id,
			'title'             => $post->post_title,
			'status'            => $post->post_status,
			'description'       => $post->post_content,
			'instructions'      => (string) get_post_meta( $exam_id, self::META_INSTRUCTIONS, true ),
			'duration'          => $duration,
			'duration_seconds'  => $duration * MINUTE_IN_SECONDS,
			'question_count'    => $count,
			'pass_mark'         => $pass_mark,
			'selection'         => $selection,
			'manual_ids'        => $manual_ids,
			'filters'           => $clean_filters,
			'difficulty'        => sanitize_key( (string) get_post_meta( $exam_id, self::META_DIFFICULTY, true ) ),
			'shuffle_questions' => (bool) $shuffle_questions,
			'shuffle_options'   => (bool) $shuffle_options,
			'max_attempts'      => max( 0, (int) get_post_meta( $exam_id, self::META_MAX_ATTEMPTS, true ) ),
			'active'            => (bool) $active,
		);
	}

	/**
	 * How many bank questions this exam could draw from right now.
	 *
	 * @param int $exam_id Exam post ID.
	 * @return int
	 */
	public static function available_count( $exam_id ) {
		$config = self::config( $exam_id );

		if ( ! $config ) {
			return 0;
		}

		if ( self::SELECT_MANUAL === $config['selection'] ) {
			return count( self::published_only( $config['manual_ids'] ) );
		}

		return CES_Cbt_Question::count( $config['filters'], $config['difficulty'] );
	}

	/**
	 * Reduce a list of IDs to published questions, preserving order.
	 *
	 * @param array $ids Candidate question IDs.
	 * @return int[]
	 */
	public static function published_only( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$found = get_posts(
			array(
				'post_type'              => CES_Cbt_Cpt::QUESTION,
				'post_status'            => 'publish',
				'post__in'               => $ids,
				'posts_per_page'         => count( $ids ),
				'fields'                 => 'ids',
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		return array_map( 'absint', (array) $found );
	}

	/**
	 * Build the question set for a new attempt.
	 *
	 * @param int $exam_id Exam post ID.
	 * @return int[] Question IDs; empty when the exam cannot be sat.
	 */
	public static function select_question_ids( $exam_id ) {
		$config = self::config( $exam_id );

		if ( ! $config ) {
			return array();
		}

		$count = max( 1, (int) $config['question_count'] );

		if ( self::SELECT_MANUAL === $config['selection'] ) {
			$ids = self::published_only( $config['manual_ids'] );

			if ( $config['shuffle_questions'] ) {
				shuffle( $ids );
			}

			$ids = array_slice( $ids, 0, $count );
		} else {
			// Random and the reserved AI mode both pull from the bank. The AI
			// mode is a placeholder: see the README.
			$ids = CES_Cbt_Question::query_ids( $config['filters'], $config['difficulty'], $count, true );

			if ( ! $config['shuffle_questions'] ) {
				sort( $ids );
			}
		}

		/**
		 * Filter the question set chosen for an attempt.
		 *
		 * This is the extension point for AI-generated practice papers: a
		 * listener can insert its own freshly created question IDs here.
		 *
		 * @param int[] $ids     Chosen question IDs.
		 * @param array $config  Exam configuration.
		 * @param int   $exam_id Exam post ID.
		 */
		$ids = apply_filters( 'ces_cbt_select_questions', $ids, $config, $exam_id );

		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

	/**
	 * Persist exam meta from already-unslashed raw input.
	 *
	 * @param int   $post_id Exam post ID.
	 * @param array $data    Raw field values.
	 * @return void
	 */
	public static function save_meta( $post_id, array $data ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return;
		}

		$duration = isset( $data['duration'] ) ? absint( $data['duration'] ) : 0;
		$count    = isset( $data['question_count'] ) ? absint( $data['question_count'] ) : 0;

		$pass_mark = isset( $data['pass_mark'] ) ? (int) $data['pass_mark'] : CES_Helpers::setting_int( 'cbt_pass_mark', 50 );
		$pass_mark = min( 100, max( 0, $pass_mark ) );

		$selection = sanitize_key( isset( $data['selection'] ) ? $data['selection'] : self::SELECT_RANDOM );
		if ( ! array_key_exists( $selection, self::selection_modes() ) ) {
			$selection = self::SELECT_RANDOM;
		}

		$manual_ids = isset( $data['question_ids'] ) ? $data['question_ids'] : array();
		if ( is_string( $manual_ids ) ) {
			$manual_ids = preg_split( '/[\s,]+/', $manual_ids );
		}
		$manual_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $manual_ids ) ) ) );

		$filters = array();
		$raw     = isset( $data['filters'] ) && is_array( $data['filters'] ) ? $data['filters'] : array();
		foreach ( array_keys( CES_Cbt_Cpt::taxonomies() ) as $taxonomy ) {
			$terms = isset( $raw[ $taxonomy ] ) ? array_filter( array_map( 'absint', (array) $raw[ $taxonomy ] ) ) : array();
			if ( ! empty( $terms ) ) {
				$filters[ $taxonomy ] = array_values( $terms );
			}
		}

		$difficulty = sanitize_key( isset( $data['difficulty'] ) ? $data['difficulty'] : '' );
		if ( $difficulty && ! array_key_exists( $difficulty, CES_Cbt_Question::difficulties() ) ) {
			$difficulty = '';
		}

		update_post_meta( $post_id, self::META_DURATION, $duration );
		update_post_meta( $post_id, self::META_COUNT, $count );
		update_post_meta( $post_id, self::META_PASS_MARK, $pass_mark );
		update_post_meta( $post_id, self::META_INSTRUCTIONS, CES_Cbt_Question::sanitize_rich_text( isset( $data['instructions'] ) ? $data['instructions'] : '' ) );
		update_post_meta( $post_id, self::META_SELECTION, $selection );
		update_post_meta( $post_id, self::META_QUESTION_IDS, $manual_ids );
		update_post_meta( $post_id, self::META_FILTERS, $filters );
		update_post_meta( $post_id, self::META_DIFFICULTY, $difficulty );
		update_post_meta( $post_id, self::META_SHUFFLE_QUESTIONS, self::tri_state( isset( $data['shuffle_questions'] ) ? $data['shuffle_questions'] : -1 ) );
		update_post_meta( $post_id, self::META_SHUFFLE_OPTIONS, self::tri_state( isset( $data['shuffle_options'] ) ? $data['shuffle_options'] : -1 ) );
		update_post_meta( $post_id, self::META_MAX_ATTEMPTS, isset( $data['max_attempts'] ) ? absint( $data['max_attempts'] ) : 0 );
		update_post_meta( $post_id, self::META_ACTIVE, empty( $data['active'] ) ? 0 : 1 );
	}

	/**
	 * Coerce an inherit / off / on value to -1, 0 or 1.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function tri_state( $value ) {
		$value = (int) $value;

		if ( $value < 0 ) {
			return -1;
		}

		return $value > 0 ? 1 : 0;
	}

	/**
	 * List exams for the front-end picker.
	 *
	 * @param array $args Optional overrides: `subject`, `exam_type`, `limit`.
	 * @return WP_Post[]
	 */
	public static function list_exams( array $args = array() ) {
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;

		$query_args = array(
			'post_type'      => CES_Cbt_Cpt::EXAM,
			'post_status'    => 'publish',
			'posts_per_page' => $limit > 0 ? $limit : 50,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$tax_query = array();

		$map = array(
			'subject'   => CES_Cbt_Cpt::TAX_SUBJECT,
			'exam_type' => CES_Cbt_Cpt::TAX_EXAM_TYPE,
			'topic'     => CES_Cbt_Cpt::TAX_TOPIC,
			'year'      => CES_Cbt_Cpt::TAX_YEAR,
		);

		foreach ( $map as $arg => $taxonomy ) {
			if ( empty( $args[ $arg ] ) ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => is_numeric( $args[ $arg ] ) ? 'term_id' : 'slug',
				'terms'    => is_numeric( $args[ $arg ] ) ? absint( $args[ $arg ] ) : sanitize_title( $args[ $arg ] ),
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query = new WP_Query( $query_args );

		return $query->posts;
	}

	/**
	 * Summary of an exam for a card or a REST list response.
	 *
	 * @param int $exam_id Exam post ID.
	 * @return array|null
	 */
	public static function summary( $exam_id ) {
		$config = self::config( $exam_id );

		if ( ! $config ) {
			return null;
		}

		$available = self::available_count( $exam_id );

		return array(
			'id'            => $config['id'],
			'title'         => $config['title'],
			'subject'       => CES_Cbt_Cpt::term_names( $config['id'], CES_Cbt_Cpt::TAX_SUBJECT ),
			'examType'      => CES_Cbt_Cpt::term_names( $config['id'], CES_Cbt_Cpt::TAX_EXAM_TYPE ),
			'year'          => CES_Cbt_Cpt::term_names( $config['id'], CES_Cbt_Cpt::TAX_YEAR ),
			'duration'      => $config['duration'],
			'questionCount' => min( $config['question_count'], $available ),
			'passMark'      => $config['pass_mark'],
			'active'        => $config['active'] && $available > 0,
			'available'     => $available,
		);
	}
}
