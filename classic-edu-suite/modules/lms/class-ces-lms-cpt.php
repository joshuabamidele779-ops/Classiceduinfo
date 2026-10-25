<?php
/**
 * Course and lesson content types, taxonomies and lesson meta.
 *
 * `ces_course` is a subject/syllabus course; `ces_lesson` is one video lesson
 * that belongs to a course and is ordered inside it with `menu_order`.
 *
 * Both post types deliberately opt out of the block editor
 * (`show_in_rest => false`). The lesson screen hosts a chunked uploader and a
 * drag-order builder that need a predictable, nonce-protected classic form;
 * meta boxes in the block editor are supported but far more fragile, and this
 * plugin has to work first time on a host nobody can debug remotely.
 *
 * `ces_subject` and `ces_exam_type` are shared with the CBT module, which is
 * being built in parallel. Whoever reaches `init` first registers them; the
 * other module only attaches its own object types. Every registration is
 * guarded with `taxonomy_exists()`.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers LMS content types and their editor UI.
 */
class CES_Lms_Cpt {

	/**
	 * Course post type.
	 */
	const COURSE = 'ces_course';

	/**
	 * Lesson post type.
	 */
	const LESSON = 'ces_lesson';

	/**
	 * Hierarchical syllabus taxonomy, mirroring the real syllabus tree.
	 */
	const TAX_SYLLABUS = 'ces_syllabus';

	/**
	 * Class/level taxonomy (JSS1-3, SSS1-3, Year, exam class).
	 */
	const TAX_LEVEL = 'ces_level';

	/**
	 * Shared subject taxonomy, possibly owned by the CBT module.
	 */
	const TAX_SUBJECT = 'ces_subject';

