<?php
/**
 * The `ces_quote` post type and its taxonomy / meta.
 *
 * Quotes are admin-managed content. The post type is deliberately not
 * publicly queryable: quotes surface through the popup, the dashboard and
 * the two shortcodes, never as standalone URLs.
 *
 * Storage:
 *   post_content                  Quote text (plain text, no markup).
 *   post_title                    Short label, auto-derived from the text.
 *   post_status publish|draft     Published quotes are candidates.
 *   _ces_quote_author             Attribution, e.g. "Nelson Mandela" or
 *                                 "African proverb". Empty = unattributed.
 *   _ces_quote_active             '1' active, '0' parked.
 *   _ces_quote_scheduled_for      'Y-m-d' to pin the quote to one day, or ''.
 *   _ces_quote_weight             1-10 rotation weight, default 1.
 *   _ces_quote_hash               md5 of the normalised text, for import de-dupe.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the quote post type, taxonomy and edit-screen meta box.
 */
class CES_Quotes_Cpt {

	/**
	 * Post type name.
	 */
	const POST_TYPE = 'ces_quote';

	/**
	 * Category taxonomy name.
	 */
	const TAXONOMY = 'ces_quote_category';

	/**
	 * Meta key: attribution.
	 */
	const META_AUTHOR = '_ces_quote_author';

	/**
	 * Meta key: active flag.
	 */
	const META_ACTIVE = '_ces_quote_active';

	/**
	 * Meta key: pinned calendar date.
	 */
	const META_SCHEDULED = '_ces_quote_scheduled_for';

	/**
	 * Meta key: rotation weight.
	 */
	const META_WEIGHT = '_ces_quote_weight';

