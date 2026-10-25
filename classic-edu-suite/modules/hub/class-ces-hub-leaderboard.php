<?php
/**
 * The ranked board, split by exam type.
 *
 * A student's standing is decided by their *best* paper, not by how many they
 * have sat, so somebody with one excellent score outranks somebody who ground
 * through forty average ones. Two students on the same score are separated by
 * time advantage — the seconds they handed back unused — which is the closest
 * honest proxy for fluency.
 *
 * Points are derived, never stored:
 *
 *     points = best score (%) + ( time advantage ratio × time weight )
 *
 * The time weight defaults to 15, so a perfect, fast paper lands around 115
 * and a perfect but slow one around 100.
 *
 * Everything is computed from `wp_ces_attempts`, cached for a few minutes.
 *
 * @package ClassicEduSuite\Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Leaderboard queries.
 */
class CES_Hub_Leaderboard {

	/**
	 * How long a computed board is cached.
	 */
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Hard ceiling on rows, whatever the caller asks for.
	 */
	const MAX_ROWS = 100;

	/**
	 * The board tabs, mirroring the exam types in the question bank.
	 *
	 * The first tab is every exam together; the rest are one exam type each,
	 * and only appear when that exam type has papers.
	 *
	 * @return array List of `array( 'slug' => '', 'label' => '', 'term_id' => int )`.
	 */
	public static function tabs() {
		$tabs = array(
			array(
				'slug'    => 'all',
				'label'   => __( 'Challenge', 'classic-edu-suite' ),
				'term_id' => 0,
			),
		);

		if ( ! taxonomy_exists( 'ces_exam_type' ) ) {
			return $tabs;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'ces_exam_type',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $tabs;
		}

		foreach ( $terms as $term ) {
			$tabs[] = array(
				'slug'    => (string) $term->slug,
				'label'   => (string) $term->name,
				'term_id' => (int) $term->term_id,
			);
		}

		/**
		 * Filter the leaderboard tabs.
		 *
		 * @param array $tabs Tab definitions.
		 */
		return (array) apply_filters( 'ces_leaderboard_tabs', $tabs );
	}

	/**
	 * Resolve a tab slug to its definition.
	 *
	 * @param string $slug Tab slug.
	 * @return array
	 */
	public static function tab( $slug ) {
		$slug = sanitize_title( (string) $slug );
		$tabs = self::tabs();

		foreach ( $tabs as $tab ) {
			if ( $tab['slug'] === $slug ) {
				return $tab;
			}
		}

		return $tabs[0];
	}

	/**
	 * The exam IDs belonging to one exam type.
	 *
	 * @param int $term_id Exam type term ID, 0 for every exam.
	 * @return int[]|null Null means "do not filter by exam".
	 */
	private static function exam_ids( $term_id ) {
		$term_id = absint( $term_id );

		if ( ! $term_id || ! class_exists( 'CES_Cbt_Cpt' ) ) {
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'              => CES_Cbt_Cpt::EXAM,
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => CES_Cbt_Cpt::TAX_EXAM_TYPE,
						'field'    => 'term_id',
						'terms'    => array( $term_id ),
					),
				),
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Build one board.
	 *
	 * @param array $args {
	 *     @type string $tab    Tab slug.
	 *     @type string $period all | month | week.
	 *     @type int    $limit  Rows wanted.
	 * }
	 * @return array {
	 *     @type array  $rows  Ranked rows.
	 *     @type string $tab   Resolved tab slug.
	 *     @type string $label Resolved tab label.
	 * }
	 */
	public static function board( array $args = array() ) {
		$args = array_merge(
			array(
				'tab'    => 'all',
				'period' => 'all',
				'limit'  => 20,
			),
			$args
		);

		$tab    = self::tab( $args['tab'] );
		$period = in_array( $args['period'], array( 'all', 'month', 'week' ), true ) ? $args['period'] : 'all';
		$limit  = max( 1, min( self::MAX_ROWS, (int) $args['limit'] ) );

		$cache_key = 'ces_lb_' . md5( $tab['slug'] . '|' . $period . '|' . $limit );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = self::query_rows( $tab, $period, $limit );

		$out = array(
			'rows'   => $rows,
			'tab'    => $tab['slug'],
			'label'  => $tab['label'],
			'period' => $period,
		);

		set_transient( $cache_key, $out, self::CACHE_TTL );

		return $out;
	}

