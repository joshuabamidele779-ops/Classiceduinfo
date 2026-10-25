<?php
/**
 * Wallet admin screen: look up a student, read their ledger, adjust their
 * balance by hand, and review referrals with their fraud flags.
 *
 * Every write on this screen is guarded by the `ces_manage_wallet`
 * capability and a nonce, and routed through admin-post.php rather than
 * being handled inline on a GET request.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for the coin wallet and the referral programme.
 */
class CES_Wallet_Admin {

	/**
	 * Admin page slug.
	 */
	const SLUG = 'ces-wallet';

	/**
	 * Capability required for every action on this screen.
	 */
	const CAP = 'ces_manage_wallet';

	/**
	 * Register the admin-post handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_ces_wallet_adjust', array( __CLASS__, 'handle_adjust' ) );
		add_action( 'admin_post_ces_wallet_recalc', array( __CLASS__, 'handle_recalculate' ) );
		add_action( 'admin_post_ces_wallet_referral', array( __CLASS__, 'handle_referral' ) );
	}

	/**
	 * Add the wallet screen to the Classic EDU menu.
	 *
	 * @param array $items Submenu definitions.
	 * @return array
	 */
	public static function submenus( $items ) {
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$items[] = array(
			'slug'       => self::SLUG,
			'title'      => __( 'Coin Wallet', 'classic-edu-suite' ),
			'menu_title' => __( 'Coin Wallet', 'classic-edu-suite' ),
			'capability' => self::CAP,
			'callback'   => array( __CLASS__, 'render_page' ),
			'position'   => 40,
		);

		return $items;
	}

	/**
	 * Contribute stat cards to the overview screen.
	 *
	 * @param array $cards Cards collected so far.
	 * @return array
	 */
	public static function overview_cards( $cards ) {
		if ( ! is_array( $cards ) ) {
			$cards = array();
		}

		$cards[] = array(
			'label' => __( 'Coins in circulation', 'classic-edu-suite' ),
			'value' => CES_Helpers::format_coins( CES_Wallet::total_in_circulation() ),
			'href'  => admin_url( 'admin.php?page=' . self::SLUG ),
		);

		$cards[] = array(
			'label' => __( 'Qualified referrals this month', 'classic-edu-suite' ),
			'value' => CES_Helpers::format_coins( CES_Referral::qualified_this_month() ),
			'href'  => admin_url( 'admin.php?page=' . self::SLUG . '&view=referrals' ),
		);

		return $cards;
	}

