<?php
/**
 * Front-end shortcodes and asset loading.
 *
 * Four shortcodes:
 *   [ces_exam id="123"]        the exam runner
 *   [ces_exam_list]            a picker, which also hosts the runner
 *   [ces_cbt_results]          a student's own history and result screens
 *   [ces_cbt_leaderboard]      top scores
 *
 * The runner's chrome is rendered here so there is meaningful content before
 * JavaScript boots; the paper itself is drawn by `cbt-exam.js` from the REST
 * payload, because the questions must not be in the page source.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode handlers.
 */
class CES_Cbt_Shortcode {

	/**
	 * Whether the assets have been registered this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register hooks and shortcodes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );

		add_shortcode( 'ces_exam', array( __CLASS__, 'exam' ) );
		add_shortcode( 'ces_exam_list', array( __CLASS__, 'exam_list' ) );
		add_shortcode( 'ces_cbt_results', array( __CLASS__, 'results' ) );
		add_shortcode( 'ces_cbt_leaderboard', array( __CLASS__, 'leaderboard' ) );
	}

	/**
	 * Register, but do not enqueue, the module's assets.
	 *
	 * @return void
	 */
	public static function register_assets() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		/*
		 * `ces-base` is a dependency, not a convenience: it carries the
		 * design tokens and the `--ces-art-*` illustrations the exam cards
		 * draw their subject artwork from. Declaring it also guarantees
		 * cbt.css loads *after* it, so these rules win on a specificity tie.
		 */
		CES_Assets::register();

		wp_register_style(
			'ces-cbt',
			CES_URL . 'modules/cbt/assets/cbt.css',
			array( 'ces-base' ),
			CES_VERSION
		);

