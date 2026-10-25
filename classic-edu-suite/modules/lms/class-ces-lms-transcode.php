<?php
/**
 * HLS packaging, the background queue, and the ffmpeg fallback.
 *
 * The design assumption here is pessimistic on purpose: **ffmpeg will very
 * likely not exist on this host.** Most shared LiteSpeed accounts have no
 * ffmpeg binary and often have `exec`/`shell_exec` in `disable_functions`.
 *
 * So the module never treats packaging as a precondition for playback:
 *
 *   ffmpeg present  -> segment into HLS renditions, status `ready`
 *   ffmpeg missing  -> status `source_only`; the single uploaded file is
 *                      served through the *same* protected, token-gated,
 *                      Range-capable endpoint
 *
 * A missing binary must never produce a broken lesson. The only thing lost in
 * the fallback is adaptive bitrate: students get one quality instead of a
 * ladder, which on metered Nigerian mobile data is a real cost, so the admin
 * is told about it loudly on the diagnostics screen.
 *
 * Work is done one rendition per cron tick. A shared host will kill a PHP
 * worker long before a 40-minute lesson finishes transcoding, so the job is
 * deliberately resumable: each tick picks up the next pending rendition and
 * the lesson only flips to `ready` once the master playlist is written.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transcode queue and ffmpeg driver.
 */
class CES_Lms_Transcode {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'ces_process_video_queue';

	/**
	 * Custom cron schedule key.
	 */
	const SCHEDULE = 'ces_five_minutes';

	/**
	 * Option holding the ordered queue of lesson ids.
	 */
	const QUEUE_OPTION = 'ces_lms_queue';

	/**
	 * Transient used as a worker mutex.
	 */
	const LOCK = 'ces_lms_queue_lock';

	/**
	 * How long the worker lock is held, in seconds.
	 */
	const LOCK_TTL = 900;

	/**
	 * Meta key counting failed attempts per rendition.
	 */
	const ATTEMPTS_META = '_ces_video_attempts';

	/**
	 * Meta key caching the source video's pixel dimensions.
	 */
	const DIMENSIONS_META = '_ces_video_dimensions';

