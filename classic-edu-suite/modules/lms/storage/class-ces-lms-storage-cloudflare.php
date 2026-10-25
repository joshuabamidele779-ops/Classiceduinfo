<?php
/**
 * Cloudflare Stream storage driver.
 *
 * Switch `video_storage_driver` to `cloudflare` and fill in
 * `cloudflare_account_id` and `cloudflare_api_token`. Cloudflare transcodes
 * and serves the HLS ladder, so ffmpeg is not needed and the local packaging
 * queue is bypassed.
 *
 * Uploads use the tus 1.0.0 resumable protocol in 10 MiB chunks. That is the
 * only sane option here: Cloudflare's simple upload endpoint needs the whole
 * file in one request body, and holding a gigabyte video in a PHP string on
 * shared hosting is a guaranteed memory fatal. Chunked PATCH keeps peak memory
 * at roughly one chunk.
 *
 * Videos are created with `requiresignedurls`, so the public manifest URL does
 * not work on its own. Playback goes through a short-lived signed token minted
 * server-side per student.
 *
 * Honest limits of moving to Cloudflare, same as for Bunny: our own stream
 * endpoint leaves the path, so the concurrent-stream cap becomes a soft limit
 * checked when a URL is issued rather than on every segment, and a signed URL
 * copied inside its lifetime will play for someone else. Coin gating, login
 * requirement, progress tracking and the per-student watermark all still work.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cloudflare Stream driver.
 */
class CES_Lms_Storage_Cloudflare extends CES_Lms_Storage {

	/**
	 * Cloudflare API v4 base.
	 */
	const API = 'https://api.cloudflare.com/client/v4';

	/**
	 * tus chunk size. Must be at least 5 MiB and divisible by 256 KiB.
	 */
	const CHUNK = 10485760;

	/**
	 * Driver key.
	 *
	 * @return string
	 */
	public function get_key() {
		return 'cloudflare';
	}

	/**
	 * Driver label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Cloudflare Stream', 'classic-edu-suite' );
	}

	/**
	 * Signed URLs are not DRM. Report honestly.
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

		if ( '' === (string) CES_Helpers::setting( 'cloudflare_account_id', '' ) ) {
			$missing[] = 'cloudflare_account_id';
		}

		if ( '' === (string) CES_Helpers::setting( 'cloudflare_api_token', '' ) ) {
			$missing[] = 'cloudflare_api_token';
		}

		if ( ! empty( $missing ) ) {
			return $this->config_error( implode( ', ', $missing ) );
		}

		return true;
	}

	/**
	 * Upload a master file to Cloudflare Stream over tus.
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

		$size = (int) filesize( $source_path );

		if ( $size <= 0 ) {
			return new WP_Error( 'ces_storage_empty', __( 'The uploaded file is empty.', 'classic-edu-suite' ) );
		}

		$name = isset( $args['original_name'] ) ? (string) $args['original_name'] : basename( $source_path );

		$created = $this->create_tus_upload( $lesson_id, $size, $name );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$sent = $this->send_chunks( $created['location'], $source_path, $size );

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		update_post_meta( $lesson_id, CES_Lms_Meta::DRIVER, $this->get_key() );
		update_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID, $created['uid'] );
		update_post_meta( $lesson_id, CES_Lms_Meta::FILESIZE, $size );
		update_post_meta( $lesson_id, CES_Lms_Meta::SOURCE_NAME, sanitize_file_name( $name ) );
		update_post_meta(
			$lesson_id,
			CES_Lms_Meta::REMOTE_DATA,
			array(
				'uploaded' => time(),
			)
		);

		// Free the shared-hosting disk as soon as Cloudflare owns the bytes.
		wp_delete_file( $source_path );
		delete_post_meta( $lesson_id, CES_Lms_Meta::SOURCE );

		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_PROCESSING );

		return array(
			'driver'    => $this->get_key(),
			'remote_id' => $created['uid'],
		);
	}

	/**
	 * Mint a signed HLS manifest URL for one student.
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
		$uid       = (string) get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID, true );

		if ( '' === $uid ) {
			return new WP_Error( 'ces_cf_no_video', __( 'This lesson has no video on Cloudflare Stream yet.', 'classic-edu-suite' ), array( 'status' => 409 ) );
		}

		if ( CES_Lms_Meta::STATUS_REMOTE !== CES_Lms_Meta::status( $lesson_id ) ) {
			return new WP_Error( 'ces_cf_not_ready', __( 'This lesson is still being prepared. Please check back in a few minutes.', 'classic-edu-suite' ), array( 'status' => 409 ) );
		}

		$remote = get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_DATA, true );
		$remote = is_array( $remote ) ? $remote : array();
		$hls    = isset( $remote['hls'] ) ? (string) $remote['hls'] : '';

		if ( '' === $hls ) {
			return new WP_Error( 'ces_cf_no_manifest', __( 'Cloudflare has not reported a playback manifest for this lesson yet.', 'classic-edu-suite' ), array( 'status' => 409 ) );
		}

		$ttl = CES_Lms_Token::ttl_for( CES_Lms_Token::SCOPE_MEDIA, $lesson_id );
		$exp = time() + $ttl;

		$signed = $this->request(
			'POST',
			self::API . '/accounts/' . rawurlencode( (string) CES_Helpers::setting( 'cloudflare_account_id', '' ) ) . '/stream/' . rawurlencode( $uid ) . '/token',
			array(
				'body'    => wp_json_encode( array( 'exp' => $exp ) ),
				'headers' => array( 'Content-Type' => 'application/json' ),
			)
		);

		if ( is_wp_error( $signed ) ) {
			return $signed;
		}

		$token = isset( $signed['result']['token'] ) ? (string) $signed['result']['token'] : '';

		if ( '' === $token ) {
			return new WP_Error( 'ces_cf_token', __( 'Cloudflare did not return a playback token.', 'classic-edu-suite' ), array( 'status' => 502 ) );
		}

		/*
		 * The manifest URL is .../<uid>/manifest/video.m3u8 and the signed
		 * form simply swaps the uid for the token.
		 */
		$url = str_replace( '/' . $uid . '/', '/' . $token . '/', $hls );

