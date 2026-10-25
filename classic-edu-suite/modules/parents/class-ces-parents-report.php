<?php
/**
 * The progress summary a parent actually receives.
 *
 * Everything here is read-only aggregation over records other modules already
 * keep: CBT attempts, wallet balance, video views and study-mode counters. No
 * new storage, and nothing is written except the "last contacted" stamp, which
 * the sender does.
 *
 * A summary is deliberately short. A parent reads it on a phone, usually while
 * doing something else, so it is four numbers and one sentence of plain
 * language — not a report card.
 *
 * @package ClassicEduSuite\Parents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds per-student progress summaries and the messages made from them.
 */
class CES_Parents_Report {

	/**
	 * Default window in days.
	 */
	const WINDOW_DAYS = 30;

	/**
	 * Aggregate one student's recent activity.
	 *
	 * @param int $user_id Student.
	 * @param int $days    Window in days.
	 * @return array {
	 *     @type string $student
	 *     @type int    $exams
	 *     @type float  $average
	 *     @type float  $best
	 *     @type int    $passes
	 *     @type int    $coins
	 *     @type int    $videos
	 *     @type int    $study_answered
	 *     @type int    $study_correct
	 *     @type string $last_active   MySQL datetime or ''.
	 *     @type string $last_active_h Human phrase.
	 *     @type string $strongest
	 *     @type string $weakest
	 *     @type bool   $active        Whether they did anything in the window.
	 * }
	 */
	public static function summary( $user_id, $days = self::WINDOW_DAYS ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$days    = max( 1, min( 365, (int) $days ) );
		$user    = get_userdata( $user_id );

		$out = array(
			'student'        => $user ? $user->display_name : __( 'your ward', 'classic-edu-suite' ),
			'first_name'     => $user ? self::first_name( $user ) : '',
			'exams'          => 0,
			'average'        => 0.0,
			'best'           => 0.0,
			'passes'         => 0,
			'coins'          => 0,
			'videos'         => 0,
			'study_answered' => 0,
			'study_correct'  => 0,
			'last_active'    => '',
			'last_active_h'  => __( 'not yet', 'classic-edu-suite' ),
			'strongest'      => '',
			'weakest'        => '',
			'days'           => $days,
			'active'         => false,
		);

		if ( ! $user_id ) {
			return $out;
		}

		$since    = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', strtotime( CES_Helpers::now() ) ) );
		$attempts = CES_Helpers::table( 'attempts' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, AVG(score) AS avg_score, MAX(score) AS best_score,
						SUM(passed) AS passes, MAX(submitted_at) AS last_at
				 FROM {$attempts}
				 WHERE user_id = %d AND status = %s AND submitted_at IS NOT NULL AND submitted_at >= %s",
				$user_id,
				'submitted',
				$since
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( is_array( $row ) ) {
			$out['exams']   = (int) $row['n'];
			$out['average'] = round( (float) $row['avg_score'], 1 );
			$out['best']    = round( (float) $row['best_score'], 1 );
			$out['passes']  = (int) $row['passes'];

			if ( ! empty( $row['last_at'] ) ) {
				$out['last_active'] = (string) $row['last_at'];
			}
		}

		// Per-subject averages, for the "doing well / needs help" sentence.
		$per_exam = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT exam_id, AVG(score) AS avg_score, COUNT(*) AS n
				 FROM {$attempts}
				 WHERE user_id = %d AND status = %s AND exam_id > 0 AND submitted_at >= %s
				 GROUP BY exam_id",
				$user_id,
				'submitted',
				$since
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$subjects = self::subject_averages( is_array( $per_exam ) ? $per_exam : array() );

		if ( ! empty( $subjects ) ) {
			$out['strongest'] = (string) key( $subjects );
			end( $subjects );
			$out['weakest'] = (string) key( $subjects );
			reset( $subjects );

			if ( $out['strongest'] === $out['weakest'] ) {
				$out['weakest'] = '';
			}
		}

		// Coins.
		if ( class_exists( 'CES_Wallet' ) && method_exists( 'CES_Wallet', 'balance' ) ) {
			$balance = CES_Wallet::balance( $user_id );

			if ( is_numeric( $balance ) ) {
				$out['coins'] = (int) $balance;
			}
		}

		// Videos finished in the window.
		$views = CES_Helpers::table( 'video_views' );

		$out['videos'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$views} WHERE user_id = %d AND completed = 1 AND last_seen_at >= %s",
				$user_id,
				$since
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$last_view = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(last_seen_at) FROM {$views} WHERE user_id = %d",
				$user_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $last_view && ( '' === $out['last_active'] || $last_view > $out['last_active'] ) ) {
			$out['last_active'] = $last_view;
		}

		// Study mode counters, kept in user meta by the hub module.
		if ( class_exists( 'CES_Hub_Study' ) && method_exists( 'CES_Hub_Study', 'stats' ) ) {
			$stats = CES_Hub_Study::stats( $user_id );

			$out['study_answered'] = isset( $stats['answered'] ) ? (int) $stats['answered'] : 0;
			$out['study_correct']  = isset( $stats['correct'] ) ? (int) $stats['correct'] : 0;

			if ( ! empty( $stats['last_at'] ) && ( '' === $out['last_active'] || $stats['last_at'] > $out['last_active'] ) ) {
				$out['last_active'] = (string) $stats['last_at'];
			}
		}

		if ( $out['last_active'] ) {
			$stamp = strtotime( $out['last_active'] );
			$now   = strtotime( CES_Helpers::now() );

			if ( $stamp && $now && $stamp <= $now ) {
				$out['last_active_h'] = sprintf(
					/* translators: %s: human time difference, e.g. "2 days". */
					__( '%s ago', 'classic-edu-suite' ),
					human_time_diff( $stamp, $now )
				);
			}
		}

		$out['active'] = ( $out['exams'] > 0 || $out['videos'] > 0 || $out['study_answered'] > 0 );

		/**
		 * Filter a student's parent-facing summary.
		 *
		 * @param array $out     Summary.
		 * @param int   $user_id Student.
		 * @param int   $days    Window.
		 */
		return apply_filters( 'ces_parent_summary', $out, $user_id, $days );
	}

