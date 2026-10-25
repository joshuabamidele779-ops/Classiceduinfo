<?php
/**
 * Chat: class rooms, live-session rooms, and the open subject lobbies.
 *
 * One table for every room, keyed by a room string (`class:12`,
 * `session:34`, `lobby:physics`), because the read is identical in all of
 * them: "everything in this room after message N". That is one index hit, and
 * it is what lets the browser poll every few seconds on cheap hosting without
 * websockets.
 *
 * ### The rules, and why they are the defaults
 *
 * This is a site full of secondary-school students, so the room is built for
 * that and not for a general-purpose social network:
 *
 *   - **No private messages.** There is no direct-message feature and no
 *     plan for one. Every message a student writes is in a room an adult can
 *     read, which is the single most effective safeguarding measure there is.
 *   - **No links, emails or phone numbers by default.** The commonest harm in
 *     a student chat is a child being talked into moving the conversation
 *     somewhere nobody is watching. `classroom_chat_allow_links` opens it up
 *     if a school wants that.
 *   - **Slow mode is on.** Five seconds between messages stops both flooding
 *     and the pile-on that a bored class produces.
 *   - **Every message can be reported**, and a moderator sees the queue.
 *   - **Display names only.** No avatars from outside, no profile pages, no
 *     way to work out who a classmate is beyond what the school already shows.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Posting, reading and moderating chat.
 */
class CES_Chat {

	/**
	 * Visible to everyone in the room.
	 */
	const VISIBLE = 'visible';

	/**
	 * Held for a moderator before anyone sees it.
	 */
	const PENDING = 'pending';

	/**
	 * Taken down by a moderator.
	 */
	const HIDDEN = 'hidden';

	/**
	 * A real person wrote it.
	 */
	const KIND_STUDENT = 'student';

	/**
	 * The consultant who owns the class wrote it.
	 */
	const KIND_CONSULTANT = 'consultant';

	/**
	 * A member of site staff wrote it.
	 */
	const KIND_STAFF = 'staff';

	/**
	 * An automated participant. Always badged as such in the room.
	 */
	const KIND_BOT = 'bot';

	/**
	 * The room itself talking: joins, session start, poll results.
	 */
	const KIND_SYSTEM = 'system';

	/**
	 * The messages table.
	 *
	 * @return string
	 */
	public static function table() {
		return CES_Helpers::table( 'chat_messages' );
	}

	/* ---------------------------------------------------------------------
	 * Rooms.
	 * ------------------------------------------------------------------ */

	/**
	 * The room string for a class.
	 *
	 * @param int $class_id Class.
	 * @return string
	 */
	public static function class_room( $class_id ) {
		return 'class:' . (int) $class_id;
	}

	/**
	 * The room string for a live session.
	 *
	 * @param int $session_id Session.
	 * @return string
	 */
	public static function session_room( $session_id ) {
		return 'session:' . (int) $session_id;
	}

	/**
	 * The room string for an open lobby.
	 *
	 * @param string $slug Lobby slug.
	 * @return string
	 */
	public static function lobby_room( $slug ) {
		return 'lobby:' . sanitize_key( (string) $slug );
	}

	/**
	 * Narrow any string to a room we recognise.
	 *
	 * @param string $room Candidate.
	 * @return string Clean room string, or '' when it is not one of ours.
	 */
	public static function sanitize_room( $room ) {
		$room = strtolower( trim( (string) $room ) );

		if ( ! preg_match( '/^(class|session|lobby):([a-z0-9_-]{1,40})$/', $room, $m ) ) {
			return '';
		}

		if ( 'lobby' === $m[1] ) {
			return array_key_exists( $m[2], self::lobbies() ) ? $room : '';
		}

		return (int) $m[2] > 0 ? $m[1] . ':' . (int) $m[2] : '';
	}

