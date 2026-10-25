<?php
/**
 * Group teaching: one scheduled session of a class, and the live room state.
 *
 * ### Why it works this way
 *
 * Real multi-way video — everybody's camera in a grid — needs a media server
 * relaying streams between participants. That cannot run on LiteSpeed shared
 * hosting at any price, and the services that do it well are billed per
 * minute per person. So this module does not pretend to.
 *
 * What it does instead is the thing that actually gets taught well on a
 * Nigerian student's data plan: **everyone watches the same video at the same
 * moment, together, with the tutor driving.** The tutor presses play and
 * every student's player moves with them. Chat runs down the side, hands go
 * up, a poll can stop the room and ask whether that last bit landed. The
 * video itself is the same protected HLS stream the rest of the site serves,
 * so it costs no more to deliver to thirty students than to one.
 *
 * Where a school does have Zoom or Google Meet, a session can carry that
 * link as well: the room becomes the place the class gathers, with the
 * material, the chat and the register, and the call opens in a tab.
 *
 * Synchronisation is deliberately crude and therefore reliable. The host
 * writes `{ position, playing, updated }`; each student computes where the
 * video should be from those three numbers and nudges their own player if it
 * has drifted more than a second and a half. No sockets, no clock
 * negotiation, nothing to go wrong on a flaky connection — a student whose
 * phone drops out for a minute simply catches up on the next poll.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scheduling and running a live session.
 */
class CES_Classroom_Session {

	/**
	 * Scheduled, not started.
	 */
	const SCHEDULED = 'scheduled';

	/**
	 * Running now.
	 */
	const LIVE = 'live';

	/**
	 * Finished.
	 */
	const ENDED = 'ended';

	/**
	 * How far a student's player may drift before it is nudged, in seconds.
	 */
	const DRIFT = 1.5;

	/* ---------------------------------------------------------------------
	 * Creating.
	 * ------------------------------------------------------------------ */

