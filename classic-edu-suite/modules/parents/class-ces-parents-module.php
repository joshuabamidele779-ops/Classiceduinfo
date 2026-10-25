<?php
/**
 * Parents module entry point.
 *
 * Collects one parent or guardian WhatsApp number per student, keeps it in
 * user meta, and gives staff a ready-written progress update to send.
 *
 * Called once from `CES_Plugin::load_modules()` on `plugins_loaded` at
 * priority 20.
 *
 * @package ClassicEduSuite\Parents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Module bootstrap.
 */
class CES_Parents_Module {

	/**
	 * Wire up the module.
	 *
	 * @return void
	 */
	public static function init() {
		CES_Parents_Fields::init();
		CES_Parents_Dashboard::init();

		add_filter( 'ces_settings_sections', array( __CLASS__, 'settings_section' ) );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_admin_caps' ) );
		add_filter( 'ces_base_asset_shortcodes', array( __CLASS__, 'asset_shortcodes' ) );
		// After CES_Assets::maybe_enqueue(), which runs at 20 and decides
		// whether this page carries one of our shortcodes at all.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 25 );

		// Optional gate: no practice until the number is on file.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'gate_rest' ), 10, 3 );

		// Keep the coverage card honest.
		add_action( 'deleted_user', array( __CLASS__, 'flush_coverage' ) );
		add_action( 'ces_parent_contact_saved', array( __CLASS__, 'flush_coverage' ) );

		if ( is_admin() ) {
			CES_Parents_Admin::init();
		}
	}

	/**
	 * Register the module stylesheet and attach it to the shared base handle.
	 *
	 * The form appears inside the dashboard, inside a shortcode and
	 * potentially inside a popup, so it rides along with `ces-base` rather
	 * than trying to guess which pages need it.
	 *
	 * @return void
	 */
	public static function register_assets( $force = false ) {
		if ( ! wp_style_is( 'ces-parents', 'registered' ) ) {
			wp_register_style(
				'ces-parents',
				CES_URL . 'modules/parents/assets/parents.css',
				array( 'ces-base' ),
				CES_VERSION
			);
		}

		if ( $force || wp_style_is( 'ces-base', 'enqueued' ) ) {
			wp_enqueue_style( 'ces-parents' );
		}
	}

	/**
	 * Enqueue the form styles from inside a render, whatever the page is.
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
	 * Pull the shared assets onto a page carrying our shortcode.
	 *
	 * @param array $tags Shortcode tags.
	 * @return array
	 */
	public static function asset_shortcodes( $tags ) {
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}

		$tags[] = 'ces_parent_contact';

		return $tags;
	}

	/**
	 * Refuse to start a CBT attempt while the parent number is missing.
	 *
	 * Implemented as a REST pre-dispatch guard rather than an edit to the CBT
	 * module, so the two modules stay independent and the gate can be removed
	 * by switching one setting off.
	 *
	 * @param mixed           $result  Response to replace the request with, if any.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return mixed
	 */
	public static function gate_rest( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result ) {
			return $result;
		}

		if ( ! CES_Helpers::enabled( 'parents_capture_enabled' ) || ! CES_Helpers::enabled( 'parents_block_until_provided' ) ) {
			return $result;
		}

		if ( ! ( $request instanceof WP_REST_Request ) || 'POST' !== $request->get_method() ) {
			return $result;
		}

		$route = (string) $request->get_route();

		if ( '/' . CES_REST_NAMESPACE . '/cbt/attempts' !== $route ) {
			return $result;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id || CES_Parent_Contact::has_number( $user_id ) ) {
			return $result;
		}

		// Staff are never locked out of their own site.
		if ( CES_Helpers::can_manage( 'ces_manage_cbt' ) ) {
			return $result;
		}

		return new WP_Error(
			'ces_parent_required',
			__( 'Add your parent or guardian’s WhatsApp number on your dashboard before starting a practice exam.', 'classic-edu-suite' ),
			array(
				'status' => 403,
				'action' => CES_Helpers::dashboard_url( CES_Parents_Dashboard::SLUG ),
			)
		);
	}

	/**
	 * Never lock a full administrator out of the Parents screen.
	 *
	 * The activator grants `ces_manage_parents`, but an install upgraded by
	 * copying files over would not have run it. Anyone who can
	 * `manage_options` is an administrator anyway.
	 *
	 * @param array $allcaps Capabilities for the user being tested.
	 * @return array
	 */
	public static function grant_admin_caps( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ CES_Parents_Admin::CAP ] = true;
		}

		return $allcaps;
	}

	/**
	 * Drop the cached coverage counts.
	 *
	 * @return void
	 */
	public static function flush_coverage() {
		delete_transient( 'ces_parent_coverage' );
	}

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
			'title'  => __( 'Parents & Guardians', 'classic-edu-suite' ),
			'fields' => array(
				'parents_capture_enabled'      => array(
					'label' => __( 'Collect parent contacts', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Shows the parent fields at signup and the Parent tab on the student dashboard.', 'classic-edu-suite' ),
				),
				'parents_required_at_signup'   => array(
					'label' => __( 'Required at signup', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Only applies to registration forms that show our fields. Profile Builder renders its own form, so students who register there are asked on the dashboard instead.', 'classic-edu-suite' ),
				),
				'parents_block_until_provided' => array(
					'label' => __( 'Block practice until provided', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'A student cannot start a CBT paper until a parent number is on file. Staff accounts are never blocked.', 'classic-edu-suite' ),
				),
				'parents_consent_required'     => array(
					'label' => __( 'Require a consent tick', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'The student must confirm the parent agreed to be contacted.', 'classic-edu-suite' ),
				),
				'parents_default_country'      => array(
					'label' => __( 'Default country code', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Digits only. 234 for Nigeria. Local numbers like 0803… are stored with this in front.', 'classic-edu-suite' ),
				),
				'parents_consent_text'         => array(
					'label' => __( 'Text shown under the fields', 'classic-edu-suite' ),
					'type'  => 'text',
				),
				'parents_report_template'      => array(
					'label' => __( 'Progress message template', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Placeholders: {parent} {student} {first_name} {exams} {average} {best} {passes} {coins} {videos} {questions} {days} {last_active} {strong} {weak} {subject_line} {site} {site_url} {dashboard}. Use \n for a line break. Leave empty for the built-in wording.', 'classic-edu-suite' ),
				),
				'parents_wa_api_enabled'       => array(
					'label' => __( 'Send through the WhatsApp Cloud API', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Optional. Without this, staff send with one click through wa.me, which needs no approval.', 'classic-edu-suite' ),
				),
				'parents_wa_phone_id'          => array(
					'label' => __( 'WhatsApp phone number ID', 'classic-edu-suite' ),
					'type'  => 'text',
				),
				'parents_wa_token'             => array(
					'label' => __( 'WhatsApp access token', 'classic-edu-suite' ),
					'type'  => 'password',
					'help'  => __( 'Stored server-side and never sent to the browser.', 'classic-edu-suite' ),
				),
				'parents_wa_template'          => array(
					'label' => __( 'Approved template name', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Required for a first message. The template needs exactly one body parameter, which receives the summary sentence.', 'classic-edu-suite' ),
				),
				'parents_wa_language'          => array(
					'label' => __( 'Template language code', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'For example en or en_US. Must match the approved template.', 'classic-edu-suite' ),
				),
			),
		);

		return $sections;
	}
}