	/**
	 * The student's first name, falling back to the display name.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private static function first_name( $user ) {
		$first = trim( (string) $user->first_name );

		if ( '' !== $first ) {
			return $first;
		}

		$parts = preg_split( '/\s+/', trim( (string) $user->display_name ) );

		return ( is_array( $parts ) && ! empty( $parts[0] ) ) ? (string) $parts[0] : (string) $user->display_name;
	}

	/**
	 * Average score per subject, best first.
	 *
	 * @param array $per_exam Rows of exam_id, avg_score, n.
	 * @return array Subject name => average, sorted descending.
	 */
	private static function subject_averages( array $per_exam ) {
		if ( empty( $per_exam ) || ! class_exists( 'CES_Cbt_Cpt' ) ) {
			return array();
		}

		$totals = array();

		foreach ( $per_exam as $row ) {
			$exam_id = isset( $row['exam_id'] ) ? absint( $row['exam_id'] ) : 0;

			if ( ! $exam_id ) {
				continue;
			}

			$subject = CES_Cbt_Cpt::first_term_name( $exam_id, CES_Cbt_Cpt::TAX_SUBJECT );

			if ( '' === $subject ) {
				continue;
			}

			$count = isset( $row['n'] ) ? max( 1, (int) $row['n'] ) : 1;
			$score = isset( $row['avg_score'] ) ? (float) $row['avg_score'] : 0.0;

			if ( ! isset( $totals[ $subject ] ) ) {
				$totals[ $subject ] = array(
					'sum' => 0.0,
					'n'   => 0,
				);
			}

			$totals[ $subject ]['sum'] += $score * $count;
			$totals[ $subject ]['n']   += $count;
		}

		$averages = array();

		foreach ( $totals as $subject => $data ) {
			if ( $data['n'] > 0 ) {
				$averages[ $subject ] = round( $data['sum'] / $data['n'], 1 );
			}
		}

		arsort( $averages );

		return $averages;
	}

