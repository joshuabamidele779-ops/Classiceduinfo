<?php
/**
 * The protected streaming endpoint.
 *
 * This is the only route by which a byte of lesson video leaves the server.
 * It is a rewrite endpoint (`/ces-stream/...`) rather than `admin-ajax.php`,
 * because admin-ajax sends `Cache-Control: no-cache` plus a cookie-touching
 * init path on every hit and is a poor fit for Range requests and internal
 * redirects.
 *
 * The chain, in order, on every single request including every segment:
 *
 *   1. Parse the action, lesson id and filename from the URI.
 *   2. Verify the HMAC token (signature, expiry, scope, revocation epoch,
 *      optional IP binding) and that its user id is the *currently
 *      authenticated* user. This is what makes a copied URL worthless.
 *   3. Re-check business access (subscriber / free preview / paid unlock
 *      still inside its window). Tokens can outlive an unlock, so access is
 *      re-derived from the database, never trusted from the token.
 *   4. Enforce the per-account concurrent-stream cap.
 *   5. Check Origin/Referer when the browser bothered to send one.
 *   6. Resolve the file inside the private tree, refusing any path escape.
 *   7. Hand delivery to the web server via an internal redirect, or fall back
 *      to a chunked readfile() with Range support.
 *
 * Playlists are never served from disk verbatim. They are rewritten so every
 * variant playlist and every segment URI is an absolute URL on this endpoint
 * carrying its own token. A student who opens the playlist in a text editor
 * sees tokenised URLs scoped to their own account, never a filesystem path
 * and never a fetchable media URL.
 *
 * What this does NOT stop, stated plainly: a signed-in student can
 * screen-record their own screen. No web video player can prevent that. The
 * per-student watermark in CES_Lms_Player is the deterrent for that case.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Token-gated media delivery.
 */
class CES_Lms_Stream {

	/**
	 * URL prefix for the endpoint.
	 */
	const PREFIX = 'ces-stream';

	/**
	 * Serve a master or variant playlist.
	 */
	const ACTION_PLAYLIST = 'p';

	/**
	 * Serve an HLS segment.
	 */
	const ACTION_SEGMENT = 's';

	/**
	 * Serve the single source file (the no-ffmpeg fallback).
	 */
	const ACTION_FILE = 'f';

	/**
	 * Serve a fixed test payload, used to verify delivery methods.
	 */
	const ACTION_PROBE = 'x';

	/**
	 * Option storing the pinned delivery method.
	 */
	const DELIVERY_OPTION = 'ces_lms_delivery';

