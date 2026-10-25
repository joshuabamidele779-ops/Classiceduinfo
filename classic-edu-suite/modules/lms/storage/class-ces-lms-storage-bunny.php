<?php
/**
 * Bunny Stream storage driver.
 *
 * Switch `video_storage_driver` to `bunny`, fill in the four `bunny_*`
 * settings, and new uploads go to Bunny Stream instead of this server's disk.
 * Bunny transcodes and serves the HLS ladder, so no ffmpeg is needed and the
 * local cron packaging queue is bypassed entirely.
 *
 * Playback uses Bunny's token authentication: a SHA-256 hash over the
 * security key, the video id and an expiry. The security key lives in
 * `bunny_token_key` and is only ever used server-side; the browser receives a
 * URL that already carries the finished hash and stops working at `expires`.
 *
 * Honest limits of moving to Bunny:
 *   - Our own stream endpoint is out of the path, so the per-request
 *     concurrent-stream check only happens when a URL is issued, not on every
 *     segment. The limit becomes a soft limit.
 *   - The per-student watermark still works (it is drawn by our player over
 *     the video element), and so does coin gating, progress tracking and the
 *     login requirement.
 *   - Bunny token auth is strong against link sharing within the expiry
 *     window but is not bound to a WordPress account the way the local driver
 *     is. A URL copied inside its lifetime will play for someone else.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bunny Stream driver.
 */
class CES_Lms_Storage_Bunny extends CES_Lms_Storage {

	/**
	 * Bunny Stream API host.
	 */
	const API = 'https://video.bunnycdn.com';

	/**
	 * Driver key.
	 *
	 * @return string
	 */
	public function get_key() {
		return 'bunny';
	}

	/**
	 * Driver label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Bunny Stream', 'classic-edu-suite' );
	}

	/**
	 * Bunny can issue DRM-protected streams on higher plans, but this driver
	 * does not configure it, so report honestly.
	 *
	 * @return bool
	 */
	public function supports_drm() {
		return false;
	}

	/**
	 * Verify the required settings are present.
	 *
	 * @return true|WP_Error
	 */
	public function check_config() {
		$missing = array();

		if ( '' === (string) CES_Helpers::setting( 'bunny_library_id', '' ) ) {
			$missing[] = 'bunny_library_id';
		}

		if ( '' === (string) CES_Helpers::setting( 'bunny_api_key', '' ) ) {
			$missing[] = 'bunny_api_key';
		}

		if ( '' === (string) CES_Helpers::setting( 'bunny_cdn_hostname', '' ) ) {
			$missing[] = 'bunny_cdn_hostname';
		}

		if ( ! empty( $missing ) ) {
			return $this->config_error( implode( ', ', $missing ) );
		}

		return true;
	}

	/**
	 * Create a Bunny video object and stream the file into it.
	 *
	 * @param int    $lesson_id   Lesson post id.
	 * @param string $source_path Absolute path to the uploaded master file.
	 * @param array  $args        Optional original_name / mime.
	 * @return array|WP_Error
	 */
	public function upload( $lesson_id, $source_path, array $args = array() ) {
		$config = $this->check_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$lesson_id = (int) $lesson_id;

		if ( '' === $source_path || ! is_file( $source_path ) ) {
			return new WP_Error( 'ces_storage_missing_file', __( 'The uploaded file could not be found on the server.', 'classic-edu-suite' ) );
		}

		$library = rawurlencode( (string) CES_Helpers::setting( 'bunny_library_id', '' ) );
		$title   = get_the_title( $lesson_id );

		if ( '' === $title ) {
			$title = sprintf( 'Lesson %d', $lesson_id );
		}

		$created = $this->request(
			'POST',
			self::API . '/library/' . $library . '/videos',
			array(
				'body'    => wp_json_encode( array( 'title' => $title ) ),
				'headers' => array( 'Content-Type' => 'application/json' ),
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		if ( empty( $created['guid'] ) ) {
			return new WP_Error( 'ces_bunny_create', __( 'Bunny did not return a video id.', 'classic-edu-suite' ) );
		}

		$guid = sanitize_text_field( (string) $created['guid'] );

		$put = $this->put_file(
			self::API . '/library/' . $library . '/videos/' . rawurlencode( $guid ),
			$source_path
		);

		if ( is_wp_error( $put ) ) {
			// Leave no orphan object behind on the provider.
			$this->request( 'DELETE', self::API . '/library/' . $library . '/videos/' . rawurlencode( $guid ) );

			return $put;
		}

		update_post_meta( $lesson_id, CES_Lms_Meta::DRIVER, $this->get_key() );
		update_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID, $guid );
		update_post_meta( $lesson_id, CES_Lms_Meta::FILESIZE, (int) filesize( $source_path ) );

		if ( ! empty( $args['original_name'] ) ) {
			update_post_meta( $lesson_id, CES_Lms_Meta::SOURCE_NAME, sanitize_file_name( (string) $args['original_name'] ) );
		}

		update_post_meta(
			$lesson_id,
			CES_Lms_Meta::REMOTE_DATA,
			array(
				'library'  => (string) CES_Helpers::setting( 'bunny_library_id', '' ),
				'uploaded' => time(),
			)
		);

		/*
		 * The local copy is no longer needed once Bunny has it. Deleting it
		 * immediately is what keeps a shared-hosting disk quota survivable.
		 */
		wp_delete_file( $source_path );
		delete_post_meta( $lesson_id, CES_Lms_Meta::SOURCE );

		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_PROCESSING );

		return array(
			'driver'    => $this->get_key(),
			'remote_id' => $guid,
		);
	}

