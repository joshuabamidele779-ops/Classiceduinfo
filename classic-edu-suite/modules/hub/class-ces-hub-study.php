<?php
/**
 * Study mode: past questions one at a time, with the answer revealed as soon
 * as the student commits to theirs.
 *
 * This is deliberately *not* the CBT runner. There is no clock, no pass mark
 * and nothing recorded against the leaderboard — it is the "Study Past
 * Questions" tile, where a student is learning rather than being measured.
 *
 * A session lives in a transient, not a table: a list of question IDs, which
 * have been answered, and a running tally. It expires on its own, so an
 * abandoned session costs nothing and needs no sweeper.
 *
 * Correct answers are resolved in PHP, per answer, and only after the student
 * has sent theirs. The browser is never handed the key up front.
 *
 * @package ClassicEduSuite\Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Study session engine.
 */
class CES_Hub_Study {

	/**
	 * Transient prefix for a session.
	 */
	const SESSION_PREFIX = 'ces_study_';

	/**
	 * How long an idle session survives.
	 */
	const SESSION_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Largest session we will build.
	 */
	const MAX_QUESTIONS = 100;

	/**
	 * User meta holding lifetime study counters.
	 */
	const META_STATS = 'ces_study_stats';

	/**
	 * Start a session.
	 *
	 * @param int   $user_id Student.
	 * @param array $filters {
	 *     @type int $subject   Subject term ID.
	 *     @type int $exam_type Exam type term ID.
	 *     @type int $topic     Topic term ID.
	 *     @type int $year      Year term ID.
	 *     @type int $count     How many questions.
	 * }
	 * @return array|WP_Error Session payload.
	 */
	public static function start( $user_id, array $filters ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return new WP_Error( 'ces_study_login', __( 'Sign in to study.', 'classic-edu-suite' ), array( 'status' => 401 ) );
		}

		if ( ! class_exists( 'CES_Cbt_Question' ) ) {
			return new WP_Error( 'ces_study_unavailable', __( 'The question bank is not available.', 'classic-edu-suite' ), array( 'status' => 503 ) );
		}

		$count = isset( $filters['count'] ) ? absint( $filters['count'] ) : 0;

		if ( $count < 1 ) {
			$count = CES_Helpers::setting_int( 'hub_study_questions', 20 );
		}

		$count = max( 1, min( self::MAX_QUESTIONS, $count ) );

		$tax_filters = array();

		$map = array(
			CES_Cbt_Cpt::TAX_SUBJECT   => isset( $filters['subject'] ) ? absint( $filters['subject'] ) : 0,
			CES_Cbt_Cpt::TAX_EXAM_TYPE => isset( $filters['exam_type'] ) ? absint( $filters['exam_type'] ) : 0,
			CES_Cbt_Cpt::TAX_TOPIC     => isset( $filters['topic'] ) ? absint( $filters['topic'] ) : 0,
			CES_Cbt_Cpt::TAX_YEAR      => isset( $filters['year'] ) ? absint( $filters['year'] ) : 0,
		);

		foreach ( $map as $taxonomy => $term_id ) {
			if ( $term_id > 0 ) {
				$tax_filters[ $taxonomy ] = array( $term_id );
			}
		}

		$ids = CES_Cbt_Question::query_ids( $tax_filters, '', $count, true );

		if ( empty( $ids ) ) {
			return new WP_Error(
				'ces_study_empty',
				__( 'There are no questions matching that choice yet. Try another subject or year.', 'classic-edu-suite' ),
				array( 'status' => 404 )
			);
		}

		$session_id = wp_generate_password( 20, false, false );

		$session = array(
			'id'       => $session_id,
			'user_id'  => $user_id,
			'ids'      => array_values( array_map( 'absint', $ids ) ),
			'answered' => array(),
			'correct'  => 0,
			'filters'  => $map,
			'started'  => CES_Helpers::now(),
		);

		self::put( $session );