	/**
	 * Give up on a rendition after this many failed attempts.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Add the five-minute cron schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (Classic EDU video queue)', 'classic-edu-suite' ),
			);
		}

		return $schedules;
	}

	/**
	 * Make sure the recurring event exists.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON_HOOK );
		}
	}

	// ------------------------------------------------------------------
	// Queue bookkeeping.
	// ------------------------------------------------------------------

	/**
	 * The current queue, as lesson ids.
	 *
	 * @return int[]
	 */
	public static function queue() {
		$queue = get_option( self::QUEUE_OPTION, array() );

		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $queue ) ) ) );
	}

	/**
	 * Persist the queue.
	 *
	 * @param int[] $queue Lesson ids.
	 * @return void
	 */
	private static function save_queue( array $queue ) {
		update_option( self::QUEUE_OPTION, array_values( array_unique( array_filter( array_map( 'absint', $queue ) ) ) ), false );
	}

	/**
	 * Add a lesson to the queue.
	 *
	 * @param int  $lesson_id Lesson post id.
	 * @param bool $reset     Whether to clear previous packaging output first.
	 * @return void
	 */
	public static function enqueue( $lesson_id, $reset = true ) {
		$lesson_id = (int) $lesson_id;

		if ( $lesson_id <= 0 ) {
			return;
		}

		if ( $reset ) {
			delete_post_meta( $lesson_id, self::ATTEMPTS_META );
			delete_post_meta( $lesson_id, CES_Lms_Meta::PENDING );
			delete_post_meta( $lesson_id, CES_Lms_Meta::RENDITIONS );
		}

		$queue   = self::queue();
		$queue[] = $lesson_id;

		self::save_queue( $queue );

		update_post_meta( $lesson_id, CES_Lms_Meta::QUEUED_AT, time() );
		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_QUEUED );

		self::ensure_scheduled();

		/*
		 * Nudge WP-Cron so a lesson uploaded on a quiet site does not sit for
		 * five minutes. Skipped under ALTERNATE_WP_CRON, where spawn_cron()
		 * can emit a redirect, which would wreck an AJAX upload response.
		 */
		if ( ! ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}

	/**
	 * Remove a lesson from the queue.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return void
	 */
	public static function dequeue( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$queue     = self::queue();
		$index     = array_search( $lesson_id, $queue, true );

		if ( false !== $index ) {
			unset( $queue[ $index ] );
			self::save_queue( $queue );
		}
	}

	/**
	 * Number of jobs waiting.
	 *
	 * @return int
	 */
	public static function queue_length() {
		return count( self::queue() );
	}

	/**
	 * Whether a worker is currently running.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		return (bool) get_transient( self::LOCK );
	}

	/**
	 * Clear a stuck worker lock.
	 *
	 * @return void
	 */
	public static function clear_lock() {
		delete_transient( self::LOCK );
	}

	// ------------------------------------------------------------------
	// The worker.
	// ------------------------------------------------------------------

	/**
	 * Cron entry point. Processes at most one lesson per tick.
	 *
	 * One at a time is deliberate. Transcoding is the single most
	 * CPU-expensive thing this plugin does, and a shared host will suspend an
	 * account that pins a core. Slow and survivable beats fast and suspended.
	 *
	 * @return void
	 */
	public static function process_queue() {
		if ( self::is_locked() ) {
			return;
		}

		$queue = self::queue();

		if ( empty( $queue ) ) {
			return;
		}

		set_transient( self::LOCK, time(), self::LOCK_TTL );

		$lesson_id = (int) array_shift( $queue );
		self::save_queue( $queue );

		try {
			$finished = self::process_lesson( $lesson_id );

			if ( ! $finished ) {
				// More work to do (another rendition, or a remote encode still
				// running): put it back on the end of the queue.
				$queue   = self::queue();
				$queue[] = $lesson_id;
				self::save_queue( $queue );
			}
		} catch ( Throwable $e ) {
			// Throwable, not Exception: a TypeError from a hostile host
			// configuration must still release the lock and record the failure.
			CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_FAILED, $e->getMessage() );
			CES_Helpers::log( 'LMS transcode exception', $e->getMessage() );
		}

		self::clear_lock();
	}

	/**
	 * Advance one lesson by one step.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return bool True when the lesson needs no further work.
	 */
	public static function process_lesson( $lesson_id ) {
		$lesson_id = (int) $lesson_id;

		if ( CES_Lms_Cpt::LESSON !== get_post_type( $lesson_id ) ) {
			return true;
		}

		$driver = CES_Lms_Storage_Factory::for_lesson( $lesson_id );

		if ( ! $driver ) {
			CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_FAILED, __( 'No storage driver is available.', 'classic-edu-suite' ) );

			return true;
		}

		// Remote drivers do their own transcoding; we only poll them.
		if ( ! $driver->needs_local_packaging() ) {
			if ( method_exists( $driver, 'refresh_status' ) ) {
				$result = $driver->refresh_status( $lesson_id );

				if ( is_wp_error( $result ) ) {
					CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_FAILED, $result->get_error_message() );

					return true;
				}
			}

			$status = CES_Lms_Meta::status( $lesson_id );

			return in_array( $status, array( CES_Lms_Meta::STATUS_REMOTE, CES_Lms_Meta::STATUS_FAILED, CES_Lms_Meta::STATUS_NONE ), true );
		}

		$source = CES_Lms_Paths::source_path( $lesson_id );

		if ( '' === $source ) {
			CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_FAILED, __( 'The uploaded source file is missing from private storage.', 'classic-edu-suite' ) );

			return true;
		}

		$caps = self::capabilities();

		if ( empty( $caps['ffmpeg'] ) ) {
			/*
			 * The graceful degradation path. No ffmpeg, so no HLS ladder, but
			 * the lesson is still fully playable and still fully protected:
			 * the source file goes out through the token-gated endpoint with
			 * Range support, exactly like a segment would.
			 */
			self::adopt_source_only( $lesson_id, __( 'ffmpeg is not available on this server, so the original file is streamed directly.', 'classic-edu-suite' ) );

			return true;
		}

		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_PROCESSING );

		self::probe( $lesson_id, $source );

		$pending = get_post_meta( $lesson_id, CES_Lms_Meta::PENDING, true );

		if ( ! is_array( $pending ) || empty( $pending ) ) {
			$done = CES_Lms_Meta::renditions( $lesson_id );

			if ( ! empty( $done ) ) {
				return self::finalise( $lesson_id );
			}

			$pending = array_keys( self::ladder( $lesson_id ) );

			if ( empty( $pending ) ) {
				self::adopt_source_only( $lesson_id, __( 'No valid renditions are configured in the video_renditions setting.', 'classic-edu-suite' ) );

				return true;
			}

			update_post_meta( $lesson_id, CES_Lms_Meta::PENDING, $pending );
		}

		$name   = (string) array_shift( $pending );
		$ladder = self::ladder( $lesson_id );

		if ( ! isset( $ladder[ $name ] ) ) {
			update_post_meta( $lesson_id, CES_Lms_Meta::PENDING, $pending );

			return false;
		}

		$result = self::package_rendition( $lesson_id, $source, $name, $ladder[ $name ] );

		if ( is_wp_error( $result ) ) {
			$attempts = get_post_meta( $lesson_id, self::ATTEMPTS_META, true );
			$attempts = is_array( $attempts ) ? $attempts : array();

			$attempts[ $name ] = isset( $attempts[ $name ] ) ? (int) $attempts[ $name ] + 1 : 1;
			update_post_meta( $lesson_id, self::ATTEMPTS_META, $attempts );

			CES_Helpers::log( 'LMS rendition failed', array( 'lesson' => $lesson_id, 'rendition' => $name, 'error' => $result->get_error_message() ) );

			if ( $attempts[ $name ] >= self::MAX_ATTEMPTS ) {
				// Drop this rendition and carry on with the rest.
				update_post_meta( $lesson_id, CES_Lms_Meta::PENDING, $pending );
				CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_PROCESSING, $result->get_error_message() );

				if ( empty( $pending ) ) {
					return self::finalise( $lesson_id );
				}

				return false;
			}

			// Retry this rendition on a later tick.
			array_unshift( $pending, $name );
			update_post_meta( $lesson_id, CES_Lms_Meta::PENDING, $pending );

			return false;
		}

		$done   = CES_Lms_Meta::renditions( $lesson_id );
		$done[] = $result;

		update_post_meta( $lesson_id, CES_Lms_Meta::RENDITIONS, $done );
		update_post_meta( $lesson_id, CES_Lms_Meta::PENDING, $pending );

		if ( empty( $pending ) ) {
			return self::finalise( $lesson_id );
		}

		return false;
	}

	/**
	 * Write the master playlist and mark the lesson ready.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return bool Always true: the lesson needs no further queue work.
	 */
	private static function finalise( $lesson_id ) {
		$lesson_id  = (int) $lesson_id;
		$renditions = CES_Lms_Meta::renditions( $lesson_id );

		if ( empty( $renditions ) ) {
			self::adopt_source_only( $lesson_id, __( 'Every rendition failed to package, so the original file is streamed directly instead.', 'classic-edu-suite' ) );

			return true;
		}

		$written = self::write_master( $lesson_id, $renditions );

		if ( is_wp_error( $written ) ) {
			self::adopt_source_only( $lesson_id, $written->get_error_message() );

			return true;
		}

		delete_post_meta( $lesson_id, CES_Lms_Meta::PENDING );
		delete_post_meta( $lesson_id, self::ATTEMPTS_META );

		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_READY );

		return true;
	}

	/**
	 * Fall back to streaming the single uploaded file.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param string $reason    Admin-facing explanation.
	 * @return void
	 */
	public static function adopt_source_only( $lesson_id, $reason = '' ) {
		$lesson_id = (int) $lesson_id;

		delete_post_meta( $lesson_id, CES_Lms_Meta::PENDING );

		CES_Lms_Meta::set_status( $lesson_id, CES_Lms_Meta::STATUS_SOURCE_ONLY, $reason );
	}

	// ------------------------------------------------------------------
	// ffmpeg.
	// ------------------------------------------------------------------

	/**
	 * Package one rendition into HLS.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param string $source    Absolute source path.
	 * @param string $name      Rendition name, e.g. '720p'.
	 * @param array  $spec      Rendition spec from the ladder.
	 * @return array|WP_Error Rendition record on success.
	 */
	private static function package_rendition( $lesson_id, $source, $name, array $spec ) {
		if ( ! CES_Lms_Paths::ensure() ) {
			return new WP_Error( 'ces_lms_storage', __( 'The private storage directory is not writable.', 'classic-edu-suite' ) );
		}

		$dir = CES_Lms_Paths::lesson_hls_dir( $lesson_id );

		if ( ! $dir ) {
			return new WP_Error( 'ces_lms_storage', __( 'Could not resolve the HLS output directory.', 'classic-edu-suite' ) );
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'ces_lms_mkdir', __( 'Could not create the HLS output directory.', 'classic-edu-suite' ) );
		}

		// Clear any partial output from a killed earlier attempt.
		foreach ( (array) glob( $dir . $name . '_*.ts' ) as $stale ) {
			wp_delete_file( $stale );
		}

		if ( file_exists( $dir . $name . '.m3u8' ) ) {
			wp_delete_file( $dir . $name . '.m3u8' );
		}

		$segment  = max( 2, min( 15, CES_Helpers::setting_int( 'video_hls_segment_seconds', 6 ) ) );
		$playlist = $dir . $name . '.m3u8';

		$command = implode(
			' ',
			array(
				escapeshellarg( self::ffmpeg_path() ),
				'-hide_banner -nostdin -loglevel error -y',
				'-i ' . escapeshellarg( $source ),
				'-vf ' . escapeshellarg( 'scale=-2:' . (int) $spec['height'] ),
				'-c:v libx264 -profile:v main -preset veryfast -crf 23',
				'-maxrate ' . escapeshellarg( $spec['vbitrate'] ),
				'-bufsize ' . escapeshellarg( $spec['bufsize'] ),
				'-force_key_frames ' . escapeshellarg( 'expr:gte(t,n_forced*' . $segment . ')' ),
				'-sc_threshold 0',
				'-c:a aac -b:a ' . escapeshellarg( $spec['abitrate'] ) . ' -ac 2',
				'-hls_time ' . (int) $segment,
				'-hls_playlist_type vod',
				'-hls_flags independent_segments',
				'-hls_segment_filename ' . escapeshellarg( $dir . $name . '_%05d.ts' ),
				'-f hls ' . escapeshellarg( $playlist ),
			)
		);

		$run = self::run( $command );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( 0 !== (int) $run['code'] || ! is_file( $playlist ) ) {
			return new WP_Error(
				'ces_lms_ffmpeg',
				sprintf(
					/* translators: 1: rendition name, 2: ffmpeg output. */
					__( 'ffmpeg failed on the %1$s rendition: %2$s', 'classic-edu-suite' ),
					$name,
					wp_trim_words( (string) $run['output'], 40 )
				)
			);
		}

		$segments = (array) glob( $dir . $name . '_*.ts' );

		if ( empty( $segments ) ) {
			return new WP_Error(
				'ces_lms_ffmpeg_empty',
				sprintf(
					/* translators: %s: rendition name. */
					__( 'ffmpeg produced no segments for the %s rendition.', 'classic-edu-suite' ),
					$name
				)
			);
		}

		$dimensions = get_post_meta( $lesson_id, self::DIMENSIONS_META, true );
		$width      = 0;

		if ( is_array( $dimensions ) && ! empty( $dimensions['width'] ) && ! empty( $dimensions['height'] ) ) {
			$ratio = (float) $dimensions['width'] / max( 1, (float) $dimensions['height'] );
			$width = (int) ( 2 * round( ( $spec['height'] * $ratio ) / 2 ) );
		}

		return array(
			'name'      => $name,
			'height'    => (int) $spec['height'],
			'width'     => $width,
			'bandwidth' => (int) $spec['bandwidth'],
			'playlist'  => $name . '.m3u8',
			'segments'  => count( $segments ),
		);
	}

	/**
	 * Write the multi-rendition master playlist.
	 *
	 * @param int   $lesson_id  Lesson post id.
	 * @param array $renditions Completed rendition records.
	 * @return true|WP_Error
	 */
	private static function write_master( $lesson_id, array $renditions ) {
		$dir = CES_Lms_Paths::lesson_hls_dir( (int) $lesson_id );

		if ( ! $dir || ! is_dir( $dir ) ) {
			return new WP_Error( 'ces_lms_storage', __( 'The HLS output directory is missing.', 'classic-edu-suite' ) );
		}

		usort(
			$renditions,
			static function ( $a, $b ) {
				return (int) $a['bandwidth'] <=> (int) $b['bandwidth'];
			}
		);

		$lines = array( '#EXTM3U', '#EXT-X-VERSION:3' );

		foreach ( $renditions as $rendition ) {
			$attributes = array( 'BANDWIDTH=' . (int) $rendition['bandwidth'] );

			if ( ! empty( $rendition['width'] ) && ! empty( $rendition['height'] ) ) {
				$attributes[] = 'RESOLUTION=' . (int) $rendition['width'] . 'x' . (int) $rendition['height'];
			}

			$attributes[] = 'NAME="' . str_replace( '"', '', (string) $rendition['name'] ) . '"';
			$attributes[] = 'CODECS="avc1.4d401f,mp4a.40.2"';

			$lines[] = '#EXT-X-STREAM-INF:' . implode( ',', $attributes );
			$lines[] = (string) $rendition['playlist'];
		}

		$written = file_put_contents( $dir . CES_Lms_Storage_Local::MASTER, implode( "\n", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $written ) {
			return new WP_Error( 'ces_lms_master_write', __( 'Could not write the master playlist.', 'classic-edu-suite' ) );
		}

		return true;
	}

	/**
	 * Read duration and dimensions from the source with ffprobe.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param string $source    Absolute source path.
	 * @return void
	 */
	public static function probe( $lesson_id, $source ) {
		$lesson_id = (int) $lesson_id;
		$ffprobe   = self::ffprobe_path();

		if ( '' === $ffprobe || ! self::shell_available() ) {
			return;
		}

		$existing = get_post_meta( $lesson_id, self::DIMENSIONS_META, true );

		if ( is_array( $existing ) && ! empty( $existing['width'] ) && CES_Lms_Meta::duration( $lesson_id ) > 0 ) {
			return;
		}

		$command = implode(
			' ',
			array(
				escapeshellarg( $ffprobe ),
				'-v error',
				'-select_streams v:0',
				'-show_entries stream=width,height',
				'-show_entries format=duration',
				'-of default=noprint_wrappers=1:nokey=0',
				escapeshellarg( $source ),
			)
		);

		$run = self::run( $command );

		if ( is_wp_error( $run ) || 0 !== (int) $run['code'] ) {
			return;
		}

		$width    = 0;
		$height   = 0;
		$duration = 0;

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $run['output'] ) as $line ) {
			$pair = explode( '=', trim( $line ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 'width' === $pair[0] ) {
				$width = (int) $pair[1];
			} elseif ( 'height' === $pair[0] ) {
				$height = (int) $pair[1];
			} elseif ( 'duration' === $pair[0] ) {
				$duration = (int) round( (float) $pair[1] );
			}
		}

		if ( $width > 0 && $height > 0 ) {
			update_post_meta( $lesson_id, self::DIMENSIONS_META, array( 'width' => $width, 'height' => $height ) );
		}

		if ( $duration > 0 && CES_Lms_Meta::duration( $lesson_id ) <= 0 ) {
			update_post_meta( $lesson_id, CES_Lms_Meta::DURATION, min( 86400, $duration ) );
		}
	}

	/**
	 * Build the rendition ladder from the `video_renditions` setting.
	 *
	 * Renditions taller than the source are dropped: upscaling burns CPU and
	 * bandwidth to make a video look worse.
	 *
	 * @param int $lesson_id Lesson post id, for its source dimensions.
	 * @return array Rendition name => spec.
	 */
	public static function ladder( $lesson_id = 0 ) {
		$known = array(
			'240p'  => array( 'height' => 240, 'vbitrate' => '400k', 'bufsize' => '800k', 'abitrate' => '64k', 'bandwidth' => 500000 ),
			'360p'  => array( 'height' => 360, 'vbitrate' => '800k', 'bufsize' => '1600k', 'abitrate' => '96k', 'bandwidth' => 1000000 ),
			'480p'  => array( 'height' => 480, 'vbitrate' => '1400k', 'bufsize' => '2800k', 'abitrate' => '128k', 'bandwidth' => 1700000 ),
			'720p'  => array( 'height' => 720, 'vbitrate' => '2800k', 'bufsize' => '5600k', 'abitrate' => '128k', 'bandwidth' => 3100000 ),
			'1080p' => array( 'height' => 1080, 'vbitrate' => '5000k', 'bufsize' => '10000k', 'abitrate' => '192k', 'bandwidth' => 5500000 ),
		);

		$configured = (string) CES_Helpers::setting( 'video_renditions', '360p,480p,720p' );
		$ladder     = array();

		foreach ( explode( ',', $configured ) as $raw ) {
			$key = strtolower( trim( $raw ) );

			if ( isset( $known[ $key ] ) ) {
				$ladder[ $key ] = $known[ $key ];
			}
		}

		if ( empty( $ladder ) ) {
			$ladder['360p'] = $known['360p'];
		}

		$dimensions = $lesson_id ? get_post_meta( (int) $lesson_id, self::DIMENSIONS_META, true ) : array();

		if ( is_array( $dimensions ) && ! empty( $dimensions['height'] ) ) {
			$source_height = (int) $dimensions['height'];
			$filtered      = array();

			foreach ( $ladder as $key => $spec ) {
				if ( $spec['height'] <= $source_height ) {
					$filtered[ $key ] = $spec;
				}
			}

			if ( ! empty( $filtered ) ) {
				$ladder = $filtered;
			} else {
				// Source is smaller than every configured rendition: keep the
				// smallest one so there is still something to package.
				$ladder = array_slice( $ladder, 0, 1, true );
			}
		}

		return $ladder;
	}

	// ------------------------------------------------------------------
	// Environment detection.
	// ------------------------------------------------------------------

	/**
	 * What this host can actually do, cached for twelve hours.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array {
	 *     @type string $ffmpeg     Path, or ''.
	 *     @type string $ffprobe    Path, or ''.
	 *     @type bool   $shell      Whether a process-spawning function works.
	 *     @type string $shell_fn   Which function will be used.
	 *     @type string $note       Human explanation for the admin.
	 * }
	 */
	public static function capabilities( $fresh = false ) {
		$cached = $fresh ? false : get_transient( 'ces_lms_caps' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$shell_fn = self::shell_function();
		$caps     = array(
			'ffmpeg'   => '',
			'ffprobe'  => '',
			'shell'    => '' !== $shell_fn,
			'shell_fn' => $shell_fn,
			'note'     => '',
		);

		if ( '' === $shell_fn ) {
			$caps['note'] = __( 'PHP cannot start external programs on this host (exec, shell_exec and proc_open are all unavailable). HLS packaging is impossible here; lessons will stream as single files instead.', 'classic-edu-suite' );

			set_transient( 'ces_lms_caps', $caps, 12 * HOUR_IN_SECONDS );

			return $caps;
		}

		$caps['ffmpeg']  = self::locate_binary( 'ffmpeg', (string) CES_Helpers::setting( 'video_ffmpeg_path', '' ) );
		$caps['ffprobe'] = self::locate_binary( 'ffprobe', self::sibling_ffprobe( $caps['ffmpeg'] ) );

		if ( '' === $caps['ffmpeg'] ) {
			$caps['note'] = __( 'No ffmpeg binary was found. Lessons will stream as single files through the protected endpoint, which works but gives students one quality instead of an adaptive ladder.', 'classic-edu-suite' );
		} elseif ( '' === $caps['ffprobe'] ) {
			$caps['note'] = __( 'ffmpeg was found but ffprobe was not, so lesson durations must be entered by hand on the lesson screen.', 'classic-edu-suite' );
		} else {
			$caps['note'] = __( 'ffmpeg and ffprobe are both available. Full HLS packaging is enabled.', 'classic-edu-suite' );
		}

		set_transient( 'ces_lms_caps', $caps, 12 * HOUR_IN_SECONDS );

		return $caps;
	}

	/**
	 * Clear the capability cache, e.g. after the ffmpeg path setting changes.
	 *
	 * @return void
	 */
	public static function flush_capabilities() {
		delete_transient( 'ces_lms_caps' );
	}

	/**
	 * Path to ffmpeg, or ''.
	 *
	 * @return string
	 */
	public static function ffmpeg_path() {
		$caps = self::capabilities();

		return (string) $caps['ffmpeg'];
	}

	/**
	 * Path to ffprobe, or ''.
	 *
	 * @return string
	 */
	public static function ffprobe_path() {
		$caps = self::capabilities();

		return (string) $caps['ffprobe'];
	}

	/**
	 * Whether PHP can spawn a process at all.
	 *
	 * @return bool
	 */
	public static function shell_available() {
		$caps = self::capabilities();

		return (bool) $caps['shell'];
	}

	/**
	 * Which process-spawning function is usable, in order of preference.
	 *
	 * @return string Function name, or '' when none is.
	 */
	private static function shell_function() {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		foreach ( array( 'proc_open', 'exec', 'shell_exec' ) as $fn ) {
			if ( function_exists( $fn ) && ! in_array( $fn, $disabled, true ) ) {
				return $fn;
			}
		}

		return '';
	}

	/**
	 * Find an executable, honouring an explicit setting first.
	 *
	 * @param string $binary   Binary name, e.g. 'ffmpeg'.
	 * @param string $explicit Configured absolute path, or ''.
	 * @return string Usable path, or ''.
	 */
	private static function locate_binary( $binary, $explicit ) {
		$explicit = trim( (string) $explicit );

		if ( '' !== $explicit && self::is_runnable( $explicit ) ) {
			return $explicit;
		}

		$candidates = array(
			'/usr/bin/' . $binary,
			'/usr/local/bin/' . $binary,
			'/opt/bin/' . $binary,
			'/opt/' . $binary . '/bin/' . $binary,
			'/usr/local/' . $binary . '/bin/' . $binary,
			'/snap/bin/' . $binary,
		);

		foreach ( $candidates as $candidate ) {
			if ( self::is_runnable( $candidate ) ) {
				return $candidate;
			}
		}

		// Last resort: ask the shell where it lives.
		$run = self::run( 'command -v ' . escapeshellarg( $binary ) );

		if ( ! is_wp_error( $run ) && 0 === (int) $run['code'] ) {
			$path = trim( strtok( (string) $run['output'], "\n" ) );

			if ( '' !== $path && self::is_runnable( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Guess the ffprobe path that sits next to a known ffmpeg.
	 *
	 * @param string $ffmpeg ffmpeg path, or ''.
	 * @return string
	 */
	private static function sibling_ffprobe( $ffmpeg ) {
		if ( '' === $ffmpeg ) {
			return '';
		}

		$candidate = dirname( $ffmpeg ) . '/ffprobe';

		return self::is_runnable( $candidate ) ? $candidate : '';
	}

	/**
	 * Whether a path looks like something we can execute.
	 *
	 * `is_executable()` is the real test but open_basedir makes it lie, so a
	 * plain existence check is accepted as a fallback.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function is_runnable( $path ) {
		if ( '' === $path || false !== strpos( $path, "\0" ) ) {
			return false;
		}

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		if ( @is_executable( $path ) ) {
			return true;
		}

		return (bool) @is_file( $path );
		// phpcs:enable
	}

	/**
	 * Run a shell command and capture output and exit code.
	 *
	 * @param string $command Fully escaped command line.
	 * @return array|WP_Error { @type string $output, @type int $code }
	 */
	private static function run( $command ) {
		$fn = self::shell_function();

		if ( '' === $fn ) {
			return new WP_Error( 'ces_lms_no_shell', __( 'PHP cannot start external programs on this host.', 'classic-edu-suite' ) );
		}

		// Transcoding takes minutes. Give it as long as the host allows.
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
			@set_time_limit( 0 );
		}

		if ( 'proc_open' === $fn ) {
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);

			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged
			$process = @proc_open( $command, $descriptors, $pipes );

			if ( ! is_resource( $process ) ) {
				return new WP_Error( 'ces_lms_proc_open', __( 'proc_open() was refused by the host.', 'classic-edu-suite' ) );
			}

			fclose( $pipes[0] );

			$stdout = (string) stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );

			$stderr = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[2] );

			$code = (int) proc_close( $process );
			// phpcs:enable

			return array(
				'output' => trim( $stdout . "\n" . $stderr ),
				'code'   => $code,
			);
		}

		if ( 'exec' === $fn ) {
			$lines = array();
			$code  = 1;

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec, WordPress.PHP.NoSilencedErrors.Discouraged
			@exec( $command . ' 2>&1', $lines, $code );

			return array(
				'output' => trim( implode( "\n", (array) $lines ) ),
				'code'   => (int) $code,
			);
		}

		// shell_exec gives no exit code, so smuggle one out through stdout.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec, WordPress.PHP.NoSilencedErrors.Discouraged
		$raw = (string) @shell_exec( $command . ' 2>&1; echo "CES_EXIT:$?"' );

		$code = 1;

		if ( preg_match( '/CES_EXIT:(\d+)\s*$/', $raw, $matches ) ) {
			$code = (int) $matches[1];
			$raw  = preg_replace( '/CES_EXIT:\d+\s*$/', '', $raw );
		}

		return array(
			'output' => trim( (string) $raw ),
			'code'   => $code,
		);
	}
}
