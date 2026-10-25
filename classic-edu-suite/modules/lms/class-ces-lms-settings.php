<?php
/**
 * Settings fields and overview cards contributed by the LMS module.
 *
 * Only keys that already exist in `CES_Settings::defaults()` are registered,
 * as the contract requires. API keys render as password fields so they are not
 * read over an admin's shoulder; they are still stored in the options table in
 * plain text, which is how every WordPress plugin stores them and is worth
 * knowing rather than pretending otherwise.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * LMS settings registration.
 */
class CES_Lms_Settings {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_settings_sections', array( __CLASS__, 'sections' ), 20 );
		add_filter( 'ces_overview_cards', array( __CLASS__, 'cards' ), 20 );
		add_action( 'update_option_' . CES_Settings::OPTION, array( __CLASS__, 'on_settings_saved' ), 10, 2 );
	}

	/**
	 * Add our settings sections.
	 *
	 * @param array $sections Existing sections.
	 * @return array
	 */
	public static function sections( $sections ) {
		if ( ! is_array( $sections ) ) {
			$sections = array();
		}

		$sections[] = array(
			'title'  => __( 'Syllabus Video Library', 'classic-edu-suite' ),
			'fields' => array(
				'video_storage_driver'      => array(
					'label'   => __( 'Where videos are stored', 'classic-edu-suite' ),
					'type'    => 'select',
					'options' => CES_Lms_Storage_Factory::choices(),
					'help'    => __( 'Applies to new uploads only. Lessons already uploaded keep playing from wherever they are, so you can migrate gradually.', 'classic-edu-suite' ),
				),
				'video_coin_cost'           => array(
					'label' => __( 'Coins to unlock a lesson', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Default cost. Any lesson can override this on its own edit screen.', 'classic-edu-suite' ),
				),
				'video_unlock_hours'        => array(
					'label' => __( 'Hours an unlock lasts', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Re-watching inside this window is always free. Minimum one hour.', 'classic-edu-suite' ),
				),
				'video_free_lessons'        => array(
					'label' => __( 'Free preview lessons per course', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'The first N lessons of every course are free to any logged-in student.', 'classic-edu-suite' ),
				),
				'subscriber_unlimited'      => array(
					'label' => __( 'Subscribers watch everything free', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'An active YITH membership or subscription bypasses coins entirely.', 'classic-edu-suite' ),
				),
				'video_concurrent_limit'    => array(
					'label' => __( 'Simultaneous streams per account', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'The real defence against one login being shared around a class group. 2 lets a student use a phone and a laptop. 0 means unlimited.', 'classic-edu-suite' ),
				),
				'video_token_ttl'           => array(
					'label' => __( 'Stream token lifetime (seconds)', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( '120 is a good default. The player refreshes tokens while it plays, so a short lifetime never interrupts a long lesson.', 'classic-edu-suite' ),
				),
				'video_bind_token_to_ip'    => array(
					'label' => __( 'Bind tokens to the viewer IP', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Stronger against link sharing. Turn it off if students report the video stopping when they move between Wi-Fi and mobile data.', 'classic-edu-suite' ),
				),
				'video_watermark_enabled'   => array(
					'label' => __( 'Show a per-student watermark', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Strongly recommended. Nothing can stop a student screen-recording, but this makes any recording they share carry their own name.', 'classic-edu-suite' ),
				),
				'video_watermark_text'      => array(
					'label' => __( 'Watermark text', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Placeholders: {display_name}, {user_id}, {email}.', 'classic-edu-suite' ),
				),
				'video_renditions'          => array(
					'label' => __( 'HLS renditions', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Comma-separated, from 240p, 360p, 480p, 720p, 1080p. Each one costs CPU to make and disk to store, so keep the list short on shared hosting. Renditions taller than the source are skipped.', 'classic-edu-suite' ),
				),
				'video_hls_segment_seconds' => array(
					'label' => __( 'HLS segment length (seconds)', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( '6 is the usual choice. Shorter segments start faster but create many more files and many more requests.', 'classic-edu-suite' ),
				),
				'video_ffmpeg_path'         => array(
					'label' => __( 'ffmpeg path', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Leave empty to auto-detect. Check Classic EDU → Video Diagnostics to see what was found.', 'classic-edu-suite' ),
				),
			),
		);

		$sections[] = array(
			'title'  => __( 'Remote Video Storage (only used when the driver above is Bunny or Cloudflare)', 'classic-edu-suite' ),
			'fields' => array(
				'bunny_library_id'      => array(
					'label' => __( 'Bunny Stream library ID', 'classic-edu-suite' ),
					'type'  => 'text',
				),
				'bunny_api_key'         => array(
					'label' => __( 'Bunny Stream API key', 'classic-edu-suite' ),
					'type'  => 'password',
					'help'  => __( 'Server-side only. It is never sent to a browser.', 'classic-edu-suite' ),
				),
				'bunny_cdn_hostname'    => array(
					'label' => __( 'Bunny CDN hostname', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'For example vz-1a2b3c4d-567.b-cdn.net', 'classic-edu-suite' ),
				),
				'bunny_token_key'       => array(
					'label' => __( 'Bunny token authentication key', 'classic-edu-suite' ),
					'type'  => 'password',
					'help'  => __( 'Enable token authentication on the Bunny pull zone and paste the security key here. Without it, playback URLs are not signed.', 'classic-edu-suite' ),
				),
				'cloudflare_account_id' => array(
					'label' => __( 'Cloudflare account ID', 'classic-edu-suite' ),
					'type'  => 'text',
				),
				'cloudflare_api_token'  => array(
					'label' => __( 'Cloudflare API token', 'classic-edu-suite' ),
					'type'  => 'password',
					'help'  => __( 'Needs the Stream:Edit permission. Server-side only.', 'classic-edu-suite' ),
				),
			),
		);

		return $sections;
	}

	/**
	 * Add stat cards to the plugin overview screen.
	 *
	 * @param array $cards Existing cards.
	 * @return array
	 */
	public static function cards( $cards ) {
		if ( ! is_array( $cards ) ) {
			$cards = array();
		}

		$courses = wp_count_posts( CES_Lms_Cpt::COURSE );
		$lessons = wp_count_posts( CES_Lms_Cpt::LESSON );
		$totals  = CES_Lms_Progress::totals();
		$queued  = CES_Lms_Transcode::queue_length();

		$cards[] = array(
			'label' => __( 'Courses', 'classic-edu-suite' ),
			'value' => isset( $courses->publish ) ? (int) $courses->publish : 0,
			'href'  => admin_url( 'edit.php?post_type=' . CES_Lms_Cpt::COURSE ),
		);

		$cards[] = array(
			'label' => __( 'Video lessons', 'classic-edu-suite' ),
			'value' => isset( $lessons->publish ) ? (int) $lessons->publish : 0,
			'href'  => admin_url( 'edit.php?post_type=' . CES_Lms_Cpt::LESSON ),
		);

		$cards[] = array(
			'label' => __( 'Hours watched', 'classic-edu-suite' ),
			'value' => number_format_i18n( round( $totals['total_seconds'] / 3600, 1 ) ),
			'href'  => admin_url( 'admin.php?page=ces-lms-analytics' ),
		);

		$cards[] = array(
			'label' => __( 'Lessons finished', 'classic-edu-suite' ),
			'value' => number_format_i18n( $totals['completions'] ),
			'href'  => admin_url( 'admin.php?page=ces-lms-analytics' ),
		);

		if ( $queued > 0 ) {
			$cards[] = array(
				'label' => __( 'Videos in the queue', 'classic-edu-suite' ),
				'value' => number_format_i18n( $queued ),
				'href'  => admin_url( 'admin.php?page=ces-lms-queue' ),
			);
		}

		return $cards;
	}

	/**
	 * React to a settings save.
	 *
	 * Clears the cached ffmpeg detection, because the admin may have just
	 * typed a path in, and resets the storage driver cache.
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 * @return void
	 */
	public static function on_settings_saved( $old, $new ) {
		$old_path = is_array( $old ) && isset( $old['video_ffmpeg_path'] ) ? (string) $old['video_ffmpeg_path'] : '';
		$new_path = is_array( $new ) && isset( $new['video_ffmpeg_path'] ) ? (string) $new['video_ffmpeg_path'] : '';

		if ( $old_path !== $new_path ) {
			CES_Lms_Transcode::flush_capabilities();
		}

		CES_Lms_Storage_Factory::flush();
	}
}
