<?php
/**
 * The Studio: everything a consultant does, on the front end.
 *
 * Consultants never see wp-admin. They are tutors, not WordPress
 * administrators, and the Users screen and the Posts list are neither use nor
 * ornament to them — they are a support burden and a security surface. So the
 * whole workspace is one dashboard tab with three views:
 *
 *   **Overview** — their classes, each with its share link, how many students
 *   are in it and how many are waiting to be let in.
 *
 *   **A class** — the share link to paste into WhatsApp, the join code to
 *   read out, the lesson list, the roster, and the live sessions.
 *
 *   **A lesson** — record or upload the video, then the plan, the chapters,
 *   the captions, the checkpoints and the quality review, with Publish at the
 *   bottom.
 *
 * Someone who is not yet a consultant gets the application form instead.
 *
 * The video upload posts to the LMS module's own chunked uploader rather than
 * reimplementing it: that code already resumes after a dropped connection,
 * sniffs the real file type, hands off to whichever storage driver is
 * configured and queues the HLS packaging. Rewriting any of that would be a
 * second place for it to go wrong.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * The consultant's workspace.
 */
class CES_Classroom_Studio {

	/**
	 * Dashboard tab slug.
	 */
	const SLUG = 'studio';

	/**
	 * admin-post action for the consultant application.
	 */
	const ACTION_APPLY = 'ces_consultant_apply';

	/**
	 * Transient prefix for a one-page-load message.
	 */
	const FLASH_KEY = 'ces_studio_flash_';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_dashboard_tabs', array( __CLASS__, 'register_tab' ), 15 );
		add_filter( 'ces_dashboard_quick_links', array( __CLASS__, 'quick_link' ), 15 );

		add_action( 'admin_post_' . self::ACTION_APPLY, array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_APPLY, array( __CLASS__, 'reject_guest' ) );

