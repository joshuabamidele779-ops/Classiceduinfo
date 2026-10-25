<?php
/**
 * Content types behind the Guidance section.
 *
 * Three post types and three taxonomies, arranged so that one term — the
 * programme, meaning a course of study such as "Mechanical Engineering" —
 * is the thread that ties an institution, a department write-up and a career
 * together. Pick a programme in the School Finder and you can walk straight
 * to the departments that teach it and the careers it leads to.
 *
 * `ces_programme` is deliberately not called `ces_course`: that slug already
 * belongs to the LMS video courses.
 *
 * None of these post types is publicly queryable. Everything renders through
 * the guidance shortcodes, which keeps one consistent look and one place
 * where access rules live.
 *
 * @package ClassicEduSuite\Guidance
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post type and taxonomy registration for guidance content.
 */
class CES_Guidance_Cpt {

	/**
	 * Institution post type slug.
	 */
	const INSTITUTION = 'ces_institution';

	/**
	 * Department post type slug.
	 */
	const DEPARTMENT = 'ces_department';

	/**
	 * Career post type slug.
	 */
	const CAREER = 'ces_career';

	/**
	 * Programme (course of study) taxonomy slug.
	 */
	const TAX_PROGRAMME = 'ces_programme';

	/**
	 * Institution type taxonomy slug.
	 */
	const TAX_INST_TYPE = 'ces_institution_type';

	/**
	 * State taxonomy slug.
	 */
	const TAX_STATE = 'ces_state';

	/**
	 * Capability gating every guidance screen.
	 */
	const CAP = 'ces_manage_guidance';

	/* --------------------------------------------------------------- Meta */

	/**
	 * Institution abbreviation meta key.
	 */
	const META_ABBR = '_ces_inst_abbr';

	/**
	 * Institution ownership meta key.
	 */
	const META_OWNERSHIP = '_ces_inst_ownership';

	/**
	 * Institution website meta key.
	 */
	const META_WEBSITE = '_ces_inst_website';

	/**
	 * Institution cut-off mark meta key.
	 */
	const META_CUTOFF = '_ces_inst_cutoff';

	/**
	 * Department duration meta key, in years.
	 */
	const META_DURATION = '_ces_dept_duration';

	/**
	 * Department UTME subject combination meta key.
	 */
	const META_UTME = '_ces_dept_utme';

	/**
	 * Department O'level requirement meta key.
	 */
	const META_OLEVEL = '_ces_dept_olevel';

	/**
	 * Department direct entry requirement meta key.
	 */
	const META_DIRECT = '_ces_dept_direct';

	/**
	 * Department career list meta key, one per line.
	 */
	const META_CAREERS = '_ces_dept_careers';

	/**
	 * Career salary range meta key.
	 */
	const META_SALARY = '_ces_career_salary';

	/**
	 * Career skills meta key, one per line.
	 */
	const META_SKILLS = '_ces_career_skills';

