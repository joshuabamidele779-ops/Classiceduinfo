<?php
/**
 * Chunked video upload: the admin page, the lesson metabox panel and the
 * AJAX endpoints behind both.
 *
 * Why chunks at all: shared LiteSpeed hosting caps `upload_max_filesize` and
 * `post_max_size` at values far below a one-hour lesson recording, and the
 * site owner cannot edit php.ini. Slicing the file in the browser and
 * appending each slice server-side sidesteps both limits without asking the
 * host for anything. It also survives the thing that actually happens on a
 * Nigerian office connection: the upload dying at 80% and needing to resume
 * rather than start again.
 *
 * Nothing here trusts the browser. The capability is checked on every call,
 * the nonce is checked on every call, the upload state lives server-side in a
 * transient keyed to the uploading user, the assembled byte count is compared
 * against the declared size, and the finished file's type is sniffed from its
 * own bytes before it is ever registered against a lesson.
 *
 * @package ClassicEduSuite\Lms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chunked uploader for lesson video.
 */
class CES_Lms_Admin_Upload {

	/**
	 * Nonce action shared by every upload call.
	 */
	const NONCE = 'ces_lms_upload';

	/**
	 * Capability required to upload or delete lesson video.
	 */
	const CAP = 'ces_manage_lms';

	/**
	 * Transient key prefix holding one in-progress upload's state.
	 */
	const STATE_PREFIX = 'ces_lms_up_';

	/**
	 * How long a half-finished upload is allowed to sit before it is swept.
	 */
	const STATE_TTL = 21600; // 6 hours.

	/**
	 * Hard ceiling on one upload, in bytes, before the `ces_lms_max_upload`
	 * filter gets a say. 4 GB is more than any lesson should ever be.
	 */
	const MAX_CEILING = 4294967296;

