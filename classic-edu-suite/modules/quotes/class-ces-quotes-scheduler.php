<?php
/**
 * Decides which quote a given viewer sees, and records what happened.
 *
 * Rotation rules:
 *   1. A quote pinned to today's date always wins.
 *   2. Otherwise pick a weighted random quote the viewer has not been shown
 *      during the current cycle. When every quote has been seen the cycle
 *      resets and the whole pool becomes available again.
 *   3. In `daily` mode the choice is sticky: the first impression of the day
 *      is logged and re-read on later page loads, so the same student never
 *      sees two different quotes on the same date.
 *
 * Log table note (the schema is frozen):
 *   `wp_ces_quote_log` carries UNIQUE KEY (user_id, shown_on). For logged-in
 *   users that index is exactly the "one row per user per day" guarantee we
 *   want, so `shown_on` is filled and inserts use INSERT IGNORE. Guests all
 *   share user_id 0, which would make them collide on that index, so guest
 *   rows store `shown_on` as NULL (MySQL treats NULLs as distinct in a unique
 *   index) and are grouped by `shown_at` instead. Every report therefore
 *   aggregates on DATE(shown_at), never on shown_on.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quote selection, impression logging and log maintenance.
 */
class CES_Quotes_Scheduler {

	/**
	 * Cron hook that rotates the site-wide quote of the day.
	 */
	const CRON_HOOK = 'ces_rotate_daily_quote';

	/**
	 * Option holding the site-wide quote of the day.
	 */
	const OPTION_QOTD = 'ces_quote_of_the_day';

	/**
	 * Transient caching the active quote pool.
	 */
	const POOL_TRANSIENT = 'ces_quotes_pool';

	/**
	 * User meta / transient key marking when the current cycle began.
	 */
	const CYCLE_KEY = 'ces_quote_cycle_start';

	/**
	 * Per-request memo of the resolved quote ID, keyed by viewer signature.
	 *
	 * @var array<string,int>
	 */
	private static $memo = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( 'ces_loaded', array( __CLASS__, 'maybe_schedule_cron' ) );

		// Any change to the pool invalidates the cache.
		add_action( 'save_post_' . CES_Quotes_Cpt::POST_TYPE, array( __CLASS__, 'flush_pool' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_pool' ) );
		add_action( 'trashed_post', array( __CLASS__, 'flush_pool' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'flush_pool' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'flush_pool' ) );
	}

	/**
	 * Make sure the daily rotation job is scheduled.
	 *
	 * @return void
	 */
	public static function maybe_schedule_cron() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		// First run just after the next local midnight.
		$timestamp = 0;

		try {
			$next      = new DateTime( 'tomorrow 00:05', wp_timezone() );
			$timestamp = $next->getTimestamp();
		} catch ( Exception $e ) {
			$timestamp = 0;
		}

		if ( $timestamp <= time() ) {
			$timestamp = time() + HOUR_IN_SECONDS;
		}

		wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
	}

	/**
	 * Cron callback: rotate the site-wide quote and prune the log.
	 *
	 * @return void
	 */
	public static function run_cron() {
		self::flush_pool();
		self::rotate_quote_of_the_day();
		self::prune_log();
	}

	/**
	 * Today's date in site time.
	 *
	 * @return string Y-m-d
	 */
	public static function today() {
		return (string) current_time( 'Y-m-d' );
	}

	/**
	 * Midnight, N days before today, as a site-time MySQL datetime.
	 *
	 * Pure calendar arithmetic on the date string: the epoch is interpreted
	 * and reformatted in UTC so no server/site timezone offset leaks in. The
	 * result is compared against `shown_at`, which is site time.
	 *
	 * @param int $days Days back from today. 0 means today's midnight.
	 * @return string Y-m-d H:i:s
	 */
	public static function days_ago( $days ) {
		$days = max( 0, (int) $days );
		$base = strtotime( self::today() . ' 00:00:00 UTC' );

		if ( ! $base ) {
			$base = time();
		}

		return gmdate( 'Y-m-d H:i:s', $base - ( $days * DAY_IN_SECONDS ) );
	}

	// ---------------------------------------------------------------------
	// The pool
	// ---------------------------------------------------------------------

