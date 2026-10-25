<?php
/**
 * The resource library: everything a student reads rather than answers.
 *
 * One post type, `ces_resource`, covers novels, the JAMB brochure, JAMB /
 * WAEC / NECO syllabuses, lesson notes and past-question papers. They differ
 * only by the `ces_resource_type` term they carry, which is what lets the
 * study hub show one tile per type without a post type each.
 *
 * A resource is either a file (an attachment or an external link) or a body
 * of text read on the site — lesson notes are usually the latter, so a student
 * on expensive mobile data is not made to download a PDF to read two pages.
 *
 * Access is one of: free, logged-in, coins, or subscribers. The coin path
 * debits the wallet once and the unlock is permanent, unlike a video.
 *
 * @package ClassicEduSuite\Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resource post type, taxonomy, meta and access rules.
 */
class CES_Hub_Resources {

	/**
	 * Resource post type slug.
	 */
	const POST_TYPE = 'ces_resource';

	/**
	 * Resource type taxonomy slug.
	 */
	const TAX_TYPE = 'ces_resource_type';

	/**
	 * Capability that gates resource management.
	 */
	const CAP = 'ces_manage_hub';

	/**
	 * Attachment ID meta key.
	 */
	const META_FILE = '_ces_res_file';

	/**
	 * External URL meta key.
	 */
	const META_LINK = '_ces_res_link';

	/**
	 * Access mode meta key.
	 */
	const META_ACCESS = '_ces_res_access';

	/**
	 * Coin cost meta key.
	 */
	const META_COINS = '_ces_res_coins';

	/**
	 * Author / publisher meta key, used by novels.
	 */
	const META_AUTHOR = '_ces_res_author';

	/**
	 * Page count meta key.
	 */
	const META_PAGES = '_ces_res_pages';

	/**
	 * Featured flag meta key.
	 */
	const META_FEATURED = '_ces_res_featured';

	/**
	 * Download counter meta key.
	 */
	const META_DOWNLOADS = '_ces_res_downloads';

