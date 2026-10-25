<?php
/**
 * The student dashboard: `[ces_dashboard]`.
 *
 * This is the hub that ties the four modules together. It owns nothing but the
 * frame and the Overview tab; every other module contributes its own tab
 * through the `ces_dashboard_tabs` filter and never edits this file:
 *
 *     add_filter( 'ces_dashboard_tabs', function ( $tabs ) {
 *         $tabs[] = array(
 *             'slug'     => 'coins',
 *             'label'    => __( 'My Coins', 'classic-edu-suite' ),
 *             'icon'     => '🪙',
 *             'callback' => array( 'CES_Wallet_Dashboard', 'render' ),
 *             'position' => 10,
 *         );
 *         return $tabs;
 *     } );
 *
 * Contract:
 *   - `slug` is sanitised with `sanitize_key()` and becomes `?tab=<slug>`.
 *   - `label` is required and printed escaped.
 *   - `icon` is optional, printed escaped, and marked aria-hidden.
 *   - `callback` must be callable and echoes its own markup. It runs only for
 *     a logged-in viewer and only when its tab is the active one.
 *   - `position` sorts ascending; the Overview tab is 0, wallet 10, CBT 20,
 *     LMS 30, referrals 40. Ties keep registration order.
 *   - A tab missing a slug, label or callable callback is dropped silently.
 *   - The first registration of a slug wins; later duplicates are ignored.
 *
 * None of the other modules is assumed to exist.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the student dashboard.
 */
class CES_Dashboard {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'ces_dashboard';

	/**
	 * Overview tab slug.
	 */
	const OVERVIEW = 'overview';

