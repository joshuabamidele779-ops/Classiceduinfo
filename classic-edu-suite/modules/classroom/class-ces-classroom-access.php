<?php
/**
 * Who may watch a consultant's lesson.
 *
 * A consultant's video is not part of the school's own library: it belongs to
 * their class, and only their students see it. That means two jobs.
 *
 * **Letting members in.** The LMS already has a perfectly good access model —
 * a row in `video_views` with `unlocked_until` in the future means you may
 * watch. So rather than teaching the LMS about classes, membership simply
 * writes one of those rows, with `access_source` set to `class`. Access is
 * refreshed each time a member opens the lesson, so it never lapses while
 * they are enrolled, and the consultant gets the LMS's own watch analytics
 * for free because the rows are the ones it already counts.
 *
 * **Keeping everyone else out.** This is the part that needs care. A lesson
 * with no course attached and a coin cost would otherwise be purchasable by
 * any student with ten coins, which would sell a stranger a seat in somebody
 * else's private class. So a class lesson is refused to non-members at all
 * three doors: the lesson page redirects them to the class, the LMS REST
 * routes refuse the unlock and the stream token, and the player never
 * renders.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access control for class lessons.
 */
class CES_Classroom_Access {

	/**
	 * `access_source` written on the view row.
	 */
	const SOURCE = 'class';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Before the content filters run, so the player never renders for
		// somebody who should not see it.
		add_action( 'template_redirect', array( __CLASS__, 'guard_lesson_page' ), 5 );

		// The REST doors: unlocking with coins, and getting a stream token.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'gate_rest' ), 8, 3 );

		// A consultant is an adult running a class, not a student who needs
		// a parent's permission.
		add_filter( 'ces_accounts_blocked', array( __CLASS__, 'exempt_consultants' ), 10, 2 );