	/**
	 * Run the ranking query.
	 *
	 * @param array  $tab    Tab definition.
	 * @param string $period Period slug.
	 * @param int    $limit  Rows wanted.
	 * @return array
	 */
	private static function query_rows( array $tab, $period, $limit ) {
		global $wpdb;

		$table = CES_Helpers::table( 'attempts' );

		// Two copies of the same conditions: one for the derived table, where
		// column names stand alone, and one for the outer query, where
		// `user_id` would otherwise be ambiguous across the join.
		$conditions = array( '%1$sstatus = %%s', '%1$ssubmitted_at IS NOT NULL', '%1$suser_id > 0' );
		$params     = array( 'submitted' );

		if ( 'month' === $period || 'week' === $period ) {
			$days         = ( 'week' === $period ) ? 7 : 30;
			$conditions[] = '%1$ssubmitted_at >= %%s';
			$params[]     = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', strtotime( CES_Helpers::now() ) ) );
		}

		$exam_ids = self::exam_ids( $tab['term_id'] );

		if ( is_array( $exam_ids ) ) {
			if ( empty( $exam_ids ) ) {
				return array();
			}

			// Integers only, cast above; safe to interpolate.
			$conditions[] = '%1$sexam_id IN (' . implode( ',', array_map( 'absint', $exam_ids ) ) . ')';
		}

		$template  = implode( ' AND ', $conditions );
		$inner_sql = sprintf( $template, '' );
		$outer_sql = sprintf( $template, 'a.' );

		// Best attempt per student: join each row back to its own maximum.
		$sql = "SELECT a.user_id, a.exam_id, a.score, a.duration_limit, a.time_spent,
					   a.correct_count, a.total_questions, a.submitted_at, b.sat, b.best
				FROM {$table} a
				INNER JOIN (
					SELECT user_id, MAX(score) AS best, COUNT(*) AS sat
					FROM {$table}
					WHERE {$inner_sql}
					GROUP BY user_id
				) b ON b.user_id = a.user_id AND b.best = a.score
				WHERE {$outer_sql}
				ORDER BY a.score DESC, ( a.duration_limit - a.time_spent ) DESC, a.submitted_at ASC
				LIMIT %d";

		// The WHERE clause appears twice, so its parameters do too.
		$all_params = array_merge( $params, $params, array( $limit * 3 ) );

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( empty( $results ) ) {
			return array();
		}

		$weight = (float) CES_Helpers::setting_int( 'hub_points_time_weight', 15 );
		$rows   = array();
		$seen   = array();

		foreach ( $results as $row ) {
			$user_id = (int) $row['user_id'];

			// A tie on score returns several rows for the same student; the
			// first is the fastest, because of the ORDER BY above.
			if ( isset( $seen[ $user_id ] ) ) {
				continue;
			}

			$seen[ $user_id ] = true;

			$user = get_userdata( $user_id );

			if ( ! $user ) {
				continue;
			}

			$duration = (int) $row['duration_limit'];
			$spent    = (int) $row['time_spent'];
			$saved    = max( 0, $duration - $spent );
			$ratio    = ( $duration > 0 ) ? min( 1.0, $saved / $duration ) : 0.0;
			$score    = round( (float) $row['score'], 2 );

			$rows[] = array(
				'user_id'   => $user_id,
				'name'      => $user->display_name,
				'avatar'    => get_avatar_url( $user_id, array( 'size' => 48 ) ),
				'best'      => $score,
				'points'    => round( $score + ( $ratio * $weight ), 2 ),
				'time_adv'  => $saved,
				'sat'       => (int) $row['sat'],
				'exam_id'   => (int) $row['exam_id'],
				'exam'      => get_the_title( (int) $row['exam_id'] ),
				'subject'   => class_exists( 'CES_Cbt_Cpt' ) ? CES_Cbt_Cpt::first_term_name( (int) $row['exam_id'], CES_Cbt_Cpt::TAX_SUBJECT ) : '',
				'correct'   => (int) $row['correct_count'],
				'questions' => (int) $row['total_questions'],
				'at'        => (string) $row['submitted_at'],
			);

			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		// Rank by points once the time bonus is in, which can reorder two
		// students who tie on raw score.
		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['points'] === $b['points'] ) {
					return $b['time_adv'] <=> $a['time_adv'];
				}

				return $b['points'] <=> $a['points'];
			}
		);

		$position = 0;

		foreach ( $rows as $index => $row ) {
			++$position;
			$rows[ $index ]['rank'] = $position;
		}

		return $rows;
	}

	/**
	 * One student's recent ranked papers, for the "view history" panel.
	 *
	 * @param int $user_id Student.
	 * @param int $limit   How many.
	 * @return array
	 */
	public static function user_history( $user_id, $limit = 5 ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$limit   = max( 1, min( 20, (int) $limit ) );

		if ( ! $user_id ) {
			return array();
		}

		$table = CES_Helpers::table( 'attempts' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT exam_id, score, duration_limit, time_spent, submitted_at
				 FROM {$table}
				 WHERE user_id = %d AND status = %s AND submitted_at IS NOT NULL
				 ORDER BY score DESC, submitted_at DESC
				 LIMIT %d",
				$user_id,
				'submitted',
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$out = array();

		foreach ( (array) $rows as $row ) {
			$exam_id = (int) $row['exam_id'];

			$out[] = array(
				'exam'     => $exam_id ? get_the_title( $exam_id ) : __( 'Practice', 'classic-edu-suite' ),
				'subject'  => ( $exam_id && class_exists( 'CES_Cbt_Cpt' ) ) ? CES_Cbt_Cpt::first_term_name( $exam_id, CES_Cbt_Cpt::TAX_SUBJECT ) : '',
				'score'    => round( (float) $row['score'], 1 ),
				'time_adv' => max( 0, (int) $row['duration_limit'] - (int) $row['time_spent'] ),
				'at'       => (string) $row['submitted_at'],
			);
		}

		return $out;
	}

	/**
	 * Where one student sits on a board, even when they are off the end of it.
	 *
	 * @param int    $user_id Student.
	 * @param string $tab     Tab slug.
	 * @param string $period  Period slug.
	 * @return array|null
	 */
	public static function standing( $user_id, $tab = 'all', $period = 'all' ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		$board = self::board(
			array(
				'tab'    => $tab,
				'period' => $period,
				'limit'  => self::MAX_ROWS,
			)
		);

		foreach ( $board['rows'] as $row ) {
			if ( (int) $row['user_id'] === $user_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Format a time advantage in seconds as a short human string.
	 *
	 * @param int $seconds Seconds saved.
	 * @return string
	 */
	public static function format_time_adv( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < 60 ) {
			return sprintf(
				/* translators: %d: seconds. */
				__( '%ds', 'classic-edu-suite' ),
				$seconds
			);
		}

		$minutes = (int) floor( $seconds / 60 );

		if ( $minutes < 60 ) {
			return sprintf(
				/* translators: %d: minutes. */
				__( '%dm', 'classic-edu-suite' ),
				$minutes
			);
		}

		return sprintf(
			/* translators: 1: hours, 2: minutes. */
			__( '%1$dh %2$dm', 'classic-edu-suite' ),
			(int) floor( $minutes / 60 ),
			$minutes % 60
		);
	}
}