		add_shortcode( 'ces_consultant_apply', array( __CLASS__, 'apply_shortcode' ) );
		add_shortcode( 'ces_studio', array( __CLASS__, 'studio_shortcode' ) );
	}

	/* ---------------------------------------------------------------------
	 * The dashboard tab.
	 * ------------------------------------------------------------------ */

	/**
	 * Add the Studio tab.
	 *
	 * @param array $tabs Tabs so far.
	 * @return array
	 */
	public static function register_tab( $tabs ) {
		if ( ! is_array( $tabs ) || ! CES_Helpers::enabled( 'classroom_enabled' ) || ! is_user_logged_in() ) {
			return $tabs;
		}

		$user_id = get_current_user_id();

		if ( ! CES_Consultant::is_consultant( $user_id ) ) {
			// Only offer the application to somebody who could take it up.
			if ( ! CES_Consultant::applications_open() ) {
				return $tabs;
			}

			$status = CES_Consultant::status( $user_id );

			if ( in_array( $status, array( 'declined', 'suspended' ), true ) ) {
				return $tabs;
			}

			$tabs[] = array(
				'slug'     => self::SLUG,
				'label'    => 'pending' === $status
					? __( 'Consultant application', 'classic-edu-suite' )
					: __( 'Teach here', 'classic-edu-suite' ),
				'icon'     => '🎓',
				'callback' => array( __CLASS__, 'render' ),
				'position' => 80,
			);

			return $tabs;
		}

		$pending = CES_Classroom_Enrolment::pending_for_consultant( $user_id );

		$tabs[] = array(
			'slug'     => self::SLUG,
			'label'    => $pending > 0
				? sprintf(
					/* translators: %d: number of students waiting. */
					__( 'Studio (%d waiting)', 'classic-edu-suite' ),
					$pending
				)
				: __( 'Studio', 'classic-edu-suite' ),
			'icon'     => '🎬',
			'callback' => array( __CLASS__, 'render' ),
			'position' => 5,
		);

		return $tabs;
	}

	/**
	 * Put waiting students at the top of the Overview links.
	 *
	 * @param array $links Links so far.
	 * @return array
	 */
	public static function quick_link( $links ) {
		if ( ! is_array( $links ) || ! is_user_logged_in() || ! CES_Consultant::is_consultant() ) {
			return $links;
		}

		$pending = CES_Classroom_Enrolment::pending_for_consultant( get_current_user_id() );

		if ( $pending < 1 ) {
			return $links;
		}

		array_unshift(
			$links,
			array(
				'label' => sprintf(
					/* translators: %d: number of students waiting. */
					_n( '%d student is waiting to join your class', '%d students are waiting to join your classes', $pending, 'classic-edu-suite' ),
					$pending
				),
				'url'   => CES_Helpers::dashboard_url( self::SLUG ),
				'icon'  => '⏳',
			)
		);

		return $links;
	}

	/**
	 * `[ces_studio]` — the workspace anywhere.
	 *
	 * @return string
	 */
	public static function studio_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<div class="ces-notice"><p>' . esc_html__( 'Sign in to open your Studio.', 'classic-edu-suite' ) . '</p></div>';
		}

		ob_start();
		self::render();

		return (string) ob_get_clean();
	}

	/**
	 * `[ces_consultant_apply]` — the application form anywhere.
	 *
	 * @return string
	 */
	public static function apply_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<div class="ces-notice"><p>'
				. esc_html__( 'Create a free account first, then apply to teach here.', 'classic-edu-suite' )
				. ' <a href="' . esc_url( CES_Helpers::login_url() ) . '">' . esc_html__( 'Sign in', 'classic-edu-suite' ) . '</a></p></div>';
		}

		if ( CES_Consultant::is_consultant() ) {
			return '<div class="ces-notice ces-notice--success"><p>'
				. esc_html__( 'You are already approved to teach here.', 'classic-edu-suite' )
				. ' <a href="' . esc_url( CES_Helpers::dashboard_url( self::SLUG ) ) . '">' . esc_html__( 'Open your Studio', 'classic-edu-suite' ) . '</a></p></div>';
		}

		return self::render_apply( get_current_user_id() );
	}

	/* ---------------------------------------------------------------------
	 * Routing.
	 * ------------------------------------------------------------------ */

	/**
	 * Render whichever view the query asks for.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		CES_Classroom_Module::enqueue_studio();

		if ( ! CES_Consultant::is_consultant( $user_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo self::render_apply( $user_id );

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view selection.
		$class_id  = isset( $_GET['class'] ) ? absint( $_GET['class'] ) : 0;
		$lesson_id = isset( $_GET['lesson'] ) ? absint( $_GET['lesson'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="ces-studio" id="ces-studio">';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo self::notices( $user_id );

		if ( $lesson_id > 0 && CES_Classroom_Lesson_Tools::can_edit( $lesson_id ) ) {
			self::render_lesson( $lesson_id );
		} elseif ( $class_id > 0 && current_user_can( 'edit_post', $class_id ) && CES_Classroom_Cpt::is_class( $class_id ) ) {
			self::render_class( $class_id );
		} else {
			self::render_overview( $user_id );
		}

		echo '</div>';
	}

	/**
	 * Flash notices.
	 *
	 * @param int $user_id Consultant.
	 * @return string
	 */
	private static function notices( $user_id ) {
		$key   = self::FLASH_KEY . (int) $user_id;
		$flash = get_transient( $key );

		if ( ! is_array( $flash ) ) {
			return '';
		}

		delete_transient( $key );

		$html = '';

		if ( ! empty( $flash['errors'] ) ) {
			$html .= '<div class="ces-notice ces-notice--error"><ul>';

			foreach ( (array) $flash['errors'] as $message ) {
				$html .= '<li>' . esc_html( $message ) . '</li>';
			}

			$html .= '</ul></div>';
		}

		if ( ! empty( $flash['message'] ) ) {
			$html .= '<div class="ces-notice ces-notice--success"><p>' . esc_html( (string) $flash['message'] ) . '</p></div>';
		}

		return $html;
	}

	/**
	 * Stash a message for the next page load.
	 *
	 * @param int   $user_id Consultant.
	 * @param array $data    Payload.
	 * @return void
	 */
	private static function flash( $user_id, array $data ) {
		set_transient( self::FLASH_KEY . (int) $user_id, $data, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * A Studio URL.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, CES_Helpers::dashboard_url( self::SLUG ) );
	}

	/* ---------------------------------------------------------------------
	 * Overview.
	 * ------------------------------------------------------------------ */

	/**
	 * The consultant's classes and headline numbers.
	 *
	 * @param int $user_id Consultant.
	 * @return void
	 */
	public static function render_overview( $user_id ) {
		$stats   = CES_Consultant::stats( $user_id );
		$classes = CES_Classroom_Cpt::for_consultant( $user_id );
		$profile = CES_Consultant::profile( $user_id );

		?>
		<div class="ces-studio__head">
			<h2 class="ces-studio__title"><?php esc_html_e( 'Studio', 'classic-edu-suite' ); ?></h2>
			<p class="ces-studio__lead">
				<?php
				printf(
					/* translators: %s: consultant display name. */
					esc_html__( 'Your classes, %s. Make a class, put a lesson in it, send the link to your students.', 'classic-edu-suite' ),
					esc_html( $profile['name'] )
				);
				?>
			</p>
		</div>

		<ul class="ces-studio__stats">
			<li><strong><?php echo esc_html( number_format_i18n( $stats['classes'] ) ); ?></strong><span><?php esc_html_e( 'classes', 'classic-edu-suite' ); ?></span></li>
			<li><strong><?php echo esc_html( number_format_i18n( $stats['students'] ) ); ?></strong><span><?php esc_html_e( 'students', 'classic-edu-suite' ); ?></span></li>
			<li><strong><?php echo esc_html( number_format_i18n( $stats['lessons'] ) ); ?></strong><span><?php esc_html_e( 'lessons', 'classic-edu-suite' ); ?></span></li>
			<li><strong><?php echo esc_html( number_format_i18n( $stats['minutes'] ) ); ?></strong><span><?php esc_html_e( 'minutes watched', 'classic-edu-suite' ); ?></span></li>
		</ul>

		<div class="ces-studio__panel">
			<h3><?php esc_html_e( 'Start a new class', 'classic-edu-suite' ); ?></h3>
			<p class="ces-studio__hint"><?php esc_html_e( 'One class per group you teach — “SS3 Physics, Saturday morning” rather than “Physics”. Your students join it with a link.', 'classic-edu-suite' ); ?></p>

			<form class="ces-studio__new-class" data-ces-new-class>
				<p class="ces-field">
					<label for="ces-new-class-title"><?php esc_html_e( 'Class name', 'classic-edu-suite' ); ?></label>
					<input type="text" id="ces-new-class-title" name="title" maxlength="120" required
						placeholder="<?php esc_attr_e( 'SS3 Physics — Saturday group', 'classic-edu-suite' ); ?>" />
				</p>
				<p class="ces-field">
					<label for="ces-new-class-desc"><?php esc_html_e( 'What is it for?', 'classic-edu-suite' ); ?></label>
					<textarea id="ces-new-class-desc" name="description" rows="2"
						placeholder="<?php esc_attr_e( 'WAEC and JAMB revision, every Saturday until May.', 'classic-edu-suite' ); ?>"></textarea>
				</p>
				<p>
					<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Create the class', 'classic-edu-suite' ); ?></button>
					<span class="ces-studio__status" data-ces-status></span>
				</p>
			</form>
		</div>

		<?php if ( empty( $classes ) ) : ?>
			<div class="ces-studio__empty">
				<p><?php esc_html_e( 'No classes yet. Make one above — it takes about ten seconds, and nothing is public until you share the link.', 'classic-edu-suite' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>

		<h3 class="ces-studio__section"><?php esc_html_e( 'Your classes', 'classic-edu-suite' ); ?></h3>

		<ul class="ces-studio__classes">
			<?php foreach ( $classes as $class_id ) : ?>
				<?php
				$pending  = CES_Classroom_Enrolment::count( $class_id, CES_Classroom_Enrolment::PENDING );
				$lessons  = count( CES_Classroom_Cpt::lessons( $class_id ) );
				$archived = CES_Classroom_Cpt::is_archived( $class_id );
				?>
				<li class="ces-studio__class<?php echo $archived ? ' is-archived' : ''; ?>">
					<div class="ces-studio__class-main">
						<h4>
							<a href="<?php echo esc_url( self::url( array( 'class' => $class_id ) ) ); ?>"><?php echo esc_html( get_the_title( $class_id ) ); ?></a>
							<?php if ( $archived ) : ?>
								<span class="ces-pill"><?php esc_html_e( 'Archived', 'classic-edu-suite' ); ?></span>
							<?php endif; ?>
						</h4>
						<p class="ces-studio__class-meta">
							<?php
							printf(
								/* translators: 1: student count, 2: lesson count. */
								esc_html__( '%1$s students · %2$s lessons', 'classic-edu-suite' ),
								esc_html( number_format_i18n( CES_Classroom_Enrolment::count( $class_id ) ) ),
								esc_html( number_format_i18n( $lessons ) )
							);
							?>
							<?php if ( $pending > 0 ) : ?>
								· <span class="ces-studio__waiting">
									<?php
									printf(
										/* translators: %d: number waiting. */
										esc_html( _n( '%d waiting', '%d waiting', $pending, 'classic-edu-suite' ) ),
										(int) $pending
									);
									?>
								</span>
							<?php endif; ?>
						</p>
						<p class="ces-studio__code">
							<?php esc_html_e( 'Code', 'classic-edu-suite' ); ?>
							<code><?php echo esc_html( CES_Classroom_Cpt::code( $class_id ) ); ?></code>
						</p>
					</div>
					<div class="ces-studio__class-actions">
						<a class="ces-btn ces-btn--ghost" href="<?php echo esc_url( self::url( array( 'class' => $class_id ) ) ); ?>"><?php esc_html_e( 'Open', 'classic-edu-suite' ); ?></a>
						<button type="button" class="ces-btn ces-btn--ghost" data-ces-copy="<?php echo esc_attr( CES_Classroom_Cpt::share_link( $class_id ) ); ?>">
							<?php esc_html_e( 'Copy join link', 'classic-edu-suite' ); ?>
						</button>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * One class.
	 * ------------------------------------------------------------------ */

	/**
	 * A class: sharing, lessons, roster, sessions, settings.
	 *
	 * @param int $class_id Class.
	 * @return void
	 */
	public static function render_class( $class_id ) {
		$class_id = (int) $class_id;
		$share    = CES_Classroom_Cpt::share_link( $class_id );
		$code     = CES_Classroom_Cpt::code( $class_id );
		$lessons  = CES_Classroom_Access::lesson_cards( $class_id );
		$pending  = CES_Classroom_Enrolment::roster( $class_id, array( 'status' => CES_Classroom_Enrolment::PENDING, 'number' => 100 ) );
		$roster   = CES_Classroom_Enrolment::roster( $class_id, array( 'status' => CES_Classroom_Enrolment::ACTIVE, 'number' => 200 ) );
		$sessions = CES_Classroom_Session::for_class( $class_id, 'any', 20 );

		$wa_text = sprintf(
			/* translators: 1: class title, 2: join link. */
			__( "I have put our lessons online. Join %1\$s here: %2\$s", 'classic-edu-suite' ),
			get_the_title( $class_id ),
			$share
		);

		?>
		<p class="ces-studio__back"><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All classes', 'classic-edu-suite' ); ?></a></p>

		<div class="ces-studio__head">
			<h2 class="ces-studio__title"><?php echo esc_html( get_the_title( $class_id ) ); ?></h2>
			<p class="ces-studio__lead"><?php echo esc_html( (string) get_post_field( 'post_content', $class_id ) ); ?></p>
		</div>

		<div class="ces-studio__panel ces-studio__panel--share" data-ces-class="<?php echo esc_attr( (string) $class_id ); ?>">
			<h3><?php esc_html_e( 'Share it with your students', 'classic-edu-suite' ); ?></h3>

			<p class="ces-studio__share-link">
				<input type="text" readonly value="<?php echo esc_attr( $share ); ?>" data-ces-share aria-label="<?php esc_attr_e( 'Join link', 'classic-edu-suite' ); ?>" />
				<button type="button" class="ces-btn ces-btn--primary" data-ces-copy="<?php echo esc_attr( $share ); ?>"><?php esc_html_e( 'Copy', 'classic-edu-suite' ); ?></button>
			</p>

			<p class="ces-studio__share-row">
				<a class="ces-btn ces-btn--whatsapp" target="_blank" rel="noopener"
					href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( $wa_text ) ); ?>">
					<?php esc_html_e( 'Send on WhatsApp', 'classic-edu-suite' ); ?>
				</a>
				<span class="ces-studio__code"><?php esc_html_e( 'Or read them this code:', 'classic-edu-suite' ); ?> <code><?php echo esc_html( $code ); ?></code></span>
			</p>

			<p class="ces-studio__hint"><?php esc_html_e( 'A student who follows the link joins with one tap. A student who types the code at “Join a class” on their dashboard gets to the same place.', 'classic-edu-suite' ); ?></p>
		</div>

		<?php if ( ! empty( $pending ) ) : ?>
			<div class="ces-studio__panel ces-studio__panel--alert" data-ces-class="<?php echo esc_attr( (string) $class_id ); ?>">
				<h3>
					<?php
					printf(
						/* translators: %d: number waiting. */
						esc_html( _n( '%d student waiting to be let in', '%d students waiting to be let in', count( $pending ), 'classic-edu-suite' ) ),
						count( $pending )
					);
					?>
				</h3>
				<ul class="ces-studio__roster">
					<?php foreach ( $pending as $row ) : ?>
						<li data-ces-member="<?php echo esc_attr( (string) $row['user_id'] ); ?>">
							<span class="ces-studio__roster-name"><?php echo esc_html( $row['user']->display_name ); ?></span>
							<span class="ces-studio__roster-actions">
								<button type="button" class="ces-btn ces-btn--primary" data-ces-member-action="approve"><?php esc_html_e( 'Let in', 'classic-edu-suite' ); ?></button>
								<button type="button" class="ces-btn ces-btn--ghost" data-ces-member-action="block"><?php esc_html_e( 'Refuse', 'classic-edu-suite' ); ?></button>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="ces-studio__panel">
			<h3><?php esc_html_e( 'Lessons', 'classic-edu-suite' ); ?></h3>

			<?php if ( empty( $lessons ) ) : ?>
				<p class="ces-studio__hint"><?php esc_html_e( 'No lessons yet. Add one below: record straight from your browser, or upload a video you already have.', 'classic-edu-suite' ); ?></p>
			<?php else : ?>
				<ol class="ces-studio__lessons">
					<?php foreach ( $lessons as $lesson ) : ?>
						<li>
							<a href="<?php echo esc_url( self::url( array( 'lesson' => $lesson['id'] ) ) ); ?>"><?php echo esc_html( $lesson['title'] ); ?></a>
							<span class="ces-studio__lesson-meta">
								<?php if ( '' !== $lesson['duration'] ) : ?>
									<?php echo esc_html( $lesson['duration'] ); ?> ·
								<?php endif; ?>
								<?php echo esc_html( $lesson['statusLabel'] ); ?>
								<?php if ( 'publish' !== get_post_status( $lesson['id'] ) ) : ?>
									· <em><?php esc_html_e( 'draft', 'classic-edu-suite' ); ?></em>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<form class="ces-studio__new-lesson" data-ces-new-lesson data-ces-class="<?php echo esc_attr( (string) $class_id ); ?>">
				<p class="ces-field">
					<label for="ces-new-lesson-title"><?php esc_html_e( 'New lesson title', 'classic-edu-suite' ); ?></label>
					<input type="text" id="ces-new-lesson-title" name="title" maxlength="140" required
						placeholder="<?php esc_attr_e( 'Solving quadratic equations by factorising', 'classic-edu-suite' ); ?>" />
					<span class="ces-field__hint"><?php esc_html_e( 'Say what the lesson teaches. This is what students choose from.', 'classic-edu-suite' ); ?></span>
				</p>
				<p>
					<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Add lesson', 'classic-edu-suite' ); ?></button>
					<span class="ces-studio__status" data-ces-status></span>
				</p>
			</form>
		</div>

		<?php if ( CES_Helpers::enabled( 'classroom_sessions_enabled' ) ) : ?>
		<div class="ces-studio__panel">
			<h3><?php esc_html_e( 'Live sessions', 'classic-edu-suite' ); ?></h3>
			<p class="ces-studio__hint"><?php esc_html_e( 'A session is your whole class watching one lesson together, with you driving the video and the chat running beside it. You can also paste a Zoom or Meet link if you would rather talk live.', 'classic-edu-suite' ); ?></p>

			<?php if ( ! empty( $sessions ) ) : ?>
				<ul class="ces-studio__sessions">
					<?php foreach ( $sessions as $session_id ) : ?>
						<?php
						$state = CES_Classroom_Session::state( $session_id );
						$start = CES_Classroom_Session::start_at( $session_id );
						?>
						<li>
							<a href="<?php echo esc_url( CES_Classroom_Session::room_url( $session_id ) ); ?>"><?php echo esc_html( get_the_title( $session_id ) ); ?></a>
							<span class="ces-studio__lesson-meta">
								<?php if ( '' !== $start ) : ?>
									<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $start ) ); ?> ·
								<?php endif; ?>
								<?php if ( CES_Classroom_Session::LIVE === $state ) : ?>
									<strong class="ces-studio__live"><?php esc_html_e( 'live now', 'classic-edu-suite' ); ?></strong>
								<?php else : ?>
									<?php echo esc_html( $state ); ?>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<form class="ces-studio__new-session" data-ces-new-session data-ces-class="<?php echo esc_attr( (string) $class_id ); ?>">
				<p class="ces-field">
					<label for="ces-session-title"><?php esc_html_e( 'Session title', 'classic-edu-suite' ); ?></label>
					<input type="text" id="ces-session-title" name="title" maxlength="140"
						placeholder="<?php esc_attr_e( 'Saturday revision — waves', 'classic-edu-suite' ); ?>" />
				</p>
				<p class="ces-field">
					<label for="ces-session-lesson"><?php esc_html_e( 'Lesson to watch together', 'classic-edu-suite' ); ?></label>
					<select id="ces-session-lesson" name="lesson_id">
						<option value="0"><?php esc_html_e( 'None — talk only', 'classic-edu-suite' ); ?></option>
						<?php foreach ( $lessons as $lesson ) : ?>
							<option value="<?php echo esc_attr( (string) $lesson['id'] ); ?>"><?php echo esc_html( $lesson['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="ces-field">
					<label for="ces-session-start"><?php esc_html_e( 'When', 'classic-edu-suite' ); ?></label>
					<input type="datetime-local" id="ces-session-start" name="start" />
				</p>
				<p class="ces-field">
					<label for="ces-session-meet"><?php esc_html_e( 'Zoom or Meet link (optional)', 'classic-edu-suite' ); ?></label>
					<input type="url" id="ces-session-meet" name="meet_url" placeholder="https://" />
				</p>
				<p>
					<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Schedule it', 'classic-edu-suite' ); ?></button>
					<span class="ces-studio__status" data-ces-status></span>
				</p>
			</form>
		</div>
		<?php endif; ?>

		<div class="ces-studio__panel">
			<h3>
				<?php
				printf(
					/* translators: %d: number of students. */
					esc_html__( 'Roster (%d)', 'classic-edu-suite' ),
					count( $roster )
				);
				?>
			</h3>

			<?php if ( empty( $roster ) ) : ?>
				<p class="ces-studio__hint"><?php esc_html_e( 'Nobody has joined yet. Send the link above.', 'classic-edu-suite' ); ?></p>
			<?php else : ?>
				<table class="ces-studio__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Student', 'classic-edu-suite' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Lessons done', 'classic-edu-suite' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Minutes', 'classic-edu-suite' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last here', 'classic-edu-suite' ); ?></th>
							<th scope="col"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $roster as $row ) : ?>
							<tr data-ces-member="<?php echo esc_attr( (string) $row['user_id'] ); ?>" data-ces-class="<?php echo esc_attr( (string) $class_id ); ?>">
								<td><?php echo esc_html( $row['user']->display_name ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['lessons_done'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['minutes_watched'] ) ); ?></td>
								<td>
									<?php
									echo esc_html(
										empty( $row['last_seen_at'] )
											? __( 'never', 'classic-edu-suite' )
											: sprintf(
												/* translators: %s: human time difference. */
												__( '%s ago', 'classic-edu-suite' ),
												human_time_diff( (int) strtotime( (string) $row['last_seen_at'] . ' UTC' ) )
											)
									);
									?>
								</td>
								<td>
									<?php if ( (int) $row['user_id'] !== CES_Classroom_Cpt::owner( $class_id ) ) : ?>
										<button type="button" class="ces-link-btn" data-ces-member-action="remove"><?php esc_html_e( 'Remove', 'classic-edu-suite' ); ?></button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php self::render_class_settings( $class_id ); ?>
		<?php
	}

	/**
	 * The class settings panel.
	 *
	 * @param int $class_id Class.
	 * @return void
	 */
	private static function render_class_settings( $class_id ) {
		$public   = CES_Classroom_Cpt::is_public( $class_id );
		$approve  = CES_Classroom_Cpt::needs_approval( $class_id );
		$chat     = CES_Classroom_Cpt::chat_enabled( $class_id );
		$archived = CES_Classroom_Cpt::is_archived( $class_id );
		$welcome  = (string) get_post_meta( $class_id, CES_Classroom_Cpt::WELCOME, true );
		?>
		<details class="ces-studio__panel ces-studio__settings">
			<summary><?php esc_html_e( 'Class settings', 'classic-edu-suite' ); ?></summary>

			<form data-ces-class-settings data-ces-class="<?php echo esc_attr( (string) $class_id ); ?>">
				<p class="ces-field ces-field--check">
					<label>
						<input type="checkbox" name="approval" <?php checked( $approve, true ); ?> />
						<?php esc_html_e( 'I let students in myself', 'classic-edu-suite' ); ?>
					</label>
					<span class="ces-field__hint"><?php esc_html_e( 'On, you approve each one. Off, anyone with the link is in immediately — fine for a group you trust, risky for a link that gets forwarded.', 'classic-edu-suite' ); ?></span>
				</p>

				<?php if ( CES_Helpers::enabled( 'classroom_allow_public' ) ) : ?>
					<p class="ces-field ces-field--check">
						<label>
							<input type="checkbox" name="visibility" <?php checked( $public, true ); ?> />
							<?php esc_html_e( 'List this class publicly', 'classic-edu-suite' ); ?>
						</label>
						<span class="ces-field__hint"><?php esc_html_e( 'It appears in the tutor directory so students can find you. Off, only people with the link or code can see it.', 'classic-edu-suite' ); ?></span>
					</p>
				<?php endif; ?>

				<p class="ces-field ces-field--check">
					<label>
						<input type="checkbox" name="chat" <?php checked( $chat, true ); ?> />
						<?php esc_html_e( 'Give this class a chat room', 'classic-edu-suite' ); ?>
					</label>
					<span class="ces-field__hint"><?php esc_html_e( 'Your students can ask questions between lessons. You see everything in it, and so do site moderators.', 'classic-edu-suite' ); ?></span>
				</p>

				<p class="ces-field">
					<label for="ces-class-welcome"><?php esc_html_e( 'What a student sees when they join', 'classic-edu-suite' ); ?></label>
					<textarea id="ces-class-welcome" name="welcome" rows="3" maxlength="600"><?php echo esc_textarea( $welcome ); ?></textarea>
				</p>

				<p class="ces-field ces-field--check">
					<label>
						<input type="checkbox" name="archived" <?php checked( $archived, true ); ?> />
						<?php esc_html_e( 'Archive this class', 'classic-edu-suite' ); ?>
					</label>
					<span class="ces-field__hint"><?php esc_html_e( 'Closes it to new students and stops the chat. Everything stays where it is, and your students keep their lessons.', 'classic-edu-suite' ); ?></span>
				</p>

				<p>
					<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Save settings', 'classic-edu-suite' ); ?></button>
					<button type="button" class="ces-link-btn" data-ces-new-code><?php esc_html_e( 'Change the join code', 'classic-edu-suite' ); ?></button>
					<span class="ces-studio__status" data-ces-status></span>
				</p>
			</form>
		</details>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * One lesson.
	 * ------------------------------------------------------------------ */

	/**
	 * The lesson workbench.
	 *
	 * @param int $lesson_id Lesson.
	 * @return void
	 */
	public static function render_lesson( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$class_id  = CES_Classroom_Access::lesson_class( $lesson_id );
		$review    = CES_Classroom_Lesson_Tools::review( $lesson_id );
		$plan      = CES_Classroom_Lesson_Tools::plan( $lesson_id );
		$status    = class_exists( 'CES_Lms_Meta' ) ? CES_Lms_Meta::status( $lesson_id ) : '';
		$published = 'publish' === get_post_status( $lesson_id );

		?>
		<p class="ces-studio__back">
			<a href="<?php echo esc_url( $class_id ? self::url( array( 'class' => $class_id ) ) : self::url() ); ?>">
				&larr; <?php echo esc_html( $class_id ? get_the_title( $class_id ) : __( 'All classes', 'classic-edu-suite' ) ); ?>
			</a>
		</p>

		<div class="ces-studio__head">
			<h2 class="ces-studio__title"><?php echo esc_html( get_the_title( $lesson_id ) ); ?></h2>
			<p class="ces-studio__lead">
				<?php echo esc_html( class_exists( 'CES_Lms_Meta' ) ? CES_Lms_Meta::status_label( $status ) : '' ); ?>
				<?php if ( ! $published ) : ?>
					· <em><?php esc_html_e( 'not published yet', 'classic-edu-suite' ); ?></em>
				<?php endif; ?>
			</p>
		</div>

		<div class="ces-studio__lesson" data-ces-lesson="<?php echo esc_attr( (string) $lesson_id ); ?>">

			<?php self::render_review( $review ); ?>

			<?php CES_Classroom_Recorder::render( $lesson_id ); ?>

			<details class="ces-studio__panel" open>
				<summary><?php esc_html_e( '1. Plan the lesson', 'classic-edu-suite' ); ?></summary>
				<p class="ces-studio__hint"><?php esc_html_e( 'Ten minutes here saves an hour of recording. The objective and the recap are shown to students; the rest is yours.', 'classic-edu-suite' ); ?></p>

				<form data-ces-plan>
					<?php foreach ( CES_Classroom_Lesson_Tools::plan_fields() as $key => $field ) : ?>
						<p class="ces-field">
							<label for="ces-plan-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
							<textarea id="ces-plan-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
								rows="<?php echo esc_attr( (string) $field['rows'] ); ?>"><?php echo esc_textarea( $plan[ $key ] ); ?></textarea>
							<span class="ces-field__hint"><?php echo esc_html( $field['hint'] ); ?></span>
						</p>
					<?php endforeach; ?>

					<p>
						<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Save the plan', 'classic-edu-suite' ); ?></button>
						<span class="ces-studio__status" data-ces-status></span>
					</p>
				</form>
			</details>

			<details class="ces-studio__panel">
				<summary><?php esc_html_e( '2. Chapters', 'classic-edu-suite' ); ?></summary>
				<p class="ces-studio__hint"><?php esc_html_e( 'One per line, time first — exactly how you would write them on YouTube. Students get buttons that jump straight there.', 'classic-edu-suite' ); ?></p>

				<form data-ces-chapters>
					<p class="ces-field">
						<label class="screen-reader-text" for="ces-chapters"><?php esc_html_e( 'Chapters', 'classic-edu-suite' ); ?></label>
						<textarea id="ces-chapters" name="text" rows="8" placeholder="<?php echo esc_attr( "0:00 What we are doing today\n1:20 The rule\n4:05 Worked example\n9:30 Common mistakes\n12:00 Recap" ); ?>"><?php echo esc_textarea( CES_Classroom_Lesson_Tools::chapters_text( $lesson_id ) ); ?></textarea>
					</p>
					<p>
						<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Save chapters', 'classic-edu-suite' ); ?></button>
						<span class="ces-studio__status" data-ces-status></span>
					</p>
				</form>
			</details>

			<details class="ces-studio__panel">
				<summary><?php esc_html_e( '3. Captions', 'classic-edu-suite' ); ?></summary>
				<p class="ces-studio__hint"><?php esc_html_e( 'Paste a .vtt or .srt file if you have one. If not, paste what you said as plain text and the Studio will spread the timings across the video — rough, but far better than nothing for a student watching with the sound off.', 'classic-edu-suite' ); ?></p>

				<form data-ces-captions>
					<p class="ces-field">
						<label class="screen-reader-text" for="ces-captions"><?php esc_html_e( 'Captions', 'classic-edu-suite' ); ?></label>
						<textarea id="ces-captions" name="text" rows="10"><?php echo esc_textarea( CES_Classroom_Lesson_Tools::vtt( $lesson_id ) ); ?></textarea>
					</p>
					<p>
						<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Save captions', 'classic-edu-suite' ); ?></button>
						<span class="ces-studio__status" data-ces-status></span>
					</p>
				</form>
			</details>

			<?php if ( CES_Classroom_Lesson_Tools::checkpoints_enabled() ) : ?>
			<details class="ces-studio__panel">
				<summary><?php esc_html_e( '4. Checkpoints', 'classic-edu-suite' ); ?></summary>
				<p class="ces-studio__hint"><?php esc_html_e( 'A question that stops the video and waits. One or two per lesson, right after you have explained something. You see which ones students get wrong.', 'classic-edu-suite' ); ?></p>

				<div data-ces-checkpoints
					data-ces-points="<?php echo esc_attr( (string) wp_json_encode( CES_Classroom_Lesson_Tools::checkpoints( $lesson_id, true ) ) ); ?>">
					<div data-ces-checkpoint-list></div>
					<p>
						<button type="button" class="ces-btn ces-btn--ghost" data-ces-add-checkpoint><?php esc_html_e( 'Add a checkpoint', 'classic-edu-suite' ); ?></button>
						<button type="button" class="ces-btn ces-btn--primary" data-ces-save-checkpoints><?php esc_html_e( 'Save checkpoints', 'classic-edu-suite' ); ?></button>
						<span class="ces-studio__status" data-ces-status></span>
					</p>
				</div>
			</details>
			<?php endif; ?>

			<div class="ces-studio__panel ces-studio__publish">
				<h3><?php esc_html_e( '5. Publish', 'classic-edu-suite' ); ?></h3>
				<p class="ces-studio__hint"><?php esc_html_e( 'Published lessons appear in the class for your students. Nobody outside the class can see them either way.', 'classic-edu-suite' ); ?></p>
				<p>
					<button type="button" class="ces-btn ces-btn--primary" data-ces-publish="<?php echo $published ? '0' : '1'; ?>">
						<?php echo $published ? esc_html__( 'Unpublish', 'classic-edu-suite' ) : esc_html__( 'Publish to my class', 'classic-edu-suite' ); ?>
					</button>
					<a class="ces-btn ces-btn--ghost" href="<?php echo esc_url( (string) get_permalink( $lesson_id ) ); ?>"><?php esc_html_e( 'Preview as a student', 'classic-edu-suite' ); ?></a>
					<span class="ces-studio__status" data-ces-status></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * The quality review panel.
	 *
	 * @param array $review Review payload.
	 * @return void
	 */
	private static function render_review( array $review ) {
		$score = (int) $review['score'];
		$tone  = $score >= 90 ? 'ok' : ( $score >= 60 ? 'wait' : 'bad' );
		?>
		<div class="ces-studio__panel ces-studio__review" data-ces-review>
			<div class="ces-studio__score ces-studio__score--<?php echo esc_attr( $tone ); ?>">
				<strong><?php echo esc_html( (string) $score ); ?></strong>
				<span><?php esc_html_e( 'out of 100', 'classic-edu-suite' ); ?></span>
			</div>

			<div class="ces-studio__review-body">
				<h3><?php esc_html_e( 'Lesson check', 'classic-edu-suite' ); ?></h3>
				<p class="ces-studio__verdict"><?php echo esc_html( (string) $review['verdict'] ); ?></p>

				<ul class="ces-studio__checks">
					<?php foreach ( (array) $review['checks'] as $check ) : ?>
						<li class="<?php echo empty( $check['pass'] ) ? 'is-todo' : 'is-done'; ?>">
							<span class="ces-studio__check-mark" aria-hidden="true"><?php echo empty( $check['pass'] ) ? '○' : '✔'; ?></span>
							<span class="ces-studio__check-label"><?php echo esc_html( (string) $check['label'] ); ?></span>
							<?php if ( empty( $check['pass'] ) ) : ?>
								<span class="ces-studio__check-advice"><?php echo esc_html( (string) $check['advice'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * The application.
	 * ------------------------------------------------------------------ */

	/**
	 * The consultant application form, or its current state.
	 *
	 * @param int $user_id Applicant.
	 * @return string
	 */
	public static function render_apply( $user_id ) {
		$status  = CES_Consultant::status( $user_id );
		$profile = CES_Consultant::profile( $user_id, true );

		if ( 'pending' === $status ) {
			return '<div class="ces-studio"><div class="ces-studio__panel"><h3>'
				. esc_html__( 'Your application is with us', 'classic-edu-suite' ) . '</h3><p>'
				. esc_html__( 'A member of staff reads every application. You will get an email either way, usually within a day or two.', 'classic-edu-suite' )
				. '</p></div></div>';
		}

		if ( 'declined' === $status || 'suspended' === $status ) {
			$reason = $profile['reason'];

			return '<div class="ces-studio"><div class="ces-studio__panel"><h3>'
				. esc_html__( 'About your consultant account', 'classic-edu-suite' ) . '</h3><p>'
				. esc_html( 'suspended' === $status
					? __( 'Your consultant account is paused. Please contact the school.', 'classic-edu-suite' )
					: __( 'This application was not approved.', 'classic-edu-suite' ) )
				. '</p>' . ( '' !== $reason ? '<p><em>' . esc_html( $reason ) . '</em></p>' : '' ) . '</div></div>';
		}

		if ( ! CES_Consultant::applications_open() ) {
			return '<div class="ces-studio"><div class="ces-studio__panel"><p>'
				. esc_html__( 'We are not taking new consultants at the moment.', 'classic-edu-suite' )
				. '</p></div></div>';
		}

		$user = get_userdata( $user_id );

		ob_start();
		?>
		<div class="ces-studio">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo self::notices( $user_id );
			?>

			<div class="ces-studio__head">
				<h2 class="ces-studio__title"><?php esc_html_e( 'Teach your own students here', 'classic-edu-suite' ); ?></h2>
				<p class="ces-studio__lead"><?php esc_html_e( 'If you tutor, you can host your video lessons on this site and share a private link with your own students. They watch on their phones, you see who watched what, and the videos cannot be downloaded or passed around.', 'classic-edu-suite' ); ?></p>
			</div>

			<div class="ces-studio__panel">
				<h3><?php esc_html_e( 'What you get', 'classic-edu-suite' ); ?></h3>
				<ul class="ces-studio__sell">
					<li><?php esc_html_e( 'Record straight from your browser, or upload what you already have.', 'classic-edu-suite' ); ?></li>
					<li><?php esc_html_e( 'A private class with a join link and a six-character code.', 'classic-edu-suite' ); ?></li>
					<li><?php esc_html_e( 'Protected streaming — no downloadable file, and every student’s name on the picture.', 'classic-edu-suite' ); ?></li>
					<li><?php esc_html_e( 'Live sessions where your whole class watches together while you drive.', 'classic-edu-suite' ); ?></li>
					<li><?php esc_html_e( 'A chat room for your class, and a register of who turned up.', 'classic-edu-suite' ); ?></li>
					<li><?php esc_html_e( 'Tools that make the lesson better: plans, chapters, captions, questions that stop the video.', 'classic-edu-suite' ); ?></li>
				</ul>
			</div>

			<div class="ces-studio__panel">
				<h3><?php esc_html_e( 'Apply', 'classic-edu-suite' ); ?></h3>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ces-studio__apply">
					<?php wp_nonce_field( self::ACTION_APPLY . '_' . $user_id ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_APPLY ); ?>" />
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( CES_Helpers::dashboard_url( self::SLUG ) ); ?>" />

					<p class="ces-field">
						<label for="ces-c-name"><?php esc_html_e( 'The name your students will see', 'classic-edu-suite' ); ?></label>
						<input type="text" id="ces-c-name" name="name" required maxlength="80"
							value="<?php echo esc_attr( '' !== $profile['name'] ? $profile['name'] : ( $user ? $user->display_name : '' ) ); ?>" />
					</p>

					<p class="ces-field">
						<label for="ces-c-subjects"><?php esc_html_e( 'Subjects you teach', 'classic-edu-suite' ); ?></label>
						<input type="text" id="ces-c-subjects" name="subjects" required maxlength="160"
							placeholder="<?php esc_attr_e( 'Physics, Further Maths', 'classic-edu-suite' ); ?>"
							value="<?php echo esc_attr( $profile['subjects'] ); ?>" />
					</p>

					<p class="ces-field">
						<label for="ces-c-org"><?php esc_html_e( 'School, centre or company', 'classic-edu-suite' ); ?> <span class="ces-field__optional"><?php esc_html_e( 'optional', 'classic-edu-suite' ); ?></span></label>
						<input type="text" id="ces-c-org" name="org" maxlength="120" value="<?php echo esc_attr( $profile['org'] ); ?>" />
					</p>

					<p class="ces-field">
						<label for="ces-c-phone"><?php esc_html_e( 'Phone number', 'classic-edu-suite' ); ?></label>
						<input type="tel" id="ces-c-phone" name="phone" required inputmode="tel"
							placeholder="0803 123 4567" value="<?php echo esc_attr( $profile['phone'] ); ?>" />
						<span class="ces-field__hint"><?php esc_html_e( 'Staff only. Students never see it.', 'classic-edu-suite' ); ?></span>
					</p>

					<p class="ces-field">
						<label for="ces-c-bio"><?php esc_html_e( 'About you, for your students', 'classic-edu-suite' ); ?></label>
						<textarea id="ces-c-bio" name="bio" rows="4" required maxlength="1200"><?php echo esc_textarea( $profile['bio'] ); ?></textarea>
						<span class="ces-field__hint"><?php esc_html_e( 'Who you teach, what you teach, and how long you have been doing it. A couple of sentences is plenty.', 'classic-edu-suite' ); ?></span>
					</p>

					<p class="ces-field">
						<label for="ces-c-exp"><?php esc_html_e( 'Anything else we should know', 'classic-edu-suite' ); ?> <span class="ces-field__optional"><?php esc_html_e( 'optional', 'classic-edu-suite' ); ?></span></label>
						<textarea id="ces-c-exp" name="experience" rows="3" maxlength="1200"><?php echo esc_textarea( $profile['experience'] ); ?></textarea>
						<span class="ces-field__hint"><?php esc_html_e( 'Qualifications, where you have taught, anyone here who knows you. Staff only.', 'classic-edu-suite' ); ?></span>
					</p>

					<p class="ces-field">
						<label for="ces-c-site"><?php esc_html_e( 'Website or social page', 'classic-edu-suite' ); ?> <span class="ces-field__optional"><?php esc_html_e( 'optional', 'classic-edu-suite' ); ?></span></label>
						<input type="url" id="ces-c-site" name="site" placeholder="https://" value="<?php echo esc_attr( $profile['site'] ); ?>" />
					</p>

					<p class="ces-field ces-field--check">
						<label>
							<input type="checkbox" name="terms" value="1" required />
							<?php esc_html_e( 'I will only teach, I will keep what students tell me private, and I understand that staff can read my class chat and watch my lessons.', 'classic-edu-suite' ); ?>
						</label>
					</p>

					<p>
						<button type="submit" class="ces-btn ces-btn--primary ces-btn--block"><?php esc_html_e( 'Send my application', 'classic-edu-suite' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Handle the application post.
	 *
	 * @return void
	 */
	public static function handle_apply() {
		if ( ! is_user_logged_in() ) {
			self::reject_guest();
		}

		$user_id = get_current_user_id();

		check_admin_referer( self::ACTION_APPLY . '_' . $user_id );

		if ( ! CES_Helpers::rate_limit( 'consultant_apply', 6, HOUR_IN_SECONDS ) ) {
			self::flash( $user_id, array( 'errors' => array( __( 'Too many attempts. Please wait a while.', 'classic-edu-suite' ) ) ) );
			self::bounce();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$input = array(
			'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'subjects'   => isset( $_POST['subjects'] ) ? sanitize_text_field( wp_unslash( $_POST['subjects'] ) ) : '',
			'org'        => isset( $_POST['org'] ) ? sanitize_text_field( wp_unslash( $_POST['org'] ) ) : '',
			'phone'      => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'bio'        => isset( $_POST['bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bio'] ) ) : '',
			'experience' => isset( $_POST['experience'] ) ? sanitize_textarea_field( wp_unslash( $_POST['experience'] ) ) : '',
			'site'       => isset( $_POST['site'] ) ? esc_url_raw( wp_unslash( $_POST['site'] ) ) : '',
			'terms'      => ! empty( $_POST['terms'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = CES_Consultant::apply( $user_id, $input );

		if ( is_wp_error( $result ) ) {
			self::flash( $user_id, array( 'errors' => array_values( $result->get_error_messages() ) ) );
			self::bounce();
		}

		self::flash(
			$user_id,
			array(
				'message' => CES_Consultant::is_consultant( $user_id )
					? __( 'You are approved. Make your first class below.', 'classic-edu-suite' )
					: __( 'Thank you — your application is with us.', 'classic-edu-suite' ),
			)
		);

		self::bounce();
	}

	/**
	 * Refuse a logged-out post.
	 *
	 * @return void
	 */
	public static function reject_guest() {
		wp_safe_redirect( CES_Helpers::login_url( CES_Helpers::dashboard_url( self::SLUG ) ) );
		exit;
	}

	/**
	 * Back to the Studio.
	 *
	 * @return void
	 */
	private static function bounce() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$target = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';

		wp_safe_redirect( '' !== $target ? $target : CES_Helpers::dashboard_url( self::SLUG ) );
		exit;
	}
}