	/**
	 * Register the wallet settings fields.
	 *
	 * Every key already exists in CES_Settings::defaults().
	 *
	 * @param array $sections Sections collected so far.
	 * @return array
	 */
	public static function settings_sections( $sections ) {
		if ( ! is_array( $sections ) ) {
			$sections = array();
		}

		$sections[] = array(
			'title'  => __( 'Coin Wallet & Referrals', 'classic-edu-suite' ),
			'fields' => array(
				'coins_signup_bonus'       => array(
					'label' => __( 'Signup bonus', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Coins paid once when an account is created. 0 disables it.', 'classic-edu-suite' ),
				),
				'coins_per_referral'       => array(
					'label' => __( 'Coins per qualified referral', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Paid when the invited student confirms their account and finishes one CBT attempt.', 'classic-edu-suite' ),
				),
				'coins_milestone_every'    => array(
					'label' => __( 'Milestone every N referrals', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'A bonus is paid at every multiple of this number of qualified referrals. 0 disables milestones.', 'classic-edu-suite' ),
				),
				'coins_referral_milestone' => array(
					'label' => __( 'Milestone bonus', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Coins paid at each referral milestone.', 'classic-edu-suite' ),
				),
				'coins_daily_login'        => array(
					'label' => __( 'Daily login coins', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Paid once per calendar day on the first visit.', 'classic-edu-suite' ),
				),
				'coins_streak_bonus'       => array(
					'label' => __( '7-day streak bonus', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Extra coins each time a student completes seven consecutive days.', 'classic-edu-suite' ),
				),
				'coins_per_quiz_pass'      => array(
					'label' => __( 'Coins per quiz pass', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Paid when a CBT attempt is submitted and passed.', 'classic-edu-suite' ),
				),
				'coins_quiz_daily_cap'     => array(
					'label' => __( 'Quiz coins daily cap', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Most coins one student can earn from quizzes in a day. 0 removes the cap.', 'classic-edu-suite' ),
				),
			),
		);

		return $sections;
	}

	/**
	 * Render the admin screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! CES_Helpers::can_manage( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'classic-edu-suite' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'users';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$message = isset( $_GET['ces_msg'] ) ? sanitize_key( wp_unslash( $_GET['ces_msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap ces-wrap">';
		echo '<h1>' . esc_html__( 'Coin Wallet', 'classic-edu-suite' ) . '</h1>';

		self::render_notice( $message );

		// Tabs.
		$views = array(
			'users'     => __( 'Students', 'classic-edu-suite' ),
			'referrals' => __( 'Referral report', 'classic-edu-suite' ),
		);

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $views as $slug => $label ) {
			$url   = admin_url( 'admin.php?page=' . self::SLUG . '&view=' . $slug );
			$class = ( $slug === $view ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		if ( 'referrals' === $view ) {
			self::render_referral_report();
		} else {
			self::render_user_view( $search, $user_id );
		}

		echo '</div>';
	}

	/**
	 * Show the result of the last write.
	 *
	 * @param string $message Message slug.
	 * @return void
	 */
	private static function render_notice( $message ) {
		$notices = array(
			'credited'    => array( 'success', __( 'Coins credited.', 'classic-edu-suite' ) ),
			'debited'     => array( 'success', __( 'Coins debited.', 'classic-edu-suite' ) ),
			'recalced'    => array( 'success', __( 'Balance rebuilt from the ledger.', 'classic-edu-suite' ) ),
			'qualified'   => array( 'success', __( 'Referral approved and paid.', 'classic-edu-suite' ) ),
			'rejected'    => array( 'success', __( 'Referral rejected.', 'classic-edu-suite' ) ),
			'nofunds'     => array( 'error', __( 'That student does not have enough coins.', 'classic-edu-suite' ) ),
			'badinput'    => array( 'error', __( 'Please pick a student and enter a whole number of coins.', 'classic-edu-suite' ) ),
			'failed'      => array( 'error', __( 'That did not work. Check the debug log.', 'classic-edu-suite' ) ),
		);

		if ( '' === $message || ! isset( $notices[ $message ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notices[ $message ][0] ),
			esc_html( $notices[ $message ][1] )
		);
	}

	/**
	 * Student search, ledger and manual adjustment.
	 *
	 * @param string $search  Search term.
	 * @param int    $user_id Selected user ID.
	 * @return void
	 */
	private static function render_user_view( $search, $user_id ) {
		// Search form.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="ces-wallet-search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="view" value="users" />';
		echo '<p>';
		echo '<label class="screen-reader-text" for="ces-wallet-s">' . esc_html__( 'Search students', 'classic-edu-suite' ) . '</label>';
		echo '<input type="search" id="ces-wallet-s" name="s" value="' . esc_attr( $search ) . '" class="regular-text" placeholder="' . esc_attr__( 'Username, email or user ID', 'classic-edu-suite' ) . '" />';
		submit_button( __( 'Search', 'classic-edu-suite' ), 'secondary', '', false );
		echo '</p>';
		echo '</form>';

		if ( '' !== $search && $user_id <= 0 ) {
			self::render_search_results( $search );
			return;
		}

		if ( $user_id <= 0 ) {
			echo '<p>' . esc_html__( 'Search for a student to see their coin balance and ledger.', 'classic-edu-suite' ) . '</p>';
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			echo '<p>' . esc_html__( 'That user no longer exists.', 'classic-edu-suite' ) . '</p>';
			return;
		}

		$balance = CES_Wallet::balance( $user_id );
		$streak  = CES_Rewards::streak( $user_id );
		$stats   = CES_Referral::stats( $user_id );

		echo '<h2>' . esc_html( $user->display_name ? $user->display_name : $user->user_login ) . '</h2>';
		echo '<p class="description">' . esc_html( $user->user_email ) . ' &middot; ';
		printf(
			/* translators: %d: user ID. */
			esc_html__( 'User ID %d', 'classic-edu-suite' ),
			(int) $user_id
		);
		echo '</p>';

		echo '<table class="widefat striped ces-wallet-summary"><tbody>';
		self::summary_row( __( 'Coin balance', 'classic-edu-suite' ), CES_Helpers::format_coins( $balance ) );
		self::summary_row( __( 'Login streak', 'classic-edu-suite' ), CES_Helpers::format_coins( $streak['days'] ) );
		self::summary_row( __( 'Referral code', 'classic-edu-suite' ), $stats['code'] );
		self::summary_row( __( 'Qualified referrals', 'classic-edu-suite' ), CES_Helpers::format_coins( $stats['qualified'] ) );
		self::summary_row( __( 'Pending referrals', 'classic-edu-suite' ), CES_Helpers::format_coins( $stats['pending'] ) );
		echo '</tbody></table>';

		// Manual adjustment.
		echo '<h3>' . esc_html__( 'Adjust balance', 'classic-edu-suite' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ces_wallet_adjust_' . $user_id );
		echo '<input type="hidden" name="action" value="ces_wallet_adjust" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( $user_id ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="ces-adjust-direction">' . esc_html__( 'Direction', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<select id="ces-adjust-direction" name="direction">';
		echo '<option value="credit">' . esc_html__( 'Add coins', 'classic-edu-suite' ) . '</option>';
		echo '<option value="debit">' . esc_html__( 'Remove coins', 'classic-edu-suite' ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="ces-adjust-amount">' . esc_html__( 'Coins', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="number" min="1" step="1" id="ces-adjust-amount" name="amount" class="small-text" required />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ces-adjust-note">' . esc_html__( 'Note', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="text" id="ces-adjust-note" name="note" class="regular-text" maxlength="255" placeholder="' . esc_attr__( 'Why you are adjusting this balance', 'classic-edu-suite' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Stored on the ledger row so the adjustment is always traceable.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save adjustment', 'classic-edu-suite' ) );
		echo '</form>';

		// Repair.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ces_wallet_recalc_' . $user_id );
		echo '<input type="hidden" name="action" value="ces_wallet_recalc" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( $user_id ) . '" />';
		submit_button( __( 'Rebuild balance from ledger', 'classic-edu-suite' ), 'secondary', 'submit', true );
		echo '</form>';

		// Ledger.
		self::render_ledger( $user_id );
	}

	/**
	 * One label/value row in the summary table.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 * @return void
	 */
	private static function summary_row( $label, $value ) {
		echo '<tr><th scope="row" style="width:220px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * Matching students for a search term.
	 *
	 * @param string $search Search term.
	 * @return void
	 */
	private static function render_search_results( $search ) {
		$args = array(
			'number'         => 25,
			'fields'         => array( 'ID', 'user_login', 'user_email', 'display_name' ),
			'search'         => '*' . $search . '*',
			'search_columns' => array( 'user_login', 'user_email', 'user_nicename' ),
		);

		if ( ctype_digit( $search ) ) {
			$args = array(
				'include' => array( (int) $search ),
				'fields'  => array( 'ID', 'user_login', 'user_email', 'display_name' ),
			);
		}

		$users = get_users( $args );

		if ( empty( $users ) ) {
			echo '<p>' . esc_html__( 'No students matched that search.', 'classic-edu-suite' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Student', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Balance', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $users as $user ) {
			$url = admin_url( 'admin.php?page=' . self::SLUG . '&view=users&user=' . (int) $user->ID );

			echo '<tr>';
			echo '<td>' . esc_html( $user->display_name ? $user->display_name : $user->user_login ) . '</td>';
			echo '<td>' . esc_html( $user->user_email ) . '</td>';
			echo '<td>' . esc_html( CES_Helpers::format_coins( CES_Wallet::balance( (int) $user->ID ) ) ) . '</td>';
			echo '<td><a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Open wallet', 'classic-edu-suite' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Paginated ledger table for one student.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function render_ledger( $user_id ) {
		$per_page = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['lpage'] ) ? max( 1, absint( $_GET['lpage'] ) ) : 1;
		$offset = ( $page - 1 ) * $per_page;

		$rows  = CES_Wallet::history( $user_id, $per_page, $offset );
		$total = CES_Wallet::history_count( $user_id );

		echo '<h3>' . esc_html__( 'Ledger', 'classic-edu-suite' ) . '</h3>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No coin activity on this account yet.', 'classic-edu-suite' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Change', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Balance after', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Note', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$delta = (int) $row['delta'];

			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['label'] ) . '</td>';
			echo '<td>' . esc_html( ( $delta >= 0 ? '+' : '-' ) . CES_Helpers::format_coins( abs( $delta ) ) ) . '</td>';
			echo '<td>' . esc_html( CES_Helpers::format_coins( (int) $row['balance_after'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['note'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per_page );

		if ( $pages > 1 ) {
			$base = admin_url( 'admin.php?page=' . self::SLUG . '&view=users&user=' . (int) $user_id );

			echo '<p class="ces-pager">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => $base . '%_%',
						'format'    => '&lpage=%#%',
						'current'   => $page,
						'total'     => $pages,
						'type'      => 'plain',
						'prev_text' => __( '&laquo; Previous', 'classic-edu-suite' ),
						'next_text' => __( 'Next &raquo;', 'classic-edu-suite' ),
					)
				)
			);
			echo '</p>';
		}
	}

	/**
	 * Referral report with fraud flags.
	 *
	 * @return void
	 */
	private static function render_referral_report() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['rpage'] ) ? max( 1, absint( $_GET['rpage'] ) ) : 1;
		$limit  = 25;
		$offset = ( $page - 1 ) * $limit;

		$filters = array(
			''          => __( 'All', 'classic-edu-suite' ),
			'pending'   => __( 'Pending', 'classic-edu-suite' ),
			'qualified' => __( 'Qualified', 'classic-edu-suite' ),
			'rejected'  => __( 'Rejected', 'classic-edu-suite' ),
		);

		$last = count( $filters );
		$i    = 0;

		echo '<ul class="subsubsub">';
		foreach ( $filters as $slug => $label ) {
			$i++;
			$url = admin_url( 'admin.php?page=' . self::SLUG . '&view=referrals' . ( $slug ? '&status=' . $slug : '' ) );

			echo '<li>';
			if ( $slug === $status ) {
				echo '<a class="current" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			} else {
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			if ( $i < $last ) {
				echo ' | ';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<div style="clear:both"></div>';

		$rows = CES_Referral::report(
			array(
				'status' => $status,
				'limit'  => $limit,
				'offset' => $offset,
			)
		);

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No referrals to show.', 'classic-edu-suite' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Referrer', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Invited', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Flag', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Signup IP', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$referrer_url = admin_url( 'admin.php?page=' . self::SLUG . '&view=users&user=' . (int) $row->referrer_id );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $referrer_url ) . '">' . esc_html( $row->referrer_name ) . '</a></td>';
			echo '<td>' . esc_html( $row->referred_name ) . '</td>';
			echo '<td>' . esc_html( CES_Wallet_Dashboard::status_label( $row->status ) );
			if ( (int) $row->reward_paid ) {
				echo ' <span class="description">' . esc_html__( '(paid)', 'classic-edu-suite' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $row->flag_label ) . '</td>';
			echo '<td><code>' . esc_html( $row->signup_ip ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row->created_at ) . '</td>';
			echo '<td>';
			self::render_referral_actions( $row );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Approve / reject buttons for one referral row.
	 *
	 * @param object $row Referral row.
	 * @return void
	 */
	private static function render_referral_actions( $row ) {
		$row_id = (int) $row->id;

		if ( ! (int) $row->reward_paid ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			wp_nonce_field( 'ces_wallet_referral_' . $row_id );
			echo '<input type="hidden" name="action" value="ces_wallet_referral" />';
			echo '<input type="hidden" name="row_id" value="' . esc_attr( $row_id ) . '" />';
			echo '<input type="hidden" name="do" value="approve" />';
			echo '<button type="submit" class="button button-primary button-small">' . esc_html__( 'Approve', 'classic-edu-suite' ) . '</button>';
			echo '</form> ';
		}

		if ( 'rejected' !== $row->status ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			wp_nonce_field( 'ces_wallet_referral_' . $row_id );
			echo '<input type="hidden" name="action" value="ces_wallet_referral" />';
			echo '<input type="hidden" name="row_id" value="' . esc_attr( $row_id ) . '" />';
			echo '<input type="hidden" name="do" value="reject" />';
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Reject', 'classic-edu-suite' ) . '</button>';
			echo '</form>';
		}
	}

	// -----------------------------------------------------------------
	// Write handlers.
	// -----------------------------------------------------------------

	/**
	 * Apply a manual credit or debit.
	 *
	 * @return void
	 */
	public static function handle_adjust() {
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		self::guard( 'ces_wallet_adjust_' . $user_id );

		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : 'credit';
		$amount    = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$note      = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

		if ( $user_id <= 0 || ! ctype_digit( (string) $amount ) || (int) $amount <= 0 ) {
			self::redirect_user( $user_id, 'badinput' );
		}

		if ( '' === $note ) {
			$note = __( 'Manual adjustment by an administrator', 'classic-edu-suite' );
		}

		$args = array(
			'ref_type' => 'admin',
			'ref_id'   => get_current_user_id(),
			'note'     => $note,
		);

		if ( 'debit' === $direction ) {
			$result = CES_Wallet::debit( $user_id, (int) $amount, 'admin_adjust', $args );
		} else {
			$result = CES_Wallet::credit( $user_id, (int) $amount, 'admin_adjust', $args );
		}

		if ( is_wp_error( $result ) ) {
			$code = 'ces_insufficient_funds' === $result->get_error_code() ? 'nofunds' : 'failed';
			self::redirect_user( $user_id, $code );
		}

		self::redirect_user( $user_id, 'debit' === $direction ? 'debited' : 'credited' );
	}

	/**
	 * Rebuild a cached balance from the ledger.
	 *
	 * @return void
	 */
	public static function handle_recalculate() {
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		self::guard( 'ces_wallet_recalc_' . $user_id );

		if ( $user_id <= 0 ) {
			self::redirect_user( 0, 'badinput' );
		}

		CES_Wallet::recalculate( $user_id );

		self::redirect_user( $user_id, 'recalced' );
	}

	/**
	 * Approve or reject one referral by hand.
	 *
	 * @return void
	 */
	public static function handle_referral() {
		$row_id = isset( $_POST['row_id'] ) ? absint( $_POST['row_id'] ) : 0;

		self::guard( 'ces_wallet_referral_' . $row_id );

		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		if ( $row_id <= 0 || ! in_array( $do, array( 'approve', 'reject' ), true ) ) {
			self::redirect_referrals( 'badinput' );
		}

		if ( 'reject' === $do ) {
			CES_Referral::reject( $row_id, 'manual' );
			self::redirect_referrals( 'rejected' );
		}

		$paid = CES_Referral::force_qualify( $row_id );

		self::redirect_referrals( $paid ? 'qualified' : 'failed' );
	}

	/**
	 * Capability and nonce check shared by every write handler.
	 *
	 * @param string $nonce_action Nonce action name.
	 * @return void
	 */
	private static function guard( $nonce_action ) {
		if ( ! CES_Helpers::can_manage( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'classic-edu-suite' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirect back to a student's wallet screen.
	 *
	 * @param int    $user_id User ID.
	 * @param string $message Message slug.
	 * @return void
	 */
	private static function redirect_user( $user_id, $message ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG . '&view=users' );

		if ( (int) $user_id > 0 ) {
			$url = add_query_arg( 'user', (int) $user_id, $url );
		}

		wp_safe_redirect( add_query_arg( 'ces_msg', $message, $url ) );
		exit;
	}

	/**
	 * Redirect back to the referral report.
	 *
	 * @param string $message Message slug.
	 * @return void
	 */
	private static function redirect_referrals( $message ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG . '&view=referrals' );

		wp_safe_redirect( add_query_arg( 'ces_msg', $message, $url ) );
		exit;
	}
}
