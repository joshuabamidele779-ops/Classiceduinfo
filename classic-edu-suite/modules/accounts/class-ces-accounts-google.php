<?php
/**
 * Google sign-in: the OAuth 2.0 authorization-code flow.
 *
 * Students sign in with the Gmail account they already have on their phone,
 * so there is no password to forget and the email address is verified by
 * Google before it ever reaches us. That last point is what makes the rest of
 * this module possible: the address we message is known to be real.
 *
 * The flow, in full:
 *
 *   1. The student presses "Continue with Gmail". We mint a random `state`
 *      and a PKCE `code_verifier`, keep both in a short-lived transient keyed
 *      by the state, and send the browser to Google.
 *   2. Google sends the browser back to `?ces_oauth=google` with a one-time
 *      code. We match the state, then exchange the code for tokens over a
 *      server-to-server HTTPS call that carries the client secret.
 *   3. The `id_token` that comes back is read for the profile. Because it
 *      arrived directly from Google's token endpoint over TLS, and not via
 *      the browser, its signature does not need to be re-checked — but the
 *      audience, issuer, expiry and `email_verified` claim all do, and are.
 *
 * Nothing here trusts a value that came through the browser except the
 * opaque code and the state it is matched against. The client secret is read
 * from the settings on the server and never printed.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Google OAuth endpoints and the two request handlers.
 */
class CES_Accounts_Google {

	/**
	 * Query argument that marks one of our OAuth requests.
	 */
	const QUERY_FLAG = 'ces_oauth';

	/**
	 * Google's authorization endpoint.
	 */
	const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * Google's token endpoint.
	 */
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/**
	 * Fallback profile endpoint, used only when no id_token comes back.
	 */
	const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

	/**
	 * Accepted `iss` claim values.
	 *
	 * @var string[]
	 */
	private static $issuers = array( 'https://accounts.google.com', 'accounts.google.com' );

	/**
	 * Transient prefix holding one pending flow.
	 */
	const STATE_PREFIX = 'ces_oauth_';

	/**
	 * Cookie that binds a pending flow to this browser.
	 */
	const STATE_COOKIE = 'ces_oauth_state';

