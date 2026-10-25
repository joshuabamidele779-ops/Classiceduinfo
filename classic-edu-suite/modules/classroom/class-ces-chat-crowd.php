<?php
/**
 * Keeping a room from feeling empty.
 *
 * An empty chat room kills itself: a student looks in, sees nothing, and does
 * not come back. So when a room has been quiet for a few minutes and somebody
 * is looking at it, this layer puts something in it worth answering.
 *
 * ### Everything here is honest about what it is
 *
 * There are several automated voices, with names, and they talk to each other
 * as well as to the room — so a student opening the Physics room finds a
 * conversation already going rather than a blank screen. Every one of them
 * carries a **BOT** badge next to the name, every time, in the room and in
 * the moderation queue.
 *
 * That badge is not negotiable, and the reason is worth writing down. These
 * are children. A student who believes they are talking to a classmate will
 * tell "them" things they would not tell a machine, and will be humiliated
 * when they find out — and they do find out. A labelled study bot that asks
 * good questions makes a room lively; an unlabelled one makes the site
 * untrustworthy the first time somebody notices. The badge costs nothing and
 * the liveliness is the same.
 *
 * The other source of life here is not automated at all: `activity` messages
 * report things that genuinely happened — a real score, a real streak, a real
 * student joining. In the open lobbies those are anonymised ("a student in
 * SS 3"), because a child's exam score is not for a public room; inside a
 * class, where everyone already knows each other, names are used.
 *
 * Nothing runs on a timer. The work happens when a room is read, which means
 * a room nobody is in stays silent and costs nothing.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * The automated participants and the real-activity feed.
 */
class CES_Chat_Crowd {

	/**
	 * Transient prefix counting what we have posted in a room this hour.
	 */
	const BUDGET_PREFIX = 'ces_crowd_budget_';

	/**
	 * Transient prefix holding the last persona and prompt used in a room.
	 */
	const STATE_PREFIX = 'ces_crowd_state_';

	/**
	 * The automated voices.
	 *
	 * Several of them, with ordinary first names, because one bot talking to
	 * itself reads as a broken feature while four voices read as a room. The
	 * `bot` author kind is what puts the badge on every one of them.
	 *
	 * @return array Map of key => array( name, voice ).
	 */
	public static function personas() {
		$personas = array(
			'ada'   => array(
				'name'  => __( 'Ada', 'classic-edu-suite' ),
				'voice' => 'asks',
			),
			'tunde' => array(
				'name'  => __( 'Tunde', 'classic-edu-suite' ),
				'voice' => 'hints',
			),
			'ngozi' => array(
				'name'  => __( 'Ngozi', 'classic-edu-suite' ),
				'voice' => 'encourages',
			),
			'musa'  => array(
				'name'  => __( 'Musa', 'classic-edu-suite' ),
				'voice' => 'facts',
			),
		);

		/**
		 * Filter the automated chat personas.
		 *
		 * Every one of these is rendered with a BOT badge regardless of what
		 * is returned here; the badge comes from the message's author kind,
		 * not from the name.
		 *
		 * @param array $personas Key => array( name, voice ).
		 */
		return (array) apply_filters( 'ces_chat_personas', $personas );
	}

	/**
	 * The display name for a persona, with the suffix the room shows.
	 *
	 * @param string $key Persona key.
	 * @return string
	 */
	public static function persona_name( $key ) {
		$personas = self::personas();

		return isset( $personas[ $key ] ) ? (string) $personas[ $key ]['name'] : __( 'Study Bot', 'classic-edu-suite' );
	}

	/* ---------------------------------------------------------------------
	 * The decision.
	 * ------------------------------------------------------------------ */

	/**
	 * Say something if the room needs it.
	 *
	 * Called when a room is read, so there is always at least one person
	 * there to see it.
	 *
	 * @param string $room Room string.
	 * @return int Message ID, 0 when nothing was posted.
	 */
	public static function maybe_speak( $room ) {
		$room = CES_Chat::sanitize_room( $room );

		if ( '' === $room || ! CES_Helpers::enabled( 'classroom_crowd_enabled' ) ) {
			return 0;
		}

		if ( ! CES_Helpers::enabled( 'classroom_chat_enabled' ) ) {
			return 0;
		}

		// A moderator reading the queue must not trigger chatter.
		if ( is_admin() ) {
			return 0;
		}

		if ( ! self::quiet_enough( $room ) ) {
			return 0;
		}

		if ( ! self::has_budget( $room ) ) {
			return 0;
		}

		$id = self::compose( $room );

		if ( $id > 0 ) {
			self::spend_budget( $room );
		}

		return $id;
	}