	/**
	 * Schedule a session.
	 *
	 * @param int   $class_id Class.
	 * @param array $args     `title`, `lesson_id`, `start`, `minutes`, `meet_url`.
	 * @return int|WP_Error New session ID.
	 */
	public static function create( $class_id, array $args = array() ) {
		$class_id = (int) $class_id;

		if ( ! CES_Helpers::enabled( 'classroom_sessions_enabled' ) ) {
			return new WP_Error( 'ces_session_off', __( 'Live sessions are switched off on this site.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		if ( ! CES_Classroom_Cpt::is_class( $class_id ) ) {
			return new WP_Error( 'ces_session_class', __( 'That class could not be found.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $class_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'That is not your class.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$title = isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '';

		if ( '' === trim( $title ) ) {
			$title = sprintf(
				/* translators: %s: class title. */
				__( 'Live session — %s', 'classic-edu-suite' ),
				get_the_title( $class_id )
			);
		}

		$session_id = wp_insert_post(
			array(
				'post_type'   => CES_Classroom_Cpt::SESSION,
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => CES_Classroom_Cpt::owner( $class_id ),
			),
			true
		);

		if ( is_wp_error( $session_id ) ) {
			return $session_id;
		}

		$session_id = (int) $session_id;

		update_post_meta( $session_id, CES_Classroom_Cpt::SESSION_CLASS, $class_id );
		update_post_meta( $session_id, CES_Classroom_Cpt::SESSION_STATE, self::SCHEDULED );

		$lesson_id = isset( $args['lesson_id'] ) ? (int) $args['lesson_id'] : 0;

		if ( $lesson_id > 0 && CES_Lms_Cpt::LESSON === get_post_type( $lesson_id ) ) {
			update_post_meta( $session_id, CES_Classroom_Cpt::SESSION_LESSON, $lesson_id );
		}

		$start = isset( $args['start'] ) ? trim( (string) $args['start'] ) : '';

		if ( '' !== $start ) {
			// The browser sends local wall-clock time, and every datetime in
			// this plugin's tables is site-local. Parsing it as UTC and
			// writing it back with gmdate() stores exactly what the tutor
			// typed, whatever the server's own timezone is — and `read_ts()`
			// reads it back the same way.
			$stamp = strtotime( str_replace( 'T', ' ', $start ) . ' UTC' );

			if ( $stamp ) {
				update_post_meta( $session_id, CES_Classroom_Cpt::SESSION_START, gmdate( 'Y-m-d H:i:s', $stamp ) );
			}
		}

		update_post_meta( $session_id, CES_Classroom_Cpt::SESSION_MINUTES, max( 5, min( 480, (int) ( isset( $args['minutes'] ) ? $args['minutes'] : 60 ) ) ) );

		if ( ! empty( $args['meet_url'] ) ) {
			$url = esc_url_raw( (string) $args['meet_url'] );

			if ( '' !== $url ) {
				update_post_meta( $session_id, CES_Classroom_Cpt::MEET_URL, $url );
			}
		}

		return $session_id;
	}

	/* ---------------------------------------------------------------------
	 * Reading.
	 * ------------------------------------------------------------------ */

	/**
	 * The class a session belongs to.
	 *
	 * @param int $session_id Session.
	 * @return int Class ID, 0 when unknown.
	 */
	public static function class_id( $session_id ) {
		$session_id = (int) $session_id;

		if ( CES_Classroom_Cpt::SESSION !== get_post_type( $session_id ) ) {
			return 0;
		}

		return (int) get_post_meta( $session_id, CES_Classroom_Cpt::SESSION_CLASS, true );
	}

	/**
	 * The lesson a session is teaching.
	 *
	 * @param int $session_id Session.
	 * @return int Lesson ID, 0 when there is none.
	 */
	public static function lesson_id( $session_id ) {
		return (int) get_post_meta( (int) $session_id, CES_Classroom_Cpt::SESSION_LESSON, true );
	}

	/**
	 * A session's state.
	 *
	 * A session that was never ended but whose time is long past reads as
	 * ended, so a tutor who closed their laptop does not leave a room
	 * apparently live for a week.
	 *
	 * @param int $session_id Session.
	 * @return string
	 */
	public static function state( $session_id ) {
		$session_id = (int) $session_id;
		$state      = (string) get_post_meta( $session_id, CES_Classroom_Cpt::SESSION_STATE, true );

		if ( ! in_array( $state, array( self::SCHEDULED, self::LIVE, self::ENDED ), true ) ) {
			$state = self::SCHEDULED;
		}

		if ( self::LIVE === $state ) {
			$playback = self::playback( $session_id );
			$minutes  = max( 5, (int) get_post_meta( $session_id, CES_Classroom_Cpt::SESSION_MINUTES, true ) );
			$stale    = ( $minutes * MINUTE_IN_SECONDS ) + ( 2 * HOUR_IN_SECONDS );

			if ( ! empty( $playback['updated'] ) && ( time() - (int) $playback['updated'] ) > $stale ) {
				return self::ENDED;
			}
		}

		return $state;
	}

	/**
	 * Whether this person runs the session.
	 *
	 * @param int $session_id Session.
	 * @param int $user_id    Optional user, defaults to the current one.
	 * @return bool
	 */
	public static function is_host( $session_id, $user_id = 0 ) {
		$session_id = (int) $session_id;
		$user_id    = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		if ( CES_Consultant::is_staff( $user_id ) ) {
			return true;
		}

		$class_id = self::class_id( $session_id );

		return $class_id > 0 && CES_Classroom_Cpt::owner( $class_id ) === $user_id;
	}

	/**
	 * Whether this person may be in the room.
	 *
	 * @param int $session_id Session.
	 * @param int $user_id    Optional user.
	 * @return bool
	 */
	public static function can_attend( $session_id, $user_id = 0 ) {
		$class_id = self::class_id( (int) $session_id );

		if ( ! $class_id ) {
			return false;
		}

		return CES_Classroom_Enrolment::is_member( $class_id, $user_id );
	}

	/**
	 * When a session starts, as a MySQL datetime in site time.
	 *
	 * @param int $session_id Session.
	 * @return string
	 */
	public static function start_at( $session_id ) {
		return (string) get_post_meta( (int) $session_id, CES_Classroom_Cpt::SESSION_START, true );
	}

	/**
	 * "Now" as a scalar comparable with our stored datetimes.
	 *
	 * Site-local times are read as if they were UTC, consistently at both
	 * ends of every comparison, which makes the arithmetic correct whatever
	 * the server and site timezones are.
	 *
	 * @return int
	 */
	private static function local_ts() {
		return (int) strtotime( CES_Helpers::now() . ' UTC' );
	}

	/**
	 * Read one of our stored datetimes as a comparable scalar.
	 *
	 * @param string $datetime MySQL datetime.
	 * @return int 0 when unparseable.
	 */
	private static function read_ts( $datetime ) {
		if ( '' === trim( (string) $datetime ) ) {
			return 0;
		}

		return (int) strtotime( (string) $datetime . ' UTC' );
	}

	/**
	 * The sessions of a class.
	 *
	 * @param int    $class_id Class.
	 * @param string $state    State to match, or 'any'.
	 * @param int    $limit    How many.
	 * @return int[] Session IDs, soonest first.
	 */
	public static function for_class( $class_id, $state = 'any', $limit = 20 ) {
		$args = array(
			'post_type'      => CES_Classroom_Cpt::SESSION,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 100, (int) $limit ) ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => CES_Classroom_Cpt::SESSION_START, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => CES_Classroom_Cpt::SESSION_CLASS,
					'value' => (int) $class_id,
				),
			),
		);

		if ( 'any' !== $state ) {
			$args['meta_query'][] = array(
				'key'   => CES_Classroom_Cpt::SESSION_STATE,
				'value' => sanitize_key( (string) $state ),
			);
		}

		return array_map( 'intval', (array) get_posts( $args ) );
	}

	/**
	 * The next session a class has coming up, or the one running now.
	 *
	 * @param int $class_id Class.
	 * @return int Session ID, 0 when there is none.
	 */
	public static function next_for_class( $class_id ) {
		$live = self::for_class( $class_id, self::LIVE, 1 );

		if ( ! empty( $live ) && self::LIVE === self::state( $live[0] ) ) {
			return (int) $live[0];
		}

		foreach ( self::for_class( $class_id, self::SCHEDULED, 10 ) as $session_id ) {
			$start = self::start_at( $session_id );

			// Anything scheduled for the future, or within the last two
			// hours — a tutor running late is still the next session.
			if ( '' === $start || self::read_ts( $start ) > ( self::local_ts() - ( 2 * HOUR_IN_SECONDS ) ) ) {
				return (int) $session_id;
			}
		}

		return 0;
	}

	/**
	 * Live sessions in any class this student belongs to.
	 *
	 * @param int $user_id Student.
	 * @return int[] Session IDs.
	 */
	public static function live_for_user( $user_id ) {
		$out = array();

		foreach ( CES_Classroom_Enrolment::classes_for( $user_id ) as $class_id ) {
			foreach ( self::for_class( $class_id, self::LIVE, 3 ) as $session_id ) {
				if ( self::LIVE === self::state( $session_id ) ) {
					$out[] = (int) $session_id;
				}
			}
		}

		return $out;
	}

	/**
	 * The room's URL.
	 *
	 * @param int $session_id Session.
	 * @return string
	 */
	public static function room_url( $session_id ) {
		$permalink = get_permalink( (int) $session_id );

		return $permalink ? $permalink : home_url( '/' );
	}

	/* ---------------------------------------------------------------------
	 * Running it.
	 * ------------------------------------------------------------------ */

	/**
	 * Open the room.
	 *
	 * @param int $session_id Session.
	 * @return true|WP_Error
	 */
	public static function start( $session_id ) {
		$session_id = (int) $session_id;

		if ( ! self::is_host( $session_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'Only the tutor can start this session.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		update_post_meta( $session_id, CES_Classroom_Cpt::SESSION_STATE, self::LIVE );

		self::set_playback_raw( $session_id, 0, false );

		$class_id = self::class_id( $session_id );

		CES_Chat::post_system(
			CES_Chat::session_room( $session_id ),
			__( 'The session has started.', 'classic-edu-suite' )
		);

		if ( $class_id > 0 && CES_Classroom_Cpt::chat_enabled( $class_id ) ) {
			CES_Chat::post_system(
				CES_Chat::class_room( $class_id ),
				sprintf(
					/* translators: %1$s: session title, %2$s: room URL. */
					__( 'Live now: %1$s — join at %2$s', 'classic-edu-suite' ),
					get_the_title( $session_id ),
					self::room_url( $session_id )
				)
			);
		}

		/**
		 * Fires when a live session opens.
		 *
		 * @param int $session_id Session.
		 * @param int $class_id   Class.
		 */
		do_action( 'ces_session_started', $session_id, $class_id );

		return true;
	}

	/**
	 * Close the room.
	 *
	 * @param int $session_id Session.
	 * @return true|WP_Error
	 */
	public static function end( $session_id ) {
		$session_id = (int) $session_id;

		if ( ! self::is_host( $session_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'Only the tutor can end this session.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		update_post_meta( $session_id, CES_Classroom_Cpt::SESSION_STATE, self::ENDED );
		self::close_poll( $session_id );
		delete_post_meta( $session_id, CES_Classroom_Cpt::SESSION_HANDS );

		$present = count( self::attendance( $session_id ) );

		CES_Chat::post_system(
			CES_Chat::session_room( $session_id ),
			sprintf(
				/* translators: %d: number of students present. */
				_n( 'Session ended. %d student attended.', 'Session ended. %d students attended.', $present, 'classic-edu-suite' ),
				$present
			)
		);

		/**
		 * Fires when a live session closes.
		 *
		 * @param int $session_id Session.
		 */
		do_action( 'ces_session_ended', $session_id );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Shared playback.
	 * ------------------------------------------------------------------ */

	/**
	 * The shared playback state.
	 *
	 * @param int $session_id Session.
	 * @return array `array( 'position' => float, 'playing' => bool, 'updated' => int )`
	 */
	public static function playback( $session_id ) {
		$stored = CES_Classroom_Cpt::json_meta( (int) $session_id, CES_Classroom_Cpt::SESSION_PLAYBACK );

		return array(
			'position' => isset( $stored['position'] ) ? (float) $stored['position'] : 0.0,
			'playing'  => ! empty( $stored['playing'] ),
			'updated'  => isset( $stored['updated'] ) ? (int) $stored['updated'] : 0,
		);
	}

	/**
	 * Where the video should be right now, for a student joining late.
	 *
	 * @param int $session_id Session.
	 * @return float Seconds.
	 */
	public static function expected_position( $session_id ) {
		$state = self::playback( $session_id );

		if ( ! $state['playing'] || ! $state['updated'] ) {
			return (float) $state['position'];
		}

		return (float) $state['position'] + max( 0, time() - (int) $state['updated'] );
	}

	/**
	 * Write the shared playback state. Host only.
	 *
	 * @param int   $session_id Session.
	 * @param float $position   Seconds into the video.
	 * @param bool  $playing    Whether it is running.
	 * @return true|WP_Error
	 */
	public static function set_playback( $session_id, $position, $playing ) {
		$session_id = (int) $session_id;

		if ( ! self::is_host( $session_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'Only the tutor controls playback.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		self::set_playback_raw( $session_id, $position, $playing );

		return true;
	}

	/**
	 * Write playback without the permission check.
	 *
	 * @param int   $session_id Session.
	 * @param float $position   Seconds.
	 * @param bool  $playing    Whether it is running.
	 * @return void
	 */
	private static function set_playback_raw( $session_id, $position, $playing ) {
		CES_Classroom_Cpt::set_json_meta(
			(int) $session_id,
			CES_Classroom_Cpt::SESSION_PLAYBACK,
			array(
				'position' => max( 0, round( (float) $position, 2 ) ),
				'playing'  => (bool) $playing,
				'updated'  => time(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Hands.
	 * ------------------------------------------------------------------ */

	/**
	 * Raised hands, newest last.
	 *
	 * @param int $session_id Session.
	 * @return array[] Each entry: user_id, name, at, waited.
	 */
	public static function hands( $session_id ) {
		$raw = CES_Classroom_Cpt::json_meta( (int) $session_id, CES_Classroom_Cpt::SESSION_HANDS );
		$out = array();

		foreach ( $raw as $user_id => $at ) {
			$user = get_userdata( (int) $user_id );

			if ( ! $user ) {
				continue;
			}

			$out[] = array(
				'userId' => (int) $user_id,
				'name'   => $user->display_name,
				'at'     => (int) $at,
				'waited' => max( 0, time() - (int) $at ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $a['at'] <=> $b['at'];
			}
		);

		return $out;
	}

	/**
	 * Put a hand up, or take it down.
	 *
	 * @param int  $session_id Session.
	 * @param int  $user_id    Student.
	 * @param bool $up         True to raise, false to lower.
	 * @return true|WP_Error
	 */
	public static function set_hand( $session_id, $user_id, $up = true ) {
		$session_id = (int) $session_id;
		$user_id    = (int) $user_id;

		if ( ! CES_Helpers::enabled( 'classroom_hand_raise' ) ) {
			return new WP_Error( 'ces_session_hands_off', __( 'Hands are switched off in this session.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		if ( ! self::can_attend( $session_id, $user_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'You are not in this class.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$raw = CES_Classroom_Cpt::json_meta( $session_id, CES_Classroom_Cpt::SESSION_HANDS );

		if ( $up ) {
			if ( isset( $raw[ $user_id ] ) ) {
				return true;
			}

			$raw[ $user_id ] = time();
		} else {
			unset( $raw[ $user_id ] );
		}

		CES_Classroom_Cpt::set_json_meta( $session_id, CES_Classroom_Cpt::SESSION_HANDS, $raw );

		return true;
	}

	/**
	 * Take every hand down. Host only.
	 *
	 * @param int $session_id Session.
	 * @return true|WP_Error
	 */
	public static function clear_hands( $session_id ) {
		if ( ! self::is_host( $session_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'Only the tutor can do that.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		delete_post_meta( (int) $session_id, CES_Classroom_Cpt::SESSION_HANDS );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Polls.
	 * ------------------------------------------------------------------ */

	/**
	 * The running poll, if there is one.
	 *
	 * Vote counts are returned to everyone, but who voted for what is not:
	 * the room sees the shape of the answer, not each other's answers.
	 *
	 * @param int $session_id Session.
	 * @param int $user_id    Viewer, to report whether they have voted.
	 * @return array|null
	 */
	public static function poll( $session_id, $user_id = 0 ) {
		$raw = CES_Classroom_Cpt::json_meta( (int) $session_id, CES_Classroom_Cpt::SESSION_POLL );

		if ( empty( $raw['question'] ) || empty( $raw['options'] ) ) {
			return null;
		}

		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$voters  = isset( $raw['voters'] ) && is_array( $raw['voters'] ) ? $raw['voters'] : array();
		$counts  = isset( $raw['counts'] ) && is_array( $raw['counts'] ) ? $raw['counts'] : array();

		$options = array();

		foreach ( (array) $raw['options'] as $index => $label ) {
			$options[] = array(
				'index' => (int) $index,
				'label' => (string) $label,
				'votes' => isset( $counts[ $index ] ) ? (int) $counts[ $index ] : 0,
			);
		}

		return array(
			'question' => (string) $raw['question'],
			'options'  => $options,
			'open'     => ! empty( $raw['open'] ),
			'total'    => array_sum( array_map( 'intval', $counts ) ),
			'voted'    => isset( $voters[ $user_id ] ) ? (int) $voters[ $user_id ] : -1,
			'correct'  => isset( $raw['correct'] ) ? (int) $raw['correct'] : -1,
		);
	}

	/**
	 * Ask the room a question. Host only.
	 *
	 * @param int    $session_id Session.
	 * @param string $question   The question.
	 * @param array  $options    Up to six answers.
	 * @param int    $correct    Index of the right answer, -1 for an opinion poll.
	 * @return true|WP_Error
	 */
	public static function open_poll( $session_id, $question, array $options, $correct = -1 ) {
		$session_id = (int) $session_id;

		if ( ! self::is_host( $session_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'Only the tutor can ask the room.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$question = CES_Chat::strip( $question );

		if ( mb_strlen( $question ) < 3 ) {
			return new WP_Error( 'ces_poll_question', __( 'Type the question first.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$clean = array();

		foreach ( $options as $option ) {
			$option = CES_Chat::strip( $option );

			if ( '' !== $option ) {
				$clean[] = mb_substr( $option, 0, 120 );
			}

			if ( count( $clean ) >= 6 ) {
				break;
			}
		}

		if ( count( $clean ) < 2 ) {
			return new WP_Error( 'ces_poll_options', __( 'Give at least two answers to choose between.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		CES_Classroom_Cpt::set_json_meta(
			$session_id,
			CES_Classroom_Cpt::SESSION_POLL,
			array(
				'question' => mb_substr( $question, 0, 240 ),
				'options'  => $clean,
				'counts'   => array_fill( 0, count( $clean ), 0 ),
				'voters'   => array(),
				'open'     => true,
				'correct'  => ( $correct >= 0 && $correct < count( $clean ) ) ? (int) $correct : -1,
				'opened'   => time(),
			)
		);

		return true;
	}

	/**
	 * Cast a vote.
	 *
	 * @param int $session_id Session.
	 * @param int $user_id    Student.
	 * @param int $index      Option index.
	 * @return array|WP_Error The poll as it now stands.
	 */
	public static function vote( $session_id, $user_id, $index ) {
		$session_id = (int) $session_id;
		$user_id    = (int) $user_id;
		$index      = (int) $index;

		if ( ! self::can_attend( $session_id, $user_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'You are not in this class.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$raw = CES_Classroom_Cpt::json_meta( $session_id, CES_Classroom_Cpt::SESSION_POLL );

		if ( empty( $raw['open'] ) ) {
			return new WP_Error( 'ces_poll_closed', __( 'That poll has closed.', 'classic-edu-suite' ), array( 'status' => 409 ) );
		}

		$options = isset( $raw['options'] ) ? (array) $raw['options'] : array();

		if ( $index < 0 || $index >= count( $options ) ) {
			return new WP_Error( 'ces_poll_option', __( 'Pick one of the answers.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$voters = isset( $raw['voters'] ) && is_array( $raw['voters'] ) ? $raw['voters'] : array();
		$counts = isset( $raw['counts'] ) && is_array( $raw['counts'] ) ? $raw['counts'] : array();

		// One vote each, and changing your mind moves it rather than adding.
		if ( isset( $voters[ $user_id ] ) ) {
			$previous = (int) $voters[ $user_id ];

			if ( $previous === $index ) {
				return self::poll( $session_id, $user_id );
			}

			$counts[ $previous ] = max( 0, (int) ( isset( $counts[ $previous ] ) ? $counts[ $previous ] : 0 ) - 1 );
		}

		$counts[ $index ]   = (int) ( isset( $counts[ $index ] ) ? $counts[ $index ] : 0 ) + 1;
		$voters[ $user_id ] = $index;

		$raw['counts'] = $counts;
		$raw['voters'] = $voters;

		CES_Classroom_Cpt::set_json_meta( $session_id, CES_Classroom_Cpt::SESSION_POLL, $raw );

		return self::poll( $session_id, $user_id );
	}

	/**
	 * Close the poll and announce the result. Host only.
	 *
	 * @param int $session_id Session.
	 * @return true|WP_Error
	 */
	public static function close_poll( $session_id ) {
		$session_id = (int) $session_id;
		$raw        = CES_Classroom_Cpt::json_meta( $session_id, CES_Classroom_Cpt::SESSION_POLL );

		if ( empty( $raw ) ) {
			return true;
		}

		if ( ! self::is_host( $session_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'Only the tutor can close the poll.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$raw['open'] = false;

		CES_Classroom_Cpt::set_json_meta( $session_id, CES_Classroom_Cpt::SESSION_POLL, $raw );

		$poll = self::poll( $session_id );

		if ( $poll && $poll['total'] > 0 ) {
			$best  = null;
			$label = '';

			foreach ( $poll['options'] as $option ) {
				if ( null === $best || $option['votes'] > $best ) {
					$best  = $option['votes'];
					$label = $option['label'];
				}
			}

			CES_Chat::post_system(
				CES_Chat::session_room( $session_id ),
				sprintf(
					/* translators: 1: the most-voted answer, 2: votes for it, 3: total votes. */
					__( 'Poll closed. Most of the room said “%1$s” (%2$d of %3$d).', 'classic-edu-suite' ),
					$label,
					(int) $best,
					(int) $poll['total']
				)
			);
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * The register.
	 * ------------------------------------------------------------------ */

	/**
	 * Note that a student is in the room.
	 *
	 * @param int $session_id Session.
	 * @param int $user_id    Student.
	 * @param int $seconds    Seconds since their last heartbeat.
	 * @return void
	 */
	public static function mark_present( $session_id, $user_id, $seconds = 0 ) {
		$session_id = (int) $session_id;
		$user_id    = (int) $user_id;

		if ( ! $user_id || self::is_host( $session_id, $user_id ) ) {
			return;
		}

		$raw = CES_Classroom_Cpt::json_meta( $session_id, CES_Classroom_Cpt::SESSION_ATTENDANCE );

		// Clamp the claim: a tab left open overnight is not attendance, and
		// the number comes from the browser.
		$seconds = max( 0, min( 120, (int) $seconds ) );

		$raw[ $user_id ] = (int) ( isset( $raw[ $user_id ] ) ? $raw[ $user_id ] : 0 ) + $seconds;

		CES_Classroom_Cpt::set_json_meta( $session_id, CES_Classroom_Cpt::SESSION_ATTENDANCE, $raw );
	}

	/**
	 * The register.
	 *
	 * @param int $session_id Session.
	 * @return array[] Each entry: user_id, name, seconds, minutes.
	 */
	public static function attendance( $session_id ) {
		$raw = CES_Classroom_Cpt::json_meta( (int) $session_id, CES_Classroom_Cpt::SESSION_ATTENDANCE );
		$out = array();

		foreach ( $raw as $user_id => $seconds ) {
			$user = get_userdata( (int) $user_id );

			if ( ! $user ) {
				continue;
			}

			$out[] = array(
				'userId'  => (int) $user_id,
				'name'    => $user->display_name,
				'seconds' => (int) $seconds,
				'minutes' => (int) round( (int) $seconds / 60 ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['seconds'] <=> $a['seconds'];
			}
		);

		return $out;
	}

	/**
	 * How many students are in the room right now.
	 *
	 * @param int $session_id Session.
	 * @return int
	 */
	public static function present_now( $session_id ) {
		$key = 'ces_session_here_' . (int) $session_id;
		$raw = get_transient( $key );

		if ( ! is_array( $raw ) ) {
			return 0;
		}

		$cutoff = time() - ( 3 * MINUTE_IN_SECONDS );
		$count  = 0;

		foreach ( $raw as $seen ) {
			if ( (int) $seen >= $cutoff ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Note that someone's browser is still open on the room.
	 *
	 * Kept in a transient rather than in post meta: it changes every few
	 * seconds per student and it does not matter if it is lost.
	 *
	 * @param int $session_id Session.
	 * @param int $user_id    Student.
	 * @return void
	 */
	public static function mark_here( $session_id, $user_id ) {
		$key = 'ces_session_here_' . (int) $session_id;
		$raw = get_transient( $key );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$raw[ (int) $user_id ] = time();

		$cutoff = time() - ( 10 * MINUTE_IN_SECONDS );

		foreach ( $raw as $id => $seen ) {
			if ( (int) $seen < $cutoff ) {
				unset( $raw[ $id ] );
			}
		}

		set_transient( $key, $raw, 15 * MINUTE_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------
	 * The payload the room polls for.
	 * ------------------------------------------------------------------ */

	/**
	 * Everything the room needs on each poll.
	 *
	 * One request per tick: playback, chat, hands, the poll and who is here.
	 * Four separate endpoints would be four round trips on a connection that
	 * can barely afford one.
	 *
	 * @param int $session_id Session.
	 * @param int $after      Highest chat message the browser already has.
	 * @return array
	 */
	public static function room_state( $session_id, $after = 0 ) {
		$session_id = (int) $session_id;
		$user_id    = get_current_user_id();
		$host       = self::is_host( $session_id, $user_id );
		$state      = self::state( $session_id );

		self::mark_here( $session_id, $user_id );

		$playback = self::playback( $session_id );

		return array(
			'state'     => $state,
			'isHost'    => $host,
			'playback'  => array(
				'position' => self::expected_position( $session_id ),
				'playing'  => (bool) $playback['playing'] && self::LIVE === $state,
				'updated'  => (int) $playback['updated'],
				'drift'    => self::DRIFT,
			),
			'messages'  => CES_Chat::messages( CES_Chat::session_room( $session_id ), $after ),
			'latest'    => CES_Chat::latest_id( CES_Chat::session_room( $session_id ) ),
			'hands'     => $host ? self::hands( $session_id ) : array(),
			'handCount' => count( self::hands( $session_id ) ),
			'myHand'    => self::has_hand( $session_id, $user_id ),
			'poll'      => self::poll( $session_id, $user_id ),
			'present'   => self::present_now( $session_id ),
			'canPost'   => CES_Chat::can_post( CES_Chat::session_room( $session_id ), $user_id ),
		);
	}

	/**
	 * Whether this student's hand is up.
	 *
	 * @param int $session_id Session.
	 * @param int $user_id    Student.
	 * @return bool
	 */
	public static function has_hand( $session_id, $user_id ) {
		$raw = CES_Classroom_Cpt::json_meta( (int) $session_id, CES_Classroom_Cpt::SESSION_HANDS );

		return isset( $raw[ (int) $user_id ] );
	}

	/* ---------------------------------------------------------------------
	 * Reminders.
	 * ------------------------------------------------------------------ */

	/**
	 * Email a class that their session starts soon.
	 *
	 * Called hourly from cron, and sends one reminder per session: the ones
	 * starting within the next hour that have not been reminded yet.
	 *
	 * @return int How many sessions were announced.
	 */
	public static function send_reminders() {
		$sessions = get_posts(
			array(
				'post_type'      => CES_Classroom_Cpt::SESSION,
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => CES_Classroom_Cpt::SESSION_STATE,
						'value' => self::SCHEDULED,
					),
					array(
						'key'     => '_ces_session_reminded',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$sent = 0;

		foreach ( (array) $sessions as $session_id ) {
			$session_id = (int) $session_id;
			$start      = self::start_at( $session_id );

			if ( '' === $start ) {
				continue;
			}

			$stamp = self::read_ts( $start );
			$now   = self::local_ts();

			if ( ! $stamp || $stamp < $now || $stamp > ( $now + HOUR_IN_SECONDS ) ) {
				continue;
			}

			update_post_meta( $session_id, '_ces_session_reminded', time() );

			$class_id = self::class_id( $session_id );

			if ( $class_id > 0 && CES_Classroom_Cpt::chat_enabled( $class_id ) ) {
				CES_Chat::post_system(
					CES_Chat::class_room( $class_id ),
					sprintf(
						/* translators: 1: session title, 2: time. */
						__( 'Reminder: %1$s starts at %2$s.', 'classic-edu-suite' ),
						get_the_title( $session_id ),
						mysql2date( 'H:i', $start )
					)
				);
			}

			++$sent;
		}

		return $sent;
	}
}
