<?php
/**
 * Watch progress, resume points and genuine completion.
 *
 * The `video_views` row doubles as the progress record: `seconds_watched`,
 * `furthest_position` and `completed` live alongside the unlock window.
 *
 * Completion is deliberately hard to fake. A student who drags the scrubber to
 * the end has a large `furthest_position` but almost no `seconds_watched`, and
 * `seconds_watched` is clamped server-side against the wall clock between
 * heartbeats, so claiming an hour of viewing in a thirty-second window is not
 * possible. Only when accumulated real watch time crosses the threshold does
 * `ces_video_completed` fire — which matters, because the wallet pays out on
 * that action.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Progress reporting and completion detection.
 */
class CES_Lms_Progress {

	/**
	 * Fraction of the lesson that must be genuinely watched to complete it.
	 */
	const COMPLETE_WATCH_RATIO = 0.85;

	/**
	 * Fraction of the lesson the student must have reached to complete it.
	 */
	const COMPLETE_REACH_RATIO = 0.90;

	/**
	 * Largest credit any single heartbeat can earn, in seconds.
	 *
	 * Caps the damage from a forged payload to one heartbeat's worth.
	 */
	const MAX_DELTA = 60;

	/**
	 * Seconds between heartbeats the player should use.
	 */
	const HEARTBEAT = 15;

