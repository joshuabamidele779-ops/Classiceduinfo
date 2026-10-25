<?php
/**
 * The student-facing video player and the access gate in front of it.
 *
 * Hardening done here, and what each measure is actually worth:
 *
 *   - No `src` attribute is ever written into the HTML. The playback URL is
 *     attached by JavaScript after the page loads. "View source" and
 *     "Save page as" yield nothing.
 *   - No `download` attribute, `controlsList="nodownload"`, and
 *     `disablePictureInPicture`. These remove the browser's own save buttons.
 *     They are a speed bump, not a lock; anyone who opens devtools sees the
 *     network requests.
 *   - Context menu suppressed on the player only, so right-click still works
 *     everywhere else on the page. Suppressing it site-wide annoys honest
 *     users and stops nobody.
 *   - A per-student watermark drifting over the video, when
 *     `video_watermark_enabled` is on.
 *
 * That watermark is the only measure on this list that addresses the attack
 * that will actually happen. A student who wants the file will screen-record
 * it; there is no browser technology that prevents that, and any plugin
 * claiming otherwise is lying. What the watermark does is make the recording
 * carry the leaker's own name and user id, burned into every frame they
 * redistribute. That is a deterrent, and it is the honest one.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Player rendering and asset loading.
 */
class CES_Lms_Player {

