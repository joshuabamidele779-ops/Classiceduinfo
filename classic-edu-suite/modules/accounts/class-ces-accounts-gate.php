<?php
/**
 * Keeping an unapproved student out, without breaking anything.
 *
 * The gate is deliberately built entirely out of filters the other modules
 * already publish, so no file outside this module is touched and the whole
 * thing can be switched off with one setting:
 *
 *   - `rest_pre_dispatch` refuses the REST calls that start an exam, open a
 *     study session or unlock a video.
 *   - `do_shortcode_tag` swaps the output of the shortcodes that serve
 *     content for an explanation of what is missing.
 *   - `parse_request` refuses the signed video stream endpoint.
 *   - `ces_dashboard_tabs` hides every tab except Overview, the approval tab
 *     and the Parent / Guardian tab, so there is one obvious thing to do.
 *
 * Three strengths, set by `accounts_gate_level`:
 *
 *   none      Nothing is blocked. The status is recorded and shown, and that
 *             is all — useful for a term's grace period while a school gets
 *             its parent list together.
 *   practice  The default. Browsing stays open — a student can see the exams,
 *             the courses and the leaderboards, which is the best argument
 *             for finishing the signup — but nothing can be started.
 *   full      Every one of this plugin's screens is closed.
 *
 * Staff are never gated, whatever the setting.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Blocks content for accounts that are not approved.
 */
class CES_Accounts_Gate {

	/**
	 * Route prefixes closed at the `practice` level, for writes only.
	 *
	 * @var string[]
	 */
	private static $practice_routes = array( '/cbt/', '/hub/', '/lms/', '/wallet/' );

	/**
	 * Shortcodes closed at the `practice` level.
	 *
	 * @var string[]
	 */
	private static $practice_shortcodes = array(
		'ces_exam',
		'ces_study',
		'ces_study_hub',
		'ces_lesson',
		'ces_course',
		'ces_past_questions',
		'ces_resource_library',
		'ces_my_courses',
	);