	/**
	 * The default message template, used when the setting is empty.
	 *
	 * `\n` sequences are turned into real line breaks by `message()`, because
	 * the settings screen stores single-line text.
	 *
	 * @return string
	 */
	public static function default_template() {
		return 'Good day {parent}. This is {site}.\n\nIn the last {days} days {student} sat {exams} practice exam(s), averaging {average}% with a best of {best}%.{subject_line}\n\nLast seen studying: {last_active}.\n\nYou can reply here if you would like to talk about their preparation.';
	}

	/**
	 * Build the message for one student.
	 *
	 * @param int    $user_id  Student.
	 * @param string $template Optional template override.
	 * @param int    $days     Window in days.
	 * @return string Plain text, ready for WhatsApp.
	 */
	public static function message( $user_id, $template = '', $days = self::WINDOW_DAYS ) {
		$summary = self::summary( $user_id, $days );
		$contact = CES_Parent_Contact::get( $user_id );

		if ( '' === trim( (string) $template ) ) {
			$template = (string) CES_Helpers::setting( 'parents_report_template', '' );
		}

		if ( '' === trim( $template ) ) {
			$template = self::default_template();
		}

		$subject_line = '';

		if ( $summary['strongest'] ) {
			$subject_line = ' ' . sprintf(
				/* translators: %s: subject name. */
				__( 'Strongest subject so far: %s.', 'classic-edu-suite' ),
				$summary['strongest']
			);
		}

		if ( $summary['weakest'] ) {
			$subject_line .= ' ' . sprintf(
				/* translators: %s: subject name. */
				__( 'Needs the most work: %s.', 'classic-edu-suite' ),
				$summary['weakest']
			);
		}

		if ( ! $summary['active'] ) {
			$subject_line = ' ' . __( 'They have not practised yet this period — a nudge from home would help.', 'classic-edu-suite' );
		}

		$parent_name = trim( (string) $contact['name'] );

		if ( '' === $parent_name ) {
			$parent_name = __( 'Sir/Ma', 'classic-edu-suite' );
		}

		$replacements = array(
			'{parent}'       => $parent_name,
			'{student}'      => $summary['student'],
			'{first_name}'   => $summary['first_name'] ? $summary['first_name'] : $summary['student'],
			'{exams}'        => number_format_i18n( $summary['exams'] ),
			'{average}'      => number_format_i18n( $summary['average'], 1 ),
			'{best}'         => number_format_i18n( $summary['best'], 1 ),
			'{passes}'       => number_format_i18n( $summary['passes'] ),
			'{coins}'        => number_format_i18n( $summary['coins'] ),
			'{videos}'       => number_format_i18n( $summary['videos'] ),
			'{questions}'    => number_format_i18n( $summary['study_answered'] ),
			'{days}'         => number_format_i18n( $summary['days'] ),
			'{last_active}'  => $summary['last_active_h'],
			'{strong}'       => $summary['strongest'],
			'{weak}'         => $summary['weakest'],
			'{subject_line}' => $subject_line,
			'{site}'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{dashboard}'    => CES_Helpers::dashboard_url(),
			'{site_url}'     => home_url( '/' ),
		);

		$text = strtr( $template, $replacements );
		$text = str_replace( array( '\\n', "\r\n" ), "\n", $text );

		/**
		 * Filter the finished parent message.
		 *
		 * @param string $text    Message body.
		 * @param int    $user_id Student.
		 * @param array  $summary Summary used to build it.
		 */
		return trim( (string) apply_filters( 'ces_parent_message', $text, $user_id, $summary ) );
	}

	/**
	 * A ready-to-send row for one student: contact, summary, message, link.
	 *
	 * @param int $user_id Student.
	 * @param int $days    Window in days.
	 * @return array|null Null when the student has no number on file.
	 */
	public static function outbox_row( $user_id, $days = self::WINDOW_DAYS ) {
		$contact = CES_Parent_Contact::get( $user_id );

		if ( '' === $contact['phone'] ) {
			return null;
		}

		$message = self::message( $user_id, '', $days );

		return array(
			'user_id' => (int) $user_id,
			'contact' => $contact,
			'summary' => self::summary( $user_id, $days ),
			'message' => $message,
			'wa_link' => CES_Parent_Contact::wa_link( $contact['phone'], $message ),
		);
	}
}
