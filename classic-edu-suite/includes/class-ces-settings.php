<?php
/**
 * Central settings registry.
 *
 * Every tunable value in the plugin lives in the single `ces_settings`
 * option array. Read values with `CES_Helpers::setting( 'key' )`; never
 * call `get_option()` for module config directly.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings defaults and the admin settings screen.
 */
class CES_Settings {

	/**
	 * Option name holding the settings array.
	 */
	const OPTION = 'ces_settings';

	/**
	 * Canonical defaults for every module.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// ---------------- Coin wallet ----------------
			'coins_signup_bonus'        => 20,
			'coins_per_referral'        => 25,
			'coins_referral_milestone'  => 100, // Bonus at every N qualified referrals.
			'coins_milestone_every'     => 5,
			'coins_daily_login'         => 2,
			'coins_streak_bonus'        => 10,  // Awarded on a 7-day streak.
			'coins_per_quiz_pass'       => 5,
			'coins_quiz_daily_cap'      => 20,

			// ---------------- Video access ----------------
			'video_coin_cost'           => 10,  // Default cost to unlock one lesson.
			'video_unlock_hours'        => 72,  // How long an unlock lasts.
			'video_free_lessons'        => 1,   // Free preview lessons per course.
			'subscriber_unlimited'      => 1,   // YITH subscribers bypass coins entirely.
			'video_storage_driver'      => 'local', // local | bunny | cloudflare
			'video_token_ttl'           => 120, // Seconds a stream token stays valid.
			'video_bind_token_to_ip'    => 1,
			'video_watermark_enabled'   => 1,
			'video_watermark_text'      => '{display_name} · {user_id}',
			'video_concurrent_limit'    => 2,   // Simultaneous streams per account.
			'video_hls_segment_seconds' => 6,
			'video_ffmpeg_path'         => '',  // Empty = auto-detect.
			'video_renditions'          => '360p,480p,720p',

			// ---------------- Remote storage (unused while driver=local) ----------------
			'bunny_library_id'          => '',
			'bunny_api_key'             => '',
			'bunny_cdn_hostname'        => '',
			'bunny_token_key'           => '',
			'cloudflare_account_id'     => '',
			'cloudflare_api_token'      => '',

			// ---------------- CBT ----------------
			'cbt_default_duration'      => 30,  // Minutes.
			'cbt_default_questions'     => 20,
			'cbt_pass_mark'             => 50,
			'cbt_shuffle_questions'     => 1,
			'cbt_shuffle_options'       => 1,
			'cbt_show_answers_after'    => 1,   // Reveal correct answers on the result screen.
			'cbt_allow_retake'          => 1,
			'cbt_retake_cooldown'       => 0,   // Minutes; 0 = no cooldown.
			'cbt_leaderboard_enabled'   => 1,
			'cbt_lockdown_mode'         => 1,   // Warn and log on tab-switch / blur.

			// ---------------- Daily quotes ----------------
			'quotes_enabled'            => 1,
			'quotes_delay_seconds'      => 8,
			'quotes_show_to_guests'     => 1,
			'quotes_referral_cta'       => 1,   // Attach the "invite a friend" task to the popup.
			'quotes_cta_text'           => 'Invite a friend and earn {coins} coins',
			'quotes_frequency'          => 'daily', // daily | every_visit

			// ---------------- Study hub ----------------
			'hub_page_id'                 => 0,   // Page carrying [ces_study_hub].
			'hub_study_questions'         => 20,  // Default study session length.
			'hub_resource_coin_cost'      => 5,   // Default cost of a locked resource.
			'hub_points_time_weight'      => 15,  // Leaderboard bonus for unused time.
			'hub_leaderboard_members_only' => 0,

			// ---------------- Guidance ----------------
			'guidance_page_id'          => 0,   // Page carrying [ces_guidance].

			// ---------------- Parents ----------------
			'parents_capture_enabled'     => 1,
			'parents_required_at_signup'  => 1,
			'parents_block_until_provided' => 0,
			'parents_consent_required'    => 1,
			'parents_default_country'     => '234',
			'parents_consent_text'        => 'We send your parent or guardian a short progress update. We never share their number with anyone else.',
			'parents_report_template'     => '',  // Empty = the built-in wording.
			'parents_wa_api_enabled'      => 0,
			'parents_wa_phone_id'         => '',
			'parents_wa_token'            => '',
			'parents_wa_template'         => '',
			'parents_wa_language'         => 'en',

			// ---------------- Accounts: Gmail sign-in ----------------
			'accounts_google_enabled'       => 0,
			'accounts_google_client_id'     => '',
			'accounts_google_client_secret' => '',
			'accounts_google_create_users'  => 1,   // Allow a brand new account from a Google sign-in.
			'accounts_google_link_existing' => 1,   // Match a Google email to an existing account.
			'accounts_google_only'          => 0,   // Refuse password logins for Google-created students.
			'accounts_google_avatar'        => 1,   // Use the Google profile picture as the avatar.
			'accounts_require_gmail'        => 0,   // Only gmail.com / googlemail.com addresses.
			'accounts_allowed_domains'      => '',  // Comma separated. Empty = any Google account.
			'accounts_default_role'         => 'subscriber',
			'accounts_button_label'         => 'Continue with Gmail',
			'accounts_inject_login_button'  => 1,   // Add the button to the login forms automatically.

			// ---------------- Accounts: parent approval ----------------
			'accounts_approval_enabled'      => 1,
			'accounts_grandfather_existing'  => 1,  // Accounts older than this module stay approved.
			'accounts_gate_level'            => 'practice', // none | practice | full
			'accounts_require_admin_review'  => 0,  // A member of staff must approve as well.
			'accounts_review_couriered'      => 0,  // Flag an approval the student carried themselves.
			'accounts_grace_minutes'         => 0,  // Minutes a new student may look around first.
			'accounts_code_ttl_minutes'      => 60,
			'accounts_code_max_attempts'     => 6,
			'accounts_resend_cooldown'       => 120, // Seconds between sends.
			'accounts_resend_daily_cap'      => 6,
			'accounts_link_ttl_hours'        => 72,
			'accounts_reminder_hours'        => 24, // 0 = never remind.
			'accounts_auto_decline_days'     => 0,  // 0 = never expire an account.
			'accounts_parent_email_enabled'  => 1,  // Also ask for a parent email address.
			'accounts_notify_admin'          => 1,
			'accounts_notify_admin_email'    => '', // Empty = the site admin address.
			'accounts_max_per_phone'         => 3,  // Flag a number used by more students than this.
			'accounts_class_required'        => 1,
			'accounts_dob_required'          => 0,
			'accounts_minor_age'             => 18, // Under this age, a parent must consent.
			'accounts_portal_page_id'        => 0,  // Page carrying [ces_parent_portal].
			'accounts_terms_url'             => '',
			'accounts_privacy_url'           => '',

			// ---------------- Consultant classrooms ----------------
			'classroom_enabled'            => 1,
			'classroom_applications_open'  => 1,   // Anyone may apply to be a consultant.
			'classroom_auto_approve'       => 0,   // Approve applications without review.
			'classroom_max_classes'        => 10,  // Per consultant.
			'classroom_max_students'       => 200, // Per class.
			'classroom_class_page_id'      => 0,   // Page carrying [ces_class].
			'classroom_directory_page_id'  => 0,   // Page carrying [ces_consultant_directory].
			'classroom_require_approval'   => 1,   // Students need the consultant's nod to join.
			'classroom_allow_public'       => 1,   // A consultant may list a class publicly.

			// ---------------- Video studio ----------------
			'classroom_recorder_enabled'   => 1,
			'classroom_recorder_minutes'   => 30,  // Longest single browser recording.
			'classroom_checkpoints'        => 1,   // Questions pinned to a timestamp.
			'classroom_require_plan'       => 0,   // A lesson plan before publishing.

			// ---------------- Live group teaching ----------------
			'classroom_sessions_enabled'   => 1,
			'classroom_session_sync'       => 1,   // The consultant drives everyone's playback.
			'classroom_session_meet_url'   => '',  // Optional external video-call link.
			'classroom_session_poll'       => 4,   // Seconds between room updates.
			'classroom_hand_raise'         => 1,

			// ---------------- Chat ----------------
			'classroom_chat_enabled'       => 1,
			'classroom_lobby_enabled'      => 1,   // The open, subject-based student rooms.
			'classroom_chat_slowmode'      => 5,   // Seconds a student must wait between messages.
			'classroom_chat_max_length'    => 300,
			'classroom_chat_history'       => 80,  // Messages loaded when a room opens.
			'classroom_chat_allow_links'   => 0,
			'classroom_chat_blocklist'     => '',  // Extra words to refuse, comma separated.
			'classroom_chat_premod'        => 0,   // Hold every student message for a moderator.
			'classroom_chat_poll'          => 6,   // Seconds between chat refreshes.

			// ---------------- Chat liveliness ----------------
			'classroom_crowd_enabled'      => 1,
			'classroom_crowd_quiet'        => 4,   // Only speak after this many quiet minutes.
			'classroom_crowd_per_hour'     => 6,   // Ceiling on automated messages per room.
			'classroom_crowd_activity'     => 1,   // Announce real scores, joins and streaks.
			'classroom_crowd_prompts'      => 1,   // Study Bot asks a question to get things going.

			// ---------------- General ----------------
			'dashboard_page_id'         => 0,
			'login_page_url'            => '/login/',
			'brand_primary'             => '#2c62cd',
			'delete_data_on_uninstall'  => 0,
		);
	}

	/**
	 * Integer-typed keys, used to coerce values on save.
	 *
	 * @return string[]
	 */
	public static function int_keys() {
		$ints = array();
		foreach ( self::defaults() as $key => $value ) {
			if ( is_int( $value ) ) {
				$ints[] = $key;
			}
		}
		return $ints;
	}

	/**
	 * Persist a partial set of settings, merged over what is stored.
	 *
	 * @param array $values Raw values to save.
	 * @return array The full, saved settings array.
	 */
	public static function save( array $values ) {
		$current  = CES_Helpers::settings();
		$defaults = self::defaults();
		$ints     = self::int_keys();
		$clean    = array();

		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			if ( in_array( $key, $ints, true ) ) {
				$clean[ $key ] = (int) $value;
				continue;
			}

			$clean[ $key ] = sanitize_text_field( (string) $value );
		}

		$merged = array_merge( $defaults, $current, $clean );
		update_option( self::OPTION, $merged );
		wp_cache_delete( 'ces_settings', 'ces' );

		return $merged;
	}
}