	/**
	 * Meta key: text hash used to skip duplicate imports.
	 */
	const META_HASH = '_ces_quote_hash';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	/**
	 * Register the post type and taxonomy.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Quotes', 'classic-edu-suite' ),
					'singular_name'      => __( 'Quote', 'classic-edu-suite' ),
					'add_new_item'       => __( 'Add Quote', 'classic-edu-suite' ),
					'edit_item'          => __( 'Edit Quote', 'classic-edu-suite' ),
					'new_item'           => __( 'New Quote', 'classic-edu-suite' ),
					'view_item'          => __( 'View Quote', 'classic-edu-suite' ),
					'search_items'       => __( 'Search Quotes', 'classic-edu-suite' ),
					'not_found'          => __( 'No quotes found.', 'classic-edu-suite' ),
					'not_found_in_trash' => __( 'No quotes in the bin.', 'classic-edu-suite' ),
					'all_items'          => __( 'All Quotes', 'classic-edu-suite' ),
					'menu_name'          => __( 'Quotes', 'classic-edu-suite' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'map_meta_cap'        => true,
				'capability_type'     => 'post',
				'supports'            => array( 'title', 'editor' ),
				'menu_icon'           => 'dashicons-format-quote',
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			array( self::POST_TYPE ),
			array(
				'labels'             => array(
					'name'          => __( 'Quote Categories', 'classic-edu-suite' ),
					'singular_name' => __( 'Quote Category', 'classic-edu-suite' ),
					'add_new_item'  => __( 'Add Quote Category', 'classic-edu-suite' ),
					'menu_name'     => __( 'Categories', 'classic-edu-suite' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'show_admin_column'  => true,
				'hierarchical'       => true,
				'rewrite'            => false,
				'query_var'          => false,
			)
		);
	}

	/**
	 * Add the quote detail meta box to the edit screen.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'ces-quote-details',
			__( 'Quote details', 'classic-edu-suite' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render the quote detail meta box.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		$author    = (string) get_post_meta( $post->ID, self::META_AUTHOR, true );
		$active    = '0' === (string) get_post_meta( $post->ID, self::META_ACTIVE, true ) ? 0 : 1;
		$scheduled = (string) get_post_meta( $post->ID, self::META_SCHEDULED, true );
		$weight    = (int) get_post_meta( $post->ID, self::META_WEIGHT, true );
		$weight    = $weight > 0 ? $weight : 1;

		wp_nonce_field( 'ces_quote_meta', 'ces_quote_meta_nonce' );

		echo '<p><label for="ces_quote_author"><strong>' . esc_html__( 'Attribution', 'classic-edu-suite' ) . '</strong></label><br />';
		echo '<input type="text" class="widefat" id="ces_quote_author" name="ces_quote_author" value="' . esc_attr( $author ) . '" placeholder="' . esc_attr__( 'e.g. Nelson Mandela, or African proverb', 'classic-edu-suite' ) . '" />';
		echo '<span class="description">' . esc_html__( 'Leave empty for an unattributed quote. Never invent an attribution.', 'classic-edu-suite' ) . '</span></p>';

		echo '<p><label><input type="checkbox" name="ces_quote_active" value="1" ' . checked( $active, 1, false ) . ' /> ';
		echo esc_html__( 'Active (eligible for the rotation)', 'classic-edu-suite' ) . '</label></p>';

		echo '<p><label for="ces_quote_scheduled_for"><strong>' . esc_html__( 'Show only on this date', 'classic-edu-suite' ) . '</strong></label><br />';
		echo '<input type="date" class="widefat" id="ces_quote_scheduled_for" name="ces_quote_scheduled_for" value="' . esc_attr( $scheduled ) . '" />';
		echo '<span class="description">' . esc_html__( 'Optional. A dated quote wins over the rotation on that day and is skipped on every other day.', 'classic-edu-suite' ) . '</span></p>';

		echo '<p><label for="ces_quote_weight"><strong>' . esc_html__( 'Weight', 'classic-edu-suite' ) . '</strong></label><br />';
		echo '<input type="number" min="1" max="10" step="1" id="ces_quote_weight" name="ces_quote_weight" value="' . esc_attr( (string) $weight ) . '" />';
		echo '<span class="description">' . esc_html__( '1-10. Higher weights come up more often inside a cycle.', 'classic-edu-suite' ) . '</span></p>';
	}

	/**
	 * Persist the meta box values.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['ces_quote_meta_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['ces_quote_meta_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ces_quote_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$author = isset( $_POST['ces_quote_author'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_quote_author'] ) ) : '';
		$active = isset( $_POST['ces_quote_active'] ) ? '1' : '0';
		$date   = isset( $_POST['ces_quote_scheduled_for'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_quote_scheduled_for'] ) ) : '';
		$weight = isset( $_POST['ces_quote_weight'] ) ? absint( $_POST['ces_quote_weight'] ) : 1;

		self::save_quote_meta(
			$post_id,
			array(
				'author'        => $author,
				'active'        => '1' === $active,
				'scheduled_for' => $date,
				'weight'        => $weight,
			)
		);

		self::sync_title( $post_id, $post->post_content );
	}

	/**
	 * Write the quote meta for a post, sanitising every value.
	 *
	 * Shared by the edit screen, the bulk importer and the seeder.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    author, active, scheduled_for, weight.
	 * @return void
	 */
	public static function save_quote_meta( $post_id, array $args ) {
		$post_id = (int) $post_id;

		$author = isset( $args['author'] ) ? sanitize_text_field( (string) $args['author'] ) : '';
		$active = ! empty( $args['active'] ) ? '1' : '0';
		$weight = isset( $args['weight'] ) ? (int) $args['weight'] : 1;
		$weight = max( 1, min( 10, $weight ) );
		$date   = isset( $args['scheduled_for'] ) ? self::sanitize_date( (string) $args['scheduled_for'] ) : '';

		update_post_meta( $post_id, self::META_AUTHOR, $author );
		update_post_meta( $post_id, self::META_ACTIVE, $active );
		update_post_meta( $post_id, self::META_WEIGHT, (string) $weight );
		update_post_meta( $post_id, self::META_SCHEDULED, $date );

		$content = (string) get_post_field( 'post_content', $post_id );
		update_post_meta( $post_id, self::META_HASH, self::hash_text( $content ) );
	}

