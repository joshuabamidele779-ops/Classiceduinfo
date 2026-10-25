<?php
/**
 * Metaboxes for questions and exams.
 *
 * The question editor's own rich text field is the question body, so maths,
 * tables and inline images all work through the normal editor. Everything
 * else — options, correct answers, explanation, difficulty, marks, diagram —
 * lives in these boxes.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Question and exam metaboxes.
 */
class CES_Cbt_Metaboxes {

	/**
	 * Nonce action for the question boxes.
	 */
	const QUESTION_NONCE = 'ces_cbt_save_question';

	/**
	 * Nonce action for the exam boxes.
	 */
	const EXAM_NONCE = 'ces_cbt_save_exam';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . CES_Cbt_Cpt::QUESTION, array( __CLASS__, 'save_question' ), 10, 2 );
		add_action( 'save_post_' . CES_Cbt_Cpt::EXAM, array( __CLASS__, 'save_exam' ), 10, 2 );
	}

	/**
	 * Add the metaboxes.
	 *
	 * @return void
	 */
	public static function register() {
		add_meta_box(
			'ces-cbt-question-options',
			__( 'Options & correct answer', 'classic-edu-suite' ),
			array( __CLASS__, 'render_question_options' ),
			CES_Cbt_Cpt::QUESTION,
			'normal',
			'high'
		);

		add_meta_box(
			'ces-cbt-question-explanation',
			__( 'Explanation (shown after submission)', 'classic-edu-suite' ),
			array( __CLASS__, 'render_question_explanation' ),
			CES_Cbt_Cpt::QUESTION,
			'normal',
			'default'
		);

		add_meta_box(
			'ces-cbt-question-setup',
			__( 'Question setup', 'classic-edu-suite' ),
			array( __CLASS__, 'render_question_setup' ),
			CES_Cbt_Cpt::QUESTION,
			'side',
			'high'
		);

		add_meta_box(
			'ces-cbt-exam-setup',
			__( 'Exam setup', 'classic-edu-suite' ),
			array( __CLASS__, 'render_exam_setup' ),
			CES_Cbt_Cpt::EXAM,
			'normal',
			'high'
		);

		add_meta_box(
			'ces-cbt-exam-selection',
			__( 'Question selection', 'classic-edu-suite' ),
			array( __CLASS__, 'render_exam_selection' ),
			CES_Cbt_Cpt::EXAM,
			'normal',
			'default'
		);

		add_meta_box(
			'ces-cbt-exam-instructions',
			__( 'Instructions shown before the exam', 'classic-edu-suite' ),
			array( __CLASS__, 'render_exam_instructions' ),
			CES_Cbt_Cpt::EXAM,
			'normal',
			'default'
		);

		add_meta_box(
			'ces-cbt-exam-publish',
			__( 'How to use this exam', 'classic-edu-suite' ),
			array( __CLASS__, 'render_exam_howto' ),
			CES_Cbt_Cpt::EXAM,
			'side',
			'default'
		);
	}

	/* ---------------------------------------------------------------------
	 * Question boxes.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the question setup sidebar box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_question_setup( $post ) {
		wp_nonce_field( self::QUESTION_NONCE, '_ces_cbt_question_nonce' );

		$question   = CES_Cbt_Question::get( $post->ID );
		$type       = $question ? $question['type'] : CES_Cbt_Question::TYPE_SINGLE;
		$difficulty = $question ? $question['difficulty'] : 'medium';
		$marks      = $question ? $question['marks'] : 1;
		$image      = $question ? (int) $question['image'] : 0;
		?>
		<p>
			<label class="ces-label" for="ces-q-type"><?php esc_html_e( 'Question type', 'classic-edu-suite' ); ?></label>
			<select id="ces-q-type" name="ces_q[type]" class="widefat" data-ces-q-type>
				<?php foreach ( CES_Cbt_Question::types() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label class="ces-label" for="ces-q-difficulty"><?php esc_html_e( 'Difficulty', 'classic-edu-suite' ); ?></label>
			<select id="ces-q-difficulty" name="ces_q[difficulty]" class="widefat">
				<?php foreach ( CES_Cbt_Question::difficulties() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $difficulty, $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label class="ces-label" for="ces-q-marks"><?php esc_html_e( 'Marks', 'classic-edu-suite' ); ?></label>
			<input type="number" step="0.25" min="0.25" id="ces-q-marks" name="ces_q[marks]" class="small-text" value="<?php echo esc_attr( $marks ); ?>" />
			<span class="description"><?php esc_html_e( 'Scores are reported as a percentage of the paper total.', 'classic-edu-suite' ); ?></span>
		</p>

		<div class="ces-image-field" data-ces-image-field>
			<label class="ces-label"><?php esc_html_e( 'Diagram / image', 'classic-edu-suite' ); ?></label>
			<input type="hidden" name="ces_q[image]" value="<?php echo esc_attr( $image ); ?>" data-ces-image-input />
			<div class="ces-image-field__preview" data-ces-image-preview>
				<?php if ( $image ) : ?>
					<?php echo wp_get_attachment_image( $image, 'medium' ); ?>
				<?php endif; ?>
			</div>
			<button type="button" class="button" data-ces-image-pick><?php esc_html_e( 'Choose image', 'classic-edu-suite' ); ?></button>
			<button type="button" class="button-link ces-danger" data-ces-image-clear><?php esc_html_e( 'Remove', 'classic-edu-suite' ); ?></button>
			<p class="description"><?php esc_html_e( 'Shown above the options. Diagrams can also be inserted directly into the question body.', 'classic-edu-suite' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the options repeater.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_question_options( $post ) {
		$question = CES_Cbt_Question::get( $post->ID );
		$type     = $question ? $question['type'] : CES_Cbt_Question::TYPE_SINGLE;
		$options  = ( $question && ! empty( $question['options'] ) ) ? $question['options'] : array();
		$correct  = $question ? $question['correct'] : array();

		if ( CES_Cbt_Question::TYPE_TRUEFALSE === $type ) {
			$options = CES_Cbt_Question::truefalse_options();
		}

		if ( empty( $options ) ) {
			$options = array(
				array(
					'key'   => 'A',
					'text'  => '',
					'image' => 0,
				),
				array(
					'key'   => 'B',
					'text'  => '',
					'image' => 0,
				),
				array(
					'key'   => 'C',
					'text'  => '',
					'image' => 0,
				),
				array(
					'key'   => 'D',
					'text'  => '',
					'image' => 0,
				),
			);
		}

		$tf_correct = in_array( 'B', $correct, true ) ? 'B' : 'A';
		?>
		<div class="ces-options" data-ces-options>
			<p class="description">
				<?php esc_html_e( 'Tick the correct answer. Option letters are assigned automatically from top to bottom, so you can reorder or delete rows safely.', 'classic-edu-suite' ); ?>
			</p>

			<div class="ces-options__tf" data-ces-tf <?php echo CES_Cbt_Question::TYPE_TRUEFALSE === $type ? '' : 'hidden'; ?>>
				<p><strong><?php esc_html_e( 'Correct answer', 'classic-edu-suite' ); ?></strong></p>
				<label><input type="radio" name="ces_q[tf_correct]" value="A" <?php checked( $tf_correct, 'A' ); ?> /> <?php esc_html_e( 'True', 'classic-edu-suite' ); ?></label>
				&nbsp;&nbsp;
				<label><input type="radio" name="ces_q[tf_correct]" value="B" <?php checked( $tf_correct, 'B' ); ?> /> <?php esc_html_e( 'False', 'classic-edu-suite' ); ?></label>
			</div>

			<div class="ces-options__list" data-ces-option-list <?php echo CES_Cbt_Question::TYPE_TRUEFALSE === $type ? 'hidden' : ''; ?>>
				<?php
				$index = 0;

				foreach ( $options as $option ) :
					$key       = isset( $option['key'] ) ? (string) $option['key'] : CES_Cbt_Question::option_letter( $index );
					$text      = isset( $option['text'] ) ? (string) $option['text'] : '';
					$image     = isset( $option['image'] ) ? absint( $option['image'] ) : 0;
					$is_right  = in_array( $key, $correct, true );
					$is_multi  = CES_Cbt_Question::TYPE_MULTI === $type;
					?>
					<div class="ces-option" data-ces-option>
						<span class="ces-option__letter" data-ces-option-letter><?php echo esc_html( CES_Cbt_Question::option_letter( $index ) ); ?></span>

						<label class="ces-option__correct">
							<input
								type="<?php echo $is_multi ? 'checkbox' : 'radio'; ?>"
								name="<?php echo $is_multi ? 'ces_q[correct][]' : 'ces_q[correct]'; ?>"
								value="<?php echo esc_attr( $index ); ?>"
								<?php checked( $is_right ); ?>
								data-ces-option-correct
							/>
							<span class="screen-reader-text"><?php esc_html_e( 'Correct', 'classic-edu-suite' ); ?></span>
						</label>

						<textarea
							class="ces-option__text"
							name="ces_q[options][<?php echo esc_attr( $index ); ?>][text]"
							rows="2"
							placeholder="<?php esc_attr_e( 'Option text', 'classic-edu-suite' ); ?>"
						><?php echo esc_textarea( $text ); ?></textarea>

						<div class="ces-option__image ces-image-field" data-ces-image-field>
							<input type="hidden" name="ces_q[options][<?php echo esc_attr( $index ); ?>][image]" value="<?php echo esc_attr( $image ); ?>" data-ces-image-input />
							<div class="ces-image-field__preview" data-ces-image-preview>
								<?php if ( $image ) : ?>
									<?php echo wp_get_attachment_image( $image, 'thumbnail' ); ?>
								<?php endif; ?>
							</div>
							<button type="button" class="button-link" data-ces-image-pick><?php esc_html_e( 'Image', 'classic-edu-suite' ); ?></button>
							<button type="button" class="button-link ces-danger" data-ces-image-clear><?php esc_html_e( 'Clear', 'classic-edu-suite' ); ?></button>
						</div>

						<button type="button" class="button-link ces-danger ces-option__remove" data-ces-option-remove>
							<?php esc_html_e( 'Remove', 'classic-edu-suite' ); ?>
						</button>
					</div>
					<?php
					++$index;
				endforeach;
				?>
			</div>

			<p class="ces-options__actions" <?php echo CES_Cbt_Question::TYPE_TRUEFALSE === $type ? 'hidden' : ''; ?> data-ces-option-actions>
				<button type="button" class="button" data-ces-option-add><?php esc_html_e( '+ Add option', 'classic-edu-suite' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the explanation editor.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_question_explanation( $post ) {
		$question    = CES_Cbt_Question::get( $post->ID );
		$explanation = $question ? $question['explanation'] : '';

		wp_editor(
			$explanation,
			'ces_q_explanation',
			array(
				'textarea_name' => 'ces_q[explanation]',
				'textarea_rows' => 6,
				'media_buttons' => true,
				'teeny'         => false,
				'quicktags'     => true,
			)
		);

		echo '<p class="description">' . esc_html__( 'Shown on the result screen when "Show correct answers after submission" is on. Worked solutions here are what turn a practice paper into teaching.', 'classic-edu-suite' ) . '</p>';
	}

	/**
	 * Persist the question boxes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_question( $post_id, $post ) {
		if ( ! self::can_save( $post_id, $post, '_ces_cbt_question_nonce', self::QUESTION_NONCE ) ) {
			return;
		}

		if ( ! isset( $_POST['ces_q'] ) || ! is_array( $_POST['ces_q'] ) ) {
			return;
		}

		$raw = wp_unslash( $_POST['ces_q'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$type = CES_Cbt_Question::sanitize_type( isset( $raw['type'] ) ? $raw['type'] : '' );

		if ( CES_Cbt_Question::TYPE_TRUEFALSE === $type ) {
			$options = CES_Cbt_Question::truefalse_options();
			$correct = array( ( isset( $raw['tf_correct'] ) && 'B' === $raw['tf_correct'] ) ? 'B' : 'A' );
		} else {
			$prepared = self::prepare_options( $raw );
			$options  = $prepared['options'];
			$correct  = $prepared['correct'];
		}

		CES_Cbt_Question::save_meta(
			$post_id,
			array(
				'type'        => $type,
				'options'     => $options,
				'correct'     => $correct,
				'explanation' => isset( $raw['explanation'] ) ? $raw['explanation'] : '',
				'difficulty'  => isset( $raw['difficulty'] ) ? $raw['difficulty'] : 'medium',
				'marks'       => isset( $raw['marks'] ) ? $raw['marks'] : 1,
				'image'       => isset( $raw['image'] ) ? $raw['image'] : 0,
			)
		);

		// Surface problems rather than silently storing an unusable question.
		$errors = CES_Cbt_Question::validate(
			array(
				'title'   => $post->post_title,
				'body'    => $post->post_content,
				'type'    => $type,
				'options' => $options,
				'correct' => $correct,
			)
		);

		if ( empty( $errors ) ) {
			delete_post_meta( $post_id, '_ces_q_warnings' );
		} else {
			update_post_meta( $post_id, '_ces_q_warnings', array_map( 'sanitize_text_field', $errors ) );
		}
	}

	/**
	 * Turn posted option rows into clean options plus correct letters.
	 *
	 * Rows the admin emptied or deleted are dropped first, then letters are
	 * reassigned by position, then the ticked row indexes are mapped onto the
	 * new letters. That keeps the correct answer attached to the right text
	 * even when rows move.
	 *
	 * @param array $raw Unslashed `ces_q` payload.
	 * @return array `array( 'options' => array, 'correct' => array )`.
	 */
	private static function prepare_options( array $raw ) {
		$rows = ( isset( $raw['options'] ) && is_array( $raw['options'] ) ) ? $raw['options'] : array();

		$kept    = array();
		$options = array();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$text  = isset( $row['text'] ) ? (string) $row['text'] : '';
			$image = isset( $row['image'] ) ? absint( $row['image'] ) : 0;

			if ( '' === trim( wp_strip_all_tags( $text ) ) && ! $image ) {
				continue;
			}

			$kept[]    = (string) $index;
			$options[] = array(
				'text'  => $text,
				'image' => $image,
			);
		}

		$posted = array();

		if ( isset( $raw['correct'] ) ) {
			$posted = is_array( $raw['correct'] ) ? $raw['correct'] : array( $raw['correct'] );
		}

		$correct = array();

		foreach ( $posted as $index ) {
			$position = array_search( (string) $index, $kept, true );

			if ( false !== $position ) {
				$correct[] = CES_Cbt_Question::option_letter( (int) $position );
			}
		}

		return array(
			'options' => $options,
			'correct' => array_values( array_unique( $correct ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Exam boxes.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the exam setup box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_exam_setup( $post ) {
		wp_nonce_field( self::EXAM_NONCE, '_ces_cbt_exam_nonce' );

		$config = CES_Cbt_Exam::config( $post->ID );

		if ( ! $config ) {
			return;
		}

		$shuffle_q = get_post_meta( $post->ID, CES_Cbt_Exam::META_SHUFFLE_QUESTIONS, true );
		$shuffle_q = ( '' === $shuffle_q || null === $shuffle_q ) ? -1 : (int) $shuffle_q;

		$shuffle_o = get_post_meta( $post->ID, CES_Cbt_Exam::META_SHUFFLE_OPTIONS, true );
		$shuffle_o = ( '' === $shuffle_o || null === $shuffle_o ) ? -1 : (int) $shuffle_o;

		$tri = array(
			-1 => __( 'Use the global setting', 'classic-edu-suite' ),
			1  => __( 'Yes', 'classic-edu-suite' ),
			0  => __( 'No', 'classic-edu-suite' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="ces-exam-duration"><?php esc_html_e( 'Duration (minutes)', 'classic-edu-suite' ); ?></label></th>
					<td>
						<input type="number" min="0" step="1" id="ces-exam-duration" name="ces_exam[duration]" class="small-text" value="<?php echo esc_attr( (int) get_post_meta( $post->ID, CES_Cbt_Exam::META_DURATION, true ) ); ?>" />
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: default duration in minutes. */
									__( '0 uses the global default of %d minutes.', 'classic-edu-suite' ),
									CES_Helpers::setting_int( 'cbt_default_duration', 30 )
								)
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ces-exam-count"><?php esc_html_e( 'Questions in the paper', 'classic-edu-suite' ); ?></label></th>
					<td>
						<input type="number" min="0" step="1" id="ces-exam-count" name="ces_exam[question_count]" class="small-text" value="<?php echo esc_attr( (int) get_post_meta( $post->ID, CES_Cbt_Exam::META_COUNT, true ) ); ?>" />
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of questions currently matching the filters. */
									__( 'Questions currently available to this exam: %d.', 'classic-edu-suite' ),
									CES_Cbt_Exam::available_count( $post->ID )
								)
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ces-exam-pass"><?php esc_html_e( 'Pass mark (%)', 'classic-edu-suite' ); ?></label></th>
					<td><input type="number" min="0" max="100" step="1" id="ces-exam-pass" name="ces_exam[pass_mark]" class="small-text" value="<?php echo esc_attr( (int) $config['pass_mark'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ces-exam-max"><?php esc_html_e( 'Maximum attempts per student', 'classic-edu-suite' ); ?></label></th>
					<td>
						<input type="number" min="0" step="1" id="ces-exam-max" name="ces_exam[max_attempts]" class="small-text" value="<?php echo esc_attr( (int) $config['max_attempts'] ); ?>" />
						<p class="description"><?php esc_html_e( '0 means unlimited, subject to the global retake setting.', 'classic-edu-suite' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shuffle questions', 'classic-edu-suite' ); ?></th>
					<td>
						<select name="ces_exam[shuffle_questions]">
							<?php foreach ( $tri as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $shuffle_q, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shuffle options', 'classic-edu-suite' ); ?></th>
					<td>
						<select name="ces_exam[shuffle_options]">
							<?php foreach ( $tri as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $shuffle_o, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Open to students', 'classic-edu-suite' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ces_exam[active]" value="1" <?php checked( (bool) $config['active'] ); ?> />
							<?php esc_html_e( 'Students may sit this exam', 'classic-edu-suite' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the question selection box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_exam_selection( $post ) {
		$config  = CES_Cbt_Exam::config( $post->ID );
		$filters = $config ? $config['filters'] : array();
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="ces-exam-selection"><?php esc_html_e( 'How questions are chosen', 'classic-edu-suite' ); ?></label></th>
					<td>
						<select id="ces-exam-selection" name="ces_exam[selection]">
							<?php foreach ( CES_Cbt_Exam::selection_modes() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $config ? $config['selection'] : '', $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Random draws a fresh paper for every sitting from the filters below. Hand-picked always uses the exact list.', 'classic-edu-suite' ); ?></p>
					</td>
				</tr>

				<?php foreach ( CES_Cbt_Cpt::taxonomies() as $taxonomy => $label ) : ?>
					<?php
					$terms = get_terms(
						array(
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
							'number'     => 500,
						)
					);

					if ( is_wp_error( $terms ) ) {
						continue;
					}

					$selected = isset( $filters[ $taxonomy ] ) ? array_map( 'absint', (array) $filters[ $taxonomy ] ) : array();
					?>
					<tr>
						<th scope="row"><label for="ces-exam-f-<?php echo esc_attr( $taxonomy ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<select id="ces-exam-f-<?php echo esc_attr( $taxonomy ); ?>" name="ces_exam[filters][<?php echo esc_attr( $taxonomy ); ?>][]" multiple size="5" class="ces-multiselect">
								<?php foreach ( $terms as $term ) : ?>
									<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php echo in_array( (int) $term->term_id, $selected, true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( $term->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>

				<tr>
					<th scope="row"><label for="ces-exam-difficulty"><?php esc_html_e( 'Difficulty', 'classic-edu-suite' ); ?></label></th>
					<td>
						<select id="ces-exam-difficulty" name="ces_exam[difficulty]">
							<option value=""><?php esc_html_e( 'Any', 'classic-edu-suite' ); ?></option>
							<?php foreach ( CES_Cbt_Question::difficulties() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $config ? $config['difficulty'] : '', $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="ces-exam-ids"><?php esc_html_e( 'Hand-picked question IDs', 'classic-edu-suite' ); ?></label></th>
					<td>
						<textarea id="ces-exam-ids" name="ces_exam[question_ids]" rows="4" class="large-text code" placeholder="104, 105, 106"><?php echo esc_textarea( $config ? implode( ', ', $config['manual_ids'] ) : '' ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Comma or space separated. Only used by the hand-picked mode. IDs are shown in the Question Bank list.', 'classic-edu-suite' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the exam instructions editor.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_exam_instructions( $post ) {
		$instructions = (string) get_post_meta( $post->ID, CES_Cbt_Exam::META_INSTRUCTIONS, true );

		wp_editor(
			$instructions,
			'ces_exam_instructions',
			array(
				'textarea_name' => 'ces_exam[instructions]',
				'textarea_rows' => 6,
				'media_buttons' => false,
				'teeny'         => true,
			)
		);
	}

	/**
	 * Render the "how to use" sidebar box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_exam_howto( $post ) {
		?>
		<p><?php esc_html_e( 'Put this shortcode on any page or Elementor widget:', 'classic-edu-suite' ); ?></p>
		<p><code>[ces_exam id="<?php echo absint( $post->ID ); ?>"]</code></p>
		<p><?php esc_html_e( 'Or list every exam and let students choose:', 'classic-edu-suite' ); ?></p>
		<p><code>[ces_exam_list]</code></p>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . CES_Cbt_Reports::PAGE . '&exam_id=' . absint( $post->ID ) ) ); ?>">
				<?php esc_html_e( 'See results for this exam', 'classic-edu-suite' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Persist the exam boxes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_exam( $post_id, $post ) {
		if ( ! self::can_save( $post_id, $post, '_ces_cbt_exam_nonce', self::EXAM_NONCE ) ) {
			return;
		}

		if ( ! isset( $_POST['ces_exam'] ) || ! is_array( $_POST['ces_exam'] ) ) {
			return;
		}

		$raw = wp_unslash( $_POST['ces_exam'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		CES_Cbt_Exam::save_meta( $post_id, is_array( $raw ) ? $raw : array() );
	}

	/* ---------------------------------------------------------------------
	 * Shared.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether this save_post pass should write meta.
	 *
	 * @param int     $post_id    Post ID.
	 * @param WP_Post $post       Post object.
	 * @param string  $nonce_name Nonce field name.
	 * @param string  $action     Nonce action.
	 * @return bool
	 */
	private static function can_save( $post_id, $post, $nonce_name, $action ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		if ( ! isset( $_POST[ $nonce_name ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) );

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return false;
		}

		return (bool) $post;
	}
}
