<?php
/**
 * Signed, short-lived stream tokens.
 *
 * A token is an HMAC-SHA256 signature over a compact, pipe-delimited payload
 * carrying the protocol version, lesson id, user id, scope, expiry, a hashed
 * client IP tag, a per-user revocation epoch and a stream session id. The
 * payload is readable by anyone holding the token (it is not encrypted) but it
 * cannot be modified, because the secret -- `CES_Helpers::stream_secret()` --
 * never leaves the server.
 *
 * What a token stops:
 *   - Guessing: the signature is 256 bits of HMAC over a server-only secret.
 *   - Tampering: changing the lesson, user or expiry invalidates the signature.
 *   - Passing the URL to a friend: verification requires that the token's user
 *     id equals the *currently authenticated* user. A stream URL pasted into
 *     another browser, a download manager with no cookie jar, or a public
 *     forum resolves to HTTP 403 regardless of how fresh the token is.
 *
 * What it cannot stop: a signed-in student screen-recording their own screen.
 * Nothing can. The watermark in the player is the deterrent for that; see
 * CES_Lms_Player and the module README.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stream token issuing and verification.
 */
class CES_Lms_Token {

	/**
	 * Payload format version. Bumping this invalidates every live token.
	 */
	const VERSION = '1';

	/**
	 * Scope for playlist (.m3u8) requests.
	 */
	const SCOPE_PLAYLIST = 'p';

	/**
	 * Scope for HLS segment requests.
	 */
	const SCOPE_MEDIA = 'm';

	/**
	 * Scope for progressive single-file requests (the no-ffmpeg fallback).
	 */
	const SCOPE_FILE = 'f';

	/**
	 * User meta key holding the per-user token revocation epoch.
	 */
	const EPOCH_META = 'ces_lms_token_epoch';

	/**
	 * Separator between the payload and the signature.
	 */
	const DELIM = '~';

	/**
	 * Issue a token.
	 *
	 * @param int    $lesson_id Lesson post id the token is valid for.
	 * @param int    $user_id   User the token is bound to.
	 * @param string $scope     One of the SCOPE_* constants.
	 * @param string $sid       Stream session id; generated when empty.
	 * @param int    $ttl       Explicit lifetime in seconds; 0 uses the scope default.
	 * @return string Opaque token, or '' when the arguments are unusable.
	 */
	public static function issue( $lesson_id, $user_id, $scope = self::SCOPE_PLAYLIST, $sid = '', $ttl = 0 ) {
		$lesson_id = (int) $lesson_id;
		$user_id   = (int) $user_id;

		if ( $lesson_id <= 0 || $user_id <= 0 ) {
			return '';
		}

		if ( ! in_array( $scope, array( self::SCOPE_PLAYLIST, self::SCOPE_MEDIA, self::SCOPE_FILE ), true ) ) {
			$scope = self::SCOPE_PLAYLIST;
		}

		$ttl = (int) $ttl > 0 ? (int) $ttl : self::ttl_for( $scope, $lesson_id );
		$sid = self::sanitize_sid( $sid );

		if ( '' === $sid ) {
			$sid = self::new_sid();
		}

		$fields = array(
			self::VERSION,
			$lesson_id,
			$user_id,
			$scope,
			time() + $ttl,
			self::ip_tag(),
			self::epoch( $user_id ),
			$sid,
		);

		$body = implode( '|', $fields );

		return self::b64( $body ) . self::DELIM . self::b64( self::sign( $body ) );
	}