	/**
	 * Build a Bunny token-authenticated HLS playlist URL.
	 *
	 * @param int   $lesson_id Lesson post id.
	 * @param array $args      user_id / sid.
	 * @return array|WP_Error
	 */
	public function get_playlist_url( $lesson_id, array $args = array() ) {
		$config = $this->check_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$lesson_id = (int) $lesson_id;
		$guid      = (string) get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID, true );

		if ( '' === $guid ) {
			return new WP_Error( 'ces_bunny_no_video', __( 'This lesson has no video on Bunny Stream yet.', 'classic-edu-suite' ), array( 'status' => 409 ) );
		}

		if ( CES_Lms_Meta::STATUS_REMOTE !== CES_Lms_Meta::status( $lesson_id ) ) {
			return new WP_Error( 'ces_bunny_not_ready', __( 'This lesson is still being prepared. Please check back in a few minutes.', 'classic-edu-suite' ), array( 'status' => 409 ) );
		}

		$host = rtrim( (string) CES_Helpers::setting( 'bunny_cdn_hostname', '' ), '/' );
		$host = preg_replace( '#^https?://#', '', $host );
		$path = '/' . $guid . '/playlist.m3u8';
		$ttl  = CES_Lms_Token::ttl_for( CES_Lms_Token::SCOPE_MEDIA, $lesson_id );
		$exp  = time() + $ttl;

		$url = 'https://' . $host . $path;

		$key = (string) CES_Helpers::setting( 'bunny_token_key', '' );

		if ( '' !== $key ) {
			/*
			 * Bunny Stream token authentication: SHA-256 hex over
			 * security key + video id + expiry. The key itself never
			 * leaves the server; only the finished hash is sent.
			 */
			$hash = hash( 'sha256', $key . $guid . $exp );

			$url = add_query_arg(
				array(
					'token'   => $hash,
					'expires' => $exp,
				),
				$url
			);
		}

