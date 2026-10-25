<?php
/**
 * Student dashboard tabs contributed by the wallet module.
 *
 * Two tabs are registered through the `ces_dashboard_tabs` filter:
 * "My Coins" at position 10 and "Invite Friends" at position 40. Both render
 * phone-first markup; the desktop layout is the same markup with more columns.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard tab renderers.
 */
class CES_Wallet_Dashboard {

	/**
	 * Rows of history shown before the "load more" button.
	 */
	const PAGE_SIZE = 20;

	/**
	 * Register the wallet's dashboard tabs.
	 *
	 * @param array $tabs Tabs collected so far.
	 * @return array
	 */
	public static function tabs( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}

		$tabs[] = array(
			'slug'     => 'coins',
			'label'    => __( 'My Coins', 'classic-edu-suite' ),
			'icon'     => '🪙',
			'callback' => array( 'CES_Wallet_Dashboard', 'render' ),
			'position' => 10,
		);

		$tabs[] = array(
			'slug'     => 'referrals',
			'label'    => __( 'Invite Friends', 'classic-edu-suite' ),
			'icon'     => '🤝',
			'callback' => array( 'CES_Wallet_Dashboard', 'render_invite' ),
			'position' => 40,
		);

		return $tabs;
	}

	/**
	 * "My Coins" tab: balance, streak, how to earn, transaction history.
	 *
	 * @return void
	 */
	public static function render() {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			self::render_login_prompt();
			return;
		}

		CES_Wallet_Module::enqueue_assets();

		$balance = CES_Wallet::balance( $user_id );
		$streak  = CES_Rewards::streak( $user_id );
		$history = CES_Wallet::history( $user_id, self::PAGE_SIZE, 0 );
		$total   = CES_Wallet::history_count( $user_id );
		$methods = CES_Rewards::earning_methods();

		echo '<div class="ces-wallet ces-wallet--coins">';

		// ---------- Balance hero ----------
		echo '<div class="ces-coin-hero">';
		echo '<span class="ces-coin-hero__label">' . esc_html__( 'Your coin balance', 'classic-edu-suite' ) . '</span>';
		echo '<span class="ces-coin-hero__value" data-ces-balance>' . esc_html( CES_Helpers::format_coins( $balance ) ) . '</span>';
		echo '<span class="ces-coin-hero__unit">' . esc_html( _n( 'coin', 'coins', $balance, 'classic-edu-suite' ) ) . '</span>';
		echo '</div>';

		// ---------- Streak ----------
		echo '<div class="ces-streak">';
		echo '<p class="ces-streak__line">';
		if ( $streak['days'] > 0 ) {
			printf(
				/* translators: %s: number of consecutive days. */
				esc_html__( 'Login streak: %s days in a row.', 'classic-edu-suite' ),
				'<strong>' . esc_html( CES_Helpers::format_coins( $streak['days'] ) ) . '</strong>'
			);
			echo ' ';
			printf(
				/* translators: 1: days remaining, 2: bonus coin amount. */
				esc_html__( '%1$s more to earn a %2$s coin bonus.', 'classic-edu-suite' ),
				esc_html( CES_Helpers::format_coins( $streak['remaining'] ) ),
				esc_html( CES_Helpers::format_coins( CES_Helpers::setting_int( 'coins_streak_bonus', 10 ) ) )
			);
		} else {
			esc_html_e( 'Log in every day to start a streak and earn bonus coins.', 'classic-edu-suite' );
		}
		echo '</p>';

		$pct = $streak['target'] > 0
			? (int) round( ( ( $streak['target'] - $streak['remaining'] ) / $streak['target'] ) * 100 )
			: 0;

		echo '<div class="ces-progress" role="img" aria-label="' . esc_attr(
			sprintf(
				/* translators: %d: percentage complete. */
				__( 'Streak progress: %d%%', 'classic-edu-suite' ),
				$pct
			)
		) . '">';
		echo '<span class="ces-progress__bar" style="width:' . esc_attr( max( 0, min( 100, $pct ) ) ) . '%"></span>';
		echo '</div>';
		echo '</div>';

		// ---------- How to earn ----------
		if ( ! empty( $methods ) ) {
			echo '<h3 class="ces-wallet__heading">' . esc_html__( 'How to earn coins', 'classic-edu-suite' ) . '</h3>';
			echo '<ul class="ces-earn-list">';
			foreach ( $methods as $method ) {
				$label = isset( $method['label'] ) ? (string) $method['label'] : '';
				$coins = isset( $method['coins'] ) ? (int) $method['coins'] : 0;

				echo '<li class="ces-earn-list__item">';
				echo '<span class="ces-earn-list__label">' . esc_html( $label ) . '</span>';
				echo '<span class="ces-earn-list__coins">+' . esc_html( CES_Helpers::format_coins( $coins ) ) . '</span>';
				echo '</li>';
			}
			echo '</ul>';

			echo '<p class="ces-wallet__cta"><a class="ces-btn ces-btn--primary" href="' . esc_url( CES_Helpers::dashboard_url( 'referrals' ) ) . '">';
			esc_html_e( 'Invite a friend', 'classic-edu-suite' );
			echo '</a></p>';
		}

		// ---------- History ----------
		echo '<h3 class="ces-wallet__heading">' . esc_html__( 'Transaction history', 'classic-edu-suite' ) . '</h3>';

		if ( empty( $history ) ) {
			echo '<p class="ces-empty">' . esc_html__( 'No coin activity yet. Take a quiz or invite a friend to get started.', 'classic-edu-suite' ) . '</p>';
		} else {
			echo '<ul class="ces-ledger" data-ces-ledger>';
			foreach ( $history as $row ) {
				self::render_ledger_row( $row );
			}
			echo '</ul>';

			if ( $total > count( $history ) ) {
				echo '<p class="ces-wallet__more">';
				echo '<button type="button" class="ces-btn ces-btn--ghost" data-ces-load-more="2" data-ces-per-page="' . esc_attr( self::PAGE_SIZE ) . '">';
				esc_html_e( 'Load more', 'classic-edu-suite' );
				echo '</button>';
				echo '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * "Invite Friends" tab: code, link, WhatsApp share, milestone progress and
	 * the list of invited students.
	 *
	 * @return void
	 */
	public static function render_invite() {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			self::render_login_prompt();
			return;
		}

		CES_Wallet_Module::enqueue_assets();

		$stats     = CES_Referral::stats( $user_id );
		$referrals = CES_Referral::list_for_referrer( $user_id, 50, 0 );

		echo '<div class="ces-wallet ces-wallet--invite">';

		echo '<p class="ces-invite__intro">';
		printf(
			/* translators: %s: coins per referral. */
			esc_html__( 'Earn %s coins every time a friend joins with your link, confirms their account and finishes one quiz.', 'classic-edu-suite' ),
			'<strong>' . esc_html( CES_Helpers::format_coins( $stats['coins_per_referral'] ) ) . '</strong>'
		);
		echo '</p>';

		// ---------- Code ----------
		echo '<div class="ces-invite__code-box">';
		echo '<span class="ces-invite__code-label">' . esc_html__( 'Your referral code', 'classic-edu-suite' ) . '</span>';
		echo '<span class="ces-invite__code">' . esc_html( $stats['code'] ) . '</span>';
		echo '</div>';

		// ---------- Link ----------
		echo '<div class="ces-invite__link-row">';
		echo '<input type="text" class="ces-invite__link" readonly value="' . esc_attr( $stats['link'] ) . '" aria-label="' . esc_attr__( 'Your referral link', 'classic-edu-suite' ) . '" data-ces-link />';
		echo '<button type="button" class="ces-btn ces-btn--ghost ces-invite__copy" data-ces-copy="' . esc_attr( $stats['link'] ) . '">';
		esc_html_e( 'Copy', 'classic-edu-suite' );
		echo '</button>';
		echo '</div>';

		if ( $stats['whatsapp'] ) {
			echo '<p class="ces-invite__share">';
			echo '<a class="ces-btn ces-btn--whatsapp" href="' . esc_url( $stats['whatsapp'] ) . '" target="_blank" rel="noopener noreferrer">';
			esc_html_e( 'Share on WhatsApp', 'classic-edu-suite' );
			echo '</a>';
			echo '</p>';
		}

		// ---------- Numbers ----------
		echo '<div class="ces-invite__stats">';
		self::render_stat( __( 'Qualified', 'classic-edu-suite' ), $stats['qualified'] );
		self::render_stat( __( 'Pending', 'classic-edu-suite' ), $stats['pending'] );
		self::render_stat( __( 'Coins earned', 'classic-edu-suite' ), $stats['coins_earned'] );
		echo '</div>';

		// ---------- Milestone progress ----------
		if ( $stats['milestone_every'] > 0 && $stats['milestone_bonus'] > 0 ) {
			$done      = $stats['milestone_every'] - $stats['to_next_milestone'];
			$pct       = (int) round( ( $done / $stats['milestone_every'] ) * 100 );

			echo '<div class="ces-milestone">';
			echo '<p class="ces-milestone__line">';
			printf(
				/* translators: 1: invites remaining, 2: bonus coins. */
				esc_html__( '%1$s more qualified invites to unlock a %2$s coin milestone bonus.', 'classic-edu-suite' ),
				'<strong>' . esc_html( CES_Helpers::format_coins( $stats['to_next_milestone'] ) ) . '</strong>',
				esc_html( CES_Helpers::format_coins( $stats['milestone_bonus'] ) )
			);
			echo '</p>';
			echo '<div class="ces-progress" role="img" aria-label="' . esc_attr(
				sprintf(
					/* translators: %d: percentage complete. */
					__( 'Milestone progress: %d%%', 'classic-edu-suite' ),
					$pct
				)
			) . '">';
			echo '<span class="ces-progress__bar" style="width:' . esc_attr( max( 0, min( 100, $pct ) ) ) . '%"></span>';
			echo '</div>';
			echo '</div>';
		}

		// ---------- Referral list ----------
		echo '<h3 class="ces-wallet__heading">' . esc_html__( 'People you invited', 'classic-edu-suite' ) . '</h3>';

		if ( empty( $referrals ) ) {
			echo '<p class="ces-empty">' . esc_html__( 'Nobody has joined with your link yet. Share it on WhatsApp to get started.', 'classic-edu-suite' ) . '</p>';
		} else {
			echo '<ul class="ces-referral-list">';
			foreach ( $referrals as $row ) {
				$status = isset( $row['status'] ) ? (string) $row['status'] : 'pending';

				echo '<li class="ces-referral-list__item">';
				echo '<span class="ces-referral-list__name">' . esc_html( $row['name'] ) . '</span>';
				echo '<span class="ces-referral-list__status ces-status ces-status--' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';

				if ( 'pending' === $status ) {
					echo '<span class="ces-referral-list__hint">' . esc_html__( 'Waiting for them to confirm and take one quiz.', 'classic-edu-suite' ) . '</span>';
				} elseif ( 'rejected' === $status && ! empty( $row['reason'] ) ) {
					echo '<span class="ces-referral-list__hint">' . esc_html( $row['reason'] ) . '</span>';
				}

				echo '</li>';
			}
			echo '</ul>';
		}

		echo '<p class="ces-invite__smallprint">' . esc_html__( 'Invites from duplicate accounts, the same device or temporary email addresses are not paid.', 'classic-edu-suite' ) . '</p>';

		echo '</div>';
	}

	/**
	 * Render one ledger list item.
	 *
	 * @param array $row Ledger row from CES_Wallet::history().
	 * @return void
	 */
	private static function render_ledger_row( array $row ) {
		$delta = isset( $row['delta'] ) ? (int) $row['delta'] : 0;
		$sign  = $delta >= 0 ? '+' : '−';
		$class = $delta >= 0 ? 'ces-ledger__amount--in' : 'ces-ledger__amount--out';

		echo '<li class="ces-ledger__item">';
		echo '<span class="ces-ledger__meta">';
		echo '<span class="ces-ledger__label">' . esc_html( isset( $row['label'] ) ? $row['label'] : '' ) . '</span>';

		if ( ! empty( $row['note'] ) ) {
			echo '<span class="ces-ledger__note">' . esc_html( $row['note'] ) . '</span>';
		}

		echo '<span class="ces-ledger__date">' . esc_html( self::format_date( isset( $row['created_at'] ) ? $row['created_at'] : '' ) ) . '</span>';
		echo '</span>';
		echo '<span class="ces-ledger__amount ' . esc_attr( $class ) . '">' . esc_html( $sign . CES_Helpers::format_coins( abs( $delta ) ) ) . '</span>';
		echo '</li>';
	}

	/**
	 * Render one stat tile.
	 *
	 * @param string $label Tile label.
	 * @param int    $value Tile value.
	 * @return void
	 */
	private static function render_stat( $label, $value ) {
		echo '<div class="ces-stat">';
		echo '<span class="ces-stat__value">' . esc_html( CES_Helpers::format_coins( (int) $value ) ) . '</span>';
		echo '<span class="ces-stat__label">' . esc_html( $label ) . '</span>';
		echo '</div>';
	}

	/**
	 * Translated label for a referral status.
	 *
	 * @param string $status pending | qualified | rejected.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'pending'   => __( 'Pending', 'classic-edu-suite' ),
			'qualified' => __( 'Earned', 'classic-edu-suite' ),
			'rejected'  => __( 'Not eligible', 'classic-edu-suite' ),
		);

		$status = (string) $status;

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
	}

	/**
	 * Format a MySQL datetime for the student-facing lists.
	 *
	 * @param string $datetime MySQL datetime.
	 * @return string
	 */
	public static function format_date( $datetime ) {
		$datetime  = (string) $datetime;
		$timestamp = $datetime ? strtotime( $datetime ) : 0;

		if ( ! $timestamp ) {
			return '';
		}

		return date_i18n( (string) get_option( 'date_format', 'j M Y' ), $timestamp );
	}

	/**
	 * Prompt a logged-out visitor to log in.
	 *
	 * Belt and braces: the dashboard only runs tab callbacks for logged-in
	 * users, but the shortcodes reuse these renderers.
	 *
	 * @return void
	 */
	private static function render_login_prompt() {
		echo '<p class="ces-empty">';
		echo '<a href="' . esc_url( CES_Helpers::login_url( CES_Helpers::dashboard_url( 'coins' ) ) ) . '">';
		esc_html_e( 'Log in to see your coins.', 'classic-edu-suite' );
		echo '</a></p>';
	}
}
