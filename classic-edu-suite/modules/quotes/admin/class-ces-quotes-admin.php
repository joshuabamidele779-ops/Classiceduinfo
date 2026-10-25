<?php
/**
 * Admin screen for the quotes module.
 *
 * Four views, all under Classic EDU → Daily Quotes:
 *   pool     Manage the quote pool (activate, park, bin, edit).
 *   import   Paste many quotes at once, one per line, `quote | author`.
 *   report   Impressions, dismissal rate and invite-CTA click rate.
 *   preview  See the popup exactly as a student sees it.
 *
 * Registers its own settings fields through `ces_settings_sections` and one
 * card on the overview screen through `ces_overview_cards`.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quote administration.
 */
class CES_Quotes_Admin {

	/**
	 * Admin page slug.
	 */
	const SLUG = 'ces-quotes';

	/**
	 * Capability required for the screen.
	 */
	const CAP = 'ces_manage_quotes';

	/**
	 * Quotes listed per page in the pool view.
	 */
	const PER_PAGE = 25;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_admin_submenus', array( __CLASS__, 'submenu' ) );
		add_filter( 'ces_settings_sections', array( __CLASS__, 'settings_section' ) );
		add_filter( 'ces_overview_cards', array( __CLASS__, 'overview_cards' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ces_quotes_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_ces_quotes_bulk', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_ces_quotes_seed', array( __CLASS__, 'handle_seed' ) );
	}

	/**
	 * Register the submenu entry.
	 *
	 * @param array $submenus Existing entries.
	 * @return array
	 */
	public static function submenu( $submenus ) {
		if ( ! is_array( $submenus ) ) {
			$submenus = array();
		}

		$submenus[] = array(
			'slug'       => self::SLUG,
			'title'      => __( 'Daily Quotes', 'classic-edu-suite' ),
			'menu_title' => __( 'Daily Quotes', 'classic-edu-suite' ),
			'capability' => self::CAP,
			'callback'   => array( __CLASS__, 'render_page' ),
			'position'   => 50,
		);

		return $submenus;
	}

	/**
	 * Register the quotes settings fields.
	 *
	 * @param array $sections Existing sections.
	 * @return array
	 */
	public static function settings_section( $sections ) {
		if ( ! is_array( $sections ) ) {
			$sections = array();
		}

		$sections[] = array(
			'title'  => __( 'Daily Quotes', 'classic-edu-suite' ),
			'fields' => array(
				'quotes_enabled'        => array(
					'label' => __( 'Enable the daily quote popup', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Turn the whole popup off without touching the quote pool.', 'classic-edu-suite' ),
				),
				'quotes_frequency'      => array(
					'label'   => __( 'How often', 'classic-edu-suite' ),
					'type'    => 'select',
					'options' => array(
						'daily'       => __( 'Once per student per day', 'classic-edu-suite' ),
						'every_visit' => __( 'Once per visit', 'classic-edu-suite' ),
					),
					'help'    => __( 'Once per day is strongly recommended. Once per visit is for campaigns only.', 'classic-edu-suite' ),
				),
				'quotes_delay_seconds'  => array(
					'label' => __( 'Delay before showing (seconds)', 'classic-edu-suite' ),
					'type'  => 'number',
					'help'  => __( 'Counted from page load. 8 seconds lets the student start reading first.', 'classic-edu-suite' ),
				),
				'quotes_show_to_guests' => array(
					'label' => __( 'Show to logged-out visitors', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Guests see the quote with a "Create a free account" call to action instead of a referral link.', 'classic-edu-suite' ),
				),
				'quotes_referral_cta'   => array(
					'label' => __( 'Attach the invite-a-friend task', 'classic-edu-suite' ),
					'type'  => 'checkbox',
					'help'  => __( 'Adds the student\'s referral link and a WhatsApp share button under the quote.', 'classic-edu-suite' ),
				),
				'quotes_cta_text'       => array(
					'label' => __( 'Invite task wording', 'classic-edu-suite' ),
					'type'  => 'text',
					'help'  => __( 'Use {coins} to insert the coins-per-referral setting. Example: Invite a friend and earn {coins} coins.', 'classic-edu-suite' ),
				),
			),
		);

		return $sections;
	}

	/**
	 * Add the quotes card to the admin overview.
	 *
	 * @param array $cards Existing cards.
	 * @return array
	 */
	public static function overview_cards( $cards ) {
		if ( ! is_array( $cards ) ) {
			$cards = array();
		}

		$stats = CES_Quotes_Scheduler::stats( 30 );

		$cards[] = array(
			'label' => __( 'Active quotes', 'classic-edu-suite' ),
			'value' => CES_Helpers::format_coins( CES_Quotes_Cpt::count_active() ),
			'href'  => self::url(),
		);

		$cards[] = array(
			'label' => __( 'Quote invites clicked (30 days)', 'classic-edu-suite' ),
			'value' => CES_Helpers::format_coins( $stats['cta_clicked'] ),
			'href'  => self::url( 'report' ),
		);

		return $cards;
	}

	/**
	 * Admin URL for this screen.
	 *
	 * @param string $view Optional view slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $view = '', array $args = array() ) {
		$args['page'] = self::SLUG;

		if ( '' !== $view ) {
			$args['view'] = $view;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Enqueue the popup stylesheet on this screen, for the preview view.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		if ( class_exists( 'CES_Assets' ) && method_exists( 'CES_Assets', 'enqueue_base' ) ) {
			CES_Assets::enqueue_base();
		}

		wp_enqueue_style(
			CES_Quotes_Popup::HANDLE,
			CES_URL . 'modules/quotes/assets/quotes-popup.css',
			array(),
			CES_VERSION
		);
	}

	/**
	 * Current view slug.
	 *
	 * @return string
	 */
	private static function current_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'pool';

		return in_array( $view, array( 'pool', 'import', 'report', 'preview' ), true ) ? $view : 'pool';
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! CES_Helpers::can_manage( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'classic-edu-suite' ) );
		}