	/**
	 * How long a started flow stays valid, in seconds.
	 */
	const STATE_TTL = 900;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Early enough that nothing has printed, late enough that the current
		// user is resolved.
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 1 );
	}

	/**
	 * Whether a client ID and secret are both on file.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) CES_Helpers::setting( 'accounts_google_client_id', '' ) )
			&& '' !== trim( (string) CES_Helpers::setting( 'accounts_google_client_secret', '' ) );
	}

	/**
	 * Whether Gmail sign-in is switched on and usable.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return CES_Helpers::enabled( 'accounts_google_enabled' ) && self::is_configured();
	}

	/**
	 * The redirect URI, which must be registered in the Google console
	 * character for character.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return add_query_arg( self::QUERY_FLAG, 'google', home_url( '/' ) );
	}

	/**
	 * The URL the "Continue with Gmail" button points at.
	 *
	 * @param string $redirect_to Where to send the student afterwards.
	 * @return string
	 */
	public static function start_url( $redirect_to = '' ) {
		$args = array(
			self::QUERY_FLAG => 'start',
		);

		if ( '' !== trim( (string) $redirect_to ) ) {
			// add_query_arg() encodes the value, so hand it the plain URL.
			$args['redirect_to'] = $redirect_to;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Route an incoming OAuth request.
	 *
	 * @return void
	 */
	public static function maybe_handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the `state` parameter is the CSRF token for this flow, checked in handle_callback().
		if ( empty( $_GET[ self::QUERY_FLAG ] ) ) {
			return;
		}

		$flag = sanitize_key( wp_unslash( $_GET[ self::QUERY_FLAG ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'start' === $flag ) {
			self::handle_start();
			return;
		}

		if ( 'google' === $flag ) {
			self::handle_callback();
		}
	}

	/* ---------------------------------------------------------------------
	 * Step 1: send the student to Google.
	 * ------------------------------------------------------------------ */

	/**
	 * Begin a flow and redirect to Google.
	 *
	 * @return void
	 */
	public static function handle_start() {
		if ( ! self::enabled() ) {
			self::bail( 'disabled' );
		}

		if ( is_user_logged_in() ) {
			wp_safe_redirect( self::intended_url() );
			exit;
		}

		if ( ! CES_Helpers::rate_limit( 'oauth_start', 12, 5 * MINUTE_IN_SECONDS ) ) {
			self::bail( 'throttled' );
		}

		$state    = wp_generate_password( 32, false, false );
		$verifier = wp_generate_password( 64, false, false );

		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'verifier'    => $verifier,
				'redirect_to' => self::intended_url(),
				'created'     => time(),
				'ip'          => CES_Helpers::ip(),
			),
			self::STATE_TTL
		);

		// A second, independent check that the browser finishing the flow is
		// the one that started it. Not load-bearing: the state transient is
		// already unguessable, and a student on a browser that refuses
		// cookies can still sign in.
		setcookie(
			self::STATE_COOKIE,
			$state,
			time() + self::STATE_TTL,
			defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
			defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			is_ssl(),
			true
		);

		$url = add_query_arg(
			array(
				'client_id'             => trim( (string) CES_Helpers::setting( 'accounts_google_client_id', '' ) ),
				'redirect_uri'          => self::redirect_uri(),
				'response_type'         => 'code',
				'scope'                 => 'openid email profile',
				'state'                 => $state,
				'code_challenge'        => self::challenge( $verifier ),
				'code_challenge_method' => 'S256',
				'access_type'           => 'online',
				'prompt'                => 'select_account',
			),
			self::AUTH_ENDPOINT
		);

		// Not a same-site URL, so wp_redirect() rather than wp_safe_redirect().
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Step 2: Google sends the browser back.
	 * ------------------------------------------------------------------ */

	/**
	 * Finish a flow: validate, exchange, sign in.
	 *
	 * @return void
	 */
	public static function handle_callback() {
		if ( ! self::enabled() ) {
			self::bail( 'disabled' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- `state` is this flow's CSRF token and is validated below.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $state || ! preg_match( '/^[A-Za-z0-9]{16,64}$/', $state ) ) {
			self::bail( 'state' );
		}

		$pending = get_transient( self::STATE_PREFIX . $state );

		// One use only, whatever happens next.
		delete_transient( self::STATE_PREFIX . $state );

		if ( ! is_array( $pending ) || empty( $pending['verifier'] ) ) {
			self::bail( 'expired' );
		}

		if ( ! empty( $_COOKIE[ self::STATE_COOKIE ] ) ) {
			$cookie = sanitize_text_field( wp_unslash( $_COOKIE[ self::STATE_COOKIE ] ) );

			if ( ! hash_equals( $state, $cookie ) ) {
				self::bail( 'state' );
			}
		}

		self::clear_state_cookie();

		$redirect_to = isset( $pending['redirect_to'] ) ? (string) $pending['redirect_to'] : '';

		if ( '' !== $error ) {
			// The student pressed "cancel" on Google's screen. Not a fault.
			self::bail( 'access_denied' === $error ? 'cancelled' : 'provider', $redirect_to );
		}

		if ( '' === $code ) {
			self::bail( 'no_code', $redirect_to );
		}

		$tokens = self::exchange_code( $code, (string) $pending['verifier'] );

		if ( is_wp_error( $tokens ) ) {
			CES_Helpers::log( 'Google token exchange failed', $tokens->get_error_message() );
			self::bail( 'exchange', $redirect_to );
		}

		$profile = self::profile_from_tokens( $tokens );

		if ( is_wp_error( $profile ) ) {
			CES_Helpers::log( 'Google profile rejected', $profile->get_error_message() );
			self::bail( $profile->get_error_code(), $redirect_to );
		}

		$result = CES_Accounts_Login::sign_in_google( $profile, $redirect_to );

		if ( is_wp_error( $result ) ) {
			self::bail( $result->get_error_code(), $redirect_to, $result->get_error_message() );
		}

		wp_safe_redirect( $result );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Talking to Google.
	 * ------------------------------------------------------------------ */

	/**
	 * Swap the one-time code for tokens.
	 *
	 * @param string $code     Authorization code from the callback.
	 * @param string $verifier PKCE code verifier kept from step 1.
	 * @return array|WP_Error Decoded token response.
	 */
	private static function exchange_code( $code, $verifier ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'code'          => $code,
					'client_id'     => trim( (string) CES_Helpers::setting( 'accounts_google_client_id', '' ) ),
					'client_secret' => trim( (string) CES_Helpers::setting( 'accounts_google_client_secret', '' ) ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
					'code_verifier' => $verifier,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ces_oauth_transport', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			$detail = is_array( $body ) && isset( $body['error_description'] )
				? (string) $body['error_description']
				: 'HTTP ' . $status;

			return new WP_Error( 'ces_oauth_rejected', $detail );
		}

		return $body;
	}

	/**
	 * Turn a token response into a validated profile.
	 *
	 * @param array $tokens Token response from Google.
	 * @return array|WP_Error {
	 *     @type string $sub        Google's permanent account ID.
	 *     @type string $email
	 *     @type string $name
	 *     @type string $given_name
	 *     @type string $family_name
	 *     @type string $picture
	 *     @type string $domain     Email domain, lower case.
	 * }
	 */
	private static function profile_from_tokens( array $tokens ) {
		$claims = array();

		if ( ! empty( $tokens['id_token'] ) ) {
			$claims = self::read_id_token( (string) $tokens['id_token'] );

			if ( is_wp_error( $claims ) ) {
				return $claims;
			}
		} elseif ( ! empty( $tokens['access_token'] ) ) {
			$claims = self::fetch_userinfo( (string) $tokens['access_token'] );

			if ( is_wp_error( $claims ) ) {
				return $claims;
			}
		} else {
			return new WP_Error( 'no_token', __( 'Google did not return a usable sign-in token.', 'classic-edu-suite' ) );
		}

		$email = isset( $claims['email'] ) ? sanitize_email( (string) $claims['email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'no_email', __( 'That Google account has no usable email address.', 'classic-edu-suite' ) );
		}

		// Google tells us whether it has verified the address. An unverified
		// one is worthless to us, because every message we send later — and
		// every account recovery — depends on it being real.
		$verified = isset( $claims['email_verified'] ) ? $claims['email_verified'] : false;

		if ( ! $verified || 'false' === $verified ) {
			return new WP_Error( 'unverified', __( 'Google has not verified that email address yet.', 'classic-edu-suite' ) );
		}

		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );

		$allowed = self::domain_allowed( $domain );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return array(
			'sub'         => isset( $claims['sub'] ) ? sanitize_text_field( (string) $claims['sub'] ) : '',
			'email'       => $email,
			'domain'      => $domain,
			'name'        => isset( $claims['name'] ) ? sanitize_text_field( (string) $claims['name'] ) : '',
			'given_name'  => isset( $claims['given_name'] ) ? sanitize_text_field( (string) $claims['given_name'] ) : '',
			'family_name' => isset( $claims['family_name'] ) ? sanitize_text_field( (string) $claims['family_name'] ) : '',
			'picture'     => isset( $claims['picture'] ) ? esc_url_raw( (string) $claims['picture'] ) : '',
		);
	}

	/**
	 * Read and check an id_token's claims.
	 *
	 * The token came straight from Google's token endpoint over TLS, which is
	 * what makes reading it without re-verifying the RS256 signature safe —
	 * Google's own documentation says as much for this leg of the flow. The
	 * claims that matter are still checked here.
	 *
	 * @param string $id_token Compact JWT.
	 * @return array|WP_Error Claims.
	 */
	private static function read_id_token( $id_token ) {
		$parts = explode( '.', $id_token );

		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'bad_token', __( 'Google returned a sign-in token we could not read.', 'classic-edu-suite' ) );
		}

		$claims = json_decode( (string) self::base64url_decode( $parts[1] ), true );

		if ( ! is_array( $claims ) ) {
			return new WP_Error( 'bad_token', __( 'Google returned a sign-in token we could not read.', 'classic-edu-suite' ) );
		}

		$client_id = trim( (string) CES_Helpers::setting( 'accounts_google_client_id', '' ) );
		$audience  = isset( $claims['aud'] ) ? (string) $claims['aud'] : '';

		if ( '' === $audience || ! hash_equals( $client_id, $audience ) ) {
			return new WP_Error( 'bad_audience', __( 'That sign-in was issued for a different website.', 'classic-edu-suite' ) );
		}

		$issuer = isset( $claims['iss'] ) ? (string) $claims['iss'] : '';

		if ( ! in_array( $issuer, self::$issuers, true ) ) {
			return new WP_Error( 'bad_issuer', __( 'That sign-in did not come from Google.', 'classic-edu-suite' ) );
		}

		$expiry = isset( $claims['exp'] ) ? (int) $claims['exp'] : 0;

		// 60 seconds of slack for a server clock that drifts, which on shared
		// hosting is normal.
		if ( $expiry && $expiry < ( time() - 60 ) ) {
			return new WP_Error( 'expired_token', __( 'That sign-in took too long and expired. Please try again.', 'classic-edu-suite' ) );
		}

		return $claims;
	}

	/**
	 * Fall back to the userinfo endpoint when there is no id_token.
	 *
	 * @param string $access_token Bearer token.
	 * @return array|WP_Error Claims.
	 */
	private static function fetch_userinfo( $access_token ) {
		$response = wp_remote_get(
			self::USERINFO_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'userinfo', $response->get_error_message() );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'userinfo', __( 'Google did not return a profile.', 'classic-edu-suite' ) );
		}

		return $body;
	}

	/**
	 * Whether an email domain is allowed to sign in.
	 *
	 * @param string $domain Lower-cased domain.
	 * @return true|WP_Error
	 */
	public static function domain_allowed( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );

		if ( CES_Helpers::enabled( 'accounts_require_gmail' ) ) {
			if ( ! in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
				return new WP_Error(
					'not_gmail',
					__( 'Please sign in with a Gmail address — the one ending in @gmail.com.', 'classic-edu-suite' )
				);
			}

			return true;
		}

		$list = self::allowed_domains();

		if ( empty( $list ) ) {
			return true;
		}

		if ( ! in_array( $domain, $list, true ) ) {
			return new WP_Error(
				'domain_blocked',
				sprintf(
					/* translators: %s: comma separated list of email domains. */
					__( 'Only accounts from %s can sign in here.', 'classic-edu-suite' ),
					implode( ', ', $list )
				)
			);
		}

		return true;
	}

	/**
	 * The configured domain allow-list.
	 *
	 * @return string[] Lower-cased domains, empty when any is allowed.
	 */
	public static function allowed_domains() {
		$raw = (string) CES_Helpers::setting( 'accounts_allowed_domains', '' );
		$out = array();

		foreach ( preg_split( '/[\s,;]+/', $raw ) as $part ) {
			$part = strtolower( trim( (string) $part, " \t\n\r\0\x0B.@" ) );

			if ( '' !== $part && false !== strpos( $part, '.' ) ) {
				$out[] = $part;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/* ---------------------------------------------------------------------
	 * Small helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * The PKCE S256 challenge for a verifier.
	 *
	 * @param string $verifier Code verifier.
	 * @return string Base64url-encoded SHA-256 digest.
	 */
	private static function challenge( $verifier ) {
		return self::base64url_encode( hash( 'sha256', (string) $verifier, true ) );
	}

	/**
	 * Base64url encode without padding.
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	private static function base64url_encode( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Base64url decode, padding restored.
	 *
	 * @param string $text Encoded text.
	 * @return string Raw bytes, '' when undecodable.
	 */
	private static function base64url_decode( $text ) {
		$text = strtr( (string) $text, '-_', '+/' );
		$pad  = strlen( $text ) % 4;

		if ( $pad ) {
			$text .= str_repeat( '=', 4 - $pad );
		}

		$out = base64_decode( $text, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		return false === $out ? '' : $out;
	}

	/**
	 * Remove the flow cookie.
	 *
	 * @return void
	 */
	private static function clear_state_cookie() {
		if ( empty( $_COOKIE[ self::STATE_COOKIE ] ) ) {
			return;
		}

		unset( $_COOKIE[ self::STATE_COOKIE ] );

		setcookie(
			self::STATE_COOKIE,
			'',
			time() - 3600,
			defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
			defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			is_ssl(),
			true
		);
	}

	/**
	 * Where the student asked to end up.
	 *
	 * @return string Same-site URL.
	 */
	private static function intended_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a destination, validated as same-site below.
		$raw = isset( $_GET['redirect_to'] ) ? (string) wp_unslash( $_GET['redirect_to'] ) : '';
		$url = esc_url_raw( $raw );

		if ( '' === $url ) {
			return CES_Helpers::dashboard_url();
		}

		// Refuse to be used as an open redirect.
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $host && strtolower( (string) $host ) !== strtolower( (string) $home ) ) {
			return CES_Helpers::dashboard_url();
		}

		return $url;
	}

	/**
	 * Readable messages for each failure slug.
	 *
	 * @return array Map of slug => message.
	 */
	public static function error_messages() {
		return array(
			'disabled'       => __( 'Gmail sign-in is switched off on this site.', 'classic-edu-suite' ),
			'throttled'      => __( 'Too many sign-in attempts. Please wait a few minutes.', 'classic-edu-suite' ),
			'state'          => __( 'That sign-in link did not match. Please start again.', 'classic-edu-suite' ),
			'expired'        => __( 'That sign-in took too long. Please try again.', 'classic-edu-suite' ),
			'cancelled'      => __( 'Sign-in was cancelled.', 'classic-edu-suite' ),
			'provider'       => __( 'Google could not complete the sign-in. Please try again.', 'classic-edu-suite' ),
			'no_code'        => __( 'Google did not send back a sign-in code. Please try again.', 'classic-edu-suite' ),
			'exchange'       => __( 'We could not confirm that sign-in with Google. Please try again.', 'classic-edu-suite' ),
			'no_token'       => __( 'Google did not return a usable sign-in token.', 'classic-edu-suite' ),
			'bad_token'      => __( 'Google returned a sign-in we could not read.', 'classic-edu-suite' ),
			'bad_audience'   => __( 'That sign-in was issued for a different website.', 'classic-edu-suite' ),
			'bad_issuer'     => __( 'That sign-in did not come from Google.', 'classic-edu-suite' ),
			'expired_token'  => __( 'That sign-in expired on the way back. Please try again.', 'classic-edu-suite' ),
			'no_email'       => __( 'That Google account has no usable email address.', 'classic-edu-suite' ),
			'unverified'     => __( 'Google has not verified that email address yet.', 'classic-edu-suite' ),
			'not_gmail'      => __( 'Please sign in with your Gmail address — the one ending in @gmail.com.', 'classic-edu-suite' ),
			'domain_blocked' => __( 'That email address is not allowed to sign in here.', 'classic-edu-suite' ),
			'no_signup'      => __( 'There is no account for that Gmail address yet, and new accounts are closed.', 'classic-edu-suite' ),
			'no_link'        => __( 'An account already uses that email address. Sign in with your password instead.', 'classic-edu-suite' ),
			'create_failed'  => __( 'We could not create your account. Please try again or contact us.', 'classic-edu-suite' ),
			'blocked'        => __( 'That account cannot sign in. Please contact the school.', 'classic-edu-suite' ),
		);
	}

	/**
	 * Abandon the flow and send the student back to the login page.
	 *
	 * @param string $slug        Failure slug, used to look up a message.
	 * @param string $redirect_to Where the student was heading.
	 * @param string $message     Optional override message.
	 * @return void
	 */
	private static function bail( $slug, $redirect_to = '', $message = '' ) {
		$slug = sanitize_key( (string) $slug );

		self::clear_state_cookie();

		$target = CES_Helpers::login_url( $redirect_to ? $redirect_to : CES_Helpers::dashboard_url() );
		$target = add_query_arg( 'ces_auth_error', $slug, $target );

		if ( '' !== trim( (string) $message ) ) {
			set_transient( 'ces_auth_msg_' . substr( CES_Helpers::visitor_hash(), 0, 20 ), (string) $message, 2 * MINUTE_IN_SECONDS );
		}

		CES_Helpers::log( 'Gmail sign-in abandoned', $slug );

		wp_safe_redirect( $target );
		exit;
	}
}
