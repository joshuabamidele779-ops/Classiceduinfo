<?php
/**
 * Storage driver contract.
 *
 * The whole point of this layer is that the site owner has chosen to
 * self-host video on shared LiteSpeed hosting, knowing the limits, but should
 * be able to move to Bunny Stream or Cloudflare Stream later by changing one
 * setting (`video_storage_driver`) rather than paying for a rewrite.
 *
 * Every driver answers the same five questions:
 *   - upload()            : take a file on disk and make it ours
 *   - get_playlist_url()  : give the player something it can actually load
 *   - get_status()        : is it ready, and if not why not
 *   - delete()            : remove every byte we created
 *   - supports_drm()      : can this driver do real encrypted DRM
 *
 * No driver ever returns a credential, an API key or a raw filesystem path to
 * the browser. `get_playlist_url()` returns either a signed URL on our own
 * stream endpoint (local) or a provider-signed, expiring URL (remote).
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class for every LMS video storage driver.
 */
abstract class CES_Lms_Storage {

	/**
	 * Playback kind: HLS master playlist.
	 */
	const PLAYBACK_HLS = 'hls';

	/**
	 * Playback kind: single progressive file served with Range support.
	 */
	const PLAYBACK_PROGRESSIVE = 'progressive';

	/**
	 * Playback kind: a provider-hosted iframe/embed.
	 */
	const PLAYBACK_EMBED = 'embed';

	/**
	 * Machine key matching the `video_storage_driver` setting value.
	 *
	 * @return string
	 */
	abstract public function get_key();

	/**
	 * Human label for admin screens.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Take ownership of an uploaded file.
	 *
	 * The file at `$source_path` has already been written inside
	 * `ces-private/`, validated for type and attributed to `$lesson_id`.
	 * Implementations either leave it in place (local) or push it to the
	 * provider and record the returned id.
	 *
	 * @param int    $lesson_id   Lesson post id.
	 * @param string $source_path Absolute path to the uploaded master file.
	 * @param array  $args        Optional extra context (filename, mime).
	 * @return array|WP_Error Array describing the stored object.
	 */
	abstract public function upload( $lesson_id, $source_path, array $args = array() );

	/**
	 * Build something the player can load.
	 *
	 * @param int   $lesson_id Lesson post id.
	 * @param array $args      {
	 *     @type int    $user_id User the URL is minted for. Required.
	 *     @type string $sid     Stream session id. Required for local.
	 * }
	 * @return array|WP_Error {
	 *     @type string $kind     One of the PLAYBACK_* constants.
	 *     @type string $url      Playlist, file or embed URL.
	 *     @type string $mime     Expected MIME type, '' when not applicable.
	 *     @type bool   $use_hlsjs Whether hls.js should drive playback.
	 *     @type int    $expires  Unix timestamp the URL stops working.
	 * }
	 */
	abstract public function get_playlist_url( $lesson_id, array $args = array() );

	/**
	 * Current packaging/availability status.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return array {
	 *     @type string $status   One of the CES_Lms_Meta::STATUS_* constants.
	 *     @type string $label    Human label.
	 *     @type string $error    Error detail, '' when healthy.
	 *     @type bool   $playable Whether a student could watch right now.
	 *     @type array  $detail   Driver-specific extras for the admin screen.
	 * }
	 */
	abstract public function get_status( $lesson_id );

	/**
	 * Remove every byte this driver created for a lesson.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return bool|WP_Error
	 */
	abstract public function delete( $lesson_id );

	/**
	 * Whether this driver can enforce real, encrypted DRM (Widevine/FairPlay).
	 *
	 * Honest default: no. Self-hosted HLS over a signed endpoint is strong
	 * access control, not DRM. It stops link sharing and download tools; it
	 * does not stop a determined person capturing their own screen.
	 *
	 * @return bool
	 */
	public function supports_drm() {
		return false;
	}

	/**
	 * Whether the module should run its own ffmpeg packaging for this driver.
	 *
	 * Remote providers transcode on their side, so local packaging is skipped.
	 *
	 * @return bool
	 */
	public function needs_local_packaging() {
		return false;
	}

	/**
	 * Whether the driver is configured well enough to be used.
	 *
	 * @return true|WP_Error
	 */
	public function check_config() {
		return true;
	}

	/**
	 * Convenience: a WP_Error for an unconfigured driver.
	 *
	 * @param string $what Which setting is missing.
	 * @return WP_Error
	 */
	protected function config_error( $what ) {
		return new WP_Error(
			'ces_storage_unconfigured',
			sprintf(
				/* translators: %s: list of missing setting names. */
				__( 'The %1$s video storage driver is missing required settings: %2$s. Fill them in under Classic EDU → Settings.', 'classic-edu-suite' ),
				$this->get_label(),
				$what
			)
		);
	}

	/**
	 * Resolve the user id a playback URL should be minted for.
	 *
	 * @param array $args Arguments passed to get_playlist_url().
	 * @return int
	 */
	protected function arg_user_id( array $args ) {
		if ( ! empty( $args['user_id'] ) ) {
			return (int) $args['user_id'];
		}

		return (int) get_current_user_id();
	}

	/**
	 * Resolve the stream session id a playback URL should carry.
	 *
	 * @param array $args Arguments passed to get_playlist_url().
	 * @return string
	 */
	protected function arg_sid( array $args ) {
		$sid = isset( $args['sid'] ) ? CES_Lms_Token::sanitize_sid( $args['sid'] ) : '';

		return '' === $sid ? CES_Lms_Token::new_sid() : $sid;
	}
}