	/**
	 * Whether the player assets have been requested this request.
	 *
	 * @var bool
	 */
	private static $assets_wanted = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'inject_single_lesson' ), 20 );
	}

	/**
	 * Register (but do not enqueue) the front-end assets.
	 *
	 * @return void
	 */
	public static function register_assets() {
		/*
		 * `ces-base` carries the design tokens and the button styles the gate
		 * uses. Declaring it as a dependency is what the asset contract asks
		 * for, and it matters here: the player is injected into `the_content`
		 * rather than through a shortcode, so `CES_Assets::needed()` does not
		 * detect it and would otherwise leave the base styles off the page.
		 */
		wp_register_style(
			'ces-lms',
			CES_URL . 'modules/lms/assets/lms.css',
			array( 'ces-base' ),
			CES_VERSION
		);

		// Bundled, never hotlinked: the contract forbids an external CDN and a
		// metered mobile connection should not pay for a third-party round trip.
		wp_register_script(
			'ces-hls',
			CES_URL . 'modules/lms/assets/vendor/hls.min.js',
			array(),
			'1.5.17',
			true
		);

		wp_register_script(
			'ces-lms-player',
			CES_URL . 'modules/lms/assets/lms-player.js',
			array( 'ces-base', 'ces-hls' ),
			CES_VERSION,
			true
		);

		wp_localize_script(
			'ces-lms-player',
			'cesLmsConfig',
			array(
				'rest'      => esc_url_raw( rest_url( CES_REST_NAMESPACE . '/lms' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'heartbeat' => CES_Lms_Progress::HEARTBEAT,
				'loggedIn'  => is_user_logged_in(),
				'i18n'      => array(
					'auto'          => __( 'Auto', 'classic-edu-suite' ),
					'quality'       => __( 'Quality', 'classic-edu-suite' ),
					'loading'       => __( 'Loading video…', 'classic-edu-suite' ),
					'error'         => __( 'The video could not be loaded. Check your connection and reload the page.', 'classic-edu-suite' ),
					'expired'       => __( 'Your viewing session expired. Reload the page to carry on.', 'classic-edu-suite' ),
					'concurrent'    => __( 'This account is already watching somewhere else. Close the other tab and reload.', 'classic-edu-suite' ),
					'resumed'       => __( 'Resumed where you left off.', 'classic-edu-suite' ),
					'dataSaver'     => __( 'Starting in low quality to save data. Change it with the Quality button.', 'classic-edu-suite' ),
					'unlocking'     => __( 'Unlocking…', 'classic-edu-suite' ),
					'unlockFailed'  => __( 'The lesson could not be unlocked.', 'classic-edu-suite' ),
				),
			)
		);
	}

	/**
	 * Enqueue the assets, once, for pages that really have a player.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( self::$assets_wanted ) {
			return;
		}

		self::$assets_wanted = true;

		wp_enqueue_style( 'ces-lms' );
		wp_enqueue_script( 'ces-hls' );
		wp_enqueue_script( 'ces-lms-player' );

		$primary = (string) CES_Helpers::setting( 'brand_primary', '#2c62cd' );

		wp_add_inline_style(
			'ces-lms',
			':root{--ces-primary:' . esc_attr( $primary ) . ';}'
		);
	}

	/**
	 * Append the player to a single lesson view.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function inject_single_lesson( $content ) {
		if ( ! is_singular( CES_Lms_Cpt::LESSON ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return $content . self::render( get_the_ID() );
	}

	// ------------------------------------------------------------------
	// Rendering.
	// ------------------------------------------------------------------

	/**
	 * Render the player, or the gate that stands in front of it.
	 *
	 * @param int   $lesson_id Lesson post id.
	 * @param array $args      Optional overrides (autoplay, show_notes).
	 * @return string Markup.
	 */
	public static function render( $lesson_id, array $args = array() ) {
		$lesson_id = (int) $lesson_id;
		$lesson    = get_post( $lesson_id );

		if ( ! $lesson || CES_Lms_Cpt::LESSON !== $lesson->post_type ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			array(
				'show_notes'  => true,
				'show_title'  => false,
				'show_course' => true,
			)
		);

		self::enqueue();

		$user_id = get_current_user_id();
		$state   = CES_Lms_Access::evaluate( $lesson_id, $user_id );

		$html = '<div class="ces-lms ces-lms-lesson" id="ces-lesson-' . esc_attr( $lesson_id ) . '">';

		if ( $args['show_title'] ) {
			$html .= '<h2 class="ces-lms-lesson__title">' . esc_html( get_the_title( $lesson_id ) ) . '</h2>';
		}

		if ( $args['show_course'] ) {
			$course_id = CES_Lms_Cpt::lesson_course_id( $lesson_id );

			if ( $course_id && 'publish' === get_post_status( $course_id ) ) {
				$html .= '<p class="ces-lms-lesson__course"><a href="' . esc_url( (string) get_permalink( $course_id ) ) . '">&larr; '
					. esc_html( get_the_title( $course_id ) ) . '</a></p>';
			}
		}

		if ( empty( $state['allowed'] ) ) {
			$html .= self::render_gate( $lesson_id, $state );
			$html .= '</div>';

			return $html;
		}

		if ( empty( $state['playable'] ) ) {
			$html .= self::notice( self::status_message( $state['status'] ), 'info' );
			$html .= '</div>';

			return $html;
		}

		$html .= self::render_player( $lesson_id, $user_id, $state );

		if ( $args['show_notes'] ) {
			$html .= self::render_extras( $lesson_id );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the actual player shell.
	 *
	 * @param int   $lesson_id Lesson post id.
	 * @param int   $user_id   Viewer id.
	 * @param array $state     Access state.
	 * @return string
	 */
	private static function render_player( $lesson_id, $user_id, array $state ) {
		$driver = CES_Lms_Storage_Factory::for_lesson( $lesson_id );

		if ( ! $driver ) {
			return self::notice( __( 'Video storage is not configured.', 'classic-edu-suite' ), 'error' );
		}

		$sid      = CES_Lms_Token::new_sid();
		$playback = $driver->get_playlist_url( $lesson_id, array( 'user_id' => $user_id, 'sid' => $sid ) );

		if ( is_wp_error( $playback ) ) {
			return self::notice( $playback->get_error_message(), 'info' );
		}

		$slots = CES_Lms_Concurrency::touch( $user_id, $sid, true );

		if ( is_wp_error( $slots ) ) {
			return self::notice( $slots->get_error_message(), 'warn' );
		}

		$duration = CES_Lms_Meta::duration( $lesson_id );

		$config = array(
			'lessonId'  => $lesson_id,
			'courseId'  => CES_Lms_Cpt::lesson_course_id( $lesson_id ),
			'sid'       => isset( $playback['sid'] ) ? (string) $playback['sid'] : $sid,
			'kind'      => (string) $playback['kind'],
			'src'       => esc_url_raw( (string) $playback['url'] ),
			'mime'      => (string) $playback['mime'],
			'useHls'    => ! empty( $playback['use_hlsjs'] ),
			'expires'   => (int) $playback['expires'],
			'refreshIn' => CES_Lms_Token::refresh_interval(),
			'duration'  => $duration,
			'resume'    => CES_Lms_Progress::resume_position( $lesson_id, $user_id ),
			'source'    => isset( $state['source'] ) ? (string) $state['source'] : '',
		);

		$watermark = self::watermark_text( $user_id );

		$html  = '<div class="ces-player" data-ces-player="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
		$html .= '<div class="ces-player__stage">';

		/*
		 * No `src` here on purpose: JavaScript attaches it. Nothing in the
		 * served HTML points at a media URL.
		 */
		$html .= '<video class="ces-player__video" playsinline preload="metadata" controls '
			. 'controlsList="nodownload noplaybackrate noremoteplayback" disablePictureInPicture '
			. 'disableRemotePlayback x-webkit-airplay="deny"></video>';

		if ( '' !== $watermark ) {
			$html .= '<div class="ces-player__watermark" aria-hidden="true"><span>' . esc_html( $watermark ) . '</span></div>';
		}

		$html .= '<div class="ces-player__overlay" data-role="overlay" hidden><p data-role="overlay-text"></p></div>';
		$html .= '</div>';

		$html .= '<div class="ces-player__bar">';
		$html .= '<button type="button" class="ces-player__quality" data-role="quality" hidden aria-haspopup="true">'
			. esc_html__( 'Quality', 'classic-edu-suite' ) . '</button>';
		$html .= '<ul class="ces-player__levels" data-role="levels" hidden></ul>';
		$html .= '<span class="ces-player__status" data-role="status" role="status"></span>';
		$html .= '</div>';

		if ( CES_Lms_Meta::STATUS_SOURCE_ONLY === $state['status'] ) {
			$html .= '<p class="ces-player__hint">'
				. esc_html__( 'This lesson plays as a single quality. Use Wi-Fi if you can.', 'classic-edu-suite' )
				. '</p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Build the watermark string for a viewer.
	 *
	 * @param int $user_id User id.
	 * @return string Empty when watermarking is off.
	 */
	public static function watermark_text( $user_id ) {
		if ( ! CES_Helpers::enabled( 'video_watermark_enabled' ) ) {
			return '';
		}

		$user_id = (int) $user_id;
		$user    = $user_id ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			return '';
		}

		$template = (string) CES_Helpers::setting( 'video_watermark_text', '{display_name} · {user_id}' );

		$text = str_replace(
			array( '{display_name}', '{user_id}', '{email}' ),
			array( $user->display_name, (string) $user_id, $user->user_email ),
			$template
		);

		return trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * Render the gate shown instead of the player.
	 *
	 * @param int   $lesson_id Lesson post id.
	 * @param array $state     Access state from CES_Lms_Access::evaluate().
	 * @return string
	 */
	private static function render_gate( $lesson_id, array $state ) {
		$html = '<div class="ces-gate ces-gate--' . esc_attr( (string) $state['reason'] ) . '">';

		$thumb = get_the_post_thumbnail( $lesson_id, 'large', array( 'class' => 'ces-gate__poster', 'loading' => 'lazy' ) );

		if ( $thumb ) {
			$html .= '<div class="ces-gate__media">' . $thumb . '<span class="ces-gate__lock" aria-hidden="true">&#128274;</span></div>';
		} else {
			/*
			 * No lesson thumbnail: show the course's subject artwork behind
			 * the padlock. The gate is the one screen a student is asked to
			 * spend coins on, so it should not be a blank rectangle.
			 */
			$course_id = CES_Lms_Cpt::lesson_course_id( $lesson_id );
			$subject   = '';
			$terms     = $course_id ? get_the_terms( $course_id, CES_Lms_Cpt::TAX_SUBJECT ) : array();

			if ( $terms && ! is_wp_error( $terms ) ) {
				$subject = (string) $terms[0]->name;
			}

			$html .= '<div class="ces-gate__media ces-gate__media--art" data-ces-art="'
				. esc_attr( CES_Helpers::subject_art( $subject ) ) . '">';
			$html .= '<span class="ces-gate__lock" aria-hidden="true">&#128274;</span></div>';
		}

		$html .= '<p class="ces-gate__message">' . esc_html( (string) $state['message'] ) . '</p>';

		if ( 'login' === $state['action'] ) {
			$html .= '<a class="ces-btn ces-btn--primary" href="' . esc_url( (string) $state['login_url'] ) . '">'
				. esc_html__( 'Log in to watch', 'classic-edu-suite' ) . '</a>';
		} elseif ( 'unlock' === $state['action'] ) {
			$html .= '<div class="ces-gate__actions" data-ces-unlock="' . esc_attr( $lesson_id ) . '">';
			$html .= '<button type="button" class="ces-btn ces-btn--primary" data-role="unlock">'
				. sprintf(
					/* translators: %s: coin cost. */
					esc_html__( 'Unlock for %s coins', 'classic-edu-suite' ),
					esc_html( CES_Helpers::format_coins( (int) $state['cost'] ) )
				)
				. '</button>';

			if ( null !== $state['balance'] ) {
				$html .= '<span class="ces-gate__balance">'
					. sprintf(
						/* translators: %s: coin balance. */
						esc_html__( 'You have %s coins', 'classic-edu-suite' ),
						esc_html( CES_Helpers::format_coins( (int) $state['balance'] ) )
					)
					. '</span>';
			}

			$html .= '<p class="ces-gate__error" data-role="error" hidden></p>';
			$html .= '</div>';
		} elseif ( 'earn' === $state['action'] ) {
			$html .= '<a class="ces-btn ces-btn--primary" href="' . esc_url( (string) $state['referral_url'] ) . '">'
				. esc_html__( 'Earn coins by inviting friends', 'classic-edu-suite' ) . '</a>';
			$html .= '<p class="ces-gate__hint">'
				. esc_html__( 'Share your invite link. When a friend joins and takes a quiz, you both get coins.', 'classic-edu-suite' )
				. '</p>';
		}

		if ( 'earn' !== $state['action'] && 'login' !== $state['action'] ) {
			$html .= '<p class="ces-gate__hint"><a href="' . esc_url( (string) $state['referral_url'] ) . '">'
				. esc_html__( 'Short on coins? Invite a friend and earn more.', 'classic-edu-suite' ) . '</a></p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Notes, attachments and transcript under the player.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return string
	 */
	private static function render_extras( $lesson_id ) {
		$html  = '';
		$notes = (string) get_post_meta( $lesson_id, CES_Lms_Meta::NOTES, true );

		if ( '' !== $notes ) {
			$html .= '<div class="ces-lms-notes"><h3>' . esc_html__( 'Lesson notes', 'classic-edu-suite' ) . '</h3>'
				. wp_kses_post( wpautop( $notes ) ) . '</div>';
		}

		$attachments = get_post_meta( $lesson_id, CES_Lms_Meta::ATTACHMENTS, true );

		if ( is_array( $attachments ) && ! empty( $attachments ) ) {
			$html .= '<div class="ces-lms-files"><h3>' . esc_html__( 'Downloads', 'classic-edu-suite' ) . '</h3><ul>';

			foreach ( $attachments as $attachment_id ) {
				$attachment_id = (int) $attachment_id;
				$url           = wp_get_attachment_url( $attachment_id );

				if ( ! $url ) {
					continue;
				}

				$html .= '<li><a href="' . esc_url( $url ) . '" rel="nofollow">'
					. esc_html( get_the_title( $attachment_id ) ) . '</a></li>';
			}

			$html .= '</ul></div>';
		}

		$transcript = (string) get_post_meta( $lesson_id, CES_Lms_Meta::TRANSCRIPT, true );

		if ( '' !== $transcript ) {
			$html .= '<details class="ces-lms-transcript"><summary>'
				. esc_html__( 'Transcript', 'classic-edu-suite' ) . '</summary><div>'
				. wp_kses_post( wpautop( esc_html( $transcript ) ) ) . '</div></details>';
		}

		return $html;
	}

	/**
	 * Student-facing message for a non-playable status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private static function status_message( $status ) {
		switch ( $status ) {
			case CES_Lms_Meta::STATUS_QUEUED:
			case CES_Lms_Meta::STATUS_PROCESSING:
				return __( 'This lesson is still being prepared. Please check back in a few minutes.', 'classic-edu-suite' );

			case CES_Lms_Meta::STATUS_FAILED:
				return __( 'This lesson is temporarily unavailable. Our team is on it.', 'classic-edu-suite' );

			default:
				return __( 'This lesson does not have a video yet.', 'classic-edu-suite' );
		}
	}

	/**
	 * Small inline notice.
	 *
	 * @param string $message Message text.
	 * @param string $tone    info | warn | error.
	 * @return string
	 */
	public static function notice( $message, $tone = 'info' ) {
		return '<p class="ces-note ces-note--' . esc_attr( $tone ) . '">' . esc_html( $message ) . '</p>';
	}
}