	/**
	 * Whether the room has been quiet long enough to be worth filling.
	 *
	 * Measured from the last message of any kind, so the bots do not talk
	 * over each other either.
	 *
	 * @param string $room Room string.
	 * @return bool
	 */
	private static function quiet_enough( $room ) {
		$minutes = max( 1, CES_Helpers::setting_int( 'classroom_crowd_quiet', 4 ) );
		$state   = get_transient( self::STATE_PREFIX . md5( $room ) );
		$last    = is_array( $state ) && ! empty( $state['at'] ) ? (int) $state['at'] : 0;

		if ( $last && ( time() - $last ) < ( $minutes * MINUTE_IN_SECONDS ) ) {
			return false;
		}

		$human = CES_Chat::last_human_at( $room );

		if ( $human && ( time() - $human ) < ( $minutes * MINUTE_IN_SECONDS ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether this room has any automated messages left this hour.
	 *
	 * @param string $room Room string.
	 * @return bool
	 */
	private static function has_budget( $room ) {
		$ceiling = max( 0, CES_Helpers::setting_int( 'classroom_crowd_per_hour', 6 ) );

		if ( $ceiling <= 0 ) {
			return false;
		}

		return (int) get_transient( self::BUDGET_PREFIX . md5( $room ) ) < $ceiling;
	}

	/**
	 * Record one automated message against the hourly ceiling.
	 *
	 * @param string $room Room string.
	 * @return void
	 */
	private static function spend_budget( $room ) {
		$key   = self::BUDGET_PREFIX . md5( $room );
		$spent = (int) get_transient( $key );

		set_transient( $key, $spent + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Remember what was said, so the next one is different.
	 *
	 * @param string $room    Room string.
	 * @param string $persona Persona key used.
	 * @param string $hash    Hash of the message used.
	 * @return void
	 */
	private static function remember( $room, $persona, $hash ) {
		$key   = self::STATE_PREFIX . md5( $room );
		$state = get_transient( $key );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$state['at']      = time();
		$state['persona'] = $persona;

		$used = isset( $state['used'] ) && is_array( $state['used'] ) ? $state['used'] : array();

		$used[] = $hash;

		// Remember the last dozen, so a room does not loop its own content.
		$state['used'] = array_slice( $used, -12 );

		set_transient( $key, $state, DAY_IN_SECONDS );
	}

	/**
	 * Whether a message was used in this room recently.
	 *
	 * @param string $room Room string.
	 * @param string $hash Message hash.
	 * @return bool
	 */
	private static function used_recently( $room, $hash ) {
		$state = get_transient( self::STATE_PREFIX . md5( $room ) );

		if ( ! is_array( $state ) || empty( $state['used'] ) || ! is_array( $state['used'] ) ) {
			return false;
		}

		return in_array( $hash, $state['used'], true );
	}

	/* ---------------------------------------------------------------------
	 * Composing.
	 * ------------------------------------------------------------------ */

	/**
	 * Pick something to say and post it.
	 *
	 * Real activity comes first when there is any, because a true thing about
	 * a real classmate is more interesting than anything a bot can say.
	 *
	 * @param string $room Room string.
	 * @return int Message ID.
	 */
	private static function compose( $room ) {
		if ( CES_Helpers::enabled( 'classroom_crowd_activity' ) && 0 === wp_rand( 0, 1 ) ) {
			$activity = self::activity_line( $room );

			if ( '' !== $activity ) {
				$hash = md5( $activity );

				if ( ! self::used_recently( $room, $hash ) ) {
					self::remember( $room, 'system', $hash );

					return CES_Chat::post_system( $room, $activity );
				}
			}
		}

		if ( ! CES_Helpers::enabled( 'classroom_crowd_prompts' ) ) {
			return 0;
		}

		$persona = self::pick_persona( $room );
		$line    = self::line_for( $persona, $room );

		if ( '' === $line ) {
			return 0;
		}

		$hash = md5( $persona . '|' . $line );

		if ( self::used_recently( $room, $hash ) ) {
			return 0;
		}

		self::remember( $room, $persona, $hash );

		return CES_Chat::post_bot( $room, self::persona_name( $persona ), $line );
	}

	/**
	 * Choose a voice, avoiding the one that spoke last.
	 *
	 * @param string $room Room string.
	 * @return string Persona key.
	 */
	private static function pick_persona( $room ) {
		$keys = array_keys( self::personas() );

		if ( empty( $keys ) ) {
			return 'ada';
		}

		$state = get_transient( self::STATE_PREFIX . md5( $room ) );
		$last  = is_array( $state ) && ! empty( $state['persona'] ) ? (string) $state['persona'] : '';

		$choices = array_values( array_diff( $keys, array( $last ) ) );

		if ( empty( $choices ) ) {
			$choices = $keys;
		}

		return (string) $choices[ wp_rand( 0, count( $choices ) - 1 ) ];
	}

	/**
	 * A line in one persona's voice, shaped to the room it is going into.
	 *
	 * @param string $persona Persona key.
	 * @param string $room    Room string.
	 * @return string
	 */
	private static function line_for( $persona, $room ) {
		$personas = self::personas();
		$voice    = isset( $personas[ $persona ]['voice'] ) ? (string) $personas[ $persona ]['voice'] : 'asks';
		$subject  = self::room_subject( $room );

		$bank = self::bank( $voice, $subject );

		/**
		 * Filter the lines an automated persona can use.
		 *
		 * @param string[] $bank    Candidate lines.
		 * @param string   $voice   Persona voice.
		 * @param string   $subject Subject slug of the room.
		 * @param string   $room    Room string.
		 */
		$bank = (array) apply_filters( 'ces_chat_crowd_lines', $bank, $voice, $subject, $room );

		$bank = array_values( array_filter( array_map( 'trim', $bank ) ) );

		if ( empty( $bank ) ) {
			return '';
		}

		return (string) $bank[ wp_rand( 0, count( $bank ) - 1 ) ];
	}

	/**
	 * The subject a room is about, for picking relevant prompts.
	 *
	 * @param string $room Room string.
	 * @return string Subject slug, or 'general'.
	 */
	private static function room_subject( $room ) {
		$parsed = CES_Chat::parse_room( $room );

		if ( 'lobby' === $parsed['type'] ) {
			return (string) $parsed['id'];
		}

		$class_id = 'class' === $parsed['type']
			? (int) $parsed['id']
			: CES_Classroom_Session::class_id( (int) $parsed['id'] );

		if ( $class_id > 0 && taxonomy_exists( 'ces_subject' ) ) {
			$terms = get_the_terms( $class_id, 'ces_subject' );

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				return CES_Helpers::subject_art( $terms[0]->name );
			}
		}

		return 'general';
	}

	/**
	 * The prompt bank.
	 *
	 * Questions a student can actually answer, which is the whole point: a
	 * prompt that invites a reply starts a conversation, and a motivational
	 * poster does not.
	 *
	 * @param string $voice   Persona voice.
	 * @param string $subject Subject slug of the room.
	 * @return string[]
	 */
	private static function bank( $voice, $subject ) {
		$subject_questions = array(
			'maths'     => array(
				__( 'Quick one: what is 15% of 240? First correct answer wins the bragging rights.', 'classic-edu-suite' ),
				__( 'Who can explain the difference between mean, median and mode in one sentence each?', 'classic-edu-suite' ),
				__( 'If 3x − 7 = 20, what is x? Show how you got there, not just the answer.', 'classic-edu-suite' ),
				__( 'Which do you find harder, simultaneous equations or word problems? Why?', 'classic-edu-suite' ),
			),
			'physics'   => array(
				__( 'What is the SI unit of pressure, and who is it named after?', 'classic-edu-suite' ),
				__( 'Explain in your own words why a heavy and a light ball hit the ground together.', 'classic-edu-suite' ),
				__( 'Someone explain the difference between speed and velocity without using the word "direction". Harder than it sounds.', 'classic-edu-suite' ),
				__( 'What topic in Physics do you wish somebody would just explain properly?', 'classic-edu-suite' ),
			),
			'chemistry' => array(
				__( 'How many moles are in 36 g of water? Walk us through it.', 'classic-edu-suite' ),
				__( 'What is the difference between an element, a compound and a mixture? One example each.', 'classic-edu-suite' ),
				__( 'Who remembers what makes a reaction exothermic?', 'classic-edu-suite' ),
			),
			'biology'   => array(
				__( 'Name the four chambers of the heart, in the order blood passes through them.', 'classic-edu-suite' ),
				__( 'What is the difference between mitosis and meiosis? Keep it to one line.', 'classic-edu-suite' ),
				__( 'Which Biology diagram do you find hardest to draw from memory?', 'classic-edu-suite' ),
			),
			'english'   => array(
				__( 'What is the difference between a simile and a metaphor? Give one of each.', 'classic-edu-suite' ),
				__( 'Summary question: what makes a good summary answer in WAEC? Three things.', 'classic-edu-suite' ),
				__( 'What word do you always spell wrongly? Mine used to be "necessary".', 'classic-edu-suite' ),
			),
			'exams'     => array(
				__( 'How many weeks have you got left before your next paper? Be honest.', 'classic-edu-suite' ),
				__( 'What is the one subject you are leaving until last? That is usually the one to start with.', 'classic-edu-suite' ),
				__( 'Has anybody here sat a mock yet? What surprised you about it?', 'classic-edu-suite' ),
			),
			'careers'   => array(
				__( 'What course are you hoping for, and which school is your first choice?', 'classic-edu-suite' ),
				__( 'Does anybody know the usual cut-off for Medicine this year? Share what you have seen.', 'classic-edu-suite' ),
				__( 'If you could shadow somebody at work for a day, whose job would it be?', 'classic-edu-suite' ),
			),
		);

		$general_questions = array(
			__( 'What are you working on today? Say the subject and we will keep you company.', 'classic-edu-suite' ),
			__( 'Which topic is giving you trouble this week? Someone here has probably cracked it.', 'classic-edu-suite' ),
			__( 'What time of day do you study best — early morning or late night?', 'classic-edu-suite' ),
			__( 'Anybody sitting an exam this term? What are you starting with?', 'classic-edu-suite' ),
			__( 'Post the hardest question you met this week and let us all have a go.', 'classic-edu-suite' ),
		);

		$questions = isset( $subject_questions[ $subject ] )
			? array_merge( $subject_questions[ $subject ], $general_questions )
			: $general_questions;

		switch ( $voice ) {
			case 'hints':
				return array(
					__( 'A trick that works: explain the topic out loud as if you were teaching it. Where you stumble is what you have not learnt yet.', 'classic-edu-suite' ),
					__( 'Past questions beat re-reading notes every time. Sit one paper properly timed, then mark it honestly.', 'classic-edu-suite' ),
					__( 'If a question looks impossible, write down what you are given and what is asked for. Half the time the method appears.', 'classic-edu-suite' ),
					__( 'Twenty-five minutes of real work beats two hours with your phone face up.', 'classic-edu-suite' ),
					__( 'Mark your own practice paper the hard way — no half marks for "I knew that".', 'classic-edu-suite' ),
				);

			case 'encourages':
				return array(
					__( 'If anybody is stuck on something and embarrassed to ask — ask. That is literally what this room is for.', 'classic-edu-suite' ),
					__( 'Whoever is revising right now: keep going. Finish the topic you are on before you stop.', 'classic-edu-suite' ),
					__( 'A bad mock is information, not a verdict. What did it tell you to work on?', 'classic-edu-suite' ),
					__( 'Nobody here was born knowing this. Ask the question.', 'classic-edu-suite' ),
				);

			case 'facts':
				return array(
					__( 'Reminder: JAMB registration dates move every year. Check the official portal yourself rather than trusting a forwarded message.', 'classic-edu-suite' ),
					__( 'A WAEC answer that shows the working usually scores more than a bare correct answer. Show your method.', 'classic-edu-suite' ),
					__( 'If a message tells you to pay somebody for "expo" or "runs", it is a scam. Every single time.', 'classic-edu-suite' ),
					__( 'Four hours of sleep does not make you a serious student. It makes you slower at everything the next day.', 'classic-edu-suite' ),
				);

			case 'asks':
			default:
				return $questions;
		}
	}

	/* ---------------------------------------------------------------------
	 * Real activity.
	 * ------------------------------------------------------------------ */

	/**
	 * A true sentence about something that has just happened on the site.
	 *
	 * In a class room, where everyone knows each other, students are named.
	 * In the open lobbies they are not: a child's exam score is not for a
	 * room of strangers, however lively it makes the feed.
	 *
	 * @param string $room Room string.
	 * @return string Empty when there is nothing real to report.
	 */
	public static function activity_line( $room ) {
		global $wpdb;

		$parsed  = CES_Chat::parse_room( $room );
		$in_class = 'class' === $parsed['type'] || 'session' === $parsed['type'];

		$attempts = CES_Helpers::table( 'attempts' );
		$since    = gmdate( 'Y-m-d H:i:s', (int) strtotime( CES_Helpers::now() . ' UTC' ) - DAY_IN_SECONDS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, exam_id, score FROM {$attempts}
				 WHERE status = %s AND submitted_at >= %s AND score >= %d
				 ORDER BY submitted_at DESC LIMIT 12",
				'submitted',
				$since,
				60
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( empty( $rows ) ) {
			return '';
		}

		// Inside a class, only talk about people in that class.
		if ( $in_class ) {
			$class_id = 'class' === $parsed['type'] ? (int) $parsed['id'] : CES_Classroom_Session::class_id( (int) $parsed['id'] );
			$members  = array();

			foreach ( CES_Classroom_Enrolment::roster( $class_id, array( 'number' => 500 ) ) as $member ) {
				$members[] = (int) $member['user_id'];
			}

			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $members ) {
						return in_array( (int) $row['user_id'], $members, true );
					}
				)
			);

			if ( empty( $rows ) ) {
				return '';
			}
		}

		$row   = $rows[ wp_rand( 0, count( $rows ) - 1 ) ];
		$score = (float) $row['score'];
		$exam  = get_the_title( (int) $row['exam_id'] );

		if ( '' === trim( (string) $exam ) ) {
			$exam = __( 'a practice paper', 'classic-edu-suite' );
		}

		if ( $in_class ) {
			$user = get_userdata( (int) $row['user_id'] );
			$name = $user ? self::first_name( $user->display_name ) : '';

			if ( '' === $name ) {
				return '';
			}

			return sprintf(
				/* translators: 1: student first name, 2: score, 3: exam title. */
				__( '%1$s just scored %2$s%% in %3$s', 'classic-edu-suite' ),
				$name,
				number_format_i18n( $score, 0 ),
				$exam
			);
		}

		$level = self::level_phrase( (int) $row['user_id'] );

		return sprintf(
			/* translators: 1: a phrase such as "A student in SS 3", 2: score, 3: exam title. */
			__( '%1$s just scored %2$s%% in %3$s', 'classic-edu-suite' ),
			$level,
			number_format_i18n( $score, 0 ),
			$exam
		);
	}

	/**
	 * The first word of a display name.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	private static function first_name( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );

		return isset( $parts[0] ) ? (string) $parts[0] : '';
	}

	/**
	 * An anonymous way to refer to a student, by class where we know it.
	 *
	 * @param int $user_id Student.
	 * @return string
	 */
	private static function level_phrase( $user_id ) {
		if ( class_exists( 'CES_Accounts_Status' ) ) {
			$label = CES_Accounts_Status::class_label( (string) get_user_meta( (int) $user_id, CES_Accounts_Status::META_CLASS, true ) );

			if ( '' !== $label ) {
				return sprintf(
					/* translators: %s: class or level, e.g. "SS 3". */
					__( 'A student in %s', 'classic-edu-suite' ),
					$label
				);
			}
		}

		return __( 'A student', 'classic-edu-suite' );
	}

	/* ---------------------------------------------------------------------
	 * Openers.
	 * ------------------------------------------------------------------ */

	/**
	 * Put an opening exchange in a brand new room.
	 *
	 * A room with three messages in it reads as a room. A room with none
	 * reads as broken, and the first student to arrive leaves again.
	 *
	 * @param string $room  Room string.
	 * @param string $topic Optional subject hint for the opener.
	 * @return void
	 */
	public static function seed( $room, $topic = '' ) {
		$room = CES_Chat::sanitize_room( $room );

		if ( '' === $room || ! CES_Helpers::enabled( 'classroom_crowd_enabled' ) || ! CES_Helpers::enabled( 'classroom_crowd_prompts' ) ) {
			return;
		}

		if ( CES_Chat::latest_id( $room ) > 0 ) {
			return;
		}

		$personas = array_keys( self::personas() );

		if ( count( $personas ) < 2 ) {
			return;
		}

		$opener = '' !== trim( (string) $topic )
			? sprintf(
				/* translators: %s: class or subject name. */
				__( 'Room open for %s. Ask anything here — somebody will pick it up.', 'classic-edu-suite' ),
				trim( (string) $topic )
			)
			: __( 'Room is open. Ask anything here — somebody will pick it up.', 'classic-edu-suite' );

		CES_Chat::post_bot( $room, self::persona_name( $personas[0] ), $opener );
		CES_Chat::post_bot( $room, self::persona_name( $personas[1] ), self::line_for( $personas[1], $room ) );

		self::remember( $room, $personas[1], md5( $opener ) );
	}
}