	/**
	 * Split a room string into its type and id.
	 *
	 * @param string $room Room string.
	 * @return array `array( 'type' => string, 'id' => int|string )`
	 */
	public static function parse_room( $room ) {
		$room = self::sanitize_room( $room );

		if ( '' === $room ) {
			return array(
				'type' => '',
				'id'   => 0,
			);
		}

		$parts = explode( ':', $room, 2 );

		return array(
			'type' => $parts[0],
			'id'   => 'lobby' === $parts[0] ? $parts[1] : (int) $parts[1],
		);
	}

	/**
	 * The open rooms every approved student may use.
	 *
	 * Subject-shaped on purpose. "Where students talk" with no walls becomes
	 * one enormous room nobody can follow; one room per subject gives a
	 * student a reason to be there and a moderator a hope of keeping up.
	 *
	 * @return array Map of slug => array( label, icon, blurb ).
	 */
	public static function lobbies() {
		$rooms = array(
			'general'   => array(
				'label' => __( 'Common Room', 'classic-edu-suite' ),
				'icon'  => '🪑',
				'blurb' => __( 'Anything and everything. Be decent to each other.', 'classic-edu-suite' ),
			),
			'maths'     => array(
				'label' => __( 'Mathematics', 'classic-edu-suite' ),
				'icon'  => '📐',
				'blurb' => __( 'Stuck on a question? Put it here.', 'classic-edu-suite' ),
			),
			'english'   => array(
				'label' => __( 'English & Literature', 'classic-edu-suite' ),
				'icon'  => '📖',
				'blurb' => __( 'Comprehension, summary, the set texts.', 'classic-edu-suite' ),
			),
			'physics'   => array(
				'label' => __( 'Physics', 'classic-edu-suite' ),
				'icon'  => '🧲',
				'blurb' => __( 'Mechanics, waves, electricity.', 'classic-edu-suite' ),
			),
			'chemistry' => array(
				'label' => __( 'Chemistry', 'classic-edu-suite' ),
				'icon'  => '⚗️',
				'blurb' => __( 'Equations, moles, organic.', 'classic-edu-suite' ),
			),
			'biology'   => array(
				'label' => __( 'Biology', 'classic-edu-suite' ),
				'icon'  => '🧬',
				'blurb' => __( 'Diagrams, systems, genetics.', 'classic-edu-suite' ),
			),
			'exams'     => array(
				'label' => __( 'JAMB, WAEC & NECO', 'classic-edu-suite' ),
				'icon'  => '📝',
				'blurb' => __( 'Timetables, registration, what to expect.', 'classic-edu-suite' ),
			),
			'careers'   => array(
				'label' => __( 'Courses & Careers', 'classic-edu-suite' ),
				'icon'  => '🎯',
				'blurb' => __( 'Cut-off marks, admission, what to study.', 'classic-edu-suite' ),
			),
		);

		/**
		 * Filter the open chat lobbies.
		 *
		 * @param array $rooms Slug => array( label, icon, blurb ).
		 */
		return (array) apply_filters( 'ces_chat_lobbies', $rooms );
	}

