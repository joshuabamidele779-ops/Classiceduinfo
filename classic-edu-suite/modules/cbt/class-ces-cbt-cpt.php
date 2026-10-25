<?php
/**
 * Content types for the CBT module.
 *
 * Registers the `ces_question` bank and the `ces_exam` paper, plus the four
 * shared taxonomies Nigerian exam content actually organises by: subject,
 * exam type (JAMB / WAEC / NECO / Post-UTME), topic and year.
 *
 * Neither post type is publicly queryable. Questions must never be readable
 * over the public REST API or a front-end URL, because the correct answer
 * lives in their post meta.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post type and taxonomy registration.
 */
class CES_Cbt_Cpt {

	/**
	 * Question post type slug.
	 */
	const QUESTION = 'ces_question';

	/**
	 * Exam post type slug.
	 */
	const EXAM = 'ces_exam';

	/**
	 * Subject taxonomy slug.
	 */
	const TAX_SUBJECT = 'ces_subject';

	/**
	 * Exam type taxonomy slug.
	 */
	const TAX_EXAM_TYPE = 'ces_exam_type';

	/**
	 * Topic taxonomy slug (hierarchical).
	 */
	const TAX_TOPIC = 'ces_topic';

	/**
	 * Year taxonomy slug.
	 */
	const TAX_YEAR = 'ces_year';

