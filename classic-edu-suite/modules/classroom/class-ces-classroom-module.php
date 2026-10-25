<?php
/**
 * Classroom module entry point.
 *
 * Consultant classes, the video studio, live group teaching and chat.
 *
 * Called once from `CES_Plugin::load_modules()` on `plugins_loaded` at
 * priority 20. It loads after the LMS, whose player, storage, tokens and
 * uploader it reuses wholesale, and after accounts, whose audit trail and
 * email shell it writes into.
 *
 * ### The one borrowed capability
 *
 * The LMS's chunked uploader is gated on `ces_manage_lms`, which also means
 * "may watch every video on the site for free". A consultant must never have
 * that. So instead of granting it, this module lends it for the duration of
 * one of four admin-ajax upload requests, where nothing evaluates video
 * access at all — and the uploader's own `current_user_can( 'edit_post' )`
 * check then confines them to lessons they authored. See `lend_upload_cap()`.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Module bootstrap.
 */
class CES_Classroom_Module {

	/**
	 * Hourly housekeeping event.
	 */
	const CRON_HOOK = 'ces_classroom_maintenance';

	/**
	 * Wire up the module.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! CES_Helpers::enabled( 'classroom_enabled' ) ) {
			return;
		}

		CES_Classroom_Cpt::init();
		CES_Classroom_Access::init();
		CES_Classroom_Studio::init();
		CES_Classroom_Room::init();
		CES_Classroom_Shortcodes::init();
		CES_Classroom_Dashboard::init();
		CES_Classroom_Rest::init();

		add_filter( 'ces_settings_sections', array( __CLASS__, 'settings_section' ) );
		add_filter( 'user_has_cap', array( __CLASS__, 'lend_upload_cap' ), 20 );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );

		add_action( self::CRON_HOOK, array( __CLASS__, 'maintenance' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cron' ), 20 );

		// When an account goes, so do its rosters and its messages.
		add_action( 'deleted_user', array( CES_Classroom_Enrolment::class, 'delete_user' ) );
		add_action( 'deleted_user', array( CES_Chat::class, 'delete_user' ) );

		// A student arriving on a share link joins without a second click.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_autojoin' ), 6 );

		if ( is_admin() ) {
			CES_Classroom_Admin::init();
		}
	}

	/**
	 * Make sure the hourly event exists.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 7 * MINUTE_IN_SECONDS ), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Hourly housekeeping.
	 *
	 * @return void
	 */
	public static function maintenance() {
		CES_Chat::prune();
		CES_Classroom_Session::send_reminders();
	}

