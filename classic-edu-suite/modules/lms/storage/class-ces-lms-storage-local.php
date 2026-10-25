<?php
/**
 * Self-hosted storage driver. The working default for this site.
 *
 * Layout, all inside `wp-content/uploads/ces-private/`, which the activator
 * denies to direct HTTP requests:
 *
 *   source/<lesson>-<hash>.<ext>   the uploaded master file
 *   hls/<lesson>/master.m3u8       multi-rendition master playlist
 *   hls/<lesson>/720p.m3u8         one variant playlist per rendition
 *   hls/<lesson>/720p_00001.ts     segments
 *
 * Nothing in that tree is web-reachable. The only way a byte leaves the server
 * is through `CES_Lms_Stream`, which demands a valid signed token bound to the
 * requesting account.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Local disk driver.
 */
class CES_Lms_Storage_Local extends CES_Lms_Storage {

	/**
	 * Master playlist filename.
	 */
	const MASTER = 'master.m3u8';

	/**
	 * Driver key.
	 *
	 * @return string
	 */
	public function get_key() {
		return 'local';
	}

	/**
	 * Driver label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Self-hosted (this server)', 'classic-edu-suite' );
	}

	/**
	 * This driver packages HLS itself, when ffmpeg exists.
	 *
	 * @return bool
	 */
	public function needs_local_packaging() {
		return true;
	}

	/**
	 * Record an uploaded master file and queue it for packaging.
	 *
	 * @param int    $lesson_id   Lesson post id.
	 * @param string $source_path Absolute path inside ces-private/source/.
	 * @param array  $args        Optional original_name / mime.
	 * @return array|WP_Error
	 */
	public function upload( $lesson_id, $source_path, array $args = array() ) {
		$lesson_id = (int) $lesson_id;

		if ( $lesson_id <= 0 ) {
			return new WP_Error( 'ces_storage_lesson', __( 'No lesson was given for this upload.', 'classic-edu-suite' ) );
		}

		if ( '' === $source_path || ! file_exists( $source_path ) ) {
			return new WP_Error( 'ces_storage_missing_file', __( 'The uploaded file could not be found on the server.', 'classic-edu-suite' ) );
		}

		$dir  = CES_Lms_Paths::source_dir();
		$real = realpath( $source_path );
		$base = $dir ? realpath( $dir ) : false;

		if ( false === $real || false === $base ) {
			return new WP_Error( 'ces_storage_path', __( 'The private storage directory is not available.', 'classic-edu-suite' ) );
		}

		$normal_base = trailingslashit( str_replace( '\\', '/', $base ) );
		$normal_real = str_replace( '\\', '/', $real );

		if ( 0 !== strpos( $normal_real, $normal_base ) ) {
			return new WP_Error( 'ces_storage_outside', __( 'Refusing to register a file outside the private storage directory.', 'classic-edu-suite' ) );
		}

		// Replacing a video: clear the previous packaging output first.
		$this->clear_hls( $lesson_id );

		$previous = (string) get_post_meta( $lesson_id, CES_Lms_Meta::SOURCE, true );

		if ( '' !== $previous && basename( $previous ) !== basename( $real ) ) {
			$old = CES_Lms_Paths::resolve_within( $dir, CES_Lms_Paths::sanitize_media_name( basename( $previous ) ) );

			if ( '' !== $old ) {
				wp_delete_file( $old );
			}
		}

		$size = (int) filesize( $real );
		$mime = isset( $args['mime'] ) ? sanitize_text_field( (string) $args['mime'] ) : '';

		if ( '' === $mime ) {
			$check = wp_check_filetype( basename( $real ) );
			$mime  = ! empty( $check['type'] ) ? $check['type'] : 'video/mp4';
		}

		update_post_meta( $lesson_id, CES_Lms_Meta::SOURCE, basename( $real ) );
		update_post_meta( $lesson_id, CES_Lms_Meta::FILESIZE, $size );
		update_post_meta( $lesson_id, CES_Lms_Meta::MIME, $mime );
		update_post_meta( $lesson_id, CES_Lms_Meta::DRIVER, $this->get_key() );

		if ( ! empty( $args['original_name'] ) ) {
			update_post_meta( $lesson_id, CES_Lms_Meta::SOURCE_NAME, sanitize_file_name( (string) $args['original_name'] ) );
		}

		delete_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_ID );
		delete_post_meta( $lesson_id, CES_Lms_Meta::REMOTE_DATA );

