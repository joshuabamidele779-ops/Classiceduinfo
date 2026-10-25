<?php
/**
 * The live room: everyone watching the same thing at the same time.
 *
 * Rendered on a session's own URL, which is a link a tutor can send five
 * minutes before the lesson. Inside it:
 *
 *   - the lesson's video, in the site's ordinary protected player, with the
 *     tutor's play, pause and seek driving everyone else's;
 *   - the chat, down the side on a laptop and underneath on a phone;
 *   - hands, which the tutor sees as a queue in the order they went up;
 *   - a poll the tutor can throw at the room mid-explanation;
 *   - a register, kept automatically from who was actually here.
 *
 * Nobody but a member of the class can open it, and everything on the page
 * talks to one polling endpoint so a room of thirty students on bad data
 * makes one request each every few seconds rather than four.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the live session room.
 */
class CES_Classroom_Room {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'inject' ), 8 );
		add_shortcode( 'ces_live_room', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Put the room into a session's own page.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function inject( $content ) {
		if ( ! is_singular( CES_Classroom_Cpt::SESSION ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return self::render( (int) get_queried_object_id() ) . $content;
	}

	/**
	 * `[ces_live_room session="12"]`.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts( array( 'session' => 0 ), $atts, 'ces_live_room' );

		$session_id = (int) $atts['session'];

		if ( ! $session_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection.
			$session_id = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0;
		}

		return $session_id ? self::render( $session_id ) : '';
	}

	/**
	 * The room.
	 *
	 * @param int $session_id Session.
	 * @return string
	 */
	public static function render( $session_id ) {
		$session_id = (int) $session_id;

		if ( CES_Classroom_Cpt::SESSION !== get_post_type( $session_id ) ) {
			return '';
		}

		$class_id = CES_Classroom_Session::class_id( $session_id );

		if ( ! $class_id ) {
			return '';
		}

		if ( ! is_user_logged_in() ) {
			return '<div class="ces-gate"><p class="ces-gate__message">'
				. esc_html__( 'Sign in to join this session.', 'classic-edu-suite' ) . '</p>'
				. '<p class="ces-gate__action"><a class="ces-btn ces-btn--primary" href="'
				. esc_url( CES_Helpers::login_url( CES_Classroom_Session::room_url( $session_id ) ) ) . '">'
				. esc_html__( 'Sign in', 'classic-edu-suite' ) . '</a></p></div>';
		}

		if ( ! CES_Classroom_Session::can_attend( $session_id ) ) {
			return '<div class="ces-gate">'
				. '<p class="ces-gate__message">' . esc_html__( 'This session is for one tutor’s class.', 'classic-edu-suite' ) . '</p>'
				. '<p class="ces-gate__action"><a class="ces-btn ces-btn--primary" href="'
				. esc_url( CES_Classroom_Cpt::share_link( $class_id ) ) . '">'
				. esc_html__( 'Ask to join the class', 'classic-edu-suite' ) . '</a></p></div>';
		}

		CES_Classroom_Module::enqueue_room();

		$user_id   = get_current_user_id();
		$host      = CES_Classroom_Session::is_host( $session_id, $user_id );
		$state     = CES_Classroom_Session::state( $session_id );
		$lesson_id = CES_Classroom_Session::lesson_id( $session_id );
		$start     = CES_Classroom_Session::start_at( $session_id );
		$meet      = (string) get_post_meta( $session_id, CES_Classroom_Cpt::MEET_URL, true );

		if ( '' === $meet ) {
			$meet = (string) get_post_meta( $class_id, CES_Classroom_Cpt::MEET_URL, true );
		}

		if ( '' === $meet ) {
			$meet = (string) CES_Helpers::setting( 'classroom_session_meet_url', '' );
		}

		if ( $lesson_id > 0 ) {
			CES_Classroom_Access::maybe_grant( $lesson_id, $user_id );
		}

		$config = array(
			'sessionId' => $session_id,
			'classId'   => $class_id,
			'room'      => CES_Chat::session_room( $session_id ),
			'isHost'    => $host,
			'state'     => $state,
			'lessonId'  => $lesson_id,
			'poll'      => max( 2, CES_Helpers::setting_int( 'classroom_session_poll', 4 ) ),
			'sync'      => CES_Helpers::enabled( 'classroom_session_sync' ),
		);

		ob_start();
		?>
		<div class="ces-room" data-ces-room="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">

			<header class="ces-room__head">
				<div>
					<p class="ces-room__kicker">
						<a href="<?php echo esc_url( CES_Classroom_Cpt::share_link( $class_id ) ); ?>"><?php echo esc_html( get_the_title( $class_id ) ); ?></a>
					</p>
					<h2 class="ces-room__title"><?php echo esc_html( get_the_title( $session_id ) ); ?></h2>
					<p class="ces-room__meta">
						<span class="ces-room__state" data-ces-room-state>
							<?php
							if ( CES_Classroom_Session::LIVE === $state ) {
								esc_html_e( 'Live now', 'classic-edu-suite' );
							} elseif ( CES_Classroom_Session::ENDED === $state ) {
								esc_html_e( 'Finished', 'classic-edu-suite' );
							} elseif ( '' !== $start ) {
								printf(
									/* translators: %s: date and time. */
									esc_html__( 'Starts %s', 'classic-edu-suite' ),
									esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $start ) )
								);
							} else {
								esc_html_e( 'Not started yet', 'classic-edu-suite' );
							}
							?>
						</span>
						· <span data-ces-room-present><?php esc_html_e( 'nobody here yet', 'classic-edu-suite' ); ?></span>
					</p>
				</div>

				<?php if ( $host ) : ?>
					<div class="ces-room__host-actions">
						<?php if ( CES_Classroom_Session::LIVE !== $state ) : ?>
							<button type="button" class="ces-btn ces-btn--primary" data-ces-room-start><?php esc_html_e( 'Start the session', 'classic-edu-suite' ); ?></button>
						<?php else : ?>
							<button type="button" class="ces-btn ces-btn--ghost" data-ces-room-end><?php esc_html_e( 'End the session', 'classic-edu-suite' ); ?></button>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</header>

			<?php if ( '' !== $meet ) : ?>
				<p class="ces-room__meet">
					<a class="ces-btn ces-btn--ghost" href="<?php echo esc_url( $meet ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open the video call', 'classic-edu-suite' ); ?>
					</a>
					<span class="ces-room__hint"><?php esc_html_e( 'Talk in the call, watch the lesson here.', 'classic-edu-suite' ); ?></span>
				</p>
			<?php endif; ?>

			<div class="ces-room__grid">

				<div class="ces-room__stage">
					<?php if ( $lesson_id > 0 ) : ?>
						<?php if ( $host && CES_Helpers::enabled( 'classroom_session_sync' ) ) : ?>
							<p class="ces-room__driving"><?php esc_html_e( 'You are driving. Whatever you play, pause or skip to, the class follows.', 'classic-edu-suite' ); ?></p>
						<?php elseif ( CES_Helpers::enabled( 'classroom_session_sync' ) ) : ?>
							<p class="ces-room__driving"><?php esc_html_e( 'Your tutor is driving the video. It will follow them.', 'classic-edu-suite' ); ?></p>
						<?php endif; ?>

						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the player.
						echo CES_Lms_Player::render( $lesson_id, array( 'show_title' => false ) );
						?>
					<?php else : ?>
						<div class="ces-room__novideo">
							<p><?php esc_html_e( 'No lesson video in this session — it is a talking session.', 'classic-edu-suite' ); ?></p>
							<?php if ( '' === $meet ) : ?>
								<p class="ces-room__hint"><?php esc_html_e( 'Use the chat beside this, or ask your tutor for a call link.', 'classic-edu-suite' ); ?></p>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<div class="ces-room__poll" data-ces-room-poll hidden></div>

					<?php if ( $host ) : ?>
						<details class="ces-room__ask">
							<summary><?php esc_html_e( 'Ask the room a question', 'classic-edu-suite' ); ?></summary>
							<form data-ces-room-pollform>
								<p class="ces-field">
									<label for="ces-poll-q"><?php esc_html_e( 'Question', 'classic-edu-suite' ); ?></label>
									<input type="text" id="ces-poll-q" name="question" maxlength="240" required
										placeholder="<?php esc_attr_e( 'What is the unit of pressure?', 'classic-edu-suite' ); ?>" />
								</p>
								<p class="ces-field">
									<label for="ces-poll-o"><?php esc_html_e( 'Answers, one per line', 'classic-edu-suite' ); ?></label>
									<textarea id="ces-poll-o" name="options" rows="4" required
										placeholder="<?php echo esc_attr( "Pascal\nNewton\nJoule\nWatt" ); ?>"></textarea>
								</p>
								<p class="ces-field">
									<label for="ces-poll-c"><?php esc_html_e( 'Which line is right?', 'classic-edu-suite' ); ?></label>
									<input type="number" id="ces-poll-c" name="correct" min="0" max="5"
										placeholder="<?php esc_attr_e( '1 for the first line, blank for an opinion poll', 'classic-edu-suite' ); ?>" />
								</p>
								<p>
									<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Ask it', 'classic-edu-suite' ); ?></button>
									<button type="button" class="ces-btn ces-btn--ghost" data-ces-room-pollclose><?php esc_html_e( 'Close the poll', 'classic-edu-suite' ); ?></button>
								</p>
							</form>
						</details>
					<?php endif; ?>
				</div>

				<aside class="ces-room__side">

					<?php if ( CES_Helpers::enabled( 'classroom_hand_raise' ) ) : ?>
						<div class="ces-room__hands">
							<?php if ( $host ) : ?>
								<h3>
									<?php esc_html_e( 'Hands up', 'classic-edu-suite' ); ?>
									<span class="ces-room__count" data-ces-room-handcount>0</span>
								</h3>
								<ul data-ces-room-handlist class="ces-room__handlist"></ul>
								<button type="button" class="ces-link-btn" data-ces-room-clearhands><?php esc_html_e( 'Clear all hands', 'classic-edu-suite' ); ?></button>
							<?php else : ?>
								<button type="button" class="ces-btn ces-btn--ghost ces-btn--block" data-ces-room-hand>
									<span data-ces-room-handlabel><?php esc_html_e( '✋ Put my hand up', 'classic-edu-suite' ); ?></span>
								</button>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<div class="ces-chat ces-chat--room" data-ces-chat="<?php echo esc_attr( CES_Chat::session_room( $session_id ) ); ?>" data-ces-chat-embedded="1">
						<h3 class="ces-chat__title"><?php esc_html_e( 'Chat', 'classic-edu-suite' ); ?></h3>
						<ol class="ces-chat__list" data-ces-chat-list aria-live="polite"></ol>
						<form class="ces-chat__form" data-ces-chat-form>
							<label class="screen-reader-text" for="ces-room-chat-input"><?php esc_html_e( 'Message', 'classic-edu-suite' ); ?></label>
							<input type="text" id="ces-room-chat-input" data-ces-chat-input autocomplete="off"
								maxlength="<?php echo esc_attr( (string) max( 20, CES_Helpers::setting_int( 'classroom_chat_max_length', 300 ) ) ); ?>"
								placeholder="<?php esc_attr_e( 'Ask a question…', 'classic-edu-suite' ); ?>" />
							<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Send', 'classic-edu-suite' ); ?></button>
						</form>
						<p class="ces-chat__status" data-ces-chat-status></p>
					</div>
				</aside>
			</div>

			<?php if ( $host ) : ?>
				<?php $register = CES_Classroom_Session::attendance( $session_id ); ?>
				<details class="ces-room__register" <?php echo CES_Classroom_Session::ENDED === $state ? 'open' : ''; ?>>
					<summary>
						<?php
						printf(
							/* translators: %d: number of students. */
							esc_html__( 'Register (%d)', 'classic-edu-suite' ),
							count( $register )
						);
						?>
					</summary>

					<?php if ( empty( $register ) ) : ?>
						<p class="ces-room__hint"><?php esc_html_e( 'Nobody has been in the room yet.', 'classic-edu-suite' ); ?></p>
					<?php else : ?>
						<table class="ces-studio__table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Student', 'classic-edu-suite' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Minutes present', 'classic-edu-suite' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $register as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row['name'] ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $row['minutes'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</details>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
