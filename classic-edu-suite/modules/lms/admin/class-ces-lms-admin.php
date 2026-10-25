<?php
/**
 * LMS admin screens and notices.
 *
 * Screens are registered through the shared `ces_admin_submenus` filter, so
 * this module never edits `admin/class-ces-admin-menu.php`.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin bootstrap for the video library.
 */
class CES_Lms_Admin {

	/**
	 * Capability required for every LMS admin screen.
	 */
	const CAP = 'ces_manage_lms';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_admin_submenus', array( __CLASS__, 'submenus' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * Register our admin screens.
	 *
	 * @param array $submenus Existing submenu definitions.
	 * @return array
	 */
	public static function submenus( $submenus ) {
		if ( ! is_array( $submenus ) ) {
			$submenus = array();
		}

		$submenus[] = array(
			'slug'       => 'ces-lms',
			'title'      => __( 'Video Library', 'classic-edu-suite' ),
			'menu_title' => __( 'Video Library', 'classic-edu-suite' ),
			'capability' => self::CAP,
			'callback'   => array( __CLASS__, 'render_library' ),
			'position'   => 30,
		);

		$submenus[] = array(
			'slug'       => 'ces-lms-upload',
			'title'      => __( 'Upload Video', 'classic-edu-suite' ),
			'menu_title' => __( 'Upload Video', 'classic-edu-suite' ),
			'capability' => self::CAP,
			'callback'   => array( 'CES_Lms_Admin_Upload', 'render_page' ),
			'position'   => 31,
		);

		$submenus[] = array(
			'slug'       => 'ces-lms-queue',
			'title'      => __( 'Transcode Queue', 'classic-edu-suite' ),
			'menu_title' => __( 'Transcode Queue', 'classic-edu-suite' ),
			'capability' => self::CAP,
			'callback'   => array( 'CES_Lms_Admin_Queue', 'render_page' ),
			'position'   => 32,
		);

		$submenus[] = array(
			'slug'       => 'ces-lms-analytics',
			'title'      => __( 'Video Analytics', 'classic-edu-suite' ),
			'menu_title' => __( 'Video Analytics', 'classic-edu-suite' ),
			'capability' => self::CAP,
			'callback'   => array( 'CES_Lms_Admin_Analytics', 'render_page' ),
			'position'   => 33,
		);

		$submenus[] = array(
			'slug'       => 'ces-lms-diagnostics',
			'title'      => __( 'Video Diagnostics', 'classic-edu-suite' ),
			'menu_title' => __( 'Video Diagnostics', 'classic-edu-suite' ),
			'capability' => self::CAP,
			'callback'   => array( 'CES_Lms_Admin_Diagnostics', 'render_page' ),
			'position'   => 34,
		);

		return $submenus;
	}