	/**
	 * Shortcodes additionally closed at the `full` level.
	 *
	 * @var string[]
	 */
	private static $full_shortcodes = array(
		'ces_exam_list',
		'ces_course_list',
		'ces_cbt_results',
		'ces_cbt_leaderboard',
		'ces_leaderboard',
		'ces_coin_balance',
		'ces_referral_link',
		'ces_referral_stats',
		'ces_career_guide',
		'ces_school_finder',
		'ces_department_guide',
		'ces_guidance',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'gate_rest' ), 9, 3 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'gate_shortcode' ), 10, 2 );
		add_action( 'parse_request', array( __CLASS__, 'gate_stream' ), -1 );
		add_filter( 'ces_dashboard_tabs', array( __CLASS__, 'gate_tabs' ), 99 );
	}

	/* ---------------------------------------------------------------------
	 * The decision.
	 * ------------------------------------------------------------------ */

	/**
	 * The configured strength.
	 *
	 * @return string none | practice | full
	 */
	public static function level() {
		$level = sanitize_key( (string) CES_Helpers::setting( 'accounts_gate_level', 'practice' ) );

		return in_array( $level, array( 'none', 'practice', 'full' ), true ) ? $level : 'practice';
	}

	/**
	 * Whether the viewer should be held back.
	 *
	 * @param int $user_id Optional user, defaults to the current one.
	 * @return bool
	 */
	public static function blocked( $user_id = 0 ) {
		if ( ! CES_Accounts_Status::approval_enabled() || 'none' === self::level() ) {
			return false;
		}

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		// A visitor who is not signed in is handled by each module's own
		// login prompt, which is a better message than ours.
		if ( ! $user_id ) {
			return false;
		}

		if ( CES_Accounts_Status::is_staff( $user_id ) ) {
			return false;
		}

		if ( CES_Accounts_Status::is_approved( $user_id ) ) {
			return false;
		}

		if ( CES_Accounts_Status::in_grace( $user_id ) ) {
			return false;
		}

		/**
		 * Filter the gate decision for one account.
		 *
		 * @param bool $blocked Decision so far.
		 * @param int  $user_id Account being checked.
		 */
		return (bool) apply_filters( 'ces_accounts_blocked', true, $user_id );
	}

	/* ---------------------------------------------------------------------
	 * REST.
	 * ------------------------------------------------------------------ */

	/**
	 * Refuse the REST calls that would start something.
	 *
	 * @param mixed           $result  Response to replace the request with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return mixed
	 */
	public static function gate_rest( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result ) {
			return $result;
		}

		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}

		$route = (string) $request->get_route();

		if ( 0 !== strpos( $route, '/' . CES_REST_NAMESPACE . '/' ) ) {
			return $result;
		}

		if ( ! self::blocked() ) {
			return $result;
		}

		$relative = substr( $route, strlen( '/' . CES_REST_NAMESPACE ) );
		$method   = strtoupper( (string) $request->get_method() );
		$writing  = in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );
		$level    = self::level();

		$hit = false;

		foreach ( self::$practice_routes as $prefix ) {
			if ( 0 === strpos( $relative, $prefix ) ) {
				$hit = ( 'full' === $level ) || $writing;
				break;
			}
		}

		if ( ! $hit ) {
			return $result;
		}

		return new WP_Error(
			'ces_account_not_approved',
			self::reason_text(),
			array(
				'status' => 403,
				'action' => CES_Helpers::dashboard_url( CES_Accounts_Dashboard::SLUG ),
				'state'  => CES_Accounts_Status::status(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Shortcodes.
	 * ------------------------------------------------------------------ */

	/**
	 * Swap a gated shortcode's output for the explanation.
	 *
	 * @param string $output Rendered shortcode output.
	 * @param string $tag    Shortcode tag.
	 * @return string
	 */
	public static function gate_shortcode( $output, $tag ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $output;
		}

		$tag = (string) $tag;

		if ( 0 !== strpos( $tag, 'ces_' ) ) {
			return $output;
		}

		$gated = self::$practice_shortcodes;

		if ( 'full' === self::level() ) {
			$gated = array_merge( $gated, self::$full_shortcodes );
		}

		/**
		 * Filter which shortcodes the approval gate closes.
		 *
		 * @param string[] $gated Shortcode tags.
		 * @param string   $level Gate level in force.
		 */
		$gated = (array) apply_filters( 'ces_accounts_gated_shortcodes', $gated, self::level() );

		if ( ! in_array( $tag, $gated, true ) ) {
			return $output;
		}

		if ( ! self::blocked() ) {
			return $output;
		}

		return self::notice_html();
	}

	/* ---------------------------------------------------------------------
	 * Video stream.
	 * ------------------------------------------------------------------ */

	/**
	 * Refuse the signed stream endpoint for an unapproved account.
	 *
	 * Runs before the LMS module's own handler, which sits on the same hook
	 * at priority 0.
	 *
	 * @param WP $wp Current request.
	 * @return void
	 */
	public static function gate_stream( $wp ) {
		if ( ! isset( $wp->query_vars['ces_stream'] ) || empty( $wp->query_vars['ces_stream'] ) ) {
			return;
		}

		if ( 'none' === self::level() || ! self::blocked() ) {
			return;
		}

		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );

		echo wp_json_encode(
			array(
				'code'    => 'ces_account_not_approved',
				'message' => self::reason_text(),
			)
		);

		exit;
	}

	/* ---------------------------------------------------------------------
	 * Dashboard.
	 * ------------------------------------------------------------------ */

	/**
	 * Hide the tabs an unapproved student cannot use.
	 *
	 * @param array $tabs Tabs so far.
	 * @return array
	 */
	public static function gate_tabs( $tabs ) {
		if ( ! is_array( $tabs ) || ! self::blocked() ) {
			return $tabs;
		}

		$keep = array( CES_Dashboard::OVERVIEW, CES_Accounts_Dashboard::SLUG, 'parent' );

		/**
		 * Filter the dashboard tabs an unapproved student may still open.
		 *
		 * @param string[] $keep Tab slugs.
		 */
		$keep = (array) apply_filters( 'ces_accounts_allowed_tabs', $keep );

		$out = array();

		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) || empty( $tab['slug'] ) ) {
				continue;
			}

			if ( in_array( sanitize_key( (string) $tab['slug'] ), $keep, true ) ) {
				$out[] = $tab;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * What the student is told.
	 * ------------------------------------------------------------------ */

	/**
	 * One sentence explaining the block, matched to where they have got to.
	 *
	 * @param int $user_id Optional user.
	 * @return string
	 */
	public static function reason_text( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$status  = CES_Accounts_Status::status( $user_id );

		switch ( $status ) {
			case 'awaiting_parent':
				return __( 'Your parent or guardian has not confirmed your account yet, so practice is still locked.', 'classic-edu-suite' );

			case 'awaiting_admin':
				return __( 'Your parent has confirmed you — a member of staff is checking the account now.', 'classic-edu-suite' );

			case 'declined':
				return __( 'This account was not approved, so practice is closed.', 'classic-edu-suite' );

			case 'suspended':
				return __( 'This account has been paused. Please contact the school.', 'classic-edu-suite' );

			default:
				return __( 'Add your parent or guardian’s details to unlock practice. It takes a minute.', 'classic-edu-suite' );
		}
	}

	/**
	 * The locked panel shown in place of gated content.
	 *
	 * @return string
	 */
	public static function notice_html() {
		if ( class_exists( 'CES_Accounts_Module' ) ) {
			CES_Accounts_Module::enqueue_style();
		}

		$user_id = get_current_user_id();
		$status  = CES_Accounts_Status::status( $user_id );
		$url     = CES_Helpers::dashboard_url( CES_Accounts_Dashboard::SLUG );

		$cta = 'onboarding' === $status
			? __( 'Add my parent’s details', 'classic-edu-suite' )
			: __( 'See what is left to do', 'classic-edu-suite' );

		$html  = '<div class="ces-gate">';
		$html .= '<span class="ces-gate__icon" aria-hidden="true">🔒</span>';
		$html .= '<h3 class="ces-gate__title">' . esc_html__( 'Almost there', 'classic-edu-suite' ) . '</h3>';
		$html .= '<p class="ces-gate__text">' . esc_html( self::reason_text( $user_id ) ) . '</p>';

		if ( 'declined' !== $status && 'suspended' !== $status ) {
			$html .= '<p class="ces-gate__why">' . esc_html__( 'We ask every student for a parent or guardian, and we ask that parent to confirm it is really them. It keeps this a place for students and their families only.', 'classic-edu-suite' ) . '</p>';
		}

		$html .= '<p class="ces-gate__action"><a class="ces-btn ces-btn--primary" href="' . esc_url( $url ) . '">' . esc_html( $cta ) . '</a></p>';
		$html .= '</div>';

		return $html;
	}
}