	/**
	 * The active quote pool, split into rotation and date-pinned quotes.
	 *
	 * @return array{rotation:array<int,int>,scheduled:array<int,string>}
	 *         rotation: post ID => weight. scheduled: post ID => Y-m-d.
	 */
	public static function pool() {
		$cached = get_transient( self::POOL_TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['rotation'], $cached['scheduled'] ) ) {
			return $cached;
		}

		$ids = get_posts(
			array(
				'post_type'        => CES_Quotes_Cpt::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => 1000,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'   => CES_Quotes_Cpt::META_ACTIVE,
						'value' => '1',
					),
					array(
						'key'     => CES_Quotes_Cpt::META_ACTIVE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$pool = array(
			'rotation'  => array(),
			'scheduled' => array(),
		);

		if ( empty( $ids ) ) {
			set_transient( self::POOL_TRANSIENT, $pool, 5 * MINUTE_IN_SECONDS );
			return $pool;
		}

		$ids = array_map( 'intval', $ids );
		update_meta_cache( 'post', $ids );

		foreach ( $ids as $id ) {
			$date = CES_Quotes_Cpt::sanitize_date( (string) get_post_meta( $id, CES_Quotes_Cpt::META_SCHEDULED, true ) );

			if ( '' !== $date ) {
				$pool['scheduled'][ $id ] = $date;
				continue;
			}

			$weight = (int) get_post_meta( $id, CES_Quotes_Cpt::META_WEIGHT, true );
			$pool['rotation'][ $id ] = max( 1, min( 10, $weight > 0 ? $weight : 1 ) );
		}

		set_transient( self::POOL_TRANSIENT, $pool, 12 * HOUR_IN_SECONDS );

		return $pool;
	}

	/**
	 * Drop the cached pool.
	 *
	 * @return void
	 */
	public static function flush_pool() {
		delete_transient( self::POOL_TRANSIENT );
		self::$memo = array();
	}

	/**
	 * Whether a post is currently an eligible quote.
	 *
	 * @param int $quote_id Post ID.
	 * @return bool
	 */
	public static function is_eligible( $quote_id ) {
		$quote_id = (int) $quote_id;

		if ( $quote_id <= 0 ) {
			return false;
		}

		$pool = self::pool();

		return isset( $pool['rotation'][ $quote_id ] ) || isset( $pool['scheduled'][ $quote_id ] );
	}

	/**
	 * The quote pinned to a given date, if any.
	 *
	 * @param string $date Y-m-d, defaults to today.
	 * @return int Post ID or 0.
	 */
	public static function scheduled_for( $date = '' ) {
		$date = '' === $date ? self::today() : CES_Quotes_Cpt::sanitize_date( $date );

		if ( '' === $date ) {
			return 0;
		}

		$pool = self::pool();

		foreach ( $pool['scheduled'] as $id => $pinned ) {
			if ( $pinned === $date ) {
				return (int) $id;
			}
		}

		return 0;
	}

	// ---------------------------------------------------------------------
	// Picking for a viewer
	// ---------------------------------------------------------------------

	/**
	 * The quote this viewer should see right now.
	 *
	 * Does not log anything: logging happens from the REST impression route
	 * once the popup has actually been displayed.
	 *
	 * @param int    $user_id      User ID, 0 for a guest.
	 * @param string $visitor_hash Guest key; defaults to the current visitor.
	 * @return int Post ID, or 0 when the pool is empty.
	 */
	public static function quote_for_viewer( $user_id = null, $visitor_hash = '' ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

		if ( ! $user_id && '' === $visitor_hash ) {
			$visitor_hash = CES_Helpers::visitor_hash();
		}

		$memo_key = $user_id ? 'u' . $user_id : 'h' . substr( $visitor_hash, 0, 16 );

		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}

		$pool = self::pool();

		if ( empty( $pool['rotation'] ) && empty( $pool['scheduled'] ) ) {
			self::$memo[ $memo_key ] = 0;
			return 0;
		}

		// A date-pinned quote always wins.
		$pinned = self::scheduled_for();

		if ( $pinned ) {
			self::$memo[ $memo_key ] = $pinned;
			return $pinned;
		}

		// Daily mode is sticky: reuse whatever was already logged today.
		if ( 'every_visit' !== self::frequency() ) {
			$row = self::today_row( $user_id, $visitor_hash );

			if ( $row && self::is_eligible( (int) $row->quote_id ) ) {
				self::$memo[ $memo_key ] = (int) $row->quote_id;
				return (int) $row->quote_id;
			}
		}

		$available = $pool['rotation'];

		if ( empty( $available ) ) {
			// Only date-pinned quotes exist and none is for today.
			self::$memo[ $memo_key ] = 0;
			return 0;
		}

		$seen = self::seen_ids( $user_id, $visitor_hash );

		if ( ! empty( $seen ) ) {
			$remaining = array_diff_key( $available, array_flip( $seen ) );

			if ( empty( $remaining ) ) {
				// Whole pool consumed: start a fresh cycle.
				self::reset_cycle( $user_id, $visitor_hash );
			} else {
				$available = $remaining;
			}
		}

		$picked = self::weighted_pick( $available );

		self::$memo[ $memo_key ] = $picked;

		return $picked;
	}