	/**
	 * Whether the current admin screen belongs to this module.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	public static function is_our_screen( $hook ) {
		if ( false !== strpos( (string) $hook, 'ces-lms' ) ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && in_array( $screen->post_type, array( CES_Lms_Cpt::LESSON, CES_Lms_Cpt::COURSE ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( ! self::is_our_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'ces-lms-admin',
			CES_URL . 'modules/lms/assets/lms-admin.css',
			array(),
			CES_VERSION
		);

		wp_enqueue_script(
			'ces-lms-admin',
			CES_URL . 'modules/lms/assets/lms-admin.js',
			array(),
			CES_VERSION,
			true
		);

		wp_localize_script(
			'ces-lms-admin',
			'cesLmsAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( CES_Lms_Admin_Upload::NONCE ),
				'chunkSize' => CES_Lms_Admin_Upload::chunk_size(),
				'maxBytes'  => CES_Lms_Admin_Upload::max_bytes(),
				'i18n'      => array(
					'pick'       => __( 'Choose a video file', 'classic-edu-suite' ),
					'uploading'  => __( 'Uploading…', 'classic-edu-suite' ),
					'processing' => __( 'Upload finished. Preparing the video…', 'classic-edu-suite' ),
					'done'       => __( 'Done. The video is queued for packaging.', 'classic-edu-suite' ),
					'failed'     => __( 'Upload failed.', 'classic-edu-suite' ),
					'tooBig'     => __( 'That file is larger than the configured limit.', 'classic-edu-suite' ),
					'badType'    => __( 'That file type is not accepted. Use MP4, MOV, MKV, WebM or M4V.', 'classic-edu-suite' ),
					'retrying'   => __( 'Network hiccup — retrying that chunk…', 'classic-edu-suite' ),
					'saveOrder'  => __( 'Saving order…', 'classic-edu-suite' ),
					'orderSaved' => __( 'Order saved.', 'classic-edu-suite' ),
				),
			)
		);
	}

	/**
	 * Environment notices: what works on this host and what does not.
	 *
	 * @return void
	 */
	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! self::is_our_screen( $screen->id ) || ! current_user_can( self::CAP ) ) {
			return;
		}

		$caps = CES_Lms_Transcode::capabilities();

		if ( '' === $caps['ffmpeg'] ) {
			echo '<div class="notice notice-warning"><p><strong>'
				. esc_html__( 'No ffmpeg on this server.', 'classic-edu-suite' ) . '</strong> '
				. esc_html__( 'Lessons will still work: each video streams as a single protected file instead of an adaptive HLS ladder. Students get one quality, which costs them more mobile data.', 'classic-edu-suite' )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=ces-lms-diagnostics' ) ) . '">'
				. esc_html__( 'See diagnostics', 'classic-edu-suite' ) . '</a></p></div>';
		}

		if ( 'readfile' === CES_Lms_Stream::delivery_method() ) {
			echo '<div class="notice notice-warning"><p><strong>'
				. esc_html__( 'Video is being delivered by PHP.', 'classic-edu-suite' ) . '</strong> '
				. esc_html__( 'Every student watching holds a PHP worker for the whole transfer, and shared hosting only allows a handful at once. Run the delivery test to switch on server-level delivery.', 'classic-edu-suite' )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=ces-lms-diagnostics' ) ) . '">'
				. esc_html__( 'Run the delivery test', 'classic-edu-suite' ) . '</a></p></div>';
		}

		$driver = CES_Lms_Storage_Factory::active();

		if ( $driver ) {
			$config = $driver->check_config();

			if ( is_wp_error( $config ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $config->get_error_message() ) . '</p></div>';
			}
		}
	}

	// ------------------------------------------------------------------
	// Library screen.
	// ------------------------------------------------------------------

	/**
	 * The main video library overview.
	 *
	 * @return void
	 */
	public static function render_library() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'classic-edu-suite' ) );
		}

		$lessons = get_posts(
			array(
				'post_type'      => CES_Lms_Cpt::LESSON,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 50,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$caps = CES_Lms_Transcode::capabilities();

		echo '<div class="wrap ces-wrap ces-lms-admin">';
		echo '<h1>' . esc_html__( 'Video Library', 'classic-edu-suite' ) . '</h1>';

		echo '<p class="ces-sub">' . esc_html(
			sprintf(
				/* translators: 1: storage driver label, 2: ffmpeg availability sentence. */
				__( 'Storage: %1$s. %2$s', 'classic-edu-suite' ),
				CES_Lms_Storage_Factory::active() ? CES_Lms_Storage_Factory::active()->get_label() : __( 'not configured', 'classic-edu-suite' ),
				(string) $caps['note']
			)
		) . '</p>';

		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . CES_Lms_Cpt::LESSON ) ) . '">'
			. esc_html__( 'Add a lesson', 'classic-edu-suite' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . CES_Lms_Cpt::COURSE ) ) . '">'
			. esc_html__( 'Add a course', 'classic-edu-suite' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=ces-lms-upload' ) ) . '">'
			. esc_html__( 'Upload a video', 'classic-edu-suite' ) . '</a>';
		echo '</p>';

		if ( empty( $lessons ) ) {
			echo '<p>' . esc_html__( 'No lessons yet. Create a course, then add lessons to it.', 'classic-edu-suite' ) . '</p></div>';

			return;
		}

		echo '<table class="widefat striped ces-lms-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Lesson', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Course', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Video', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Length', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Viewers', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Finished', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $lessons as $lesson ) {
			$lesson_id = (int) $lesson->ID;
			$status    = CES_Lms_Meta::status( $lesson_id );
			$stats     = CES_Lms_Progress::lesson_stats( $lesson_id );
			$course_id = CES_Lms_Cpt::lesson_course_id( $lesson_id );

			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( (string) get_edit_post_link( $lesson_id ) ) . '">'
				. esc_html( get_the_title( $lesson_id ) ) . '</a></strong>';

			if ( 'publish' !== $lesson->post_status ) {
				echo ' <span class="ces-muted">(' . esc_html( $lesson->post_status ) . ')</span>';
			}

			echo '</td>';

			echo '<td>' . ( $course_id ? esc_html( get_the_title( $course_id ) ) : '&mdash;' ) . '</td>';

			echo '<td><span class="ces-pill ces-pill--' . esc_attr( $status ) . '">'
				. esc_html( CES_Lms_Meta::status_label( $status ) ) . '</span></td>';

			$duration = CES_Lms_Meta::duration( $lesson_id );
			echo '<td>' . ( $duration ? esc_html( CES_Lms_Meta::format_duration( $duration ) ) : '&mdash;' ) . '</td>';

			echo '<td>' . esc_html( number_format_i18n( $stats['viewers'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $stats['completions'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}
