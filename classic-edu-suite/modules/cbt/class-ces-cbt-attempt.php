<?php
/**
 * Attempt lifecycle: start, autosave, submit, score.
 *
 * Everything that decides a mark happens in this file, on the server. The
 * browser is told how much time is left and which questions exist; it is never
 * told a correct answer before submission, and its clock is never trusted.
 *
 * Time handling: every datetime column holds site-local time. All arithmetic
 * in this class goes through `to_ts()` / `from_ts()`, which treat those
 * strings as a single consistent shifted epoch, so differences and
 * comparisons are correct without juggling timezones. `epoch()` converts back
 * to a real UTC timestamp for the one case that needs it, syncing the
 * browser's countdown.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exam attempts.
 */
class CES_Cbt_Attempt {

	/**
	 * Attempt is open and answerable.
	 */
	const STATUS_IN_PROGRESS = 'in_progress';

	/**
	 * Attempt is finished and scored. Every completed attempt uses this
	 * status, whatever finished it; `meta.submit_reason` records which.
	 */
	const STATUS_SUBMITTED = 'submitted';

	/**
	 * Grace period before the cron sweeper touches an expired attempt.
	 */
	const EXPIRY_GRACE = 60;

	/**
	 * How many lockdown events are retained per attempt.
	 */
	const MAX_EVENTS = 50;

	/* ---------------------------------------------------------------------
	 * Time helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Convert a stored MySQL datetime into a comparable timestamp.
	 *
	 * @param string $mysql MySQL datetime, or empty.
	 * @return int 0 when the value is empty or unparseable.
	 */
	public static function to_ts( $mysql ) {
		$mysql = (string) $mysql;

		if ( '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
			return 0;
		}

		$ts = strtotime( $mysql . ' +0000' );

		return $ts ? (int) $ts : 0;
	}

	/**
	 * Convert a timestamp from `to_ts()` back to a MySQL datetime.
	 *
	 * @param int $ts Timestamp.
	 * @return string
	 */
	public static function from_ts( $ts ) {
		return gmdate( 'Y-m-d H:i:s', (int) $ts );
	}

	/**
	 * "Now" on the same scale as `to_ts()`.
	 *
	 * @return int
	 */
	public static function now_ts() {
		return self::to_ts( CES_Helpers::now() );
	}

