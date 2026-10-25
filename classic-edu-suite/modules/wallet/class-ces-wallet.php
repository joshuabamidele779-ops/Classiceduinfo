<?php
/**
 * Coin wallet ledger: the single source of truth for student coin balances.
 *
 * The `wallet_ledger` table is append-only. Every mutation writes one row
 * carrying the signed `delta` and the resulting `balance_after`. The newest
 * row's `balance_after` is the authoritative balance; it is mirrored into the
 * `ces_coin_balance` user meta purely as a read cache.
 *
 * Concurrency model (see modules/wallet/README.md for the long version):
 *
 *   1. A MySQL advisory lock (`GET_LOCK`) serialises read-modify-write per
 *      user. When the host forbids `GET_LOCK` we degrade to a transient spin
 *      lock, and when that is unavailable we continue unlocked.
 *   2. A real transaction wraps the balance read and the insert, and the read
 *      uses `SELECT ... FOR UPDATE` so a competing request blocks on the row
 *      rather than reading a stale balance.
 *   3. The unique `idempotency_key` index is the actual guarantee. A duplicate
 *      key is swallowed and reported as a silent no-op, so a student
 *      double-tapping "unlock video" can never be charged twice as long as the
 *      caller passes a deterministic key.
 *
 * Callers that spend coins SHOULD always pass a deterministic
 * `idempotency_key`; that is what makes a debit replay-proof.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Append-only coin ledger and the cross-module wallet API.
 */
class CES_Wallet {

	/**
	 * User meta key holding the cached balance.
	 */
	const META_BALANCE = 'ces_coin_balance';

	/**
	 * Seconds to wait for the per-user advisory lock.
	 */
	const LOCK_TIMEOUT = 5;

	/**
	 * Current balance for a user.
	 *
	 * Reads the cached meta value and falls back to a full ledger
	 * recomputation when the cache is missing or corrupt.
	 *
	 * @param int $user_id User ID.
	 * @return int Balance in coins, never negative.
	 */
	public static function balance( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$cached = get_user_meta( $user_id, self::META_BALANCE, true );

		if ( is_numeric( $cached ) ) {
			return max( 0, (int) $cached );
		}

		return self::recalculate( $user_id );
	}

	/**
	 * Add coins to a user's wallet.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $amount  Positive coin amount.
	 * @param string $type    Transaction type, e.g. `referral_reward`.
	 * @param array  $args    Optional `ref_type`, `ref_id`, `note`,
	 *                        `idempotency_key`.
	 * @return int|WP_Error New balance, or WP_Error on failure.
	 */
	public static function credit( $user_id, $amount, $type, $args = array() ) {
		$amount = self::normalize_amount( $amount );

		if ( is_wp_error( $amount ) ) {
			return $amount;
		}

		$user_id = self::normalize_user( $user_id );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return self::write( $user_id, $amount, $type, is_array( $args ) ? $args : array() );
	}

	/**
	 * Remove coins from a user's wallet.
	 *
	 * Atomic against concurrent requests and never allows a negative balance.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $amount  Positive coin amount to deduct.
	 * @param string $type    Transaction type, e.g. `video_unlock`.
	 * @param array  $args    Optional `ref_type`, `ref_id`, `note`,
	 *                        `idempotency_key`.
	 * @return int|WP_Error New balance, or WP_Error. Returns
	 *                      WP_Error( 'ces_insufficient_funds' ) when too low.
	 */
	public static function debit( $user_id, $amount, $type, $args = array() ) {
		$amount = self::normalize_amount( $amount );

		if ( is_wp_error( $amount ) ) {
			return $amount;
		}

		$user_id = self::normalize_user( $user_id );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return self::write( $user_id, -$amount, $type, is_array( $args ) ? $args : array() );
	}

	/**
	 * Recent ledger rows, newest first.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Rows to return, 1-200.
	 * @param int $offset  Rows to skip.
	 * @return array List of associative rows.
	 */
	public static function history( $user_id, $limit = 50, $offset = 0 ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return array();
		}

