<?php
/**
 * Signing a student in, and the button that starts it.
 *
 * Three jobs:
 *
 *   1. Turn a validated Google profile into a logged-in WordPress user —
 *      matching an existing account, linking one by its verified email, or
 *      creating a new one.
 *   2. Put the "Continue with Gmail" button in front of students, on
 *      `wp-login.php`, on the WooCommerce login form, on whichever page the
 *      `login_page_url` setting points at (which on this site is Profile
 *      Builder's), and through the `[ces_google_login]` shortcode.
 *   3. Send a student who has not finished onboarding to the right place
 *      after any login, password or Gmail.
 *
 * The role a new account gets comes from the `accounts_default_role` setting,
 * and a role that can `manage_options` is refused outright: a public sign-in
 * button must never be able to mint an administrator.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Account creation, linking and the sign-in button.
 */
class CES_Accounts_Login {

	/**
	 * Google's permanent account identifier (`sub`).
	 */
	const META_GOOGLE_ID = 'ces_google_id';

	/**
	 * Google profile picture URL.
	 */
	const META_AVATAR = 'ces_google_avatar';

	/**
	 * When the Google account was linked.
	 */
	const META_LINKED_AT = 'ces_google_linked_at';

	/**
	 * Shortcode printing the button.
	 */
	const SHORTCODE = 'ces_google_login';