	/**
	 * Site UTC offset in seconds.
	 *
	 * @return int
	 */
	private static function gmt_offset() {
		return (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	}

	/**
	 * Real UTC timestamp for a stored datetime, for syncing the browser clock.
	 *
	 * @param string $mysql MySQL datetime.
	 * @return int
	 */
	public static function epoch( $mysql ) {
		$ts = self::to_ts( $mysql );

		return $ts ? $ts - self::gmt_offset() : 0;
	}

	/* ---------------------------------------------------------------------
	 * Row access.
	 * ------------------------------------------------------------------ */

	/**
	 * Fetch one attempt row.
	 *
	 * @param int $attempt_id Attempt ID.
	 * @return object|null
	 */
	public static function get( $attempt_id ) {
		global $wpdb;

		$attempt_id = absint( $attempt_id );

		if ( ! $attempt_id ) {
			return null;
		}

		$table = CES_Helpers::table( 'attempts' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $attempt_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * Fetch an attempt and confirm it belongs to the user.
	 *
	 * @param int $attempt_id Attempt ID.
	 * @param int $user_id    User ID; 0 skips the ownership check (cron).
	 * @return object|WP_Error
	 */
	public static function get_for_user( $attempt_id, $user_id ) {
		$row = self::get( $attempt_id );

		if ( ! $row ) {
			return new WP_Error(
				'ces_cbt_attempt_not_found',
				__( 'That attempt does not exist.', 'classic-edu-suite' ),
				array( 'status' => 404 )
			);
		}

		$user_id = absint( $user_id );

		if ( $user_id && (int) $row->user_id !== $user_id && ! CES_Helpers::can_manage( CES_Cbt_Cpt::CAP ) ) {
			return new WP_Error(
				'ces_cbt_forbidden',
				__( 'That attempt belongs to another student.', 'classic-edu-suite' ),
				array( 'status' => 403 )
			);
		}

		return $row;
	}

	/**
	 * Question IDs recorded on an attempt, in presentation order.
	 *
	 * @param object $row Attempt row.
	 * @return int[]
	 */
	public static function question_ids( $row ) {
		if ( ! $row || empty( $row->question_ids ) ) {
			return array();
		}

		$ids = json_decode( (string) $row->question_ids, true );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Decoded `meta` column.
	 *
	 * @param object $row Attempt row.
	 * @return array
	 */
	public static function meta( $row ) {
		if ( ! $row || empty( $row->meta ) ) {
			return array();
		}

		$meta = json_decode( (string) $row->meta, true );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Merge a patch into the `meta` column.
	 *
	 * @param int   $attempt_id Attempt ID.
	 * @param array $patch      Keys to set.
	 * @return array The merged meta.
	 */
	public static function update_meta( $attempt_id, array $patch ) {
		global $wpdb;

		$row = self::get( $attempt_id );

		if ( ! $row ) {
			return array();
		}

		$meta  = array_merge( self::meta( $row ), $patch );
		$table = CES_Helpers::table( 'attempts' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET meta = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				wp_json_encode( $meta ),
				absint( $attempt_id )
			)
		);

		return $meta;
	}

	/* ---------------------------------------------------------------------
	 * Starting an attempt.
	 * ------------------------------------------------------------------ */

	/**
	 * Find a student's open attempt at an exam.
	 *
	 * @param int $user_id User ID.
	 * @param int $exam_id Exam post ID.
	 * @return object|null
	 */
	public static function find_in_progress( $user_id, $exam_id ) {
		global $wpdb;

		$table = CES_Helpers::table( 'attempts' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND exam_id = %d AND status = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id ),
				absint( $exam_id ),
				self::STATUS_IN_PROGRESS
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Count a student's completed attempts at an exam.
	 *
	 * @param int $user_id User ID.
	 * @param int $exam_id Exam post ID.
	 * @return int
	 */
	public static function completed_count( $user_id, $exam_id ) {
		global $wpdb;

		$table = CES_Helpers::table( 'attempts' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND exam_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id ),
				absint( $exam_id ),
				self::STATUS_SUBMITTED
			)
		);
	}

	/**
	 * The most recent completed attempt at an exam.
	 *
	 * @param int $user_id User ID.
	 * @param int $exam_id Exam post ID.
	 * @return object|null
	 */
	public static function last_completed( $user_id, $exam_id ) {
		global $wpdb;

		$table = CES_Helpers::table( 'attempts' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND exam_id = %d AND status = %s ORDER BY submitted_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id ),
				absint( $exam_id ),
				self::STATUS_SUBMITTED
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Whether the student may start a fresh attempt.
	 *
	 * Enforces `cbt_allow_retake`, `cbt_retake_cooldown` and the exam's own
	 * maximum attempt count.
	 *
	 * @param int   $user_id User ID.
	 * @param int   $exam_id Exam post ID.
	 * @param array $config  Exam configuration from `CES_Cbt_Exam::config()`.
	 * @return true|WP_Error
	 */
	public static function retake_check( $user_id, $exam_id, array $config ) {
		$done = self::completed_count( $user_id, $exam_id );

		if ( $done < 1 ) {
			return true;
		}

		if ( ! CES_Helpers::enabled( 'cbt_allow_retake' ) ) {
			return new WP_Error(
				'ces_cbt_no_retake',
				__( 'You have already sat this exam and retakes are turned off.', 'classic-edu-suite' ),
				array( 'status' => 403 )
			);
		}

		$max = isset( $config['max_attempts'] ) ? (int) $config['max_attempts'] : 0;

		if ( $max > 0 && $done >= $max ) {
			return new WP_Error(
				'ces_cbt_attempts_exhausted',
				sprintf(
					/* translators: %d: maximum number of attempts allowed. */
					__( 'You have used all %d attempts at this exam.', 'classic-edu-suite' ),
					$max
				),
				array( 'status' => 403 )
			);
		}

		$cooldown = CES_Helpers::setting_int( 'cbt_retake_cooldown', 0 ) * MINUTE_IN_SECONDS;

		if ( $cooldown > 0 ) {
			$last = self::last_completed( $user_id, $exam_id );
			$then = $last ? self::to_ts( $last->submitted_at ) : 0;

			if ( $then ) {
				$wait = ( $then + $cooldown ) - self::now_ts();

				if ( $wait > 0 ) {
					return new WP_Error(
						'ces_cbt_cooldown',
						sprintf(
							/* translators: %s: human readable time difference, e.g. "15 mins". */
							__( 'You can retake this exam in %s.', 'classic-edu-suite' ),
							human_time_diff( self::now_ts(), $then + $cooldown )
						),
						array(
							'status' => 429,
							'wait'   => $wait,
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Start, or resume, an attempt.
	 *
	 * Resuming is the default: a student who reloads, loses power or switches
	 * device lands back on the same paper with the same clock, rather than
	 * silently creating a second attempt.
	 *
	 * @param int $user_id User ID.
	 * @param int $exam_id Exam post ID.
	 * @return array|WP_Error The client payload, or an error.
	 */
	public static function start( $user_id, $exam_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$exam_id = absint( $exam_id );

		if ( ! $user_id ) {
			return new WP_Error(
				'ces_cbt_login_required',
				__( 'Please log in to sit an exam.', 'classic-edu-suite' ),
				array( 'status' => 401 )
			);
		}

		$config = CES_Cbt_Exam::config( $exam_id );

		if ( ! $config || 'publish' !== $config['status'] ) {
			return new WP_Error(
				'ces_cbt_exam_not_found',
				__( 'That exam is not available.', 'classic-edu-suite' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $config['active'] ) {
			return new WP_Error(
				'ces_cbt_exam_closed',
				__( 'That exam is closed at the moment.', 'classic-edu-suite' ),
				array( 'status' => 403 )
			);
		}

		// Resume, or close out, an attempt that is already open.
		$open = self::find_in_progress( $user_id, $exam_id );

		if ( $open ) {
			if ( ! self::is_expired( $open ) ) {
				return self::payload( $open );
			}

			// Its time ran out while the student was away: score it now, then
			// fall through to the retake rules for a fresh sitting.
			self::submit( (int) $open->id, $user_id, 'expired' );
		}

		$allowed = self::retake_check( $user_id, $exam_id, $config );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$question_ids = CES_Cbt_Exam::select_question_ids( $exam_id );

		if ( empty( $question_ids ) ) {
			return new WP_Error(
				'ces_cbt_no_questions',
				__( 'This exam has no questions yet. Please tell your tutor.', 'classic-edu-suite' ),
				array( 'status' => 409 )
			);
		}

		// Record the option order per question so a resumed or reviewed paper
		// looks exactly as it did when the student first saw it. Option keys
		// stay canonical, so shuffling display order cannot affect marking.
		$option_order = array();
		$total_marks  = 0.0;

		foreach ( $question_ids as $qid ) {
			$question = CES_Cbt_Question::get( $qid );

			if ( ! $question ) {
				continue;
			}

			$total_marks += (float) $question['marks'];

			$keys = CES_Cbt_Question::option_keys( $question );

			if ( $config['shuffle_options'] && CES_Cbt_Question::TYPE_TRUEFALSE !== $question['type'] ) {
				shuffle( $keys );
			}

			$option_order[ (string) $qid ] = $keys;
		}

		$now_ts  = self::now_ts();
		$seconds = max( MINUTE_IN_SECONDS, (int) $config['duration_seconds'] );

		$meta = array(
			'exam_title'      => $config['title'],
			'pass_mark'       => (int) $config['pass_mark'],
			'total_marks'     => round( $total_marks, 2 ),
			'shuffle_options' => $config['shuffle_options'] ? 1 : 0,
			'option_order'    => $option_order,
			'lockdown'        => array(
				'blur'   => 0,
				'events' => array(),
			),
		);

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			CES_Helpers::table( 'attempts' ),
			array(
				'user_id'         => $user_id,
				'exam_id'         => $exam_id,
				'status'          => self::STATUS_IN_PROGRESS,
				'question_ids'    => wp_json_encode( array_values( $question_ids ) ),
				'total_questions' => count( $question_ids ),
				'correct_count'   => 0,
				'score'           => 0,
				'passed'          => 0,
				'duration_limit'  => $seconds,
				'time_spent'      => 0,
				'started_at'      => self::from_ts( $now_ts ),
				'expires_at'      => self::from_ts( $now_ts + $seconds ),
				'ip_address'      => CES_Helpers::ip(),
				'meta'            => wp_json_encode( $meta ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%d', '%f', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error(
				'ces_cbt_start_failed',
				__( 'Could not start the exam. Please try again.', 'classic-edu-suite' ),
				array( 'status' => 500 )
			);
		}

		$row = self::get( (int) $wpdb->insert_id );

		if ( ! $row ) {
			return new WP_Error(
				'ces_cbt_start_failed',
				__( 'Could not start the exam. Please try again.', 'classic-edu-suite' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires once an attempt row exists and the clock is running.
		 *
		 * @param int $attempt_id Attempt ID.
		 * @param int $user_id    User ID.
		 * @param int $exam_id    Exam post ID.
		 */
		do_action( 'ces_cbt_attempt_started', (int) $row->id, $user_id, $exam_id );

		return self::payload( $row );
	}

	/* ---------------------------------------------------------------------
	 * The clock.
	 * ------------------------------------------------------------------ */

	/**
	 * Seconds left on an attempt, never below zero.
	 *
	 * @param object $row Attempt row.
	 * @return int
	 */
	public static function remaining_seconds( $row ) {
		if ( ! $row ) {
			return 0;
		}

		$expires = self::to_ts( $row->expires_at );

		if ( ! $expires ) {
			return 0;
		}

		return max( 0, $expires - self::now_ts() );
	}

	/**
	 * Whether an attempt's deadline has passed.
	 *
	 * @param object $row Attempt row.
	 * @return bool
	 */
	public static function is_expired( $row ) {
		if ( ! $row ) {
			return true;
		}

		$expires = self::to_ts( $row->expires_at );

		if ( ! $expires ) {
			return false;
		}

		return self::now_ts() > $expires;
	}

	/* ---------------------------------------------------------------------
	 * Answers.
	 * ------------------------------------------------------------------ */

	/**
	 * All stored answers for an attempt, keyed by question ID.
	 *
	 * @param int $attempt_id Attempt ID.
	 * @return array
	 */
	public static function answers( $attempt_id ) {
		global $wpdb;

		$table = CES_Helpers::table( 'attempt_answers' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT question_id, given_answer, is_correct, flagged, answered_at FROM {$table} WHERE attempt_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $attempt_id )
			)
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->question_id ] = array(
				'given'       => (string) $row->given_answer,
				'is_correct'  => (int) $row->is_correct,
				'flagged'     => (int) $row->flagged,
				'answered_at' => (string) $row->answered_at,
			);
		}

		return $out;
	}

	/**
	 * Autosave one answer.
	 *
	 * Idempotent on the `(attempt_id, question_id)` unique index, so the
	 * offline retry queue in the browser can replay the same save as often as
	 * it likes. The `flagged` column is deliberately left alone here.
	 *
	 * @param int   $attempt_id  Attempt ID.
	 * @param int   $question_id Question post ID.
	 * @param mixed $given       Raw answer: a key, or a list of keys.
	 * @param int   $user_id     User making the request.
	 * @return array|WP_Error
	 */
	public static function save_answer( $attempt_id, $question_id, $given, $user_id ) {
		global $wpdb;

		$row = self::get_for_user( $attempt_id, $user_id );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( self::STATUS_IN_PROGRESS !== (string) $row->status ) {
			return new WP_Error(
				'ces_cbt_already_submitted',
				__( 'This paper has already been submitted.', 'classic-edu-suite' ),
				array( 'status' => 409 )
			);
		}

		// The deadline is enforced here as well as at submit time: an answer
		// that arrives late is never stored, so it can never be scored.
		if ( self::is_expired( $row ) ) {
			self::submit( (int) $row->id, (int) $row->user_id, 'expired' );

			return new WP_Error(
				'ces_cbt_expired',
				__( 'Time is up. Your paper has been submitted with the answers already saved.', 'classic-edu-suite' ),
				array( 'status' => 410 )
			);
		}

		$question_id = absint( $question_id );

		if ( ! in_array( $question_id, self::question_ids( $row ), true ) ) {
			return new WP_Error(
				'ces_cbt_bad_question',
				__( 'That question is not part of this paper.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		$question = CES_Cbt_Question::get( $question_id );

		if ( ! $question ) {
			return new WP_Error(
				'ces_cbt_bad_question',
				__( 'That question no longer exists.', 'classic-edu-suite' ),
				array( 'status' => 410 )
			);
		}

		$keys  = CES_Cbt_Question::normalize_answer( $given, CES_Cbt_Question::option_keys( $question ) );
		$table = CES_Helpers::table( 'attempt_answers' );
		$now   = self::from_ts( self::now_ts() );

		// Clearing an answer is a legitimate save: it stores an empty string.
		if ( empty( $keys ) ) {
			$stored = '';
			$grade  = array(
				'is_correct' => false,
				'marks'      => 0.0,
			);
		} else {
			if ( CES_Cbt_Question::TYPE_MULTI !== $question['type'] ) {
				$keys = array( reset( $keys ) );
			}

			$stored = CES_Cbt_Question::answer_to_storage( $keys );
			$grade  = CES_Cbt_Question::grade( $question, $keys );
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT INTO {$table} (attempt_id, question_id, given_answer, is_correct, flagged, answered_at)
				VALUES (%d, %d, %s, %d, 0, %s)
				ON DUPLICATE KEY UPDATE given_answer = VALUES(given_answer), is_correct = VALUES(is_correct), answered_at = VALUES(answered_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $row->id,
				$question_id,
				$stored,
				$grade['is_correct'] ? 1 : 0,
				$now
			)
		);

		return array(
			'attemptId'  => (int) $row->id,
			'questionId' => $question_id,
			'given'      => $keys,
			'saved'      => true,
			'remaining'  => self::remaining_seconds( $row ),
			'serverTime' => time(),
		);
	}

	/**
	 * Flag or unflag a question for review.
	 *
	 * @param int  $attempt_id  Attempt ID.
	 * @param int  $question_id Question post ID.
	 * @param bool $flagged     Desired state.
	 * @param int  $user_id     User making the request.
	 * @return array|WP_Error
	 */
	public static function set_flag( $attempt_id, $question_id, $flagged, $user_id ) {
		global $wpdb;

		$row = self::get_for_user( $attempt_id, $user_id );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( self::STATUS_IN_PROGRESS !== (string) $row->status ) {
			return new WP_Error(
				'ces_cbt_already_submitted',
				__( 'This paper has already been submitted.', 'classic-edu-suite' ),
				array( 'status' => 409 )
			);
		}

		$question_id = absint( $question_id );

		if ( ! in_array( $question_id, self::question_ids( $row ), true ) ) {
			return new WP_Error(
				'ces_cbt_bad_question',
				__( 'That question is not part of this paper.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		$table = CES_Helpers::table( 'attempt_answers' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT INTO {$table} (attempt_id, question_id, given_answer, is_correct, flagged, answered_at)
				VALUES (%d, %d, '', 0, %d, %s)
				ON DUPLICATE KEY UPDATE flagged = VALUES(flagged)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $row->id,
				$question_id,
				$flagged ? 1 : 0,
				self::from_ts( self::now_ts() )
			)
		);

		return array(
			'attemptId'  => (int) $row->id,
			'questionId' => $question_id,
			'flagged'    => (bool) $flagged,
		);
	}

	/* ---------------------------------------------------------------------
	 * Submission and scoring.
	 * ------------------------------------------------------------------ */

	/**
	 * Score and close an attempt.
	 *
	 * Scoring reads the correct answers straight from post meta; nothing the
	 * browser sent is used except the stored `given_answer` values, which were
	 * themselves validated against the question's own option keys on save.
	 *
	 * Answers saved after `expires_at` are excluded, so a late submission is
	 * marked only on work done inside the window.
	 *
	 * Calling this on an already-submitted attempt is a no-op that returns the
	 * existing result, which makes the client's retry-on-failure safe.
	 *
	 * @param int    $attempt_id Attempt ID.
	 * @param int    $user_id    User making the request; 0 for cron.
	 * @param string $reason     One of `user`, `expired`, `cron`.
	 * @return array|WP_Error
	 */
	public static function submit( $attempt_id, $user_id = 0, $reason = 'user' ) {
		global $wpdb;

		$row = self::get_for_user( $attempt_id, $user_id );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( self::STATUS_IN_PROGRESS !== (string) $row->status ) {
			return self::result( (int) $row->id, $user_id );
		}

		$attempt_id   = (int) $row->id;
		$question_ids = self::question_ids( $row );
		$stored       = self::answers( $attempt_id );
		$meta         = self::meta( $row );

		$expires_ts = self::to_ts( $row->expires_at );
		$started_ts = self::to_ts( $row->started_at );
		$now_ts     = self::now_ts();

		$correct_count = 0;
		$earned        = 0.0;
		$total_marks   = 0.0;
		$answered      = 0;
		$fixups        = array();

		foreach ( $question_ids as $qid ) {
			$question = CES_Cbt_Question::get( $qid );

			if ( ! $question ) {
				continue;
			}

			$total_marks += (float) $question['marks'];

			$given = '';

			if ( isset( $stored[ $qid ] ) ) {
				$answered_ts = self::to_ts( $stored[ $qid ]['answered_at'] );

				// Hard deadline: work saved after the clock ran out does not count.
				if ( ! $expires_ts || ! $answered_ts || $answered_ts <= $expires_ts + 2 ) {
					$given = $stored[ $qid ]['given'];
				}
			}

			if ( '' === $given ) {
				if ( isset( $stored[ $qid ] ) && 0 !== (int) $stored[ $qid ]['is_correct'] ) {
					$fixups[ $qid ] = 0;
				}
				continue;
			}

			++$answered;

			$grade = CES_Cbt_Question::grade( $question, $given );

			if ( $grade['is_correct'] ) {
				++$correct_count;
				$earned += (float) $grade['marks'];
			}

			$expected = $grade['is_correct'] ? 1 : 0;

			if ( isset( $stored[ $qid ] ) && (int) $stored[ $qid ]['is_correct'] !== $expected ) {
				$fixups[ $qid ] = $expected;
			}
		}

		// Reconcile the opportunistic is_correct written at autosave time with
		// this authoritative pass, so per-question analytics stay honest.
		if ( ! empty( $fixups ) ) {
			$answers_table = CES_Helpers::table( 'attempt_answers' );

			foreach ( $fixups as $qid => $value ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"UPDATE {$answers_table} SET is_correct = %d WHERE attempt_id = %d AND question_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						(int) $value,
						$attempt_id,
						absint( $qid )
					)
				);
			}
		}

		$score = ( $total_marks > 0 ) ? round( ( $earned / $total_marks ) * 100, 2 ) : 0.00;

		$pass_mark = isset( $meta['pass_mark'] ) ? (int) $meta['pass_mark'] : CES_Helpers::setting_int( 'cbt_pass_mark', 50 );
		$passed    = ( $score >= $pass_mark ) ? 1 : 0;

		$limit      = max( 0, (int) $row->duration_limit );
		$elapsed    = ( $started_ts && $now_ts > $started_ts ) ? ( $now_ts - $started_ts ) : 0;
		$time_spent = $limit > 0 ? min( $elapsed, $limit ) : $elapsed;

		$reason = in_array( $reason, array( 'user', 'expired', 'cron' ), true ) ? $reason : 'user';

		$meta['submit_reason'] = $reason;
		$meta['total_marks']   = round( $total_marks, 2 );
		$meta['earned_marks']  = round( $earned, 2 );
		$meta['answered']      = $answered;
		$meta['pass_mark']     = $pass_mark;

		$table = CES_Helpers::table( 'attempts' );

		// The status guard makes a double submit impossible, so the coin award
		// below can only ever fire once per attempt.
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, correct_count = %d, score = %f, passed = %d, time_spent = %d, submitted_at = %s, meta = %s WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_SUBMITTED,
				$correct_count,
				$score,
				$passed,
				$time_spent,
				self::from_ts( $now_ts ),
				wp_json_encode( $meta ),
				$attempt_id,
				self::STATUS_IN_PROGRESS
			)
		);

		if ( $updated ) {
			/**
			 * Fires when an attempt is scored.
			 *
			 * The wallet module listens to this to award coins and to qualify
			 * referrals. The signature is part of the cross-module contract:
			 * do not change it.
			 *
			 * @param int   $user_id    Student's user ID.
			 * @param int   $attempt_id Attempt ID.
			 * @param float $score      Percentage score.
			 * @param bool  $passed     Whether the pass mark was reached.
			 */
			do_action( 'ces_quiz_completed', (int) $row->user_id, $attempt_id, (float) $score, (bool) $passed );
		}

		return self::result( $attempt_id, $user_id );
	}

	/**
	 * Cron sweeper: score attempts whose time ran out and were never submitted.
	 *
	 * @return int How many attempts were closed.
	 */
	public static function expire_stale() {
		global $wpdb;

		$table  = CES_Helpers::table( 'attempts' );
		$cutoff = self::from_ts( self::now_ts() - self::EXPIRY_GRACE );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s ORDER BY id ASC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_IN_PROGRESS,
				$cutoff
			)
		);

		$closed = 0;

		foreach ( (array) $rows as $row ) {
			$result = self::submit( (int) $row->id, 0, 'cron' );

			if ( ! is_wp_error( $result ) ) {
				++$closed;
			}
		}

		if ( $closed ) {
			CES_Helpers::log( 'CBT: auto-submitted stale attempts', $closed );
		}

		return $closed;
	}

	/* ---------------------------------------------------------------------
	 * Payloads.
	 * ------------------------------------------------------------------ */

	/**
	 * The in-exam payload: everything the runner needs, nothing it must not have.
	 *
	 * @param object $row Attempt row.
	 * @return array
	 */
	public static function payload( $row ) {
		$attempt_id   = (int) $row->id;
		$meta         = self::meta( $row );
		$question_ids = self::question_ids( $row );
		$stored       = self::answers( $attempt_id );
		$orders       = isset( $meta['option_order'] ) && is_array( $meta['option_order'] ) ? $meta['option_order'] : array();

		$questions = array();
		$answers   = array();
		$flags     = array();

		foreach ( $question_ids as $qid ) {
			$question = CES_Cbt_Question::get( $qid );

			if ( ! $question ) {
				continue;
			}

			$order = isset( $orders[ (string) $qid ] ) && is_array( $orders[ (string) $qid ] ) ? $orders[ (string) $qid ] : array();

			$questions[] = CES_Cbt_Question::for_client( $question, $order );

			if ( isset( $stored[ $qid ] ) ) {
				if ( '' !== $stored[ $qid ]['given'] ) {
					$answers[ (string) $qid ] = explode( ',', $stored[ $qid ]['given'] );
				}

				if ( ! empty( $stored[ $qid ]['flagged'] ) ) {
					$flags[] = $qid;
				}
			}
		}

		$exam_config = CES_Cbt_Exam::config( (int) $row->exam_id );

		return array(
			'attemptId'       => $attempt_id,
			'examId'          => (int) $row->exam_id,
			'examTitle'       => isset( $meta['exam_title'] ) ? (string) $meta['exam_title'] : __( 'Exam', 'classic-edu-suite' ),
			'instructions'    => $exam_config ? CES_Cbt_Question::sanitize_rich_text( wpautop( $exam_config['instructions'] ) ) : '',
			'status'          => (string) $row->status,
			'totalQuestions'  => count( $questions ),
			'passMark'        => isset( $meta['pass_mark'] ) ? (int) $meta['pass_mark'] : CES_Helpers::setting_int( 'cbt_pass_mark', 50 ),
			'durationSeconds' => (int) $row->duration_limit,
			'remaining'       => self::remaining_seconds( $row ),
			'expiresAt'       => self::epoch( $row->expires_at ),
			'serverTime'      => time(),
			'questions'       => $questions,
			'answers'         => $answers,
			'flags'           => array_values( array_unique( array_map( 'absint', $flags ) ) ),
			'lockdown'        => CES_Helpers::enabled( 'cbt_lockdown_mode' ),
			'resultUrl'       => CES_Helpers::dashboard_url( 'cbt-results' ),
		);
	}

	/**
	 * The result payload for a finished attempt.
	 *
	 * Per-question review, with the correct answer and explanation, is gated
	 * on the `cbt_show_answers_after` setting.
	 *
	 * @param int $attempt_id Attempt ID.
	 * @param int $user_id    User making the request; 0 for internal callers.
	 * @return array|WP_Error
	 */
	public static function result( $attempt_id, $user_id = 0 ) {
		$row = self::get_for_user( $attempt_id, $user_id );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$attempt_id = (int) $row->id;
		$meta       = self::meta( $row );
		$finished   = self::STATUS_IN_PROGRESS !== (string) $row->status;

		if ( ! $finished ) {
			return new WP_Error(
				'ces_cbt_not_submitted',
				__( 'That paper has not been submitted yet.', 'classic-edu-suite' ),
				array( 'status' => 409 )
			);
		}

		$reveal = CES_Helpers::enabled( 'cbt_show_answers_after' );

		/**
		 * Filter whether correct answers are revealed on this result screen.
		 *
		 * @param bool   $reveal     Whether to reveal.
		 * @param object $row        Attempt row.
		 * @param int    $attempt_id Attempt ID.
		 */
		$reveal = (bool) apply_filters( 'ces_cbt_reveal_answers', $reveal, $row, $attempt_id );

		$stored    = self::answers( $attempt_id );
		$questions = array();

		foreach ( self::question_ids( $row ) as $qid ) {
			$question = CES_Cbt_Question::get( $qid );

			if ( ! $question ) {
				continue;
			}

			$given = isset( $stored[ $qid ] ) ? $stored[ $qid ]['given'] : '';
			$given = ( '' === $given ) ? array() : explode( ',', $given );

			$review = CES_Cbt_Question::for_review( $question, $given, $reveal );

			$review['flagged'] = ! empty( $stored[ $qid ]['flagged'] );

			$questions[] = $review;
		}

		$lockdown = isset( $meta['lockdown'] ) && is_array( $meta['lockdown'] ) ? $meta['lockdown'] : array();

		return array(
			'attemptId'      => $attempt_id,
			'examId'         => (int) $row->exam_id,
			'examTitle'      => isset( $meta['exam_title'] ) ? (string) $meta['exam_title'] : __( 'Exam', 'classic-edu-suite' ),
			'score'          => (float) $row->score,
			'passMark'       => isset( $meta['pass_mark'] ) ? (int) $meta['pass_mark'] : CES_Helpers::setting_int( 'cbt_pass_mark', 50 ),
			'passed'         => (bool) $row->passed,
			'correctCount'   => (int) $row->correct_count,
			'totalQuestions' => (int) $row->total_questions,
			'answered'       => isset( $meta['answered'] ) ? (int) $meta['answered'] : 0,
			'totalMarks'     => isset( $meta['total_marks'] ) ? (float) $meta['total_marks'] : 0.0,
			'earnedMarks'    => isset( $meta['earned_marks'] ) ? (float) $meta['earned_marks'] : 0.0,
			'timeSpent'      => (int) $row->time_spent,
			'submittedAt'    => (string) $row->submitted_at,
			'submitReason'   => isset( $meta['submit_reason'] ) ? (string) $meta['submit_reason'] : 'user',
			'revealAnswers'  => $reveal,
			'blurEvents'     => isset( $lockdown['blur'] ) ? (int) $lockdown['blur'] : 0,
			'questions'      => $questions,
		);
	}

	/* ---------------------------------------------------------------------
	 * Lockdown logging.
	 * ------------------------------------------------------------------ */

	/**
	 * Record a lockdown event against an attempt.
	 *
	 * This is a deterrent and an audit trail, nothing more: a determined
	 * student can disable JavaScript. See the README.
	 *
	 * @param int    $attempt_id Attempt ID.
	 * @param string $type       Event type, e.g. `blur`, `visibility`, `copy`.
	 * @param int    $user_id    User making the request.
	 * @return array|WP_Error
	 */
	public static function log_event( $attempt_id, $type, $user_id ) {
		$row = self::get_for_user( $attempt_id, $user_id );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( self::STATUS_IN_PROGRESS !== (string) $row->status ) {
			return array(
				'logged' => false,
				'blur'   => 0,
			);
		}

		$type = sanitize_key( $type );

		if ( ! in_array( $type, array( 'blur', 'visibility', 'copy', 'contextmenu', 'resize' ), true ) ) {
			$type = 'blur';
		}

		$meta     = self::meta( $row );
		$lockdown = isset( $meta['lockdown'] ) && is_array( $meta['lockdown'] ) ? $meta['lockdown'] : array();

		$events = isset( $lockdown['events'] ) && is_array( $lockdown['events'] ) ? $lockdown['events'] : array();
		$blur   = isset( $lockdown['blur'] ) ? (int) $lockdown['blur'] : 0;

		++$blur;

		$events[] = array(
			'type' => $type,
			'at'   => self::from_ts( self::now_ts() ),
		);

		if ( count( $events ) > self::MAX_EVENTS ) {
			$events = array_slice( $events, -self::MAX_EVENTS );
		}

		self::update_meta(
			(int) $row->id,
			array(
				'lockdown' => array(
					'blur'   => $blur,
					'events' => $events,
				),
			)
		);

		return array(
			'logged' => true,
			'blur'   => $blur,
		);
	}

	/* ---------------------------------------------------------------------
	 * Reporting.
	 * ------------------------------------------------------------------ */

	/**
	 * A student's own attempt history.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Rows to return.
	 * @param int $offset  Rows to skip.
	 * @return array List of row arrays.
	 */
	public static function user_results( $user_id, $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table = CES_Helpers::table( 'attempts' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, exam_id, status, score, passed, correct_count, total_questions, time_spent, submitted_at, started_at, meta
				FROM {$table}
				WHERE user_id = %d
				ORDER BY id DESC
				LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id ),
				max( 1, min( 100, (int) $limit ) ),
				max( 0, (int) $offset )
			)
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$meta = json_decode( (string) $row->meta, true );
			$meta = is_array( $meta ) ? $meta : array();

			$out[] = array(
				'attemptId'      => (int) $row->id,
				'examId'         => (int) $row->exam_id,
				'examTitle'      => isset( $meta['exam_title'] ) ? (string) $meta['exam_title'] : get_the_title( (int) $row->exam_id ),
				'status'         => (string) $row->status,
				'score'          => (float) $row->score,
				'passed'         => (bool) $row->passed,
				'correctCount'   => (int) $row->correct_count,
				'totalQuestions' => (int) $row->total_questions,
				'timeSpent'      => (int) $row->time_spent,
				'submittedAt'    => (string) $row->submitted_at,
				'startedAt'      => (string) $row->started_at,
			);
		}

		return $out;
	}

	/**
	 * Count a student's attempts, for pagination.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function user_result_count( $user_id ) {
		global $wpdb;

		$table = CES_Helpers::table( 'attempts' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			)
		);
	}

	/**
	 * Leaderboard rows.
	 *
	 * With an exam ID, ranks each student's best score at that paper, fastest
	 * time breaking ties. Without one, ranks across every exam by number of
	 * passes then average score.
	 *
	 * @param int $exam_id Exam post ID, or 0 for the all-exam board.
	 * @param int $limit   Rows to return.
	 * @return array
	 */
	public static function leaderboard( $exam_id = 0, $limit = 20 ) {
		global $wpdb;

		$table   = CES_Helpers::table( 'attempts' );
		$limit   = max( 1, min( 100, (int) $limit ) );
		$exam_id = absint( $exam_id );

		if ( $exam_id ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT user_id, MAX(score) AS best_score, MIN(time_spent) AS best_time, COUNT(*) AS attempts
					FROM {$table}
					WHERE exam_id = %d AND status = %s AND user_id > 0
					GROUP BY user_id
					ORDER BY best_score DESC, best_time ASC
					LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$exam_id,
					self::STATUS_SUBMITTED,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT user_id, AVG(score) AS best_score, MIN(time_spent) AS best_time, COUNT(*) AS attempts, SUM(passed) AS passes
					FROM {$table}
					WHERE status = %s AND user_id > 0
					GROUP BY user_id
					ORDER BY passes DESC, best_score DESC
					LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_SUBMITTED,
					$limit
				)
			);
		}

		$out  = array();
		$rank = 0;

		foreach ( (array) $rows as $row ) {
			++$rank;

			$user = get_userdata( (int) $row->user_id );

			$out[] = array(
				'rank'     => $rank,
				'userId'   => (int) $row->user_id,
				'name'     => $user ? $user->display_name : sprintf(
					/* translators: %d: numeric user ID. */
					__( 'Student #%d', 'classic-edu-suite' ),
					(int) $row->user_id
				),
				'score'    => round( (float) $row->best_score, 2 ),
				'time'     => (int) $row->best_time,
				'attempts' => (int) $row->attempts,
				'passes'   => isset( $row->passes ) ? (int) $row->passes : 0,
			);
		}

		return $out;
	}

	/**
	 * Headline stats for one exam, for the admin analytics screen.
	 *
	 * @param int $exam_id Exam post ID.
	 * @return array
	 */
	public static function stats_for_exam( $exam_id ) {
		global $wpdb;

		$table = CES_Helpers::table( 'attempts' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) AS attempts, COUNT(DISTINCT user_id) AS students, AVG(score) AS avg_score, SUM(passed) AS passes, AVG(time_spent) AS avg_time
				FROM {$table}
				WHERE exam_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $exam_id ),
				self::STATUS_SUBMITTED
			)
		);

		$in_progress = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE exam_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $exam_id ),
				self::STATUS_IN_PROGRESS
			)
		);

		return array(
			'attempts'    => $row ? (int) $row->attempts : 0,
			'students'    => $row ? (int) $row->students : 0,
			'avg_score'   => $row ? round( (float) $row->avg_score, 1 ) : 0.0,
			'passes'      => $row ? (int) $row->passes : 0,
			'avg_time'    => $row ? (int) $row->avg_time : 0,
			'in_progress' => $in_progress,
		);
	}