	/**
	 * Validate a Y-m-d date string, returning '' when it is not one.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$parts = explode( '-', $value );
		if ( 3 !== count( $parts ) ) {
			return '';
		}

		list( $year, $month, $day ) = array_map( 'absint', $parts );

		if ( ! checkdate( $month, $day, $year ) ) {
			return '';
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Normalised hash of a quote's text, used to detect duplicates.
	 *
	 * @param string $text Quote text.
	 * @return string
	 */
	public static function hash_text( $text ) {
		$normalised = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', ' ', (string) $text ) ) );
		return md5( $normalised );
	}

	/**
	 * Keep the post title readable in list tables without a second field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Quote text.
	 * @return void
	 */
	public static function sync_title( $post_id, $content ) {
		$text = trim( wp_strip_all_tags( (string) $content ) );

		if ( '' === $text ) {
			return;
		}

		$title = wp_html_excerpt( $text, 70, '…' );
		$post  = get_post( $post_id );

		if ( ! $post || $post->post_title === $title ) {
			return;
		}

		// Unhook to avoid recursion through save_post.
		remove_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10 );

		wp_update_post(
			array(
				'ID'         => (int) $post_id,
				'post_title' => $title,
			)
		);

		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	/**
	 * Create a quote post from raw text.
	 *
	 * @param string $text Quote text.
	 * @param array  $args author, active, scheduled_for, weight, terms, skip_duplicates.
	 * @return int|WP_Error Post ID, 0 when skipped as a duplicate, or WP_Error.
	 */
	public static function create( $text, array $args = array() ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );

		if ( '' === $text ) {
			return new WP_Error( 'ces_quote_empty', __( 'The quote text is empty.', 'classic-edu-suite' ) );
		}

		$defaults = array(
			'author'          => '',
			'active'          => true,
			'scheduled_for'   => '',
			'weight'          => 1,
			'terms'           => array(),
			'skip_duplicates' => true,
		);

		$args = array_merge( $defaults, $args );

		if ( $args['skip_duplicates'] && self::find_by_text( $text ) ) {
			return 0;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => wp_html_excerpt( $text, 70, '…' ),
				'post_content' => $text,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::save_quote_meta( $post_id, $args );

		if ( ! empty( $args['terms'] ) ) {
			wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', (array) $args['terms'] ), self::TAXONOMY, false );
		}

		return (int) $post_id;
	}

	/**
	 * Look up an existing quote by its normalised text.
	 *
	 * @param string $text Quote text.
	 * @return int Post ID or 0.
	 */
	public static function find_by_text( $text ) {
		$found = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'         => self::META_HASH,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => self::hash_text( $text ),
			)
		);

		return empty( $found ) ? 0 : (int) $found[0];
	}

	/**
	 * Shape a quote post into the array the templates and REST layer use.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return array{id:int,text:string,author:string,categories:string[]} Empty id when not a quote.
	 */
	public static function to_array( $post ) {
		$post = get_post( $post );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return array(
				'id'         => 0,
				'text'       => '',
				'author'     => '',
				'categories' => array(),
			);
		}

		$terms      = get_the_terms( $post->ID, self::TAXONOMY );
		$categories = array();

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = $term->name;
			}
		}

		return array(
			'id'         => (int) $post->ID,
			'text'       => trim( wp_strip_all_tags( $post->post_content ) ),
			'author'     => (string) get_post_meta( $post->ID, self::META_AUTHOR, true ),
			'categories' => $categories,
		);
	}

	/**
	 * Count active quotes in the pool.
	 *
	 * @return int
	 */
	public static function count_active() {
		$pool = CES_Quotes_Scheduler::pool();
		return count( $pool['rotation'] ) + count( $pool['scheduled'] );
	}
}