	/**
	 * Configured frequency, validated.
	 *
	 * @return string daily|every_visit
	 */
	public static function frequency() {
		$value = (string) CES_Helpers::setting( 'quotes_frequency', 'daily' );
		return 'every_visit' === $value ? 'every_visit' : 'daily';
	}

	/**
	 * Weighted random pick from an id => weight map.
	 *
	 * @param array<int,int> $candidates Post ID => weight.
	 * @return int Post ID, or 0 when the map is empty.
	 */
	public static function weighted_pick( array $candidates ) {
		if ( empty( $candidates ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $candidates as $weight ) {
			$total += max( 1, (int) $weight );
		}

		if ( $total <= 0 ) {
			$keys = array_keys( $candidates );
			return (int) $keys[0];
		}

		$ticket = wp_rand( 1, $total );
		$cursor = 0;

		foreach ( $candidates as $id => $weight ) {
			$cursor += max( 1, (int) $weight );

			if ( $ticket <= $cursor ) {
				return (int) $id;
			}
		}

		$keys = array_keys( $candidates );
		return (int) end( $keys );
	}

	// ---------------------------------------------------------------------
	// Cycle bookkeeping
	// ---------------------------------------------------------------------

	/**
	 * Date the viewer's current rotation cycle began.
	 *
	 * @param int    $user_id      User ID, 0 for a guest.
	 * @param string $visitor_hash Guest key.
	 * @return string Y-m-d
	 */
	public static function cycle_start( $user_id, $visitor_hash ) {
		$user_id = (int) $user_id;

		if ( $user_id ) {
			$stored = CES_Quotes_Cpt::sanitize_date( (string) get_user_meta( $user_id, self::CYCLE_KEY, true ) );

			if ( '' === $stored ) {
				$stored = self::today();
				update_user_meta( $user_id, self::CYCLE_KEY, $stored );
			}

			return $stored;
		}

		$key    = self::guest_key( self::CYCLE_KEY, $visitor_hash );
		$stored = CES_Quotes_Cpt::sanitize_date( (string) get_transient( $key ) );

		if ( '' === $stored ) {
			$stored = self::today();
			set_transient( $key, $stored, 60 * DAY_IN_SECONDS );
		}

		return $stored;
	}

	/**
	 * Start a new cycle for the viewer, so the whole pool is available again.
	 *
	 * @param int    $user_id      User ID, 0 for a guest.
	 * @param string $visitor_hash Guest key.
	 * @return void
	 */
	public static function reset_cycle( $user_id, $visitor_hash ) {
		$user_id = (int) $user_id;
		$today   = self::today();

		if ( $user_id ) {
			update_user_meta( $user_id, self::CYCLE_KEY, $today );
			delete_user_meta( $user_id, 'ces_quote_recent' );
			return;
		}

		set_transient( self::guest_key( self::CYCLE_KEY, $visitor_hash ), $today, 60 * DAY_IN_SECONDS );
		delete_transient( self::guest_key( 'ces_quote_recent', $visitor_hash ) );
	}

	/**
	 * Quote IDs the viewer has already been shown in this cycle.
	 *
	 * The log table is the source of truth. In `every_visit` mode the log can
	 * only hold one row per day, so a short "recently shown" list supplements
	 * it for the same-day repeats.
	 *
	 * @param int    $user_id      User ID, 0 for a guest.
	 * @param string $visitor_hash Guest key.
	 * @return int[] Quote IDs.
	 */
	public static function seen_ids( $user_id, $visitor_hash ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$table   = CES_Helpers::table( 'quote_log' );
		$since   = self::cycle_start( $user_id, $visitor_hash ) . ' 00:00:00';

		if ( $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT quote_id FROM {$table} WHERE user_id = %d AND shown_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					$since
				)
			);
		} elseif ( '' !== $visitor_hash ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT quote_id FROM {$table} WHERE user_id = 0 AND visitor_hash = %s AND shown_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$visitor_hash,
					$since
				)
			);
		} else {
			$rows = array();
		}

		$seen = array_map( 'intval', (array) $rows );

		foreach ( self::recent_ids( $user_id, $visitor_hash ) as $id ) {
			$seen[] = (int) $id;
		}

		return array_values( array_unique( array_filter( $seen ) ) );
	}

	/**
	 * The supplementary "shown within this cycle" list.
	 *
	 * @param int    $user_id      User ID, 0 for a guest.
	 * @param string $visitor_hash Guest key.
	 * @return int[]
	 */
	public static function recent_ids( $user_id, $visitor_hash ) {
		$user_id = (int) $user_id;

		if ( $user_id ) {
			$stored = get_user_meta( $user_id, 'ces_quote_recent', true );
		} else {
			$stored = get_transient( self::guest_key( 'ces_quote_recent', $visitor_hash ) );
		}

		return is_array( $stored ) ? array_map( 'intval', $stored ) : array();
	}

	/**
	 * Append a quote to the viewer's "shown within this cycle" list.
	 *
	 * @param int    $user_id      User ID, 0 for a guest.
	 * @param string $visitor_hash Guest key.
	 * @param int    $quote_id     Quote just shown.
	 * @return void
	 */
	public static function remember_recent( $user_id, $visitor_hash, $quote_id ) {
		$user_id  = (int) $user_id;
		$quote_id = (int) $quote_id;

		if ( $quote_id <= 0 ) {
			return;
		}

		$pool_size = count( self::pool()['rotation'] );
		$recent    = self::recent_ids( $user_id, $visitor_hash );
		$recent[]  = $quote_id;
		$recent    = array_values( array_unique( $recent ) );

		// Never grow past the pool, otherwise the cycle could never complete.
		if ( $pool_size > 0 && count( $recent ) > $pool_size ) {
			$recent = array_slice( $recent, -$pool_size );
		}

		if ( $user_id ) {
			update_user_meta( $user_id, 'ces_quote_recent', $recent );
			return;
		}

		set_transient( self::guest_key( 'ces_quote_recent', $visitor_hash ), $recent, 60 * DAY_IN_SECONDS );
	}

	/**
	 * Build a transient key for a guest, short enough for the options table.
	 *
	 * @param string $prefix       Logical key.
	 * @param string $visitor_hash Guest key.
	 * @return string
	 */
	private static function guest_key( $prefix, $visitor_hash ) {
		return 'ces_q_' . md5( $prefix . '|' . (string) $visitor_hash );
	}

	// ---------------------------------------------------------------------
	// The log
	// ---------------------------------------------------------------------

	/**
	 * The viewer's log row for today, if there is one.
	 *
	 * @param int    $user_id      User ID, 0 for a guest.
	 * @param string $visitor_hash Guest key.
	 * @return object|null
	 */
	public static function today_row( $user_id, $visitor_hash ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$table   = CES_Helpers::table( 'quote_log' );

		if ( $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d AND shown_on = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					self::today()
				)
			);
		}

		if ( '' === $visitor_hash ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = 0 AND visitor_hash = %s AND shown_at >= %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$visitor_hash,
				self::today() . ' 00:00:00'
			)
		);
	}

	/**
	 * Whether the viewer has already been shown a quote today.
	 *
	 * @param int    $user_id      User ID, 0 for a guest.
	 * @param string $visitor_hash Guest key.
	 * @return bool
	 */
	public static function shown_today( $user_id, $visitor_hash ) {
		return null !== self::today_row( $user_id, $visitor_hash );
	}

	/**
	 * Record that the popup actually appeared.
	 *
	 * Idempotent for logged-in users thanks to the unique (user_id, shown_on)
	 * index: a second impression on the same day is silently ignored.
	 *
	 * @param int    $quote_id     Quote shown.
	 * @param int    $user_id      User ID, 0 for a guest.
	 * @param string $visitor_hash Guest key.
	 * @return bool True when a row was written.
	 */
	public static function log_impression( $quote_id, $user_id, $visitor_hash ) {
		global $wpdb;

		$quote_id = (int) $quote_id;
		$user_id  = (int) $user_id;

		if ( $quote_id <= 0 ) {
			return false;
		}

		self::remember_recent( $user_id, $visitor_hash, $quote_id );

		$table = CES_Helpers::table( 'quote_log' );
		$now   = CES_Helpers::now();

		if ( $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$done = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} ( user_id, visitor_hash, quote_id, shown_on, dismissed, cta_clicked, shown_at ) VALUES ( %d, %s, %d, %s, 0, 0, %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					'',
					$quote_id,
					self::today(),
					$now
				)
			);

			return (bool) $done;
		}

		if ( '' === $visitor_hash || self::shown_today( 0, $visitor_hash ) ) {
			return false;
		}

		// Guests leave shown_on NULL so they do not collide on the unique
		// (user_id, shown_on) index. Their day comes from shown_at.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$done = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} ( user_id, visitor_hash, quote_id, shown_on, dismissed, cta_clicked, shown_at ) VALUES ( 0, %s, %d, NULL, 0, 0, %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$visitor_hash,
				$quote_id,
				$now
			)
		);

		return (bool) $done;
	}

	/**
	 * Flag today's row as dismissed or CTA-clicked.
	 *
	 * @param string $column       'dismissed' or 'cta_clicked'.
	 * @param int    $quote_id     Quote the viewer acted on.
	 * @param int    $user_id      User ID, 0 for a guest.
	 * @param string $visitor_hash Guest key.
	 * @return bool
	 */
	public static function mark( $column, $quote_id, $user_id, $visitor_hash ) {
		global $wpdb;

		if ( ! in_array( $column, array( 'dismissed', 'cta_clicked' ), true ) ) {
			return false;
		}

		$user_id  = (int) $user_id;
		$quote_id = (int) $quote_id;
		$table    = CES_Helpers::table( 'quote_log' );

		// The impression may not have been logged yet (very fast dismissal).
		if ( ! self::shown_today( $user_id, $visitor_hash ) ) {
			self::log_impression( $quote_id, $user_id, $visitor_hash );
		}

		$row = self::today_row( $user_id, $visitor_hash );

		if ( ! $row ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET {$column} = 1 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $row->id
			)
		);

		return false !== $done;
	}

	/**
	 * Delete log rows older than the retention window.
	 *
	 * @return int Rows removed.
	 */
	public static function prune_log() {
		global $wpdb;

		/**
		 * Filter how many days of quote impressions are kept.
		 *
		 * @param int $days Retention window in days.
		 */
		$days = (int) apply_filters( 'ces_quotes_log_retention_days', 180 );
		$days = max( 7, $days );

		$cutoff = self::days_ago( $days );
		$table  = CES_Helpers::table( 'quote_log' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE shown_at IS NOT NULL AND shown_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return (int) $removed;
	}

	// ---------------------------------------------------------------------
	// Site-wide quote of the day
	// ---------------------------------------------------------------------

	/**
	 * The site-wide quote of the day, recomputed lazily when stale.
	 *
	 * @return int Post ID or 0.
	 */
	public static function quote_of_the_day() {
		$stored = get_option( self::OPTION_QOTD, array() );

		if (
			is_array( $stored )
			&& isset( $stored['date'], $stored['quote_id'] )
			&& self::today() === $stored['date']
			&& self::is_eligible( (int) $stored['quote_id'] )
		) {
			return (int) $stored['quote_id'];
		}

		return self::rotate_quote_of_the_day();
	}

	/**
	 * Choose and store a fresh site-wide quote of the day.
	 *
	 * @return int Post ID or 0.
	 */
	public static function rotate_quote_of_the_day() {
		$pinned = self::scheduled_for();

		if ( $pinned ) {
			$picked = $pinned;
		} else {
			$pool = self::pool();

			if ( empty( $pool['rotation'] ) ) {
				return 0;
			}

			$candidates = $pool['rotation'];
			$previous   = get_option( self::OPTION_QOTD, array() );

			if ( is_array( $previous ) && ! empty( $previous['quote_id'] ) && count( $candidates ) > 1 ) {
				unset( $candidates[ (int) $previous['quote_id'] ] );
			}

			$picked = self::weighted_pick( $candidates );
		}

		if ( $picked <= 0 ) {
			return 0;
		}

		update_option(
			self::OPTION_QOTD,
			array(
				'date'     => self::today(),
				'quote_id' => (int) $picked,
			),
			false
		);

		return (int) $picked;
	}

	/**
	 * A random active quote, optionally limited to one category.
	 *
	 * @param string $category Category slug or name, optional.
	 * @return int Post ID or 0.
	 */
	public static function random_quote( $category = '' ) {
		$pool = self::pool();

		if ( empty( $pool['rotation'] ) ) {
			return 0;
		}

		$candidates = $pool['rotation'];

		if ( '' !== $category ) {
			$filtered = array();

			foreach ( array_keys( $candidates ) as $id ) {
				if ( has_term( $category, CES_Quotes_Cpt::TAXONOMY, $id ) ) {
					$filtered[ $id ] = $candidates[ $id ];
				}
			}

			if ( ! empty( $filtered ) ) {
				$candidates = $filtered;
			}
		}

		return self::weighted_pick( $candidates );
	}

	// ---------------------------------------------------------------------
	// Reporting
	// ---------------------------------------------------------------------

	/**
	 * Engagement totals over a window.
	 *
	 * @param int $days Window length in days.
	 * @return array{impressions:int,dismissed:int,cta_clicked:int,unique_viewers:int,dismiss_rate:float,cta_rate:float}
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;

		$days  = max( 1, (int) $days );
		$table = CES_Helpers::table( 'quote_log' );
		$since = self::days_ago( $days - 1 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS impressions,
					SUM(dismissed) AS dismissed,
					SUM(cta_clicked) AS cta_clicked,
					COUNT(DISTINCT CONCAT(user_id, '|', visitor_hash)) AS unique_viewers
				FROM {$table}
				WHERE shown_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		$impressions = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;
		$dismissed   = isset( $row['dismissed'] ) ? (int) $row['dismissed'] : 0;
		$clicked     = isset( $row['cta_clicked'] ) ? (int) $row['cta_clicked'] : 0;
		$viewers     = isset( $row['unique_viewers'] ) ? (int) $row['unique_viewers'] : 0;

		return array(
			'impressions'    => $impressions,
			'dismissed'      => $dismissed,
			'cta_clicked'    => $clicked,
			'unique_viewers' => $viewers,
			'dismiss_rate'   => $impressions > 0 ? round( ( $dismissed / $impressions ) * 100, 1 ) : 0.0,
			'cta_rate'       => $impressions > 0 ? round( ( $clicked / $impressions ) * 100, 1 ) : 0.0,
		);
	}

	/**
	 * Per-quote engagement breakdown, best CTA rate first.
	 *
	 * @param int $days  Window length in days.
	 * @param int $limit Rows to return.
	 * @return array[] Each row: quote_id, impressions, dismissed, cta_clicked.
	 */
	public static function per_quote_stats( $days = 30, $limit = 20 ) {
		global $wpdb;

		$days  = max( 1, (int) $days );
		$limit = max( 1, min( 100, (int) $limit ) );
		$table = CES_Helpers::table( 'quote_log' );
		$since = self::days_ago( $days - 1 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT quote_id,
					COUNT(*) AS impressions,
					SUM(dismissed) AS dismissed,
					SUM(cta_clicked) AS cta_clicked
				FROM {$table}
				WHERE shown_at >= %s AND quote_id > 0
				GROUP BY quote_id
				ORDER BY cta_clicked DESC, impressions DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since,
				$limit
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'quote_id'    => (int) $row['quote_id'],
				'impressions' => (int) $row['impressions'],
				'dismissed'   => (int) $row['dismissed'],
				'cta_clicked' => (int) $row['cta_clicked'],
			);
		}

		return $out;
	}
}
