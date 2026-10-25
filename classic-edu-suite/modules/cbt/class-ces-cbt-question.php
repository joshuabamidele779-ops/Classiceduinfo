<?php
/**
 * Question model.
 *
 * Reads and writes the structured post meta behind a `ces_question`, grades a
 * given answer, and produces the browser-safe payload. The browser-safe
 * payload is the only shape that may ever leave the server before an attempt
 * is submitted: it has no `correct` key and no `explanation` key.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Question storage, validation and grading.
 */
class CES_Cbt_Question {

	/**
	 * Question type meta key.
	 */
	const META_TYPE = '_ces_q_type';

	/**
	 * Options array meta key.
	 */
	const META_OPTIONS = '_ces_q_options';

	/**
	 * Correct option keys meta key.
	 */
	const META_CORRECT = '_ces_q_correct';

	/**
	 * Explanation meta key.
	 */
	const META_EXPLANATION = '_ces_q_explanation';

	/**
	 * Difficulty meta key.
	 */
	const META_DIFFICULTY = '_ces_q_difficulty';

	/**
	 * Marks meta key.
	 */
	const META_MARKS = '_ces_q_marks';

	/**
	 * Question diagram attachment ID meta key.
	 */
	const META_IMAGE = '_ces_q_image';

	/**
	 * Single-answer multiple choice.
	 */
	const TYPE_SINGLE = 'single';

	/**
	 * True / false.
	 */
	const TYPE_TRUEFALSE = 'truefalse';

	/**
	 * Multiple correct answers.
	 */
	const TYPE_MULTI = 'multi';

	/**
	 * Maximum options a single question may carry.
	 */
	const MAX_OPTIONS = 10;

	/**
	 * Supported question types.
	 *
	 * @return array Map of slug => label.
	 */
	public static function types() {
		return array(
			self::TYPE_SINGLE    => __( 'Multiple choice (one answer)', 'classic-edu-suite' ),
			self::TYPE_TRUEFALSE => __( 'True / False', 'classic-edu-suite' ),
			self::TYPE_MULTI     => __( 'Multiple choice (several answers)', 'classic-edu-suite' ),
		);
	}

	/**
	 * Supported difficulty levels.
	 *
	 * @return array Map of slug => label.
	 */
	public static function difficulties() {
		return array(
			'easy'   => __( 'Easy', 'classic-edu-suite' ),
			'medium' => __( 'Medium', 'classic-edu-suite' ),
			'hard'   => __( 'Hard', 'classic-edu-suite' ),
		);
	}

	/**
	 * Option key for a zero-based position: 0 => A, 1 => B, and so on.
	 *
	 * @param int $index Zero-based option index.
	 * @return string
	 */
	public static function option_letter( $index ) {
		$index    = max( 0, (int) $index );
		$alphabet = range( 'A', 'Z' );

		return isset( $alphabet[ $index ] ) ? $alphabet[ $index ] : 'Z';
	}

	/**
	 * Coerce a raw type value into a supported type.
	 *
	 * @param string $type Raw type.
	 * @return string
	 */
	public static function sanitize_type( $type ) {
		$type = sanitize_key( (string) $type );

		return array_key_exists( $type, self::types() ) ? $type : self::TYPE_SINGLE;
	}

	/**
	 * Coerce a raw difficulty value into a supported level.
	 *
	 * @param string $difficulty Raw difficulty.
	 * @return string
	 */
	public static function sanitize_difficulty( $difficulty ) {
		$difficulty = sanitize_key( (string) $difficulty );

		return array_key_exists( $difficulty, self::difficulties() ) ? $difficulty : 'medium';
	}

	/**
	 * The two fixed options used by a true/false question.
	 *
	 * @return array
	 */
	public static function truefalse_options() {
		return array(
			array(
				'key'   => 'A',
				'text'  => __( 'True', 'classic-edu-suite' ),
				'image' => 0,
			),
			array(
				'key'   => 'B',
				'text'  => __( 'False', 'classic-edu-suite' ),
				'image' => 0,
			),
		);
	}