	/**
	 * Memoised, normalised tab list.
	 *
	 * @var array|null
	 */
	private static $tabs = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_filter( 'ces_dashboard_tabs', array( __CLASS__, 'register_overview_tab' ), 5 );
	}

	/**
	 * Contribute the default Overview tab.
	 *
	 * @param array $tabs Tabs so far.
	 * @return array
	 */
	public static function register_overview_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}

		$tabs[] = array(
			'slug'     => self::OVERVIEW,
			'label'    => __( 'Overview', 'classic-edu-suite' ),
			'icon'     => '🏠',
			'callback' => array( __CLASS__, 'render_overview' ),
			'position' => 0,
		);

		return $tabs;
	}

	/**
	 * Collect, validate and sort the registered tabs.
	 *
	 * @return array[] Keyed by slug, in display order. Each entry has slug,
	 *                 label, icon, callback, position and url.
	 */
	public static function tabs() {
		if ( null !== self::$tabs ) {
			return self::$tabs;
		}

		/**
		 * Collect the student dashboard tabs.
		 *
		 * @param array $tabs Tab definitions.
		 */
		$raw = apply_filters( 'ces_dashboard_tabs', array() );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$clean = array();
		$order = 0;

		foreach ( $raw as $tab ) {
			if ( ! is_array( $tab ) ) {
				continue;
			}

			$slug  = isset( $tab['slug'] ) ? sanitize_key( (string) $tab['slug'] ) : '';
			$label = isset( $tab['label'] ) ? (string) $tab['label'] : '';

			if ( '' === $slug || '' === trim( $label ) ) {
				continue;
			}

			if ( isset( $clean[ $slug ] ) ) {
				continue;
			}

			if ( empty( $tab['callback'] ) || ! is_callable( $tab['callback'] ) ) {
				continue;
			}

			$clean[ $slug ] = array(
				'slug'     => $slug,
				'label'    => $label,
				'icon'     => isset( $tab['icon'] ) ? (string) $tab['icon'] : '',
				'callback' => $tab['callback'],
				'position' => isset( $tab['position'] ) ? (int) $tab['position'] : 50,
				'order'    => $order,
				'url'      => CES_Helpers::dashboard_url( $slug ),
			);

			$order++;
		}

		uasort( $clean, array( __CLASS__, 'compare_tabs' ) );

		self::$tabs = $clean;

		return self::$tabs;
	}

	/**
	 * Sort by position, then by registration order.
	 *
	 * @param array $a First tab.
	 * @param array $b Second tab.
	 * @return int
	 */
	public static function compare_tabs( $a, $b ) {
		if ( $a['position'] === $b['position'] ) {
			return $a['order'] <=> $b['order'];
		}

		return $a['position'] <=> $b['position'];
	}

	/**
	 * The requested tab slug, falling back to the first available tab.
	 *
	 * @param array $tabs Normalised tabs.
	 * @return string
	 */
	public static function active_slug( array $tabs ) {
		if ( empty( $tabs ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( '' !== $requested && isset( $tabs[ $requested ] ) ) {
			return $requested;
		}

		$slugs = array_keys( $tabs );

		return (string) $slugs[0];
	}

	/**
	 * `[ces_dashboard]`.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title' => '',
			),
			$atts,
			self::SHORTCODE
		);

		if ( class_exists( 'CES_Assets' ) && method_exists( 'CES_Assets', 'enqueue_base' ) ) {
			CES_Assets::enqueue_base();
		}

		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}

		$tabs = self::tabs();

		if ( empty( $tabs ) ) {
			return '<div class="ces-dash ces-dash--empty"><p>'
				. esc_html__( 'Your dashboard is not ready yet. Please check back shortly.', 'classic-edu-suite' )
				. '</p></div>';
		}

		$active = self::active_slug( $tabs );

		return CES_Helpers::render(
			'dashboard/wrapper.php',
			array(
				'tabs'      => $tabs,
				'active'    => $active,
				'content'   => self::render_tab( $tabs[ $active ] ),
				'title'     => (string) $atts['title'],
				'nav_label' => __( 'Dashboard sections', 'classic-edu-suite' ),
			)
		);
	}

	/**
	 * Run one tab's callback and capture its output.
	 *
	 * A module that throws must not take the whole dashboard down with it.
	 *
	 * @param array $tab Normalised tab.
	 * @return string
	 */
	public static function render_tab( array $tab ) {
		ob_start();

		try {
			call_user_func( $tab['callback'], $tab );
		} catch ( Throwable $e ) {
			ob_end_clean();

			CES_Helpers::log( 'Dashboard tab failed: ' . $tab['slug'], $e->getMessage() );

			return '<div class="ces-notice ces-notice--error"><p>'
				. esc_html__( 'This section could not be loaded. Please try again later.', 'classic-edu-suite' )
				. '</p></div>';
		}

		return (string) ob_get_clean();
	}

	/**
	 * The logged-out prompt.
	 *
	 * @return string
	 */
	public static function login_prompt() {
		$here = CES_Helpers::dashboard_url();

		return CES_Helpers::render(
			'dashboard/login-prompt.php',
			array(
				'login_url' => CES_Helpers::login_url( $here ),
				'heading'   => __( 'Sign in to your dashboard', 'classic-edu-suite' ),
				'message'   => __( 'Your coins, quiz results, courses and referral link all live here. Signing in takes a second.', 'classic-edu-suite' ),
				'bullets'   => array(
					__( 'Practise past questions and see your scores', 'classic-edu-suite' ),
					__( 'Earn coins by inviting friends, then unlock syllabus videos', 'classic-edu-suite' ),
					__( 'Pick up every course exactly where you stopped', 'classic-edu-suite' ),
				),
				'cta_label' => __( 'Sign in or create a free account', 'classic-edu-suite' ),
			)
		);
	}

	// -----------------------------------------------------------------
	// Overview tab
	// -----------------------------------------------------------------

	/**
	 * Render the Overview tab. Echoes its own markup, like every other tab.
	 *
	 * @return void
	 */
	public static function render_overview() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the template.
		echo CES_Helpers::render( 'dashboard/overview.php', self::overview_data() );
	}

	/**
	 * Everything the Overview template needs, resolved here so the template
	 * stays free of logic and of cross-module guards.
	 *
	 * @return array
	 */
	public static function overview_data() {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		$name    = $user ? $user->display_name : '';

		if ( '' === trim( (string) $name ) ) {
			$name = __( 'there', 'classic-edu-suite' );
		}

		return array(
			'greeting'     => sprintf(
				/* translators: %s: student's display name. */
				__( 'Welcome back, %s', 'classic-edu-suite' ),
				$name
			),
			'intro'        => __( 'Here is where you stand today.', 'classic-edu-suite' ),
			'wallet'       => self::wallet_card( $user_id ),
			'quote'        => self::quote_card(),
			'quick_links'  => self::quick_links(),
			'invite'       => self::invite_card( $user_id ),
			'links_label'  => __( 'Jump to', 'classic-edu-suite' ),
			'quote_label'  => __( 'Today’s motivation', 'classic-edu-suite' ),
			'invite_label' => __( 'Invite a friend', 'classic-edu-suite' ),
		);
	}

	/**
	 * Coin balance card, or null when the wallet module is absent.
	 *
	 * @param int $user_id Student.
	 * @return array|null
	 */
	public static function wallet_card( $user_id ) {
		if ( ! class_exists( 'CES_Wallet' ) || ! method_exists( 'CES_Wallet', 'balance' ) ) {
			return null;
		}

		$balance = CES_Wallet::balance( (int) $user_id );

		if ( ! is_numeric( $balance ) ) {
			return null;
		}

		$card = array(
			'label'  => __( 'Coin balance', 'classic-edu-suite' ),
			'value'  => CES_Helpers::format_coins( (int) $balance ),
			'hint'   => __( 'Spend coins to unlock syllabus videos.', 'classic-edu-suite' ),
			'url'    => '',
			'action' => '',
		);

		$tabs = self::tabs();

		if ( isset( $tabs['coins'] ) ) {
			$card['url']    = $tabs['coins']['url'];
			$card['action'] = __( 'See my coins', 'classic-edu-suite' );
		}

		return $card;
	}

	/**
	 * Today's quote for the Overview tab, or null when there is none.
	 *
	 * @return array|null
	 */
	public static function quote_card() {
		if ( ! class_exists( 'CES_Quotes_Scheduler' ) || ! class_exists( 'CES_Quotes_Cpt' ) ) {
			return null;
		}

		$quote_id = (int) CES_Quotes_Scheduler::quote_of_the_day();

		if ( $quote_id <= 0 ) {
			return null;
		}

		$quote = CES_Quotes_Cpt::to_array( $quote_id );

		if ( empty( $quote['id'] ) || '' === $quote['text'] ) {
			return null;
		}

		return array(
			'text'   => $quote['text'],
			'author' => $quote['author'],
		);
	}

	/**
	 * Quick links to every other tab.
	 *
	 * @return array[] Each entry: label, url, icon.
	 */
	public static function quick_links() {
		$links = array();

		foreach ( self::tabs() as $slug => $tab ) {
			if ( self::OVERVIEW === $slug ) {
				continue;
			}

			$links[] = array(
				'label' => $tab['label'],
				'url'   => $tab['url'],
				'icon'  => $tab['icon'],
			);
		}

		/**
		 * Filter the Overview tab's quick links.
		 *
		 * @param array[] $links Each entry: label, url, icon.
		 */
		$links = apply_filters( 'ces_dashboard_quick_links', $links );

		$clean = array();

		foreach ( (array) $links as $link ) {
			if ( empty( $link['label'] ) || empty( $link['url'] ) ) {
				continue;
			}

			$clean[] = array(
				'label' => (string) $link['label'],
				'url'   => (string) $link['url'],
				'icon'  => isset( $link['icon'] ) ? (string) $link['icon'] : '',
			);
		}

		return $clean;
	}

	/**
	 * The invite-a-friend prompt.
	 *
	 * @param int $user_id Student.
	 * @return array
	 */
	public static function invite_card( $user_id ) {
		if ( class_exists( 'CES_Quotes_Cta' ) && method_exists( 'CES_Quotes_Cta', 'data' ) ) {
			$cta = CES_Quotes_Cta::data( (int) $user_id );

			return array(
				'text'         => isset( $cta['text'] ) ? $cta['text'] : '',
				'link'         => isset( $cta['link'] ) ? $cta['link'] : '',
				'button_label' => isset( $cta['button_label'] ) ? $cta['button_label'] : '',
				'whatsapp_url' => isset( $cta['whatsapp_url'] ) ? $cta['whatsapp_url'] : '',
				'copy_link'    => isset( $cta['copy_link'] ) ? $cta['copy_link'] : '',
				'copy_label'   => __( 'Copy my link', 'classic-edu-suite' ),
				'copied_label' => __( 'Copied', 'classic-edu-suite' ),
			);
		}

		return array(
			'text'         => __( 'Invite a friend and earn coins towards your next video.', 'classic-edu-suite' ),
			'link'         => CES_Helpers::dashboard_url( 'referrals' ),
			'button_label' => __( 'Invite friends', 'classic-edu-suite' ),
			'whatsapp_url' => '',
			'copy_link'    => '',
			'copy_label'   => __( 'Copy my link', 'classic-edu-suite' ),
			'copied_label' => __( 'Copied', 'classic-edu-suite' ),
		);
	}
}