		return array(
			'driver' => $this->get_key(),
			'file'   => basename( $real ),
			'bytes'  => $size,
			'mime'   => $mime,
		);
	}

	/**
	 * Mint a signed playback URL on our own stream endpoint.
	 *
	 * @param int   $lesson_id Lesson post id.
	 * @param array $args      user_id / sid.
	 * @return array|WP_Error
	 */
	public function get_playlist_url( $lesson_id, array $args = array() ) {
		$lesson_id = (int) $lesson_id;
		$user_id   = $this->arg_user_id( $args );
		$sid       = $this->arg_sid( $args );

		if ( $user_id <= 0 ) {
			return new WP_Error( 'ces_storage_user', __( 'A playback URL can only be issued to a signed-in student.', 'classic-edu-suite' ), array( 'status' => 401 ) );
		}

		$status = CES_Lms_Meta::status( $lesson_id );

		if ( CES_Lms_Meta::STATUS_READY === $status && '' !== $this->master_path( $lesson_id ) ) {
			$ttl   = CES_Lms_Token::ttl_for( CES_Lms_Token::SCOPE_PLAYLIST, $lesson_id );
			$token = CES_Lms_Token::issue( $lesson_id, $user_id, CES_Lms_Token::SCOPE_PLAYLIST, $sid, $ttl );

			if ( '' === $token ) {
				return new WP_Error( 'ces_storage_token', __( 'Could not issue a playback token.', 'classic-edu-suite' ), array( 'status' => 500 ) );
			}

			return array(
				'kind'      => self::PLAYBACK_HLS,
				'url'       => CES_Lms_Stream::build_url( CES_Lms_Stream::ACTION_PLAYLIST, $lesson_id, self::MASTER, $token ),
				'mime'      => 'application/vnd.apple.mpegurl',
				'use_hlsjs' => true,
				'expires'   => time() + $ttl,
				'sid'       => $sid,
			);
		}

		if ( CES_Lms_Meta::STATUS_SOURCE_ONLY === $status && '' !== CES_Lms_Paths::source_path( $lesson_id ) ) {
			$ttl   = CES_Lms_Token::ttl_for( CES_Lms_Token::SCOPE_FILE, $lesson_id );
			$token = CES_Lms_Token::issue( $lesson_id, $user_id, CES_Lms_Token::SCOPE_FILE, $sid, $ttl );

			if ( '' === $token ) {
				return new WP_Error( 'ces_storage_token', __( 'Could not issue a playback token.', 'classic-edu-suite' ), array( 'status' => 500 ) );
			}

			$mime = (string) get_post_meta( $lesson_id, CES_Lms_Meta::MIME, true );

			return array(
				'kind'      => self::PLAYBACK_PROGRESSIVE,
				'url'       => CES_Lms_Stream::build_url( CES_Lms_Stream::ACTION_FILE, $lesson_id, '', $token ),
				'mime'      => '' === $mime ? 'video/mp4' : $mime,
				'use_hlsjs' => false,
				'expires'   => time() + $ttl,
				'sid'       => $sid,
			);
		}

		return new WP_Error(
			'ces_storage_not_ready',
			$this->not_ready_message( $status ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Status report for admin screens and the REST layer.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return array
	 */
	public function get_status( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$status    = CES_Lms_Meta::status( $lesson_id );

		$renditions = CES_Lms_Meta::renditions( $lesson_id );
		$pending    = get_post_meta( $lesson_id, CES_Lms_Meta::PENDING, true );
		$pending    = is_array( $pending ) ? $pending : array();

		$segments = 0;
		$bytes    = 0;
		$dir      = CES_Lms_Paths::lesson_hls_dir( $lesson_id );

		if ( $dir && is_dir( $dir ) ) {
			$found = glob( $dir . '*.ts' );

			if ( is_array( $found ) ) {
				$segments = count( $found );

				foreach ( $found as $file ) {
					$bytes += (int) filesize( $file );
				}
			}
		}

		return array(
			'status'   => $status,
			'label'    => CES_Lms_Meta::status_label( $status ),
			'error'    => (string) get_post_meta( $lesson_id, CES_Lms_Meta::ERROR, true ),
			'playable' => in_array( $status, CES_Lms_Meta::playable_statuses(), true ),
			'detail'   => array(
				'driver'       => $this->get_key(),
				'source'       => (string) get_post_meta( $lesson_id, CES_Lms_Meta::SOURCE_NAME, true ),
				'source_bytes' => (int) get_post_meta( $lesson_id, CES_Lms_Meta::FILESIZE, true ),
				'renditions'   => $renditions,
				'pending'      => $pending,
				'segments'     => $segments,
				'hls_bytes'    => $bytes,
				'duration'     => CES_Lms_Meta::duration( $lesson_id ),
			),
		);
	}

	/**
	 * Delete source file, HLS output and all related meta.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return bool
	 */
	public function delete( $lesson_id ) {
		$lesson_id = (int) $lesson_id;

		$this->clear_hls( $lesson_id );

		$source = CES_Lms_Paths::source_path( $lesson_id );

		if ( '' !== $source ) {
			wp_delete_file( $source );
		}

		foreach (
			array(
				CES_Lms_Meta::SOURCE,
				CES_Lms_Meta::SOURCE_NAME,
				CES_Lms_Meta::FILESIZE,
				CES_Lms_Meta::MIME,
				CES_Lms_Meta::RENDITIONS,
				CES_Lms_Meta::PENDING,
				CES_Lms_Meta::ERROR,
			) as $key
		) {
			delete_post_meta( $lesson_id, $key );
		}

		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_NONE );

		return true;
	}

	/**
	 * Remove just the packaged HLS output, keeping the source file.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return void
	 */
	public function clear_hls( $lesson_id ) {
		$dir = CES_Lms_Paths::lesson_hls_dir( (int) $lesson_id );

		if ( $dir && is_dir( $dir ) ) {
			CES_Lms_Paths::delete_dir( $dir );
		}

		delete_post_meta( (int) $lesson_id, CES_Lms_Meta::RENDITIONS );
		delete_post_meta( (int) $lesson_id, CES_Lms_Meta::PENDING );
	}

	/**
	 * Absolute path to a lesson's master playlist, or '' when absent.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return string
	 */
	public function master_path( $lesson_id ) {
		$dir = CES_Lms_Paths::lesson_hls_dir( (int) $lesson_id );

		if ( ! $dir ) {
			return '';
		}

		$path = $dir . self::MASTER;

		return is_file( $path ) ? $path : '';
	}

	/**
	 * Student-facing explanation for a lesson that cannot play yet.
	 *
	 * @param string $status Current status.
	 * @return string
	 */
	private function not_ready_message( $status ) {
		switch ( $status ) {
			case CES_Lms_Meta::STATUS_QUEUED:
			case CES_Lms_Meta::STATUS_PROCESSING:
				return __( 'This lesson is still being prepared. Please check back in a few minutes.', 'classic-edu-suite' );

			case CES_Lms_Meta::STATUS_FAILED:
				return __( 'This lesson could not be prepared. Our team has been notified.', 'classic-edu-suite' );

			default:
				return __( 'This lesson does not have a video yet.', 'classic-edu-suite' );
		}
	}
}