	/**
	 * Site-wide CBT totals, for the overview cards.
	 *
	 * @return array
	 */
	public static function global_stats() {
		global $wpdb;

		$table = CES_Helpers::table( 'attempts' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) AS attempts, AVG(score) AS avg_score, SUM(passed) AS passes FROM {$table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_SUBMITTED
			)
		);

		return array(
			'attempts'  => $row ? (int) $row->attempts : 0,
			'avg_score' => $row ? round( (float) $row->avg_score, 1 ) : 0.0,
			'passes'    => $row ? (int) $row->passes : 0,
		);
	}

	/**
	 * Questions with the worst correct rate: usually the broken ones.
	 *
	 * @param int $exam_id Exam post ID, or 0 for every exam.
	 * @param int $limit   Rows to return.
	 * @param int $min_seen Minimum times a question must have been answered.
	 * @return array
	 */
	public static function hardest_questions( $exam_id = 0, $limit = 15, $min_seen = 5 ) {
		global $wpdb;

		$answers  = CES_Helpers::table( 'attempt_answers' );
		$attempts = CES_Helpers::table( 'attempts' );
		$limit    = max( 1, min( 100, (int) $limit ) );
		$min_seen = max( 1, (int) $min_seen );
		$exam_id  = absint( $exam_id );

		if ( $exam_id ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT ans.question_id, COUNT(*) AS seen, SUM(ans.is_correct) AS correct
					FROM {$answers} AS ans
					INNER JOIN {$attempts} AS att ON att.id = ans.attempt_id
					WHERE att.status = %s AND att.exam_id = %d AND ans.given_answer <> ''
					GROUP BY ans.question_id
					HAVING seen >= %d
					ORDER BY (SUM(ans.is_correct) / COUNT(*)) ASC, seen DESC
					LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_SUBMITTED,
					$exam_id,
					$min_seen,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT ans.question_id, COUNT(*) AS seen, SUM(ans.is_correct) AS correct
					FROM {$answers} AS ans
					INNER JOIN {$attempts} AS att ON att.id = ans.attempt_id
					WHERE att.status = %s AND ans.given_answer <> ''
					GROUP BY ans.question_id
					HAVING seen >= %d
					ORDER BY (SUM(ans.is_correct) / COUNT(*)) ASC, seen DESC
					LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_SUBMITTED,
					$min_seen,
					$limit
				)
			);
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$seen    = max( 1, (int) $row->seen );
			$correct = (int) $row->correct;

			$out[] = array(
				'question_id' => (int) $row->question_id,
				'title'       => get_the_title( (int) $row->question_id ),
				'seen'        => (int) $row->seen,
				'correct'     => $correct,
				'rate'        => round( ( $correct / $seen ) * 100, 1 ),
			);
		}

		return $out;
	}

	/**
	 * Recent attempts, for the admin results table.
	 *
	 * @param array $args `exam_id`, `user_id`, `limit`, `offset`.
	 * @return array
	 */
	public static function recent_attempts( array $args = array() ) {
		global $wpdb;

		$table   = CES_Helpers::table( 'attempts' );
		$exam_id = isset( $args['exam_id'] ) ? absint( $args['exam_id'] ) : 0;
		$user_id = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
		$limit   = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 30;
		$offset  = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		if ( $exam_id && $user_id ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE exam_id = %d AND user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$exam_id,
					$user_id,
					$limit,
					$offset
				)
			);
		} elseif ( $exam_id ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE exam_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$exam_id,
					$limit,
					$offset
				)
			);
		} elseif ( $user_id ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					$limit,
					$offset
				)
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$limit,
					$offset
				)
			);
		}

		return (array) $rows;
	}

	/**
	 * Format a duration in seconds as `mm:ss` or `h:mm:ss`.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$hours   = (int) floor( $seconds / HOUR_IN_SECONDS );
		$minutes = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
		$rest    = $seconds % MINUTE_IN_SECONDS;

		if ( $hours > 0 ) {
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $rest );
		}

		return sprintf( '%02d:%02d', $minutes, $rest );
	}
}