		if ( $url === $hls ) {
			return new WP_Error( 'ces_cf_manifest_shape', __( 'The stored Cloudflare manifest URL has an unexpected shape. Re-run the queue to refresh it.', 'classic-edu-suite' ), array( 'status' => 500 ) );
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
	 * Local status mirror.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return array
	 */
	public function get_status( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$status    = CES_Lms_Meta::status( $lesson_id );
		$remote    = get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_DATA, true );
		$remote    = is_array( $remote ) ? $remote : array();

		return array(
			'status'   => $status,
			'label'    => CES_Lms_Meta::status_label( $status ),
			'error'    => (string) get_post_meta( $lesson_id, CES_Lms_Meta::ERROR, true ),
			'playable' => CES_Lms_Meta::STATUS_REMOTE === $status,
			'detail'   => array(
				'driver'    => $this->get_key(),
				'remote_id' => (string) get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID, true ),
				'duration'  => CES_Lms_Meta::duration( $lesson_id ),
				'pct'       => isset( $remote['pct'] ) ? (float) $remote['pct'] : 0,
			),
		);
	}

	/**
	 * Poll Cloudflare for the encode state. Called by the cron worker.
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
		$uid       = (string) get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID, true );

		if ( '' === $uid ) {
			return new WP_Error( 'ces_cf_no_video', __( 'This lesson has no Cloudflare video id.', 'classic-edu-suite' ) );
		}

		$response = $this->request(
			'GET',
			self::API . '/accounts/' . rawurlencode( (string) CES_Helpers::setting( 'cloudflare_account_id', '' ) ) . '/stream/' . rawurlencode( $uid )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();
		$state  = isset( $result['status']['state'] ) ? (string) $result['status']['state'] : '';

		if ( isset( $result['duration'] ) && (float) $result['duration'] > 0 ) {
			update_post_meta( $lesson_id, CES_Lms_Meta::DURATION, (int) round( (float) $result['duration'] ) );
		}

		$remote = get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_DATA, true );
		$remote = is_array( $remote ) ? $remote : array();

		if ( ! empty( $result['playback']['hls'] ) ) {
			$remote['hls'] = esc_url_raw( (string) $result['playback']['hls'] );
		}

		if ( isset( $result['status']['pctComplete'] ) ) {
			$remote['pct'] = (float) $result['status']['pctComplete'];
		}

		update_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_DATA, $remote );

		if ( 'ready' === $state && ! empty( $remote['hls'] ) ) {
			CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_REMOTE );
			return true;
		}

		if ( 'error' === $state ) {
			$message = isset( $result['status']['errorReasonText'] ) ? (string) $result['status']['errorReasonText'] : __( 'Cloudflare reported an encoding error.', 'classic-edu-suite' );
			CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_FAILED, $message );
			return true;
		}

		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_PROCESSING );

		return true;
	}

	/**
	 * Delete the Cloudflare Stream video.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return bool|WP_Error
	 */
	public function delete( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$uid       = (string) get_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID, true );

		if ( '' === $uid ) {
			return true;
		}

		$config = $this->check_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$result = $this->request(
			'DELETE',
			self::API . '/accounts/' . rawurlencode( (string) CES_Helpers::setting( 'cloudflare_account_id', '' ) ) . '/stream/' . rawurlencode( $uid )
		);

		delete_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID );
		delete_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_DATA );
		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_NONE );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Open a tus upload and return its endpoint and media id.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param int    $size      File size in bytes.
	 * @param string $name      Original filename.
	 * @return array|WP_Error { @type string $location, @type string $uid }
	 */
	private function create_tus_upload( $lesson_id, $size, $name ) {
		$title = get_the_title( $lesson_id );

		if ( '' === $title ) {
			$title = $name;
		}

		// tus Upload-Metadata: comma-separated "key base64value" pairs. A bare
		// key (no value) is a boolean flag, which is how requiresignedurls is
		// expressed.
		$metadata = 'requiresignedurls,name ' . base64_encode( $title ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$response = wp_remote_post(
			self::API . '/accounts/' . rawurlencode( (string) CES_Helpers::setting( 'cloudflare_account_id', '' ) ) . '/stream',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization'   => 'Bearer ' . (string) CES_Helpers::setting( 'cloudflare_api_token', '' ),
					'Tus-Resumable'   => '1.0.0',
					'Upload-Length'   => (string) $size,
					'Upload-Metadata' => $metadata,
				),
				'body'    => '',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 201 !== $code ) {
			CES_Helpers::log(
				'LMS Cloudflare tus create failed',
				array( 'code' => $code, 'body' => substr( (string) wp_remote_retrieve_body( $response ), 0, 500 ) )
			);

			return new WP_Error(
				'ces_cf_tus_create',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Cloudflare refused to start the upload (HTTP %d). Check the account id and API token scope.', 'classic-edu-suite' ),
					$code
				)
			);
		}

		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		$uid      = (string) wp_remote_retrieve_header( $response, 'stream-media-id' );

		if ( '' === $location ) {
			return new WP_Error( 'ces_cf_tus_location', __( 'Cloudflare did not return a tus upload URL.', 'classic-edu-suite' ) );
		}

		if ( '' === $uid ) {
			// Fall back to the last path segment of the tus endpoint.
			$path = (string) wp_parse_url( $location, PHP_URL_PATH );
			$uid  = basename( $path );
		}

		return array(
			'location' => $location,
			'uid'      => sanitize_text_field( $uid ),
		);
	}

	/**
	 * Send a file to a tus endpoint in fixed-size chunks.
	 *
	 * @param string $location tus upload URL.
	 * @param string $path     Absolute file path.
	 * @param int    $size     File size in bytes.
	 * @return true|WP_Error
	 */
	private function send_chunks( $location, $path, $size ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$handle = fopen( $path, 'rb' );

		if ( ! $handle ) {
			return new WP_Error( 'ces_cf_read', __( 'Could not open the uploaded file for sending.', 'classic-edu-suite' ) );
		}

		$offset = 0;

		while ( $offset < $size ) {
			$chunk = fread( $handle, self::CHUNK );

			if ( false === $chunk || '' === $chunk ) {
				fclose( $handle );

				return new WP_Error( 'ces_cf_read', __( 'The upload stopped part-way through reading the file.', 'classic-edu-suite' ) );
			}

			$response = wp_remote_request(
				$location,
				array(
					'method'  => 'PATCH',
					'timeout' => 300,
					'headers' => array(
						'Authorization'  => 'Bearer ' . (string) CES_Helpers::setting( 'cloudflare_api_token', '' ),
						'Tus-Resumable'  => '1.0.0',
						'Upload-Offset'  => (string) $offset,
						'Content-Type'   => 'application/offset+octet-stream',
						'Content-Length' => (string) strlen( $chunk ),
					),
					'body'    => $chunk,
				)
			);

			if ( is_wp_error( $response ) ) {
				fclose( $handle );

				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 204 !== $code && 200 !== $code ) {
				fclose( $handle );

				CES_Helpers::log( 'LMS Cloudflare tus patch failed', array( 'code' => $code, 'offset' => $offset ) );

				return new WP_Error(
					'ces_cf_tus_patch',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Cloudflare rejected an upload chunk (HTTP %d).', 'classic-edu-suite' ),
						$code
					)
				);
			}

			$reported = (int) wp_remote_retrieve_header( $response, 'upload-offset' );
			$offset   = $reported > $offset ? $reported : $offset + strlen( $chunk );
		}

		fclose( $handle );
		// phpcs:enable

		return true;
	}

	/**
	 * Perform a JSON API call against Cloudflare.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Absolute URL.
	 * @param array  $args   Extra wp_remote_request arguments.
	 * @return array|WP_Error
	 */
	private function request( $method, $url, array $args = array() ) {
		$defaults = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . (string) CES_Helpers::setting( 'cloudflare_api_token', '' ),
				'Accept'        => 'application/json',
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

		$decoded = json_decode( $body, true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code < 200 || $code >= 300 ) {
			$detail = '';

			if ( ! empty( $decoded['errors'][0]['message'] ) ) {
				$detail = (string) $decoded['errors'][0]['message'];
			}

			CES_Helpers::log( 'LMS Cloudflare API error', array( 'code' => $code, 'body' => substr( $body, 0, 500 ) ) );

			return new WP_Error(
				'ces_cf_http',
				sprintf(
					/* translators: 1: HTTP status code, 2: provider error message. */
					__( 'Cloudflare Stream returned HTTP %1$d. %2$s', 'classic-edu-suite' ),
					$code,
					$detail
				)
			);
		}

		return $decoded;
	}
}
