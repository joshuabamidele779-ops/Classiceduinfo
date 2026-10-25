<?php
/**
 * The student-facing pages: a class, joining one, the chat, the directory.
 *
 * `[ces_class]` is the one that matters most, because it is what the share
 * link opens. It has to work for four different visitors at once:
 *
 *   - a member, who wants the lesson list and nothing in the way of it;
 *   - a logged-in student who is not a member, who gets one button;
 *   - somebody waiting to be let in, who needs to know that is what is
 *     happening rather than that it is broken;
 *   - a visitor who is not signed in at all, who needs to see enough of the
 *     class to be worth signing up for and nothing that is private.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end shortcodes and the class page.
 */
class CES_Classroom_Shortcodes {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'ces_class', array( __CLASS__, 'class_page' ) );
		add_shortcode( 'ces_class_join', array( __CLASS__, 'join_form' ) );
		add_shortcode( 'ces_my_classes', array( __CLASS__, 'my_classes' ) );
		add_shortcode( 'ces_chat', array( __CLASS__, 'chat' ) );
		add_shortcode( 'ces_consultant_directory', array( __CLASS__, 'directory' ) );

		add_filter( 'the_content', array( __CLASS__, 'inject_class_page' ), 8 );
		add_filter( 'ces_base_asset_shortcodes', array( __CLASS__, 'asset_shortcodes' ) );
	}

	/**
	 * Tell the shared asset layer about our shortcodes.
	 *
	 * @param array $tags Tags so far.
	 * @return array
	 */
	public static function asset_shortcodes( $tags ) {
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}

		foreach ( array( 'ces_class', 'ces_class_join', 'ces_my_classes', 'ces_chat', 'ces_consultant_directory', 'ces_studio', 'ces_consultant_apply', 'ces_live_room' ) as $tag ) {
			$tags[] = $tag;
		}

		return $tags;
	}

	/**
	 * Put the class page into a class's own URL.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function inject_class_page( $content ) {
		if ( ! is_singular( CES_Classroom_Cpt::CLASS_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return self::render_class( (int) get_queried_object_id() );
	}

	/**
	 * Which class the current request is about.
	 *
	 * @param int $fallback Class ID from the shortcode attribute.
	 * @return int
	 */
	public static function requested_class( $fallback = 0 ) {
		$class_id = (int) $fallback;

		if ( ! $class_id && is_singular( CES_Classroom_Cpt::CLASS_TYPE ) ) {
			$class_id = (int) get_queried_object_id();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only lookup from a shared link.
		if ( ! $class_id && isset( $_GET['class_id'] ) ) {
			$class_id = absint( $_GET['class_id'] );
		}

		if ( ! $class_id && isset( $_GET['ces_class'] ) ) {
			$class_id = CES_Classroom_Cpt::by_code( sanitize_text_field( wp_unslash( $_GET['ces_class'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return CES_Classroom_Cpt::is_class( $class_id ) ? $class_id : 0;
	}

	/* ---------------------------------------------------------------------
	 * The class page.
	 * ------------------------------------------------------------------ */

	/**
	 * `[ces_class]`.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function class_page( $atts = array() ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'ces_class' );

		$class_id = self::requested_class( (int) $atts['id'] );

		if ( ! $class_id ) {
			return self::join_form();
		}

		return self::render_class( $class_id );
	}

	/**
	 * The class page for whoever is looking at it.
	 *
	 * @param int $class_id Class.
	 * @return string
	 */
	public static function render_class( $class_id ) {
		CES_Classroom_Module::enqueue_front();

		$class_id = (int) $class_id;
		$user_id  = get_current_user_id();
		$owner    = CES_Classroom_Cpt::owner( $class_id );
		$tutor    = CES_Consultant::profile( $owner );
		$member   = CES_Classroom_Enrolment::is_member( $class_id, $user_id );
		$status   = $user_id ? CES_Classroom_Enrolment::status( $class_id, $user_id ) : '';
		$lessons  = CES_Classroom_Access::lesson_cards( $class_id, $user_id );
		$archived = CES_Classroom_Cpt::is_archived( $class_id );
		$session  = CES_Classroom_Session::next_for_class( $class_id );

		ob_start();
		?>
		<div class="ces-classpage">

			<header class="ces-classpage__head">
				<p class="ces-classpage__kicker"><?php esc_html_e( 'A class on this site', 'classic-edu-suite' ); ?></p>
				<h1 class="ces-classpage__title"><?php echo esc_html( get_the_title( $class_id ) ); ?></h1>

				<p class="ces-classpage__tutor">
					<?php
					printf(
						/* translators: %s: tutor name. */
						esc_html__( 'Taught by %s', 'classic-edu-suite' ),
						'<strong>' . esc_html( $tutor['name'] ) . '</strong>'
					);
					?>
					<?php if ( '' !== $tutor['subjects'] ) : ?>
						· <?php echo esc_html( $tutor['subjects'] ); ?>
					<?php endif; ?>
				</p>

				<?php $description = (string) get_post_field( 'post_content', $class_id ); ?>
				<?php if ( '' !== trim( $description ) ) : ?>
					<p class="ces-classpage__about"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>

				<p class="ces-classpage__facts">
					<span>
						<?php
						printf(
							/* translators: %s: number of lessons. */
							esc_html( _n( '%s lesson', '%s lessons', count( $lessons ), 'classic-edu-suite' ) ),
							esc_html( number_format_i18n( count( $lessons ) ) )
						);
						?>
					</span>
					<span>
						<?php
						printf(
							/* translators: %s: number of students. */
							esc_html( _n( '%s student', '%s students', CES_Classroom_Enrolment::count( $class_id ), 'classic-edu-suite' ) ),
							esc_html( number_format_i18n( CES_Classroom_Enrolment::count( $class_id ) ) )
						);
						?>
					</span>
				</p>
			</header>

			<?php if ( $archived && ! $member ) : ?>
				<div class="ces-notice"><p><?php esc_html_e( 'This class has finished and is not taking new students.', 'classic-edu-suite' ); ?></p></div>
				<?php
				echo '</div>';

				return (string) ob_get_clean();
			endif;
			?>

			<?php if ( ! $user_id ) : ?>
				<div class="ces-gate">
					<p class="ces-gate__message"><?php esc_html_e( 'Sign in to join this class. It is free.', 'classic-edu-suite' ); ?></p>
					<p class="ces-gate__action">
						<a class="ces-btn ces-btn--primary" href="<?php echo esc_url( CES_Helpers::login_url( CES_Classroom_Cpt::share_link( $class_id ) ) ); ?>">
							<?php esc_html_e( 'Sign in or create an account', 'classic-edu-suite' ); ?>
						</a>
					</p>
					<?php if ( class_exists( 'CES_Accounts_Login' ) ) : ?>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						echo CES_Accounts_Login::button_html( array( 'redirect_to' => CES_Classroom_Cpt::share_link( $class_id ) ) );
						?>
					<?php endif; ?>
				</div>
			<?php elseif ( CES_Classroom_Enrolment::PENDING === $status ) : ?>
				<div class="ces-notice">
					<p><?php esc_html_e( 'You have asked to join. Your tutor will let you in — you will see the lessons here as soon as they do.', 'classic-edu-suite' ); ?></p>
				</div>
			<?php elseif ( CES_Classroom_Enrolment::BLOCKED === $status ) : ?>
				<div class="ces-notice ces-notice--error">
					<p><?php esc_html_e( 'You cannot join this class. Please speak to the tutor.', 'classic-edu-suite' ); ?></p>
				</div>
			<?php elseif ( ! $member ) : ?>
				<div class="ces-classpage__join" data-ces-join="<?php echo esc_attr( CES_Classroom_Cpt::code( $class_id ) ); ?>">
					<p>
						<?php
						echo esc_html(
							CES_Classroom_Cpt::needs_approval( $class_id )
								? __( 'Ask your tutor to let you in, and the lessons appear here.', 'classic-edu-suite' )
								: __( 'Join and the lessons open straight away.', 'classic-edu-suite' )
						);
						?>
					</p>
					<p>
						<button type="button" class="ces-btn ces-btn--primary ces-btn--block" data-ces-join-button>
							<?php
							echo esc_html(
								CES_Classroom_Cpt::needs_approval( $class_id )
									? __( 'Ask to join this class', 'classic-edu-suite' )
									: __( 'Join this class', 'classic-edu-suite' )
							);
							?>
						</button>
					</p>
					<p class="ces-classpage__status" data-ces-join-status></p>
				</div>
			<?php else : ?>

				<?php $welcome = (string) get_post_meta( $class_id, CES_Classroom_Cpt::WELCOME, true ); ?>
				<?php if ( '' !== trim( $welcome ) ) : ?>
					<div class="ces-notice ces-notice--success"><p><?php echo esc_html( $welcome ); ?></p></div>
				<?php endif; ?>

				<?php if ( $session > 0 ) : ?>
					<?php $live = CES_Classroom_Session::LIVE === CES_Classroom_Session::state( $session ); ?>
					<div class="ces-classpage__session<?php echo $live ? ' is-live' : ''; ?>">
						<div>
							<h2><?php echo esc_html( get_the_title( $session ) ); ?></h2>
							<p>
								<?php
								if ( $live ) {
									esc_html_e( 'Happening right now.', 'classic-edu-suite' );
								} else {
									$start = CES_Classroom_Session::start_at( $session );

									echo '' !== $start
										? esc_html(
											sprintf(
												/* translators: %s: date and time. */
												__( 'Next session: %s', 'classic-edu-suite' ),
												mysql2date( get_option( 'date_format' ) . ' H:i', $start )
											)
										)
										: esc_html__( 'A session is planned.', 'classic-edu-suite' );
								}
								?>
							</p>
						</div>
						<a class="ces-btn <?php echo $live ? 'ces-btn--primary' : 'ces-btn--ghost'; ?>" href="<?php echo esc_url( CES_Classroom_Session::room_url( $session ) ); ?>">
							<?php echo $live ? esc_html__( 'Join the room', 'classic-edu-suite' ) : esc_html__( 'Open the room', 'classic-edu-suite' ); ?>
						</a>
					</div>
				<?php endif; ?>

				<h2 class="ces-classpage__section"><?php esc_html_e( 'Lessons', 'classic-edu-suite' ); ?></h2>

				<?php if ( empty( $lessons ) ) : ?>
					<p class="ces-classpage__empty"><?php esc_html_e( 'Your tutor has not put a lesson up yet. Check back shortly.', 'classic-edu-suite' ); ?></p>
				<?php else : ?>
					<ol class="ces-classpage__lessons">
						<?php foreach ( $lessons as $lesson ) : ?>
							<?php if ( 'publish' !== get_post_status( $lesson['id'] ) && ! CES_Classroom_Lesson_Tools::can_edit( $lesson['id'] ) ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<li class="ces-classpage__lesson<?php echo $lesson['completed'] ? ' is-done' : ''; ?>">
								<a href="<?php echo esc_url( $lesson['url'] ); ?>">
									<span class="ces-classpage__lesson-no"><?php echo esc_html( (string) $lesson['position'] ); ?></span>
									<span class="ces-classpage__lesson-body">
										<strong><?php echo esc_html( $lesson['title'] ); ?></strong>
										<span class="ces-classpage__lesson-meta">
											<?php if ( '' !== $lesson['duration'] ) : ?>
												<?php echo esc_html( $lesson['duration'] ); ?>
											<?php endif; ?>
											<?php if ( $lesson['completed'] ) : ?>
												· <?php esc_html_e( 'finished', 'classic-edu-suite' ); ?>
											<?php elseif ( $lesson['watched'] > 0 ) : ?>
												· <?php esc_html_e( 'started', 'classic-edu-suite' ); ?>
											<?php endif; ?>
											<?php if ( ! $lesson['playable'] ) : ?>
												· <em><?php echo esc_html( $lesson['statusLabel'] ); ?></em>
											<?php endif; ?>
										</span>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>

				<?php if ( CES_Classroom_Cpt::chat_enabled( $class_id ) ) : ?>
					<h2 class="ces-classpage__section"><?php esc_html_e( 'Class chat', 'classic-edu-suite' ); ?></h2>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					echo self::render_chat_room( CES_Chat::class_room( $class_id ) );
					?>
				<?php endif; ?>

				<?php if ( $owner !== $user_id ) : ?>
					<p class="ces-classpage__leave">
						<button type="button" class="ces-link-btn" data-ces-leave="<?php echo esc_attr( (string) $class_id ); ?>">
							<?php esc_html_e( 'Leave this class', 'classic-edu-suite' ); ?>
						</button>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Joining.
	 * ------------------------------------------------------------------ */

	/**
	 * `[ces_class_join]` — type a code.
	 *
	 * @return string
	 */
	public static function join_form() {
		CES_Classroom_Module::enqueue_front();

		if ( ! is_user_logged_in() ) {
			return '<div class="ces-gate"><p class="ces-gate__message">'
				. esc_html__( 'Sign in, then type the code your tutor gave you.', 'classic-edu-suite' )
				. '</p><p class="ces-gate__action"><a class="ces-btn ces-btn--primary" href="'
				. esc_url( CES_Helpers::login_url() ) . '">' . esc_html__( 'Sign in', 'classic-edu-suite' ) . '</a></p></div>';
		}

		ob_start();
		?>
		<div class="ces-joinbox" data-ces-joinbox>
			<h3><?php esc_html_e( 'Join a class', 'classic-edu-suite' ); ?></h3>
			<p><?php esc_html_e( 'If your tutor gave you a six-character code, type it here. If they sent you a link, just open the link.', 'classic-edu-suite' ); ?></p>

			<form data-ces-join-form>
				<p class="ces-field">
					<label class="screen-reader-text" for="ces-join-code"><?php esc_html_e( 'Class code', 'classic-edu-suite' ); ?></label>
					<input type="text" id="ces-join-code" name="code" maxlength="10" autocomplete="off"
						class="ces-joinbox__code" placeholder="<?php esc_attr_e( 'ABC123', 'classic-edu-suite' ); ?>" required />
					<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Join', 'classic-edu-suite' ); ?></button>
				</p>
			</form>

			<p class="ces-joinbox__status" data-ces-join-status></p>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * `[ces_my_classes]` — the student's own classes.
	 *
	 * @return string
	 */
	public static function my_classes() {
		CES_Classroom_Module::enqueue_front();

		if ( ! is_user_logged_in() ) {
			return self::join_form();
		}

		$user_id = get_current_user_id();
		$classes = CES_Classroom_Enrolment::classes_for( $user_id );
		$pending = CES_Classroom_Enrolment::classes_for( $user_id, CES_Classroom_Enrolment::PENDING );
		$live    = CES_Classroom_Session::live_for_user( $user_id );

		ob_start();
		?>
		<div class="ces-myclasses">
			<?php if ( ! empty( $live ) ) : ?>
				<?php foreach ( $live as $session_id ) : ?>
					<div class="ces-classpage__session is-live">
						<div>
							<h2><?php echo esc_html( get_the_title( $session_id ) ); ?></h2>
							<p><?php esc_html_e( 'Live right now.', 'classic-edu-suite' ); ?></p>
						</div>
						<a class="ces-btn ces-btn--primary" href="<?php echo esc_url( CES_Classroom_Session::room_url( $session_id ) ); ?>">
							<?php esc_html_e( 'Join', 'classic-edu-suite' ); ?>
						</a>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( empty( $classes ) && empty( $pending ) ) : ?>
				<p class="ces-myclasses__empty"><?php esc_html_e( 'You are not in any class yet. If a tutor has given you a link or a code, use it below.', 'classic-edu-suite' ); ?></p>
			<?php else : ?>
				<ul class="ces-myclasses__list">
					<?php foreach ( $classes as $class_id ) : ?>
						<?php
						$lessons = CES_Classroom_Cpt::lessons( $class_id );
						$tutor   = CES_Consultant::profile( CES_Classroom_Cpt::owner( $class_id ) );
						?>
						<li>
							<a href="<?php echo esc_url( CES_Classroom_Cpt::share_link( $class_id ) ); ?>">
								<strong><?php echo esc_html( get_the_title( $class_id ) ); ?></strong>
								<span>
									<?php
									printf(
										/* translators: 1: tutor name, 2: lesson count. */
										esc_html__( '%1$s · %2$s lessons', 'classic-edu-suite' ),
										esc_html( $tutor['name'] ),
										esc_html( number_format_i18n( count( $lessons ) ) )
									);
									?>
								</span>
							</a>
						</li>
					<?php endforeach; ?>

					<?php foreach ( $pending as $class_id ) : ?>
						<li class="is-pending">
							<a href="<?php echo esc_url( CES_Classroom_Cpt::share_link( $class_id ) ); ?>">
								<strong><?php echo esc_html( get_the_title( $class_id ) ); ?></strong>
								<span><?php esc_html_e( 'waiting for the tutor to let you in', 'classic-edu-suite' ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo self::join_form();
			?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Chat.
	 * ------------------------------------------------------------------ */

	/**
	 * `[ces_chat]` — the open rooms, with a room switcher.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function chat( $atts = array() ) {
		$atts = shortcode_atts( array( 'room' => '' ), $atts, 'ces_chat' );

		CES_Classroom_Module::enqueue_chat();

		if ( ! CES_Helpers::enabled( 'classroom_chat_enabled' ) ) {
			return '';
		}

		if ( ! is_user_logged_in() ) {
			return '<div class="ces-gate"><p class="ces-gate__message">'
				. esc_html__( 'Sign in to talk to other students.', 'classic-edu-suite' )
				. '</p><p class="ces-gate__action"><a class="ces-btn ces-btn--primary" href="'
				. esc_url( CES_Helpers::login_url() ) . '">' . esc_html__( 'Sign in', 'classic-edu-suite' ) . '</a></p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only room selection.
		$wanted = isset( $_GET['room'] ) ? sanitize_text_field( wp_unslash( $_GET['room'] ) ) : (string) $atts['room'];
		$room   = CES_Chat::sanitize_room( $wanted );

		if ( '' === $room ) {
			$room = CES_Chat::lobby_room( 'general' );
		}

		if ( ! CES_Chat::can_read( $room ) ) {
			if ( class_exists( 'CES_Accounts_Status' ) && CES_Accounts_Status::approval_enabled() && ! CES_Accounts_Status::is_approved() ) {
				return '<div class="ces-gate"><p class="ces-gate__message">'
					. esc_html__( 'The student rooms open once your parent or guardian has confirmed your account.', 'classic-edu-suite' )
					. '</p><p class="ces-gate__action"><a class="ces-btn ces-btn--primary" href="'
					. esc_url( CES_Helpers::dashboard_url( 'account' ) ) . '">'
					. esc_html__( 'See what is left to do', 'classic-edu-suite' ) . '</a></p></div>';
			}

			return '<div class="ces-notice"><p>' . esc_html__( 'That room is not open to you.', 'classic-edu-suite' ) . '</p></div>';
		}

		$lobbies = CES_Chat::lobbies();
		$classes = CES_Classroom_Enrolment::classes_for( get_current_user_id() );

		ob_start();
		?>
		<div class="ces-chatpage">
			<nav class="ces-chatpage__rooms" aria-label="<?php esc_attr_e( 'Chat rooms', 'classic-edu-suite' ); ?>">
				<?php if ( CES_Helpers::enabled( 'classroom_lobby_enabled' ) ) : ?>
					<?php foreach ( $lobbies as $slug => $lobby ) : ?>
						<?php $target = CES_Chat::lobby_room( $slug ); ?>
						<a class="ces-chatpage__room<?php echo $target === $room ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( add_query_arg( 'room', $target, remove_query_arg( 'room' ) ) ); ?>">
							<span aria-hidden="true"><?php echo esc_html( (string) $lobby['icon'] ); ?></span>
							<?php echo esc_html( (string) $lobby['label'] ); ?>
						</a>
					<?php endforeach; ?>
				<?php endif; ?>

				<?php foreach ( $classes as $class_id ) : ?>
					<?php
					if ( ! CES_Classroom_Cpt::chat_enabled( $class_id ) ) {
						continue;
					}

					$target = CES_Chat::class_room( $class_id );
					?>
					<a class="ces-chatpage__room<?php echo $target === $room ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( add_query_arg( 'room', $target, remove_query_arg( 'room' ) ) ); ?>">
						<span aria-hidden="true">🏫</span>
						<?php echo esc_html( get_the_title( $class_id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="ces-chatpage__main">
				<?php
				$label = CES_Chat::room_label( $room );
				$blurb = '';
				$parsed = CES_Chat::parse_room( $room );

				if ( 'lobby' === $parsed['type'] && isset( $lobbies[ $parsed['id'] ] ) ) {
					$blurb = (string) $lobbies[ $parsed['id'] ]['blurb'];
				}
				?>

				<div class="ces-chatpage__head">
					<h2><?php echo esc_html( $label ); ?></h2>
					<?php if ( '' !== $blurb ) : ?>
						<p><?php echo esc_html( $blurb ); ?></p>
					<?php endif; ?>
				</div>

				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				echo self::render_chat_room( $room );
				?>

				<details class="ces-chatpage__rules">
					<summary><?php esc_html_e( 'The rules here', 'classic-edu-suite' ); ?></summary>
					<ul>
						<li><?php esc_html_e( 'Every room is read by staff. There are no private messages on this site, and that is on purpose.', 'classic-edu-suite' ); ?></li>
						<li><?php esc_html_e( 'No phone numbers, email addresses or links. Keep the conversation here, where an adult can see it.', 'classic-edu-suite' ); ?></li>
						<li><?php esc_html_e( 'Be decent. Report anything that is not, with the flag on the message — three reports hides it immediately.', 'classic-edu-suite' ); ?></li>
						<li><?php esc_html_e( 'Helpers with a BOT label are automated. They ask questions to get things going; they are not students.', 'classic-edu-suite' ); ?></li>
						<li><?php esc_html_e( 'If an adult you do not know tries to move the conversation somewhere else, tell a member of staff.', 'classic-edu-suite' ); ?></li>
					</ul>
				</details>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The chat widget for one room.
	 *
	 * @param string $room Room string.
	 * @return string
	 */
	public static function render_chat_room( $room ) {
		CES_Classroom_Module::enqueue_chat();

		$room = CES_Chat::sanitize_room( $room );

		if ( '' === $room || ! CES_Chat::can_read( $room ) ) {
			return '';
		}

		$max = max( 20, CES_Helpers::setting_int( 'classroom_chat_max_length', 300 ) );

		ob_start();
		?>
		<div class="ces-chat" data-ces-chat="<?php echo esc_attr( $room ); ?>">
			<ol class="ces-chat__list" data-ces-chat-list aria-live="polite" aria-label="<?php esc_attr_e( 'Messages', 'classic-edu-suite' ); ?>"></ol>

			<?php if ( CES_Chat::can_post( $room ) ) : ?>
				<form class="ces-chat__form" data-ces-chat-form>
					<label class="screen-reader-text" for="ces-chat-input-<?php echo esc_attr( md5( $room ) ); ?>"><?php esc_html_e( 'Message', 'classic-edu-suite' ); ?></label>
					<input type="text" id="ces-chat-input-<?php echo esc_attr( md5( $room ) ); ?>" data-ces-chat-input
						autocomplete="off" maxlength="<?php echo esc_attr( (string) $max ); ?>"
						placeholder="<?php esc_attr_e( 'Say something…', 'classic-edu-suite' ); ?>" />
					<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Send', 'classic-edu-suite' ); ?></button>
				</form>
			<?php else : ?>
				<p class="ces-chat__closed"><?php esc_html_e( 'This room is read-only for you.', 'classic-edu-suite' ); ?></p>
			<?php endif; ?>

			<p class="ces-chat__status" data-ces-chat-status></p>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * The directory.
	 * ------------------------------------------------------------------ */

	/**
	 * `[ces_consultant_directory]` — tutors and their public classes.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function directory( $atts = array() ) {
		$atts = shortcode_atts( array( 'limit' => 24 ), $atts, 'ces_consultant_directory' );

		CES_Classroom_Module::enqueue_front();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		$search = isset( $_GET['tutor_s'] ) ? sanitize_text_field( wp_unslash( $_GET['tutor_s'] ) ) : '';

		$result = CES_Consultant::directory(
			array(
				'search' => $search,
				'number' => max( 1, min( 60, (int) $atts['limit'] ) ),
			)
		);

		ob_start();
		?>
		<div class="ces-directory">
			<form class="ces-directory__search" method="get">
				<label class="screen-reader-text" for="ces-tutor-s"><?php esc_html_e( 'Search tutors', 'classic-edu-suite' ); ?></label>
				<input type="search" id="ces-tutor-s" name="tutor_s" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search by name or subject', 'classic-edu-suite' ); ?>" />
				<button type="submit" class="ces-btn ces-btn--ghost"><?php esc_html_e( 'Search', 'classic-edu-suite' ); ?></button>
			</form>

			<?php if ( empty( $result['users'] ) ) : ?>
				<p class="ces-directory__empty"><?php esc_html_e( 'No tutors listed yet.', 'classic-edu-suite' ); ?></p>
			<?php else : ?>
				<ul class="ces-directory__list">
					<?php foreach ( $result['users'] as $user ) : ?>
						<?php
						$profile = CES_Consultant::profile( (int) $user->ID );
						$public  = array();

						foreach ( CES_Classroom_Cpt::for_consultant( (int) $user->ID, false ) as $class_id ) {
							if ( CES_Classroom_Cpt::is_public( $class_id ) ) {
								$public[] = $class_id;
							}
						}
						?>
						<li class="ces-directory__card">
							<img class="ces-directory__avatar" src="<?php echo esc_url( (string) $profile['avatar'] ); ?>" alt="" width="64" height="64" loading="lazy" />
							<div class="ces-directory__body">
								<h3><?php echo esc_html( $profile['name'] ); ?></h3>
								<?php if ( '' !== $profile['subjects'] ) : ?>
									<p class="ces-directory__subjects"><?php echo esc_html( $profile['subjects'] ); ?></p>
								<?php endif; ?>
								<?php if ( '' !== $profile['org'] ) : ?>
									<p class="ces-directory__org"><?php echo esc_html( $profile['org'] ); ?></p>
								<?php endif; ?>
								<?php if ( '' !== $profile['bio'] ) : ?>
									<p class="ces-directory__bio"><?php echo esc_html( wp_trim_words( $profile['bio'], 34 ) ); ?></p>
								<?php endif; ?>

								<?php if ( ! empty( $public ) ) : ?>
									<ul class="ces-directory__classes">
										<?php foreach ( $public as $class_id ) : ?>
											<li>
												<a href="<?php echo esc_url( CES_Classroom_Cpt::share_link( $class_id ) ); ?>">
													<?php echo esc_html( get_the_title( $class_id ) ); ?>
												</a>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php else : ?>
									<p class="ces-directory__private"><?php esc_html_e( 'Classes by invitation — ask them for a link.', 'classic-edu-suite' ); ?></p>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