	/**
	 * Register the AJAX endpoints.
	 *
	 * Called from `CES_Lms_Module::init()` inside its `is_admin()` branch;
	 * `admin-ajax.php` counts as admin, so this is where the handlers belong.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_ces_lms_upload_begin', array( __CLASS__, 'ajax_begin' ) );
		add_action( 'wp_ajax_ces_lms_upload_chunk', array( __CLASS__, 'ajax_chunk' ) );
		add_action( 'wp_ajax_ces_lms_upload_finish', array( __CLASS__, 'ajax_finish' ) );
		add_action( 'wp_ajax_ces_lms_upload_abort', array( __CLASS__, 'ajax_abort' ) );
		add_action( 'wp_ajax_ces_lms_video_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'wp_ajax_ces_lms_video_requeue', array( __CLASS__, 'ajax_requeue' ) );
	}

	// ------------------------------------------------------------------
	// Limits and types.
	// ------------------------------------------------------------------

	/**
	 * Bytes to send per chunk.
	 *
	 * Derived from whatever this host actually allows, then clamped. The
	 * margin matters: a multipart POST carries the nonce, the action and the
	 * part boundaries as well as the slice itself, so a chunk sized exactly at
	 * `post_max_size` is rejected by PHP before any of our code runs.
	 *
	 * @return int
	 */
	public static function chunk_size() {
		$upload = wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) );
		$post   = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		$limits = array_filter( array( $upload, $post ) );
		$limit  = empty( $limits ) ? 2097152 : min( $limits );

		// Leave 256 KB of headroom for the rest of the request.
		$chunk = $limit - 262144;

		if ( $chunk < 262144 ) {
			$chunk = 262144;
		}

		if ( $chunk > 4194304 ) {
			$chunk = 4194304;
		}

		/**
		 * Filter the upload chunk size in bytes.
		 *
		 * @param int $chunk Chunk size.
		 */
		return (int) apply_filters( 'ces_lms_chunk_size', (int) $chunk );
	}

	/**
	 * Largest single upload allowed, in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		/**
		 * Filter the maximum lesson video size in bytes.
		 *
		 * @param int $max Maximum size.
		 */
		$max = (int) apply_filters( 'ces_lms_max_upload', 2147483648 );

		if ( $max <= 0 || $max > self::MAX_CEILING ) {
			$max = self::MAX_CEILING;
		}

		return $max;
	}

	/**
	 * Accepted source extensions mapped to the MIME type we record.
	 *
	 * Matroska is accepted because ffmpeg reads it happily, but a browser will
	 * not play it directly: without ffmpeg on the host such a lesson can only
	 * end up `source_only`, which is unplayable. `finalize()` refuses that
	 * combination rather than leaving an admin with a silently broken lesson.
	 *
	 * @return array Extension => MIME type.
	 */
	public static function allowed_types() {
		return array(
			'mp4'  => 'video/mp4',
			'm4v'  => 'video/x-m4v',
			'mov'  => 'video/quicktime',
			'webm' => 'video/webm',
			'mkv'  => 'video/x-matroska',
		);
	}

	/**
	 * Extensions a browser can play without transcoding.
	 *
	 * @return string[]
	 */
	public static function browser_playable() {
		return array( 'mp4', 'm4v', 'webm', 'mov' );
	}

	// ------------------------------------------------------------------
	// Shared request guards.
	// ------------------------------------------------------------------

	/**
	 * Check the nonce and capability, or end the request.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'classic-edu-suite' ) ), 403 );
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to upload video.', 'classic-edu-suite' ) ), 403 );
		}
	}

	/**
	 * Validate that a post id is a lesson the current user may edit.
	 *
	 * @param int $lesson_id Candidate id.
	 * @return int The id, or 0.
	 */
	private static function valid_lesson( $lesson_id ) {
		$lesson_id = (int) $lesson_id;

		if ( $lesson_id <= 0 || CES_Lms_Cpt::LESSON !== get_post_type( $lesson_id ) ) {
			return 0;
		}

		if ( ! current_user_can( 'edit_post', $lesson_id ) ) {
			return 0;
		}

		return $lesson_id;
	}

	/**
	 * Transient key for one upload id.
	 *
	 * @param string $uid Upload id.
	 * @return string
	 */
	private static function state_key( $uid ) {
		return self::STATE_PREFIX . $uid;
	}

	/**
	 * Read an upload's state, confirming it belongs to this user.
	 *
	 * @param string $uid Upload id.
	 * @return array|null
	 */
	private static function get_state( $uid ) {
		$uid = self::sanitize_uid( $uid );

		if ( '' === $uid ) {
			return null;
		}

		$state = get_transient( self::state_key( $uid ) );

		if ( ! is_array( $state ) || empty( $state['user_id'] ) ) {
			return null;
		}

		// One admin cannot resume or poison another admin's upload.
		if ( (int) $state['user_id'] !== get_current_user_id() ) {
			return null;
		}

		return $state;
	}

	/**
	 * Persist an upload's state.
	 *
	 * @param string $uid   Upload id.
	 * @param array  $state State.
	 * @return void
	 */
	private static function put_state( $uid, array $state ) {
		set_transient( self::state_key( $uid ), $state, self::STATE_TTL );
	}

	/**
	 * Forget an upload and delete its partial file.
	 *
	 * @param string $uid Upload id.
	 * @return void
	 */
	private static function clear_state( $uid ) {
		$uid = self::sanitize_uid( $uid );

		if ( '' === $uid ) {
			return;
		}

		$path = self::tmp_path( $uid );

		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		delete_transient( self::state_key( $uid ) );
	}

	/**
	 * Narrow an upload id to the shape we generate.
	 *
	 * @param string $uid Candidate.
	 * @return string Clean id, or ''.
	 */
	private static function sanitize_uid( $uid ) {
		$uid = strtolower( (string) $uid );

		return preg_match( '/^[a-f0-9]{32}$/', $uid ) ? $uid : '';
	}

	/**
	 * Absolute path of the partial file for an upload.
	 *
	 * @param string $uid Upload id.
	 * @return string Path, or '' when private storage is unavailable.
	 */
	private static function tmp_path( $uid ) {
		$uid = self::sanitize_uid( $uid );
		$dir = CES_Lms_Paths::tmp_dir();

		if ( '' === $uid || '' === $dir ) {
			return '';
		}

		return $dir . $uid . '.part';
	}

	// ------------------------------------------------------------------
	// AJAX: begin.
	// ------------------------------------------------------------------

	/**
	 * Open an upload session.
	 *
	 * Accepts either an existing lesson id or a new lesson title, so the
	 * standalone upload screen can create the lesson as a draft and attach the
	 * video in one pass.
	 *
	 * @return void
	 */
	public static function ajax_begin() {
		self::guard();

		if ( ! CES_Lms_Paths::ensure() ) {
			wp_send_json_error(
				array( 'message' => __( 'The private video directory could not be created. Check the permissions on wp-content/uploads.', 'classic-edu-suite' ) ),
				500
			);
		}

		$lesson_id = self::valid_lesson( isset( $_POST['lesson_id'] ) ? (int) $_POST['lesson_id'] : 0 );
		$new_title = isset( $_POST['new_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['new_title'] ) ) : '';

		if ( ! $lesson_id && '' !== $new_title ) {
			$lesson_id = self::create_lesson( $new_title );

			if ( is_wp_error( $lesson_id ) ) {
				wp_send_json_error( array( 'message' => $lesson_id->get_error_message() ), 400 );
			}
		}

		if ( ! $lesson_id ) {
			wp_send_json_error( array( 'message' => __( 'Choose a lesson to attach this video to, or give a title for a new one.', 'classic-edu-suite' ) ), 400 );
		}

		$name = isset( $_POST['name'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['name'] ) ) : '';
		$size = isset( $_POST['size'] ) ? (int) $_POST['size'] : 0;
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		$allowed = self::allowed_types();

		if ( '' === $name || ! isset( $allowed[ $ext ] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'That file type is not accepted. Use MP4, MOV, MKV, WebM or M4V.', 'classic-edu-suite' ) ),
				400
			);
		}

		if ( $size <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'That file appears to be empty.', 'classic-edu-suite' ) ), 400 );
		}

		if ( $size > self::max_bytes() ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: human-readable size limit. */
						__( 'That file is larger than the %s limit.', 'classic-edu-suite' ),
						size_format( self::max_bytes() )
					),
				),
				400
			);
		}

		$uid = substr( hash( 'sha256', wp_generate_password( 32, false, false ) . microtime( true ) ), 0, 32 );

		$path = self::tmp_path( $uid );

		if ( '' === $path ) {
			wp_send_json_error( array( 'message' => __( 'The private video directory is not available.', 'classic-edu-suite' ) ), 500 );
		}

		// Start from empty: a stale file from a crashed upload must not be
		// mistaken for progress on this one.
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		self::put_state(
			$uid,
			array(
				'user_id'   => get_current_user_id(),
				'lesson_id' => $lesson_id,
				'name'      => $name,
				'ext'       => $ext,
				'size'      => $size,
				'written'   => 0,
				'started'   => time(),
			)
		);

		wp_send_json_success(
			array(
				'uid'       => $uid,
				'lessonId'  => $lesson_id,
				'chunkSize' => self::chunk_size(),
			)
		);
	}

	/**
	 * Create a draft lesson to hang a new upload on.
	 *
	 * @param string $title Lesson title.
	 * @return int|WP_Error New lesson id.
	 */
	private static function create_lesson( $title ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'ces_lms_cap', __( 'You cannot create lessons.', 'classic-edu-suite' ) );
		}

		$lesson_id = wp_insert_post(
			array(
				'post_type'   => CES_Lms_Cpt::LESSON,
				'post_title'  => $title,
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $lesson_id ) ) {
			return $lesson_id;
		}

		return (int) $lesson_id;
	}

	// ------------------------------------------------------------------
	// AJAX: chunk.
	// ------------------------------------------------------------------

	/**
	 * Append one chunk to the partial file.
	 *
	 * The client sends an explicit byte offset and we refuse anything that
	 * does not match what we have already written. That makes a retried chunk
	 * idempotent and a duplicated or out-of-order chunk an error rather than a
	 * corrupted video.
	 *
	 * @return void
	 */
	public static function ajax_chunk() {
		self::guard();

		$uid   = isset( $_POST['uid'] ) ? (string) $_POST['uid'] : '';
		$state = self::get_state( $uid );

		if ( ! $state ) {
			wp_send_json_error( array( 'message' => __( 'That upload is no longer active. Start it again.', 'classic-edu-suite' ) ), 410 );
		}

		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : -1;

		if ( $offset !== (int) $state['written'] ) {
			// Already have this chunk: tell the client where we really are.
			wp_send_json_success(
				array(
					'written'   => (int) $state['written'],
					'duplicate' => true,
				)
			);
		}

		if ( empty( $_FILES['chunk'] ) || ! isset( $_FILES['chunk']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'That chunk did not arrive. Retrying.', 'classic-edu-suite' ) ), 400 );
		}

		$error = isset( $_FILES['chunk']['error'] ) ? (int) $_FILES['chunk']['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error ) {
			wp_send_json_error( array( 'message' => self::upload_error_message( $error ) ), 400 );
		}

		$tmp = isset( $_FILES['chunk']['tmp_name'] ) ? (string) $_FILES['chunk']['tmp_name'] : '';

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'That chunk could not be read.', 'classic-edu-suite' ) ), 400 );
		}

		$bytes = (int) filesize( $tmp );

		if ( $bytes <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'That chunk was empty.', 'classic-edu-suite' ) ), 400 );
		}

		if ( (int) $state['written'] + $bytes > (int) $state['size'] ) {
			self::clear_state( $uid );
			wp_send_json_error( array( 'message' => __( 'The upload sent more data than it declared and has been discarded.', 'classic-edu-suite' ) ), 400 );
		}

		$path = self::tmp_path( $uid );

		if ( '' === $path ) {
			wp_send_json_error( array( 'message' => __( 'The private video directory is not available.', 'classic-edu-suite' ) ), 500 );
		}

		$in = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $in ) {
			wp_send_json_error( array( 'message' => __( 'That chunk could not be opened.', 'classic-edu-suite' ) ), 500 );
		}

		$out = fopen( $path, 0 === (int) $state['written'] ? 'wb' : 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $out ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_send_json_error( array( 'message' => __( 'The server could not write the upload to disk.', 'classic-edu-suite' ) ), 500 );
		}

		$copied = 0;

		while ( ! feof( $in ) ) {
			$buffer = fread( $in, 262144 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread

			if ( false === $buffer || '' === $buffer ) {
				break;
			}

			$written = fwrite( $out, $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			if ( false === $written ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				wp_send_json_error( array( 'message' => __( 'The server ran out of room while writing the upload.', 'classic-edu-suite' ) ), 500 );
			}

			$copied += $written;
		}

		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$state['written'] = (int) $state['written'] + $copied;

		self::put_state( $uid, $state );

		wp_send_json_success(
			array(
				'written' => (int) $state['written'],
				'size'    => (int) $state['size'],
			)
		);
	}

	/**
	 * Human message for a PHP upload error code.
	 *
	 * @param int $code One of the UPLOAD_ERR_* constants.
	 * @return string
	 */
	private static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'That chunk was larger than this server accepts. Reload the page so the chunk size is recalculated.', 'classic-edu-suite' );

			case UPLOAD_ERR_PARTIAL:
				return __( 'That chunk arrived incomplete. Retrying.', 'classic-edu-suite' );

			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server has nowhere to put uploads. Ask the host to check the PHP temporary directory.', 'classic-edu-suite' );

			default:
				return __( 'That chunk could not be received.', 'classic-edu-suite' );
		}
	}

	// ------------------------------------------------------------------
	// AJAX: finish.
	// ------------------------------------------------------------------

	/**
	 * Close the upload: validate, register and queue the file.
	 *
	 * @return void
	 */
	public static function ajax_finish() {
		self::guard();

		$uid   = isset( $_POST['uid'] ) ? (string) $_POST['uid'] : '';
		$state = self::get_state( $uid );

		if ( ! $state ) {
			wp_send_json_error( array( 'message' => __( 'That upload is no longer active. Start it again.', 'classic-edu-suite' ) ), 410 );
		}

		$result = self::finalize( $uid, $state );

		if ( is_wp_error( $result ) ) {
			self::clear_state( $uid );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Move a completed upload into private storage and hand it to the driver.
	 *
	 * @param string $uid   Upload id.
	 * @param array  $state Upload state.
	 * @return array|WP_Error Summary for the admin UI.
	 */
	private static function finalize( $uid, array $state ) {
		$lesson_id = self::valid_lesson( $state['lesson_id'] );

		if ( ! $lesson_id ) {
			return new WP_Error( 'ces_lms_lesson', __( 'That lesson no longer exists.', 'classic-edu-suite' ) );
		}

		$path = self::tmp_path( $uid );

		if ( '' === $path || ! is_file( $path ) ) {
			return new WP_Error( 'ces_lms_partial', __( 'The uploaded data could not be found on the server.', 'classic-edu-suite' ) );
		}

		$actual = (int) filesize( $path );

		if ( $actual !== (int) $state['size'] ) {
			return new WP_Error(
				'ces_lms_size',
				__( 'The upload finished short of its declared size, so it has been discarded. Please try again.', 'classic-edu-suite' )
			);
		}

		$ext     = (string) $state['ext'];
		$allowed = self::allowed_types();

		if ( ! isset( $allowed[ $ext ] ) ) {
			return new WP_Error( 'ces_lms_type', __( 'That file type is not accepted.', 'classic-edu-suite' ) );
		}

		/*
		 * Sniff the real type from the assembled bytes. The filename was
		 * checked at `begin`, but the filename is only the client's claim;
		 * this is the file itself.
		 *
		 * `wp_check_filetype_and_ext()` returns a false type when the bytes
		 * disagree with the extension — but it also returns false for an
		 * extension WordPress simply does not know, and Matroska is not in the
		 * default mime list. So a false type is a rejection only for the
		 * extensions WordPress does recognise; for the rest we fall back to
		 * our own allow-list, which is as far as this check can honestly go.
		 */
		$known   = wp_get_mime_types();
		$is_known = false;

		foreach ( array_keys( $known ) as $pattern ) {
			if ( in_array( $ext, explode( '|', $pattern ), true ) ) {
				$is_known = true;
				break;
			}
		}

		$sniffed = wp_check_filetype_and_ext( $path, 'video.' . $ext );
		$mime    = ! empty( $sniffed['type'] ) ? (string) $sniffed['type'] : '';

		if ( '' === $mime && $is_known ) {
			return new WP_Error(
				'ces_lms_not_video',
				__( 'That file does not look like the video its name claims. Upload an MP4, MOV, MKV, WebM or M4V file.', 'classic-edu-suite' )
			);
		}

		if ( '' === $mime ) {
			$mime = $allowed[ $ext ];
		}

		if ( 0 !== strpos( $mime, 'video/' ) ) {
			return new WP_Error(
				'ces_lms_not_video',
				__( 'That file is not a video. Upload an MP4, MOV, MKV, WebM or M4V file.', 'classic-edu-suite' )
			);
		}

		$driver = CES_Lms_Storage_Factory::active();

		if ( ! $driver ) {
			return new WP_Error( 'ces_lms_driver', __( 'No video storage driver is configured.', 'classic-edu-suite' ) );
		}

		$config = $driver->check_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$caps        = CES_Lms_Transcode::capabilities();
		$can_package = $driver->needs_local_packaging() && '' !== $caps['ffmpeg'];

		/*
		 * Matroska cannot play in a browser. With ffmpeg it becomes HLS and is
		 * fine; without it the lesson would sit in `source_only` and silently
		 * fail for every student, so refuse it here where the admin can still
		 * do something about it.
		 */
		if ( ! $can_package && ! in_array( $ext, self::browser_playable(), true ) ) {
			return new WP_Error(
				'ces_lms_needs_ffmpeg',
				__( 'This server has no ffmpeg, so it can only stream files a browser plays directly. Convert this video to MP4 and upload that instead.', 'classic-edu-suite' )
			);
		}

		// Give the stored file a name that reveals nothing and cannot collide.
		$final_name = sprintf( 'lesson-%d-%s.%s', $lesson_id, substr( $uid, 0, 12 ), $ext );
		$final_name = CES_Lms_Paths::sanitize_media_name( $final_name );

		if ( '' === $final_name ) {
			return new WP_Error( 'ces_lms_name', __( 'The stored filename could not be built.', 'classic-edu-suite' ) );
		}

		$source_dir = CES_Lms_Paths::source_dir();

		if ( '' === $source_dir ) {
			return new WP_Error( 'ces_lms_paths', __( 'The private video directory is not available.', 'classic-edu-suite' ) );
		}

		$destination = $source_dir . $final_name;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $path, $destination ) ) {
			return new WP_Error(
				'ces_lms_move',
				__( 'The finished upload could not be moved into private storage.', 'classic-edu-suite' )
			);
		}

		delete_transient( self::state_key( self::sanitize_uid( $uid ) ) );

		$stored = $driver->upload(
			$lesson_id,
			$destination,
			array(
				'original_name' => (string) $state['name'],
				'mime'          => $mime,
			)
		);

		if ( is_wp_error( $stored ) ) {
			// The driver refused it, so do not leave the bytes lying around.
			if ( file_exists( $destination ) ) {
				wp_delete_file( $destination );
			}

			return $stored;
		}

		self::set_post_upload_status( $lesson_id, $driver, $can_package );

		$status = CES_Lms_Meta::status( $lesson_id );

		return array(
			'lessonId'  => $lesson_id,
			'status'    => $status,
			'label'     => CES_Lms_Meta::status_label( $status ),
			'bytes'     => $actual,
			'size'      => size_format( $actual ),
			'editLink'  => (string) get_edit_post_link( $lesson_id, 'raw' ),
			'queued'    => CES_Lms_Meta::STATUS_QUEUED === $status,
		);
	}

	/**
	 * Decide what happens to a lesson right after its bytes land.
	 *
	 * @param int              $lesson_id   Lesson post id.
	 * @param CES_Lms_Storage  $driver      Active storage driver.
	 * @param bool             $can_package Whether local HLS packaging is possible.
	 * @return void
	 */
	private static function set_post_upload_status( $lesson_id, $driver, $can_package ) {
		if ( $can_package ) {
			// Sets the status to queued and nudges cron.
			CES_Lms_Transcode::enqueue( $lesson_id, true );

			return;
		}

		if ( $driver->needs_local_packaging() ) {
			// Local storage, no ffmpeg: the single file is the deliverable.
			CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_SOURCE_ONLY );

			return;
		}

		// A remote provider is transcoding on its own side.
		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_REMOTE );
	}

	// ------------------------------------------------------------------
	// AJAX: abort, delete, requeue.
	// ------------------------------------------------------------------

	/**
	 * Throw away an upload in progress.
	 *
	 * @return void
	 */
	public static function ajax_abort() {
		self::guard();

		$uid = isset( $_POST['uid'] ) ? (string) $_POST['uid'] : '';

		if ( self::get_state( $uid ) ) {
			self::clear_state( $uid );
		}

		wp_send_json_success( array( 'aborted' => true ) );
	}

	/**
	 * Delete a lesson's video entirely.
	 *
	 * @return void
	 */
	public static function ajax_delete() {
		self::guard();

		$lesson_id = self::valid_lesson( isset( $_POST['lesson_id'] ) ? (int) $_POST['lesson_id'] : 0 );

		if ( ! $lesson_id ) {
			wp_send_json_error( array( 'message' => __( 'That lesson does not exist.', 'classic-edu-suite' ) ), 404 );
		}

		$driver = CES_Lms_Storage_Factory::for_lesson( $lesson_id );

		if ( $driver ) {
			$deleted = $driver->delete( $lesson_id );

			if ( is_wp_error( $deleted ) ) {
				wp_send_json_error( array( 'message' => $deleted->get_error_message() ), 500 );
			}
		}

		CES_Lms_Transcode::dequeue( $lesson_id );
		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_NONE );

		foreach ( array( CES_Lms_Meta::DURATION, CES_Lms_Meta::FILESIZE, CES_Lms_Meta::SOURCE_NAME ) as $key ) {
			delete_post_meta( $lesson_id, $key );
		}

		wp_send_json_success(
			array(
				'status' => CES_Lms_Meta::STATUS_NONE,
				'label'  => CES_Lms_Meta::status_label( CES_Lms_Meta::STATUS_NONE ),
			)
		);
	}

	/**
	 * Put an existing lesson back in the packaging queue.
	 *
	 * @return void
	 */
	public static function ajax_requeue() {
		self::guard();

		$lesson_id = self::valid_lesson( isset( $_POST['lesson_id'] ) ? (int) $_POST['lesson_id'] : 0 );

		if ( ! $lesson_id ) {
			wp_send_json_error( array( 'message' => __( 'That lesson does not exist.', 'classic-edu-suite' ) ), 404 );
		}

		if ( '' === CES_Lms_Paths::source_path( $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This lesson has no source file to package.', 'classic-edu-suite' ) ), 400 );
		}

		if ( '' === CES_Lms_Transcode::capabilities()['ffmpeg'] ) {
			wp_send_json_error( array( 'message' => __( 'There is no ffmpeg on this server, so there is nothing to queue.', 'classic-edu-suite' ) ), 400 );
		}

		CES_Lms_Transcode::enqueue( $lesson_id, true );

		$status = CES_Lms_Meta::status( $lesson_id );

		wp_send_json_success(
			array(
				'status' => $status,
				'label'  => CES_Lms_Meta::status_label( $status ),
			)
		);
	}

	// ------------------------------------------------------------------
	// Rendering: the lesson metabox panel.
	// ------------------------------------------------------------------

	/**
	 * The video panel shown inside the lesson editor.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return void
	 */
	public static function render_panel( $lesson_id ) {
		$lesson_id = (int) $lesson_id;

		if ( ! current_user_can( self::CAP ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to manage lesson video.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		$status = CES_Lms_Meta::status( $lesson_id );
		$caps   = CES_Lms_Transcode::capabilities();

		echo '<div class="ces-up" data-ces-upload="' . esc_attr( (string) wp_json_encode( array( 'lessonId' => $lesson_id ) ) ) . '">';

		self::render_current( $lesson_id, $status );
		self::render_dropzone();
		self::render_actions( $lesson_id, $status, $caps );

		echo '</div>';
	}

	/**
	 * The "what is attached right now" block.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param string $status    Current status.
	 * @return void
	 */
	private static function render_current( $lesson_id, $status ) {
		$name     = (string) get_post_meta( $lesson_id, CES_Lms_Meta::SOURCE_NAME, true );
		$bytes    = (int) get_post_meta( $lesson_id, CES_Lms_Meta::FILESIZE, true );
		$duration = CES_Lms_Meta::duration( $lesson_id );
		$error    = (string) get_post_meta( $lesson_id, CES_Lms_Meta::ERROR, true );

		echo '<p class="ces-up__status">';
		echo '<span class="ces-pill ces-pill--' . esc_attr( $status ) . '" data-role="status-pill">'
			. esc_html( CES_Lms_Meta::status_label( $status ) ) . '</span>';
		echo '</p>';

		if ( CES_Lms_Meta::STATUS_NONE === $status ) {
			echo '<p class="ces-up__hint">' . esc_html__( 'No video attached yet.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		echo '<ul class="ces-up__facts">';

		if ( '' !== $name ) {
			echo '<li><strong>' . esc_html__( 'File', 'classic-edu-suite' ) . ':</strong> ' . esc_html( $name ) . '</li>';
		}

		if ( $bytes > 0 ) {
			echo '<li><strong>' . esc_html__( 'Size', 'classic-edu-suite' ) . ':</strong> ' . esc_html( size_format( $bytes ) ) . '</li>';
		}

		echo '<li><strong>' . esc_html__( 'Length', 'classic-edu-suite' ) . ':</strong> '
			. ( $duration > 0 ? esc_html( CES_Lms_Meta::format_duration( $duration ) ) : esc_html__( 'unknown', 'classic-edu-suite' ) )
			. '</li>';

		$renditions = CES_Lms_Meta::renditions( $lesson_id );

		if ( ! empty( $renditions ) ) {
			$names = array();

			foreach ( $renditions as $rendition ) {
				if ( ! empty( $rendition['name'] ) ) {
					$names[] = (string) $rendition['name'];
				}
			}

			if ( ! empty( $names ) ) {
				echo '<li><strong>' . esc_html__( 'Qualities', 'classic-edu-suite' ) . ':</strong> '
					. esc_html( implode( ', ', $names ) ) . '</li>';
			}
		}

		echo '</ul>';

		if ( '' !== $error ) {
			echo '<p class="ces-up__error">' . esc_html( $error ) . '</p>';
		}
	}

	/**
	 * The file picker and progress bar.
	 *
	 * @return void
	 */
	private static function render_dropzone() {
		echo '<div class="ces-up__drop" data-role="drop">';

		echo '<p class="ces-up__drop-text">' . esc_html__( 'Drop a video here, or', 'classic-edu-suite' ) . '</p>';

		// The input is wrapped by the label rather than linked with `for`, so
		// it needs no id and still works when two panels share a page.
		echo '<p><label class="button ces-up__pick">';
		echo '<input type="file" class="ces-up__input" data-role="input" accept="'
			. esc_attr( '.' . implode( ',.', array_keys( self::allowed_types() ) ) ) . '" />';
		echo esc_html__( 'Choose a video file', 'classic-edu-suite' );
		echo '</label></p>';

		echo '<p class="ces-up__limits">' . esc_html(
			sprintf(
				/* translators: 1: maximum upload size, 2: chunk size. */
				__( 'Up to %1$s, sent in %2$s pieces so your host\'s upload limit does not matter.', 'classic-edu-suite' ),
				size_format( self::max_bytes() ),
				size_format( self::chunk_size() )
			)
		) . '</p>';

		echo '</div>';

		echo '<div class="ces-up__progress" data-role="progress" hidden>';
		echo '<div class="ces-up__bar"><span class="ces-up__fill" data-role="fill"></span></div>';
		echo '<p class="ces-up__msg"><span data-role="pct">0%</span> <span data-role="msg"></span></p>';
		echo '<p><button type="button" class="button button-link-delete" data-role="cancel">'
			. esc_html__( 'Cancel upload', 'classic-edu-suite' ) . '</button></p>';
		echo '</div>';

		echo '<p class="ces-up__notice" data-role="notice" hidden></p>';
	}

	/**
	 * Buttons for an already-attached video.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param string $status    Current status.
	 * @param array  $caps      Transcode capabilities.
	 * @return void
	 */
	private static function render_actions( $lesson_id, $status, array $caps ) {
		if ( CES_Lms_Meta::STATUS_NONE === $status ) {
			return;
		}

		echo '<p class="ces-up__actions">';

		if ( '' !== $caps['ffmpeg'] ) {
			echo '<button type="button" class="button" data-role="requeue">'
				. esc_html__( 'Package again', 'classic-edu-suite' ) . '</button> ';
		}

		echo '<button type="button" class="button button-link-delete" data-role="delete">'
			. esc_html__( 'Delete this video', 'classic-edu-suite' ) . '</button>';
		echo '</p>';
	}

	// ------------------------------------------------------------------
	// Rendering: the standalone upload screen.
	// ------------------------------------------------------------------

	/**
	 * The Upload Video admin page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'classic-edu-suite' ) );
		}

		$caps = CES_Lms_Transcode::capabilities();

		echo '<div class="wrap ces-wrap ces-lms-admin">';
		echo '<h1>' . esc_html__( 'Upload Video', 'classic-edu-suite' ) . '</h1>';

		echo '<p class="ces-sub">' . esc_html( (string) $caps['note'] ) . '</p>';

		$lessons = get_posts(
			array(
				'post_type'      => CES_Lms_Cpt::LESSON,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		echo '<div class="ces-up ces-up--page" data-ces-upload="' . esc_attr( (string) wp_json_encode( array( 'lessonId' => 0 ) ) ) . '">';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="ces-up-lesson">'
			. esc_html__( 'Attach to lesson', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<select id="ces-up-lesson" class="ces-up__lesson" data-role="lesson">';
		echo '<option value="0">' . esc_html__( '— Create a new lesson —', 'classic-edu-suite' ) . '</option>';

		foreach ( $lessons as $lesson ) {
			$label = get_the_title( $lesson ) ? get_the_title( $lesson ) : sprintf( '#%d', (int) $lesson->ID );

			if ( 'publish' !== $lesson->post_status ) {
				$label .= ' (' . $lesson->post_status . ')';
			}

			echo '<option value="' . esc_attr( (string) (int) $lesson->ID ) . '">' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Pick an existing lesson, or leave this on "Create a new lesson" and give a title below.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ces-up-title">'
			. esc_html__( 'New lesson title', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="text" id="ces-up-title" class="regular-text" data-role="title" />';
		echo '<p class="description">' . esc_html__( 'Used only when creating a new lesson. It is saved as a draft so you can set the course and the price before publishing.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		self::render_dropzone();

		echo '</div>';
		echo '</div>';
	}
}