	/**
	 * Verify a token.
	 *
	 * @param string $token          Raw token from the request.
	 * @param string $expected_scope Required scope, or '' to accept any.
	 * @param bool   $match_current  Require the token user to be the logged-in user.
	 * @return array|WP_Error Payload array on success.
	 */
	public static function verify( $token, $expected_scope = '', $match_current = true ) {
		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token || strlen( $token ) > 1024 ) {
			return new WP_Error( 'ces_token_missing', __( 'This video link is missing its access token.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$parts = explode( self::DELIM, $token );

		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'ces_token_malformed', __( 'This video link is not valid.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$body = self::unb64( $parts[0] );
		$sig  = self::unb64( $parts[1] );

		if ( '' === $body || '' === $sig ) {
			return new WP_Error( 'ces_token_malformed', __( 'This video link is not valid.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		// Constant-time comparison: never leak signature bytes through timing.
		if ( ! hash_equals( self::sign( $body ), $sig ) ) {
			return new WP_Error( 'ces_token_bad_signature', __( 'This video link is not valid.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$fields = explode( '|', $body );

		if ( 8 !== count( $fields ) || self::VERSION !== $fields[0] ) {
			return new WP_Error( 'ces_token_version', __( 'This video link has expired. Reload the lesson page.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$payload = array(
			'lesson_id' => (int) $fields[1],
			'user_id'   => (int) $fields[2],
			'scope'     => (string) $fields[3],
			'expires'   => (int) $fields[4],
			'ip_tag'    => (string) $fields[5],
			'epoch'     => (int) $fields[6],
			'sid'       => self::sanitize_sid( $fields[7] ),
		);

		if ( $payload['lesson_id'] <= 0 || $payload['user_id'] <= 0 || '' === $payload['sid'] ) {
			return new WP_Error( 'ces_token_malformed', __( 'This video link is not valid.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		if ( $payload['expires'] < time() ) {
			return new WP_Error( 'ces_token_expired', __( 'This video link has expired. Reload the lesson page.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		if ( '' !== $expected_scope && $expected_scope !== $payload['scope'] ) {
			return new WP_Error( 'ces_token_scope', __( 'This video link is not valid for that request.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		// Revocation: bumping the user's epoch kills every token they hold.
		if ( $payload['epoch'] !== self::epoch( $payload['user_id'] ) ) {
			return new WP_Error( 'ces_token_revoked', __( 'Your video session was ended. Reload the lesson page.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		/*
		 * The single most useful check in the whole chain: the token only works
		 * for the account it was minted for, proven by that account's own
		 * WordPress auth cookie on this request. Sharing the URL achieves
		 * nothing; yt-dlp and IDM carry no cookie at all.
		 */
		if ( $match_current && $payload['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'ces_token_user', __( 'This video link belongs to a different account.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		if ( CES_Helpers::enabled( 'video_bind_token_to_ip' ) && '' !== $payload['ip_tag'] ) {
			if ( ! hash_equals( $payload['ip_tag'], self::ip_tag() ) ) {
				return new WP_Error(
					'ces_token_ip',
					__( 'Your network changed. Reload the lesson page to continue watching.', 'classic-edu-suite' ),
					array( 'status' => 403 )
				);
			}
		}

		return $payload;
	}

	/**
	 * Default lifetime for a scope, in seconds.
	 *
	 * Playlist tokens are deliberately tiny -- the default is 120 seconds --
	 * because the player refreshes them while it plays.
	 *
	 * Media and file tokens are longer-lived, and that is a considered
	 * trade-off rather than an oversight. Native HLS on Safari/iOS fetches the
	 * playlist once and cannot be asked to re-sign the segment URIs inside it,
	 * so those URIs have to stay valid for the length of the lesson. The
	 * exposure that creates is small, because the token is still bound to one
	 * user, one lesson and one IP: a long-lived URL is useless without that
	 * account's session cookie.
	 *
	 * @param string $scope     Scope constant.
	 * @param int    $lesson_id Lesson post id, used for its duration.
	 * @return int
	 */
	public static function ttl_for( $scope, $lesson_id = 0 ) {
		$base = CES_Helpers::setting_int( 'video_token_ttl', 120 );
		$base = max( 30, min( 3600, $base ) );

		if ( self::SCOPE_PLAYLIST === $scope ) {
			return $base;
		}

		$duration = CES_Lms_Meta::duration( $lesson_id );

		if ( $duration <= 0 ) {
			$duration = 2 * HOUR_IN_SECONDS;
		}

		$ttl = $base + $duration + 300;

		return (int) max( 300, min( 6 * HOUR_IN_SECONDS, $ttl ) );
	}

	/**
	 * How long the player should wait before asking for a fresh token.
	 *
	 * Half the playlist TTL, floored at 15 seconds. Refreshing at the halfway
	 * mark means a slow network or a backgrounded tab still has a full half-TTL
	 * of slack, so a two-hour lesson never stalls on an expired token.
	 *
	 * @return int Seconds.
	 */
	public static function refresh_interval() {
		return (int) max( 15, floor( self::ttl_for( self::SCOPE_PLAYLIST ) / 2 ) );
	}

	/**
	 * Generate a fresh stream session id.
	 *
	 * Identifies one playing tab for the concurrent-stream limiter. Random, so
	 * a student cannot forge someone else's slot.
	 *
	 * @return string 16 lowercase hex characters.
	 */
	public static function new_sid() {
		return substr( bin2hex( self::random_bytes( 12 ) ), 0, 16 );
	}

	/**
	 * Narrow a session id to the expected shape.
	 *
	 * @param string $sid Candidate.
	 * @return string Clean sid, or ''.
	 */
	public static function sanitize_sid( $sid ) {
		$sid = strtolower( (string) $sid );

		return preg_match( '/^[a-f0-9]{8,32}$/', $sid ) ? $sid : '';
	}

	/**
	 * Bump a user's token epoch, invalidating every token they hold.
	 *
	 * @param int $user_id User id.
	 * @return int The new epoch.
	 */
	public static function revoke_all( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$next = self::epoch( $user_id ) + 1;
		update_user_meta( $user_id, self::EPOCH_META, $next );

		return $next;
	}

	/**
	 * Current token epoch for a user.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function epoch( $user_id ) {
		$epoch = (int) get_user_meta( (int) $user_id, self::EPOCH_META, true );

		return $epoch > 0 ? $epoch : 1;
	}

	/**
	 * Keyed hash of the client IP.
	 *
	 * Stored rather than the address itself so a token never discloses where
	 * the student is. Returns '' when no IP could be determined, which simply
	 * skips the IP check for that token instead of locking the student out.
	 *
	 * @return string 16 hex characters, or ''.
	 */
	private static function ip_tag() {
		$ip = CES_Helpers::ip();

		if ( '' === $ip ) {
			return '';
		}

		return substr( hash_hmac( 'sha256', 'ip|' . $ip, CES_Helpers::stream_secret() ), 0, 16 );
	}

	/**
	 * Raw HMAC-SHA256 of a payload under the install secret.
	 *
	 * @param string $body Payload string.
	 * @return string Raw binary digest.
	 */
	private static function sign( $body ) {
		return hash_hmac( 'sha256', $body, CES_Helpers::stream_secret(), true );
	}

	/**
	 * URL-safe base64 encode without padding.
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	private static function b64( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * URL-safe base64 decode.
	 *
	 * @param string $encoded Encoded string.
	 * @return string Raw bytes, or '' when the input is not valid base64.
	 */
	private static function unb64( $encoded ) {
		$encoded = (string) $encoded;

		if ( ! preg_match( '#^[A-Za-z0-9\-_]+$#', $encoded ) ) {
			return '';
		}

		$pad = strlen( $encoded ) % 4;

		if ( $pad ) {
			$encoded .= str_repeat( '=', 4 - $pad );
		}

		$raw = base64_decode( strtr( $encoded, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		return false === $raw ? '' : $raw;
	}

	/**
	 * Cryptographically strong random bytes, with a safe fallback.
	 *
	 * @param int $length Byte count.
	 * @return string
	 */
	private static function random_bytes( $length ) {
		$length = max( 8, (int) $length );

		try {
			return random_bytes( $length );
		} catch ( Exception $e ) {
			// wp_generate_password uses the WP random source; good enough here.
			return substr( hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ), true ), 0, $length );
		}
	}
}
