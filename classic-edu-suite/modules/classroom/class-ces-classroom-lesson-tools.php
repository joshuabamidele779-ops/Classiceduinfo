<?php
/**
 * The tools that make a recorded lesson worth watching.
 *
 * Giving a tutor an upload button produces forty minutes of someone reading
 * their notes. The difference between that and a lesson a student finishes is
 * almost entirely structural, and structure is something software can
 * actually help with. So this class holds five tools, each of which exists
 * because of a specific way teaching videos fail:
 *
 *   **The plan.** Lessons ramble because they were not planned. The plan is a
 *   fixed shape — one objective, a hook, the steps, a worked example, a check
 *   for understanding, a recap — and the tutor fills it in before they
 *   record. It doubles as the teleprompter script and as the lesson notes
 *   students read afterwards.
 *
 *   **Chapters.** A student who cannot find the bit they need rewatches from
 *   the start, gets bored, and stops. Chapter markers turn a 20-minute video
 *   into six things a student can jump to. Typed in the YouTube convention
 *   (`2:15 Solving for x`) because every tutor already knows that format.
 *
 *   **Captions.** Students watch on a phone, often with the sound off,
 *   usually in a room with other people, sometimes in a second language.
 *   Captions are the single biggest comprehension win available, and they
 *   are stored as WebVTT so the browser's own caption track renders them.
 *
 *   **Checkpoints.** A question pinned to a timestamp stops the video and
 *   asks whether that bit landed. It converts watching into doing, and it
 *   tells the tutor exactly where the explanation was not good enough.
 *
 *   **The review.** A checklist, scored, with specific advice. Not a gate —
 *   a tutor can publish anything they like — but it tells them what is
 *   missing before their students find out.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lesson plans, chapters, captions, checkpoints and the quality review.
 */
class CES_Classroom_Lesson_Tools {

	/**
	 * The shortest a lesson should be, in seconds.
	 */
	const MIN_GOOD = 180;

	/**
	 * The longest a single lesson should be before it wants splitting.
	 */
	const MAX_GOOD = 1500;

	/**
	 * Whether questions may be pinned into a video on this site.
	 *
	 * @return bool
	 */
	public static function checkpoints_enabled() {
		return CES_Helpers::enabled( 'classroom_checkpoints' );
	}

	/**
	 * Whether this person may edit a lesson's extras.
	 *
	 * @param int $lesson_id Lesson.
	 * @param int $user_id   Optional user.
	 * @return bool
	 */
	public static function can_edit( $lesson_id, $user_id = 0 ) {
		$lesson_id = (int) $lesson_id;
		$user_id   = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $lesson_id || ! $user_id || CES_Lms_Cpt::LESSON !== get_post_type( $lesson_id ) ) {
			return false;
		}

		if ( CES_Consultant::is_staff( $user_id ) ) {
			return true;
		}