	/**
	 * Bytes per chunk in the readfile fallback.
	 */
	const CHUNK = 262144;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ), 10 );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'maybe_handle' ), 0 );
	}

	/**
	 * Register the rewrite rule.
	 *
	 * @return void
	 */
	public static function add_rewrite() {
		add_rewrite_rule(
			'^' . self::PREFIX . '/([pmsfx])/([0-9]+)/([A-Za-z0-9][A-Za-z0-9._\-]{0,180})/?$',
			'index.php?ces_stream=1&ces_stream_action=$matches[1]&ces_stream_lesson=$matches[2]&ces_stream_file=$matches[3]',
			'top'
		);
	}

	/**
	 * Whitelist our query vars.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'ces_stream';
		$vars[] = 'ces_stream_action';
		$vars[] = 'ces_stream_lesson';
		$vars[] = 'ces_stream_file';

		return $vars;
	}

	/**
	 * Build an endpoint URL.
	 *
	 * Falls back to a plain query string when the site has no pretty
	 * permalinks, so the module works even on an install where mod_rewrite is
	 * not cooperating.
	 *
	 * @param string $action  One of the ACTION_* constants.
	 * @param int    $lesson  Lesson post id.
	 * @param string $file    Filename, or '' for actions that ignore it.
	 * @param string $token   Signed token.
	 * @param array  $extra   Extra query arguments.
	 * @return string
	 */
	public static function build_url( $action, $lesson, $file = '', $token = '', array $extra = array() ) {
		$lesson = (int) $lesson;
		$file   = '' === $file ? 'video.bin' : $file;

		$query = array();

		if ( '' !== $token ) {
			$query['t'] = $token;
		}

		$query = array_merge( $query, $extra );

		if ( get_option( 'permalink_structure' ) ) {
			$url = home_url( '/' . self::PREFIX . '/' . $action . '/' . $lesson . '/' . rawurlencode( $file ) );
		} else {
			$url   = home_url( '/' );
			$query = array_merge(
				array(
					'ces_stream'        => 1,
					'ces_stream_action' => $action,
					'ces_stream_lesson' => $lesson,
					'ces_stream_file'   => $file,
				),
				$query
			);
		}

		return empty( $query ) ? $url : add_query_arg( $query, $url );
	}

	/**
	 * Intercept a stream request before WordPress loads a template.
	 *
	 * @param WP $wp Current WordPress environment.
	 * @return void
	 */
	public static function maybe_handle( $wp ) {
		if ( empty( $wp->query_vars['ces_stream'] ) ) {
			return;
		}

		$action = isset( $wp->query_vars['ces_stream_action'] ) ? sanitize_key( (string) $wp->query_vars['ces_stream_action'] ) : '';
		$lesson = isset( $wp->query_vars['ces_stream_lesson'] ) ? absint( $wp->query_vars['ces_stream_lesson'] ) : 0;
		$file   = isset( $wp->query_vars['ces_stream_file'] ) ? (string) $wp->query_vars['ces_stream_file'] : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';

		switch ( $action ) {
			case self::ACTION_PROBE:
				self::handle_probe();
				break;

			case self::ACTION_PLAYLIST:
				self::handle_playlist( $lesson, $file, $token );
				break;

			case self::ACTION_SEGMENT:
				self::handle_segment( $lesson, $file, $token );
				break;

			case self::ACTION_FILE:
				self::handle_source_file( $lesson, $token );
				break;

			default:
				self::fail( new WP_Error( 'ces_stream_action', __( 'Unknown stream action.', 'classic-edu-suite' ), array( 'status' => 400 ) ) );
		}
	}

	// ------------------------------------------------------------------
	// Request handlers.
	// ------------------------------------------------------------------

	/**
	 * Serve a rewritten master or variant playlist.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param string $file      Playlist filename.
	 * @param string $token     Signed token.
	 * @return void
	 */
	private static function handle_playlist( $lesson_id, $file, $token ) {
		$gate = self::authorise( $lesson_id, $token, CES_Lms_Token::SCOPE_PLAYLIST );

		if ( is_wp_error( $gate ) ) {
			self::fail( $gate );
		}

		$name = CES_Lms_Paths::sanitize_media_name( $file );

		if ( '' === $name || ! preg_match( '/\.m3u8$/', $name ) ) {
			self::fail( new WP_Error( 'ces_stream_file', __( 'That playlist does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) ) );
		}

		$dir  = CES_Lms_Paths::lesson_hls_dir( $lesson_id );
		$path = CES_Lms_Paths::resolve_within( $dir, $name );

		if ( '' === $path ) {
			self::fail( new WP_Error( 'ces_stream_file', __( 'That playlist does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			self::fail( new WP_Error( 'ces_stream_read', __( 'That playlist could not be read.', 'classic-edu-suite' ), array( 'status' => 500 ) ) );
		}

		$body = self::rewrite_playlist( (string) $raw, $lesson_id, $gate['user_id'], $gate['sid'] );

		self::send_common_headers();

		header( 'Content-Type: application/vnd.apple.mpegurl; charset=utf-8' );
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'Content-Length: ' . strlen( $body ) );

		if ( 'HEAD' === self::method() ) {
			exit;
		}

		self::discard_buffers();

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Playlist body is generated server-side, not HTML.
		exit;
	}

	/**
	 * Serve one HLS segment.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param string $file      Segment filename.
	 * @param string $token     Signed token.
	 * @return void
	 */
	private static function handle_segment( $lesson_id, $file, $token ) {
		$gate = self::authorise( $lesson_id, $token, CES_Lms_Token::SCOPE_MEDIA );

		if ( is_wp_error( $gate ) ) {
			self::fail( $gate );
		}

		$name = CES_Lms_Paths::sanitize_media_name( $file );

		if ( '' === $name || ! preg_match( '/\.(ts|m4s)$/', $name ) ) {
			self::fail( new WP_Error( 'ces_stream_file', __( 'That segment does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) ) );
		}

		$dir  = CES_Lms_Paths::lesson_hls_dir( $lesson_id );
		$path = CES_Lms_Paths::resolve_within( $dir, $name );

		if ( '' === $path ) {
			self::fail( new WP_Error( 'ces_stream_file', __( 'That segment does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) ) );
		}

		self::deliver( $path, 'video/mp2t' );
	}

	/**
	 * Serve the single source file, for lessons packaged without ffmpeg.
	 *
	 * The filename in the URL is deliberately ignored: the file served is
	 * always the one recorded against this lesson in post meta, so the URL
	 * carries no filesystem information at all.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param string $token     Signed token.
	 * @return void
	 */
	private static function handle_source_file( $lesson_id, $token ) {
		$gate = self::authorise( $lesson_id, $token, CES_Lms_Token::SCOPE_FILE );

		if ( is_wp_error( $gate ) ) {
			self::fail( $gate );
		}

		$path = CES_Lms_Paths::source_path( $lesson_id );

		if ( '' === $path ) {
			self::fail( new WP_Error( 'ces_stream_file', __( 'This lesson has no video file.', 'classic-edu-suite' ), array( 'status' => 404 ) ) );
		}

		$mime = (string) get_post_meta( $lesson_id, CES_Lms_Meta::MIME, true );

		self::deliver( $path, '' === $mime ? 'video/mp4' : $mime );
	}

	/**
	 * Serve a fixed 64-byte payload so the admin can verify delivery methods.
	 *
	 * Self-authenticating with a time-bucketed HMAC: no cookies needed, which
	 * is what lets the diagnostics screen test this over a loopback request.
	 * The payload is a constant and discloses nothing.
	 *
	 * @return void
	 */
	private static function handle_probe() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$method = isset( $_GET['m'] ) ? sanitize_key( wp_unslash( $_GET['m'] ) ) : '';
		$key    = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
		// phpcs:enable

		if ( ! hash_equals( self::probe_key( $method ), $key ) && ! hash_equals( self::probe_key( $method, -1 ), $key ) ) {
			self::fail( new WP_Error( 'ces_probe_key', __( 'Invalid probe request.', 'classic-edu-suite' ), array( 'status' => 403 ) ) );
		}

		if ( ! CES_Lms_Paths::ensure() ) {
			self::fail( new WP_Error( 'ces_probe_storage', __( 'Private storage is not writable.', 'classic-edu-suite' ), array( 'status' => 500 ) ) );
		}

		$path = CES_Lms_Paths::tmp_dir() . 'delivery-probe.bin';

		if ( ! is_file( $path ) || self::probe_payload() !== file_get_contents( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, self::probe_payload() );
		}

		self::deliver( $path, 'application/octet-stream', $method );
	}

	/**
	 * The constant probe payload.
	 *
	 * @return string
	 */
	public static function probe_payload() {
		return str_pad( 'CES-DELIVERY-PROBE-OK', 64, '.' );
	}

	/**
	 * HMAC key authorising a probe request.
	 *
	 * @param string $method Delivery method being probed.
	 * @param int    $offset Bucket offset, -1 to accept the previous window.
	 * @return string
	 */
	public static function probe_key( $method, $offset = 0 ) {
		$bucket = (int) floor( time() / 300 ) + (int) $offset;

		return hash_hmac( 'sha256', 'probe|' . sanitize_key( $method ) . '|' . $bucket, CES_Helpers::stream_secret() );
	}

	// ------------------------------------------------------------------
	// Authorisation.
	// ------------------------------------------------------------------

	/**
	 * Run the full gate for a media request.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param string $token     Raw token.
	 * @param string $scope     Required token scope.
	 * @return array|WP_Error Payload with user_id and sid on success.
	 */
	private static function authorise( $lesson_id, $token, $scope ) {
		$lesson_id = (int) $lesson_id;

		if ( $lesson_id <= 0 ) {
			return new WP_Error( 'ces_stream_lesson', __( 'No lesson was requested.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$payload = CES_Lms_Token::verify( $token, $scope, true );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( $payload['lesson_id'] !== $lesson_id ) {
			return new WP_Error( 'ces_stream_mismatch', __( 'This video link is not valid for that lesson.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		if ( CES_Lms_Cpt::LESSON !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'ces_stream_lesson', __( 'That lesson does not exist.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$origin = self::check_origin();

		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		/*
		 * Re-derive access from the database on every request. A media token
		 * can legitimately outlive a coin unlock window, so the token is proof
		 * of identity, never proof of entitlement.
		 */
		$access = CES_Lms_Access::can_watch( $lesson_id, $payload['user_id'] );

		if ( is_wp_error( $access ) ) {
			return $access;
		}

		// Write-coalesced: playlist requests refresh the slot, segments only
		// read, which keeps segment delivery free of database writes.
		$write      = ( CES_Lms_Token::SCOPE_PLAYLIST === $scope || CES_Lms_Token::SCOPE_FILE === $scope );
		$concurrent = CES_Lms_Concurrency::touch( $payload['user_id'], $payload['sid'], $write );

		if ( is_wp_error( $concurrent ) ) {
			return $concurrent;
		}

		return $payload;
	}

	/**
	 * Reject obviously cross-site requests without breaking mobile browsers.
	 *
	 * An absent Origin *and* Referer is explicitly allowed. iOS native HLS
	 * (AVPlayer) sends neither, and a site running `Referrer-Policy:
	 * no-referrer` strips Referer for everyone. Treating "no header" as
	 * hostile would lock out exactly the students this site serves. A header
	 * that is present and points somewhere else, though, is a real hotlink
	 * attempt and is refused.
	 *
	 * @return true|WP_Error
	 */
	private static function check_origin() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! $host ) {
			return true;
		}

		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

			if ( 'null' === strtolower( trim( $value ) ) ) {
				// A sandboxed iframe. Not necessarily hostile; let it through.
				continue;
			}

			$candidate = wp_parse_url( $value, PHP_URL_HOST );

			if ( $candidate && strtolower( $candidate ) !== strtolower( $host ) ) {
				return new WP_Error(
					'ces_stream_origin',
					__( 'This video can only be played on the Classic Edu Info website.', 'classic-edu-suite' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Playlist rewriting.
	// ------------------------------------------------------------------

	/**
	 * Rewrite every URI inside a playlist into a tokenised endpoint URL.
	 *
	 * Variant playlists and segments get longer-lived tokens than the master
	 * URL the page embeds. That is forced by native HLS on Safari/iOS, which
	 * fetches a playlist once and offers no hook to re-sign the URIs inside
	 * it. The exposure is contained: those tokens are still bound to one
	 * account, one lesson and (optionally) one IP, and the player replaces
	 * them with fresh short-lived ones whenever hls.js is driving playback.
	 *
	 * @param string $raw       Playlist as stored on disk.
	 * @param int    $lesson_id Lesson post id.
	 * @param int    $user_id   User the tokens are minted for.
	 * @param string $sid       Stream session id.
	 * @return string
	 */
	private static function rewrite_playlist( $raw, $lesson_id, $user_id, $sid ) {
		$long_ttl = CES_Lms_Token::ttl_for( CES_Lms_Token::SCOPE_MEDIA, $lesson_id );
		$lines    = preg_split( '/\r\n|\r|\n/', $raw );
		$out      = array();

		foreach ( (array) $lines as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				$out[] = '';
				continue;
			}

			if ( '#' === $trimmed[0] ) {
				// Rewrite URIs embedded in attribute lists (keys, maps, media).
				if ( false !== strpos( $trimmed, 'URI="' ) ) {
					$trimmed = preg_replace_callback(
						'/URI="([^"]+)"/',
						static function ( $matches ) use ( $lesson_id, $user_id, $sid, $long_ttl ) {
							$url = self::uri_for( $matches[1], $lesson_id, $user_id, $sid, $long_ttl );

							return 'URI="' . ( '' === $url ? $matches[1] : $url ) . '"';
						},
						$trimmed
					);
				}

				$out[] = $trimmed;
				continue;
			}

			$url   = self::uri_for( $trimmed, $lesson_id, $user_id, $sid, $long_ttl );
			$out[] = '' === $url ? '#' . $trimmed : $url;
		}

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * Turn one playlist URI into a signed endpoint URL.
	 *
	 * @param string $uri       Raw URI from the playlist.
	 * @param int    $lesson_id Lesson post id.
	 * @param int    $user_id   User id.
	 * @param string $sid       Stream session id.
	 * @param int    $ttl       Token lifetime.
	 * @return string Signed URL, or '' when the URI is not acceptable.
	 */
	private static function uri_for( $uri, $lesson_id, $user_id, $sid, $ttl ) {
		$name = CES_Lms_Paths::sanitize_media_name( basename( trim( $uri ) ) );

		if ( '' === $name ) {
			return '';
		}

		if ( preg_match( '/\.m3u8$/', $name ) ) {
			$token = CES_Lms_Token::issue( $lesson_id, $user_id, CES_Lms_Token::SCOPE_PLAYLIST, $sid, $ttl );

			return CES_Lms_Stream::build_url( self::ACTION_PLAYLIST, $lesson_id, $name, $token );
		}

		if ( preg_match( '/\.(ts|m4s)$/', $name ) ) {
			$token = CES_Lms_Token::issue( $lesson_id, $user_id, CES_Lms_Token::SCOPE_MEDIA, $sid, $ttl );

			return CES_Lms_Stream::build_url( self::ACTION_SEGMENT, $lesson_id, $name, $token );
		}

		return '';
	}

	// ------------------------------------------------------------------
	// Delivery.
	// ------------------------------------------------------------------

	/**
	 * Delivery method candidates, in preference order.
	 *
	 * @return array Method key => human label.
	 */
	public static function delivery_methods() {
		return array(
			'litespeed' => __( 'LiteSpeed internal file send (X-LiteSpeed-Send-File)', 'classic-edu-suite' ),
			'xsendfile' => __( 'Apache mod_xsendfile (X-Sendfile)', 'classic-edu-suite' ),
			'accel'     => __( 'nginx internal redirect (X-Accel-Redirect)', 'classic-edu-suite' ),
			'readfile'  => __( 'PHP readfile() with Range support (always works, ties up a worker)', 'classic-edu-suite' ),
		);
	}

	/**
	 * The delivery method actually in use.
	 *
	 * Defaults to `readfile` until an admin has verified a faster one on the
	 * diagnostics screen. That default is deliberate: an unverified
	 * internal-redirect header fails *silently*, returning an empty 200 and a
	 * video that never plays, whereas readfile() is merely slow. Never
	 * broken beats possibly fast.
	 *
	 * @return string
	 */
	public static function delivery_method() {
		$pinned  = (string) get_option( self::DELIVERY_OPTION, '' );
		$methods = self::delivery_methods();

		if ( '' !== $pinned && isset( $methods[ $pinned ] ) ) {
			return $pinned;
		}

		return 'readfile';
	}

	/**
	 * The method this host most likely supports, for the diagnostics screen.
	 *
	 * @return string
	 */
	public static function suggested_method() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		if ( false !== strpos( $software, 'litespeed' ) ) {
			return 'litespeed';
		}

		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'accel';
		}

		if ( function_exists( 'apache_get_modules' ) ) {
			$modules = (array) apache_get_modules();

			if ( in_array( 'mod_xsendfile', $modules, true ) ) {
				return 'xsendfile';
			}
		}

		return 'readfile';
	}

	/**
	 * The URI prefix an nginx `internal` location must map to the private dir.
	 *
	 * @return string
	 */
	public static function accel_prefix() {
		return '/ces-protected/';
	}

	/**
	 * Send a file to the client.
	 *
	 * @param string $path     Absolute, already-validated file path.
	 * @param string $mime     Content type.
	 * @param string $override Force a delivery method (probe only).
	 * @return void
	 */
	private static function deliver( $path, $mime, $override = '' ) {
		$size = (int) filesize( $path );

		if ( $size <= 0 ) {
			self::fail( new WP_Error( 'ces_stream_empty', __( 'That file is empty.', 'classic-edu-suite' ), array( 'status' => 404 ) ) );
		}

		$methods = self::delivery_methods();
		$method  = ( '' !== $override && isset( $methods[ $override ] ) ) ? $override : self::delivery_method();

		self::send_common_headers();

		header( 'Content-Type: ' . $mime );
		header( 'Accept-Ranges: bytes' );

		/*
		 * `private` rather than `no-store` on media bytes. no-store would make
		 * a student on metered mobile data re-download every segment they
		 * scrub back over, which on this site is a real cost. `private`
		 * forbids every shared and proxy cache, which is the part that
		 * matters; the bytes may sit in that one student's own browser for a
		 * few minutes. Playlists, which carry tokens, are no-store.
		 */
		header( 'Cache-Control: private, max-age=300, no-transform' );
		header( 'Content-Disposition: inline' );

		if ( 'HEAD' === self::method() ) {
			header( 'Content-Length: ' . $size );
			exit;
		}

		if ( 'litespeed' === $method ) {
			// LiteSpeed reads the file itself and frees this PHP worker now.
			header( 'X-LiteSpeed-Send-File: ' . $path );
			exit;
		}

		if ( 'xsendfile' === $method ) {
			header( 'X-Sendfile: ' . $path );
			exit;
		}

		if ( 'accel' === $method ) {
			$base = CES_Lms_Paths::base();

			if ( $base && 0 === strpos( str_replace( '\\', '/', $path ), str_replace( '\\', '/', $base ) ) ) {
				$relative = substr( str_replace( '\\', '/', $path ), strlen( str_replace( '\\', '/', $base ) ) );

				header( 'X-Accel-Redirect: ' . self::accel_prefix() . $relative );
				exit;
			}
		}

		self::readfile_range( $path, $size );
	}

	/**
	 * Chunked readfile with HTTP Range support.
	 *
	 * The honest cost of this path: one PHP worker is held for the whole
	 * duration of the transfer. Shared hosting typically allows somewhere
	 * between 10 and 40 simultaneous PHP processes for the entire account, so
	 * roughly that many students streaming at once will exhaust the pool and
	 * the *whole site* — not just video — starts queueing or returning 508s.
	 * This is why the diagnostics screen pushes so hard for an internal
	 * redirect, and why HLS, whose segments are short-lived requests rather
	 * than one long one, degrades far more gracefully here than a single
	 * progressive file does.
	 *
	 * @param string $path Absolute file path.
	 * @param int    $size File size in bytes.
	 * @return void
	 */
	private static function readfile_range( $path, $size ) {
		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		if ( '' !== $range ) {
			if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $range ), $matches ) ) {
				header( 'Content-Range: bytes */' . $size );
				self::status( 416 );
				exit;
			}

			$from = $matches[1];
			$to   = $matches[2];

			if ( '' === $from ) {
				// Suffix range: the last N bytes.
				$length = (int) $to;

				if ( $length <= 0 ) {
					header( 'Content-Range: bytes */' . $size );
					self::status( 416 );
					exit;
				}

				$start = max( 0, $size - $length );
				$end   = $size - 1;
			} else {
				$start = (int) $from;
				$end   = '' === $to ? $size - 1 : (int) $to;
			}

			if ( $start > $end || $start >= $size ) {
				header( 'Content-Range: bytes */' . $size );
				self::status( 416 );
				exit;
			}

			$end = min( $end, $size - 1 );

			self::status( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}

		$length = $end - $start + 1;

		header( 'Content-Length: ' . $length );

		self::discard_buffers();

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$handle = fopen( $path, 'rb' );

		if ( ! $handle ) {
			self::fail( new WP_Error( 'ces_stream_open', __( 'That file could not be opened.', 'classic-edu-suite' ), array( 'status' => 500 ) ) );
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = $length;

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
			@set_time_limit( 0 );
		}

		ignore_user_abort( false );

		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( self::CHUNK, $remaining ) );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw media bytes.

			$remaining -= strlen( $chunk );

			flush();

			if ( connection_aborted() ) {
				break;
			}
		}

		fclose( $handle );
		// phpcs:enable

		exit;
	}

	// ------------------------------------------------------------------
	// Response plumbing.
	// ------------------------------------------------------------------

	/**
	 * Headers sent on every stream response.
	 *
	 * @return void
	 */
	private static function send_common_headers() {
		if ( headers_sent() ) {
			return;
		}

		header_remove( 'Last-Modified' );
		header_remove( 'ETag' );

		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: same-origin' );
		header( 'Vary: Origin' );
	}

	/**
	 * Current request method.
	 *
	 * @return string
	 */
	private static function method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * Send a status line.
	 *
	 * @param int $code HTTP status code.
	 * @return void
	 */
	private static function status( $code ) {
		if ( ! headers_sent() ) {
			status_header( (int) $code );
		}
	}

	/**
	 * Throw away any output buffers a theme or plugin opened.
	 *
	 * @return void
	 */
	private static function discard_buffers() {
		while ( ob_get_level() > 0 ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ob_end_clean();
		}
	}

	/**
	 * End the request with a plain-text error.
	 *
	 * Deliberately not HTML: this endpoint is consumed by media players, and a
	 * themed error page would be parsed as a corrupt segment.
	 *
	 * @param WP_Error $error Error to report.
	 * @return void
	 */
	private static function fail( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 403;

		self::discard_buffers();

		if ( ! headers_sent() ) {
			status_header( $status );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'X-Ces-Stream-Error: ' . $error->get_error_code() );
		}

		echo esc_html( $error->get_error_message() );
		exit;
	}
}