	/**
	 * User meta holding the list of resource IDs a student has unlocked.
	 */
	const USER_META_UNLOCKED = 'ces_unlocked_resources';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_admin_caps' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed_terms' ) );
		add_action( 'admin_post_ces_resource_open', array( __CLASS__, 'handle_open' ) );
		add_action( 'admin_post_nopriv_ces_resource_open', array( __CLASS__, 'handle_open' ) );
		add_action( 'admin_post_ces_resource_unlock', array( __CLASS__, 'handle_unlock' ) );
	}

	/**
	 * The resource types seeded on a fresh install, in hub display order.
	 *
	 * @return array Map of slug => array( label, icon, description ).
	 */
	public static function seed_types() {
		return array(
			'past-question-paper' => array(
				'label' => __( 'Past Question Papers', 'classic-edu-suite' ),
				'icon'  => '📄',
				'desc'  => __( 'Full papers as they were sat, year by year.', 'classic-edu-suite' ),
			),
			'lesson-note'         => array(
				'label' => __( 'Lesson Notes', 'classic-edu-suite' ),
				'icon'  => '📕',
				'desc'  => __( 'Topic by topic notes written for the syllabus.', 'classic-edu-suite' ),
			),
			'novel'               => array(
				'label' => __( 'Novels', 'classic-edu-suite' ),
				'icon'  => '📚',
				'desc'  => __( 'Set texts and their summaries.', 'classic-edu-suite' ),
			),
			'jamb-syllabus'       => array(
				'label' => __( 'JAMB Syllabus', 'classic-edu-suite' ),
				'icon'  => '🧭',
				'desc'  => __( 'What JAMB will actually ask you, per subject.', 'classic-edu-suite' ),
			),
			'waec-syllabus'       => array(
				'label' => __( 'WAEC Syllabus', 'classic-edu-suite' ),
				'icon'  => '🧾',
				'desc'  => __( 'The WAEC scheme, per subject.', 'classic-edu-suite' ),
			),
			'neco-syllabus'       => array(
				'label' => __( 'NECO Syllabus', 'classic-edu-suite' ),
				'icon'  => '📘',
				'desc'  => __( 'The NECO scheme, per subject.', 'classic-edu-suite' ),
			),
			'jamb-brochure'       => array(
				'label' => __( 'JAMB Brochure', 'classic-edu-suite' ),
				'icon'  => '🏫',
				'desc'  => __( 'Courses, institutions and their requirements.', 'classic-edu-suite' ),
			),
			'scheme-of-work'      => array(
				'label' => __( 'Scheme of Work', 'classic-edu-suite' ),
				'icon'  => '🗓️',
				'desc'  => __( 'Term by term teaching plans.', 'classic-edu-suite' ),
			),
		);
	}

	/**
	 * Access modes a resource can carry.
	 *
	 * @return array Map of slug => label.
	 */
	public static function access_modes() {
		return array(
			'free'       => __( 'Free for everyone', 'classic-edu-suite' ),
			'login'      => __( 'Signed-in students only', 'classic-edu-suite' ),
			'coins'      => __( 'Costs coins to unlock', 'classic-edu-suite' ),
			'subscriber' => __( 'Subscribers only', 'classic-edu-suite' ),
		);
	}

	/**
	 * Capability map for the post type.
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
	 * Register the post type and its taxonomy.
	 *
	 * The post type is not publicly queryable: a paid resource must not be
	 * readable at a front-end URL that skips the access check. Everything is
	 * rendered through the library shortcode instead.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Resources', 'classic-edu-suite' ),
					'singular_name'      => __( 'Resource', 'classic-edu-suite' ),
					'menu_name'          => __( 'Resources', 'classic-edu-suite' ),
					'all_items'          => __( 'Resources', 'classic-edu-suite' ),
					'add_new'            => __( 'Add Resource', 'classic-edu-suite' ),
					'add_new_item'       => __( 'Add New Resource', 'classic-edu-suite' ),
					'edit_item'          => __( 'Edit Resource', 'classic-edu-suite' ),
					'new_item'           => __( 'New Resource', 'classic-edu-suite' ),
					'search_items'       => __( 'Search Resources', 'classic-edu-suite' ),
					'not_found'          => __( 'No resources yet.', 'classic-edu-suite' ),
					'not_found_in_trash' => __( 'No resources in the bin.', 'classic-edu-suite' ),
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
				'menu_icon'           => 'dashicons-book-alt',
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
				'taxonomies'          => array( self::TAX_TYPE, 'ces_subject', 'ces_exam_type', 'ces_year' ),
				'capabilities'        => self::post_caps(),
				'map_meta_cap'        => true,
			)
		);

		register_taxonomy(
			self::TAX_TYPE,
			array( self::POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Resource Types', 'classic-edu-suite' ),
					'singular_name' => __( 'Resource Type', 'classic-edu-suite' ),
					'add_new_item'  => __( 'Add New Resource Type', 'classic-edu-suite' ),
					'search_items'  => __( 'Search Resource Types', 'classic-edu-suite' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => array(
					'manage_terms' => self::CAP,
					'edit_terms'   => self::CAP,
					'delete_terms' => self::CAP,
					'assign_terms' => self::CAP,
				),
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
	 * Create the starter resource types once per install.
	 *
	 * @return void
	 */
	public static function maybe_seed_terms() {
		if ( get_option( 'ces_hub_terms_seeded' ) ) {
			return;
		}

		update_option( 'ces_hub_terms_seeded', 1 );

		if ( ! taxonomy_exists( self::TAX_TYPE ) ) {
			return;
		}

		foreach ( self::seed_types() as $slug => $type ) {
			if ( ! term_exists( $slug, self::TAX_TYPE ) ) {
				wp_insert_term( $type['label'], self::TAX_TYPE, array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * The icon for a resource type slug.
	 *
	 * @param string $slug Term slug.
	 * @return string
	 */
	public static function type_icon( $slug ) {
		$types = self::seed_types();
		$slug  = (string) $slug;

		if ( isset( $types[ $slug ]['icon'] ) ) {
			return (string) $types[ $slug ]['icon'];
		}

		return '📂';
	}

	/* ---------------------------------------------------------------------
	 * Reading one resource.
	 * ------------------------------------------------------------------ */

	/**
	 * Load a resource as a plain array.
	 *
	 * @param int $post_id Resource ID.
	 * @return array|null Null when missing or the wrong post type.
	 */
	public static function get( $post_id ) {
		$post_id = absint( $post_id );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$file_id = absint( get_post_meta( $post_id, self::META_FILE, true ) );
		$link    = trim( (string) get_post_meta( $post_id, self::META_LINK, true ) );
		$access  = (string) get_post_meta( $post_id, self::META_ACCESS, true );

		if ( ! array_key_exists( $access, self::access_modes() ) ) {
			$access = 'login';
		}

		$type_terms = get_the_terms( $post_id, self::TAX_TYPE );
		$type_slug  = '';
		$type_label = '';

		if ( ! empty( $type_terms ) && ! is_wp_error( $type_terms ) ) {
			$first      = reset( $type_terms );
			$type_slug  = (string) $first->slug;
			$type_label = (string) $first->name;
		}

		return array(
			'id'          => $post_id,
			'title'       => get_the_title( $post_id ),
			'excerpt'     => (string) $post->post_excerpt,
			'body'        => (string) $post->post_content,
			'type'        => $type_slug,
			'type_label'  => $type_label,
			'icon'        => self::type_icon( $type_slug ),
			'subject'     => class_exists( 'CES_Cbt_Cpt' ) ? CES_Cbt_Cpt::first_term_name( $post_id, 'ces_subject' ) : '',
			'exam_type'   => class_exists( 'CES_Cbt_Cpt' ) ? CES_Cbt_Cpt::first_term_name( $post_id, 'ces_exam_type' ) : '',
			'year'        => class_exists( 'CES_Cbt_Cpt' ) ? CES_Cbt_Cpt::first_term_name( $post_id, 'ces_year' ) : '',
			'author'      => (string) get_post_meta( $post_id, self::META_AUTHOR, true ),
			'pages'       => absint( get_post_meta( $post_id, self::META_PAGES, true ) ),
			'file_id'     => $file_id,
			'file_url'    => $file_id ? (string) wp_get_attachment_url( $file_id ) : '',
			'link'        => $link,
			'access'      => $access,
			'coins'       => absint( get_post_meta( $post_id, self::META_COINS, true ) ),
			'featured'    => '1' === (string) get_post_meta( $post_id, self::META_FEATURED, true ),
			'downloads'   => absint( get_post_meta( $post_id, self::META_DOWNLOADS, true ) ),
			'thumbnail'   => get_the_post_thumbnail_url( $post_id, 'medium' ),
			'has_file'    => ( $file_id > 0 || '' !== $link ),
			'has_reading' => '' !== trim( wp_strip_all_tags( (string) $post->post_content ) ),
		);
	}

	/**
	 * Whether a student has already unlocked a resource with coins.
	 *
	 * @param int $post_id Resource ID.
	 * @param int $user_id Student.
	 * @return bool
	 */
	public static function is_unlocked( $post_id, $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$unlocked = get_user_meta( $user_id, self::USER_META_UNLOCKED, true );

		if ( ! is_array( $unlocked ) ) {
			return false;
		}

		return in_array( absint( $post_id ), array_map( 'absint', $unlocked ), true );
	}

	/**
	 * Record an unlock.
	 *
	 * @param int $post_id Resource ID.
	 * @param int $user_id Student.
	 * @return void
	 */
	private static function mark_unlocked( $post_id, $user_id ) {
		$user_id  = absint( $user_id );
		$unlocked = get_user_meta( $user_id, self::USER_META_UNLOCKED, true );

		if ( ! is_array( $unlocked ) ) {
			$unlocked = array();
		}

		$unlocked[] = absint( $post_id );

		update_user_meta( $user_id, self::USER_META_UNLOCKED, array_values( array_unique( array_map( 'absint', $unlocked ) ) ) );
	}

	/**
	 * Decide whether a viewer may open a resource.
	 *
	 * @param array $resource Resource array from `get()`.
	 * @param int   $user_id  Viewer, 0 for a guest.
	 * @return array {
	 *     @type bool   $allowed
	 *     @type string $reason  One of: ok, login, coins, subscriber.
	 *     @type int    $cost    Coins needed, 0 when not applicable.
	 *     @type string $message Human sentence explaining a refusal.
	 * }
	 */
	public static function can_access( array $resource, $user_id = 0 ) {
		$user_id = absint( $user_id );

		$ok = array(
			'allowed' => true,
			'reason'  => 'ok',
			'cost'    => 0,
			'message' => '',
		);

		if ( 'free' === $resource['access'] ) {
			return $ok;
		}

		if ( ! $user_id ) {
			return array(
				'allowed' => false,
				'reason'  => 'login',
				'cost'    => 0,
				'message' => __( 'Sign in to open this.', 'classic-edu-suite' ),
			);
		}

		// Staff see everything.
		if ( CES_Helpers::can_manage( self::CAP ) ) {
			return $ok;
		}

		if ( 'login' === $resource['access'] ) {
			return $ok;
		}

		if ( CES_Helpers::is_subscriber( $user_id ) ) {
			return $ok;
		}

		if ( 'subscriber' === $resource['access'] ) {
			return array(
				'allowed' => false,
				'reason'  => 'subscriber',
				'cost'    => 0,
				'message' => __( 'This one is for subscribers.', 'classic-edu-suite' ),
			);
		}

		// Coins.
		if ( self::is_unlocked( $resource['id'], $user_id ) ) {
			return $ok;
		}

		$cost = $resource['coins'] > 0 ? $resource['coins'] : CES_Helpers::setting_int( 'hub_resource_coin_cost', 5 );

		return array(
			'allowed' => false,
			'reason'  => 'coins',
			'cost'    => $cost,
			'message' => sprintf(
				/* translators: %d: coin cost. */
				__( 'Unlock for %d coins.', 'classic-edu-suite' ),
				$cost
			),
		);
	}

	/**
	 * Spend coins to unlock a resource.
	 *
	 * @param int $post_id Resource ID.
	 * @param int $user_id Student.
	 * @return true|WP_Error
	 */
	public static function unlock( $post_id, $user_id ) {
		$resource = self::get( $post_id );
		$user_id  = absint( $user_id );

		if ( ! $resource ) {
			return new WP_Error( 'ces_hub_missing', __( 'That resource could not be found.', 'classic-edu-suite' ) );
		}

		if ( ! $user_id ) {
			return new WP_Error( 'ces_hub_login', __( 'Sign in first.', 'classic-edu-suite' ) );
		}

		$access = self::can_access( $resource, $user_id );

		if ( $access['allowed'] ) {
			return true;
		}

		if ( 'coins' !== $access['reason'] ) {
			return new WP_Error( 'ces_hub_denied', $access['message'] );
		}

		if ( ! class_exists( 'CES_Wallet' ) || ! method_exists( 'CES_Wallet', 'debit' ) ) {
			return new WP_Error( 'ces_hub_no_wallet', __( 'Coin payments are not available right now.', 'classic-edu-suite' ) );
		}

		$result = CES_Wallet::debit(
			$user_id,
			(int) $access['cost'],
			'resource_unlock',
			array(
				'ref_type'        => 'resource',
				'ref_id'          => $resource['id'],
				'note'            => $resource['title'],
				'idempotency_key' => 'res_' . $user_id . '_' . $resource['id'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::mark_unlocked( $resource['id'], $user_id );

		/**
		 * Fires after a resource is unlocked with coins.
		 *
		 * @param int $post_id Resource ID.
		 * @param int $user_id Student.
		 * @param int $cost    Coins spent.
		 */
		do_action( 'ces_resource_unlocked', $resource['id'], $user_id, (int) $access['cost'] );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Queries.
	 * ------------------------------------------------------------------ */

	/**
	 * Query resources.
	 *
	 * @param array $args {
	 *     @type string $type      Resource type slug.
	 *     @type string $subject   Subject term slug or name.
	 *     @type string $exam_type Exam type term slug or name.
	 *     @type string $year      Year term slug or name.
	 *     @type string $search    Free text.
	 *     @type bool   $featured  Only featured resources.
	 *     @type int    $limit     Page size.
	 *     @type int    $paged     1-based page.
	 * }
	 * @return array `array( 'items' => array[], 'total' => int, 'pages' => int )`
	 */
	public static function query( array $args = array() ) {
		$args = array_merge(
			array(
				'type'      => '',
				'subject'   => '',
				'exam_type' => '',
				'year'      => '',
				'search'    => '',
				'featured'  => false,
				'limit'     => 12,
				'paged'     => 1,
			),
			$args
		);

		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 60, (int) $args['limit'] ) ),
			'paged'          => max( 1, (int) $args['paged'] ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( '' !== trim( (string) $args['search'] ) ) {
			$query_args['s']       = sanitize_text_field( (string) $args['search'] );
			$query_args['orderby'] = 'relevance';
		}

		$tax_query = array();

		$map = array(
			self::TAX_TYPE  => $args['type'],
			'ces_subject'   => $args['subject'],
			'ces_exam_type' => $args['exam_type'],
			'ces_year'      => $args['year'],
		);

		foreach ( $map as $taxonomy => $value ) {
			$value = trim( (string) $value );

			if ( '' === $value || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => is_numeric( $value ) ? 'term_id' : 'slug',
				'terms'    => is_numeric( $value ) ? absint( $value ) : sanitize_title( $value ),
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		if ( ! empty( $args['featured'] ) ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::META_FEATURED,
					'value' => '1',
				),
			);
		}

		$query = new WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$resource = self::get( $post->ID );

			if ( $resource ) {
				$items[] = $resource;
			}
		}

		wp_reset_postdata();

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * How many published resources carry a type.
	 *
	 * @param string $type Resource type slug.
	 * @return int
	 */
	public static function count_by_type( $type ) {
		$term = get_term_by( 'slug', sanitize_title( (string) $type ), self::TAX_TYPE );

		if ( ! $term || is_wp_error( $term ) ) {
			return 0;
		}

		return (int) $term->count;
	}

	/* ---------------------------------------------------------------------
	 * Front-end handlers.
	 * ------------------------------------------------------------------ */

	/**
	 * The gated link that opens a resource file.
	 *
	 * @param int $post_id Resource ID.
	 * @return string
	 */
	public static function open_url( $post_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=ces_resource_open&resource=' . absint( $post_id ) ),
			'ces_resource_open_' . absint( $post_id )
		);
	}

	/**
	 * The link that spends coins on a resource.
	 *
	 * @param int    $post_id  Resource ID.
	 * @param string $redirect Where to return afterwards.
	 * @return string
	 */
	public static function unlock_url( $post_id, $redirect = '' ) {
		$url = admin_url( 'admin-post.php?action=ces_resource_unlock&resource=' . absint( $post_id ) );

		if ( $redirect ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
		}

		return wp_nonce_url( $url, 'ces_resource_unlock_' . absint( $post_id ) );
	}

	/**
	 * Check access, count the download, and send the student to the file.
	 *
	 * @return void
	 */
	public static function handle_open() {
		$post_id = isset( $_GET['resource'] ) ? absint( $_GET['resource'] ) : 0;

		check_admin_referer( 'ces_resource_open_' . $post_id );

		$resource = self::get( $post_id );

		if ( ! $resource ) {
			wp_die( esc_html__( 'That resource could not be found.', 'classic-edu-suite' ), 404 );
		}

		$access = self::can_access( $resource, get_current_user_id() );

		if ( ! $access['allowed'] ) {
			if ( 'login' === $access['reason'] ) {
				wp_safe_redirect( CES_Helpers::login_url( CES_Helpers::dashboard_url() ) );
				exit;
			}

			wp_die( esc_html( $access['message'] ), 403 );
		}

		$target = $resource['file_url'] ? $resource['file_url'] : $resource['link'];

		if ( '' === $target ) {
			wp_die( esc_html__( 'This resource has no file attached yet.', 'classic-edu-suite' ), 404 );
		}

		update_post_meta( $post_id, self::META_DOWNLOADS, $resource['downloads'] + 1 );

		/**
		 * Fires when a resource file is opened.
		 *
		 * @param int $post_id Resource ID.
		 * @param int $user_id Viewer.
		 */
		do_action( 'ces_resource_opened', $post_id, get_current_user_id() );

		// An external link is by definition off-site, so wp_safe_redirect
		// cannot be used for it.
		if ( $resource['file_url'] ) {
			wp_safe_redirect( $target );
		} else {
			wp_redirect( esc_url_raw( $target ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		}

		exit;
	}

	/**
	 * Spend coins, then return the student to the library.
	 *
	 * @return void
	 */
	public static function handle_unlock() {
		$post_id = isset( $_GET['resource'] ) ? absint( $_GET['resource'] ) : 0;

		check_admin_referer( 'ces_resource_unlock_' . $post_id );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( CES_Helpers::login_url( CES_Helpers::dashboard_url() ) );
			exit;
		}

		if ( ! CES_Helpers::rate_limit( 'resource_unlock', 20, 5 * MINUTE_IN_SECONDS ) ) {
			wp_die( esc_html__( 'Too many unlock attempts. Please wait a moment.', 'classic-edu-suite' ), 429 );
		}

		$result = self::unlock( $post_id, get_current_user_id() );

		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

		if ( '' === $redirect ) {
			$redirect = CES_Helpers::dashboard_url( 'study' );
		}

		$redirect = add_query_arg(
			'ces_res',
			is_wp_error( $result ) ? 'failed' : 'unlocked',
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