	/**
	 * Record a heartbeat from the player.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $user_id   User id.
	 * @param int $position  Current playhead position in seconds.
	 * @param int $delta     Seconds watched since the last report, per client.
	 * @return array|WP_Error Updated progress on success.
	 */
	public static function report( $lesson_id, $user_id, $position, $delta ) {
		global $wpdb;

		$lesson_id = (int) $lesson_id;
		$user_id   = (int) $user_id;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'ces_login_required', __( 'Please log in first.', 'classic-edu-suite' ), array( 'status' => 401 ) );
		}

		if ( CES_Lms_Cpt::LESSON !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'ces_lesson_missing', __( 'That lesson does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$allowed = CES_Lms_Access::can_watch( $lesson_id, $user_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! CES_Helpers::rate_limit( 'lms_progress_' . $lesson_id, 40, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ces_rate_limited', __( 'Too many progress updates.', 'classic-edu-suite' ), array( 'status' => 429 ) );
		}

		$duration = CES_Lms_Meta::duration( $lesson_id );
		$ceiling  = $duration > 0 ? $duration + 60 : 86400;

		$position = max( 0, min( $ceiling, (int) $position ) );

		/*
		 * Clamp the claimed watch time against real elapsed time. The client
		 * is never trusted on anything that can earn coins.
		 */
		$beat_key = 'ces_lms_beat_' . $user_id . '_' . $lesson_id;
		$last     = (int) get_transient( $beat_key );
		$now      = time();

		$elapsed = $last > 0 ? max( 0, $now - $last ) : self::HEARTBEAT;
		$delta   = max( 0, min( (int) $delta, $elapsed, self::MAX_DELTA ) );

		set_transient( $beat_key, $now, 2 * HOUR_IN_SECONDS );

		$course_id = CES_Lms_Cpt::lesson_course_id( $lesson_id );
		$table     = CES_Helpers::table( 'video_views' );
		$stamp     = CES_Helpers::now();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT INTO {$table}
					( user_id, lesson_id, course_id, access_source, seconds_watched, furthest_position, last_seen_at )
				VALUES ( %d, %d, %d, %s, %d, %d, %s )
				ON DUPLICATE KEY UPDATE
					course_id = VALUES( course_id ),
					seconds_watched = seconds_watched + VALUES( seconds_watched ),
					furthest_position = GREATEST( furthest_position, VALUES( furthest_position ) ),
					last_seen_at = VALUES( last_seen_at )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$lesson_id,
				$course_id,
				CES_Lms_Access::SOURCE_FREE,
				$delta,
				$position,
				$stamp
			)
		);

		$row = CES_Lms_Access::view_row( $lesson_id, $user_id );

		if ( ! $row ) {
			return new WP_Error( 'ces_progress_write', __( 'Progress could not be saved.', 'classic-edu-suite' ), array( 'status' => 500 ) );
		}

		$watched  = (int) $row['seconds_watched'];
		$furthest = (int) $row['furthest_position'];
		$complete = (bool) (int) $row['completed'];

		if ( ! $complete && self::qualifies_as_complete( $watched, $furthest, $duration ) ) {
			self::mark_complete( $lesson_id, $user_id );
			$complete = true;
		}

		return array(
			'lesson_id'         => $lesson_id,
			'seconds_watched'   => $watched,
			'furthest_position' => $furthest,
			'completed'         => $complete,
			'duration'          => $duration,
			'credited'          => $delta,
		);
	}

	/**
	 * Decide whether a lesson has genuinely been finished.
	 *
	 * @param int $watched  Accumulated real watch seconds.
	 * @param int $furthest Furthest playhead position reached.
	 * @param int $duration Lesson duration, 0 when unknown.
	 * @return bool
	 */
	public static function qualifies_as_complete( $watched, $furthest, $duration ) {
		$watched  = (int) $watched;
		$furthest = (int) $furthest;
		$duration = (int) $duration;

		if ( $duration > 0 ) {
			return $watched >= (int) floor( $duration * self::COMPLETE_WATCH_RATIO )
				&& $furthest >= (int) floor( $duration * self::COMPLETE_REACH_RATIO );
		}

		/*
		 * Duration unknown (no ffprobe on this host). Fall back to using the
		 * furthest point reached as a proxy for the lesson length, and require
		 * a minute of real viewing so a two-second open does not count.
		 */
		return $furthest >= 60 && $watched >= (int) floor( $furthest * self::COMPLETE_WATCH_RATIO );
	}

	/**
	 * Flag a lesson complete and fire the cross-module action once.
	 *
	 * The UPDATE is guarded on `completed = 0`, so two racing heartbeats
	 * cannot both fire the action and pay the student twice.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $user_id   User id.
	 * @return bool True when this call was the one that completed it.
	 */
	public static function mark_complete( $lesson_id, $user_id ) {
		global $wpdb;

		$table = CES_Helpers::table( 'video_views' );

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET completed = 1 WHERE user_id = %d AND lesson_id = %d AND completed = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $lesson_id
			)
		);

		if ( ! $affected ) {
			return false;
		}

		/**
		 * Fires when a student genuinely finishes a lesson.
		 *
		 * The wallet listens for this and may award coins, which is exactly
		 * why the watch-time check above is server-side and clamped.
		 *
		 * @param int $user_id   Student.
		 * @param int $lesson_id Lesson.
		 */
		do_action( 'ces_video_completed', (int) $user_id, (int) $lesson_id );

		return true;
	}

	/**
	 * Progress rows for a set of lessons, keyed by lesson id.
	 *
	 * @param int   $user_id    User id.
	 * @param int[] $lesson_ids Lesson post ids.
	 * @return array
	 */
	public static function for_lessons( $user_id, array $lesson_ids ) {
		global $wpdb;

		$user_id    = (int) $user_id;
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );

		if ( $user_id <= 0 || empty( $lesson_ids ) ) {
			return array();
		}

		$table        = CES_Helpers::table( 'video_views' );
		$placeholders = implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) );
		$params       = array_merge( array( $user_id ), $lesson_ids );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT lesson_id, seconds_watched, furthest_position, completed, unlocked_until, access_source
				FROM {$table}
				WHERE user_id = %d AND lesson_id IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['lesson_id'] ] = array(
				'seconds_watched'   => (int) $row['seconds_watched'],
				'furthest_position' => (int) $row['furthest_position'],
				'completed'         => (bool) (int) $row['completed'],
				'unlocked_until'    => (string) $row['unlocked_until'],
				'access_source'     => (string) $row['access_source'],
			);
		}

		return $out;
	}

	/**
	 * Resume position for a lesson.
	 *
	 * Rewinds a few seconds so the student gets a run-up rather than landing
	 * mid-sentence, and refuses to resume right at the end.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $user_id   User id.
	 * @return int Seconds.
	 */
	public static function resume_position( $lesson_id, $user_id ) {
		$row = CES_Lms_Access::view_row( (int) $lesson_id, (int) $user_id );

		if ( ! $row ) {
			return 0;
		}

		$furthest = (int) $row['furthest_position'];
		$duration = CES_Lms_Meta::duration( $lesson_id );

		if ( $duration > 0 && $furthest >= $duration - 15 ) {
			return 0;
		}

		return max( 0, $furthest - 5 );
	}

	/**
	 * How far through a course a student is.
	 *
	 * @param int $course_id Course post id.
	 * @param int $user_id   User id.
	 * @return array { @type int $total, @type int $completed, @type int $percent }
	 */
	public static function course_progress( $course_id, $user_id ) {
		$lessons = CES_Lms_Cpt::course_lesson_ids( (int) $course_id );
		$total   = count( $lessons );

		if ( 0 === $total || (int) $user_id <= 0 ) {
			return array(
				'total'     => $total,
				'completed' => 0,
				'percent'   => 0,
			);
		}

		$rows = self::for_lessons( $user_id, $lessons );
		$done = 0;

		foreach ( $rows as $row ) {
			if ( ! empty( $row['completed'] ) ) {
				++$done;
			}
		}

		return array(
			'total'     => $total,
			'completed' => $done,
			'percent'   => (int) round( ( $done / $total ) * 100 ),
		);
	}

	/**
	 * Aggregate watch analytics for one lesson.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return array
	 */
	public static function lesson_stats( $lesson_id ) {
		global $wpdb;

		$table = CES_Helpers::table( 'video_views' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS viewers,
					SUM( completed ) AS completions,
					SUM( seconds_watched ) AS total_seconds,
					SUM( coins_spent ) AS coins,
					AVG( furthest_position ) AS avg_position
				FROM {$table}
				WHERE lesson_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $lesson_id
			),
			ARRAY_A
		);

		$viewers  = isset( $row['viewers'] ) ? (int) $row['viewers'] : 0;
		$duration = CES_Lms_Meta::duration( $lesson_id );
		$average  = isset( $row['avg_position'] ) ? (int) round( (float) $row['avg_position'] ) : 0;

		return array(
			'viewers'       => $viewers,
			'completions'   => isset( $row['completions'] ) ? (int) $row['completions'] : 0,
			'total_seconds' => isset( $row['total_seconds'] ) ? (int) $row['total_seconds'] : 0,
			'coins'         => isset( $row['coins'] ) ? (int) $row['coins'] : 0,
			'avg_position'  => $average,
			'avg_percent'   => $duration > 0 ? (int) min( 100, round( ( $average / $duration ) * 100 ) ) : 0,
		);
	}

	/**
	 * Headline numbers across the whole library, for the overview cards.
	 *
	 * @return array
	 */
	public static function totals() {
		global $wpdb;

		$table = CES_Helpers::table( 'video_views' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT
				COUNT(*) AS rows_total,
				COUNT( DISTINCT user_id ) AS students,
				SUM( completed ) AS completions,
				SUM( seconds_watched ) AS total_seconds,
				SUM( coins_spent ) AS coins
			FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array(
			'rows'          => isset( $row['rows_total'] ) ? (int) $row['rows_total'] : 0,
			'students'      => isset( $row['students'] ) ? (int) $row['students'] : 0,
			'completions'   => isset( $row['completions'] ) ? (int) $row['completions'] : 0,
			'total_seconds' => isset( $row['total_seconds'] ) ? (int) $row['total_seconds'] : 0,
			'coins'         => isset( $row['coins'] ) ? (int) $row['coins'] : 0,
		);
	}

	/**
	 * Recent viewers of a lesson, for the admin analytics screen.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $limit     Maximum rows.
	 * @return array[]
	 */
	public static function lesson_viewers( $lesson_id, $limit = 50 ) {
		global $wpdb;

		$table = CES_Helpers::table( 'video_views' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT user_id, access_source, coins_spent, seconds_watched, furthest_position, completed, unlocked_until, last_seen_at
				FROM {$table}
				WHERE lesson_id = %d
				ORDER BY last_seen_at DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $lesson_id,
				max( 1, min( 500, (int) $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