		$view = self::current_view();

		echo '<div class="wrap ces-wrap">';
		echo '<h1>' . esc_html__( 'Daily Quotes', 'classic-edu-suite' ) . '</h1>';
		echo '<p class="ces-sub">' . esc_html__( 'One motivating quote per student per day, with the invite-a-friend task attached.', 'classic-edu-suite' ) . '</p>';

		self::render_notices();
		self::render_tabs( $view );

		if ( 'import' === $view ) {
			self::render_import();
		} elseif ( 'report' === $view ) {
			self::render_report();
		} elseif ( 'preview' === $view ) {
			self::render_preview();
		} else {
			self::render_pool();
		}

		echo '</div>';
	}

	/**
	 * Tab navigation.
	 *
	 * @param string $view Active view.
	 * @return void
	 */
	private static function render_tabs( $view ) {
		$tabs = array(
			'pool'    => __( 'Quote pool', 'classic-edu-suite' ),
			'import'  => __( 'Bulk import', 'classic-edu-suite' ),
			'report'  => __( 'Engagement', 'classic-edu-suite' ),
			'preview' => __( 'Preview popup', 'classic-edu-suite' ),
		);

		echo '<h2 class="nav-tab-wrapper ces-admin-tabs">';

		foreach ( $tabs as $slug => $label ) {
			$class = 'nav-tab' . ( $slug === $view ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::url( $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '</h2>';
	}

	/**
	 * Admin notices driven by redirect query args.
	 *
	 * @return void
	 */
	private static function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$imported = isset( $_GET['ces_imported'] ) ? absint( $_GET['ces_imported'] ) : -1;
		$skipped  = isset( $_GET['ces_skipped'] ) ? absint( $_GET['ces_skipped'] ) : 0;
		$failed   = isset( $_GET['ces_failed'] ) ? absint( $_GET['ces_failed'] ) : 0;
		$bulk     = isset( $_GET['ces_bulk'] ) ? absint( $_GET['ces_bulk'] ) : -1;
		$seeded   = isset( $_GET['ces_seeded'] ) ? absint( $_GET['ces_seeded'] ) : -1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $imported >= 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: 1: imported count, 2: skipped count, 3: failed count. */
				esc_html__( 'Imported %1$d quotes. Skipped %2$d duplicates. %3$d lines could not be read.', 'classic-edu-suite' ),
				(int) $imported,
				(int) $skipped,
				(int) $failed
			);
			echo '</p></div>';
		}

		if ( $bulk >= 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %d: number of quotes updated. */
				esc_html__( '%d quotes updated.', 'classic-edu-suite' ),
				(int) $bulk
			);
			echo '</p></div>';
		}

		if ( $seeded >= 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %d: number of starter quotes added. */
				esc_html__( 'Added %d starter quotes.', 'classic-edu-suite' ),
				(int) $seeded
			);
			echo '</p></div>';
		}
	}

	// -----------------------------------------------------------------
	// Pool
	// -----------------------------------------------------------------

	/**
	 * The quote pool table.
	 *
	 * @return void
	 */
	private static function render_pool() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$query = new WP_Query(
			array(
				'post_type'      => CES_Quotes_Cpt::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		echo '<p class="ces-actions">';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . CES_Quotes_Cpt::POST_TYPE ) ) . '">' . esc_html__( 'Add a quote', 'classic-edu-suite' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::url( 'import' ) ) . '">' . esc_html__( 'Bulk import', 'classic-edu-suite' ) . '</a> ';

		if ( ! $query->have_posts() ) {
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ces_quotes_seed' ), 'ces_quotes_seed' ) ) . '">' . esc_html__( 'Install the starter pack', 'classic-edu-suite' ) . '</a>';
		}

		echo '</p>';

		if ( ! $query->have_posts() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The quote pool is empty, so the popup will not appear. Add a quote or install the starter pack.', 'classic-edu-suite' ) . '</p></div>';
			wp_reset_postdata();
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ces_quotes_bulk' );
		echo '<input type="hidden" name="action" value="ces_quotes_bulk" />';

		echo '<div class="tablenav top"><div class="alignleft actions bulkactions">';
		echo '<label class="screen-reader-text" for="ces_bulk_action">' . esc_html__( 'Bulk action', 'classic-edu-suite' ) . '</label>';
		echo '<select name="bulk_action" id="ces_bulk_action">';
		echo '<option value="">' . esc_html__( 'Bulk actions', 'classic-edu-suite' ) . '</option>';
		echo '<option value="activate">' . esc_html__( 'Activate', 'classic-edu-suite' ) . '</option>';
		echo '<option value="deactivate">' . esc_html__( 'Park (deactivate)', 'classic-edu-suite' ) . '</option>';
		echo '<option value="trash">' . esc_html__( 'Move to bin', 'classic-edu-suite' ) . '</option>';
		echo '</select> ';
		submit_button( __( 'Apply', 'classic-edu-suite' ), 'secondary', 'ces_apply', false );
		echo '</div></div>';

		echo '<table class="wp-list-table widefat fixed striped ces-quotes-table"><thead><tr>';
		echo '<td class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-1">' . esc_html__( 'Select all quotes', 'classic-edu-suite' ) . '</label><input type="checkbox" id="cb-select-all-1" /></td>';
		echo '<th scope="col">' . esc_html__( 'Quote', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Attribution', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Categories', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Weight', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Pinned date', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id = get_the_ID();
			$quote   = CES_Quotes_Cpt::to_array( $post_id );
			$active  = '0' !== (string) get_post_meta( $post_id, CES_Quotes_Cpt::META_ACTIVE, true );
			$pinned  = (string) get_post_meta( $post_id, CES_Quotes_Cpt::META_SCHEDULED, true );
			$weight  = (int) get_post_meta( $post_id, CES_Quotes_Cpt::META_WEIGHT, true );
			$status  = get_post_status( $post_id );

			if ( 'publish' !== $status ) {
				$label = __( 'Not published', 'classic-edu-suite' );
			} elseif ( ! $active ) {
				$label = __( 'Parked', 'classic-edu-suite' );
			} else {
				$label = __( 'Active', 'classic-edu-suite' );
			}

			echo '<tr>';
			echo '<th scope="row" class="check-column"><input type="checkbox" name="quote_ids[]" value="' . esc_attr( (string) $post_id ) . '" /></th>';
			echo '<td><strong>' . esc_html( $quote['text'] ) . '</strong><div class="row-actions"><span class="edit"><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html__( 'Edit', 'classic-edu-suite' ) . '</a></span></div></td>';
			echo '<td>' . ( '' !== $quote['author'] ? esc_html( $quote['author'] ) : '<em>' . esc_html__( 'Unattributed', 'classic-edu-suite' ) . '</em>' ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $quote['categories'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) max( 1, $weight ) ) . '</td>';
			echo '<td>' . ( '' !== $pinned ? esc_html( $pinned ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</form>';

		wp_reset_postdata();

		$pages = (int) $query->max_num_pages;

		if ( $pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links returns safe markup.
			echo paginate_links(
				array(
					'base'      => self::url( 'pool' ) . '%_%',
					'format'    => '&paged=%#%',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			);
			echo '</div></div>';
		}
	}

	// -----------------------------------------------------------------
	// Import
	// -----------------------------------------------------------------

	/**
	 * The bulk import form.
	 *
	 * @return void
	 */
	private static function render_import() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ces-import-form">';
		wp_nonce_field( 'ces_quotes_import' );
		echo '<input type="hidden" name="action" value="ces_quotes_import" />';

		echo '<p>' . esc_html__( 'One quote per line. Separate the quote from its attribution with a vertical bar:', 'classic-edu-suite' ) . '</p>';
		echo '<pre class="ces-code">' . esc_html( "Little by little, a little becomes a lot. | Tanzanian proverb\nIt always seems impossible until it is done. | Nelson Mandela\nNo attribution needed on this one." ) . '</pre>';
		echo '<p class="description">' . esc_html__( 'Never invent an attribution. If you are not certain who said a line, leave it blank or mark it as a proverb.', 'classic-edu-suite' ) . '</p>';

		echo '<p><label for="ces_quotes_blob"><strong>' . esc_html__( 'Quotes', 'classic-edu-suite' ) . '</strong></label><br />';
		echo '<textarea id="ces_quotes_blob" name="quotes" rows="14" class="large-text code" required></textarea></p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="ces_quotes_category">' . esc_html__( 'Category', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="ces_quotes_category" name="category" value="" />';
		echo '<p class="description">' . esc_html__( 'Optional. Applied to every imported quote; created if it does not exist.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ces_quotes_weight">' . esc_html__( 'Weight', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="number" min="1" max="10" step="1" class="small-text" id="ces_quotes_weight" name="weight" value="1" />';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Duplicates', 'classic-edu-suite' ) . '</th><td>';
		echo '<label><input type="checkbox" name="skip_duplicates" value="1" checked="checked" /> ' . esc_html__( 'Skip quotes whose text already exists', 'classic-edu-suite' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Import quotes', 'classic-edu-suite' ) );
		echo '</form>';
	}

	/**
	 * Handle the import submission.
	 *
	 * @return void
	 */
	public static function handle_import() {
		if ( ! CES_Helpers::can_manage( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'classic-edu-suite' ) );
		}

		check_admin_referer( 'ces_quotes_import' );

		$blob = isset( $_POST['quotes'] ) ? (string) wp_unslash( $_POST['quotes'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$blob = wp_strip_all_tags( $blob );

		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$weight   = isset( $_POST['weight'] ) ? absint( $_POST['weight'] ) : 1;
		$skip     = ! empty( $_POST['skip_duplicates'] );

		$result = self::import_text( $blob, $category, $weight, $skip );

		wp_safe_redirect(
			self::url(
				'pool',
				array(
					'ces_imported' => $result['created'],
					'ces_skipped'  => $result['skipped'],
					'ces_failed'   => $result['failed'],
				)
			)
		);
		exit;
	}

	/**
	 * Parse and import a pasted block of quotes.
	 *
	 * @param string $blob     Raw text, one quote per line.
	 * @param string $category Optional category applied to all of them.
	 * @param int    $weight   Rotation weight.
	 * @param bool   $skip     Skip duplicates.
	 * @return array{created:int,skipped:int,failed:int}
	 */
	public static function import_text( $blob, $category = '', $weight = 1, $skip = true ) {
		$created = 0;
		$skipped = 0;
		$failed  = 0;

		$lines = preg_split( '/\r\n|\r|\n/', (string) $blob );

		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$terms = '' !== trim( $category ) ? array( trim( $category ) ) : array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$author = '';
			$text   = $line;

			if ( false !== strpos( $line, '|' ) ) {
				$parts  = explode( '|', $line, 2 );
				$text   = trim( $parts[0] );
				$author = trim( $parts[1] );
			}

			if ( '' === $text ) {
				$failed++;
				continue;
			}

			$result = CES_Quotes_Cpt::create(
				$text,
				array(
					'author'          => $author,
					'active'          => true,
					'weight'          => $weight,
					'terms'           => $terms,
					'skip_duplicates' => (bool) $skip,
				)
			);

			if ( is_wp_error( $result ) ) {
				$failed++;
			} elseif ( 0 === $result ) {
				$skipped++;
			} else {
				$created++;
			}
		}

		if ( $created > 0 ) {
			CES_Quotes_Scheduler::flush_pool();
		}

		return array(
			'created' => $created,
			'skipped' => $skipped,
			'failed'  => $failed,
		);
	}

	/**
	 * Handle the bulk action form.
	 *
	 * @return void
	 */
	public static function handle_bulk() {
		if ( ! CES_Helpers::can_manage( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'classic-edu-suite' ) );
		}

		check_admin_referer( 'ces_quotes_bulk' );

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$raw    = isset( $_POST['quote_ids'] ) ? (array) wp_unslash( $_POST['quote_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ids    = array_filter( array_map( 'absint', $raw ) );
		$count  = 0;

		if ( in_array( $action, array( 'activate', 'deactivate', 'trash' ), true ) ) {
			foreach ( $ids as $id ) {
				$post = get_post( $id );

				if ( ! $post || CES_Quotes_Cpt::POST_TYPE !== $post->post_type ) {
					continue;
				}

				if ( 'trash' === $action ) {
					if ( current_user_can( 'delete_post', $id ) && wp_trash_post( $id ) ) {
						$count++;
					}
					continue;
				}

				if ( ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}

				update_post_meta( $id, CES_Quotes_Cpt::META_ACTIVE, 'activate' === $action ? '1' : '0' );
				$count++;
			}
		}

		CES_Quotes_Scheduler::flush_pool();

		wp_safe_redirect( self::url( 'pool', array( 'ces_bulk' => $count ) ) );
		exit;
	}

	/**
	 * Re-install the starter quote pack on request.
	 *
	 * @return void
	 */
	public static function handle_seed() {
		if ( ! CES_Helpers::can_manage( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'classic-edu-suite' ) );
		}

		check_admin_referer( 'ces_quotes_seed' );

		$created = CES_Quotes_Seeder::install();

		wp_safe_redirect( self::url( 'pool', array( 'ces_seeded' => $created ) ) );
		exit;
	}

	// -----------------------------------------------------------------
	// Report
	// -----------------------------------------------------------------

	/**
	 * The engagement report.
	 *
	 * @return void
	 */
	private static function render_report() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;

		$stats = CES_Quotes_Scheduler::stats( $days );

		echo '<p class="ces-actions">';

		foreach ( array( 7, 30, 90 ) as $option ) {
			$class = 'button' . ( $option === $days ? ' button-primary' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::url( 'report', array( 'days' => $option ) ) ) . '">';
			printf(
				/* translators: %d: number of days. */
				esc_html__( 'Last %d days', 'classic-edu-suite' ),
				(int) $option
			);
			echo '</a> ';
		}

		echo '</p>';

		$cards = array(
			array(
				'value' => CES_Helpers::format_coins( $stats['impressions'] ),
				'label' => __( 'Popups shown', 'classic-edu-suite' ),
			),
			array(
				'value' => CES_Helpers::format_coins( $stats['unique_viewers'] ),
				'label' => __( 'Unique viewers', 'classic-edu-suite' ),
			),
			array(
				'value' => $stats['dismiss_rate'] . '%',
				'label' => __( 'Dismissed', 'classic-edu-suite' ),
			),
			array(
				'value' => $stats['cta_rate'] . '%',
				'label' => __( 'Invite clicked', 'classic-edu-suite' ),
			),
		);

		echo '<div class="ces-cards">';

		foreach ( $cards as $card ) {
			echo '<div class="ces-card">';
			echo '<span class="ces-card__value">' . esc_html( $card['value'] ) . '</span>';
			echo '<span class="ces-card__label">' . esc_html( $card['label'] ) . '</span>';
			echo '</div>';
		}

		echo '</div>';

		echo '<p class="description">' . esc_html__( 'A dismissal is a student closing the popup. An invite click means they opened their referral share. Impressions are recorded once per student per day.', 'classic-edu-suite' ) . '</p>';

		$rows = CES_Quotes_Scheduler::per_quote_stats( $days, 20 );

		echo '<h2>' . esc_html__( 'Best performing quotes', 'classic-edu-suite' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No impressions recorded in this window yet.', 'classic-edu-suite' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Quote', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Shown', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Dismissed', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Invite clicked', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Click rate', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$quote = CES_Quotes_Cpt::to_array( $row['quote_id'] );
			$text  = '' !== $quote['text'] ? $quote['text'] : __( '(deleted quote)', 'classic-edu-suite' );
			$rate  = $row['impressions'] > 0 ? round( ( $row['cta_clicked'] / $row['impressions'] ) * 100, 1 ) : 0;

			echo '<tr>';
			echo '<td>' . esc_html( wp_html_excerpt( $text, 90, '…' ) ) . '</td>';
			echo '<td>' . esc_html( CES_Helpers::format_coins( $row['impressions'] ) ) . '</td>';
			echo '<td>' . esc_html( CES_Helpers::format_coins( $row['dismissed'] ) ) . '</td>';
			echo '<td>' . esc_html( CES_Helpers::format_coins( $row['cta_clicked'] ) ) . '</td>';
			echo '<td>' . esc_html( $rate . '%' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// -----------------------------------------------------------------
	// Preview
	// -----------------------------------------------------------------

	/**
	 * Render the popup inert, as a student would see it.
	 *
	 * @return void
	 */
	private static function render_preview() {
		$quote_id = (int) CES_Quotes_Scheduler::quote_of_the_day();

		if ( $quote_id <= 0 ) {
			$quote_id = (int) CES_Quotes_Scheduler::random_quote();
		}

		echo '<h2>' . esc_html__( 'What a student sees', 'classic-edu-suite' ) . '</h2>';

		if ( $quote_id <= 0 ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'There is nothing to preview: the quote pool is empty.', 'classic-edu-suite' ) . '</p></div>';
			return;
		}

		$markup = CES_Quotes_Popup::markup( $quote_id, true );

		if ( '' === $markup ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The popup template produced no output. Check for a theme override of quotes/popup.php.', 'classic-edu-suite' ) . '</p></div>';
			return;
		}

		echo '<div class="ces-popup-preview">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the template.
		echo $markup;
		echo '</div>';

		echo '<h2>' . esc_html__( 'Notes', 'classic-edu-suite' ) . '</h2>';
		echo '<ul class="ul-disc">';
		echo '<li>' . esc_html__( 'The popup fires only once per student per calendar day and remembers dismissal in a cookie, so it never re-appears as they browse.', 'classic-edu-suite' ) . '</li>';
		echo '<li>' . esc_html__( 'Its CSS and JS are not loaded at all on a page view where it cannot fire.', 'classic-edu-suite' ) . '</li>';
		echo '<li>' . esc_html__( 'This site also runs Popup Builder. If one of its popups fires on the same page, two boxes will appear at once: either exclude that page there, or exclude it here by returning false from the ces_quotes_should_show filter.', 'classic-edu-suite' ) . '</li>';
		echo '</ul>';
	}
}
