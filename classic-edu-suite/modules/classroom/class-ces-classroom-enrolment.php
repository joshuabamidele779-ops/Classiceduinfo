<?php
/**
 * Who is in whose class.
 *
 * One row per student per class in `ces_class_members`, which makes both of
 * the questions that get asked constantly a single indexed read: "who is in
 * this class" and "which classes am I in".
 *
 * A student arrives one of three ways — they follow the share link, they type
 * the six-character code, or the consultant adds them. Whichever it was is
 * kept in `source`, because a consultant looking at a roster wants to know
 * which of these people they actually invited.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * The class roster.
 */
class CES_Classroom_Enrolment {

	/**
	 * In the class, with access.
	 */
	const ACTIVE = 'active';

	/**
	 * Asked to join, waiting on the consultant.
	 */
	const PENDING = 'pending';

	/**
	 * Refused or removed by the consultant; cannot ask again.
	 */
	const BLOCKED = 'blocked';

	/**
	 * Left of their own accord.
	 */
	const LEFT = 'left';

	/**
	 * The roster table.
	 *
	 * @return string
	 */
	public static function table() {
		return CES_Helpers::table( 'class_members' );
	}

	/* ---------------------------------------------------------------------
	 * Joining and leaving.
	 * ------------------------------------------------------------------ */

