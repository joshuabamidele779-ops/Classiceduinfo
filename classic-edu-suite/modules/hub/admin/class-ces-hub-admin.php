<?php
/**
 * Admin side of the study hub: the resource editor and its menus.
 *
 * Questions and exams already have their own screens in the CBT module; this
 * adds the one thing that was missing, a place to put everything a student
 * reads rather than answers.
 *
 * @package ClassicEduSuite\Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resource admin screens.
 */
class CES_Hub_Admin {

	/**
	 * Nonce action for the resource metabox.
	 */
	const NONCE = 'ces_resource_meta';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_admin_submenus', array( __CLASS__, 'submenus' ) );
		add_filter( 'ces_overview_cards', array( __CLASS__, 'overview_cards' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . CES_Hub_Resources::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );

		add_filter( 'manage_' . CES_Hub_Resources::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . CES_Hub_Resources::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );

		add_filter( 'parent_file', array( __CLASS__, 'highlight_parent_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Add the Resources entry under Classic EDU.
	 *
	 * @param array $submenus Submenus so far.
	 * @return array
	 */
	public static function submenus( $submenus ) {
		if ( ! is_array( $submenus ) ) {
			$submenus = array();
		}

		$submenus[] = array(
			'slug'       => 'edit.php?post_type=' . CES_Hub_Resources::POST_TYPE,
			'title'      => __( 'Resources', 'classic-edu-suite' ),
			'menu_title' => __( 'Resources', 'classic-edu-suite' ),
			'capability' => CES_Hub_Resources::CAP,
			'callback'   => '__return_null',
			'position'   => 32,
		);

		return $submenus;
	}

	/**
	 * Headline count on the overview screen.
	 *
	 * @param array $cards Cards so far.
	 * @return array
	 */
	public static function overview_cards( $cards ) {
		if ( ! is_array( $cards ) ) {
			$cards = array();
		}

		$counts = wp_count_posts( CES_Hub_Resources::POST_TYPE );

		$cards[] = array(
			'label' => __( 'Library resources', 'classic-edu-suite' ),
			'value' => number_format_i18n( isset( $counts->publish ) ? (int) $counts->publish : 0 ),
			'href'  => admin_url( 'edit.php?post_type=' . CES_Hub_Resources::POST_TYPE ),
		);

		return $cards;
	}

	/**
	 * Enqueue the media picker on the resource editor.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || CES_Hub_Resources::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'ces-hub-admin',
			CES_URL . 'modules/hub/assets/hub-admin.js',
			array(),
			CES_VERSION,
			true
		);

		wp_localize_script(
			'ces-hub-admin',
			'CES_HUB_ADMIN',
			array(
				'chooseTitle'  => __( 'Choose a file', 'classic-edu-suite' ),
				'chooseButton' => __( 'Use this file', 'classic-edu-suite' ),
			)
		);
	}

	/**
	 * Register the metabox.
	 *
	 * @return void
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'ces-resource-details',
			__( 'Resource details', 'classic-edu-suite' ),
			array( __CLASS__, 'render_meta_box' ),
			CES_Hub_Resources::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Draw the metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		$file_id  = absint( get_post_meta( $post->ID, CES_Hub_Resources::META_FILE, true ) );
		$link     = (string) get_post_meta( $post->ID, CES_Hub_Resources::META_LINK, true );
		$access   = (string) get_post_meta( $post->ID, CES_Hub_Resources::META_ACCESS, true );
		$coins    = absint( get_post_meta( $post->ID, CES_Hub_Resources::META_COINS, true ) );
		$author   = (string) get_post_meta( $post->ID, CES_Hub_Resources::META_AUTHOR, true );
		$pages    = absint( get_post_meta( $post->ID, CES_Hub_Resources::META_PAGES, true ) );
		$featured = '1' === (string) get_post_meta( $post->ID, CES_Hub_Resources::META_FEATURED, true );

		if ( ! array_key_exists( $access, CES_Hub_Resources::access_modes() ) ) {
			$access = 'login';
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		// File.
		echo '<tr><th><label for="ces_res_file">' . esc_html__( 'File', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="hidden" id="ces_res_file" name="ces_res_file" value="' . esc_attr( $file_id ) . '" />';
		echo '<span id="ces-res-file-name">' . esc_html( $file_id ? (string) get_the_title( $file_id ) : __( 'No file chosen', 'classic-edu-suite' ) ) . '</span> ';
		echo '<button type="button" class="button" id="ces-res-file-pick">' . esc_html__( 'Choose file', 'classic-edu-suite' ) . '</button> ';
		echo '<button type="button" class="button" id="ces-res-file-clear">' . esc_html__( 'Clear', 'classic-edu-suite' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'A PDF, Word file or image already in the media library. Leave empty for a text-only resource such as a lesson note.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		// External link.
		echo '<tr><th><label for="ces_res_link">' . esc_html__( 'External link', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="url" class="regular-text" id="ces_res_link" name="ces_res_link" value="' . esc_attr( $link ) . '" placeholder="https://" />';
		echo '<p class="description">' . esc_html__( 'Used only when no file is chosen.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		// Access.
		echo '<tr><th><label for="ces_res_access">' . esc_html__( 'Who can open it', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<select id="ces_res_access" name="ces_res_access">';

		foreach ( CES_Hub_Resources::access_modes() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $access, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></td></tr>';

		// Coins.
		echo '<tr><th><label for="ces_res_coins">' . esc_html__( 'Coin cost', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" min="0" id="ces_res_coins" name="ces_res_coins" value="' . esc_attr( $coins ) . '" />';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: default coin cost. */
				__( 'Only used when access is set to coins. 0 falls back to the site default of %d.', 'classic-edu-suite' ),
				CES_Helpers::setting_int( 'hub_resource_coin_cost', 5 )
			)
		) . '</p>';
		echo '</td></tr>';

		// Author.
		echo '<tr><th><label for="ces_res_author">' . esc_html__( 'Author / publisher', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="ces_res_author" name="ces_res_author" value="' . esc_attr( $author ) . '" />';
		echo '<p class="description">' . esc_html__( 'Shown on novels and set texts.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		// Pages.
		echo '<tr><th><label for="ces_res_pages">' . esc_html__( 'Pages', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" min="0" id="ces_res_pages" name="ces_res_pages" value="' . esc_attr( $pages ) . '" />';
		echo '</td></tr>';

		// Featured.
		echo '<tr><th>' . esc_html__( 'Featured', 'classic-edu-suite' ) . '</th><td>';
		echo '<label><input type="checkbox" name="ces_res_featured" value="1" ' . checked( $featured, true, false ) . ' /> ';
		echo esc_html__( 'Pin this to the top of the library.', 'classic-edu-suite' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'Tag the resource with a Resource Type, and with a Subject, Exam Type and Year where they apply — that is what puts it behind the right tile and in the right filter.', 'classic-edu-suite' ) . '</p>';
	}

	/**
	 * Save the metabox.
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! $post || CES_Hub_Resources::POST_TYPE !== $post->post_type ) {
			return;
		}

		$file_id = isset( $_POST['ces_res_file'] ) ? absint( $_POST['ces_res_file'] ) : 0;

		if ( $file_id && 'attachment' !== get_post_type( $file_id ) ) {
			$file_id = 0;
		}

		update_post_meta( $post_id, CES_Hub_Resources::META_FILE, $file_id );

		$link = isset( $_POST['ces_res_link'] ) ? esc_url_raw( wp_unslash( $_POST['ces_res_link'] ) ) : '';
		update_post_meta( $post_id, CES_Hub_Resources::META_LINK, $link );

		$access = isset( $_POST['ces_res_access'] ) ? sanitize_key( wp_unslash( $_POST['ces_res_access'] ) ) : 'login';

		if ( ! array_key_exists( $access, CES_Hub_Resources::access_modes() ) ) {
			$access = 'login';
		}

		update_post_meta( $post_id, CES_Hub_Resources::META_ACCESS, $access );

		update_post_meta( $post_id, CES_Hub_Resources::META_COINS, isset( $_POST['ces_res_coins'] ) ? absint( $_POST['ces_res_coins'] ) : 0 );
		update_post_meta( $post_id, CES_Hub_Resources::META_PAGES, isset( $_POST['ces_res_pages'] ) ? absint( $_POST['ces_res_pages'] ) : 0 );
		update_post_meta( $post_id, CES_Hub_Resources::META_AUTHOR, isset( $_POST['ces_res_author'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_res_author'] ) ) : '' );
		update_post_meta( $post_id, CES_Hub_Resources::META_FEATURED, empty( $_POST['ces_res_featured'] ) ? '0' : '1' );
	}

	/**
	 * Resource list table columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$out = array();

		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out['ces_access'] = __( 'Access', 'classic-edu-suite' );
				$out['ces_file']   = __( 'File', 'classic-edu-suite' );
			}
		}

		return $out;
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function column( $column, $post_id ) {
		if ( 'ces_access' === $column ) {
			$access = (string) get_post_meta( $post_id, CES_Hub_Resources::META_ACCESS, true );
			$modes  = CES_Hub_Resources::access_modes();

			echo esc_html( isset( $modes[ $access ] ) ? $modes[ $access ] : $modes['login'] );

			if ( 'coins' === $access ) {
				$coins = absint( get_post_meta( $post_id, CES_Hub_Resources::META_COINS, true ) );

				if ( $coins ) {
					echo ' (' . esc_html( number_format_i18n( $coins ) ) . ')';
				}
			}

			return;
		}

		if ( 'ces_file' === $column ) {
			$file_id = absint( get_post_meta( $post_id, CES_Hub_Resources::META_FILE, true ) );
			$link    = (string) get_post_meta( $post_id, CES_Hub_Resources::META_LINK, true );

			if ( $file_id ) {
				echo esc_html( basename( (string) get_attached_file( $file_id ) ) );
			} elseif ( $link ) {
				echo esc_html__( 'External link', 'classic-edu-suite' );
			} else {
				echo esc_html__( 'Read on site', 'classic-edu-suite' );
			}
		}
	}

	/**
	 * Keep the Classic EDU menu highlighted on resource screens.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	public static function highlight_parent_menu( $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && CES_Hub_Resources::POST_TYPE === $screen->post_type ) {
			return 'classic-edu';
		}

		return $parent_file;
	}
}