	/**
	 * Guard so the button is injected into page content only once.
	 *
	 * @var bool
	 */
	private static $injected = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );

		if ( CES_Helpers::enabled( 'accounts_inject_login_button' ) ) {
			add_action( 'login_form', array( __CLASS__, 'print_button' ), 20 );
			add_action( 'woocommerce_login_form_start', array( __CLASS__, 'print_button' ) );
			add_filter( 'the_content', array( __CLASS__, 'inject_into_login_page' ), 9 );
			add_filter( 'do_shortcode_tag', array( __CLASS__, 'add_button_to_gate' ), 20, 2 );
		}

		add_filter( 'login_message', array( __CLASS__, 'login_message' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'after_login_redirect' ), 20, 3 );

		// Password sign-in can be closed off for accounts that came in
		// through Google, so a weak password cannot be the way round the gate.
		add_filter( 'authenticate', array( __CLASS__, 'maybe_block_password' ), 30, 3 );

		if ( CES_Helpers::enabled( 'accounts_google_avatar' ) ) {
			add_filter( 'pre_get_avatar_data', array( __CLASS__, 'google_avatar' ), 10, 2 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Signing in.
	 * ------------------------------------------------------------------ */

	/**
	 * Log a student in from a validated Google profile.
	 *
	 * @param array  $profile     Validated profile from CES_Accounts_Google.
	 * @param string $redirect_to Where the student was heading.
	 * @return string|WP_Error URL to send the browser to.
	 */
	public static function sign_in_google( array $profile, $redirect_to = '' ) {
		$email = isset( $profile['email'] ) ? sanitize_email( (string) $profile['email'] ) : '';
		$sub   = isset( $profile['sub'] ) ? (string) $profile['sub'] : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'no_email', __( 'That Google account has no usable email address.', 'classic-edu-suite' ) );
		}

		$user_id  = 0;
		$is_fresh = false;

		// 1. An account already linked to this exact Google account. Matched
		// on `sub` rather than on the email, because a Gmail address can be
		// renamed and `sub` never changes.
		if ( '' !== $sub ) {
			$found = get_users(
				array(
					'meta_key'   => self::META_GOOGLE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $sub,                 // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'number'     => 1,
					'fields'     => 'ids',
				)
			);

			if ( ! empty( $found ) ) {
				$user_id = (int) $found[0];
			}
		}

		// 2. An account on the same email address. Safe to claim because
		// Google has verified that the person signing in owns it.
		if ( ! $user_id ) {
			$existing = get_user_by( 'email', $email );

			if ( $existing ) {
				if ( ! CES_Helpers::enabled( 'accounts_google_link_existing' ) ) {
					return new WP_Error( 'no_link', __( 'An account already uses that email address. Sign in with your password instead.', 'classic-edu-suite' ) );
				}

				$user_id = (int) $existing->ID;

				CES_Accounts_Status::log(
					$user_id,
					'google_linked',
					array(
						'actor_id' => $user_id,
						'channel'  => 'google',
						'detail'   => $email,
					)
				);
			}
		}

		// 3. Nobody. Make one.
		if ( ! $user_id ) {
			// Deliberately independent of WordPress's own "anyone can
			// register" switch: a school can keep the normal registration
			// form closed and still let students in through Gmail, which is
			// the combination most of them actually want.
			if ( ! CES_Helpers::enabled( 'accounts_google_create_users' ) ) {
				return new WP_Error( 'no_signup', __( 'There is no account for that Gmail address yet, and new accounts are closed.', 'classic-edu-suite' ) );
			}

			$user_id = self::create_user( $profile );

			if ( is_wp_error( $user_id ) ) {
				CES_Helpers::log( 'Google account creation failed', $user_id->get_error_message() );

				return new WP_Error( 'create_failed', __( 'We could not create your account. Please try again or contact us.', 'classic-edu-suite' ) );
			}

			$is_fresh = true;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'create_failed', __( 'We could not open your account. Please try again.', 'classic-edu-suite' ) );
		}

		self::store_profile( $user_id, $profile );

		/**
		 * Filter whether this account may sign in at all.
		 *
		 * The approval gate does not use this: a declined student is still
		 * signed in so the site can tell them why and who to speak to. This
		 * is for a hard block, such as a banned account.
		 *
		 * @param bool    $allowed Decision so far.
		 * @param WP_User $user    Account signing in.
		 * @param array   $profile Google profile.
		 */
		$allowed = (bool) apply_filters( 'ces_accounts_allow_login', true, $user, $profile );

		if ( ! $allowed ) {
			return new WP_Error( 'blocked', __( 'That account cannot sign in. Please contact the school.', 'classic-edu-suite' ) );
		}

		// Sign in. `remember` is deliberate: these are students on their own
		// phones, and being thrown out weekly is a reason to stop coming back.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		CES_Accounts_Status::log(
			$user_id,
			$is_fresh ? 'signup_google' : 'login_google',
			array(
				'actor_id' => $user_id,
				'channel'  => 'google',
			)
		);

		// `user_register` has normally set this already, through
		// CES_Accounts_Module::on_user_register. This covers the case where
		// something unhooked that, and never overwrites a status on file.
		if ( $is_fresh && '' === CES_Accounts_Status::raw_status( $user_id ) ) {
			CES_Accounts_Status::set_status(
				$user_id,
				'onboarding',
				array(
					'actor_id' => $user_id,
					'channel'  => 'google',
					'event'    => 'signup',
				)
			);
		}

		return self::destination( $user_id, $redirect_to );
	}

	/**
	 * Create a WordPress account from a Google profile.
	 *
	 * @param array $profile Validated profile.
	 * @return int|WP_Error New user ID.
	 */
	private static function create_user( array $profile ) {
		$email = sanitize_email( (string) $profile['email'] );
		$login = self::unique_login( $email, isset( $profile['given_name'] ) ? (string) $profile['given_name'] : '' );

		$name = isset( $profile['name'] ) ? trim( (string) $profile['name'] ) : '';

		if ( '' === $name ) {
			$first = isset( $profile['given_name'] ) ? trim( (string) $profile['given_name'] ) : '';
			$last  = isset( $profile['family_name'] ) ? trim( (string) $profile['family_name'] ) : '';
			$name  = trim( $first . ' ' . $last );
		}

		if ( '' === $name ) {
			$name = $login;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'    => $login,
				'user_email'    => $email,
				'user_pass'     => wp_generate_password( 32, true, true ),
				'display_name'  => $name,
				'nickname'      => $name,
				'first_name'    => isset( $profile['given_name'] ) ? (string) $profile['given_name'] : '',
				'last_name'     => isset( $profile['family_name'] ) ? (string) $profile['family_name'] : '',
				'role'          => self::default_role(),
				'user_nicename' => sanitize_title( $login ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// WordPress emails the site admin about a new registration; the
		// student gets nothing, because there is no password to send.
		if ( function_exists( 'wp_new_user_notification' ) ) {
			wp_new_user_notification( $user_id, null, 'admin' );
		}

		return (int) $user_id;
	}

	/**
	 * The role new accounts are given.
	 *
	 * A role that can `manage_options` is refused: this is a public button.
	 *
	 * @return string Role slug.
	 */
	public static function default_role() {
		$wanted   = sanitize_key( (string) CES_Helpers::setting( 'accounts_default_role', 'subscriber' ) );
		$fallback = sanitize_key( (string) get_option( 'default_role', 'subscriber' ) );

		if ( '' === $fallback ) {
			$fallback = 'subscriber';
		}

		$roles = wp_roles();

		if ( ! $roles || ! isset( $roles->roles[ $wanted ] ) ) {
			return $fallback;
		}

		$caps = isset( $roles->roles[ $wanted ]['capabilities'] ) ? (array) $roles->roles[ $wanted ]['capabilities'] : array();

		foreach ( array( 'manage_options', 'promote_users', 'edit_users', 'install_plugins' ) as $dangerous ) {
			if ( ! empty( $caps[ $dangerous ] ) ) {
				CES_Helpers::log( 'Refused to create a privileged account from a Gmail sign-in', $wanted );

				return $fallback;
			}
		}

		return $wanted;
	}

	/**
	 * A free username based on the email address.
	 *
	 * @param string $email Email address.
	 * @param string $hint  Optional first name to fall back on.
	 * @return string
	 */
	private static function unique_login( $email, $hint = '' ) {
		$base = strstr( (string) $email, '@', true );
		$base = str_replace( array( '.', '+' ), '', (string) $base );
		$base = sanitize_user( $base, true );
		$base = strtolower( $base );

		if ( strlen( $base ) < 3 ) {
			$base = strtolower( sanitize_user( (string) $hint, true ) );
		}

		if ( strlen( $base ) < 3 ) {
			$base = 'student';
		}

		$base  = substr( $base, 0, 40 );
		$login = $base;
		$n     = 1;

		while ( username_exists( $login ) ) {
			++$n;
			$login = $base . $n;

			// Pathological case: a site with thousands of near-identical
			// names. Switch to something certainly free rather than looping.
			if ( $n > 200 ) {
				$login = $base . '-' . wp_generate_password( 6, false, false );
				break;
			}
		}

		return $login;
	}

	/**
	 * Keep the Google identifiers and picture on the account.
	 *
	 * @param int   $user_id User.
	 * @param array $profile Validated profile.
	 * @return void
	 */
	private static function store_profile( $user_id, array $profile ) {
		$user_id = absint( $user_id );

		if ( ! empty( $profile['sub'] ) ) {
			update_user_meta( $user_id, self::META_GOOGLE_ID, sanitize_text_field( (string) $profile['sub'] ) );
		}

		if ( ! empty( $profile['picture'] ) ) {
			update_user_meta( $user_id, self::META_AVATAR, esc_url_raw( (string) $profile['picture'] ) );
		}

		if ( ! get_user_meta( $user_id, self::META_LINKED_AT, true ) ) {
			update_user_meta( $user_id, self::META_LINKED_AT, CES_Helpers::now() );
		}

		update_user_meta( $user_id, CES_Accounts_Status::META_PROVIDER, 'google' );

		// Fill in a blank name, but never overwrite one the student has set
		// themselves — some use a nickname deliberately.
		$user = get_userdata( $user_id );

		if ( $user && '' === trim( (string) $user->first_name ) && ! empty( $profile['given_name'] ) ) {
			update_user_meta( $user_id, 'first_name', sanitize_text_field( (string) $profile['given_name'] ) );
		}

		if ( $user && '' === trim( (string) $user->last_name ) && ! empty( $profile['family_name'] ) ) {
			update_user_meta( $user_id, 'last_name', sanitize_text_field( (string) $profile['family_name'] ) );
		}
	}

	/**
	 * Where to send a student once they are signed in.
	 *
	 * @param int    $user_id     Student.
	 * @param string $redirect_to Where they were heading.
	 * @return string
	 */
	public static function destination( $user_id, $redirect_to = '' ) {
		if ( CES_Accounts_Status::approval_enabled() && ! CES_Accounts_Status::is_approved( $user_id ) && ! CES_Accounts_Status::in_grace( $user_id ) ) {
			return CES_Helpers::dashboard_url( CES_Accounts_Dashboard::SLUG );
		}

		$redirect_to = trim( (string) $redirect_to );

		return '' !== $redirect_to ? $redirect_to : CES_Helpers::dashboard_url();
	}

	/**
	 * Send an unapproved student to the approval tab after any login.
	 *
	 * @param string  $redirect_to Requested destination.
	 * @param string  $requested   Raw requested destination.
	 * @param WP_User $user        User logging in, or WP_Error.
	 * @return string
	 */
	public static function after_login_redirect( $redirect_to, $requested, $user ) {
		unset( $requested );

		if ( ! ( $user instanceof WP_User ) ) {
			return $redirect_to;
		}

		if ( ! CES_Accounts_Status::approval_enabled() || CES_Accounts_Status::is_staff( $user->ID ) ) {
			return $redirect_to;
		}

		if ( CES_Accounts_Status::is_approved( $user->ID ) || CES_Accounts_Status::in_grace( $user->ID ) ) {
			return $redirect_to;
		}

		return CES_Helpers::dashboard_url( CES_Accounts_Dashboard::SLUG );
	}

	/**
	 * Refuse a password login for a Google-created student, when asked to.
	 *
	 * @param null|WP_User|WP_Error $user     Result so far.
	 * @param string                $username Username or email being tried.
	 * @param string                $password Password being tried.
	 * @return null|WP_User|WP_Error
	 */
	public static function maybe_block_password( $user, $username, $password ) {
		unset( $username, $password );

		if ( ! CES_Helpers::enabled( 'accounts_google_only' ) || ! CES_Accounts_Google::enabled() ) {
			return $user;
		}

		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		// Staff always keep their password. Locking an administrator out of
		// wp-login.php because Google is misconfigured is unrecoverable.
		if ( CES_Accounts_Status::is_staff( $user->ID ) ) {
			return $user;
		}

		if ( 'google' !== (string) get_user_meta( $user->ID, CES_Accounts_Status::META_PROVIDER, true ) ) {
			return $user;
		}

		return new WP_Error(
			'ces_use_google',
			sprintf(
				/* translators: %s: the sign-in button label. */
				__( 'This account signs in with Gmail. Please use the “%s” button.', 'classic-edu-suite' ),
				self::button_label()
			)
		);
	}

	/**
	 * Use the Google profile picture as the avatar.
	 *
	 * @param array|null $args        Avatar data so far.
	 * @param mixed      $id_or_email User identifier of some kind.
	 * @return array|null
	 */
	public static function google_avatar( $args, $id_or_email ) {
		$user_id = 0;

		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( $id_or_email instanceof WP_User ) {
			$user_id = (int) $id_or_email->ID;
		} elseif ( $id_or_email instanceof WP_Post ) {
			$user_id = (int) $id_or_email->post_author;
		} elseif ( $id_or_email instanceof WP_Comment && ! empty( $id_or_email->user_id ) ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$found   = get_user_by( 'email', $id_or_email );
			$user_id = $found ? (int) $found->ID : 0;
		}

		if ( ! $user_id ) {
			return $args;
		}

		$url = (string) get_user_meta( $user_id, self::META_AVATAR, true );

		if ( '' === $url ) {
			return $args;
		}

		if ( ! is_array( $args ) ) {
			$args = array();
		}

		$args['url']          = $url;
		$args['found_avatar'] = true;

		return $args;
	}

	/* ---------------------------------------------------------------------
	 * The button.
	 * ------------------------------------------------------------------ */

	/**
	 * The button label from the settings.
	 *
	 * @return string
	 */
	public static function button_label() {
		$label = trim( (string) CES_Helpers::setting( 'accounts_button_label', 'Continue with Gmail' ) );

		return '' !== $label ? $label : __( 'Continue with Gmail', 'classic-edu-suite' );
	}

	/**
	 * Google's four-colour "G", inline so there is no request to Google
	 * before the student has chosen to sign in.
	 *
	 * @return string SVG markup.
	 */
	public static function google_mark() {
		return '<svg class="ces-google-btn__mark" width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false">'
			. '<path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.91c1.7-1.57 2.69-3.88 2.69-6.62z"/>'
			. '<path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.91-2.26c-.81.54-1.84.86-3.05.86-2.34 0-4.32-1.58-5.03-3.71H.96v2.33A9 9 0 0 0 9 18z"/>'
			. '<path fill="#FBBC05" d="M3.97 10.71A5.41 5.41 0 0 1 3.68 9c0-.6.1-1.17.29-1.71V4.96H.96A9 9 0 0 0 0 9c0 1.45.35 2.82.96 4.04l3.01-2.33z"/>'
			. '<path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.59C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.96l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>'
			. '</svg>';
	}

	/**
	 * The button markup.
	 *
	 * @param array $args Optional. `label`, `redirect_to`, `note`, `block`.
	 * @return string Markup, or '' when Gmail sign-in is unavailable.
	 */
	public static function button_html( array $args = array() ) {
		if ( ! CES_Accounts_Google::enabled() ) {
			return '';
		}

		$args = array_merge(
			array(
				'label'       => self::button_label(),
				'redirect_to' => '',
				'note'        => '',
				'block'       => true,
			),
			$args
		);

		if ( class_exists( 'CES_Accounts_Module' ) ) {
			CES_Accounts_Module::enqueue_style();
		}

		$url = CES_Accounts_Google::start_url( (string) $args['redirect_to'] );

		$classes = 'ces-google-btn';

		if ( ! empty( $args['block'] ) ) {
			$classes .= ' ces-google-btn--block';
		}

		$html  = '<div class="ces-google-wrap">';
		$html .= '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '" rel="nofollow">';
		$html .= self::google_mark();
		$html .= '<span class="ces-google-btn__label">' . esc_html( $args['label'] ) . '</span>';
		$html .= '</a>';

		if ( '' !== trim( (string) $args['note'] ) ) {
			$html .= '<p class="ces-google-wrap__note">' . esc_html( $args['note'] ) . '</p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Echo the button, for action hooks.
	 *
	 * @return void
	 */
	public static function print_button() {
		$html = self::button_html(
			array(
				'note' => __( 'No password to remember. We only ever see your name, your email address and your picture.', 'classic-edu-suite' ),
			)
		);

		if ( '' === $html ) {
			return;
		}

		// On wp-login.php our stylesheet is not enqueued, so the button
		// carries just enough inline style to look deliberate.
		if ( did_action( 'login_form' ) && ! did_action( 'wp_head' ) ) {
			$html = '<style>.ces-google-wrap{margin:0 0 16px}.ces-google-btn{display:flex;align-items:center;justify-content:center;gap:10px;'
				. 'padding:11px 16px;border:1px solid #747775;border-radius:999px;background:#fff;color:#1f1f1f;font-weight:600;'
				. 'text-decoration:none;font-size:14px}.ces-google-btn:hover{background:#f7f8f8}.ces-google-wrap__note{font-size:11px;'
				. 'color:#50575e;margin:8px 0 0;line-height:1.5}</style>' . $html;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in button_html().
		echo $html;
	}

	/**
	 * `[ces_google_login]`.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'label'       => self::button_label(),
				'redirect_to' => '',
				'note'        => '',
			),
			$atts,
			self::SHORTCODE
		);

		if ( is_user_logged_in() ) {
			return '';
		}

		if ( ! CES_Accounts_Google::enabled() ) {
			return '';
		}

		return self::error_notice_html() . self::button_html(
			array(
				'label'       => (string) $atts['label'],
				'redirect_to' => (string) $atts['redirect_to'],
				'note'        => (string) $atts['note'],
			)
		);
	}

	/**
	 * Prepend the button to the configured login page's content.
	 *
	 * This is how the button reaches Profile Builder's login form, which
	 * renders its own markup and offers us no hook of its own.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function inject_into_login_page( $content ) {
		if ( self::$injected || is_admin() || is_user_logged_in() ) {
			return $content;
		}

		if ( ! is_main_query() || ! in_the_loop() || ! is_singular() ) {
			return $content;
		}

		if ( ! self::is_login_page() ) {
			return $content;
		}

		$button = self::button_html(
			array(
				'note' => __( 'No password to remember. We only ever see your name, your email address and your picture.', 'classic-edu-suite' ),
			)
		);

		if ( '' === $button ) {
			return $content;
		}

		self::$injected = true;

		return self::error_notice_html() . $button . '<p class="ces-google-wrap__or"><span>' . esc_html__( 'or', 'classic-edu-suite' ) . '</span></p>' . $content;
	}

	/**
	 * Put the button inside the shared `[ces_login_required]` gate.
	 *
	 * Done through the core `do_shortcode_tag` filter rather than by editing
	 * the shared public layer, so the two modules stay independent.
	 *
	 * @param string $output Rendered shortcode output.
	 * @param string $tag    Shortcode tag.
	 * @return string
	 */
	public static function add_button_to_gate( $output, $tag ) {
		if ( 'ces_login_required' !== $tag || is_user_logged_in() ) {
			return $output;
		}

		// The gate renders nothing for a signed-in visitor's content; only
		// the prompt carries this wrapper.
		if ( false === strpos( (string) $output, 'ces-gate__action' ) ) {
			return $output;
		}

		$button = self::button_html();

		if ( '' === $button ) {
			return $output;
		}

		return str_replace( '<p class="ces-gate__action">', $button . '<p class="ces-gate__action">', (string) $output );
	}

	/**
	 * Whether the page being viewed is the login page.
	 *
	 * @return bool
	 */
	public static function is_login_page() {
		$configured = (string) CES_Helpers::setting( 'login_page_url', '/login/' );
		$path       = trim( (string) wp_parse_url( $configured, PHP_URL_PATH ), '/' );
		$post       = get_post();

		if ( ! $post ) {
			return false;
		}

		if ( '' !== $path && $post->post_name === basename( $path ) ) {
			return true;
		}

		// A page that renders a known login form is a login page too.
		if ( '' !== (string) $post->post_content ) {
			foreach ( array( 'wppb-login', 'woocommerce_my_account', 'ces_google_login' ) as $tag ) {
				if ( has_shortcode( $post->post_content, $tag ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Failure messages.
	 * ------------------------------------------------------------------ */

	/**
	 * The notice markup for a `?ces_auth_error=` on the login page.
	 *
	 * @return string
	 */
	public static function error_notice_html() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display-only flag on a GET.
		$slug = isset( $_GET['ces_auth_error'] ) ? sanitize_key( wp_unslash( $_GET['ces_auth_error'] ) ) : '';

		if ( '' === $slug ) {
			return '';
		}

		$message = self::error_message( $slug );

		if ( '' === $message ) {
			return '';
		}

		$class = 'cancelled' === $slug ? 'ces-notice' : 'ces-notice ces-notice--error';

		return '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Look up the message for a failure slug.
	 *
	 * @param string $slug Failure slug.
	 * @return string
	 */
	public static function error_message( $slug ) {
		$detail = get_transient( 'ces_auth_msg_' . substr( CES_Helpers::visitor_hash(), 0, 20 ) );

		if ( is_string( $detail ) && '' !== $detail ) {
			delete_transient( 'ces_auth_msg_' . substr( CES_Helpers::visitor_hash(), 0, 20 ) );

			return $detail;
		}

		$messages = CES_Accounts_Google::error_messages();

		return isset( $messages[ $slug ] ) ? $messages[ $slug ] : __( 'Sign-in could not be completed. Please try again.', 'classic-edu-suite' );
	}

	/**
	 * Show the same message on wp-login.php.
	 *
	 * @param string $message Message markup so far.
	 * @return string
	 */
	public static function login_message( $message ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display-only flag on a GET.
		$slug = isset( $_GET['ces_auth_error'] ) ? sanitize_key( wp_unslash( $_GET['ces_auth_error'] ) ) : '';

		if ( '' === $slug ) {
			return $message;
		}

		$text = self::error_message( $slug );

		if ( '' === $text ) {
			return $message;
		}

		return $message . '<div id="login_error">' . esc_html( $text ) . '</div>';
	}
}