	/**
	 * Allowed HTML for a question body or an explanation.
	 *
	 * Starts from the post-content whitelist so images, tables, sub/sup and
	 * lists all survive, which matters for maths and diagram questions. A few
	 * MathML containers are added on top for people who paste MathML rather
	 * than using TeX delimiters.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$allowed = wp_kses_allowed_html( 'post' );

		$math_attrs = array(
			'class'       => true,
			'id'          => true,
			'style'       => true,
			'xmlns'       => true,
			'display'     => true,
			'mathvariant' => true,
		);

		foreach ( array( 'math', 'semantics', 'annotation', 'mrow', 'mi', 'mn', 'mo', 'ms', 'mtext', 'msup', 'msub', 'msubsup', 'mfrac', 'msqrt', 'mroot', 'munder', 'mover', 'munderover', 'mtable', 'mtr', 'mtd', 'mspace', 'mfenced' ) as $tag ) {
			$allowed[ $tag ] = $math_attrs;
		}

		/**
		 * Filter the HTML allowed inside question bodies and explanations.
		 *
		 * @param array $allowed Allowed tags and attributes.
		 */
		return apply_filters( 'ces_cbt_allowed_html', $allowed );
	}

	/**
	 * Sanitize rich text for storage.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function sanitize_rich_text( $html ) {
		return wp_kses( (string) $html, self::allowed_html() );
	}

	/**
	 * Clean an options array into canonical storage shape.
	 *
	 * Keys are always reassigned by position, so a reordered or part-deleted
	 * set can never end up with duplicate or missing keys.
	 *
	 * @param array $raw Raw options; each item may use `text`/`label` and `image`.
	 * @return array
	 */
	public static function sanitize_options( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( $raw as $item ) {
			if ( is_string( $item ) ) {
				$item = array( 'text' => $item );
			}

			if ( ! is_array( $item ) ) {
				continue;
			}

			$text = '';
			foreach ( array( 'text', 'label', 'value' ) as $field ) {
				if ( isset( $item[ $field ] ) && '' !== trim( (string) $item[ $field ] ) ) {
					$text = (string) $item[ $field ];
					break;
				}
			}

			$image = isset( $item['image'] ) ? absint( $item['image'] ) : 0;

			if ( '' === trim( wp_strip_all_tags( $text ) ) && ! $image ) {
				continue;
			}

			$clean[] = array(
				'key'   => self::option_letter( count( $clean ) ),
				'text'  => self::sanitize_rich_text( $text ),
				'image' => $image,
			);

			if ( count( $clean ) >= self::MAX_OPTIONS ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Normalize a raw answer into a list of option keys.
	 *
	 * Accepts an array, or a comma separated string such as "A,C". Unknown
	 * keys are dropped when `$valid_keys` is supplied.
	 *
	 * @param mixed $raw        Raw answer.
	 * @param array $valid_keys Option keys that exist on the question.
	 * @return array Sorted, unique list of keys.
	 */
	public static function normalize_answer( $raw, array $valid_keys = array() ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$keys = array();

		foreach ( $raw as $value ) {
			$value = strtoupper( trim( preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) ) );

			if ( '' === $value ) {
				continue;
			}

			// Tolerate "1"/"2" style answers from spreadsheets.
			if ( ctype_digit( $value ) ) {
				$value = self::option_letter( (int) $value - 1 );
			}

			if ( ! empty( $valid_keys ) && ! in_array( $value, $valid_keys, true ) ) {
				continue;
			}

			$keys[ $value ] = true;
		}

		$keys = array_keys( $keys );
		sort( $keys );

		return $keys;
	}

	/**
	 * Turn a list of keys into the `given_answer` column value.
	 *
	 * @param array $keys Option keys.
	 * @return string
	 */
	public static function answer_to_storage( array $keys ) {
		return substr( implode( ',', $keys ), 0, 255 );
	}

	/**
	 * Option keys present on a question record.
	 *
	 * @param array $question Question record from `get()`.
	 * @return array
	 */
	public static function option_keys( array $question ) {
		$keys = array();

		if ( ! empty( $question['options'] ) && is_array( $question['options'] ) ) {
			foreach ( $question['options'] as $option ) {
				if ( isset( $option['key'] ) ) {
					$keys[] = (string) $option['key'];
				}
			}
		}

		return $keys;
	}

	/**
	 * Load a full question record, correct answers included.
	 *
	 * Server-side only. Never hand the result of this method to a REST
	 * response; run it through `for_client()` first.
	 *
	 * @param int $post_id Question post ID.
	 * @return array|null Null when the post is missing or the wrong type.
	 */
	public static function get( $post_id ) {
		$post_id = absint( $post_id );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || CES_Cbt_Cpt::QUESTION !== $post->post_type ) {
			return null;
		}

		$type    = self::sanitize_type( get_post_meta( $post_id, self::META_TYPE, true ) );
		$options = get_post_meta( $post_id, self::META_OPTIONS, true );

		if ( self::TYPE_TRUEFALSE === $type ) {
			$options = self::truefalse_options();
		} else {
			$options = is_array( $options ) ? $options : array();
		}

		$correct = get_post_meta( $post_id, self::META_CORRECT, true );
		$correct = self::normalize_answer( $correct, wp_list_pluck( $options, 'key' ) );

		$marks = get_post_meta( $post_id, self::META_MARKS, true );
		$marks = ( '' === $marks || null === $marks ) ? 1.0 : (float) $marks;

		if ( $marks <= 0 ) {
			$marks = 1.0;
		}

		return array(
			'id'          => $post_id,
			'title'       => $post->post_title,
			'body'        => $post->post_content,
			'type'        => $type,
			'options'     => $options,
			'correct'     => $correct,
			'explanation' => (string) get_post_meta( $post_id, self::META_EXPLANATION, true ),
			'difficulty'  => self::sanitize_difficulty( get_post_meta( $post_id, self::META_DIFFICULTY, true ) ),
			'marks'       => $marks,
			'image'       => absint( get_post_meta( $post_id, self::META_IMAGE, true ) ),
			'status'      => $post->post_status,
		);
	}

	/**
	 * Marks carried by a question, without loading the whole record.
	 *
	 * @param int $post_id Question post ID.
	 * @return float
	 */
	public static function marks( $post_id ) {
		$marks = get_post_meta( absint( $post_id ), self::META_MARKS, true );
		$marks = ( '' === $marks || null === $marks ) ? 1.0 : (float) $marks;

		return $marks > 0 ? $marks : 1.0;
	}

	/**
	 * Persist question meta.
	 *
	 * Expects already-unslashed input. Everything is sanitized here, so both
	 * the metabox save and the CSV/JSON importer can share one code path.
	 *
	 * @param int   $post_id Question post ID.
	 * @param array $data    Raw field values.
	 * @return void
	 */
	public static function save_meta( $post_id, array $data ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return;
		}

		$type = self::sanitize_type( isset( $data['type'] ) ? $data['type'] : self::TYPE_SINGLE );

		if ( self::TYPE_TRUEFALSE === $type ) {
			$options = self::truefalse_options();
		} else {
			$options = self::sanitize_options( isset( $data['options'] ) ? $data['options'] : array() );
		}

		$correct = self::normalize_answer(
			isset( $data['correct'] ) ? $data['correct'] : array(),
			wp_list_pluck( $options, 'key' )
		);

		// A single-answer question can only ever hold one key.
		if ( self::TYPE_MULTI !== $type && count( $correct ) > 1 ) {
			$correct = array( reset( $correct ) );
		}

		$marks = isset( $data['marks'] ) ? (float) $data['marks'] : 1.0;
		if ( $marks <= 0 ) {
			$marks = 1.0;
		}

		update_post_meta( $post_id, self::META_TYPE, $type );
		update_post_meta( $post_id, self::META_OPTIONS, $options );
		update_post_meta( $post_id, self::META_CORRECT, $correct );
		update_post_meta( $post_id, self::META_EXPLANATION, self::sanitize_rich_text( isset( $data['explanation'] ) ? $data['explanation'] : '' ) );
		update_post_meta( $post_id, self::META_DIFFICULTY, self::sanitize_difficulty( isset( $data['difficulty'] ) ? $data['difficulty'] : 'medium' ) );
		update_post_meta( $post_id, self::META_MARKS, round( $marks, 2 ) );
		update_post_meta( $post_id, self::META_IMAGE, isset( $data['image'] ) ? absint( $data['image'] ) : 0 );
	}

	/**
	 * Validation errors for a prospective question.
	 *
	 * @param array $data Field values in the shape `save_meta()` accepts, plus
	 *                    `title` and `body`.
	 * @return array List of human readable error strings; empty when valid.
	 */
	public static function validate( array $data ) {
		$errors = array();

		$body  = isset( $data['body'] ) ? (string) $data['body'] : '';
		$title = isset( $data['title'] ) ? (string) $data['title'] : '';

		if ( '' === trim( wp_strip_all_tags( $body ) ) && '' === trim( $title ) ) {
			$errors[] = __( 'The question text is empty.', 'classic-edu-suite' );
		}

		$type = self::sanitize_type( isset( $data['type'] ) ? $data['type'] : self::TYPE_SINGLE );

		if ( self::TYPE_TRUEFALSE === $type ) {
			$options = self::truefalse_options();
		} else {
			$options = self::sanitize_options( isset( $data['options'] ) ? $data['options'] : array() );
		}

		if ( count( $options ) < 2 ) {
			$errors[] = __( 'At least two options are required.', 'classic-edu-suite' );
		}

		$keys    = wp_list_pluck( $options, 'key' );
		$correct = self::normalize_answer( isset( $data['correct'] ) ? $data['correct'] : array(), $keys );

		if ( empty( $correct ) ) {
			$errors[] = __( 'The correct answer is missing, or does not match any option.', 'classic-edu-suite' );
		}

		if ( self::TYPE_MULTI !== $type && count( $correct ) > 1 ) {
			$errors[] = __( 'This question type accepts exactly one correct answer.', 'classic-edu-suite' );
		}

		if ( self::TYPE_MULTI === $type && count( $correct ) === count( $keys ) && count( $keys ) > 0 ) {
			$errors[] = __( 'Every option is marked correct, which cannot be right.', 'classic-edu-suite' );
		}

		return $errors;
	}

	/**
	 * Build the browser-safe payload for one question.
	 *
	 * Deliberately omits `correct` and `explanation`. Option order follows
	 * `$option_order` when given, which is the per-attempt shuffle recorded
	 * at start time so a resumed paper looks identical.
	 *
	 * @param array $question     Question record from `get()`.
	 * @param array $option_order Option keys in display order.
	 * @return array
	 */
	public static function for_client( array $question, array $option_order = array() ) {
		$options = isset( $question['options'] ) && is_array( $question['options'] ) ? $question['options'] : array();

		if ( ! empty( $option_order ) ) {
			$by_key  = array();
			$ordered = array();

			foreach ( $options as $option ) {
				if ( isset( $option['key'] ) ) {
					$by_key[ (string) $option['key'] ] = $option;
				}
			}

			foreach ( $option_order as $key ) {
				$key = (string) $key;
				if ( isset( $by_key[ $key ] ) ) {
					$ordered[] = $by_key[ $key ];
					unset( $by_key[ $key ] );
				}
			}

			// Anything the stored order did not mention goes on the end, so an
			// option added after the attempt started is still answerable.
			foreach ( $by_key as $option ) {
				$ordered[] = $option;
			}

			$options = $ordered;
		}

		$out_options = array();

		foreach ( $options as $option ) {
			$image_id  = isset( $option['image'] ) ? absint( $option['image'] ) : 0;
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';

			$out_options[] = array(
				'key'   => isset( $option['key'] ) ? (string) $option['key'] : '',
				'text'  => self::sanitize_rich_text( isset( $option['text'] ) ? $option['text'] : '' ),
				'image' => $image_url ? esc_url_raw( $image_url ) : '',
			);
		}

		$image_id  = isset( $question['image'] ) ? absint( $question['image'] ) : 0;
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '';

		$payload = array(
			'id'       => isset( $question['id'] ) ? (int) $question['id'] : 0,
			'type'     => isset( $question['type'] ) ? (string) $question['type'] : self::TYPE_SINGLE,
			'marks'    => isset( $question['marks'] ) ? (float) $question['marks'] : 1.0,
			'body'     => self::render_body( isset( $question['body'] ) ? $question['body'] : '', isset( $question['title'] ) ? $question['title'] : '' ),
			'image'    => $image_url ? esc_url_raw( $image_url ) : '',
			'options'  => $out_options,
			'subject'  => '',
			'topic'    => '',
			'examType' => '',
			'year'     => '',
		);

		if ( ! empty( $question['id'] ) ) {
			$payload['subject']  = CES_Cbt_Cpt::first_term_name( $question['id'], CES_Cbt_Cpt::TAX_SUBJECT );
			$payload['topic']    = CES_Cbt_Cpt::first_term_name( $question['id'], CES_Cbt_Cpt::TAX_TOPIC );
			$payload['examType'] = CES_Cbt_Cpt::first_term_name( $question['id'], CES_Cbt_Cpt::TAX_EXAM_TYPE );
			$payload['year']     = CES_Cbt_Cpt::first_term_name( $question['id'], CES_Cbt_Cpt::TAX_YEAR );
		}

		/**
		 * Filter the browser-safe question payload.
		 *
		 * Anything added here is visible to the student mid-exam. Never add
		 * the correct answer.
		 *
		 * @param array $payload  Safe payload.
		 * @param array $question Full record, correct answers included.
		 */
		$payload = apply_filters( 'ces_cbt_question_payload', $payload, $question );

		// Belt and braces: strip the sensitive keys even if a filter added them.
		unset( $payload['correct'], $payload['explanation'] );

		return $payload;
	}

	/**
	 * Render a question body for display.
	 *
	 * @param string $body  Raw post content.
	 * @param string $title Post title, used when the body is empty.
	 * @return string
	 */
	public static function render_body( $body, $title = '' ) {
		$body = (string) $body;

		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			$body = (string) $title;
		}

		return self::sanitize_rich_text( wpautop( $body ) );
	}

	/**
	 * The review payload for one question, shown only after submission.
	 *
	 * @param array $question Question record from `get()`.
	 * @param array $given    Keys the student chose.
	 * @param bool  $reveal   Whether to include the correct answer and explanation.
	 * @return array
	 */
	public static function for_review( array $question, array $given, $reveal ) {
		$payload           = self::for_client( $question );
		$payload['given']  = array_values( $given );
		$payload['marks']  = isset( $question['marks'] ) ? (float) $question['marks'] : 1.0;
		$grade             = self::grade( $question, $given );
		$payload['isCorrect'] = (bool) $grade['is_correct'];
		$payload['earned']    = (float) $grade['marks'];
		$payload['answered']  = ! empty( $given );

		if ( $reveal ) {
			$payload['correct']     = array_values( isset( $question['correct'] ) ? $question['correct'] : array() );
			$payload['explanation'] = self::sanitize_rich_text( wpautop( isset( $question['explanation'] ) ? $question['explanation'] : '' ) );
		}

		return $payload;
	}

	/**
	 * Grade a given answer against the stored correct answer.
	 *
	 * Multi-answer questions are all-or-nothing by default: every correct key
	 * and no incorrect key. Filter `ces_cbt_grade` to change that.
	 *
	 * @param array $question Question record from `get()`.
	 * @param mixed $given    Raw or normalized answer.
	 * @return array `array( 'is_correct' => bool, 'marks' => float )`.
	 */
	public static function grade( array $question, $given ) {
		$keys    = self::option_keys( $question );
		$given   = self::normalize_answer( $given, $keys );
		$correct = self::normalize_answer( isset( $question['correct'] ) ? $question['correct'] : array(), $keys );
		$marks   = isset( $question['marks'] ) ? (float) $question['marks'] : 1.0;

		$is_correct = false;

		if ( ! empty( $correct ) && ! empty( $given ) ) {
			$is_correct = ( $given === $correct );
		}

		$result = array(
			'is_correct' => $is_correct,
			'marks'      => $is_correct ? $marks : 0.0,
		);

		/**
		 * Filter a grading result, for example to add partial credit on
		 * multi-answer questions.
		 *
		 * @param array $result   Grading result.
		 * @param array $question Question record.
		 * @param array $given    Normalized given keys.
		 * @param array $correct  Normalized correct keys.
		 */
		$result = apply_filters( 'ces_cbt_grade', $result, $question, $given, $correct );

		return array(
			'is_correct' => ! empty( $result['is_correct'] ),
			'marks'      => isset( $result['marks'] ) ? (float) $result['marks'] : 0.0,
		);
	}

	/**
	 * Count published questions, optionally filtered by taxonomy terms.
	 *
	 * @param array $tax_filters Map of taxonomy slug => term IDs.
	 * @param string $difficulty Optional difficulty slug.
	 * @return int
	 */
	public static function count( array $tax_filters = array(), $difficulty = '' ) {
		$ids = self::query_ids( $tax_filters, $difficulty, -1, false );

		return count( $ids );
	}

	/**
	 * Query published question IDs.
	 *
	 * @param array  $tax_filters Map of taxonomy slug => term IDs.
	 * @param string $difficulty  Optional difficulty slug.
	 * @param int    $limit       How many to return; -1 for all.
	 * @param bool   $random      Whether to randomise the order.
	 * @return int[]
	 */
	public static function query_ids( array $tax_filters = array(), $difficulty = '', $limit = -1, $random = true ) {
		$args = array(
			'post_type'              => CES_Cbt_Cpt::QUESTION,
			'post_status'            => 'publish',
			'posts_per_page'         => ( $limit > 0 ) ? (int) $limit : -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'orderby'                => $random ? 'rand' : 'ID',
			'order'                  => 'ASC',
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

		$difficulty = sanitize_key( (string) $difficulty );

		if ( $difficulty && array_key_exists( $difficulty, self::difficulties() ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::META_DIFFICULTY,
					'value' => $difficulty,
				),
			);
		}

		$query = new WP_Query( $args );

		return array_map( 'absint', (array) $query->posts );
	}
}