		// Keep the roster's progress columns moving.
		add_action( 'ces_video_completed', array( __CLASS__, 'on_lesson_completed' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Which class a lesson belongs to.
	 * ------------------------------------------------------------------ */

	/**
	 * The class a lesson belongs to, if any.
	 *
	 * @param int $lesson_id Lesson.
	 * @return int Class ID, 0 for an ordinary school lesson.
	 */
	public static function lesson_class( $lesson_id ) {
		$lesson_id = (int) $lesson_id;

		if ( $lesson_id <= 0 ) {
			return 0;
		}

		$class_id = (int) get_post_meta( $lesson_id, CES_Classroom_Cpt::LESSON_CLASS, true );

		if ( $class_id > 0 && CES_Classroom_Cpt::is_class( $class_id ) ) {
			return $class_id;
		}

		return 0;
	}

	/**
	 * Whether a lesson belongs to a consultant's class.
	 *
	 * @param int $lesson_id Lesson.
	 * @return bool
	 */
	public static function is_class_lesson( $lesson_id ) {
		return self::lesson_class( $lesson_id ) > 0;
	}

	/**
	 * Whether this person may watch a class lesson.
	 *
	 * @param int $lesson_id Lesson.
	 * @param int $user_id   Optional user, defaults to the current one.
	 * @return bool
	 */
	public static function can_watch( $lesson_id, $user_id = 0 ) {
		$class_id = self::lesson_class( $lesson_id );

		if ( ! $class_id ) {
			// Not ours to judge; the LMS's own rules apply.
			return true;
		}

		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		if ( CES_Consultant::is_staff( $user_id ) ) {
			return true;
		}

		if ( (int) get_post_field( 'post_author', (int) $lesson_id ) === $user_id ) {
			return true;
		}

		return CES_Classroom_Enrolment::is_member( $class_id, $user_id );
	}

	/* ---------------------------------------------------------------------
	 * Letting members in.
	 * ------------------------------------------------------------------ */

	/**
	 * Give a member the LMS access row that lets them watch.
	 *
	 * Idempotent and cheap: refreshing an existing row is one indexed write,
	 * and it happens once per lesson view rather than per request.
	 *
	 * @param int $lesson_id Lesson.
	 * @param int $user_id   Optional user, defaults to the current one.
	 * @return bool Whether access was granted.
	 */
	public static function maybe_grant( $lesson_id, $user_id = 0 ) {
		$lesson_id = (int) $lesson_id;
		$user_id   = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $lesson_id || ! $user_id ) {
			return false;
		}

		if ( ! self::is_class_lesson( $lesson_id ) ) {
			return false;
		}

		if ( ! self::can_watch( $lesson_id, $user_id ) ) {
			return false;
		}

		// Already unlocked and not close to lapsing: nothing to do.
		if ( class_exists( 'CES_Lms_Access' ) && CES_Lms_Access::is_unlocked( $lesson_id, $user_id ) ) {
			$row = CES_Lms_Access::view_row( $lesson_id, $user_id );

			if ( is_array( $row ) && ! empty( $row['unlocked_until'] ) ) {
				$until = (int) strtotime( (string) $row['unlocked_until'] . ' UTC' );

				// Refresh only in the last quarter of the window, so a
				// student watching a long lesson is not writing on every view.
				if ( $until - ( (int) strtotime( CES_Helpers::now() . ' UTC' ) ) > ( CES_Lms_Access::unlock_hours() * HOUR_IN_SECONDS / 4 ) ) {
					return true;
				}
			}
		}

		if ( ! class_exists( 'CES_Lms_Access' ) ) {
			return false;
		}

		CES_Lms_Access::record( $lesson_id, $user_id, self::SOURCE, 0 );

		$class_id = self::lesson_class( $lesson_id );

		if ( $class_id > 0 ) {
			CES_Classroom_Enrolment::touch( $class_id, $user_id );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Keeping everyone else out.
	 * ------------------------------------------------------------------ */

	/**
	 * Guard a direct visit to a class lesson's own page.
	 *
	 * A member is granted access and carries on to the normal player. A
	 * stranger is sent to the class, where they can ask to join — which is
	 * more use to them than an unlock button they must not press.
	 *
	 * @return void
	 */
	public static function guard_lesson_page() {
		if ( ! is_singular( CES_Lms_Cpt::LESSON ) ) {
			return;
		}

		$lesson_id = (int) get_queried_object_id();
		$class_id  = self::lesson_class( $lesson_id );

		if ( ! $class_id ) {
			return;
		}

		if ( self::can_watch( $lesson_id ) ) {
			self::maybe_grant( $lesson_id );

			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( CES_Helpers::login_url( get_permalink( $lesson_id ) ) );
			exit;
		}

		wp_safe_redirect( CES_Classroom_Cpt::share_link( $class_id ) );
		exit;
	}

	/**
	 * Refuse the LMS REST routes for a class lesson a stranger is poking at.
	 *
	 * @param mixed           $result  Response to replace the request with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return mixed
	 */
	public static function gate_rest( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result || ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}

		$route = (string) $request->get_route();

		if ( 0 !== strpos( $route, '/' . CES_REST_NAMESPACE . '/lms/' ) ) {
			return $result;
		}

		// Every LMS route that matters carries the lesson one way or another.
		$lesson_id = 0;

		foreach ( array( 'id', 'lesson_id', 'lesson' ) as $key ) {
			$candidate = $request->get_param( $key );

			if ( $candidate && CES_Lms_Cpt::LESSON === get_post_type( (int) $candidate ) ) {
				$lesson_id = (int) $candidate;
				break;
			}
		}

		if ( ! $lesson_id || ! self::is_class_lesson( $lesson_id ) ) {
			return $result;
		}

		if ( self::can_watch( $lesson_id ) ) {
			self::maybe_grant( $lesson_id );

			return $result;
		}

		$class_id = self::lesson_class( $lesson_id );

		return new WP_Error(
			'ces_class_only',
			__( 'This lesson belongs to a tutor’s class. Ask them for the join link.', 'classic-edu-suite' ),
			array(
				'status' => 403,
				'action' => CES_Classroom_Cpt::share_link( $class_id ),
			)
		);
	}

	/**
	 * Never hold a consultant behind the parent-approval gate.
	 *
	 * @param bool $blocked Decision so far.
	 * @param int  $user_id Account being checked.
	 * @return bool
	 */
	public static function exempt_consultants( $blocked, $user_id ) {
		if ( ! $blocked ) {
			return $blocked;
		}

		return CES_Consultant::is_consultant( $user_id ) ? false : $blocked;
	}

	/* ---------------------------------------------------------------------
	 * Progress.
	 * ------------------------------------------------------------------ */

	/**
	 * Move the roster's counters when a member finishes a lesson.
	 *
	 * @param int $user_id   Student.
	 * @param int $lesson_id Lesson.
	 * @return void
	 */
	public static function on_lesson_completed( $user_id, $lesson_id ) {
		$class_id = self::lesson_class( $lesson_id );

		if ( ! $class_id ) {
			return;
		}

		$duration = class_exists( 'CES_Lms_Meta' ) ? (int) CES_Lms_Meta::duration( $lesson_id ) : 0;

		CES_Classroom_Enrolment::add_progress(
			$class_id,
			$user_id,
			$duration > 0 ? (int) round( $duration / 60 ) : 0,
			1
		);
	}

	/* ---------------------------------------------------------------------
	 * Reading a class's lessons for display.
	 * ------------------------------------------------------------------ */

	/**
	 * The lessons of a class, with everything a card needs.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Viewer.
	 * @return array[]
	 */
	public static function lesson_cards( $class_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$out     = array();

		$progress = array();
		$lessons  = CES_Classroom_Cpt::lessons( $class_id );

		if ( $user_id && class_exists( 'CES_Lms_Progress' ) && ! empty( $lessons ) ) {
			$progress = CES_Lms_Progress::for_lessons( $user_id, $lessons );
		}

		foreach ( $lessons as $position => $lesson_id ) {
			$status = class_exists( 'CES_Lms_Meta' ) ? CES_Lms_Meta::status( $lesson_id ) : '';
			$row    = isset( $progress[ $lesson_id ] ) ? (array) $progress[ $lesson_id ] : array();

			$out[] = array(
				'id'          => (int) $lesson_id,
				'position'    => (int) $position + 1,
				'title'       => get_the_title( $lesson_id ),
				'url'         => (string) get_permalink( $lesson_id ),
				'duration'    => class_exists( 'CES_Lms_Meta' ) ? CES_Lms_Meta::format_duration( CES_Lms_Meta::duration( $lesson_id ) ) : '',
				'playable'    => class_exists( 'CES_Lms_Meta' ) ? CES_Lms_Meta::is_playable( $lesson_id ) : false,
				'status'      => $status,
				'statusLabel' => class_exists( 'CES_Lms_Meta' ) ? CES_Lms_Meta::status_label( $status ) : '',
				'completed'   => ! empty( $row['completed'] ),
				'watched'     => isset( $row['furthest_position'] ) ? (int) $row['furthest_position'] : 0,
				'chapters'    => count( CES_Classroom_Cpt::json_meta( $lesson_id, CES_Classroom_Cpt::LESSON_CHAPTERS ) ),
			);
		}

		return $out;
	}
}