		return self::public_state( $session );
	}

	/**
	 * Fetch one question of a session, without its answer.
	 *
	 * @param string $session_id Session key.
	 * @param int    $user_id    Student.
	 * @param int    $index      Zero-based position.
	 * @return array|WP_Error
	 */
	public static function question( $session_id, $user_id, $index ) {
		$session = self::load( $session_id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$index = absint( $index );

		if ( ! isset( $session['ids'][ $index ] ) ) {
			return new WP_Error( 'ces_study_index', __( 'That question is not part of this session.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$question = CES_Cbt_Question::get( $session['ids'][ $index ] );

		if ( ! $question ) {
			return new WP_Error( 'ces_study_gone', __( 'That question has been removed.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$payload = CES_Cbt_Question::for_client( $question );

		$payload['index'] = $index;
		$payload['total'] = count( $session['ids'] );

		// A question already answered in this session comes back with its
		// result, so a reload does not wipe what the student has seen.
		if ( isset( $session['answered'][ $question['id'] ] ) ) {
			$record = $session['answered'][ $question['id'] ];

			$payload['answered']    = true;
			$payload['given']       = isset( $record['given'] ) ? array_values( (array) $record['given'] ) : array();
			$payload['isCorrect']   = ! empty( $record['correct'] );
			$payload['correct']     = array_values( (array) $question['correct'] );
			$payload['explanation'] = self::explanation( $question );
		} else {
			$payload['answered'] = false;
		}

		return $payload;
	}

	/**
	 * Mark one answer and reveal the result.
	 *
	 * @param string $session_id  Session key.
	 * @param int    $user_id     Student.
	 * @param int    $question_id Question being answered.
	 * @param mixed  $given       Raw answer: a key, or a list of keys.
	 * @return array|WP_Error
	 */
	public static function answer( $session_id, $user_id, $question_id, $given ) {
		$session = self::load( $session_id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$question_id = absint( $question_id );

		if ( ! in_array( $question_id, $session['ids'], true ) ) {
			return new WP_Error( 'ces_study_foreign', __( 'That question is not part of this session.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$question = CES_Cbt_Question::get( $question_id );

		if ( ! $question ) {
			return new WP_Error( 'ces_study_gone', __( 'That question has been removed.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$keys   = CES_Cbt_Question::option_keys( $question );
		$chosen = CES_Cbt_Question::normalize_answer( $given, $keys );
		$grade  = CES_Cbt_Question::grade( $question, $chosen );

		// Answering twice never moves the tally twice.
		$already = isset( $session['answered'][ $question_id ] );

		if ( ! $already ) {
			$session['answered'][ $question_id ] = array(
				'given'   => $chosen,
				'correct' => ! empty( $grade['is_correct'] ),
				'at'      => time(),
			);

			if ( ! empty( $grade['is_correct'] ) ) {
				++$session['correct'];
			}

			self::put( $session );
			self::bump_stats( $user_id, ! empty( $grade['is_correct'] ) );
		}

		return array(
			'questionId'  => $question_id,
			'isCorrect'   => ! empty( $grade['is_correct'] ),
			'correct'     => array_values( (array) $question['correct'] ),
			'given'       => array_values( $chosen ),
			'explanation' => self::explanation( $question ),
			'answered'    => count( $session['answered'] ),
			'score'       => (int) $session['correct'],
			'total'       => count( $session['ids'] ),
		);
	}

	/**
	 * Finish a session and hand back the summary.
	 *
	 * @param string $session_id Session key.
	 * @param int    $user_id    Student.
	 * @return array|WP_Error
	 */
	public static function finish( $session_id, $user_id ) {
		$session = self::load( $session_id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$total    = count( $session['ids'] );
		$answered = count( $session['answered'] );
		$correct  = (int) $session['correct'];
		$percent  = $answered ? round( ( $correct / $answered ) * 100, 1 ) : 0.0;

		delete_transient( self::SESSION_PREFIX . $session['id'] );

		/**
		 * Fires when a study session is finished.
		 *
		 * @param int   $user_id Student.
		 * @param array $summary Session summary.
		 */
		do_action(
			'ces_study_completed',
			$user_id,
			array(
				'total'    => $total,
				'answered' => $answered,
				'correct'  => $correct,
				'percent'  => $percent,
			)
		);

		return array(
			'total'     => $total,
			'answered'  => $answered,
			'correct'   => $correct,
			'percent'   => $percent,
			'wrong'     => max( 0, $answered - $correct ),
			'lifetime'  => self::stats( $user_id ),
			'dashboard' => CES_Helpers::dashboard_url( 'study' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Session storage.
	 * ------------------------------------------------------------------ */

	/**
	 * Write a session back to its transient.
	 *
	 * @param array $session Session array.
	 * @return void
	 */
	private static function put( array $session ) {
		set_transient( self::SESSION_PREFIX . $session['id'], $session, self::SESSION_TTL );
	}

	/**
	 * Load a session and confirm it belongs to this student.
	 *
	 * @param string $session_id Session key.
	 * @param int    $user_id    Student.
	 * @return array|WP_Error
	 */
	private static function load( $session_id, $user_id ) {
		$session_id = preg_replace( '/[^A-Za-z0-9]/', '', (string) $session_id );
		$user_id    = absint( $user_id );

		if ( '' === $session_id ) {
			return new WP_Error( 'ces_study_session', __( 'That study session has expired. Start a new one.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$session = get_transient( self::SESSION_PREFIX . $session_id );

		if ( ! is_array( $session ) || empty( $session['ids'] ) ) {
			return new WP_Error( 'ces_study_session', __( 'That study session has expired. Start a new one.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		if ( (int) $session['user_id'] !== $user_id ) {
			return new WP_Error( 'ces_study_owner', __( 'That study session belongs to someone else.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		if ( ! isset( $session['answered'] ) || ! is_array( $session['answered'] ) ) {
			$session['answered'] = array();
		}

		return $session;
	}

	/**
	 * The browser-safe view of a session.
	 *
	 * @param array $session Session array.
	 * @return array
	 */
	private static function public_state( array $session ) {
		return array(
			'session'  => (string) $session['id'],
			'total'    => count( $session['ids'] ),
			'answered' => count( $session['answered'] ),
			'score'    => (int) $session['correct'],
			'index'    => 0,
		);
	}

	/**
	 * The explanation shown after an answer, already sanitised.
	 *
	 * @param array $question Question record.
	 * @return string
	 */
	private static function explanation( array $question ) {
		$text = isset( $question['explanation'] ) ? (string) $question['explanation'] : '';

		if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
			return '';
		}

		return CES_Cbt_Question::sanitize_rich_text( wpautop( $text ) );
	}

	/* ---------------------------------------------------------------------
	 * Lifetime counters.
	 * ------------------------------------------------------------------ */

	/**
	 * A student's lifetime study counters.
	 *
	 * @param int $user_id Student.
	 * @return array `array( 'answered' => int, 'correct' => int, 'last_at' => string )`
	 */
	public static function stats( $user_id ) {
		$stats = get_user_meta( absint( $user_id ), self::META_STATS, true );

		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		return array(
			'answered' => isset( $stats['answered'] ) ? (int) $stats['answered'] : 0,
			'correct'  => isset( $stats['correct'] ) ? (int) $stats['correct'] : 0,
			'last_at'  => isset( $stats['last_at'] ) ? (string) $stats['last_at'] : '',
		);
	}

	/**
	 * Add one answer to the lifetime counters.
	 *
	 * @param int  $user_id    Student.
	 * @param bool $is_correct Whether it was right.
	 * @return void
	 */
	private static function bump_stats( $user_id, $is_correct ) {
		$stats = self::stats( $user_id );

		$stats['answered'] = $stats['answered'] + 1;
		$stats['correct']  = $stats['correct'] + ( $is_correct ? 1 : 0 );
		$stats['last_at']  = CES_Helpers::now();

		update_user_meta( absint( $user_id ), self::META_STATS, $stats );
	}

	/**
	 * Accuracy as a percentage, for the dashboard.
	 *
	 * @param int $user_id Student.
	 * @return float
	 */
	public static function accuracy( $user_id ) {
		$stats = self::stats( $user_id );

		if ( $stats['answered'] < 1 ) {
			return 0.0;
		}

		return round( ( $stats['correct'] / $stats['answered'] ) * 100, 1 );
	}
}
