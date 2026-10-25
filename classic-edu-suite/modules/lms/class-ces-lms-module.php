<?php
/**
 * LMS module entry point.
 *
 * Called once from `CES_Plugin::load_modules()` on `plugins_loaded` at
 * priority 20, after the wallet and CBT modules so that `CES_Wallet` is
 * already available to the coin-gated video access layer.
 *
 * Load order inside the module matters in two places:
 *
 *  - `CES_Lms_Stream` registers its rewrite rule on `init` priority 10 and
 *    intercepts `parse_request` at priority 0, so it is wired before the post
 *    types to guarantee the stream endpoint wins over any CPT permalink.
 *  - `CES_Lms_Settings` registers the settings fields and the overview card,
 *    so it runs regardless of admin context (the REST and cron paths read the
 *    same settings).
 *
 * Shortcodes registered by this module (via `CES_Lms_Shortcodes`):
 *   [ces_course_list]
 *   [ces_course]
 *   [ces_lesson]
 *   [ces_my_courses]
 *
 * Cron events owned by this module:
 *   `ces_process_video_queue` — every five minutes, packages queued lessons.
 *
 * @package ClassicEduSuite\Lms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the LMS module.
 */
class CES_Lms_Module {

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register every hook this module owns.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		// The signed stream endpoint. First, so its rewrite and its
		// parse_request interception are in place before anything else.
		CES_Lms_Stream::init();

		// Courses, lessons, taxonomies, capabilities and the editor boxes.
		CES_Lms_Cpt::init();

		// Student-facing REST: unlock, token, heartbeat, progress.
		CES_Lms_Rest::init();

		// Player assets and the single-lesson injection.
		CES_Lms_Player::init();

		// Course/lesson shortcodes and the single-course injection.
		CES_Lms_Shortcodes::init();

		// The "My Courses" dashboard tab (position 30).
		CES_Lms_Dashboard::init();

		// Settings fields, overview card, cache flush on save.
		CES_Lms_Settings::init();

		// The packaging queue and its five-minute worker. Driver-agnostic:
		// `process_queue()` resolves the owning driver per lesson, so this is
		// wired for remote drivers too rather than only for local ffmpeg.
		CES_Lms_Transcode::init();

		/*
		 * Admin screens and their AJAX endpoints. `admin-ajax.php` satisfies
		 * `is_admin()`, so the upload, builder and queue handlers are
		 * registered here rather than on the front end.
		 */
		if ( is_admin() ) {
			CES_Lms_Admin::init();
			CES_Lms_Admin_Upload::init();
			CES_Lms_Admin_Builder::init();
			CES_Lms_Admin_Queue::init();
			CES_Lms_Admin_Diagnostics::init();
		}
	}
}