		return (int) get_post_field( 'post_author', $lesson_id ) === $user_id;
	}

	/* ---------------------------------------------------------------------
	 * The plan.
	 * ------------------------------------------------------------------ */

	/**
	 * The shape of a lesson plan, with the prompt shown for each part.
	 *
	 * @return array Map of key => array( label, hint, rows ).
	 */
	public static function plan_fields() {
		return array(
			'objective' => array(
				'label' => __( 'One thing they will be able to do', 'classic-edu-suite' ),
				'hint'  => __( 'Finish this sentence: “By the end of this lesson you will be able to…”. One thing, not four. This is the single most useful line in the plan.', 'classic-edu-suite' ),
				'rows'  => 2,
			),
			'hook'      => array(
				'label' => __( 'How you open', 'classic-edu-suite' ),
				'hint'  => __( 'The first fifteen seconds decide whether they stay. A question they cannot answer yet, a mistake everyone makes, or a past-question that looks impossible.', 'classic-edu-suite' ),
				'rows'  => 3,
			),
			'recall'    => array(
				'label' => __( 'What they need to remember first', 'classic-edu-suite' ),
				'hint'  => __( 'The one or two things from earlier work this lesson stands on. Thirty seconds of reminder saves ten minutes of confusion.', 'classic-edu-suite' ),
				'rows'  => 2,
			),
			'steps'     => array(
				'label' => __( 'The teaching, step by step', 'classic-edu-suite' ),
				'hint'  => __( 'One line per step, in order. If you have more than seven, this is two lessons.', 'classic-edu-suite' ),
				'rows'  => 7,
			),
			'example'   => array(
				'label' => __( 'The worked example', 'classic-edu-suite' ),
				'hint'  => __( 'A real question, worked all the way through, out loud, including the bit where you explain why you chose that method. Use a past question if you can.', 'classic-edu-suite' ),
				'rows'  => 4,
			),
			'check'     => array(
				'label' => __( 'How you check they understood', 'classic-edu-suite' ),
				'hint'  => __( 'The question you stop and ask. Put it in as a checkpoint too, so the video actually pauses and waits for them.', 'classic-edu-suite' ),
				'rows'  => 3,
			),
			'mistakes'  => array(
				'label' => __( 'The mistakes to warn them about', 'classic-edu-suite' ),
				'hint'  => __( 'The errors you see every year in this topic. Naming them is worth more than another example.', 'classic-edu-suite' ),
				'rows'  => 3,
			),
			'recap'     => array(
				'label' => __( 'The recap', 'classic-edu-suite' ),
				'hint'  => __( 'Three lines at the end, repeating the objective. This is what they will still have next week.', 'classic-edu-suite' ),
				'rows'  => 3,
			),
			'homework'  => array(
				'label' => __( 'What they do next', 'classic-edu-suite' ),
				'hint'  => __( 'Specific: which questions, from where, by when. “Revise this topic” is not an instruction.', 'classic-edu-suite' ),
				'rows'  => 2,
			),
			'materials' => array(
				'label' => __( 'What they need to hand', 'classic-edu-suite' ),
				'hint'  => __( 'Calculator, graph paper, the set text, a particular past paper.', 'classic-edu-suite' ),
				'rows'  => 2,
			),
			'script'    => array(
				'label' => __( 'Script or prompts (optional)', 'classic-edu-suite' ),
				'hint'  => __( 'Anything you want scrolling in front of you while you record. The teleprompter in the recorder reads from this box.', 'classic-edu-suite' ),
				'rows'  => 10,
			),
		);
	}

	/**
	 * A lesson's plan, with every key present.
	 *
	 * @param int $lesson_id Lesson.
	 * @return array
	 */
	public static function plan( $lesson_id ) {
		$stored = CES_Classroom_Cpt::json_meta( (int) $lesson_id, CES_Classroom_Cpt::LESSON_PLAN );
		$out    = array();

		foreach ( array_keys( self::plan_fields() ) as $key ) {
			$out[ $key ] = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
		}

		return $out;
	}

	/**
	 * Save a plan.
	 *
	 * @param int   $lesson_id Lesson.
	 * @param array $input     Raw input, keyed like plan_fields().
	 * @return true|WP_Error
	 */
	public static function save_plan( $lesson_id, array $input ) {
		if ( ! self::can_edit( $lesson_id ) ) {
			return new WP_Error( 'ces_plan_denied', __( 'That is not your lesson.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$clean = array();

		foreach ( array_keys( self::plan_fields() ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			$value = sanitize_textarea_field( (string) $input[ $key ] );
			$value = mb_substr( trim( $value ), 0, 4000 );

			if ( '' !== $value ) {
				$clean[ $key ] = $value;
			}
		}

		CES_Classroom_Cpt::set_json_meta( (int) $lesson_id, CES_Classroom_Cpt::LESSON_PLAN, $clean );

		// The plan is also the lesson notes a student reads underneath the
		// player, so the LMS's own notes field is kept in step with it.
		self::sync_notes( (int) $lesson_id, $clean );

		return true;
	}

	/**
	 * Write the student-facing part of the plan into the LMS notes field.
	 *
	 * The script, the mistakes list and the check question are the tutor's
	 * working notes and are deliberately left out.
	 *
	 * @param int   $lesson_id Lesson.
	 * @param array $plan      Clean plan.
	 * @return void
	 */
	private static function sync_notes( $lesson_id, array $plan ) {
		$parts = array();

		if ( ! empty( $plan['objective'] ) ) {
			$parts[] = __( 'By the end of this lesson you will be able to:', 'classic-edu-suite' ) . "\n" . $plan['objective'];
		}

		if ( ! empty( $plan['materials'] ) ) {
			$parts[] = __( 'What you need:', 'classic-edu-suite' ) . "\n" . $plan['materials'];
		}

		if ( ! empty( $plan['recap'] ) ) {
			$parts[] = __( 'In summary:', 'classic-edu-suite' ) . "\n" . $plan['recap'];
		}

		if ( ! empty( $plan['homework'] ) ) {
			$parts[] = __( 'Your turn:', 'classic-edu-suite' ) . "\n" . $plan['homework'];
		}

		if ( empty( $parts ) ) {
			return;
		}

		if ( class_exists( 'CES_Lms_Meta' ) ) {
			update_post_meta( $lesson_id, CES_Lms_Meta::NOTES, implode( "\n\n", $parts ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Chapters.
	 * ------------------------------------------------------------------ */

	/**
	 * A lesson's chapter markers, in time order.
	 *
	 * @param int $lesson_id Lesson.
	 * @return array[] Each entry: t (seconds), label, stamp (mm:ss).
	 */
	public static function chapters( $lesson_id ) {
		$raw = CES_Classroom_Cpt::json_meta( (int) $lesson_id, CES_Classroom_Cpt::LESSON_CHAPTERS );
		$out = array();

		foreach ( $raw as $chapter ) {
			if ( ! is_array( $chapter ) || ! isset( $chapter['t'] ) ) {
				continue;
			}

			$seconds = max( 0, (int) $chapter['t'] );
			$label   = isset( $chapter['label'] ) ? (string) $chapter['label'] : '';

			if ( '' === trim( $label ) ) {
				continue;
			}

			$out[] = array(
				't'     => $seconds,
				'label' => $label,
				'stamp' => self::stamp( $seconds ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $a['t'] <=> $b['t'];
			}
		);

		return $out;
	}

	/**
	 * Save chapters from the textarea a tutor typed them into.
	 *
	 * @param int    $lesson_id Lesson.
	 * @param string $text      One chapter per line.
	 * @return array|WP_Error The parsed chapters.
	 */
	public static function save_chapters( $lesson_id, $text ) {
		if ( ! self::can_edit( $lesson_id ) ) {
			return new WP_Error( 'ces_chapters_denied', __( 'That is not your lesson.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$chapters = self::parse_chapters( $text );

		CES_Classroom_Cpt::set_json_meta( (int) $lesson_id, CES_Classroom_Cpt::LESSON_CHAPTERS, $chapters );

		return self::chapters( $lesson_id );
	}

	/**
	 * Read chapter lines in the format everybody already types.
	 *
	 * Accepts `0:00 Introduction`, `1:30 — Solving for x`, `12:05: Recap`
	 * and `1:02:30 The hard bit`, which covers how a tutor will actually
	 * write them without being told a syntax.
	 *
	 * @param string $text Raw textarea contents.
	 * @return array[] Each entry: t, label.
	 */
	public static function parse_chapters( $text ) {
		$out = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			if ( ! preg_match( '/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?\s*[-–—:.)]*\s*(.+)$/u', $line, $m ) ) {
				continue;
			}

			if ( isset( $m[3] ) && '' !== $m[3] ) {
				$seconds = ( (int) $m[1] * 3600 ) + ( (int) $m[2] * 60 ) + (int) $m[3];
			} else {
				$seconds = ( (int) $m[1] * 60 ) + (int) $m[2];
			}

			$label = trim( sanitize_text_field( $m[4] ) );

			if ( '' === $label ) {
				continue;
			}

			$out[] = array(
				't'     => max( 0, $seconds ),
				'label' => mb_substr( $label, 0, 120 ),
			);

			if ( count( $out ) >= 60 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Chapters back as editable text.
	 *
	 * @param int $lesson_id Lesson.
	 * @return string
	 */
	public static function chapters_text( $lesson_id ) {
		$lines = array();

		foreach ( self::chapters( $lesson_id ) as $chapter ) {
			$lines[] = $chapter['stamp'] . ' ' . $chapter['label'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Seconds as `mm:ss`, or `h:mm:ss` past an hour.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function stamp( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );
		$rest    = $seconds % 60;

		if ( $hours > 0 ) {
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $rest );
		}

		return sprintf( '%d:%02d', $minutes, $rest );
	}

	/* ---------------------------------------------------------------------
	 * Captions.
	 * ------------------------------------------------------------------ */

	/**
	 * A lesson's WebVTT captions.
	 *
	 * @param int $lesson_id Lesson.
	 * @return string Empty when there are none.
	 */
	public static function vtt( $lesson_id ) {
		return (string) get_post_meta( (int) $lesson_id, CES_Classroom_Cpt::LESSON_VTT, true );
	}

	/**
	 * Whether a lesson has captions.
	 *
	 * @param int $lesson_id Lesson.
	 * @return bool
	 */
	public static function has_captions( $lesson_id ) {
		return '' !== trim( self::vtt( $lesson_id ) );
	}

	/**
	 * Save captions, accepting either WebVTT or plain text.
	 *
	 * Plain text is the common case: a tutor pastes what they said, and
	 * timings are spread across the video's duration. Rough, but captions
	 * that are slightly out beat no captions by a wide margin, and they can
	 * be corrected afterwards.
	 *
	 * @param int    $lesson_id Lesson.
	 * @param string $text      WebVTT or plain text.
	 * @return array|WP_Error `array( 'cues' => int, 'converted' => bool )`
	 */
	public static function save_captions( $lesson_id, $text ) {
		if ( ! self::can_edit( $lesson_id ) ) {
			return new WP_Error( 'ces_vtt_denied', __( 'That is not your lesson.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$lesson_id = (int) $lesson_id;
		$text      = trim( (string) wp_strip_all_tags( $text ) );

		if ( '' === $text ) {
			delete_post_meta( $lesson_id, CES_Classroom_Cpt::LESSON_VTT );

			return array(
				'cues'      => 0,
				'converted' => false,
			);
		}

		if ( mb_strlen( $text ) > 200000 ) {
			return new WP_Error( 'ces_vtt_long', __( 'That caption file is too large. Split the lesson instead.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$converted = false;

		if ( ! preg_match( '/^WEBVTT/', $text ) ) {
			if ( false !== strpos( $text, '-->' ) ) {
				// A SubRip file, or VTT with the header missing.
				$text = "WEBVTT\n\n" . preg_replace( '/^\d+\s*$/m', '', $text );
				$text = str_replace( ',', '.', $text );
			} else {
				$text      = self::text_to_vtt( $lesson_id, $text );
				$converted = true;
			}
		}

		$cues = preg_match_all( '/-->/', $text );

		if ( ! $cues ) {
			return new WP_Error( 'ces_vtt_empty', __( 'No captions could be read from that. Paste the text of what you said, or a .vtt file.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		update_post_meta( $lesson_id, CES_Classroom_Cpt::LESSON_VTT, $text );

		// Keep the LMS's own transcript field in step: it is what the
		// "read the transcript" panel under the player shows.
		if ( class_exists( 'CES_Lms_Meta' ) ) {
			update_post_meta( $lesson_id, CES_Lms_Meta::TRANSCRIPT, self::vtt_to_text( $text ) );
		}

		return array(
			'cues'      => (int) $cues,
			'converted' => $converted,
		);
	}

	/**
	 * Turn a wall of text into timed cues spread over the video.
	 *
	 * @param int    $lesson_id Lesson.
	 * @param string $text      Plain text.
	 * @return string WebVTT.
	 */
	private static function text_to_vtt( $lesson_id, $text ) {
		$duration = class_exists( 'CES_Lms_Meta' ) ? (int) CES_Lms_Meta::duration( $lesson_id ) : 0;

		// Split on sentence ends, then group into cues of a readable length.
		$sentences = preg_split( '/(?<=[.!?])\s+/u', preg_replace( '/\s+/u', ' ', $text ) );
		$sentences = array_values( array_filter( array_map( 'trim', (array) $sentences ) ) );

		if ( empty( $sentences ) ) {
			return '';
		}

		$cues    = array();
		$current = '';

		foreach ( $sentences as $sentence ) {
			if ( mb_strlen( $current . ' ' . $sentence ) > 140 && '' !== $current ) {
				$cues[]  = $current;
				$current = $sentence;
				continue;
			}

			$current = '' === $current ? $sentence : $current . ' ' . $sentence;
		}

		if ( '' !== $current ) {
			$cues[] = $current;
		}

		$count = count( $cues );

		// With no known duration, assume a readable 160 words a minute.
		if ( $duration <= 0 ) {
			$words    = str_word_count( $text );
			$duration = max( 30, (int) round( ( $words / 160 ) * 60 ) );
		}

		$per = max( 2.0, $duration / max( 1, $count ) );

		$out = array( 'WEBVTT', '' );

		foreach ( $cues as $index => $cue ) {
			$start = $index * $per;
			$end   = min( $duration, $start + $per );

			$out[] = self::vtt_time( $start ) . ' --> ' . self::vtt_time( $end );
			$out[] = $cue;
			$out[] = '';
		}

		return implode( "\n", $out );
	}

	/**
	 * Seconds as a WebVTT timestamp.
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	private static function vtt_time( $seconds ) {
		$seconds = max( 0, (float) $seconds );
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( fmod( $seconds, 3600 ) / 60 );
		$rest    = fmod( $seconds, 60 );

		return sprintf( '%02d:%02d:%06.3f', $hours, $minutes, $rest );
	}

	/**
	 * Strip the timings out of WebVTT to get readable text.
	 *
	 * @param string $vtt WebVTT.
	 * @return string
	 */
	public static function vtt_to_text( $vtt ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $vtt );
		$out   = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || 'WEBVTT' === $line || false !== strpos( $line, '-->' ) ) {
				continue;
			}

			if ( preg_match( '/^\d+$/', $line ) || preg_match( '/^(NOTE|STYLE|REGION)\b/', $line ) ) {
				continue;
			}

			$out[] = $line;
		}

		return trim( implode( ' ', $out ) );
	}

	/* ---------------------------------------------------------------------
	 * Checkpoints.
	 * ------------------------------------------------------------------ */

	/**
	 * A lesson's checkpoints, in time order.
	 *
	 * The correct answer is only included for someone who may edit the
	 * lesson. A student's copy carries the question and the options and
	 * nothing else — the answer is checked on the server, like every other
	 * answer in this plugin.
	 *
	 * @param int  $lesson_id  Lesson.
	 * @param bool $with_answers Include the correct index and explanation.
	 * @return array[]
	 */
	public static function checkpoints( $lesson_id, $with_answers = false ) {
		$raw = CES_Classroom_Cpt::json_meta( (int) $lesson_id, CES_Classroom_Cpt::LESSON_CHECKPOINTS );
		$out = array();

		foreach ( $raw as $index => $checkpoint ) {
			if ( ! is_array( $checkpoint ) || empty( $checkpoint['question'] ) || empty( $checkpoint['options'] ) ) {
				continue;
			}

			$entry = array(
				'index'    => (int) $index,
				't'        => max( 0, (int) ( isset( $checkpoint['t'] ) ? $checkpoint['t'] : 0 ) ),
				'question' => (string) $checkpoint['question'],
				'options'  => array_values( array_map( 'strval', (array) $checkpoint['options'] ) ),
			);

			$entry['stamp'] = self::stamp( $entry['t'] );

			if ( $with_answers ) {
				$entry['correct'] = (int) ( isset( $checkpoint['correct'] ) ? $checkpoint['correct'] : 0 );
				$entry['explain'] = (string) ( isset( $checkpoint['explain'] ) ? $checkpoint['explain'] : '' );
			}

			$out[] = $entry;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $a['t'] <=> $b['t'];
			}
		);

		return $out;
	}

	/**
	 * Save a lesson's checkpoints.
	 *
	 * @param int   $lesson_id Lesson.
	 * @param array $input     List of `{ t, question, options, correct, explain }`.
	 * @return array|WP_Error The stored checkpoints.
	 */
	public static function save_checkpoints( $lesson_id, array $input ) {
		if ( ! self::can_edit( $lesson_id ) ) {
			return new WP_Error( 'ces_checkpoint_denied', __( 'That is not your lesson.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		if ( ! self::checkpoints_enabled() ) {
			return new WP_Error( 'ces_checkpoint_off', __( 'Checkpoints are switched off on this site.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$clean = array();

		foreach ( $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$question = isset( $row['question'] ) ? CES_Chat::strip( $row['question'] ) : '';

			if ( mb_strlen( $question ) < 5 ) {
				continue;
			}

			$options = array();

			foreach ( (array) ( isset( $row['options'] ) ? $row['options'] : array() ) as $option ) {
				$option = CES_Chat::strip( $option );

				if ( '' !== $option ) {
					$options[] = mb_substr( $option, 0, 160 );
				}

				if ( count( $options ) >= 5 ) {
					break;
				}
			}

			if ( count( $options ) < 2 ) {
				continue;
			}

			$correct = (int) ( isset( $row['correct'] ) ? $row['correct'] : 0 );

			$clean[] = array(
				't'        => max( 0, (int) ( isset( $row['t'] ) ? $row['t'] : 0 ) ),
				'question' => mb_substr( $question, 0, 300 ),
				'options'  => $options,
				'correct'  => ( $correct >= 0 && $correct < count( $options ) ) ? $correct : 0,
				'explain'  => mb_substr( sanitize_textarea_field( (string) ( isset( $row['explain'] ) ? $row['explain'] : '' ) ), 0, 500 ),
			);

			if ( count( $clean ) >= 20 ) {
				break;
			}
		}

		CES_Classroom_Cpt::set_json_meta( (int) $lesson_id, CES_Classroom_Cpt::LESSON_CHECKPOINTS, $clean );

		return self::checkpoints( $lesson_id, true );
	}

	/**
	 * Mark a student's answer to a checkpoint.
	 *
	 * @param int $lesson_id Lesson.
	 * @param int $index     Checkpoint index.
	 * @param int $answer    The option they chose.
	 * @return array|WP_Error `array( 'correct' => bool, 'answer' => int, 'explain' => string )`
	 */
	public static function answer_checkpoint( $lesson_id, $index, $answer ) {
		$lesson_id = (int) $lesson_id;

		if ( ! CES_Classroom_Access::can_watch( $lesson_id ) ) {
			return new WP_Error( 'ces_checkpoint_denied', __( 'You are not in this class.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$raw = CES_Classroom_Cpt::json_meta( $lesson_id, CES_Classroom_Cpt::LESSON_CHECKPOINTS );

		// The index is the position in the stored list, which is the order
		// the student's copy was built from.
		$ordered = self::checkpoints( $lesson_id, true );
		$found   = null;

		foreach ( $ordered as $checkpoint ) {
			if ( (int) $checkpoint['index'] === (int) $index ) {
				$found = $checkpoint;
				break;
			}
		}

		unset( $raw );

		if ( null === $found ) {
			return new WP_Error( 'ces_checkpoint_missing', __( 'That question is no longer there.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$correct = (int) $found['correct'] === (int) $answer;

		return array(
			'correct' => $correct,
			'answer'  => (int) $found['correct'],
			'explain' => (string) $found['explain'],
		);
	}

	/* ---------------------------------------------------------------------
	 * The review.
	 * ------------------------------------------------------------------ */

	/**
	 * Score a lesson against what makes a teaching video work.
	 *
	 * Advisory only. A tutor can publish whatever they like; this exists so
	 * they find out what is missing before thirty students do.
	 *
	 * @param int $lesson_id Lesson.
	 * @return array {
	 *     @type int     $score   Out of 100.
	 *     @type array[] $checks  Each: key, label, pass, advice, weight.
	 *     @type string  $verdict One line.
	 * }
	 */
	public static function review( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$plan      = self::plan( $lesson_id );
		$duration  = class_exists( 'CES_Lms_Meta' ) ? (int) CES_Lms_Meta::duration( $lesson_id ) : 0;
		$chapters  = self::chapters( $lesson_id );
		$points    = self::checkpoints( $lesson_id );
		$title     = (string) get_the_title( $lesson_id );

		$checks = array();

		$checks[] = array(
			'key'    => 'video',
			'label'  => __( 'The video is uploaded and playable', 'classic-edu-suite' ),
			'pass'   => class_exists( 'CES_Lms_Meta' ) ? CES_Lms_Meta::is_playable( $lesson_id ) : false,
			'weight' => 25,
			'advice' => __( 'Record or upload the video. Until it is ready, students see a holding message.', 'classic-edu-suite' ),
		);

		$checks[] = array(
			'key'    => 'title',
			'label'  => __( 'The title says what the lesson teaches', 'classic-edu-suite' ),
			'pass'   => mb_strlen( trim( $title ) ) >= 12 && false === stripos( $title, 'untitled' ) && false === stripos( $title, 'lesson 1' ),
			'weight' => 8,
			'advice' => __( '“Solving quadratic equations by factorising” beats “Maths lesson 3”. The title is what a student chooses from.', 'classic-edu-suite' ),
		);

		$checks[] = array(
			'key'    => 'objective',
			'label'  => __( 'There is one clear objective', 'classic-edu-suite' ),
			'pass'   => mb_strlen( trim( $plan['objective'] ) ) >= 15,
			'weight' => 15,
			'advice' => __( 'Fill in “By the end of this lesson you will be able to…” in the plan. Students see it above the player.', 'classic-edu-suite' ),
		);

		$checks[] = array(
			'key'    => 'hook',
			'label'  => __( 'You have planned the opening', 'classic-edu-suite' ),
			'pass'   => mb_strlen( trim( $plan['hook'] ) ) >= 15,
			'weight' => 6,
			'advice' => __( 'The first fifteen seconds decide whether they stay. Plan them rather than starting with “so, erm…”.', 'classic-edu-suite' ),
		);

		$checks[] = array(
			'key'    => 'example',
			'label'  => __( 'There is a worked example', 'classic-edu-suite' ),
			'pass'   => mb_strlen( trim( $plan['example'] ) ) >= 15,
			'weight' => 10,
			'advice' => __( 'One real question worked all the way through teaches more than three explanations. Use a past question.', 'classic-edu-suite' ),
		);

		$checks[] = array(
			'key'    => 'length',
			'label'  => __( 'The length is right for one sitting', 'classic-edu-suite' ),
			'pass'   => $duration > 0 && $duration >= self::MIN_GOOD && $duration <= self::MAX_GOOD,
			'weight' => 10,
			'advice' => $duration > self::MAX_GOOD
				? __( 'Over twenty-five minutes, most students stop before the end. Split it into two lessons at a natural break.', 'classic-edu-suite' )
				: __( 'Aim for between three and twenty-five minutes. Shorter than three and it is a note, not a lesson.', 'classic-edu-suite' ),
		);

		$wants_chapters = $duration > 480;

		$checks[] = array(
			'key'    => 'chapters',
			'label'  => __( 'Long lessons have chapter markers', 'classic-edu-suite' ),
			'pass'   => ! $wants_chapters || count( $chapters ) >= 3,
			'weight' => 8,
			'advice' => __( 'Add chapters so a student revising can jump straight to the part they need instead of scrubbing.', 'classic-edu-suite' ),
		);

		$checks[] = array(
			'key'    => 'captions',
			'label'  => __( 'There are captions', 'classic-edu-suite' ),
			'pass'   => self::has_captions( $lesson_id ),
			'weight' => 10,
			'advice' => __( 'Most students watch on a phone with the sound low. Paste what you said and the Studio will time it for you.', 'classic-edu-suite' ),
		);

		if ( self::checkpoints_enabled() ) {
			$checks[] = array(
				'key'    => 'checkpoint',
				'label'  => __( 'At least one question stops the video', 'classic-edu-suite' ),
				'pass'   => count( $points ) >= 1,
				'weight' => 10,
				'advice' => __( 'A checkpoint turns watching into doing, and shows you exactly where your explanation did not land.', 'classic-edu-suite' ),
			);
		}

		$checks[] = array(
			'key'    => 'recap',
			'label'  => __( 'The lesson ends with a recap and something to do', 'classic-edu-suite' ),
			'pass'   => mb_strlen( trim( $plan['recap'] ) ) >= 10 && mb_strlen( trim( $plan['homework'] ) ) >= 5,
			'weight' => 8,
			'advice' => __( 'Three lines of recap and one specific task. “Revise this” is not a task.', 'classic-edu-suite' ),
		);

		$checks[] = array(
			'key'    => 'thumbnail',
			'label'  => __( 'There is a cover image', 'classic-edu-suite' ),
			'pass'   => has_post_thumbnail( $lesson_id ),
			'weight' => 4,
			'advice' => __( 'Grab a frame while recording, or upload a picture. A lesson with no cover looks unfinished.', 'classic-edu-suite' ),
		);

		$total  = 0;
		$earned = 0;

		foreach ( $checks as $check ) {
			$total += (int) $check['weight'];

			if ( ! empty( $check['pass'] ) ) {
				$earned += (int) $check['weight'];
			}
		}

		$score = $total > 0 ? (int) round( ( $earned / $total ) * 100 ) : 0;

		if ( $score >= 90 ) {
			$verdict = __( 'This is ready. Share the class link.', 'classic-edu-suite' );
		} elseif ( $score >= 70 ) {
			$verdict = __( 'Good enough to publish. The items below would make it better.', 'classic-edu-suite' );
		} elseif ( $score >= 40 ) {
			$verdict = __( 'Publishable, but students will feel the gaps. Fix the big ones first.', 'classic-edu-suite' );
		} else {
			$verdict = __( 'Not ready yet. Start with the video and the objective.', 'classic-edu-suite' );
		}

		/**
		 * Filter the lesson quality checks.
		 *
		 * @param array[] $checks    The checks.
		 * @param int     $lesson_id Lesson.
		 */
		$checks = (array) apply_filters( 'ces_lesson_review_checks', $checks, $lesson_id );

		return array(
			'score'   => $score,
			'checks'  => $checks,
			'verdict' => $verdict,
		);
	}
}
