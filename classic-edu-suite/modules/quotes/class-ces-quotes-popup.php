<?php
/**
 * The daily quote popup: eligibility, assets and footer markup.
 *
 * Design rules this class exists to enforce:
 *   - It never fires for a viewer who has already seen today's quote, and the
 *     JS/CSS are not enqueued in that case either. A returning student on
 *     their fifth page view of the day pays nothing for this feature.
 *   - A dismissal cookie short-circuits the whole thing before any database
 *     query runs.
 *   - The markup is rendered hidden in the footer and revealed by JS after
 *     `quotes_delay_seconds`, so a slow network never delays the page and a
 *     visitor without JS simply never sees it.
 *   - Everything is namespaced `ces-` so it cannot collide with the Popup
 *     Builder plugin that already runs on this site.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end popup renderer.
 */
class CES_Quotes_Popup {

	/**
	 * Script and style handle.
	 */
	const HANDLE = 'ces-quotes-popup';

	/**
	 * Cookie remembering the last day the popup was shown or dismissed.
	 */
	const COOKIE = 'ces_quote_seen';

	/**
	 * Memoised eligibility decision for this request.
	 *
	 * @var bool|null
	 */
	private static $eligible = null;

	/**
	 * Memoised quote ID for this request.
	 *
	 * @var int|null
	 */
	private static $quote_id = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'maybe_render' ), 90 );
	}

	/**
	 * Whether the popup can fire on this request for this viewer.
	 *
	 * @return bool
	 */
	public static function should_show() {
		if ( null !== self::$eligible ) {
			return self::$eligible;
		}

		self::$eligible = self::evaluate();

		return self::$eligible;
	}

	/**
	 * Work out eligibility. Cheap checks first, database last.
	 *
	 * @return bool
	 */
	private static function evaluate() {
		if ( ! CES_Helpers::enabled( 'quotes_enabled' ) ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_preview() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id && ! CES_Helpers::enabled( 'quotes_show_to_guests' ) ) {
			return false;
		}

		/**
		 * Short-circuit the popup for specific pages or viewers.
		 *
		 * Return false to suppress it, for example on a checkout page or
		 * wherever Popup Builder already owns the screen.
		 *
		 * @param bool $show    Decision so far.
		 * @param int  $user_id Current user, 0 for a guest.
		 */
		if ( ! apply_filters( 'ces_quotes_should_show', true, $user_id ) ) {
			return false;
		}

		$daily = 'every_visit' !== CES_Quotes_Scheduler::frequency();

		// The cookie keeps a returning visitor out of the database entirely.
		if ( $daily && self::cookie_matches_today() ) {
			return false;
		}

		$visitor_hash = $user_id ? '' : CES_Helpers::visitor_hash();

		if ( $daily && CES_Quotes_Scheduler::shown_today( $user_id, $visitor_hash ) ) {
			return false;
		}

		return self::quote_id() > 0;
	}

	/**
	 * Whether the dismissal cookie is set to today.
	 *
	 * @return bool
	 */
	private static function cookie_matches_today() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}

		$value = CES_Quotes_Cpt::sanitize_date( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );

		return '' !== $value && $value === CES_Quotes_Scheduler::today();
	}

	/**
	 * The quote this viewer gets, memoised.
	 *
	 * @return int Post ID or 0.
	 */
	public static function quote_id() {
		if ( null === self::$quote_id ) {
			self::$quote_id = (int) CES_Quotes_Scheduler::quote_for_viewer();
		}

		return self::$quote_id;
	}

	/**
	 * Enqueue the popup assets, but only when it can actually fire.
	 *
	 * @return void
	 */
	public static function maybe_enqueue() {
		if ( ! self::should_show() ) {
			return;
		}

		if ( class_exists( 'CES_Assets' ) && method_exists( 'CES_Assets', 'enqueue_base' ) ) {
			CES_Assets::enqueue_base();
		}

		wp_enqueue_style(
			self::HANDLE,
			CES_URL . 'modules/quotes/assets/quotes-popup.css',
			array( 'ces-base' ),
			CES_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			CES_URL . 'modules/quotes/assets/quotes-popup.js',
			array( 'ces-base' ),
			CES_VERSION,
			true
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.CESQuotes = ' . wp_json_encode( self::script_config() ) . ';',
			'before'
		);
	}

	/**
	 * Configuration handed to the popup script.
	 *
	 * @return array
	 */
	public static function script_config() {
		$delay = CES_Helpers::setting_int( 'quotes_delay_seconds', 8 );
		$delay = max( 0, min( 300, $delay ) );

		return array(
			'quoteId'   => self::quote_id(),
			'delay'     => $delay * 1000,
			'frequency' => CES_Quotes_Scheduler::frequency(),
			'cookie'    => self::COOKIE,
			'today'     => CES_Quotes_Scheduler::today(),
			'endpoints' => array(
				'impression' => 'quotes/impression',
				'dismiss'    => 'quotes/dismiss',
				'cta'        => 'quotes/cta',
			),
		);
	}

	/**
	 * Print the hidden popup markup in the footer.
	 *
	 * @return void
	 */
	public static function maybe_render() {
		if ( ! self::should_show() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the template.
		echo self::markup( self::quote_id() );
	}

	/**
	 * Render the popup template for a given quote.
	 *
	 * Also used by the admin preview screen, which is why it takes the quote
	 * ID rather than reaching for the current viewer's.
	 *
	 * @param int  $quote_id Quote post ID.
	 * @param bool $preview  True to render it already open and inert.
	 * @return string Markup, '' when the quote is unusable.
	 */
	public static function markup( $quote_id, $preview = false ) {
		$quote = CES_Quotes_Cpt::to_array( (int) $quote_id );

		if ( empty( $quote['id'] ) || '' === $quote['text'] ) {
			return '';
		}

		$cta = CES_Quotes_Cta::data();

		return CES_Helpers::render(
			'quotes/popup.php',
			array(
				'quote'         => $quote,
				'cta'           => $cta,
				'heading'       => self::heading(),
				'is_preview'    => (bool) $preview,
				'dismiss_label' => __( 'Close', 'classic-edu-suite' ),
				'copy_label'    => __( 'Copy my link', 'classic-edu-suite' ),
				'copied_label'  => __( 'Copied', 'classic-edu-suite' ),
				'foot'          => self::foot_link(),
			)
		);
	}

	/**
	 * The small secondary link under the invite task.
	 *
	 * @return array{label:string,url:string}
	 */
	public static function foot_link() {
		if ( is_user_logged_in() ) {
			return array(
				'label' => __( 'Open my dashboard', 'classic-edu-suite' ),
				'url'   => CES_Helpers::dashboard_url(),
			);
		}

		return array(
			'label' => __( 'I already have an account', 'classic-edu-suite' ),
			'url'   => CES_Helpers::login_url( CES_Helpers::dashboard_url() ),
		);
	}

	/**
	 * Popup heading, filterable.
	 *
	 * @return string
	 */
	public static function heading() {
		/**
		 * Filter the popup heading.
		 *
		 * @param string $heading Heading text.
		 */
		return (string) apply_filters( 'ces_quotes_popup_heading', __( 'Today’s motivation', 'classic-edu-suite' ) );
	}
}
