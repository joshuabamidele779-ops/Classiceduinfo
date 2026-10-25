<?php
/**
 * Canonical post-meta keys and video status vocabulary for the LMS module.
 *
 * Centralised so the CPT editor, the transcoder, the storage drivers, the
 * stream endpoint and the admin screens all agree on one spelling. Nothing
 * here touches the database schema: every value is ordinary post meta on a
 * `ces_lesson` or `ces_course` post.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta key registry plus small typed accessors.
 */
class CES_Lms_Meta {

	// ------------------------------------------------------------------
	// Video / media meta (on ces_lesson).
	// ------------------------------------------------------------------

	/**
	 * Basename of the uploaded master file inside ces-private/source/.
	 */
	const SOURCE = '_ces_video_source';

	/**
	 * Original client-side filename, for display only.
	 */
	const SOURCE_NAME = '_ces_video_source_name';

	/**
	 * Source file size in bytes.
	 */
	const FILESIZE = '_ces_video_filesize';

	/**
	 * Source MIME type as sniffed server-side.
	 */
	const MIME = '_ces_video_mime';

	/**
	 * Packaging status, one of the STATUS_* constants.
	 */
	const STATUS = '_ces_video_status';

	/**
	 * Last packaging error message, shown to admins only.
	 */
	const ERROR = '_ces_video_error';

	/**
	 * Storage driver key that currently owns this lesson's media.
	 */
	const DRIVER = '_ces_video_driver';

	/**
	 * Completed renditions: array of arrays with name/height/bandwidth/playlist.
	 */
	const RENDITIONS = '_ces_video_renditions';

	/**
	 * Rendition names still waiting to be packaged.
	 */
	const PENDING = '_ces_video_pending';

	/**
	 * Duration in whole seconds.
	 */
	const DURATION = '_ces_video_duration';

	/**
	 * Remote provider object id (Bunny GUID, Cloudflare Stream UID).
	 */
	const REMOTE_ID = '_ces_video_remote_id';

	/**
	 * Remote provider payload cache (never contains credentials).
	 */
	const REMOTE_DATA = '_ces_video_remote';

	/**
	 * Timestamp of the last status change.
	 */
	const UPDATED = '_ces_video_updated';

	/**
	 * Unix timestamp the job entered the queue.
	 */
	const QUEUED_AT = '_ces_video_queued_at';

	// ------------------------------------------------------------------
	// Lesson business meta.
	// ------------------------------------------------------------------

	/**
	 * Parent course post id.
	 */
	const COURSE = '_ces_lesson_course';

	/**
	 * Free-preview flag ('1' or '').
	 */
	const FREE_PREVIEW = '_ces_lesson_free';

	/**
	 * Per-lesson coin cost override. Empty string means "inherit the setting".
	 */
	const COIN_COST = '_ces_lesson_coin_cost';

	/**
	 * Attachment ids offered as lesson downloads (notes, worksheets).
	 */
	const ATTACHMENTS = '_ces_lesson_attachments';

	/**
	 * Short rich-text lesson notes.
	 */
	const NOTES = '_ces_lesson_notes';

	/**
	 * Plain-text transcript, also used for search and accessibility.
	 */
	const TRANSCRIPT = '_ces_lesson_transcript';

	// ------------------------------------------------------------------
	// Status vocabulary.
	// ------------------------------------------------------------------

	/**
	 * No media uploaded yet.
	 */
	const STATUS_NONE = 'none';

	/**
	 * Waiting for the cron worker.
	 */
	const STATUS_QUEUED = 'queued';

	/**
	 * A cron tick is currently packaging this lesson.
	 */
	const STATUS_PROCESSING = 'processing';

	/**
	 * HLS renditions written and playable.
	 */
	const STATUS_READY = 'ready';

	/**
	 * Packaging failed; see the ERROR meta.
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * No ffmpeg on this host. The single source file is streamed through the
	 * same protected, token-gated, Range-capable endpoint instead. Playable.
	 */
	const STATUS_SOURCE_ONLY = 'source_only';

	/**
	 * Media lives with a remote provider (Bunny / Cloudflare Stream).
	 */
	const STATUS_REMOTE = 'remote';

	/**
	 * Statuses from which a lesson can actually be watched.
	 *
	 * @return string[]
	 */
	public static function playable_statuses() {
		return array( self::STATUS_READY, self::STATUS_SOURCE_ONLY, self::STATUS_REMOTE );
	}

	/**
	 * Human label for a status value.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			self::STATUS_NONE        => __( 'No video', 'classic-edu-suite' ),
			self::STATUS_QUEUED      => __( 'Queued', 'classic-edu-suite' ),
			self::STATUS_PROCESSING  => __( 'Processing', 'classic-edu-suite' ),
			self::STATUS_READY       => __( 'Ready (HLS)', 'classic-edu-suite' ),
			self::STATUS_FAILED      => __( 'Failed', 'classic-edu-suite' ),
			self::STATUS_SOURCE_ONLY => __( 'Ready (single file, no ffmpeg)', 'classic-edu-suite' ),
			self::STATUS_REMOTE      => __( 'Ready (remote CDN)', 'classic-edu-suite' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Current packaging status for a lesson.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return string
	 */
	public static function status( $lesson_id ) {
		$status = (string) get_post_meta( (int) $lesson_id, self::STATUS, true );

		return '' === $status ? self::STATUS_NONE : $status;
	}

	/**
	 * Write a status, optionally with an error message.
	 *
	 * @param int    $lesson_id Lesson post id.
	 * @param string $status    One of the STATUS_* constants.
	 * @param string $error     Optional error detail.
	 * @return void
	 */
	public static function set_status( $lesson_id, $status, $error = '' ) {
		$lesson_id = (int) $lesson_id;

		update_post_meta( $lesson_id, self::STATUS, $status );
		update_post_meta( $lesson_id, self::UPDATED, time() );

		if ( '' === $error ) {
			delete_post_meta( $lesson_id, self::ERROR );
		} else {
			update_post_meta( $lesson_id, self::ERROR, wp_strip_all_tags( $error ) );
		}
	}

	/**
	 * Whether the lesson has media a student could play right now.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return bool
	 */
	public static function is_playable( $lesson_id ) {
		return in_array( self::status( $lesson_id ), self::playable_statuses(), true );
	}

	/**
	 * Lesson duration in seconds, 0 when unknown.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return int
	 */
	public static function duration( $lesson_id ) {
		return max( 0, (int) get_post_meta( (int) $lesson_id, self::DURATION, true ) );
	}

	/**
	 * Completed renditions for a lesson.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return array[]
	 */
	public static function renditions( $lesson_id ) {
		$list = get_post_meta( (int) $lesson_id, self::RENDITIONS, true );

		return is_array( $list ) ? $list : array();
	}

	/**
	 * Format a duration as m:ss or h:mm:ss.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );
		$secs    = $seconds % 60;

		if ( $hours > 0 ) {
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $secs );
		}

		return sprintf( '%d:%02d', $minutes, $secs );
	}
}