		wp_register_script(
			'ces-cbt-exam',
			CES_URL . 'modules/cbt/assets/cbt-exam.js',
			array(),
			CES_VERSION,
			true
		);
	}

	/**
	 * Enqueue the stylesheet, with the brand colour wired to `--ces-primary`.
	 *
	 * @return void
	 */
	public static function enqueue_style() {
		self::register_assets();
		wp_enqueue_style( 'ces-cbt' );

		$primary = CES_Helpers::setting( 'brand_primary', '#2c62cd' );
		$primary = sanitize_hex_color( (string) $primary );

		if ( ! $primary ) {
			$primary = '#2c62cd';
		}

		wp_add_inline_style( 'ces-cbt', ':root{--ces-primary:' . $primary . ';}' );
	}

	/**
	 * Enqueue the runner script and hand it the REST endpoint plus strings.
	 *
	 * @return void
	 */
	public static function enqueue_script() {
		self::enqueue_style();
		wp_enqueue_script( 'ces-cbt-exam' );

		/**
		 * Filter the URL of a MathJax or KaTeX bundle to load on exam pages.
		 *
		 * Empty by default: the plugin ships no maths renderer and the build
		 * rules forbid hotlinking a CDN. Point this at a copy you host, or
		 * enqueue your own renderer, and the runner will typeset after every
		 * render. See the README.
		 *
		 * @param string $url Script URL.
		 */
		$math_url = (string) apply_filters( 'ces_cbt_math_script_url', '' );

		if ( $math_url ) {
			wp_enqueue_script( 'ces-cbt-math', esc_url_raw( $math_url ), array(), CES_VERSION, true );
		}

		wp_localize_script(
			'ces-cbt-exam',
			'cesCbt',
			array(
				'root'    => esc_url_raw( rest_url( ( defined( 'CES_REST_NAMESPACE' ) ? CES_REST_NAMESPACE : 'ces/v1' ) . '/cbt' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'userId'  => get_current_user_id(),
				'strings' => array(
					'loading'        => __( 'Loading…', 'classic-edu-suite' ),
					'starting'       => __( 'Starting your exam…', 'classic-edu-suite' ),
					'question'       => __( 'Question', 'classic-edu-suite' ),
					'of'             => __( 'of', 'classic-edu-suite' ),
					'next'           => __( 'Next', 'classic-edu-suite' ),
					'previous'       => __( 'Previous', 'classic-edu-suite' ),
					'flag'           => __( 'Flag for review', 'classic-edu-suite' ),
					'unflag'         => __( 'Remove flag', 'classic-edu-suite' ),
					'clear'          => __( 'Clear answer', 'classic-edu-suite' ),
					'submit'         => __( 'Submit paper', 'classic-edu-suite' ),
					'submitting'     => __( 'Submitting…', 'classic-edu-suite' ),
					'saved'          => __( 'Saved', 'classic-edu-suite' ),
					'saving'         => __( 'Saving…', 'classic-edu-suite' ),
					'offline'        => __( 'Offline — answers queued', 'classic-edu-suite' ),
					'retrying'       => __( 'Reconnecting…', 'classic-edu-suite' ),
					'queued'         => __( 'answer(s) waiting to save', 'classic-edu-suite' ),
					'timeLeft'       => __( 'Time left', 'classic-edu-suite' ),
					'timeUp'         => __( 'Time is up. Submitting your paper…', 'classic-edu-suite' ),
					'confirmTitle'   => __( 'Submit your paper?', 'classic-edu-suite' ),
					'confirmAll'     => __( 'All questions answered. You cannot change your answers after this.', 'classic-edu-suite' ),
					'confirmSome'    => __( 'You have %d unanswered question(s). You cannot change your answers after this.', 'classic-edu-suite' ),
					'confirmFlagged' => __( '%d question(s) are still flagged for review.', 'classic-edu-suite' ),
					'cancel'         => __( 'Keep working', 'classic-edu-suite' ),
					'yesSubmit'      => __( 'Yes, submit', 'classic-edu-suite' ),
					'palette'        => __( 'Question palette', 'classic-edu-suite' ),
					'answeredLabel'  => __( 'Answered', 'classic-edu-suite' ),
					'unansweredLbl'  => __( 'Not answered', 'classic-edu-suite' ),
					'flaggedLabel'   => __( 'Flagged', 'classic-edu-suite' ),
					'currentLabel'   => __( 'Current', 'classic-edu-suite' ),
					'yourScore'      => __( 'Your score', 'classic-edu-suite' ),
					'passed'         => __( 'Passed', 'classic-edu-suite' ),
					'failed'         => __( 'Not passed', 'classic-edu-suite' ),
					'correctAnswers' => __( 'Correct answers', 'classic-edu-suite' ),
					'timeTaken'      => __( 'Time taken', 'classic-edu-suite' ),
					'reviewHeading'  => __( 'Question review', 'classic-edu-suite' ),
					'yourAnswer'     => __( 'Your answer', 'classic-edu-suite' ),
					'correctAnswer'  => __( 'Correct answer', 'classic-edu-suite' ),
					'explanation'    => __( 'Explanation', 'classic-edu-suite' ),
					'noAnswer'       => __( 'Not answered', 'classic-edu-suite' ),
					'answersHidden'  => __( 'Correct answers are not shown for this exam.', 'classic-edu-suite' ),
					'lockdownWarn'   => __( 'Leaving the exam tab is recorded. Please stay on this page.', 'classic-edu-suite' ),
					'genericError'   => __( 'Something went wrong. Please try again.', 'classic-edu-suite' ),
					'multiHint'      => __( 'Select every answer that applies.', 'classic-edu-suite' ),
					'startExam'      => __( 'Start exam', 'classic-edu-suite' ),
					'resumeExam'     => __( 'Resume exam', 'classic-edu-suite' ),
					'viewResults'    => __( 'View all my results', 'classic-edu-suite' ),
					'tryAgain'       => __( 'Try again', 'classic-edu-suite' ),
					'minutes'        => __( 'min', 'classic-edu-suite' ),
					'questions'      => __( 'questions', 'classic-edu-suite' ),
					'passMark'       => __( 'pass mark', 'classic-edu-suite' ),
				),
			)
		);
	}

	/**
	 * A login prompt for guests.
	 *
	 * @param string $message Why they need to log in.
	 * @return string
	 */
	private static function login_notice( $message ) {
		$current = home_url( add_query_arg( array() ) );

		return '<div class="ces-cbt ces-cbt--notice"><p>' . esc_html( $message ) . '</p>'
			. '<p><a class="ces-cbt__btn ces-cbt__btn--primary" href="' . esc_url( CES_Helpers::login_url( $current ) ) . '">'
			. esc_html__( 'Log in to continue', 'classic-edu-suite' ) . '</a></p></div>';
	}

	/**
	 * A plain notice box.
	 *
	 * @param string $message Message.
	 * @param string $tone    `info`, `warn` or `error`.
	 * @return string
	 */
	private static function notice( $message, $tone = 'info' ) {
		return '<div class="ces-cbt ces-cbt--notice ces-cbt--' . esc_attr( sanitize_key( $tone ) ) . '"><p>'
			. esc_html( $message ) . '</p></div>';
	}

	/**
	 * The URL the exam list points at for a given exam.
	 *
	 * @param int $exam_id   Exam post ID.
	 * @param int $page_id   Optional page hosting the runner.
	 * @return string
	 */
	private static function exam_url( $exam_id, $page_id = 0 ) {
		$base = $page_id ? get_permalink( $page_id ) : get_permalink();

		if ( ! $base ) {
			$base = home_url( add_query_arg( array() ) );
		}

		return add_query_arg( 'ces_exam', absint( $exam_id ), remove_query_arg( array( 'attempt', 'ces_exam' ), $base ) );
	}

	/* ---------------------------------------------------------------------
	 * [ces_exam]
	 * ------------------------------------------------------------------ */

	/**
	 * Render the exam runner.
	 *
	 * @param array $atts Shortcode attributes: `id`.
	 * @return string
	 */
	public static function exam( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'ces_exam'
		);

		$exam_id = absint( $atts['id'] );

		if ( ! $exam_id && isset( $_GET['ces_exam'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$exam_id = absint( wp_unslash( $_GET['ces_exam'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! $exam_id ) {
			return self::notice( __( 'No exam was specified.', 'classic-edu-suite' ), 'warn' );
		}

		$config = CES_Cbt_Exam::config( $exam_id );

		if ( ! $config || 'publish' !== $config['status'] ) {
			return self::notice( __( 'That exam is not available.', 'classic-edu-suite' ), 'warn' );
		}

		if ( ! is_user_logged_in() ) {
			return self::login_notice( __( 'You need an account to sit this exam. Your score, coins and progress are saved to it.', 'classic-edu-suite' ) );
		}

		$user_id   = get_current_user_id();
		$available = CES_Cbt_Exam::available_count( $exam_id );

		if ( ! $config['active'] || $available < 1 ) {
			return self::notice( __( 'This exam is not open yet. Please check back soon.', 'classic-edu-suite' ), 'warn' );
		}

		$open   = CES_Cbt_Attempt::find_in_progress( $user_id, $exam_id );
		$resume = ( $open && ! CES_Cbt_Attempt::is_expired( $open ) ) ? (int) $open->id : 0;

		$blocker = '';

		if ( ! $resume ) {
			$allowed = CES_Cbt_Attempt::retake_check( $user_id, $exam_id, $config );

			if ( is_wp_error( $allowed ) ) {
				$blocker = $allowed->get_error_message();
			}
		}

		self::enqueue_script();

		$count    = min( (int) $config['question_count'], $available );
		$used     = CES_Cbt_Attempt::completed_count( $user_id, $exam_id );
		$lockdown = CES_Helpers::enabled( 'cbt_lockdown_mode' );

		$boot = array(
			'examId'          => $exam_id,
			'resumeAttemptId' => $resume,
			'title'           => $config['title'],
			'durationSeconds' => (int) $config['duration_seconds'],
			'questionCount'   => $count,
			'passMark'        => (int) $config['pass_mark'],
			'lockdown'        => (bool) $lockdown,
			'resultsUrl'      => CES_Helpers::dashboard_url( 'cbt-results' ),
		);

		ob_start();
		?>
		<div class="ces-cbt ces-cbt--exam" data-ces-cbt="<?php echo esc_attr( wp_json_encode( $boot ) ); ?>">
			<noscript>
				<div class="ces-cbt__notice ces-cbt--warn">
					<p><?php esc_html_e( 'This exam needs JavaScript. Please enable it, or use a different browser.', 'classic-edu-suite' ); ?></p>
				</div>
			</noscript>

			<section class="ces-cbt__intro" data-ces-role="intro">
				<h2 class="ces-cbt__title"><?php echo esc_html( $config['title'] ); ?></h2>

				<ul class="ces-cbt__chips">
					<li class="ces-cbt__chip">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of questions. */
								_n( '%d question', '%d questions', $count, 'classic-edu-suite' ),
								$count
							)
						);
						?>
					</li>
					<li class="ces-cbt__chip">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: duration in minutes. */
								_n( '%d minute', '%d minutes', (int) $config['duration'], 'classic-edu-suite' ),
								(int) $config['duration']
							)
						);
						?>
					</li>
					<li class="ces-cbt__chip">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: pass mark percentage. */
								__( '%d%% to pass', 'classic-edu-suite' ),
								(int) $config['pass_mark']
							)
						);
						?>
					</li>
					<?php if ( $config['max_attempts'] > 0 ) : ?>
						<li class="ces-cbt__chip">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: attempts used, 2: attempts allowed. */
									__( 'Attempt %1$d of %2$d', 'classic-edu-suite' ),
									min( $used + 1, (int) $config['max_attempts'] ),
									(int) $config['max_attempts']
								)
							);
							?>
						</li>
					<?php endif; ?>
				</ul>

				<?php if ( ! empty( $config['instructions'] ) ) : ?>
					<div class="ces-cbt__instructions">
						<h3><?php esc_html_e( 'Instructions', 'classic-edu-suite' ); ?></h3>
						<?php echo wp_kses_post( wpautop( $config['instructions'] ) ); ?>
					</div>
				<?php endif; ?>

				<div class="ces-cbt__instructions">
					<h3><?php esc_html_e( 'Before you begin', 'classic-edu-suite' ); ?></h3>
					<ul class="ces-cbt__bullets">
						<li><?php esc_html_e( 'The timer starts the moment you tap the button and keeps running on the server, even if you close the page.', 'classic-edu-suite' ); ?></li>
						<li><?php esc_html_e( 'Every answer is saved as you go. If your data drops, answers are queued and sent again automatically.', 'classic-edu-suite' ); ?></li>
						<li><?php esc_html_e( 'Use the question grid to jump around, and flag anything you want to come back to.', 'classic-edu-suite' ); ?></li>
						<?php if ( $lockdown ) : ?>
							<li><?php esc_html_e( 'Leaving this tab during the exam is recorded on your attempt.', 'classic-edu-suite' ); ?></li>
						<?php endif; ?>
					</ul>
				</div>

				<?php if ( $blocker ) : ?>
					<div class="ces-cbt__notice ces-cbt--warn"><p><?php echo esc_html( $blocker ); ?></p></div>
					<p><a class="ces-cbt__btn" href="<?php echo esc_url( CES_Helpers::dashboard_url( 'cbt-results' ) ); ?>"><?php esc_html_e( 'See my results', 'classic-edu-suite' ); ?></a></p>
				<?php else : ?>
					<p class="ces-cbt__actions">
						<button type="button" class="ces-cbt__btn ces-cbt__btn--primary ces-cbt__btn--lg" data-ces-action="start">
							<?php echo $resume ? esc_html__( 'Resume exam', 'classic-edu-suite' ) : esc_html__( 'Start exam', 'classic-edu-suite' ); ?>
						</button>
					</p>
					<p class="ces-cbt__hint" data-ces-role="intro-error" hidden></p>
				<?php endif; ?>
			</section>

			<section class="ces-cbt__runner" data-ces-role="runner" hidden></section>
			<section class="ces-cbt__result" data-ces-role="result" hidden></section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * [ces_exam_list]
	 * ------------------------------------------------------------------ */

	/**
	 * Render the exam picker. Hosts the runner when `?ces_exam=` is set.
	 *
	 * @param array $atts Shortcode attributes: `subject`, `exam_type`, `limit`,
	 *                    `exam_page`.
	 * @return string
	 */
	public static function exam_list( $atts ) {
		$atts = shortcode_atts(
			array(
				'subject'   => '',
				'exam_type' => '',
				'topic'     => '',
				'year'      => '',
				'limit'     => 50,
				'exam_page' => 0,
			),
			$atts,
			'ces_exam_list'
		);

		$page_id = absint( $atts['exam_page'] );

		// When an exam is selected and the runner is not on its own page, show
		// the runner here instead of the list.
		if ( ! $page_id && isset( $_GET['ces_exam'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$selected = absint( wp_unslash( $_GET['ces_exam'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $selected ) {
				$back = remove_query_arg( 'ces_exam', home_url( add_query_arg( array() ) ) );

				return '<p class="ces-cbt__back"><a href="' . esc_url( $back ) . '">'
					. esc_html__( '&larr; All exams', 'classic-edu-suite' ) . '</a></p>'
					. self::exam( array( 'id' => $selected ) );
			}
		}

		self::enqueue_style();

		$exams = CES_Cbt_Exam::list_exams(
			array(
				'subject'   => $atts['subject'],
				'exam_type' => $atts['exam_type'],
				'topic'     => $atts['topic'],
				'year'      => $atts['year'],
				'limit'     => absint( $atts['limit'] ),
			)
		);

		if ( empty( $exams ) ) {
			return self::notice( __( 'No exams have been published yet.', 'classic-edu-suite' ) );
		}

		ob_start();
		?>
		<div class="ces-cbt ces-cbt--list">
			<div class="ces-cbt__grid">
				<?php
				foreach ( $exams as $exam ) :
					$summary = CES_Cbt_Exam::summary( $exam->ID );

					if ( ! $summary ) {
						continue;
					}

					$url = self::exam_url( $exam->ID, $page_id );
					?>
					<article class="ces-cbt__card" data-ces-art="<?php echo esc_attr( CES_Helpers::subject_art( $summary['subject'] ) ); ?>">
						<span class="ces-cbt__card-art" aria-hidden="true"></span>

						<h3 class="ces-cbt__card-title">
							<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $summary['title'] ); ?></a>
						</h3>

						<ul class="ces-cbt__chips ces-cbt__chips--sm">
							<?php if ( $summary['examType'] ) : ?>
								<li class="ces-cbt__chip"><?php echo esc_html( $summary['examType'] ); ?></li>
							<?php endif; ?>
							<?php if ( $summary['subject'] ) : ?>
								<li class="ces-cbt__chip"><?php echo esc_html( $summary['subject'] ); ?></li>
							<?php endif; ?>
							<?php if ( $summary['year'] ) : ?>
								<li class="ces-cbt__chip"><?php echo esc_html( $summary['year'] ); ?></li>
							<?php endif; ?>
						</ul>

						<p class="ces-cbt__card-meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: question count, 2: duration in minutes, 3: pass mark. */
									__( '%1$d questions · %2$d min · %3$d%% to pass', 'classic-edu-suite' ),
									(int) $summary['questionCount'],
									(int) $summary['duration'],
									(int) $summary['passMark']
								)
							);
							?>
						</p>

						<?php if ( $summary['active'] ) : ?>
							<a class="ces-cbt__btn ces-cbt__btn--primary" href="<?php echo esc_url( $url ); ?>">
								<?php esc_html_e( 'Open exam', 'classic-edu-suite' ); ?>
							</a>
						<?php else : ?>
							<span class="ces-cbt__badge ces-cbt__badge--muted"><?php esc_html_e( 'Coming soon', 'classic-edu-suite' ); ?></span>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * [ces_cbt_results]
	 * ------------------------------------------------------------------ */

	/**
	 * Render a student's results, or one result in full.
	 *
	 * @param array $atts Shortcode attributes: `limit`.
	 * @return string
	 */
	public static function results( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit' => 20,
			),
			$atts,
			'ces_cbt_results'
		);

		if ( ! is_user_logged_in() ) {
			return self::login_notice( __( 'Log in to see your exam results.', 'classic-edu-suite' ) );
		}

		self::enqueue_style();

		$user_id = get_current_user_id();

		$attempt_id = isset( $_GET['attempt'] ) ? absint( wp_unslash( $_GET['attempt'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $attempt_id ) {
			return self::render_single_result( $attempt_id, $user_id );
		}

		$rows = CES_Cbt_Attempt::user_results( $user_id, absint( $atts['limit'] ), 0 );

		if ( empty( $rows ) ) {
			return self::notice( __( 'You have not sat any exams yet. Pick one and get started.', 'classic-edu-suite' ) );
		}

		$base = home_url( add_query_arg( array() ) );

		ob_start();
		?>
		<div class="ces-cbt ces-cbt--results">
			<div class="ces-cbt__table-wrap">
				<table class="ces-cbt__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Exam', 'classic-edu-suite' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Score', 'classic-edu-suite' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'classic-edu-suite' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Date', 'classic-edu-suite' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Review', 'classic-edu-suite' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Exam', 'classic-edu-suite' ); ?>">
									<?php echo esc_html( $row['examTitle'] ); ?>
									<span class="ces-cbt__muted">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: correct answers, 2: total questions. */
												__( '%1$d / %2$d correct', 'classic-edu-suite' ),
												(int) $row['correctCount'],
												(int) $row['totalQuestions']
											)
										);
										?>
									</span>
								</td>
								<td data-label="<?php esc_attr_e( 'Score', 'classic-edu-suite' ); ?>">
									<?php echo esc_html( number_format_i18n( $row['score'], 1 ) ); ?>%
								</td>
								<td data-label="<?php esc_attr_e( 'Result', 'classic-edu-suite' ); ?>">
									<?php if ( CES_Cbt_Attempt::STATUS_IN_PROGRESS === $row['status'] ) : ?>
										<span class="ces-cbt__badge ces-cbt__badge--muted"><?php esc_html_e( 'In progress', 'classic-edu-suite' ); ?></span>
									<?php elseif ( $row['passed'] ) : ?>
										<span class="ces-cbt__badge ces-cbt__badge--pass"><?php esc_html_e( 'Passed', 'classic-edu-suite' ); ?></span>
									<?php else : ?>
										<span class="ces-cbt__badge ces-cbt__badge--fail"><?php esc_html_e( 'Not passed', 'classic-edu-suite' ); ?></span>
									<?php endif; ?>
								</td>
								<td data-label="<?php esc_attr_e( 'Date', 'classic-edu-suite' ); ?>">
									<?php
									$when = $row['submittedAt'] ? $row['submittedAt'] : $row['startedAt'];
									echo esc_html( $when ? mysql2date( get_option( 'date_format' ) . ' H:i', $when ) : '—' );
									?>
								</td>
								<td data-label="<?php esc_attr_e( 'Review', 'classic-edu-suite' ); ?>">
									<?php if ( CES_Cbt_Attempt::STATUS_IN_PROGRESS !== $row['status'] ) : ?>
										<a class="ces-cbt__btn ces-cbt__btn--sm" href="<?php echo esc_url( add_query_arg( 'attempt', (int) $row['attemptId'], $base ) ); ?>">
											<?php esc_html_e( 'Review', 'classic-edu-suite' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one result screen with its per-question review.
	 *
	 * @param int $attempt_id Attempt ID.
	 * @param int $user_id    Viewing user.
	 * @return string
	 */
	private static function render_single_result( $attempt_id, $user_id ) {
		$result = CES_Cbt_Attempt::result( $attempt_id, $user_id );

		if ( is_wp_error( $result ) ) {
			return self::notice( $result->get_error_message(), 'warn' );
		}

		$back = remove_query_arg( 'attempt', home_url( add_query_arg( array() ) ) );

		ob_start();
		?>
		<div class="ces-cbt ces-cbt--result-page">
			<p class="ces-cbt__back"><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '&larr; All my results', 'classic-edu-suite' ); ?></a></p>

			<div class="ces-cbt__scorecard <?php echo $result['passed'] ? 'is-pass' : 'is-fail'; ?>">
				<h2 class="ces-cbt__title"><?php echo esc_html( $result['examTitle'] ); ?></h2>
				<p class="ces-cbt__score"><?php echo esc_html( number_format_i18n( $result['score'], 1 ) ); ?><span>%</span></p>
				<p class="ces-cbt__verdict">
					<?php echo $result['passed'] ? esc_html__( 'Passed', 'classic-edu-suite' ) : esc_html__( 'Not passed', 'classic-edu-suite' ); ?>
					<span class="ces-cbt__muted">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: pass mark percentage. */
								__( 'pass mark %d%%', 'classic-edu-suite' ),
								(int) $result['passMark']
							)
						);
						?>
					</span>
				</p>

				<ul class="ces-cbt__stats">
					<li>
						<strong><?php echo esc_html( (int) $result['correctCount'] . ' / ' . (int) $result['totalQuestions'] ); ?></strong>
						<span><?php esc_html_e( 'Correct', 'classic-edu-suite' ); ?></span>
					</li>
					<li>
						<strong><?php echo esc_html( CES_Cbt_Attempt::format_duration( $result['timeSpent'] ) ); ?></strong>
						<span><?php esc_html_e( 'Time taken', 'classic-edu-suite' ); ?></span>
					</li>
					<li>
						<strong><?php echo esc_html( (int) $result['answered'] ); ?></strong>
						<span><?php esc_html_e( 'Attempted', 'classic-edu-suite' ); ?></span>
					</li>
				</ul>

				<?php if ( 'user' !== $result['submitReason'] ) : ?>
					<p class="ces-cbt__hint"><?php esc_html_e( 'This paper was submitted automatically when the time ran out.', 'classic-edu-suite' ); ?></p>
				<?php endif; ?>
			</div>

			<h3 class="ces-cbt__section-title"><?php esc_html_e( 'Question review', 'classic-edu-suite' ); ?></h3>

			<?php if ( ! $result['revealAnswers'] ) : ?>
				<p class="ces-cbt__hint"><?php esc_html_e( 'Correct answers are not shown for this exam.', 'classic-edu-suite' ); ?></p>
			<?php endif; ?>

			<ol class="ces-cbt__review">
				<?php foreach ( $result['questions'] as $question ) : ?>
					<li class="ces-cbt__review-item <?php echo $question['isCorrect'] ? 'is-correct' : 'is-wrong'; ?>">
						<div class="ces-cbt__review-body"><?php echo wp_kses_post( $question['body'] ); ?></div>

						<?php if ( ! empty( $question['image'] ) ) : ?>
							<img class="ces-cbt__review-image" src="<?php echo esc_url( $question['image'] ); ?>" alt="" loading="lazy" />
						<?php endif; ?>

						<ul class="ces-cbt__review-options">
							<?php
							foreach ( $question['options'] as $option ) :
								$key      = (string) $option['key'];
								$is_given = in_array( $key, (array) $question['given'], true );
								$is_right = ! empty( $question['correct'] ) && in_array( $key, (array) $question['correct'], true );

								$classes = array( 'ces-cbt__review-option' );

								if ( $is_given ) {
									$classes[] = 'is-given';
								}
								if ( $is_right ) {
									$classes[] = 'is-right';
								}
								?>
								<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
									<span class="ces-cbt__option-key"><?php echo esc_html( $key ); ?></span>
									<span class="ces-cbt__option-text"><?php echo wp_kses_post( $option['text'] ); ?></span>
									<?php if ( ! empty( $option['image'] ) ) : ?>
										<img src="<?php echo esc_url( $option['image'] ); ?>" alt="" loading="lazy" />
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>

						<p class="ces-cbt__review-meta">
							<?php if ( empty( $question['given'] ) ) : ?>
								<span class="ces-cbt__badge ces-cbt__badge--muted"><?php esc_html_e( 'Not answered', 'classic-edu-suite' ); ?></span>
							<?php elseif ( $question['isCorrect'] ) : ?>
								<span class="ces-cbt__badge ces-cbt__badge--pass"><?php esc_html_e( 'Correct', 'classic-edu-suite' ); ?></span>
							<?php else : ?>
								<span class="ces-cbt__badge ces-cbt__badge--fail"><?php esc_html_e( 'Wrong', 'classic-edu-suite' ); ?></span>
							<?php endif; ?>

							<?php if ( $result['revealAnswers'] && ! empty( $question['correct'] ) ) : ?>
								<span class="ces-cbt__muted">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: correct option letters, e.g. "A" or "A, C". */
											__( 'Correct answer: %s', 'classic-edu-suite' ),
											implode( ', ', array_map( 'strval', (array) $question['correct'] ) )
										)
									);
									?>
								</span>
							<?php endif; ?>
						</p>

						<?php if ( ! empty( $question['explanation'] ) ) : ?>
							<div class="ces-cbt__explanation">
								<h4><?php esc_html_e( 'Explanation', 'classic-edu-suite' ); ?></h4>
								<?php echo wp_kses_post( $question['explanation'] ); ?>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * [ces_cbt_leaderboard]
	 * ------------------------------------------------------------------ */

	/**
	 * Render a leaderboard.
	 *
	 * @param array $atts Shortcode attributes: `exam`, `limit`.
	 * @return string
	 */
	public static function leaderboard( $atts ) {
		$atts = shortcode_atts(
			array(
				'exam'  => 0,
				'limit' => 20,
			),
			$atts,
			'ces_cbt_leaderboard'
		);

		if ( ! CES_Helpers::enabled( 'cbt_leaderboard_enabled' ) ) {
			return '';
		}

		self::enqueue_style();

		$exam_id = absint( $atts['exam'] );
		$rows    = CES_Cbt_Attempt::leaderboard( $exam_id, absint( $atts['limit'] ) );

		if ( empty( $rows ) ) {
			return self::notice( __( 'No scores on the board yet. Be the first.', 'classic-edu-suite' ) );
		}

		$me = get_current_user_id();

		ob_start();
		?>
		<div class="ces-cbt ces-cbt--leaderboard">
			<?php if ( $exam_id ) : ?>
				<h3 class="ces-cbt__section-title"><?php echo esc_html( get_the_title( $exam_id ) ); ?></h3>
			<?php endif; ?>

			<div class="ces-cbt__table-wrap">
				<table class="ces-cbt__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( '#', 'classic-edu-suite' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Student', 'classic-edu-suite' ); ?></th>
							<th scope="col"><?php echo $exam_id ? esc_html__( 'Best score', 'classic-edu-suite' ) : esc_html__( 'Average', 'classic-edu-suite' ); ?></th>
							<th scope="col"><?php echo $exam_id ? esc_html__( 'Best time', 'classic-edu-suite' ) : esc_html__( 'Passes', 'classic-edu-suite' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr class="<?php echo ( $me && $me === (int) $row['userId'] ) ? 'is-me' : ''; ?>">
								<td data-label="#"><?php echo esc_html( (int) $row['rank'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Student', 'classic-edu-suite' ); ?>"><?php echo esc_html( $row['name'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Score', 'classic-edu-suite' ); ?>"><?php echo esc_html( number_format_i18n( $row['score'], 1 ) ); ?>%</td>
								<td data-label="<?php esc_attr_e( 'Detail', 'classic-edu-suite' ); ?>">
									<?php echo $exam_id ? esc_html( CES_Cbt_Attempt::format_duration( $row['time'] ) ) : esc_html( (int) $row['passes'] ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
