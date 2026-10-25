<?php
/**
 * Shared admin menu.
 *
 * Owns the top-level "Classic EDU" menu and the settings screen. Modules do
 * not edit this file: they add their own screens by filtering
 * `ces_admin_submenus`, which keeps module ownership of files disjoint.
 *
 * Each submenu entry is an array:
 *     array(
 *         'slug'       => 'ces-questions',
 *         'title'      => 'Question Bank',
 *         'menu_title' => 'Question Bank',
 *         'capability' => 'ces_manage_cbt',
 *         'callback'   => array( 'CES_Cbt_Admin', 'render_page' ),
 *         'position'   => 20,
 *     )
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin menu tree.
 */
class CES_Admin_Menu {

	/**
	 * Top-level menu slug.
	 */
	const SLUG = 'classic-edu';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ces_save_settings', array( __CLASS__, 'handle_save_settings' ) );
	}

	/**
	 * Build the menu.
	 *
	 * @return void
	 */
	public static function register() {
		add_menu_page(
			__( 'Classic EDU', 'classic-edu-suite' ),
			__( 'Classic EDU', 'classic-edu-suite' ),
			'ces_manage',
			self::SLUG,
			array( __CLASS__, 'render_overview' ),
			'dashicons-welcome-learn-more',
			26
		);

		add_submenu_page(
			self::SLUG,
			__( 'Overview', 'classic-edu-suite' ),
			__( 'Overview', 'classic-edu-suite' ),
			'ces_manage',
			self::SLUG,
			array( __CLASS__, 'render_overview' )
		);

		/**
		 * Collect module admin screens.
		 *
		 * @param array $submenus List of submenu definitions.
		 */
		$submenus = apply_filters( 'ces_admin_submenus', array() );

		usort(
			$submenus,
			static function ( $a, $b ) {
				return ( isset( $a['position'] ) ? $a['position'] : 50 ) <=> ( isset( $b['position'] ) ? $b['position'] : 50 );
			}
		);

		foreach ( $submenus as $item ) {
			if ( empty( $item['slug'] ) || empty( $item['callback'] ) ) {
				continue;
			}

			add_submenu_page(
				self::SLUG,
				isset( $item['title'] ) ? $item['title'] : $item['slug'],
				isset( $item['menu_title'] ) ? $item['menu_title'] : ( isset( $item['title'] ) ? $item['title'] : $item['slug'] ),
				isset( $item['capability'] ) ? $item['capability'] : 'ces_manage',
				$item['slug'],
				$item['callback']
			);
		}

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'classic-edu-suite' ),
			__( 'Settings', 'classic-edu-suite' ),
			'manage_options',
			'ces-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Enqueue admin CSS on plugin screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'classic-edu' ) && false === strpos( (string) $hook, 'ces-' ) ) {
			return;
		}

		wp_enqueue_style(
			'ces-admin',
			CES_URL . 'admin/assets/admin.css',
			array(),
			CES_VERSION
		);
	}

	/**
	 * Overview screen: headline numbers across all modules.
	 *
	 * @return void
	 */
	public static function render_overview() {
		if ( ! CES_Helpers::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'classic-edu-suite' ) );
		}

		/**
		 * Modules push their own stat cards here.
		 *
		 * Each entry: array( 'label' => '', 'value' => '', 'href' => '' ).
		 *
		 * @param array $cards Stat cards.
		 */
		$cards = apply_filters( 'ces_overview_cards', array() );

		echo '<div class="wrap ces-wrap">';
		echo '<h1>' . esc_html__( 'Classic EDU Suite', 'classic-edu-suite' ) . '</h1>';
		echo '<p class="ces-sub">' . esc_html__( 'CBT exams, syllabus videos, coin wallet and daily quotes.', 'classic-edu-suite' ) . '</p>';

		if ( empty( $cards ) ) {
			echo '<p>' . esc_html__( 'No data yet. Add questions, courses and quotes to get started.', 'classic-edu-suite' ) . '</p>';
		} else {
			echo '<div class="ces-cards">';
			foreach ( $cards as $card ) {
				$label = isset( $card['label'] ) ? $card['label'] : '';
				$value = isset( $card['value'] ) ? $card['value'] : '';
				$href  = isset( $card['href'] ) ? $card['href'] : '';

				echo '<div class="ces-card">';
				echo '<span class="ces-card__value">' . esc_html( $value ) . '</span>';
				echo '<span class="ces-card__label">' . esc_html( $label ) . '</span>';
				if ( $href ) {
					echo '<a class="ces-card__link" href="' . esc_url( $href ) . '">' . esc_html__( 'Manage', 'classic-edu-suite' ) . '</a>';
				}
				echo '</div>';
			}
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Settings screen.
	 *
	 * Sections are contributed by modules through `ces_settings_sections` so
	 * that each module owns the definition of its own fields.
	 *
	 * @return void
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'classic-edu-suite' ) );
		}

		$settings = CES_Helpers::settings();

		/**
		 * Settings sections.
		 *
		 * Each section: array(
		 *   'title'  => 'Coin Wallet',
		 *   'fields' => array(
		 *      'coins_per_referral' => array(
		 *          'label' => 'Coins per qualified referral',
		 *          'type'  => 'number'|'text'|'checkbox'|'select',
		 *          'help'  => '',
		 *          'options' => array( 'value' => 'Label' ), // select only
		 *      ),
		 *   ),
		 * )
		 *
		 * @param array $sections Settings sections.
		 */
		$sections = apply_filters( 'ces_settings_sections', array() );

		$notice = isset( $_GET['ces_saved'] ) ? absint( $_GET['ces_saved'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap ces-wrap">';
		echo '<h1>' . esc_html__( 'Classic EDU Settings', 'classic-edu-suite' ) . '</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'classic-edu-suite' ) . '</p></div>';
		}

		if ( empty( $sections ) ) {
			echo '<p>' . esc_html__( 'No settings registered.', 'classic-edu-suite' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ces_save_settings' );
		echo '<input type="hidden" name="action" value="ces_save_settings" />';

		foreach ( $sections as $section ) {
			if ( empty( $section['fields'] ) ) {
				continue;
			}

			echo '<h2>' . esc_html( isset( $section['title'] ) ? $section['title'] : '' ) . '</h2>';
			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $section['fields'] as $key => $field ) {
				$value = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
				$type  = isset( $field['type'] ) ? $field['type'] : 'text';
				$label = isset( $field['label'] ) ? $field['label'] : $key;

				echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';

				if ( 'checkbox' === $type ) {
					echo '<input type="hidden" name="ces[' . esc_attr( $key ) . ']" value="0" />';
					echo '<label><input type="checkbox" id="' . esc_attr( $key ) . '" name="ces[' . esc_attr( $key ) . ']" value="1" ' . checked( (int) $value, 1, false ) . ' /> ';
					echo esc_html__( 'Enabled', 'classic-edu-suite' ) . '</label>';
				} elseif ( 'select' === $type && ! empty( $field['options'] ) ) {
					echo '<select id="' . esc_attr( $key ) . '" name="ces[' . esc_attr( $key ) . ']">';
					foreach ( $field['options'] as $opt_value => $opt_label ) {
						echo '<option value="' . esc_attr( $opt_value ) . '" ' . selected( (string) $value, (string) $opt_value, false ) . '>' . esc_html( $opt_label ) . '</option>';
					}
					echo '</select>';
				} elseif ( 'number' === $type ) {
					echo '<input type="number" class="small-text" id="' . esc_attr( $key ) . '" name="ces[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" />';
				} elseif ( 'password' === $type ) {
					echo '<input type="password" class="regular-text" id="' . esc_attr( $key ) . '" name="ces[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" autocomplete="new-password" />';
				} else {
					echo '<input type="text" class="regular-text" id="' . esc_attr( $key ) . '" name="ces[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" />';
				}

				if ( ! empty( $field['help'] ) ) {
					echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
				}

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		submit_button( __( 'Save Settings', 'classic-edu-suite' ) );
		echo '</form></div>';
	}

	/**
	 * Persist the settings form.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'classic-edu-suite' ) );
		}

		check_admin_referer( 'ces_save_settings' );

		$raw = isset( $_POST['ces'] ) ? wp_unslash( $_POST['ces'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( is_array( $raw ) ) {
			CES_Settings::save( $raw );
		}

		wp_safe_redirect( add_query_arg( 'ces_saved', 1, admin_url( 'admin.php?page=ces-settings' ) ) );
		exit;
	}
}