	/**
	 * Put a student in a class.
	 *
	 * @param int    $class_id Class.
	 * @param int    $user_id  Student.
	 * @param string $source   `link` | `code` | `staff` | `invite`.
	 * @param string $role     `student` | `assistant`.
	 * @return array|WP_Error The row on success.
	 */
	public static function join( $class_id, $user_id, $source = 'link', $role = 'student' ) {
		global $wpdb;

		$class_id = (int) $class_id;
		$user_id  = (int) $user_id;

		if ( ! CES_Classroom_Cpt::is_class( $class_id ) ) {
			return new WP_Error( 'ces_class_missing', __( 'That class could not be found.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'ces_class_user', __( 'Sign in first, then open the class link again.', 'classic-edu-suite' ), array( 'status' => 401 ) );
		}

		if ( CES_Classroom_Cpt::is_archived( $class_id ) ) {
			return new WP_Error( 'ces_class_archived', __( 'That class has been closed by its tutor.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$existing = self::row( $class_id, $user_id );

		if ( $existing && self::BLOCKED === $existing['status'] ) {
			return new WP_Error(
				'ces_class_blocked',
				__( 'You cannot join this class. Please speak to the tutor.', 'classic-edu-suite' ),
				array( 'status' => 403 )
			);
		}

		if ( $existing && self::ACTIVE === $existing['status'] ) {
			return $existing;
		}

		$staff_added = in_array( $source, array( 'staff', 'invite' ), true );
		$status      = ( $staff_added || ! CES_Classroom_Cpt::needs_approval( $class_id ) ) ? self::ACTIVE : self::PENDING;

		// Capacity applies to the people actually in the room, not to the
		// queue waiting to be let in.
		if ( self::ACTIVE === $status ) {
			$limit = max( 1, CES_Helpers::setting_int( 'classroom_max_students', 200 ) );

			if ( self::count( $class_id ) >= $limit && ( ! $existing || self::ACTIVE !== $existing['status'] ) ) {
				return new WP_Error(
					'ces_class_full',
					__( 'This class is full. Ask the tutor to make room.', 'classic-edu-suite' ),
					array( 'status' => 403 )
				);
			}
		}

		$now  = CES_Helpers::now();
		$role = in_array( $role, array( 'student', 'assistant', 'consultant' ), true ) ? $role : 'student';

		$data = array(
			'class_id'     => $class_id,
			'user_id'      => $user_id,
			'role'         => $role,
			'status'       => $status,
			'source'       => sanitize_key( $source ),
			'joined_at'    => $now,
			'last_seen_at' => $now,
		);

		$formats = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' );

		if ( self::ACTIVE === $status ) {
			$data['approved_at'] = $now;
			$formats[]           = '%s';
		}

		if ( $existing ) {
			// Re-joining after leaving: keep the counters, reset the state.
			unset( $data['class_id'], $data['user_id'], $data['joined_at'] );

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				$data,
				array( 'id' => (int) $existing['id'] ),
				null,
				array( '%d' )
			);
		} else {
			$wpdb->insert( self::table(), $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		self::flush_counts( $class_id );

		$row = self::row( $class_id, $user_id );

		if ( ! $row ) {
			return new WP_Error( 'ces_class_store', __( 'We could not add you to that class. Please try again.', 'classic-edu-suite' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires when a student joins, or asks to join, a class.
		 *
		 * @param int    $class_id Class.
		 * @param int    $user_id  Student.
		 * @param string $status   `active` or `pending`.
		 */
		do_action( 'ces_class_joined', $class_id, $user_id, $status );

		if ( self::ACTIVE === $status ) {
			self::announce_join( $class_id, $user_id );
		} else {
			self::notify_request( $class_id, $user_id );
		}

		return $row;
	}

	/**
	 * Let a waiting student in.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Student.
	 * @return bool
	 */
	public static function approve( $class_id, $user_id ) {
		global $wpdb;

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'      => self::ACTIVE,
				'approved_at' => CES_Helpers::now(),
			),
			array(
				'class_id' => (int) $class_id,
				'user_id'  => (int) $user_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);

		if ( $updated ) {
			self::flush_counts( $class_id );
			self::announce_join( $class_id, $user_id );

			/**
			 * Fires when a student is admitted to a class.
			 *
			 * @param int $class_id Class.
			 * @param int $user_id  Student.
			 */
			do_action( 'ces_class_approved', (int) $class_id, (int) $user_id );
		}

		return (bool) $updated;
	}

	/**
	 * Set a member's status directly.
	 *
	 * @param int    $class_id Class.
	 * @param int    $user_id  Student.
	 * @param string $status   New status.
	 * @return bool
	 */
	public static function set_status( $class_id, $user_id, $status ) {
		global $wpdb;

		$status = sanitize_key( (string) $status );

		if ( ! in_array( $status, array( self::ACTIVE, self::PENDING, self::BLOCKED, self::LEFT ), true ) ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'status' => $status ),
			array(
				'class_id' => (int) $class_id,
				'user_id'  => (int) $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		self::flush_counts( $class_id );

		return (bool) $updated;
	}

	/**
	 * A student leaves a class.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Student.
	 * @return bool
	 */
	public static function leave( $class_id, $user_id ) {
		return self::set_status( $class_id, $user_id, self::LEFT );
	}

	/**
	 * Remove a member's row entirely.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Student.
	 * @return bool
	 */
	public static function remove( $class_id, $user_id ) {
		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'class_id' => (int) $class_id,
				'user_id'  => (int) $user_id,
			),
			array( '%d', '%d' )
		);

		self::flush_counts( $class_id );

		return (bool) $deleted;
	}

	/* ---------------------------------------------------------------------
	 * Reading the roster.
	 * ------------------------------------------------------------------ */

	/**
	 * One member's row.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Student.
	 * @return array|null
	 */
	public static function row( $class_id, $user_id ) {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE class_id = %d AND user_id = %d LIMIT 1",
				(int) $class_id,
				(int) $user_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A member's status, '' when they have no row.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Student.
	 * @return string
	 */
	public static function status( $class_id, $user_id ) {
		$row = self::row( $class_id, $user_id );

		return $row ? (string) $row['status'] : '';
	}

	/**
	 * Whether this person is in the class right now.
	 *
	 * The consultant who owns it and site staff always count as members, so
	 * they can see their own class without enrolling in it.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Optional user, defaults to the current one.
	 * @return bool
	 */
	public static function is_member( $class_id, $user_id = 0 ) {
		$class_id = (int) $class_id;
		$user_id  = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		if ( CES_Classroom_Cpt::owner( $class_id ) === $user_id || CES_Consultant::is_staff( $user_id ) ) {
			return true;
		}

		return self::ACTIVE === self::status( $class_id, $user_id );
	}

	/**
	 * The roster.
	 *
	 * @param int   $class_id Class.
	 * @param array $args     Optional. `status`, `number`, `offset`, `search`.
	 * @return array[] Rows, each with a `user` key holding the WP_User.
	 */
	public static function roster( $class_id, array $args = array() ) {
		global $wpdb;

		$args = array_merge(
			array(
				'status' => self::ACTIVE,
				'number' => 100,
				'offset' => 0,
			),
			$args
		);

		$table  = self::table();
		$number = max( 1, min( 500, (int) $args['number'] ) );
		$offset = max( 0, (int) $args['offset'] );

		if ( 'any' === $args['status'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE class_id = %d ORDER BY FIELD(status,'pending','active','left','blocked'), joined_at DESC LIMIT %d OFFSET %d",
					(int) $class_id,
					$number,
					$offset
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE class_id = %d AND status = %s ORDER BY joined_at DESC LIMIT %d OFFSET %d",
					(int) $class_id,
					sanitize_key( (string) $args['status'] ),
					$number,
					$offset
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$user = get_userdata( (int) $row['user_id'] );

			if ( ! $user ) {
				continue;
			}

			$row['user'] = $user;
			$out[]       = $row;
		}

		return $out;
	}

	/**
	 * How many members a class has in one status.
	 *
	 * The active count is cached in post meta, because it is printed on every
	 * class card and the Studio list shows a dozen of them at once.
	 *
	 * @param int    $class_id Class.
	 * @param string $status   Status to count.
	 * @return int
	 */
	public static function count( $class_id, $status = self::ACTIVE ) {
		global $wpdb;

		$class_id = (int) $class_id;
		$status   = sanitize_key( (string) $status );

		if ( self::ACTIVE === $status ) {
			$cached = get_post_meta( $class_id, CES_Classroom_Cpt::MEMBERS, true );

			if ( '' !== $cached && null !== $cached ) {
				return (int) $cached;
			}
		}

		$table = self::table();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE class_id = %d AND status = %s",
				$class_id,
				$status
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( self::ACTIVE === $status ) {
			update_post_meta( $class_id, CES_Classroom_Cpt::MEMBERS, $count );
		}

		return $count;
	}

	/**
	 * Total minutes watched by everyone in a class.
	 *
	 * @param int $class_id Class.
	 * @return int
	 */
	public static function minutes( $class_id ) {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(minutes_watched),0) FROM {$table} WHERE class_id = %d",
				(int) $class_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The classes one student is in.
	 *
	 * @param int    $user_id Student.
	 * @param string $status  Status to match, or 'any'.
	 * @return int[] Class IDs, newest first.
	 */
	public static function classes_for( $user_id, $status = self::ACTIVE ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return array();
		}

		$table = self::table();

		if ( 'any' === $status ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT class_id FROM {$table} WHERE user_id = %d ORDER BY joined_at DESC LIMIT 200",
					$user_id
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT class_id FROM {$table} WHERE user_id = %d AND status = %s ORDER BY joined_at DESC LIMIT 200",
					$user_id,
					sanitize_key( (string) $status )
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$out = array();

		foreach ( (array) $ids as $class_id ) {
			$class_id = (int) $class_id;

			// A deleted class can leave a row behind if the delete hook never
			// ran, for instance on a direct database edit.
			if ( CES_Classroom_Cpt::is_class( $class_id ) ) {
				$out[] = $class_id;
			}
		}

		return $out;
	}

	/**
	 * Pending requests across all of one consultant's classes.
	 *
	 * @param int $user_id Consultant.
	 * @return int
	 */
	public static function pending_for_consultant( $user_id ) {
		$total = 0;

		foreach ( CES_Classroom_Cpt::for_consultant( $user_id ) as $class_id ) {
			$total += self::count( $class_id, self::PENDING );
		}

		return $total;
	}

	/* ---------------------------------------------------------------------
	 * Counters.
	 * ------------------------------------------------------------------ */

	/**
	 * Note that a member is here.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Student.
	 * @return void
	 */
	public static function touch( $class_id, $user_id ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'last_seen_at' => CES_Helpers::now() ),
			array(
				'class_id' => (int) $class_id,
				'user_id'  => (int) $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Add watch time, and optionally a finished lesson, to a member's row.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Student.
	 * @param int $minutes  Minutes to add.
	 * @param int $lessons  Lessons finished to add.
	 * @return void
	 */
	public static function add_progress( $class_id, $user_id, $minutes = 0, $lessons = 0 ) {
		global $wpdb;

		$minutes = max( 0, (int) $minutes );
		$lessons = max( 0, (int) $lessons );

		if ( ! $minutes && ! $lessons ) {
			return;
		}

		$table = self::table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"UPDATE {$table}
				 SET minutes_watched = minutes_watched + %d,
				     lessons_done = lessons_done + %d,
				     last_seen_at = %s
				 WHERE class_id = %d AND user_id = %d",
				$minutes,
				$lessons,
				CES_Helpers::now(),
				(int) $class_id,
				(int) $user_id
			)
		);
	}

	/**
	 * Drop the cached member count.
	 *
	 * @param int $class_id Optional class. Omit to clear the consultant cache.
	 * @return void
	 */
	public static function flush_counts( $class_id = 0 ) {
		if ( $class_id ) {
			delete_post_meta( (int) $class_id, CES_Classroom_Cpt::MEMBERS );
		}

		delete_transient( 'ces_consultant_counts' );
	}

	/**
	 * Delete every row for a class.
	 *
	 * @param int $class_id Class.
	 * @return void
	 */
	public static function delete_class( $class_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'class_id' => (int) $class_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete every row for a user.
	 *
	 * @param int $user_id Student.
	 * @return void
	 */
	public static function delete_user( $user_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'user_id' => (int) $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ---------------------------------------------------------------------
	 * Chat announcement.
	 * ------------------------------------------------------------------ */

	/**
	 * Say in the class chat that somebody has arrived.
	 *
	 * A real event about a real person, which is the only kind of automatic
	 * message this plugin posts into a room besides the clearly-labelled
	 * Study Bot.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Student.
	 * @return void
	 */
	private static function announce_join( $class_id, $user_id ) {
		if ( ! CES_Classroom_Cpt::chat_enabled( $class_id ) ) {
			return;
		}

		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return;
		}

		CES_Chat::post_system(
			CES_Chat::class_room( $class_id ),
			sprintf(
				/* translators: %s: student display name. */
				__( '%s joined the class', 'classic-edu-suite' ),
				$user->display_name
			)
		);
	}

	/**
	 * Tell the consultant somebody is waiting to be let in.
	 *
	 * @param int $class_id Class.
	 * @param int $user_id  Student.
	 * @return void
	 */
	private static function notify_request( $class_id, $user_id ) {
		if ( ! class_exists( 'CES_Accounts_Notify' ) ) {
			return;
		}

		$owner   = get_userdata( CES_Classroom_Cpt::owner( $class_id ) );
		$student = get_userdata( (int) $user_id );

		if ( ! $owner || ! $student || ! is_email( $owner->user_email ) ) {
			return;
		}

		$waiting = self::count( $class_id, self::PENDING );

		$body  = '<p>' . esc_html(
			sprintf(
				/* translators: 1: student name, 2: class title. */
				__( '%1$s has asked to join %2$s.', 'classic-edu-suite' ),
				$student->display_name,
				get_the_title( (int) $class_id )
			)
		) . '</p>';

		if ( $waiting > 1 ) {
			$body .= '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of students waiting. */
					_n( '%d student is waiting for you.', '%d students are waiting for you.', $waiting, 'classic-edu-suite' ),
					$waiting
				)
			) . '</p>';
		}

		$body .= '<p style="margin:28px 0;"><a href="' . esc_url( CES_Helpers::dashboard_url( CES_Classroom_Studio::SLUG ) ) . '" style="background:#2c62cd;color:#ffffff;padding:13px 22px;border-radius:999px;text-decoration:none;font-weight:600;display:inline-block;">'
			. esc_html__( 'Open my Studio', 'classic-edu-suite' ) . '</a></p>';

		CES_Accounts_Notify::email(
			$owner->user_email,
			sprintf(
				/* translators: %s: class title. */
				__( 'Someone wants to join %s', 'classic-edu-suite' ),
				get_the_title( (int) $class_id )
			),
			CES_Accounts_Notify::wrap_email( __( 'A student is waiting', 'classic-edu-suite' ), $body )
		);
	}
}