	/**
	 * Shared exam type taxonomy, possibly owned by the CBT module.
	 */
	const TAX_EXAM_TYPE = 'ces_exam_type';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 6 );
		add_action( 'init', array( __CLASS__, 'attach_shared_taxonomies' ), 20 );

		add_filter( 'user_has_cap', array( __CLASS__, 'grant_caps' ), 10, 4 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::LESSON, array( __CLASS__, 'save_lesson' ), 10, 2 );
		add_action( 'save_post_' . self::COURSE, array( __CLASS__, 'save_course' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_lesson' ) );

		add_filter( 'manage_' . self::LESSON . '_posts_columns', array( __CLASS__, 'lesson_columns' ) );
		add_action( 'manage_' . self::LESSON . '_posts_custom_column', array( __CLASS__, 'lesson_column' ), 10, 2 );
		add_filter( 'manage_edit-' . self::LESSON . '_sortable_columns', array( __CLASS__, 'lesson_sortable' ) );

		add_filter( 'manage_' . self::COURSE . '_posts_columns', array( __CLASS__, 'course_columns' ) );
		add_action( 'manage_' . self::COURSE . '_posts_custom_column', array( __CLASS__, 'course_column' ), 10, 2 );
	}

	/**
	 * Register `ces_course` and `ces_lesson`.
	 *
	 * @return void
	 */
	public static function register_post_types() {
		register_post_type(
			self::COURSE,
			array(
				'labels'              => array(
					'name'               => __( 'Courses', 'classic-edu-suite' ),
					'singular_name'      => __( 'Course', 'classic-edu-suite' ),
					'add_new'            => __( 'Add Course', 'classic-edu-suite' ),
					'add_new_item'       => __( 'Add New Course', 'classic-edu-suite' ),
					'edit_item'          => __( 'Edit Course', 'classic-edu-suite' ),
					'new_item'           => __( 'New Course', 'classic-edu-suite' ),
					'view_item'          => __( 'View Course', 'classic-edu-suite' ),
					'search_items'       => __( 'Search Courses', 'classic-edu-suite' ),
					'not_found'          => __( 'No courses found.', 'classic-edu-suite' ),
					'not_found_in_trash' => __( 'No courses in the trash.', 'classic-edu-suite' ),
					'all_items'          => __( 'Courses', 'classic-edu-suite' ),
					'menu_name'          => __( 'Courses', 'classic-edu-suite' ),
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => CES_Admin_Menu::SLUG,
				'show_in_nav_menus'   => true,
				'show_in_rest'        => false,
				'has_archive'         => true,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-video-alt3',
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'author' ),
				'capability_type'     => array( 'ces_course', 'ces_courses' ),
				'map_meta_cap'        => true,
				'exclude_from_search' => false,
				'rewrite'             => array(
					'slug'       => 'courses',
					'with_front' => false,
				),
			)
		);

		register_post_type(
			self::LESSON,
			array(
				'labels'              => array(
					'name'               => __( 'Video Lessons', 'classic-edu-suite' ),
					'singular_name'      => __( 'Video Lesson', 'classic-edu-suite' ),
					'add_new'            => __( 'Add Lesson', 'classic-edu-suite' ),
					'add_new_item'       => __( 'Add New Lesson', 'classic-edu-suite' ),
					'edit_item'          => __( 'Edit Lesson', 'classic-edu-suite' ),
					'new_item'           => __( 'New Lesson', 'classic-edu-suite' ),
					'view_item'          => __( 'View Lesson', 'classic-edu-suite' ),
					'search_items'       => __( 'Search Lessons', 'classic-edu-suite' ),
					'not_found'          => __( 'No lessons found.', 'classic-edu-suite' ),
					'not_found_in_trash' => __( 'No lessons in the trash.', 'classic-edu-suite' ),
					'all_items'          => __( 'Video Lessons', 'classic-edu-suite' ),
					'menu_name'          => __( 'Video Lessons', 'classic-edu-suite' ),
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => CES_Admin_Menu::SLUG,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-format-video',
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'author' ),
				'capability_type'     => array( 'ces_lesson', 'ces_lessons' ),
				'map_meta_cap'        => true,
				'exclude_from_search' => true,
				'rewrite'             => array(
					'slug'       => 'lesson',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Register the taxonomies this module owns outright.
	 *
	 * @return void
	 */
	public static function register_taxonomies() {
		$objects = array( self::COURSE, self::LESSON );

		if ( ! taxonomy_exists( self::TAX_SYLLABUS ) ) {
			register_taxonomy(
				self::TAX_SYLLABUS,
				$objects,
				array(
					'labels'            => array(
						'name'          => __( 'Syllabus Topics', 'classic-edu-suite' ),
						'singular_name' => __( 'Syllabus Topic', 'classic-edu-suite' ),
						'search_items'  => __( 'Search Topics', 'classic-edu-suite' ),
						'all_items'     => __( 'All Topics', 'classic-edu-suite' ),
						'parent_item'   => __( 'Parent Topic', 'classic-edu-suite' ),
						'edit_item'     => __( 'Edit Topic', 'classic-edu-suite' ),
						'add_new_item'  => __( 'Add Topic', 'classic-edu-suite' ),
						'menu_name'     => __( 'Syllabus', 'classic-edu-suite' ),
					),
					'hierarchical'      => true,
					'public'            => true,
					'show_ui'           => true,
					'show_admin_column' => true,
					'show_in_rest'      => false,
					'capabilities'      => self::tax_caps(),
					'rewrite'           => array(
						'slug'       => 'syllabus',
						'with_front' => false,
					),
				)
			);
		}

		if ( ! taxonomy_exists( self::TAX_LEVEL ) ) {
			register_taxonomy(
				self::TAX_LEVEL,
				$objects,
				array(
					'labels'            => array(
						'name'          => __( 'Levels', 'classic-edu-suite' ),
						'singular_name' => __( 'Level', 'classic-edu-suite' ),
						'all_items'     => __( 'All Levels', 'classic-edu-suite' ),
						'parent_item'   => __( 'Parent Level', 'classic-edu-suite' ),
						'edit_item'     => __( 'Edit Level', 'classic-edu-suite' ),
						'add_new_item'  => __( 'Add Level', 'classic-edu-suite' ),
						'menu_name'     => __( 'Levels', 'classic-edu-suite' ),
					),
					'hierarchical'      => true,
					'public'            => true,
					'show_ui'           => true,
					'show_admin_column' => true,
					'show_in_rest'      => false,
					'capabilities'      => self::tax_caps(),
					'rewrite'           => array(
						'slug'       => 'level',
						'with_front' => false,
					),
				)
			);
		}
	}

	/**
	 * Register or adopt the taxonomies shared with the CBT module.
	 *
	 * Runs late on `init` so that if CBT already registered `ces_subject` or
	 * `ces_exam_type` we simply attach courses and lessons to it rather than
	 * fighting over the definition.
	 *
	 * @return void
	 */
	public static function attach_shared_taxonomies() {
		$objects = array( self::COURSE, self::LESSON );

		if ( ! taxonomy_exists( self::TAX_SUBJECT ) ) {
			register_taxonomy(
				self::TAX_SUBJECT,
				$objects,
				array(
					'labels'            => array(
						'name'          => __( 'Subjects', 'classic-edu-suite' ),
						'singular_name' => __( 'Subject', 'classic-edu-suite' ),
						'all_items'     => __( 'All Subjects', 'classic-edu-suite' ),
						'edit_item'     => __( 'Edit Subject', 'classic-edu-suite' ),
						'add_new_item'  => __( 'Add Subject', 'classic-edu-suite' ),
						'menu_name'     => __( 'Subjects', 'classic-edu-suite' ),
					),
					'hierarchical'      => false,
					'public'            => true,
					'show_ui'           => true,
					'show_admin_column' => true,
					'show_in_rest'      => false,
					'capabilities'      => self::tax_caps(),
					'rewrite'           => array(
						'slug'       => 'subject',
						'with_front' => false,
					),
				)
			);
		} else {
			foreach ( $objects as $object ) {
				register_taxonomy_for_object_type( self::TAX_SUBJECT, $object );
			}
		}

		if ( ! taxonomy_exists( self::TAX_EXAM_TYPE ) ) {
			register_taxonomy(
				self::TAX_EXAM_TYPE,
				$objects,
				array(
					'labels'            => array(
						'name'          => __( 'Exam Types', 'classic-edu-suite' ),
						'singular_name' => __( 'Exam Type', 'classic-edu-suite' ),
						'all_items'     => __( 'All Exam Types', 'classic-edu-suite' ),
						'edit_item'     => __( 'Edit Exam Type', 'classic-edu-suite' ),
						'add_new_item'  => __( 'Add Exam Type', 'classic-edu-suite' ),
						'menu_name'     => __( 'Exam Types', 'classic-edu-suite' ),
					),
					'hierarchical'      => false,
					'public'            => true,
					'show_ui'           => true,
					'show_admin_column' => true,
					'show_in_rest'      => false,
					'capabilities'      => self::tax_caps(),
					'rewrite'           => array(
						'slug'       => 'exam-type',
						'with_front' => false,
					),
				)
			);
		} else {
			foreach ( $objects as $object ) {
				register_taxonomy_for_object_type( self::TAX_EXAM_TYPE, $object );
			}
		}
	}

	/**
	 * Taxonomy capability map, pinned to the LMS capability.
	 *
	 * @return array
	 */
	private static function tax_caps() {
		return array(
			'manage_terms' => 'ces_manage_lms',
			'edit_terms'   => 'ces_manage_lms',
			'delete_terms' => 'ces_manage_lms',
			'assign_terms' => 'ces_manage_lms',
		);
	}

	/**
	 * Every primitive capability generated for our two post types.
	 *
	 * @return string[]
	 */
	public static function post_type_caps() {
		$caps = array();

		foreach ( array( 'ces_course' => 'ces_courses', 'ces_lesson' => 'ces_lessons' ) as $single => $plural ) {
			$caps[] = 'edit_' . $plural;
			$caps[] = 'edit_others_' . $plural;
			$caps[] = 'edit_published_' . $plural;
			$caps[] = 'edit_private_' . $plural;
			$caps[] = 'publish_' . $plural;
			$caps[] = 'read_private_' . $plural;
			$caps[] = 'delete_' . $plural;
			$caps[] = 'delete_others_' . $plural;
			$caps[] = 'delete_published_' . $plural;
			$caps[] = 'delete_private_' . $plural;
			$caps[] = 'create_' . $plural;
			$caps[] = 'read_' . $single;
			$caps[] = 'edit_' . $single;
			$caps[] = 'delete_' . $single;
		}

		return $caps;
	}

	/**
	 * Grant the custom post type capabilities to LMS managers.
	 *
	 * The activator (frozen) hands instructors `ces_manage_lms` but no core
	 * post capabilities, so mapping them here keeps the role usable without
	 * giving an instructor the run of the rest of the site's content.
	 *
	 * @param array $allcaps All capabilities for the user.
	 * @param array $caps    Required capabilities (unused).
	 * @param array $args    Context (unused).
	 * @param mixed $user    WP_User object.
	 * @return array
	 */
	public static function grant_caps( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args );

		$manager = ! empty( $allcaps['ces_manage_lms'] ) || ! empty( $allcaps['manage_options'] );

		if ( ! $manager ) {
			return $allcaps;
		}

		unset( $user );

		foreach ( self::post_type_caps() as $cap ) {
			$allcaps[ $cap ] = true;
		}

		// Needed for the media library side of lesson attachments.
		$allcaps['upload_files'] = true;

		return $allcaps;
	}

	// ------------------------------------------------------------------
	// Editor UI.
	// ------------------------------------------------------------------

	/**
	 * Register meta boxes.
	 *
	 * @return void
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'ces-lesson-video',
			__( 'Protected Video', 'classic-edu-suite' ),
			array( __CLASS__, 'render_video_box' ),
			self::LESSON,
			'normal',
			'high'
		);

		add_meta_box(
			'ces-lesson-details',
			__( 'Lesson Settings', 'classic-edu-suite' ),
			array( __CLASS__, 'render_lesson_box' ),
			self::LESSON,
			'normal',
			'default'
		);

		add_meta_box(
			'ces-course-lessons',
			__( 'Lessons in this Course', 'classic-edu-suite' ),
			array( __CLASS__, 'render_course_box' ),
			self::COURSE,
			'normal',
			'high'
		);
	}

	/**
	 * Video panel: delegates to the upload admin class.
	 *
	 * @param WP_Post $post Lesson post.
	 * @return void
	 */
	public static function render_video_box( $post ) {
		if ( class_exists( 'CES_Lms_Admin_Upload' ) ) {
			CES_Lms_Admin_Upload::render_panel( (int) $post->ID );
			return;
		}

		echo '<p>' . esc_html__( 'The upload component is unavailable.', 'classic-edu-suite' ) . '</p>';
	}

	/**
	 * Lesson settings panel.
	 *
	 * @param WP_Post $post Lesson post.
	 * @return void
	 */
	public static function render_lesson_box( $post ) {
		$lesson_id = (int) $post->ID;

		wp_nonce_field( 'ces_lms_save_lesson_' . $lesson_id, 'ces_lms_lesson_nonce' );

		$course_id   = (int) get_post_meta( $lesson_id, CES_Lms_Meta::COURSE, true );
		$duration    = CES_Lms_Meta::duration( $lesson_id );
		$free        = '1' === (string) get_post_meta( $lesson_id, CES_Lms_Meta::FREE_PREVIEW, true );
		$cost        = get_post_meta( $lesson_id, CES_Lms_Meta::COIN_COST, true );
		$notes       = (string) get_post_meta( $lesson_id, CES_Lms_Meta::NOTES, true );
		$transcript  = (string) get_post_meta( $lesson_id, CES_Lms_Meta::TRANSCRIPT, true );
		$attachments = get_post_meta( $lesson_id, CES_Lms_Meta::ATTACHMENTS, true );
		$attachments = is_array( $attachments ) ? implode( ',', array_map( 'absint', $attachments ) ) : '';

		$courses = get_posts(
			array(
				'post_type'        => self::COURSE,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => 300,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		echo '<table class="form-table ces-lms-form"><tbody>';

		echo '<tr><th scope="row"><label for="ces_lesson_course">' . esc_html__( 'Course', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<select id="ces_lesson_course" name="ces_lesson_course">';
		echo '<option value="0">' . esc_html__( '— none —', 'classic-edu-suite' ) . '</option>';
		foreach ( $courses as $course ) {
			echo '<option value="' . esc_attr( $course->ID ) . '" ' . selected( $course_id, (int) $course->ID, false ) . '>' . esc_html( get_the_title( $course ) ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Lesson order inside the course is the "Order" value in the Page Attributes box, or drag the list on the course screen.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ces_lesson_duration">' . esc_html__( 'Duration (seconds)', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="number" min="0" step="1" class="small-text" id="ces_lesson_duration" name="ces_lesson_duration" value="' . esc_attr( $duration ) . '" /> ';
		echo '<span class="description">' . esc_html( $duration ? CES_Lms_Meta::format_duration( $duration ) : __( 'Filled in automatically when ffprobe is available.', 'classic-edu-suite' ) ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Free preview', 'classic-edu-suite' ) . '</th><td>';
		echo '<label><input type="checkbox" name="ces_lesson_free" value="1" ' . checked( $free, true, false ) . ' /> ';
		echo esc_html__( 'Anyone logged in may watch this lesson without spending coins.', 'classic-edu-suite' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ces_lesson_cost">' . esc_html__( 'Coin cost override', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="number" min="0" step="1" class="small-text" id="ces_lesson_cost" name="ces_lesson_cost" value="' . esc_attr( '' === $cost ? '' : (int) $cost ) . '" />';
		echo '<p class="description">' . sprintf(
			/* translators: %d: default coin cost from settings. */
			esc_html__( 'Leave empty to use the site default (%d coins).', 'classic-edu-suite' ),
			(int) CES_Helpers::setting_int( 'video_coin_cost', 10 )
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ces_lesson_attachments">' . esc_html__( 'Attachment IDs', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="ces_lesson_attachments" name="ces_lesson_attachments" value="' . esc_attr( $attachments ) . '" />';
		echo '<p class="description">' . esc_html__( 'Comma-separated media library IDs for notes or worksheets offered under the player. These are ordinary public media files, not protected like the video.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ces_lesson_notes">' . esc_html__( 'Lesson notes', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<textarea id="ces_lesson_notes" name="ces_lesson_notes" rows="5" class="large-text">' . esc_textarea( $notes ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Shown beneath the player. Basic HTML allowed.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ces_lesson_transcript">' . esc_html__( 'Transcript', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<textarea id="ces_lesson_transcript" name="ces_lesson_transcript" rows="6" class="large-text">' . esc_textarea( $transcript ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Plain text. Helps students on very slow connections and improves accessibility.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Course panel: the drag-order lesson builder.
	 *
	 * @param WP_Post $post Course post.
	 * @return void
	 */
	public static function render_course_box( $post ) {
		if ( class_exists( 'CES_Lms_Admin_Builder' ) ) {
			CES_Lms_Admin_Builder::render_panel( (int) $post->ID );
			return;
		}

		echo '<p>' . esc_html__( 'The course builder component is unavailable.', 'classic-edu-suite' ) . '</p>';
	}

	// ------------------------------------------------------------------
	// Save handlers.
	// ------------------------------------------------------------------

	/**
	 * Persist lesson meta.
	 *
	 * @param int     $post_id Lesson id.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_lesson( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || self::LESSON !== $post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['ces_lms_lesson_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['ces_lms_lesson_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'ces_lms_save_lesson_' . (int) $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$post_id = (int) $post_id;

		$course_id = isset( $_POST['ces_lesson_course'] ) ? absint( wp_unslash( $_POST['ces_lesson_course'] ) ) : 0;

		if ( $course_id && self::COURSE !== get_post_type( $course_id ) ) {
			$course_id = 0;
		}

		update_post_meta( $post_id, CES_Lms_Meta::COURSE, $course_id );

		$duration = isset( $_POST['ces_lesson_duration'] ) ? absint( wp_unslash( $_POST['ces_lesson_duration'] ) ) : 0;
		update_post_meta( $post_id, CES_Lms_Meta::DURATION, min( 86400, $duration ) );

		update_post_meta( $post_id, CES_Lms_Meta::FREE_PREVIEW, isset( $_POST['ces_lesson_free'] ) ? '1' : '' );

		$cost_raw = isset( $_POST['ces_lesson_cost'] ) ? trim( (string) wp_unslash( $_POST['ces_lesson_cost'] ) ) : '';

		if ( '' === $cost_raw ) {
			update_post_meta( $post_id, CES_Lms_Meta::COIN_COST, '' );
		} else {
			update_post_meta( $post_id, CES_Lms_Meta::COIN_COST, min( 100000, absint( $cost_raw ) ) );
		}

		$ids_raw = isset( $_POST['ces_lesson_attachments'] ) ? (string) wp_unslash( $_POST['ces_lesson_attachments'] ) : '';
		$ids     = array();

		foreach ( explode( ',', $ids_raw ) as $chunk ) {
			$id = absint( trim( $chunk ) );

			if ( $id && 'attachment' === get_post_type( $id ) ) {
				$ids[] = $id;
			}
		}

		update_post_meta( $post_id, CES_Lms_Meta::ATTACHMENTS, array_values( array_unique( $ids ) ) );

		$notes = isset( $_POST['ces_lesson_notes'] ) ? wp_kses_post( wp_unslash( $_POST['ces_lesson_notes'] ) ) : '';
		update_post_meta( $post_id, CES_Lms_Meta::NOTES, $notes );

		$transcript = isset( $_POST['ces_lesson_transcript'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ces_lesson_transcript'] ) ) : '';
		update_post_meta( $post_id, CES_Lms_Meta::TRANSCRIPT, $transcript );
	}

	/**
	 * Course save hook. Lesson ordering is handled by the builder's own
	 * nonce-protected admin-post action, so nothing is needed here yet.
	 *
	 * @param int     $post_id Course id.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_course( $post_id, $post ) {
		unset( $post_id, $post );
	}

	/**
	 * Delete a lesson's protected media when the post is permanently deleted.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function on_delete_lesson( $post_id ) {
		$post_id = (int) $post_id;

		if ( self::LESSON !== get_post_type( $post_id ) ) {
			return;
		}

		if ( class_exists( 'CES_Lms_Storage_Factory' ) ) {
			$driver = CES_Lms_Storage_Factory::for_lesson( $post_id );

			if ( $driver ) {
				$driver->delete( $post_id );
			}
		}

		if ( class_exists( 'CES_Lms_Transcode' ) ) {
			CES_Lms_Transcode::dequeue( $post_id );
		}
	}

	// ------------------------------------------------------------------
	// List table columns.
	// ------------------------------------------------------------------

	/**
	 * Lesson list columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function lesson_columns( $columns ) {
		$insert = array(
			'ces_course'   => __( 'Course', 'classic-edu-suite' ),
			'ces_video'    => __( 'Video', 'classic-edu-suite' ),
			'ces_duration' => __( 'Length', 'classic-edu-suite' ),
			'ces_access'   => __( 'Access', 'classic-edu-suite' ),
			'menu_order'   => __( 'Order', 'classic-edu-suite' ),
		);

		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out = array_merge( $out, $insert );
			}
		}

		return $out;
	}

	/**
	 * Render a lesson list cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Lesson id.
	 * @return void
	 */
	public static function lesson_column( $column, $post_id ) {
		$post_id = (int) $post_id;

		switch ( $column ) {
			case 'ces_course':
				$course_id = (int) get_post_meta( $post_id, CES_Lms_Meta::COURSE, true );

				if ( $course_id ) {
					echo '<a href="' . esc_url( get_edit_post_link( $course_id ) ) . '">' . esc_html( get_the_title( $course_id ) ) . '</a>';
				} else {
					echo '<span class="ces-muted">' . esc_html__( '— none —', 'classic-edu-suite' ) . '</span>';
				}
				break;

			case 'ces_video':
				$status = CES_Lms_Meta::status( $post_id );
				echo '<span class="ces-pill ces-pill--' . esc_attr( $status ) . '">' . esc_html( CES_Lms_Meta::status_label( $status ) ) . '</span>';

				$error = (string) get_post_meta( $post_id, CES_Lms_Meta::ERROR, true );

				if ( '' !== $error ) {
					echo '<br /><small class="ces-error">' . esc_html( wp_trim_words( $error, 18 ) ) . '</small>';
				}
				break;

			case 'ces_duration':
				$duration = CES_Lms_Meta::duration( $post_id );
				echo $duration ? esc_html( CES_Lms_Meta::format_duration( $duration ) ) : '&mdash;';
				break;

			case 'ces_access':
				if ( '1' === (string) get_post_meta( $post_id, CES_Lms_Meta::FREE_PREVIEW, true ) ) {
					echo esc_html__( 'Free preview', 'classic-edu-suite' );
				} else {
					$cost = get_post_meta( $post_id, CES_Lms_Meta::COIN_COST, true );
					$cost = '' === $cost ? CES_Helpers::setting_int( 'video_coin_cost', 10 ) : (int) $cost;

					printf(
						/* translators: %s: coin amount. */
						esc_html__( '%s coins', 'classic-edu-suite' ),
						esc_html( CES_Helpers::format_coins( $cost ) )
					);
				}
				break;

			case 'menu_order':
				echo esc_html( (string) get_post_field( 'menu_order', $post_id ) );
				break;
		}
	}

	/**
	 * Make the order column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function lesson_sortable( $columns ) {
		$columns['menu_order'] = 'menu_order';

		return $columns;
	}

	/**
	 * Course list columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function course_columns( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out['ces_lessons'] = __( 'Lessons', 'classic-edu-suite' );
				$out['ces_ready']   = __( 'Playable', 'classic-edu-suite' );
			}
		}

		return $out;
	}

	/**
	 * Render a course list cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Course id.
	 * @return void
	 */
	public static function course_column( $column, $post_id ) {
		if ( 'ces_lessons' !== $column && 'ces_ready' !== $column ) {
			return;
		}

		$lessons = self::course_lesson_ids( (int) $post_id, array( 'publish', 'draft', 'private' ) );

		if ( 'ces_lessons' === $column ) {
			echo esc_html( (string) count( $lessons ) );
			return;
		}

		$ready = 0;

		foreach ( $lessons as $lesson_id ) {
			if ( CES_Lms_Meta::is_playable( $lesson_id ) ) {
				++$ready;
			}
		}

		echo esc_html( $ready . ' / ' . count( $lessons ) );
	}

	// ------------------------------------------------------------------
	// Query helpers shared across the module.
	// ------------------------------------------------------------------

	/**
	 * Lesson ids belonging to a course, in display order.
	 *
	 * @param int      $course_id Course post id.
	 * @param string[] $statuses  Post statuses to include.
	 * @return int[]
	 */
	public static function course_lesson_ids( $course_id, $statuses = array( 'publish' ) ) {
		$course_id = (int) $course_id;

		if ( $course_id <= 0 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => self::LESSON,
				'post_status'    => $statuses,
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				),
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => CES_Lms_Meta::COURSE,
						'value' => $course_id,
					),
				),
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Zero-based position of a lesson inside its course.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @param int $course_id Course post id.
	 * @return int Position, or -1 when the lesson is not in the course.
	 */
	public static function lesson_position( $lesson_id, $course_id ) {
		$ids   = self::course_lesson_ids( $course_id );
		$index = array_search( (int) $lesson_id, $ids, true );

		return false === $index ? -1 : (int) $index;
	}

	/**
	 * Course id for a lesson.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return int
	 */
	public static function lesson_course_id( $lesson_id ) {
		return (int) get_post_meta( (int) $lesson_id, CES_Lms_Meta::COURSE, true );
	}
}