	/**
	 * Career "what to study" meta key.
	 */
	const META_STUDY = '_ces_career_study';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_admin_caps' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed_terms' ) );
	}

	/**
	 * Ownership options for an institution.
	 *
	 * @return array Map of slug => label.
	 */
	public static function ownerships() {
		return array(
			'federal' => __( 'Federal', 'classic-edu-suite' ),
			'state'   => __( 'State', 'classic-edu-suite' ),
			'private' => __( 'Private', 'classic-edu-suite' ),
		);
	}

	/**
	 * Capability map shared by the three post types.
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
	 * Capability map for the guidance taxonomies.
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
	 * Build the label set for a post type.
	 *
	 * @param string $singular Singular label.
	 * @param string $plural   Plural label.
	 * @return array
	 */
	private static function labels( $singular, $plural ) {
		return array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'all_items'          => $plural,
			/* translators: %s: singular post type label. */
			'add_new_item'       => sprintf( __( 'Add New %s', 'classic-edu-suite' ), $singular ),
			'add_new'            => __( 'Add New', 'classic-edu-suite' ),
			/* translators: %s: singular post type label. */
			'edit_item'          => sprintf( __( 'Edit %s', 'classic-edu-suite' ), $singular ),
			/* translators: %s: singular post type label. */
			'new_item'           => sprintf( __( 'New %s', 'classic-edu-suite' ), $singular ),
			/* translators: %s: plural post type label. */
			'search_items'       => sprintf( __( 'Search %s', 'classic-edu-suite' ), $plural ),
			'not_found'          => __( 'Nothing found.', 'classic-edu-suite' ),
			'not_found_in_trash' => __( 'Nothing in the bin.', 'classic-edu-suite' ),
		);
	}

	/**
	 * Register post types and taxonomies.
	 *
	 * @return void
	 */
	public static function register() {
		$shared = array(
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
			'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
			'capabilities'        => self::post_caps(),
			'map_meta_cap'        => true,
		);

		register_post_type(
			self::INSTITUTION,
			array_merge(
				$shared,
				array(
					'labels'     => self::labels( __( 'Institution', 'classic-edu-suite' ), __( 'Institutions', 'classic-edu-suite' ) ),
					'menu_icon'  => 'dashicons-bank',
					'taxonomies' => array( self::TAX_PROGRAMME, self::TAX_INST_TYPE, self::TAX_STATE ),
				)
			)
		);

		register_post_type(
			self::DEPARTMENT,
			array_merge(
				$shared,
				array(
					'labels'     => self::labels( __( 'Department', 'classic-edu-suite' ), __( 'Departments', 'classic-edu-suite' ) ),
					'menu_icon'  => 'dashicons-welcome-learn-more',
					'taxonomies' => array( self::TAX_PROGRAMME ),
				)
			)
		);

		register_post_type(
			self::CAREER,
			array_merge(
				$shared,
				array(
					'labels'     => self::labels( __( 'Career', 'classic-edu-suite' ), __( 'Careers', 'classic-edu-suite' ) ),
					'menu_icon'  => 'dashicons-businessperson',
					'taxonomies' => array( self::TAX_PROGRAMME ),
				)
			)
		);

		$term_caps = self::term_caps();

		register_taxonomy(
			self::TAX_PROGRAMME,
			array( self::INSTITUTION, self::DEPARTMENT, self::CAREER ),
			array(
				'labels'            => array(
					'name'          => __( 'Programmes', 'classic-edu-suite' ),
					'singular_name' => __( 'Programme', 'classic-edu-suite' ),
					'add_new_item'  => __( 'Add New Programme', 'classic-edu-suite' ),
					'search_items'  => __( 'Search Programmes', 'classic-edu-suite' ),
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
			self::TAX_INST_TYPE,
			array( self::INSTITUTION ),
			array(
				'labels'            => array(
					'name'          => __( 'Institution Types', 'classic-edu-suite' ),
					'singular_name' => __( 'Institution Type', 'classic-edu-suite' ),
					'add_new_item'  => __( 'Add New Institution Type', 'classic-edu-suite' ),
					'search_items'  => __( 'Search Institution Types', 'classic-edu-suite' ),
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
			self::TAX_STATE,
			array( self::INSTITUTION ),
			array(
				'labels'            => array(
					'name'          => __( 'States', 'classic-edu-suite' ),
					'singular_name' => __( 'State', 'classic-edu-suite' ),
					'add_new_item'  => __( 'Add New State', 'classic-edu-suite' ),
					'search_items'  => __( 'Search States', 'classic-edu-suite' ),
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
	 * Keep administrators out of a capability trap.
	 *
	 * @param array $allcaps Capabilities for the user being tested.
	 * @return array
	 */
	public static function grant_admin_caps( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP ] = true;
		}

		return $allcaps;
	}

	/**
	 * Seed institution types and the 36 states plus the FCT, once.
	 *
	 * @return void
	 */
	public static function maybe_seed_terms() {
		if ( get_option( 'ces_guidance_terms_seeded' ) ) {
			return;
		}

		update_option( 'ces_guidance_terms_seeded', 1 );

		$seed = array(
			self::TAX_INST_TYPE => array(
				__( 'University', 'classic-edu-suite' ),
				__( 'Polytechnic', 'classic-edu-suite' ),
				__( 'College of Education', 'classic-edu-suite' ),
				__( 'Monotechnic', 'classic-edu-suite' ),
				__( 'Innovation Enterprise Institution', 'classic-edu-suite' ),
			),
			self::TAX_STATE     => array(
				'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue',
				'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu',
				'FCT - Abuja', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina',
				'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo',
				'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
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
	 * Load an institution as a plain array.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function institution( $post_id ) {
		$post = self::valid_post( $post_id, self::INSTITUTION );

		if ( ! $post ) {
			return null;
		}

		$ownership  = (string) get_post_meta( $post->ID, self::META_OWNERSHIP, true );
		$ownerships = self::ownerships();

		return array(
			'id'              => (int) $post->ID,
			'title'           => get_the_title( $post->ID ),
			'excerpt'         => (string) $post->post_excerpt,
			'body'            => (string) $post->post_content,
			'abbr'            => (string) get_post_meta( $post->ID, self::META_ABBR, true ),
			'ownership'       => $ownership,
			'ownership_label' => isset( $ownerships[ $ownership ] ) ? $ownerships[ $ownership ] : '',
			'website'         => (string) get_post_meta( $post->ID, self::META_WEBSITE, true ),
			'cutoff'          => (int) get_post_meta( $post->ID, self::META_CUTOFF, true ),
			'type'            => self::term_name( $post->ID, self::TAX_INST_TYPE ),
			'state'           => self::term_name( $post->ID, self::TAX_STATE ),
			'programmes'      => self::term_list( $post->ID, self::TAX_PROGRAMME ),
			'thumbnail'       => get_the_post_thumbnail_url( $post->ID, 'medium' ),
		);
	}

	/**
	 * Load a department as a plain array.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function department( $post_id ) {
		$post = self::valid_post( $post_id, self::DEPARTMENT );

		if ( ! $post ) {
			return null;
		}

		return array(
			'id'           => (int) $post->ID,
			'title'        => get_the_title( $post->ID ),
			'excerpt'      => (string) $post->post_excerpt,
			'body'         => (string) $post->post_content,
			'duration'     => (string) get_post_meta( $post->ID, self::META_DURATION, true ),
			'utme'         => (string) get_post_meta( $post->ID, self::META_UTME, true ),
			'olevel'       => (string) get_post_meta( $post->ID, self::META_OLEVEL, true ),
			'direct_entry' => (string) get_post_meta( $post->ID, self::META_DIRECT, true ),
			'careers'      => self::lines( (string) get_post_meta( $post->ID, self::META_CAREERS, true ) ),
			'programmes'   => self::term_list( $post->ID, self::TAX_PROGRAMME ),
			'thumbnail'    => get_the_post_thumbnail_url( $post->ID, 'medium' ),
		);
	}

	/**
	 * Load a career as a plain array.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function career( $post_id ) {
		$post = self::valid_post( $post_id, self::CAREER );

		if ( ! $post ) {
			return null;
		}

		return array(
			'id'         => (int) $post->ID,
			'title'      => get_the_title( $post->ID ),
			'excerpt'    => (string) $post->post_excerpt,
			'body'       => (string) $post->post_content,
			'salary'     => (string) get_post_meta( $post->ID, self::META_SALARY, true ),
			'skills'     => self::lines( (string) get_post_meta( $post->ID, self::META_SKILLS, true ) ),
			'study'      => (string) get_post_meta( $post->ID, self::META_STUDY, true ),
			'programmes' => self::term_list( $post->ID, self::TAX_PROGRAMME ),
			'thumbnail'  => get_the_post_thumbnail_url( $post->ID, 'medium' ),
		);
	}

	/**
	 * Fetch a published post of an expected type.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Expected type.
	 * @return WP_Post|null
	 */
	private static function valid_post( $post_id, $post_type ) {
		$post_id = absint( $post_id );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || $post_type !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		return $post;
	}

	/**
	 * First term name for a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	public static function term_name( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$first = reset( $terms );

		return isset( $first->name ) ? (string) $first->name : '';
	}

	/**
	 * All terms for a post, as name => slug.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array
	 */
	public static function term_list( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();

		foreach ( $terms as $term ) {
			$out[] = array(
				'name' => (string) $term->name,
				'slug' => (string) $term->slug,
				'id'   => (int) $term->term_id,
			);
		}

		return $out;
	}

	/**
	 * Split a textarea value into trimmed, non-empty lines.
	 *
	 * @param string $text Raw text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		$parts = preg_split( '/\r\n|\r|\n/', (string) $text );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		$out = array();

		foreach ( $parts as $line ) {
			$line = trim( $line );

			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}
}