	/**
	 * A human label for any room.
	 *
	 * @param string $room Room string.
	 * @return string
	 */
	public static function room_label( $room ) {
		$parsed = self::parse_room( $room );

		if ( 'lobby' === $parsed['type'] ) {
			$lobbies = self::lobbies();

			return isset( $lobbies[ $parsed['id'] ] ) ? (string) $lobbies[ $parsed['id'] ]['label'] : '';
		}

		if ( 'class' === $parsed['type'] ) {
			return (string) get_the_title( (int) $parsed['id'] );
		}

		if ( 'session' === $parsed['type'] ) {
			return (string) get_the_title( (int) $parsed['id'] );
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * Permission.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether someone may read a room.
	 *
	 * @param string $room    Room string.
	 * @param int    $user_id Optional user, defaults to the current one.
	 * @return bool
	 */
	public static function can_read( $room, $user_id = 0 ) {
		$room = self::sanitize_room( $room );

		if ( '' === $room || ! CES_Helpers::enabled( 'classroom_chat_enabled' ) ) {
			return false;
		}

		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		if ( current_user_can( 'ces_moderate_chat' ) ) {
			return true;
		}

		$parsed = self::parse_room( $room );

		if ( 'lobby' === $parsed['type'] ) {
			if ( ! CES_Helpers::enabled( 'classroom_lobby_enabled' ) ) {
				return false;
			}

			// The open rooms are for students who are through the parent
			// approval gate. An unapproved account has nothing to say here
			// yet, and this is the one place on the site where a stranger
			// could talk to a child.
			if ( class_exists( 'CES_Accounts_Status' ) && CES_Accounts_Status::approval_enabled() ) {
				return CES_Accounts_Status::is_approved( $user_id );
			}

			return true;
		}

		if ( 'class' === $parsed['type'] ) {
			return CES_Classroom_Cpt::chat_enabled( (int) $parsed['id'] )
				&& CES_Classroom_Enrolment::is_member( (int) $parsed['id'], $user_id );
		}

		if ( 'session' === $parsed['type'] ) {
			$class_id = CES_Classroom_Session::class_id( (int) $parsed['id'] );

			return $class_id > 0 && CES_Classroom_Enrolment::is_member( $class_id, $user_id );
		}

		return false;
	}

	/**
	 * Whether someone may write in a room.
	 *
	 * @param string $room    Room string.
	 * @param int    $user_id Optional user.
	 * @return bool
	 */
	public static function can_post( $room, $user_id = 0 ) {
		if ( ! self::can_read( $room, $user_id ) ) {
			return false;
		}

		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$parsed  = self::parse_room( $room );

		if ( 'class' === $parsed['type'] && CES_Classroom_Cpt::is_archived( (int) $parsed['id'] ) ) {
			return current_user_can( 'ces_moderate_chat' );
		}

		if ( 'session' === $parsed['type'] ) {
			$session_id = (int) $parsed['id'];

			// Once a session has ended its room is a record, not a chat.
			if ( 'ended' === CES_Classroom_Session::state( $session_id ) ) {
				return CES_Classroom_Session::is_host( $session_id, $user_id );
			}
		}

		return true;
	}

	/**
	 * Which kind of author this person is in this room.
	 *
	 * @param string $room    Room string.
	 * @param int    $user_id User.
	 * @return string One of the KIND_* constants.
	 */
	public static function kind_for( $room, $user_id ) {
		$user_id = (int) $user_id;

		if ( CES_Consultant::is_staff( $user_id ) ) {
			return self::KIND_STAFF;
		}

		$parsed = self::parse_room( $room );

		if ( 'class' === $parsed['type'] && CES_Classroom_Cpt::owner( (int) $parsed['id'] ) === $user_id ) {
			return self::KIND_CONSULTANT;
		}

		if ( 'session' === $parsed['type'] && CES_Classroom_Session::is_host( (int) $parsed['id'], $user_id ) ) {
			return self::KIND_CONSULTANT;
		}

		return self::KIND_STUDENT;
	}

	/* ---------------------------------------------------------------------
	 * Writing.
	 * ------------------------------------------------------------------ */

	/**
	 * Post a message from a real person.
	 *
	 * @param string $room    Room string.
	 * @param int    $user_id Author.
	 * @param string $body    What they typed.
	 * @param int    $reply_to Optional message being replied to.
	 * @return array|WP_Error The stored message, ready for the browser.
	 */
	public static function post( $room, $user_id, $body, $reply_to = 0 ) {
		$room    = self::sanitize_room( $room );
		$user_id = (int) $user_id;

		if ( '' === $room ) {
			return new WP_Error( 'ces_chat_room', __( 'That chat room does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		if ( ! self::can_post( $room, $user_id ) ) {
			return new WP_Error( 'ces_chat_denied', __( 'You cannot post in this room.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$clean = self::clean_body( $body );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$wait = self::slow_mode_wait( $user_id );

		if ( $wait > 0 ) {
			return new WP_Error(
				'ces_chat_slow',
				sprintf(
					/* translators: %d: seconds to wait. */
					_n( 'Wait %d second before posting again.', 'Wait %d seconds before posting again.', $wait, 'classic-edu-suite' ),
					$wait
				),
				array( 'status' => 429 )
			);
		}

		if ( ! CES_Helpers::rate_limit( 'chat_post', 40, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ces_chat_flood', __( 'That is a lot of messages. Take a short break.', 'classic-edu-suite' ), array( 'status' => 429 ) );
		}

		$kind   = self::kind_for( $room, $user_id );
		$status = self::VISIBLE;

		// Pre-moderation holds students only: a consultant teaching a live
		// class cannot wait for a moderator to release each sentence.
		if ( CES_Helpers::enabled( 'classroom_chat_premod' ) && self::KIND_STUDENT === $kind ) {
			$status = self::PENDING;
		}

		$id = self::insert(
			array(
				'room'        => $room,
				'user_id'     => $user_id,
				'author_kind' => $kind,
				'author_name' => '',
				'body'        => $clean,
				'status'      => $status,
				'reply_to'    => max( 0, (int) $reply_to ),
			)
		);

		if ( ! $id ) {
			return new WP_Error( 'ces_chat_store', __( 'That message could not be saved. Try again.', 'classic-edu-suite' ), array( 'status' => 500 ) );
		}

		self::mark_posted( $user_id );

		if ( self::VISIBLE === $status ) {
			self::note_human_message( $room );
		}

		/**
		 * Fires after a person posts a chat message.
		 *
		 * @param int    $id      Message ID.
		 * @param string $room    Room string.
		 * @param int    $user_id Author.
		 */
		do_action( 'ces_chat_posted', $id, $room, $user_id );

		$message = self::get( $id );

		return $message ? self::prepare( $message ) : new WP_Error( 'ces_chat_store', __( 'That message could not be read back.', 'classic-edu-suite' ), array( 'status' => 500 ) );
	}

	/**
	 * Post a message from the room itself.
	 *
	 * @param string $room Room string.
	 * @param string $text Message.
	 * @return int Message ID.
	 */
	public static function post_system( $room, $text ) {
		$room = self::sanitize_room( $room );

		if ( '' === $room ) {
			return 0;
		}

		return self::insert(
			array(
				'room'        => $room,
				'user_id'     => 0,
				'author_kind' => self::KIND_SYSTEM,
				'author_name' => '',
				'body'        => self::strip( $text ),
				'status'      => self::VISIBLE,
				'reply_to'    => 0,
			)
		);
	}

	/**
	 * Post a message from a named automated participant.
	 *
	 * The name is stored on the row and the room badges it, so nobody is ever
	 * led to believe one of these is a classmate.
	 *
	 * @param string $room Room string.
	 * @param string $name Persona name, e.g. "Study Bot".
	 * @param string $text Message.
	 * @return int Message ID.
	 */
	public static function post_bot( $room, $name, $text ) {
		$room = self::sanitize_room( $room );

		if ( '' === $room ) {
			return 0;
		}

		return self::insert(
			array(
				'room'        => $room,
				'user_id'     => 0,
				'author_kind' => self::KIND_BOT,
				'author_name' => substr( sanitize_text_field( (string) $name ), 0, 100 ),
				'body'        => self::strip( $text ),
				'status'      => self::VISIBLE,
				'reply_to'    => 0,
			)
		);
	}

	/**
	 * Insert a row.
	 *
	 * @param array $data Row fields.
	 * @return int New ID, 0 on failure.
	 */
	private static function insert( array $data ) {
		global $wpdb;

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'room'        => (string) $data['room'],
				'user_id'     => (int) $data['user_id'],
				'author_kind' => (string) $data['author_kind'],
				'author_name' => (string) $data['author_name'],
				'body'        => (string) $data['body'],
				'status'      => (string) $data['status'],
				'reply_to'    => (int) $data['reply_to'],
				'ip_address'  => $data['user_id'] ? CES_Helpers::ip() : '',
				'created_at'  => CES_Helpers::now(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/* ---------------------------------------------------------------------
	 * Cleaning what was typed.
	 * ------------------------------------------------------------------ */

	/**
	 * Strip a string back to plain text on one line.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function strip( $text ) {
		$text = wp_strip_all_tags( (string) $text, true );
		$text = preg_replace( '/[\r\n\t]+/', ' ', $text );
		$text = preg_replace( '/\s{2,}/', ' ', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Validate and tidy a message a student typed.
	 *
	 * @param string $body Raw body.
	 * @return string|WP_Error Clean body.
	 */
	public static function clean_body( $body ) {
		$body = self::strip( $body );

		if ( '' === $body ) {
			return new WP_Error( 'ces_chat_empty', __( 'Type something first.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$max = max( 20, CES_Helpers::setting_int( 'classroom_chat_max_length', 300 ) );

		if ( mb_strlen( $body ) > $max ) {
			return new WP_Error(
				'ces_chat_long',
				sprintf(
					/* translators: %d: character limit. */
					__( 'Keep it under %d characters.', 'classic-edu-suite' ),
					$max
				),
				array( 'status' => 400 )
			);
		}

		if ( ! CES_Helpers::enabled( 'classroom_chat_allow_links' ) ) {
			if ( preg_match( '#(https?://|www\.)#i', $body ) ) {
				return new WP_Error(
					'ces_chat_link',
					__( 'Links are not allowed in chat.', 'classic-edu-suite' ),
					array( 'status' => 400 )
				);
			}

			if ( preg_match( '/[\w.+-]+@[\w-]+\.[\w.]+/', $body ) ) {
				return new WP_Error(
					'ces_chat_email',
					__( 'Please do not post email addresses here.', 'classic-edu-suite' ),
					array( 'status' => 400 )
				);
			}

			// Seven or more digits in a row, spaces and dashes ignored, is a
			// phone number however it was typed.
			if ( preg_match( '/(?:\d[\s-]?){7,}/', $body ) ) {
				return new WP_Error(
					'ces_chat_phone',
					__( 'Please do not post phone numbers here. Keep the conversation on the site.', 'classic-edu-suite' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( self::contains_blocked( $body ) ) {
			return new WP_Error(
				'ces_chat_blocked',
				__( 'That message breaks the chat rules. Try saying it another way.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		// SHOUTING. Long all-caps messages read as aggression and they are
		// what a bored class escalates with.
		if ( mb_strlen( $body ) > 14 && $body === mb_strtoupper( $body ) && preg_match( '/[A-Z]{10,}/', $body ) ) {
			$body = self::sentence_case( $body );
		}

		return $body;
	}

	/**
	 * Turn a shouted message back into ordinary text.
	 *
	 * @param string $body Message.
	 * @return string
	 */
	private static function sentence_case( $body ) {
		$lower = mb_strtolower( $body );

		return mb_strtoupper( mb_substr( $lower, 0, 1 ) ) . mb_substr( $lower, 1 );
	}

	/**
	 * The words a message may not contain.
	 *
	 * A deliberately short starting list of the obvious, plus whatever the
	 * school adds in `classroom_chat_blocklist`. No blocklist is ever
	 * complete, which is why reporting and the moderation queue exist: this
	 * catches the lazy cases so a moderator can spend their attention on the
	 * rest.
	 *
	 * @return string[] Lower-cased words.
	 */
	public static function blocked_words() {
		$default = array(
			'fuck',
			'shit',
			'bitch',
			'bastard',
			'cunt',
			'dick',
			'pussy',
			'whore',
			'slut',
			'nigger',
			'faggot',
			'retard',
			'rape',
			'porn',
			'nudes',
			'sex chat',
			'kill yourself',
			'kys',
		);

		$extra = preg_split( '/[,\n]+/', (string) CES_Helpers::setting( 'classroom_chat_blocklist', '' ) );

		foreach ( (array) $extra as $word ) {
			$word = strtolower( trim( (string) $word ) );

			if ( '' !== $word ) {
				$default[] = $word;
			}
		}

		/**
		 * Filter the chat blocklist.
		 *
		 * @param string[] $words Lower-cased words and phrases.
		 */
		return array_values( array_unique( (array) apply_filters( 'ces_chat_blocklist', $default ) ) );
	}

	/**
	 * Whether a message trips the blocklist.
	 *
	 * Letter-for-digit substitutions are folded first, so `sh1t` and `f*ck`
	 * are caught along with the plain spelling.
	 *
	 * @param string $body Message.
	 * @return bool
	 */
	public static function contains_blocked( $body ) {
		$folded = strtolower( (string) $body );
		$folded = strtr( $folded, array( '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '*' => '' ) );
		$folded = preg_replace( '/[^a-z ]+/', '', $folded );
		$folded = ' ' . preg_replace( '/\s+/', ' ', (string) $folded ) . ' ';

		foreach ( self::blocked_words() as $word ) {
			$word = preg_replace( '/[^a-z ]+/', '', strtolower( $word ) );

			if ( '' === $word ) {
				continue;
			}

			// Whole word, so "class" does not match a blocked "ass".
			if ( false !== strpos( $folded, ' ' . $word . ' ' ) ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Slow mode.
	 * ------------------------------------------------------------------ */

	/**
	 * How many seconds this person must still wait.
	 *
	 * @param int $user_id Author.
	 * @return int 0 when they may post now.
	 */
	public static function slow_mode_wait( $user_id ) {
		$seconds = max( 0, CES_Helpers::setting_int( 'classroom_chat_slowmode', 5 ) );

		if ( $seconds <= 0 || current_user_can( 'ces_moderate_chat' ) ) {
			return 0;
		}

		$last = (int) get_transient( 'ces_chat_last_' . (int) $user_id );

		if ( ! $last ) {
			return 0;
		}

		$wait = ( $last + $seconds ) - time();

		return $wait > 0 ? $wait : 0;
	}

	/**
	 * Remember when this person last posted.
	 *
	 * @param int $user_id Author.
	 * @return void
	 */
	private static function mark_posted( $user_id ) {
		$seconds = max( 1, CES_Helpers::setting_int( 'classroom_chat_slowmode', 5 ) );

		set_transient( 'ces_chat_last_' . (int) $user_id, time(), $seconds + 60 );
	}

	/* ---------------------------------------------------------------------
	 * Reading.
	 * ------------------------------------------------------------------ */

	/**
	 * One message row.
	 *
	 * @param int $id Message ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Messages in a room.
	 *
	 * @param string $room  Room string.
	 * @param int    $after Return only messages with a higher ID. 0 for the
	 *                      most recent page.
	 * @param int    $limit How many.
	 * @return array[] Prepared messages, oldest first.
	 */
	public static function messages( $room, $after = 0, $limit = 0 ) {
		global $wpdb;

		$room = self::sanitize_room( $room );

		if ( '' === $room ) {
			return array();
		}

		$limit = $limit > 0 ? $limit : CES_Helpers::setting_int( 'classroom_chat_history', 80 );
		$limit = max( 1, min( 200, (int) $limit ) );
		$table = self::table();
		$after = max( 0, (int) $after );

		// A moderator sees held and hidden messages so they can act on them;
		// everyone else sees the room as it really is, plus their own held
		// message so they are not left wondering where it went.
		//
		// Each branch below is one complete prepare() call. Building a WHERE
		// clause with prepare() and interpolating it into a second prepare()
		// works right up until a value contains a per cent sign, so it is not
		// done here.
		$user_id   = get_current_user_id();
		$moderator = current_user_can( 'ces_moderate_chat' );

		if ( $moderator && $after > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE room = %s AND status <> %s AND id > %d ORDER BY id ASC LIMIT %d",
				$room,
				'deleted',
				$after,
				$limit
			);
		} elseif ( $moderator ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE room = %s AND status <> %s ORDER BY id DESC LIMIT %d",
				$room,
				'deleted',
				$limit
			);
		} elseif ( $after > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE room = %s
				   AND ( status = %s OR ( status = %s AND user_id = %d ) )
				   AND id > %d
				 ORDER BY id ASC LIMIT %d",
				$room,
				self::VISIBLE,
				self::PENDING,
				$user_id,
				$after,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE room = %s
				   AND ( status = %s OR ( status = %s AND user_id = %d ) )
				 ORDER BY id DESC LIMIT %d",
				$room,
				self::VISIBLE,
				self::PENDING,
				$user_id,
				$limit
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		// A descending read is the newest page, so flip it: the list always
		// draws oldest first.
		if ( $after <= 0 ) {
			$rows = array_reverse( (array) $rows );
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = self::prepare( $row );
		}

		return $out;
	}

	/**
	 * The highest message ID in a room.
	 *
	 * @param string $room Room string.
	 * @return int
	 */
	public static function latest_id( $room ) {
		global $wpdb;

		$room = self::sanitize_room( $room );

		if ( '' === $room ) {
			return 0;
		}

		$table = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(MAX(id),0) FROM {$table} WHERE room = %s", $room )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * How many people have said something in a room lately.
	 *
	 * @param string $room    Room string.
	 * @param int    $minutes Window.
	 * @return int
	 */
	public static function active_people( $room, $minutes = 15 ) {
		global $wpdb;

		$room = self::sanitize_room( $room );

		if ( '' === $room ) {
			return 0;
		}

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', (int) strtotime( CES_Helpers::now() . ' UTC' ) - ( max( 1, (int) $minutes ) * MINUTE_IN_SECONDS ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE room = %s AND user_id > 0 AND created_at >= %s",
				$room,
				$since
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Turn a row into the shape the browser gets.
	 *
	 * @param array $row Message row.
	 * @return array
	 */
	public static function prepare( array $row ) {
		$user_id = (int) $row['user_id'];
		$kind    = (string) $row['author_kind'];

		$name = (string) $row['author_name'];

		if ( '' === $name && $user_id > 0 ) {
			$user = get_userdata( $user_id );
			$name = $user ? $user->display_name : __( 'Someone', 'classic-edu-suite' );
		}

		if ( '' === $name ) {
			$name = self::KIND_SYSTEM === $kind ? '' : __( 'Someone', 'classic-edu-suite' );
		}

		$created = (string) $row['created_at'];
		$stamp   = (int) strtotime( $created . ' UTC' );

		return array(
			'id'       => (int) $row['id'],
			'kind'     => $kind,
			'name'     => $name,
			'body'     => (string) $row['body'],
			'status'   => (string) $row['status'],
			'replyTo'  => (int) $row['reply_to'],
			'flags'    => (int) $row['flags'],
			'time'     => $stamp ? date_i18n( 'H:i', $stamp ) : '',
			'ago'      => $stamp ? human_time_diff( $stamp, (int) strtotime( CES_Helpers::now() . ' UTC' ) ) : '',
			'own'      => $user_id > 0 && $user_id === get_current_user_id(),
			'isBot'    => self::KIND_BOT === $kind,
			'isSystem' => self::KIND_SYSTEM === $kind,
			'userId'   => $user_id,
		);
	}

	/* ---------------------------------------------------------------------
	 * Moderation.
	 * ------------------------------------------------------------------ */

	/**
	 * Set a message's status.
	 *
	 * @param int    $id     Message ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;

		$status = sanitize_key( (string) $status );

		if ( ! in_array( $status, array( self::VISIBLE, self::PENDING, self::HIDDEN, 'deleted' ), true ) ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'status' => $status ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);

		return (bool) $updated;
	}

	/**
	 * A student reports a message.
	 *
	 * Three reports hide it automatically and put it in the queue. That is
	 * blunt, and deliberately so: a message a room has objected to three
	 * times should come down before a moderator next logs in, not after.
	 *
	 * @param int $id      Message ID.
	 * @param int $user_id Reporter.
	 * @return array|WP_Error `array( 'flags' => int, 'hidden' => bool )`
	 */
	public static function report( $id, $user_id ) {
		global $wpdb;

		$id      = (int) $id;
		$user_id = (int) $user_id;
		$row     = self::get( $id );

		if ( ! $row ) {
			return new WP_Error( 'ces_chat_missing', __( 'That message is gone.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		if ( ! self::can_read( (string) $row['room'], $user_id ) ) {
			return new WP_Error( 'ces_chat_denied', __( 'You cannot report that.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$key = 'ces_chat_rep_' . $id . '_' . $user_id;

		if ( get_transient( $key ) ) {
			return array(
				'flags'  => (int) $row['flags'],
				'hidden' => self::HIDDEN === (string) $row['status'],
			);
		}

		set_transient( $key, 1, WEEK_IN_SECONDS );

		$table = self::table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "UPDATE {$table} SET flags = flags + 1 WHERE id = %d", $id )
		);

		$flags  = (int) $row['flags'] + 1;
		$hidden = false;

		if ( $flags >= 3 && self::VISIBLE === (string) $row['status'] ) {
			self::set_status( $id, self::HIDDEN );
			$hidden = true;
		}

		CES_Helpers::log( 'Chat message reported', array( 'id' => $id, 'flags' => $flags ) );

		return array(
			'flags'  => $flags,
			'hidden' => $hidden,
		);
	}

	/**
	 * Messages needing a moderator's attention.
	 *
	 * @param int $limit How many.
	 * @return array[] Raw rows, newest first.
	 */
	public static function queue( $limit = 50 ) {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 200, (int) $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = %s OR ( flags > 0 AND status <> %s )
				 ORDER BY id DESC LIMIT %d",
				self::PENDING,
				'deleted',
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many messages are waiting for a moderator.
	 *
	 * @return int
	 */
	public static function queue_count() {
		global $wpdb;

		$table = self::table();

		// Deliberately the same condition as queue(), so the number on the
		// menu is the number of rows a moderator will actually find.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s OR ( flags > 0 AND status <> %s )",
				self::PENDING,
				'deleted'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ---------------------------------------------------------------------
	 * Housekeeping.
	 * ------------------------------------------------------------------ */

	/**
	 * Note that a human has just spoken, for the liveliness layer.
	 *
	 * @param string $room Room string.
	 * @return void
	 */
	private static function note_human_message( $room ) {
		set_transient( 'ces_chat_human_' . md5( $room ), time(), DAY_IN_SECONDS );
	}

	/**
	 * When a human last spoke in a room, as a Unix timestamp.
	 *
	 * @param string $room Room string.
	 * @return int 0 when not known.
	 */
	public static function last_human_at( $room ) {
		return (int) get_transient( 'ces_chat_human_' . md5( $room ) );
	}

	/**
	 * Delete every message in a room.
	 *
	 * @param string $room Room string.
	 * @return void
	 */
	public static function delete_room( $room ) {
		global $wpdb;

		$room = self::sanitize_room( $room );

		if ( '' === $room ) {
			return;
		}

		$wpdb->delete( self::table(), array( 'room' => $room ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete everything one person ever wrote.
	 *
	 * @param int $user_id Author.
	 * @return void
	 */
	public static function delete_user( $user_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'user_id' => (int) $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Trim rooms back to a sane length.
	 *
	 * Chat is not a record worth keeping for ever, and an unbounded table on
	 * shared hosting becomes the site's slowest query. Keeps the newest
	 * `classroom_chat_history` times four per room.
	 *
	 * @return int Rows removed.
	 */
	public static function prune() {
		global $wpdb;

		$table = self::table();
		$keep  = max( 100, CES_Helpers::setting_int( 'classroom_chat_history', 80 ) * 4 );

		$rooms = $wpdb->get_col( "SELECT room FROM {$table} GROUP BY room HAVING COUNT(*) > {$keep}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$removed = 0;

		foreach ( (array) $rooms as $room ) {
			$cutoff = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE room = %s ORDER BY id DESC LIMIT 1 OFFSET %d",
					$room,
					$keep
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( $cutoff > 0 ) {
				$removed += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->prepare( "DELETE FROM {$table} WHERE room = %s AND id <= %d", $room, $cutoff )
				);
			}
		}

		return $removed;
	}
}