	/**
	 * Capability that gates every CBT screen.
	 */
	const CAP = 'ces_manage_cbt';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_admin_caps' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed_terms' ) );
		add_filter( 'parent_file', array( __CLASS__, 'highlight_parent_menu' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'highlight_submenu' ) );
	}

	/**
	 * The taxonomies shared by questions and exams.
	 *
	 * @return array Map of taxonomy slug => human label.
	 */
	public static function taxonomies() {
		return array(
			self::TAX_SUBJECT   => __( 'Subject', 'classic-edu-suite' ),
			self::TAX_EXAM_TYPE => __( 'Exam Type', 'classic-edu-suite' ),
			self::TAX_TOPIC     => __( 'Topic', 'classic-edu-suite' ),
			self::TAX_YEAR      => __( 'Year', 'classic-edu-suite' ),
		);
	}

	/**
	 * Build the capability map for a CBT post type.
	 *
	 * Everything funnels into a single capability, `ces_manage_cbt`, which the
	 * activator grants to administrators and to the EDU Instructor role.
	 *
	 * @return array
	 */
	private static function post_caps() {
		$cap = self::CAP;

		return array(
			'edit_post'              => $cap,
			'read_post'              => $cap,
			'delete_post'            => $cap,
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'delete_posts'           => $cap,
			'delete_others_posts'    => $cap,
			'delete_published_posts' => $cap,
			'delete_private_posts'   => $cap,
			'edit_private_posts'     => $cap,
			'edit_published_posts'   => $cap,
			'publish_posts'          => $cap,
			'read_private_posts'     => $cap,
			'create_posts'           => $cap,
		);
	}

	/**
	 * Capability map for the CBT taxonomies.
	 *
	 * @return array
	 */
	private static function term_caps() {
		$cap = self::CAP;

		return array(
			'manage_terms' => $cap,
			'edit_terms'   => $cap,
			'delete_terms' => $cap,
			'assign_terms' => $cap,
		);
	}

	/**
	 * Register post types and taxonomies.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::QUESTION,
			array(
				'labels'              => array(
					'name'               => __( 'Questions', 'classic-edu-suite' ),
					'singular_name'      => __( 'Question', 'classic-edu-suite' ),
					'menu_name'          => __( 'Question Bank', 'classic-edu-suite' ),
					'all_items'          => __( 'Question Bank', 'classic-edu-suite' ),
					'add_new'            => __( 'Add Question', 'classic-edu-suite' ),
					'add_new_item'       => __( 'Add New Question', 'classic-edu-suite' ),
					'edit_item'          => __( 'Edit Question', 'classic-edu-suite' ),
					'new_item'           => __( 'New Question', 'classic-edu-suite' ),
					'view_item'          => __( 'View Question', 'classic-edu-suite' ),
					'search_items'       => __( 'Search Questions', 'classic-edu-suite' ),
					'not_found'          => __( 'No questions found.', 'classic-edu-suite' ),
					'not_found_in_trash' => __( 'No questions in the bin.', 'classic-edu-suite' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-editor-help',
				'supports'            => array( 'title', 'editor', 'author', 'revisions', 'thumbnail' ),
				'taxonomies'          => array( self::TAX_SUBJECT, self::TAX_EXAM_TYPE, self::TAX_TOPIC, self::TAX_YEAR ),
				'capabilities'        => self::post_caps(),
				'map_meta_cap'        => true,
			)
		);

		register_post_type(
			self::EXAM,
			array(
				'labels'              => array(
					'name'               => __( 'Exams', 'classic-edu-suite' ),
					'singular_name'      => __( 'Exam', 'classic-edu-suite' ),
					'menu_name'          => __( 'Exams', 'classic-edu-suite' ),
					'all_items'          => __( 'Exams', 'classic-edu-suite' ),
					'add_new'            => __( 'Add Exam', 'classic-edu-suite' ),
					'add_new_item'       => __( 'Add New Exam', 'classic-edu-suite' ),
					'edit_item'          => __( 'Edit Exam', 'classic-edu-suite' ),
					'new_item'           => __( 'New Exam', 'classic-edu-suite' ),
					'view_item'          => __( 'View Exam', 'classic-edu-suite' ),
					'search_items'       => __( 'Search Exams', 'classic-edu-suite' ),
					'not_found'          => __( 'No exams found.', 'classic-edu-suite' ),
					'not_found_in_trash' => __( 'No exams in the bin.', 'classic-edu-suite' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-clipboard',
				'supports'            => array( 'title', 'editor', 'thumbnail', 'revisions' ),
				'taxonomies'          => array( self::TAX_SUBJECT, self::TAX_EXAM_TYPE, self::TAX_TOPIC, self::TAX_YEAR ),
				'capabilities'        => self::post_caps(),
				'map_meta_cap'        => true,
			)
		);

		$object_types = array( self::QUESTION, self::EXAM );
		$term_caps    = self::term_caps();

		register_taxonomy(
			self::TAX_SUBJECT,
			$object_types,
			array(
				'labels'            => array(
					'name'          => __( 'Subjects', 'classic-edu-suite' ),
					'singular_name' => __( 'Subject', 'classic-edu-suite' ),
					'add_new_item'  => __( 'Add New Subject', 'classic-edu-suite' ),
					'search_items'  => __( 'Search Subjects', 'classic-edu-suite' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => $term_caps,
			)
		);

		register_taxonomy(
			self::TAX_EXAM_TYPE,
			$object_types,
			array(
				'labels'            => array(
					'name'          => __( 'Exam Types', 'classic-edu-suite' ),
					'singular_name' => __( 'Exam Type', 'classic-edu-suite' ),
					'add_new_item'  => __( 'Add New Exam Type', 'classic-edu-suite' ),
					'search_items'  => __( 'Search Exam Types', 'classic-edu-suite' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => $term_caps,
			)
		);

		register_taxonomy(
			self::TAX_TOPIC,
			$object_types,
			array(
				'labels'            => array(
					'name'          => __( 'Topics', 'classic-edu-suite' ),
					'singular_name' => __( 'Topic', 'classic-edu-suite' ),
					'add_new_item'  => __( 'Add New Topic', 'classic-edu-suite' ),
					'search_items'  => __( 'Search Topics', 'classic-edu-suite' ),
					'parent_item'   => __( 'Parent Topic', 'classic-edu-suite' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => $term_caps,
			)
		);

		register_taxonomy(
			self::TAX_YEAR,
			$object_types,
			array(
				'labels'            => array(
					'name'          => __( 'Years', 'classic-edu-suite' ),
					'singular_name' => __( 'Year', 'classic-edu-suite' ),
					'add_new_item'  => __( 'Add New Year', 'classic-edu-suite' ),
					'search_items'  => __( 'Search Years', 'classic-edu-suite' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => $term_caps,
			)
		);
	}

	/**
	 * Make sure a full administrator is never locked out of CBT screens.
	 *
	 * The activator grants `ces_manage_cbt` on activation, but an install that
	 * was upgraded by copying files would never have run it. Anyone who can
	 * `manage_options` is an administrator anyway.
	 *
	 * @param array $allcaps Capabilities for the user being tested.
	 * @return array
	 */
	public static function grant_admin_caps( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP ]        = true;
			$allcaps['ces_view_reports'] = true;
		}

		return $allcaps;
	}

	/**
	 * Seed the exam types and a starter set of subjects, once per install.
	 *
	 * Purely a convenience so a fresh install is not an empty set of
	 * dropdowns. Terms the admin deletes are not recreated.
	 *
	 * @return void
	 */
	public static function maybe_seed_terms() {
		if ( get_option( 'ces_cbt_terms_seeded' ) ) {
			return;
		}

		update_option( 'ces_cbt_terms_seeded', 1 );

		$seed = array(
			self::TAX_EXAM_TYPE => array( 'JAMB', 'WAEC', 'NECO', 'Post-UTME' ),
			self::TAX_SUBJECT   => array(
				'Mathematics',
				'English Language',
				'Biology',
				'Chemistry',
				'Physics',
				'Economics',
				'Government',
				'Literature in English',
				'Agricultural Science',
				'Commerce',
				'Christian Religious Studies',
				'Islamic Religious Studies',
				'Geography',
				'Further Mathematics',
				'Accounting',
				'Civic Education',
			),
		);

		foreach ( $seed as $taxonomy => $names ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			foreach ( $names as $name ) {
				if ( ! term_exists( $name, $taxonomy ) ) {
					wp_insert_term( $name, $taxonomy );
				}
			}
		}
	}

	/**
	 * Keep the "Classic EDU" top-level menu highlighted on CBT screens.
	 *
	 * @param string $parent_file Current parent menu file.
	 * @return string
	 */
	public static function highlight_parent_menu( $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && in_array( $screen->post_type, array( self::QUESTION, self::EXAM ), true ) ) {
			return 'classic-edu';
		}

		return $parent_file;
	}

	/**
	 * Point the highlighted submenu at the right CBT entry.
	 *
	 * @param string $submenu_file Current submenu file.
	 * @return string
	 */
	public static function highlight_submenu( $submenu_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && in_array( $screen->post_type, array( self::QUESTION, self::EXAM ), true ) ) {
			return 'edit.php?post_type=' . $screen->post_type;
		}

		return $submenu_file;
	}

	/**
	 * Term names for an object, as a comma separated string.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	public static function term_names( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		return implode( ', ', wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * First term name for an object.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	public static function first_term_name( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$first = reset( $terms );

		return isset( $first->name ) ? (string) $first->name : '';
	}
}
