<?php
/**
 * Accounts module entry point.
 *
 * Gmail sign-in, parent approval, and the gate that holds a student back
 * until a parent has said yes.
 *
 * Called once from `CES_Plugin::load_modules()` on `plugins_loaded` at
 * priority 20. Nothing in this module edits a file belonging to another one:
 * the gate is built from published filters, the parent record is read and
 * written through `CES_Parent_Contact`, and the dashboard tab is contributed
 * through `ces_dashboard_tabs`.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Module bootstrap.
 */
class CES_Accounts_Module {

	/**
	 * Hourly housekeeping event.
	 */
	const CRON_HOOK = 'ces_accounts_maintenance';

	/**
	 * Wire up the module.
	 *
	 * @return void
	 */
	public static function init() {
		// Records when this module first ran, which is what decides whether
		// an existing account is grandfathered past the gate.
		CES_Accounts_Status::installed_at();

		CES_Accounts_Google::init();
		CES_Accounts_Login::init();
		CES_Accounts_Onboarding::init();
		CES_Accounts_Portal::init();
		CES_Accounts_Dashboard::init();
		CES_Accounts_Privacy::init();

		if ( CES_Accounts_Status::approval_enabled() ) {
			CES_Accounts_Gate::init();
		}

		add_filter( 'ces_settings_sections', array( __CLASS__, 'settings_section' ) );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_admin_caps' ) );
		add_filter( 'ces_base_asset_shortcodes', array( __CLASS__, 'asset_shortcodes' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 25 );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_assets' ) );

		// Housekeeping: expire old requests, send the one reminder, and give
		// up on abandoned signups where the school asked for that.
		add_action( self::CRON_HOOK, array( CES_Accounts_Verification::class, 'maintenance' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cron' ), 20 );

		// Keep the admin counts honest.
		add_action( 'deleted_user', array( CES_Accounts_Status::class, 'flush_counts' ) );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ) );

		if ( is_admin() ) {
			CES_Accounts_Admin::init();
		}
	}

	/**
	 * Make sure the hourly event exists.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Put a new account at the start of the ladder.
	 *
	 * Covers every way a student can be created — Profile Builder,
	 * WooCommerce, wp-login.php, WP-CLI — not just our own Gmail button.
	 * Accounts made by an administrator from the Users screen are left alone,
	 * because those are usually staff.
	 *
	 * @param int $user_id New account.
	 * @return void
	 */
	public static function on_user_register( $user_id ) {
		CES_Accounts_Status::flush_counts();

		if ( ! CES_Accounts_Status::approval_enabled() ) {
			return;
		}

		$user_id = absint( $user_id );

		if ( CES_Accounts_Status::is_staff( $user_id ) ) {
			return;
		}

		if ( '' !== CES_Accounts_Status::raw_status( $user_id ) ) {
			return;
		}

		if ( is_admin() && current_user_can( 'create_users' ) ) {
			CES_Accounts_Status::approve(
				$user_id,
				array(
					'channel' => 'staff',
					'notify'  => false,
				)
			);

			return;
		}

		CES_Accounts_Status::set_status(
			$user_id,
			'onboarding',
			array(
				'actor_id' => $user_id,
				'event'    => 'signup',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Assets.
	 * ------------------------------------------------------------------ */

	/**
	 * Register the module stylesheet and script.
	 *
	 * @param bool $force Enqueue even when the base handle is not queued.
	 * @return void
	 */
	public static function register_assets( $force = false ) {
		if ( ! wp_style_is( 'ces-accounts', 'registered' ) ) {
			wp_register_style(
				'ces-accounts',
				CES_URL . 'modules/accounts/assets/accounts.css',
				array( 'ces-base' ),
				CES_VERSION
			);
		}

		if ( ! wp_script_is( 'ces-accounts', 'registered' ) ) {
			wp_register_script(
				'ces-accounts',
				CES_URL . 'modules/accounts/assets/accounts.js',
				array(),
				CES_VERSION,
				true
			);
		}

		if ( $force || wp_style_is( 'ces-base', 'enqueued' ) ) {
			wp_enqueue_style( 'ces-accounts' );
			wp_enqueue_script( 'ces-accounts' );
		}
	}

	/**
	 * Enqueue the styles from inside a render, whatever the page is.
	 *
	 * @return void
	 */
	public static function enqueue_style() {
		if ( class_exists( 'CES_Assets' ) ) {
			CES_Assets::enqueue_base();
		}

		self::register_assets( true );
	}

	/**
	 * The sign-in button's styles on wp-login.php.
	 *
	 * @return void
	 */
	public static function login_assets() {
		if ( ! CES_Accounts_Google::enabled() ) {
			return;
		}

		wp_enqueue_style(
			'ces-accounts-login',
			CES_URL . 'modules/accounts/assets/accounts.css',
			array(),
			CES_VERSION
		);
	}

	/**
	 * Pull the shared assets onto a page carrying one of our shortcodes.
	 *
	 * @param array $tags Shortcode tags.
	 * @return array
	 */
	public static function asset_shortcodes( $tags ) {
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}

		$tags[] = CES_Accounts_Login::SHORTCODE;
		$tags[] = CES_Accounts_Onboarding::SHORTCODE;
		$tags[] = CES_Accounts_Portal::SHORTCODE;

		return $tags;
	}

	/**
	 * Never lock a full administrator out of the Approvals screen.
	 *
	 * @param array $allcaps Capabilities for the user being tested.
	 * @return array
	 */
	public static function grant_admin_caps( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ CES_Accounts_Admin::CAP ] = true;
		}

		return $allcaps;
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
			'title'  => __( 'Gmail Sign-in', 'classic-edu-suite' ),
			'fields' => array(
				'accounts_google_enabled'       => array(
					'label' => __( 'Let students sign in with Gmail', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => sprintf(
						/* translators: %s: the redirect URI to register with Google. */
						__( 'Needs a client ID and secret from Google Cloud Console. Register this exact redirect URI there: %s', 'classic-edu-suite' ),
						CES_Accounts_Google::redirect_uri()
					),
				),
				'accounts_google_client_id'     => array(
					'label' => __( 'Google client ID', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Ends in .apps.googleusercontent.com', 'classic-edu-suite' ),
				),
				'accounts_google_client_secret' => array(
					'label' => __( 'Google client secret', 'classic-edu-suite' ),
					'type'  => 'password',
					'help'  => __( 'Stored server-side and never sent to the browser.', 'classic-edu-suite' ),
				),
				'accounts_button_label'         => array(
					'label' => __( 'Button text', 'classic-edu-suite' ),
					'type'  => 'text',
				),
				'accounts_inject_login_button'  => array(
					'label' => __( 'Add the button to login forms automatically', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Covers wp-login.php, WooCommerce and the page set as the login page. Use [ces_google_login] to place it anywhere else.', 'classic-edu-suite' ),
				),
				'accounts_google_create_users'  => array(
					'label' => __( 'Create an account on first sign-in', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Works even when WordPress registration is closed, so Gmail can be the only way in.', 'classic-edu-suite' ),
				),
				'accounts_google_link_existing' => array(
					'label' => __( 'Match a Gmail address to an existing account', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Safe because Google has verified the address belongs to whoever is signing in. Switch off and such a sign-in is refused instead.', 'classic-edu-suite' ),
				),
				'accounts_google_only'          => array(
					'label' => __( 'Refuse password logins for Gmail accounts', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Students who signed up with Gmail must keep using the button. Staff passwords always keep working.', 'classic-edu-suite' ),
				),
				'accounts_require_gmail'        => array(
					'label' => __( 'Only @gmail.com addresses', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Refuses a Google Workspace account on a company or school domain.', 'classic-edu-suite' ),
				),
				'accounts_allowed_domains'      => array(
					'label' => __( 'Allowed email domains', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Comma separated, e.g. gmail.com, myschool.edu.ng. Leave empty to allow any Google account. Ignored when the setting above is on.', 'classic-edu-suite' ),
				),
				'accounts_default_role'         => array(
					'label' => __( 'Role for new accounts', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'A role slug, usually subscriber or customer. A role that can manage the site is refused.', 'classic-edu-suite' ),
				),
				'accounts_google_avatar'        => array(
					'label' => __( 'Use the Google profile picture', 'classic-edu-suite' ),
					'type'  => 'checkbox',
				),
			),
		);

		$sections[] = array(
			'title'  => __( 'Parent Approval', 'classic-edu-suite' ),
			'fields' => array(
				'accounts_approval_enabled'     => array(
					'label' => __( 'Require a parent to approve each student', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'The master switch. Off, and nothing in this section applies.', 'classic-edu-suite' ),
				),
				'accounts_gate_level'           => array(
					'label'   => __( 'What is locked until approval', 'classic-edu-suite' ),
					'type'    => 'select',
					'options' => array(
						'none'     => __( 'Nothing — just record the status', 'classic-edu-suite' ),
						'practice' => __( 'Starting exams, study sessions and videos (recommended)', 'classic-edu-suite' ),
						'full'     => __( 'Everything this plugin provides', 'classic-edu-suite' ),
					),
					'help'    => __( 'The middle option leaves browsing open, which is the best argument for finishing the signup. Staff are never locked out.', 'classic-edu-suite' ),
				),
				'accounts_grandfather_existing' => array(
					'label' => __( 'Leave existing students alone', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Accounts that existed before this was switched on stay approved. Switch off to put every student through the process, including the ones already studying.', 'classic-edu-suite' ),
				),
				'accounts_require_admin_review' => array(
					'label' => __( 'Staff must approve as well', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'A parent’s yes puts the account in the queue instead of opening it. Flagged accounts always wait for staff, whatever this says.', 'classic-edu-suite' ),
				),
				'accounts_review_couriered'     => array(
					'label' => __( 'Check approvals the student carried', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Without a WhatsApp API or a parent email, the student forwards the request — so they can read the link and the code, and the answer proves less. Tick this and such an approval is flagged and waits for staff instead of opening the account. Leave it off for the least friction.', 'classic-edu-suite' ),
				),
				'accounts_grace_minutes'        => array(
					'label' => __( 'Look-around time, in minutes', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'A brand new student may use the site freely for this long before the gate applies. 0 switches it off.', 'classic-edu-suite' ),
				),
				'accounts_class_required'       => array(
					'label' => __( 'Ask for the student’s class', 'classic-edu-suite' ),
					'type'  => 'checkbox',
				),
				'accounts_dob_required'         => array(
					'label' => __( 'Ask for the student’s date of birth', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Only worth asking if you act on it. A student under the age below is always held for staff review.', 'classic-edu-suite' ),
				),
				'accounts_minor_age'            => array(
					'label' => __( 'Age of majority', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'accounts_parent_email_enabled' => array(
					'label' => __( 'Ask for a parent email address too', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Optional for the student, but it lets the site write to the parent itself instead of relying on the student to forward a message.', 'classic-edu-suite' ),
				),
				'accounts_code_ttl_minutes'     => array(
					'label' => __( 'Code lasts, in minutes', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'accounts_link_ttl_hours'       => array(
					'label' => __( 'Approval link lasts, in hours', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Longer than the code, because a parent may not look at WhatsApp for a day.', 'classic-edu-suite' ),
				),
				'accounts_code_max_attempts'    => array(
					'label' => __( 'Tries allowed at the code', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'accounts_resend_cooldown'      => array(
					'label' => __( 'Wait between sends, in seconds', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'accounts_resend_daily_cap'     => array(
					'label' => __( 'Sends allowed per student per day', 'classic-edu-suite' ),
					'type'  => 'number',
				),
				'accounts_reminder_hours'       => array(
					'label' => __( 'Remind a silent parent after, in hours', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'One reminder only, ever. 0 switches reminders off.', 'classic-edu-suite' ),
				),
				'accounts_auto_decline_days'    => array(
					'label' => __( 'Give up on an unfinished signup after, in days', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( '0 means never. An abandoned signup is not a problem, and a student who comes back three weeks later should be able to finish.', 'classic-edu-suite' ),
				),
				'accounts_max_per_phone'        => array(
					'label' => __( 'Flag a parent number used by more students than', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Siblings are normal. Fifteen accounts on one number is not.', 'classic-edu-suite' ),
				),
				'accounts_notify_admin'         => array(
					'label' => __( 'Email staff when an account needs review', 'classic-edu-suite' ),
					'type'  => 'checkbox',
				),
				'accounts_notify_admin_email'   => array(
					'label' => __( 'Send those emails to', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Leave empty to use the site’s admin address.', 'classic-edu-suite' ),
				),
				'accounts_portal_page_id'       => array(
					'label' => __( 'Parent page ID', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'A page containing [ces_parent_portal], so the parent’s approval page wears your theme. Leave as 0 and the plugin prints a plain, self-contained page instead.', 'classic-edu-suite' ),
				),
				'accounts_terms_url'            => array(
					'label' => __( 'Terms of use URL', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Set this and students must tick to accept at signup. Leave empty for no tick-box.', 'classic-edu-suite' ),
				),
				'accounts_privacy_url'          => array(
					'label' => __( 'Privacy notice URL', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Linked from the signup form, under what we tell the parent we will send them.', 'classic-edu-suite' ),
				),
			),
		);

		return $sections;
	}
}