		$limit  = max( 1, min( 200, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$table  = CES_Helpers::table( 'wallet_ledger' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, delta, balance_after, type, ref_type, ref_id, note, created_at
				FROM {$table}
				WHERE user_id = %d
				ORDER BY id DESC
				LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['id']            = (int) $row['id'];
			$rows[ $index ]['delta']         = (int) $row['delta'];
			$rows[ $index ]['balance_after'] = (int) $row['balance_after'];
			$rows[ $index ]['ref_id']        = (int) $row['ref_id'];
			$rows[ $index ]['label']         = self::type_label( $row['type'] );
		}

		return $rows;
	}

	/**
	 * Total ledger rows for a user, for paging.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function history_count( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$table = CES_Helpers::table( 'wallet_ledger' );

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Rebuild the cached balance from the ledger.
	 *
	 * Repair entry point: the ledger is authoritative, the meta cache is not.
	 * Logs when the newest row's `balance_after` disagrees with the sum of all
	 * deltas, which would indicate a historic partial write.
	 *
	 * @param int $user_id User ID.
	 * @return int The balance now cached.
	 */
	public static function recalculate( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$table = CES_Helpers::table( 'wallet_ledger' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, balance_after FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
				$user_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$latest = $row ? (int) $row->balance_after : 0;

		$sum = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(delta),0) FROM {$table} WHERE user_id = %d", $user_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $row && $sum !== $latest ) {
			CES_Helpers::log(
				'Wallet ledger drift detected',
				array(
					'user_id'       => $user_id,
					'latest_row'    => (int) $row->id,
					'balance_after' => $latest,
					'sum_of_deltas' => $sum,
				)
			);
		}

		$balance = max( 0, $latest );

		update_user_meta( $user_id, self::META_BALANCE, $balance );

		return $balance;
	}