		return array(
			'kind'      => self::PLAYBACK_HLS,
			'url'       => $url,
			'mime'      => 'application/vnd.apple.mpegurl',
			'use_hlsjs' => true,
			'expires'   => $exp,
			'sid'       => $this->arg_sid( $args ),
		);
	}

	/**
	 * Poll Bunny for the encoding state and mirror it into post meta.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return array
	 */
	public function get_status( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$status    = CES_Lms_Meta::status( $lesson_id );
		$guid      = (string) get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID, true );

		return array(
			'status'   => $status,
			'label'    => CES_Lms_Meta::status_label( $status ),
			'error'    => (string) get_post_meta( $lesson_id, CES_Lms_Meta::ERROR, true ),
			'playable' => CES_Lms_Meta::STATUS_REMOTE === $status,
			'detail'   => array(
				'driver'    => $this->get_key(),
				'remote_id' => $guid,
				'duration'  => CES_Lms_Meta::duration( $lesson_id ),
			),
		);
	}

	/**
	 * Ask Bunny for the current encode state. Called by the cron worker.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return true|WP_Error
	 */
	public function refresh_status( $lesson_id ) {
		$config = $this->check_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$lesson_id = (int) $lesson_id;
		$guid      = (string) get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID, true );

		if ( '' === $guid ) {
			return new WP_Error( 'ces_bunny_no_video', __( 'This lesson has no Bunny video id.', 'classic-edu-suite' ) );
		}

		$library = rawurlencode( (string) CES_Helpers::setting( 'bunny_library_id', '' ) );

		$video = $this->request( 'GET', self::API . '/library/' . $library . '/videos/' . rawurlencode( $guid ) );

		if ( is_wp_error( $video ) ) {
			return $video;
		}

		$state = isset( $video['status'] ) ? (int) $video['status'] : -1;

		if ( ! empty( $video['length'] ) ) {
			update_post_meta( $lesson_id, CES_Lms_Meta::DURATION, (int) $video['length'] );
		}

		// Bunny: 0 queued, 1 processing, 2 encoding, 3 finished, 4 resolution
		// finished, 5 failed.
		if ( 3 === $state || 4 === $state ) {
			CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_REMOTE );
			return true;
		}

		if ( 5 === $state ) {
			CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_FAILED, __( 'Bunny Stream reported the encode as failed.', 'classic-edu-suite' ) );
			return true;
		}

		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_PROCESSING );

		return true;
	}

	/**
	 * Delete the Bunny video object.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return bool|WP_Error
	 */
	public function delete( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$guid      = (string) get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID, true );

		if ( '' === $guid ) {
			return true;
		}

		$config = $this->check_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$library = rawurlencode( (string) CES_Helpers::setting( 'bunny_library_id', '' ) );

		$result = $this->request( 'DELETE', self::API . '/library/' . $library . '/videos/' . rawurlencode( $guid ) );

		delete_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID );
		delete_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_DATA );
		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_NONE );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Perform a JSON API call against Bunny.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Absolute URL.
	 * @param array  $args   Extra wp_remote_request arguments.
	 * @return array|WP_Error Decoded body, or an empty array for 204s.
	 */
	private function request( $method, $url, array $args = array() ) {
		$defaults = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'AccessKey' => (string) CES_Helpers::setting( 'bunny_api_key', '' ),
				'Accept'    => 'application/json',
			),
		);

		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$args['headers'] = array_merge( $defaults['headers'], $args['headers'] );
		}

		$response = wp_remote_request( $url, array_merge( $defaults, $args ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			CES_Helpers::log( 'LMS Bunny API error', array( 'code' => $code, 'body' => substr( $body, 0, 500 ) ) );

			return new WP_Error(
				'ces_bunny_http',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Bunny Stream returned HTTP %d. Check the library id and API key.', 'classic-edu-suite' ),
					$code
				)
			);
		}

		if ( '' === trim( $body ) ) {
			return array();
		}

		$decoded = json_decode( $body, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Stream a file into a PUT request without loading it into memory.
	 *
	 * The WordPress HTTP API has no streaming-upload mode, and a lesson video
	 * can easily be a gigabyte, so cURL's own file handle is used when the
	 * extension is present. Without cURL we only attempt small files, because
	 * reading a large one into a string is a guaranteed memory-limit fatal on
	 * shared hosting.
	 *
	 * @param string $url  Target URL.
	 * @param string $path Absolute file path.
	 * @return true|WP_Error
	 */
	private function put_file( $url, $path ) {
		$size = (int) filesize( $path );

		if ( function_exists( 'curl_init' ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_close, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$handle = fopen( $path, 'rb' );

			if ( ! $handle ) {
				return new WP_Error( 'ces_bunny_read', __( 'Could not open the uploaded file for sending.', 'classic-edu-suite' ) );
			}

			$curl = curl_init( $url );

			curl_setopt( $curl, CURLOPT_PUT, true );
			curl_setopt( $curl, CURLOPT_INFILE, $handle );
			curl_setopt( $curl, CURLOPT_INFILESIZE, $size );
			curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $curl, CURLOPT_TIMEOUT, 0 );
			curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT, 20 );
			curl_setopt(
				$curl,
				CURLOPT_HTTPHEADER,
				array(
					'AccessKey: ' . (string) CES_Helpers::setting( 'bunny_api_key', '' ),
					'Content-Type: application/octet-stream',
				)
			);

			$body = curl_exec( $curl );
			$code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
			$err  = curl_errno( $curl ) ? curl_error( $curl ) : '';

			curl_close( $curl );
			fclose( $handle );
			// phpcs:enable

			if ( '' !== $err ) {
				return new WP_Error( 'ces_bunny_curl', $err );
			}

			if ( $code < 200 || $code >= 300 ) {
				CES_Helpers::log( 'LMS Bunny upload failed', array( 'code' => $code, 'body' => substr( (string) $body, 0, 500 ) ) );

				return new WP_Error(
					'ces_bunny_upload',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Bunny Stream refused the upload with HTTP %d.', 'classic-edu-suite' ),
						$code
					)
				);
			}

			return true;
		}

		if ( $size > 32 * MB_IN_BYTES ) {
			return new WP_Error(
				'ces_bunny_no_curl',
				__( 'This file is too large to send without the PHP cURL extension. Ask your host to enable cURL, or keep using the self-hosted driver.', 'classic-edu-suite' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			return new WP_Error( 'ces_bunny_read', __( 'Could not read the uploaded file.', 'classic-edu-suite' ) );
		}

		$result = $this->request(
			'PUT',
			$url,
			array(
				'body'    => $contents,
				'timeout' => 300,
				'headers' => array( 'Content-Type' => 'application/octet-stream' ),
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}
}