	/**
	 * Lend a consultant the LMS upload capability, for an upload only.
	 *
	 * Narrow on purpose: the request must be an admin-ajax call, its action
	 * must be one of the four upload actions, and the account must already
	 * hold `ces_consultant`. Within such a request nothing consults
	 * `CES_Lms_Access::is_staff()`, so the capability cannot leak into free
	 * video access, and the uploader still refuses a lesson the consultant
	 * did not author.
	 *
	 * @param array $allcaps Capabilities for the user being tested.
	 * @return array
	 */
	public static function lend_upload_cap( $allcaps ) {
		if ( empty( $allcaps[ CES_Consultant::CAP ] ) || ! empty( $allcaps['ces_manage_lms'] ) ) {
			return $allcaps;
		}

		if ( ! wp_doing_ajax() ) {
			return $allcaps;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the uploader verifies its own nonce; this only reads which action was asked for.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( ! in_array( $action, CES_Classroom_Recorder::$upload_actions, true ) ) {
			return $allcaps;
		}

		$allcaps['ces_manage_lms'] = true;

		// The uploader needs the lesson post-type capabilities too, which the
		// consultant role already carries for its own posts.
		$allcaps['upload_files'] = true;

		return $allcaps;
	}

	/**
	 * Join a class straight from a share link.
	 *
	 * A link that needs a second click to actually join loses people, and the
	 * link is already the credential. Only the open kind joins silently; a
	 * class that wants approval records the request and says so.
	 *
	 * @return void
	 */
	public static function maybe_autojoin() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a share link, and the join is idempotent.
		$code = isset( $_GET['ces_class'] ) ? sanitize_text_field( wp_unslash( $_GET['ces_class'] ) ) : '';

		if ( '' === $code || ! is_user_logged_in() ) {
			return;
		}

		$class_id = CES_Classroom_Cpt::by_code( $code );

		if ( ! $class_id ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( '' !== CES_Classroom_Enrolment::status( $class_id, $user_id ) ) {
			return;
		}

		if ( CES_Classroom_Cpt::owner( $class_id ) === $user_id ) {
			return;
		}

		CES_Classroom_Enrolment::join( $class_id, $user_id, 'link' );
	}

	/* ---------------------------------------------------------------------
	 * Assets.
	 * ------------------------------------------------------------------ */

	/**
	 * Register the module's styles and scripts.
	 *
	 * @return void
	 */
	public static function register_assets() {
		if ( ! wp_style_is( 'ces-classroom', 'registered' ) ) {
			wp_register_style(
				'ces-classroom',
				CES_URL . 'modules/classroom/assets/classroom.css',
				array( 'ces-base' ),
				CES_VERSION
			);
		}

		foreach ( array( 'chat', 'studio', 'recorder', 'room' ) as $handle ) {
			if ( wp_script_is( 'ces-classroom-' . $handle, 'registered' ) ) {
				continue;
			}

			wp_register_script(
				'ces-classroom-' . $handle,
				CES_URL . 'modules/classroom/assets/' . $handle . '.js',
				array( 'ces-base' ),
				CES_VERSION,
				true
			);
		}
	}

	/**
	 * The `window.CESClass` payload every one of our scripts reads.
	 *
	 * @return array
	 */
	public static function config() {
		$upload = CES_Classroom_Recorder::upload_config();

		return array(
			'rest'      => esc_url_raw( trailingslashit( rest_url( CES_REST_NAMESPACE ) ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'userId'    => (int) get_current_user_id(),
			'chatPoll'  => max( 3, CES_Helpers::setting_int( 'classroom_chat_poll', 6 ) ),
			'slowmode'  => max( 0, CES_Helpers::setting_int( 'classroom_chat_slowmode', 5 ) ),
			'maxLength' => max( 20, CES_Helpers::setting_int( 'classroom_chat_max_length', 300 ) ),
			'canMod'    => current_user_can( 'ces_moderate_chat' ),
			'upload'    => $upload,
			'studioUrl' => esc_url_raw( CES_Helpers::dashboard_url( CES_Classroom_Studio::SLUG ) ),
			'i18n'      => array(
				'sending'      => __( 'Sending…', 'classic-edu-suite' ),
				'saved'        => __( 'Saved', 'classic-edu-suite' ),
				'saveFailed'   => __( 'That did not save. Try again.', 'classic-edu-suite' ),
				'copied'       => __( 'Copied', 'classic-edu-suite' ),
				'copyFailed'   => __( 'Copy it by hand — select the link and press Ctrl+C.', 'classic-edu-suite' ),
				'joining'      => __( 'Joining…', 'classic-edu-suite' ),
				'joined'       => __( 'You are in. Loading the lessons…', 'classic-edu-suite' ),
				'requested'    => __( 'Asked to join. Your tutor will let you in.', 'classic-edu-suite' ),
				'botLabel'     => __( 'BOT', 'classic-edu-suite' ),
				'report'       => __( 'Report this message', 'classic-edu-suite' ),
				'reported'     => __( 'Reported. Thank you.', 'classic-edu-suite' ),
				'hide'         => __( 'Hide', 'classic-edu-suite' ),
				'restore'      => __( 'Restore', 'classic-edu-suite' ),
				'held'         => __( 'Waiting for a moderator', 'classic-edu-suite' ),
				'hidden'       => __( 'Hidden by a moderator', 'classic-edu-suite' ),
				'empty'        => __( 'Nothing here yet. Say the first thing.', 'classic-edu-suite' ),
				'slowWait'     => __( 'Wait a moment before sending again.', 'classic-edu-suite' ),
				'uploading'    => __( 'Uploading', 'classic-edu-suite' ),
				'processing'   => __( 'Uploaded. The site is processing the video now — you can close this page.', 'classic-edu-suite' ),
				'uploadFailed' => __( 'The upload failed. Check your connection and try again — it will resume.', 'classic-edu-suite' ),
				'recording'    => __( 'Recording', 'classic-edu-suite' ),
				'paused'       => __( 'Paused', 'classic-edu-suite' ),
				'resume'       => __( 'Resume', 'classic-edu-suite' ),
				'pause'        => __( 'Pause', 'classic-edu-suite' ),
				'noPermission' => __( 'The browser would not give us the screen or microphone. Check the address bar for a blocked permission.', 'classic-edu-suite' ),
				'tooQuiet'     => __( 'We can barely hear you. Move closer, or use earphones with a microphone.', 'classic-edu-suite' ),
				'goodLevel'    => __( 'That level is good.', 'classic-edu-suite' ),
				'tooLoud'      => __( 'A little loud — back off slightly to avoid distortion.', 'classic-edu-suite' ),
				'handUp'       => __( '✋ Put my hand up', 'classic-edu-suite' ),
				'handDown'     => __( 'Take my hand down', 'classic-edu-suite' ),
				'live'         => __( 'Live now', 'classic-edu-suite' ),
				'ended'        => __( 'Finished', 'classic-edu-suite' ),
				'nobodyHere'   => __( 'nobody here yet', 'classic-edu-suite' ),
				'onePerson'    => __( '1 person here', 'classic-edu-suite' ),
				'manyPeople'   => __( '%d people here', 'classic-edu-suite' ),
				'correct'      => __( 'Correct', 'classic-edu-suite' ),
				'notQuite'     => __( 'Not quite', 'classic-edu-suite' ),
				'confirmLeave' => __( 'Leave this class? You will lose access to its lessons.', 'classic-edu-suite' ),
				'confirmCode'  => __( 'Change the code? The old link and code stop working for anybody who has not joined yet.', 'classic-edu-suite' ),
			),
		);
	}

	/**
	 * Print the config once, before any of our scripts run.
	 *
	 * @param string $handle The script handle being enqueued.
	 * @return void
	 */
	private static function attach_config( $handle ) {
		static $done = array();

		if ( isset( $done[ $handle ] ) ) {
			return;
		}

		$done[ $handle ] = true;

		wp_add_inline_script(
			$handle,
			'window.CESClass = window.CESClass || {}; Object.assign( window.CESClass, ' . wp_json_encode( self::config() ) . ' );',
			'before'
		);
	}

	/**
	 * Enqueue the stylesheet and the base handle.
	 *
	 * @return void
	 */
	public static function enqueue_front() {
		if ( class_exists( 'CES_Assets' ) ) {
			CES_Assets::enqueue_base();
		}

		self::register_assets();
		wp_enqueue_style( 'ces-classroom' );

		// The small front-end behaviours — join, leave, copy — live in the
		// chat bundle, which every one of these pages wants anyway.
		self::attach_config( 'ces-classroom-chat' );
		wp_enqueue_script( 'ces-classroom-chat' );
	}

	/**
	 * Everything the chat needs.
	 *
	 * @return void
	 */
	public static function enqueue_chat() {
		self::enqueue_front();
	}

	/**
	 * Everything the Studio needs, including the recorder.
	 *
	 * @return void
	 */
	public static function enqueue_studio() {
		self::enqueue_front();

		self::attach_config( 'ces-classroom-studio' );
		wp_enqueue_script( 'ces-classroom-studio' );

		if ( CES_Classroom_Recorder::enabled() ) {
			self::attach_config( 'ces-classroom-recorder' );
			wp_enqueue_script( 'ces-classroom-recorder' );
		}
	}

	/**
	 * Everything the live room needs.
	 *
	 * @return void
	 */
	public static function enqueue_room() {
		self::enqueue_front();

		self::attach_config( 'ces-classroom-room' );
		wp_enqueue_script( 'ces-classroom-room' );
	}

	/* ---------------------------------------------------------------------
	 * Settings.
	 * ------------------------------------------------------------------ */

	/**
	 * Settings fields for this module.
	 *
	 * @param array $sections Sections so far.
	 * @return array
	 */
	public static function settings_section( $sections ) {
		if ( ! is_array( $sections ) ) {
			$sections = array();
		}

		$sections[] = array(
			'title'  => __( 'Consultant Classrooms', 'classic-edu-suite' ),
			'fields' => array(
				'classroom_enabled'           => array(
					'label' => __( 'Let outside tutors host classes', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'The master switch for classes, the Studio, live sessions and chat.', 'classic-edu-suite' ),
				),
				'classroom_applications_open' => array(
					'label' => __( 'Accept new consultant applications', 'classic-edu-suite' ),
					'type'  => 'checkbox',
				),
				'classroom_auto_approve'      => array(
					'label' => __( 'Approve applications automatically', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Not recommended. A consultant can upload video children watch and run a chat room with a roster of them — that is worth a person reading the application.', 'classic-edu-suite' ),
				),
				'classroom_max_classes'       => array(
					'label' => __( 'Classes per consultant', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'classroom_max_students'      => array(
					'label' => __( 'Students per class', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'classroom_require_approval'  => array(
					'label' => __( 'Students need the tutor’s approval by default', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'A share link gets forwarded; this is what stops a forwarded link filling somebody’s class with strangers. Each tutor can change it per class.', 'classic-edu-suite' ),
				),
				'classroom_allow_public'      => array(
					'label' => __( 'Let tutors list classes publicly', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Public classes appear in the tutor directory. Off, every class is link-only.', 'classic-edu-suite' ),
				),
				'classroom_class_page_id'     => array(
					'label' => __( 'Class page ID', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Optional. A page containing [ces_class] to use for share links instead of the class’s own URL.', 'classic-edu-suite' ),
				),
				'classroom_directory_page_id' => array(
					'label' => __( 'Tutor directory page ID', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Optional. A page containing [ces_consultant_directory].', 'classic-edu-suite' ),
				),
			),
		);

		$sections[] = array(
			'title'  => __( 'Video Studio', 'classic-edu-suite' ),
			'fields' => array(
				'classroom_recorder_enabled' => array(
					'label' => __( 'Record in the browser', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Screen, camera and voice, with no software to install. Recordings are WebM, which this site can stream even without ffmpeg.', 'classic-edu-suite' ),
				),
				'classroom_recorder_minutes' => array(
					'label' => __( 'Longest single recording, in minutes', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'The browser holds the whole recording in memory, so very long ones are risky on a cheap laptop. Thirty is sensible; a lesson should be shorter than that anyway.', 'classic-edu-suite' ),
				),
				'classroom_checkpoints'      => array(
					'label' => __( 'Allow questions pinned into a video', 'classic-edu-suite' ),
					'type'  => 'checkbox',
				),
				'classroom_require_plan'     => array(
					'label' => __( 'Require an objective before publishing', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'A lesson cannot be published until the tutor has written what students will be able to do. Raises quality; adds friction.', 'classic-edu-suite' ),
				),
			),
		);

		$sections[] = array(
			'title'  => __( 'Live Sessions', 'classic-edu-suite' ),
			'fields' => array(
				'classroom_sessions_enabled' => array(
					'label' => __( 'Allow live sessions', 'classic-edu-suite' ),
					'type'  => 'checkbox',
				),
				'classroom_session_sync'     => array(
					'label' => __( 'The tutor drives everyone’s video', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Play, pause and skip on the tutor’s player move every student’s. Off, students watch at their own pace with the chat still shared.', 'classic-edu-suite' ),
				),
				'classroom_session_poll'     => array(
					'label' => __( 'Room refresh, in seconds', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Lower feels more live and costs more requests. Four is a good balance for thirty students on shared hosting.', 'classic-edu-suite' ),
				),
				'classroom_hand_raise'       => array(
					'label' => __( 'Let students raise a hand', 'classic-edu-suite' ),
					'type'  => 'checkbox',
				),
				'classroom_session_meet_url' => array(
					'label' => __( 'Fallback video-call link', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Optional. A standing Zoom or Meet link offered in every room that has none of its own. Real two-way video needs a service like those; this site streams one-way and runs the chat.', 'classic-edu-suite' ),
				),
			),
		);

		$sections[] = array(
			'title'  => __( 'Student Chat', 'classic-edu-suite' ),
			'fields' => array(
				'classroom_chat_enabled'     => array(
					'label' => __( 'Switch chat on', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Class rooms, live-session rooms and the open subject rooms. There are no private messages anywhere in this plugin, by design.', 'classic-edu-suite' ),
				),
				'classroom_lobby_enabled'    => array(
					'label' => __( 'Open the subject rooms to all students', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'The rooms any approved student can use. Off, chat exists only inside a tutor’s class.', 'classic-edu-suite' ),
				),
				'classroom_chat_slowmode'    => array(
					'label' => __( 'Seconds between a student’s messages', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'classroom_chat_max_length'  => array(
					'label' => __( 'Longest message, in characters', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'classroom_chat_history'     => array(
					'label' => __( 'Messages loaded when a room opens', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Four times this many are kept per room; older ones are pruned hourly.', 'classic-edu-suite' ),
				),
				'classroom_chat_poll'        => array(
					'label' => __( 'Chat refresh, in seconds', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'classroom_chat_allow_links' => array(
					'label' => __( 'Allow links, emails and phone numbers', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Off is strongly recommended. Getting a child to move the conversation somewhere unwatched is how most harm starts.', 'classic-edu-suite' ),
				),
				'classroom_chat_premod'      => array(
					'label' => __( 'Hold every student message for a moderator', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Safest and slowest. Students see their own held message and nobody else does until it is released.', 'classic-edu-suite' ),
				),
				'classroom_chat_blocklist'   => array(
					'label' => __( 'Extra blocked words', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Comma separated, added to the built-in list. Whole words only.', 'classic-edu-suite' ),
				),
			),
		);

		$sections[] = array(
			'title'  => __( 'Chat Liveliness', 'classic-edu-suite' ),
			'fields' => array(
				'classroom_crowd_enabled'  => array(
					'label' => __( 'Keep quiet rooms from feeling empty', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Automated helpers post a question or a tip when a room has been silent and somebody is looking at it. Every one of them is badged BOT next to its name — students are never led to think they are classmates.', 'classic-edu-suite' ),
				),
				'classroom_crowd_quiet'    => array(
					'label' => __( 'Only speak after this many quiet minutes', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'classroom_crowd_per_hour' => array(
					'label' => __( 'Most automated messages per room per hour', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Keep it low. A room full of bot chatter is worse than a quiet one.', 'classic-edu-suite' ),
				),
				'classroom_crowd_prompts'  => array(
					'label' => __( 'Helpers ask questions to get things going', 'classic-edu-suite' ),
					'type'  => 'checkbox',
				),
				'classroom_crowd_activity' => array(
					'label' => __( 'Announce real scores and joins', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'True things that have just happened. Named inside a class, where everyone knows each other; anonymised in the open rooms, because a child’s score is not for a room of strangers.', 'classic-edu-suite' ),
				),
			),
		);

		return $sections;
	}
}