	/**
	 * Look up a ledger row by its idempotency key.
	 *
	 * @param string $key Idempotency key.
	 * @return object|null Row object, or null.
	 */
	public static function find_by_key( $key ) {
		global $wpdb;

		$key = (string) $key;

		if ( '' === $key ) {
			return null;
		}

		$table = CES_Helpers::table( 'wallet_ledger' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s LIMIT 1", $key )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $row ? $row : null;
	}

	/**
	 * Sum of coin deltas for one user, one type, on one calendar day.
	 *
	 * Used by the daily caps in CES_Rewards.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Transaction type.
	 * @param string $date    Optional Y-m-d date, defaults to today (site time).
	 * @return int
	 */
	public static function sum_for_day( $user_id, $type, $date = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$date  = $date ? $date : current_time( 'Y-m-d' );
		$table = CES_Helpers::table( 'wallet_ledger' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(delta),0) FROM {$table}
				WHERE user_id = %d AND type = %s AND DATE(created_at) = %s",
				$user_id,
				(string) $type,
				$date
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Sum of coin deltas for one user across several transaction types.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $types   Transaction types.
	 * @return int
	 */
	public static function sum_for_types( $user_id, array $types ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$types   = array_values( array_filter( array_map( 'strval', $types ) ) );

		if ( $user_id <= 0 || empty( $types ) ) {
			return 0;
		}

		$table        = CES_Helpers::table( 'wallet_ledger' );
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$params       = array_merge( array( $user_id ), $types );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare(
			"SELECT COALESCE(SUM(delta),0) FROM {$table} WHERE user_id = %d AND type IN ( {$placeholders} )",
			$params
		);

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Total coins currently in circulation across all accounts.
	 *
	 * @return int
	 */
	public static function total_in_circulation() {
		global $wpdb;

		$table = CES_Helpers::table( 'wallet_ledger' );

		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(delta),0) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Known transaction types mapped to human labels.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'signup_bonus'       => __( 'Signup bonus', 'classic-edu-suite' ),
			'referral_reward'    => __( 'Referral reward', 'classic-edu-suite' ),
			'referral_milestone' => __( 'Referral milestone bonus', 'classic-edu-suite' ),
			'daily_login'        => __( 'Daily login', 'classic-edu-suite' ),
			'streak_bonus'       => __( 'Login streak bonus', 'classic-edu-suite' ),
			'quiz_pass'          => __( 'Quiz passed', 'classic-edu-suite' ),
			'video_unlock'       => __( 'Video unlocked', 'classic-edu-suite' ),
			'admin_adjust'       => __( 'Admin adjustment', 'classic-edu-suite' ),
		);
	}

	/**
	 * Human label for a transaction type.
	 *
	 * @param string $type Transaction type.
	 * @return string
	 */
	public static function type_label( $type ) {
		$types = self::types();
		$type  = (string) $type;

		if ( isset( $types[ $type ] ) ) {
			return $types[ $type ];
		}

		return ucwords( str_replace( '_', ' ', $type ) );
	}

	// -----------------------------------------------------------------
	// Internals.
	// -----------------------------------------------------------------

	/**
	 * Validate and coerce a coin amount.
	 *
	 * Accepts a real integer, an all-digit string or a float with no
	 * fractional part. Everything else is rejected.
	 *
	 * @param mixed $amount Raw amount.
	 * @return int|WP_Error
	 */
	private static function normalize_amount( $amount ) {
		$int = null;

		if ( is_int( $amount ) ) {
			$int = $amount;
		} elseif ( is_float( $amount ) && (float) (int) $amount === $amount ) {
			$int = (int) $amount;
		} elseif ( is_string( $amount ) && '' !== trim( $amount ) && ctype_digit( trim( $amount ) ) ) {
			$int = (int) trim( $amount );
		}

		if ( null === $int ) {
			return new WP_Error(
				'ces_invalid_amount',
				__( 'Coin amounts must be whole numbers.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		if ( $int <= 0 ) {
			return new WP_Error(
				'ces_invalid_amount',
				__( 'Coin amounts must be greater than zero.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Filter the largest single coin movement allowed.
		 *
		 * A sanity ceiling, well inside the signed INT column range.
		 *
		 * @param int $max Maximum coins per transaction.
		 */
		$max = (int) apply_filters( 'ces_wallet_max_amount', 1000000 );

		if ( $int > $max ) {
			return new WP_Error(
				'ces_invalid_amount',
				__( 'That coin amount is too large.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		return $int;
	}

	/**
	 * Validate a user ID against the users table.
	 *
	 * @param mixed $user_id Raw user ID.
	 * @return int|WP_Error
	 */
	private static function normalize_user( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'ces_invalid_user',
				__( 'Unknown user account.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		return $user_id;
	}

	/**
	 * Append one row to the ledger.
	 *
	 * @param int    $user_id Validated user ID.
	 * @param int    $delta   Signed coin delta, non-zero.
	 * @param string $type    Transaction type.
	 * @param array  $args    Optional row arguments.
	 * @return int|WP_Error New balance, or WP_Error.
	 */
	private static function write( $user_id, $delta, $type, array $args ) {
		global $wpdb;

		$delta = (int) $delta;

		if ( 0 === $delta ) {
			return new WP_Error(
				'ces_invalid_amount',
				__( 'Coin amounts must be greater than zero.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		$table    = CES_Helpers::table( 'wallet_ledger' );
		$type     = substr( sanitize_key( (string) $type ), 0, 40 );
		$ref_type = isset( $args['ref_type'] ) ? substr( sanitize_key( (string) $args['ref_type'] ), 0, 40 ) : '';
		$ref_id   = isset( $args['ref_id'] ) ? absint( $args['ref_id'] ) : 0;
		$note     = isset( $args['note'] ) ? substr( sanitize_text_field( (string) $args['note'] ), 0, 255 ) : '';

		if ( '' === $type ) {
			return new WP_Error(
				'ces_invalid_type',
				__( 'A transaction type is required.', 'classic-edu-suite' ),
				array( 'status' => 400 )
			);
		}

		$key = '';
		if ( isset( $args['idempotency_key'] ) && '' !== trim( (string) $args['idempotency_key'] ) ) {
			$key = substr( sanitize_text_field( (string) $args['idempotency_key'] ), 0, 100 );
		}

		if ( '' === $key ) {
			// The unique index covers this column, so it can never be blank.
			$key = self::generate_key( $user_id, $type );
		}

		// Cheap pre-flight: an already-recorded award is a silent no-op.
		if ( self::find_by_key( $key ) ) {
			return self::balance( $user_id );
		}

		$lock = self::lock( $user_id );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB

		$current = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT balance_after FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1 FOR UPDATE",
				$user_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$new_balance = $current + $delta;

		if ( $new_balance < 0 ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
			self::unlock( $user_id, $lock );

			return new WP_Error(
				'ces_insufficient_funds',
				sprintf(
					/* translators: 1: coins needed, 2: coins available. */
					__( 'You need %1$s coins but only have %2$s. Invite a friend or take a quiz to earn more.', 'classic-edu-suite' ),
					CES_Helpers::format_coins( abs( $delta ) ),
					CES_Helpers::format_coins( $current )
				),
				array(
					'status'    => 402,
					'balance'   => $current,
					'required'  => abs( $delta ),
					'shortfall' => abs( $delta ) - $current,
				)
			);
		}

		$suppress = $wpdb->suppress_errors( true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'         => $user_id,
				'delta'           => $delta,
				'balance_after'   => $new_balance,
				'type'            => $type,
				'ref_type'        => $ref_type,
				'ref_id'          => $ref_id,
				'idempotency_key' => $key,
				'note'            => $note,
				'created_at'      => CES_Helpers::now(),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$db_error = (string) $wpdb->last_error;

		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
			self::unlock( $user_id, $lock );

			if ( self::is_duplicate_error( $db_error ) ) {
				// Another request already booked this exact award.
				return self::recalculate( $user_id );
			}

			CES_Helpers::log(
				'Wallet ledger write failed',
				array(
					'user_id' => $user_id,
					'delta'   => $delta,
					'type'    => $type,
					'error'   => $db_error,
				)
			);

			return new WP_Error(
				'ces_ledger_write_failed',
				__( 'Could not record that coin transaction. Please try again.', 'classic-edu-suite' ),
				array( 'status' => 500 )
			);
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		self::unlock( $user_id, $lock );

		update_user_meta( $user_id, self::META_BALANCE, $new_balance );

		if ( $delta > 0 ) {
			/**
			 * Fires after coins are credited.
			 *
			 * @param int    $user_id     User ID.
			 * @param int    $amount      Positive coin amount.
			 * @param string $type        Transaction type.
			 * @param int    $new_balance Balance after the credit.
			 */
			do_action( 'ces_coins_credited', $user_id, $delta, $type, $new_balance );
		} else {
			/**
			 * Fires after coins are debited.
			 *
			 * @param int    $user_id     User ID.
			 * @param int    $amount      Positive coin amount removed.
			 * @param string $type        Transaction type.
			 * @param int    $new_balance Balance after the debit.
			 */
			do_action( 'ces_coins_debited', $user_id, abs( $delta ), $type, $new_balance );
		}

		return $new_balance;
	}

	/**
	 * Build a unique fallback idempotency key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Transaction type.
	 * @return string
	 */
	private static function generate_key( $user_id, $type ) {
		$key = 'auto:' . $type . ':' . (int) $user_id . ':'
			. str_replace( '.', '', (string) microtime( true ) ) . ':'
			. wp_generate_password( 10, false, false );

		return substr( $key, 0, 100 );
	}

	/**
	 * Whether a MySQL error string is a unique-key violation.
	 *
	 * @param string $error Error text from $wpdb->last_error.
	 * @return bool
	 */
	private static function is_duplicate_error( $error ) {
		$error = (string) $error;

		if ( '' === $error ) {
			return false;
		}

		return ( false !== stripos( $error, 'duplicate entry' ) )
			|| ( false !== stripos( $error, 'duplicate key' ) )
			|| ( false !== strpos( $error, '1062' ) );
	}

	/**
	 * Advisory lock name for a user, namespaced per install.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function lock_name( $user_id ) {
		global $wpdb;

		// MySQL lock names are limited to 64 characters.
		return 'ces' . substr( md5( $wpdb->prefix . '|' . DB_NAME ), 0, 12 ) . 'w' . (int) $user_id;
	}

	/**
	 * Acquire the per-user write lock.
	 *
	 * @param int $user_id User ID.
	 * @return string Lock mode actually acquired: `mysql`, `transient` or `none`.
	 */
	private static function lock( $user_id ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$got      = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $user_id ), self::LOCK_TIMEOUT )
		); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $suppress );

		if ( '1' === (string) $got ) {
			return 'mysql';
		}

		if ( '0' === (string) $got ) {
			// GET_LOCK works but we timed out waiting. The transaction below is
			// still safe, so proceed rather than failing the student's request.
			CES_Helpers::log( 'Wallet lock timed out', array( 'user_id' => (int) $user_id ) );
			return 'none';
		}

		// GET_LOCK unavailable (restricted grants). Best-effort spin lock.
		$key = 'ces_wlock_' . (int) $user_id;

		for ( $i = 0; $i < 15; $i++ ) {
			if ( ! get_transient( $key ) ) {
				set_transient( $key, 1, 10 );
				return 'transient';
			}
			usleep( 100000 );
		}

		return 'none';
	}

	/**
	 * Release the per-user write lock.
	 *
	 * @param int    $user_id User ID.
	 * @param string $mode    Mode returned by lock().
	 * @return void
	 */
	private static function unlock( $user_id, $mode ) {
		global $wpdb;

		if ( 'mysql' === $mode ) {
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $user_id ) )
			); // phpcs:ignore WordPress.DB
			$wpdb->suppress_errors( $suppress );
			return;
		}

		if ( 'transient' === $mode ) {
			delete_transient( 'ces_wlock_' . (int) $user_id );
		}
	}
}
