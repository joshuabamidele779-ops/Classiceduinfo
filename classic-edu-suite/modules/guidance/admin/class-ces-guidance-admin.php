<?php
/**
 * Admin side of Guidance: the editors and a bulk CSV importer.
 *
 * Typing a thousand institutions by hand is not a plan, so the importer takes
 * a spreadsheet for each of the three types and upserts by title. Programmes
 * named in a row are created as terms on the fly, which is what makes the
 * School Finder work the moment the file finishes uploading.
 *
 * @package ClassicEduSuite\Guidance
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guidance admin screens.
 */
class CES_Guidance_Admin {

	/**
	 * Importer page slug.
	 */
	const PAGE = 'ces-guidance-import';

	/**
	 * Metabox nonce action.
	 */
	const NONCE = 'ces_guidance_meta';

	/**
	 * Transient prefix for the import report.
	 */
	const REPORT_KEY = 'ces_guidance_report_';

	/**
	 * Largest file we will read, in bytes.
	 */
	const MAX_BYTES = 8388608;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_admin_submenus', array( __CLASS__, 'submenus' ) );
		add_filter( 'ces_overview_cards', array( __CLASS__, 'overview_cards' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta' ), 10, 2 );

		add_action( 'admin_post_ces_guidance_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_ces_guidance_sample', array( __CLASS__, 'handle_sample' ) );

		add_filter( 'parent_file', array( __CLASS__, 'highlight_parent_menu' ) );
	}

	/**
	 * The three post types and their labels.
	 *
	 * @return array Map of slug => label.
	 */
	public static function types() {
		return array(
			CES_Guidance_Cpt::INSTITUTION => __( 'Institutions', 'classic-edu-suite' ),
			CES_Guidance_Cpt::DEPARTMENT  => __( 'Departments', 'classic-edu-suite' ),
			CES_Guidance_Cpt::CAREER      => __( 'Careers', 'classic-edu-suite' ),
		);
	}

	/**
	 * Add the guidance entries under Classic EDU.
	 *
	 * @param array $submenus Submenus so far.
	 * @return array
	 */
	public static function submenus( $submenus ) {
		if ( ! is_array( $submenus ) ) {
			$submenus = array();
		}

		$cap      = CES_Guidance_Cpt::CAP;
		$position = 60;

		foreach ( self::types() as $post_type => $label ) {
			$submenus[] = array(
				'slug'       => 'edit.php?post_type=' . $post_type,
				'title'      => $label,
				'menu_title' => $label,
				'capability' => $cap,
				'callback'   => '__return_null',
				'position'   => $position,
			);

			++$position;
		}

		$submenus[] = array(
			'slug'       => self::PAGE,
			'title'      => __( 'Guidance Import', 'classic-edu-suite' ),
			'menu_title' => __( 'Guidance Import', 'classic-edu-suite' ),
			'capability' => $cap,
			'callback'   => array( __CLASS__, 'render_page' ),
			'position'   => $position,
		);

		return $submenus;
	}

	/**
	 * Headline counts on the overview screen.
	 *
	 * @param array $cards Cards so far.
	 * @return array
	 */
	public static function overview_cards( $cards ) {
		if ( ! is_array( $cards ) ) {
			$cards = array();
		}

		$counts = wp_count_posts( CES_Guidance_Cpt::INSTITUTION );

		$cards[] = array(
			'label' => __( 'Institutions listed', 'classic-edu-suite' ),
			'value' => number_format_i18n( isset( $counts->publish ) ? (int) $counts->publish : 0 ),
			'href'  => admin_url( 'edit.php?post_type=' . CES_Guidance_Cpt::INSTITUTION ),
		);

		return $cards;
	}

	/**
	 * Keep the Classic EDU menu highlighted on guidance screens.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	public static function highlight_parent_menu( $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && array_key_exists( (string) $screen->post_type, self::types() ) ) {
			return 'classic-edu';
		}

		return $parent_file;
	}

	/* ---------------------------------------------------------------------
	 * Metaboxes.
	 * ------------------------------------------------------------------ */

	/**
	 * Register one metabox per guidance type.
	 *
	 * @return void
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'ces-institution-details',
			__( 'Institution details', 'classic-edu-suite' ),
			array( __CLASS__, 'render_institution_box' ),
			CES_Guidance_Cpt::INSTITUTION,
			'normal',
			'high'
		);

		add_meta_box(
			'ces-department-details',
			__( 'Entry requirements', 'classic-edu-suite' ),
			array( __CLASS__, 'render_department_box' ),
			CES_Guidance_Cpt::DEPARTMENT,
			'normal',
			'high'
		);

		add_meta_box(
			'ces-career-details',
			__( 'Career details', 'classic-edu-suite' ),
			array( __CLASS__, 'render_career_box' ),
			CES_Guidance_Cpt::CAREER,
			'normal',
			'high'
		);
	}

	/**
	 * Open a metabox table and print the nonce.
	 *
	 * @return void
	 */
	private static function open_box() {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
	}

	/**
	 * Print one text row.
	 *
	 * @param string $key   Field name.
	 * @param string $label Label.
	 * @param string $value Current value.
	 * @param string $help  Description.
	 * @param string $type  Input type.
	 * @return void
	 */
	private static function text_row( $key, $label, $value, $help = '', $type = 'text' ) {
		echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';

		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Print one textarea row.
	 *
	 * @param string $key   Field name.
	 * @param string $label Label.
	 * @param string $value Current value.
	 * @param string $help  Description.
	 * @return void
	 */
	private static function area_row( $key, $label, $value, $help = '' ) {
		echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="4" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';

		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Institution metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render_institution_box( $post ) {
		self::open_box();

		self::text_row( 'ces_inst_abbr', __( 'Short name', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_ABBR, true ), __( 'For example UNILAG, FUTA, YABATECH.', 'classic-edu-suite' ) );

		$ownership = (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_OWNERSHIP, true );

		echo '<tr><th><label for="ces_inst_ownership">' . esc_html__( 'Ownership', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<select id="ces_inst_ownership" name="ces_inst_ownership">';
		echo '<option value="">' . esc_html__( 'Not set', 'classic-edu-suite' ) . '</option>';

		foreach ( CES_Guidance_Cpt::ownerships() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $ownership, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></td></tr>';

		self::text_row( 'ces_inst_website', __( 'Website', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_WEBSITE, true ), '', 'url' );
		self::text_row( 'ces_inst_cutoff', __( 'Cut-off mark', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_CUTOFF, true ), __( 'The institution’s own cut-off, where it publishes one.', 'classic-edu-suite' ), 'number' );

		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'Tag the institution with every Programme it offers — that is what the School Finder searches.', 'classic-edu-suite' ) . '</p>';
	}

	/**
	 * Department metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render_department_box( $post ) {
		self::open_box();

		self::text_row( 'ces_dept_duration', __( 'Course length (years)', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_DURATION, true ) );
		self::area_row( 'ces_dept_utme', __( 'UTME subjects', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_UTME, true ), __( 'The subject combination, written as a student would read it.', 'classic-edu-suite' ) );
		self::area_row( 'ces_dept_olevel', __( 'O’level requirement', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_OLEVEL, true ) );
		self::area_row( 'ces_dept_direct', __( 'Direct entry', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_DIRECT, true ) );
		self::area_row( 'ces_dept_careers', __( 'Careers it leads to', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_CAREERS, true ), __( 'One per line.', 'classic-edu-suite' ) );

		echo '</tbody></table>';
	}

	/**
	 * Career metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render_career_box( $post ) {
		self::open_box();

		self::text_row( 'ces_career_salary', __( 'Typical pay', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_SALARY, true ), __( 'Free text, for example “₦150,000 – ₦400,000 monthly”.', 'classic-edu-suite' ) );
		self::text_row( 'ces_career_study', __( 'What to study', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_STUDY, true ) );
		self::area_row( 'ces_career_skills', __( 'Skills that matter', 'classic-edu-suite' ), (string) get_post_meta( $post->ID, CES_Guidance_Cpt::META_SKILLS, true ), __( 'One per line.', 'classic-edu-suite' ) );

		echo '</tbody></table>';
	}

	/**
	 * Save whichever metabox was on screen.
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $post || ! array_key_exists( (string) $post->post_type, self::types() ) ) {
			return;
		}

		if ( empty( $_POST[ self::NONCE . '_nonce' ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$text_fields = array(
			'ces_inst_abbr'     => CES_Guidance_Cpt::META_ABBR,
			'ces_dept_duration' => CES_Guidance_Cpt::META_DURATION,
			'ces_career_salary' => CES_Guidance_Cpt::META_SALARY,
			'ces_career_study'  => CES_Guidance_Cpt::META_STUDY,
		);

		foreach ( $text_fields as $field => $meta_key ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		$area_fields = array(
			'ces_dept_utme'     => CES_Guidance_Cpt::META_UTME,
			'ces_dept_olevel'   => CES_Guidance_Cpt::META_OLEVEL,
			'ces_dept_direct'   => CES_Guidance_Cpt::META_DIRECT,
			'ces_dept_careers'  => CES_Guidance_Cpt::META_CAREERS,
			'ces_career_skills' => CES_Guidance_Cpt::META_SKILLS,
		);

		foreach ( $area_fields as $field => $meta_key ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		if ( isset( $_POST['ces_inst_ownership'] ) ) {
			$ownership = sanitize_key( wp_unslash( $_POST['ces_inst_ownership'] ) );

			if ( ! array_key_exists( $ownership, CES_Guidance_Cpt::ownerships() ) ) {
				$ownership = '';
			}

			update_post_meta( $post_id, CES_Guidance_Cpt::META_OWNERSHIP, $ownership );
		}

		if ( isset( $_POST['ces_inst_website'] ) ) {
			update_post_meta( $post_id, CES_Guidance_Cpt::META_WEBSITE, esc_url_raw( wp_unslash( $_POST['ces_inst_website'] ) ) );
		}

		if ( isset( $_POST['ces_inst_cutoff'] ) ) {
			update_post_meta( $post_id, CES_Guidance_Cpt::META_CUTOFF, absint( $_POST['ces_inst_cutoff'] ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * CSV import.
	 * ------------------------------------------------------------------ */

	/**
	 * The columns each import type understands.
	 *
	 * @return array Map of post type => column list.
	 */
	public static function columns() {
		return array(
			CES_Guidance_Cpt::INSTITUTION => array( 'title', 'abbr', 'type', 'state', 'ownership', 'cutoff', 'website', 'programmes', 'summary', 'description' ),
			CES_Guidance_Cpt::DEPARTMENT  => array( 'title', 'programmes', 'duration', 'utme', 'olevel', 'direct_entry', 'careers', 'summary', 'description' ),
			CES_Guidance_Cpt::CAREER      => array( 'title', 'programmes', 'salary', 'skills', 'study', 'summary', 'description' ),
		);
	}

	/**
	 * Guard the importer.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! CES_Helpers::can_manage( CES_Guidance_Cpt::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to import guidance content.', 'classic-edu-suite' ), 403 );
		}
	}

	/**
	 * Render the importer screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report_key = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : '';
		$report     = $report_key ? get_transient( self::REPORT_KEY . $report_key ) : false;

		echo '<div class="wrap ces-wrap">';
		echo '<h1>' . esc_html__( 'Guidance Import', 'classic-edu-suite' ) . '</h1>';
		echo '<p class="ces-sub">' . esc_html__( 'Bulk-load institutions, departments and careers from a spreadsheet. Rows are matched on the title, so uploading a corrected file again updates rather than duplicates.', 'classic-edu-suite' ) . '</p>';

		if ( is_array( $report ) ) {
			$class = empty( $report['errors'] ) ? 'notice notice-success' : 'notice notice-warning';

			echo '<div class="' . esc_attr( $class ) . '"><p><strong>' . esc_html(
				sprintf(
					/* translators: 1: created, 2: updated, 3: skipped. */
					__( 'Import finished: %1$d created, %2$d updated, %3$d skipped.', 'classic-edu-suite' ),
					isset( $report['created'] ) ? (int) $report['created'] : 0,
					isset( $report['updated'] ) ? (int) $report['updated'] : 0,
					isset( $report['skipped'] ) ? (int) $report['skipped'] : 0
				)
			) . '</strong></p>';

			if ( ! empty( $report['errors'] ) ) {
				echo '<ul>';

				foreach ( array_slice( (array) $report['errors'], 0, 30 ) as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}

				echo '</ul>';
			}

			echo '</div>';
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ces_guidance_import' );
		echo '<input type="hidden" name="action" value="ces_guidance_import" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th>' . esc_html__( 'What are you importing?', 'classic-edu-suite' ) . '</th><td><select name="ces_type">';

		foreach ( self::types() as $post_type => $label ) {
			echo '<option value="' . esc_attr( $post_type ) . '">' . esc_html( $label ) . '</option>';
		}

		echo '</select></td></tr>';

		echo '<tr><th>' . esc_html__( 'CSV file', 'classic-edu-suite' ) . '</th><td>';
		echo '<input type="file" name="ces_guidance_file" accept=".csv,.txt,text/csv" required />';
		echo '<p class="description">' . esc_html__( 'First row must be a header. Use a semicolon or a pipe to separate several programmes, careers or skills in one cell.', 'classic-edu-suite' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Import', 'classic-edu-suite' ) );
		echo '</form>';

		// Sample downloads.
		echo '<h2>' . esc_html__( 'Sample files', 'classic-edu-suite' ) . '</h2>';
		echo '<p>';

		foreach ( self::types() as $post_type => $label ) {
			echo '<a class="button" href="' . esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=ces_guidance_sample&ces_type=' . rawurlencode( $post_type ) ),
					'ces_guidance_sample'
				)
			) . '">' . esc_html(
				sprintf(
					/* translators: %s: content type label. */
					__( 'Sample: %s', 'classic-edu-suite' ),
					$label
				)
			) . '</a> ';
		}

		echo '</p>';

		// Column reference.
		echo '<h2>' . esc_html__( 'Columns', 'classic-edu-suite' ) . '</h2>';

		foreach ( self::columns() as $post_type => $columns ) {
			$labels = self::types();

			echo '<p><strong>' . esc_html( $labels[ $post_type ] ) . ':</strong> <code>' . esc_html( implode( ', ', $columns ) ) . '</code></p>';
		}

		echo '</div>';
	}

	/**
	 * Process an uploaded CSV.
	 *
	 * @return void
	 */
	public static function handle_import() {
		self::guard();
		check_admin_referer( 'ces_guidance_import' );

		$post_type = isset( $_POST['ces_type'] ) ? sanitize_key( wp_unslash( $_POST['ces_type'] ) ) : '';

		if ( ! array_key_exists( $post_type, self::types() ) ) {
			self::finish( array( 'errors' => array( __( 'Unknown import type.', 'classic-edu-suite' ) ) ) );
		}

		if ( empty( $_FILES['ces_guidance_file']['tmp_name'] ) ) {
			self::finish( array( 'errors' => array( __( 'No file was uploaded.', 'classic-edu-suite' ) ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tmp  = (string) $_FILES['ces_guidance_file']['tmp_name'];
		$size = isset( $_FILES['ces_guidance_file']['size'] ) ? (int) $_FILES['ces_guidance_file']['size'] : 0;

		if ( $size > self::MAX_BYTES ) {
			self::finish( array( 'errors' => array( __( 'That file is too large. Split it into smaller files.', 'classic-edu-suite' ) ) ) );
		}

		if ( ! is_uploaded_file( $tmp ) ) {
			self::finish( array( 'errors' => array( __( 'That upload could not be read.', 'classic-edu-suite' ) ) ) );
		}

		$handle = fopen( $tmp, 'r' );

		if ( ! $handle ) {
			self::finish( array( 'errors' => array( __( 'That file could not be opened.', 'classic-edu-suite' ) ) ) );
		}

		$header = fgetcsv( $handle, 0, ',' );

		if ( ! is_array( $header ) ) {
			fclose( $handle );
			self::finish( array( 'errors' => array( __( 'The first line could not be read as a header row.', 'classic-edu-suite' ) ) ) );
		}

		// Strip a UTF-8 BOM from the first header cell.
		if ( isset( $header[0] ) ) {
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		}

		$map = array();

		foreach ( $header as $index => $label ) {
			$map[ $index ] = sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( trim( (string) $label ) ) ) );
		}

		if ( ! in_array( 'title', $map, true ) ) {
			fclose( $handle );
			self::finish( array( 'errors' => array( __( 'The header row has no "title" column.', 'classic-edu-suite' ) ) ) );
		}

		$report = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		$line = 1;

		while ( false !== ( $row = fgetcsv( $handle, 0, ',' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			++$line;

			if ( ! is_array( $row ) ) {
				continue;
			}

			$data = array();

			foreach ( $map as $index => $key ) {
				$data[ $key ] = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
			}

			if ( '' === $data['title'] ) {
				++$report['skipped'];
				continue;
			}

			$outcome = self::write_row( $post_type, $data );

			if ( is_wp_error( $outcome ) ) {
				++$report['skipped'];
				$report['errors'][] = sprintf(
					/* translators: 1: line number, 2: error message. */
					__( 'Line %1$d: %2$s', 'classic-edu-suite' ),
					$line,
					$outcome->get_error_message()
				);

				continue;
			}

			if ( 'created' === $outcome ) {
				++$report['created'];
			} else {
				++$report['updated'];
			}
		}

		fclose( $handle );

		self::finish( $report );
	}

	/**
	 * Create or update one row.
	 *
	 * @param string $post_type Post type.
	 * @param array  $data      Row keyed by column.
	 * @return string|WP_Error 'created' or 'updated'.
	 */
	private static function write_row( $post_type, array $data ) {
		$title    = sanitize_text_field( $data['title'] );
		$existing = self::find_by_title( $title, $post_type );

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'post_excerpt' => isset( $data['summary'] ) ? sanitize_textarea_field( $data['summary'] ) : '',
		);

		if ( $existing instanceof WP_Post ) {
			$postarr['ID'] = $existing->ID;
			$post_id       = wp_update_post( $postarr, true );
			$outcome       = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$outcome = 'created';
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		// Programmes, shared by all three types.
		if ( ! empty( $data['programmes'] ) ) {
			self::assign_terms( $post_id, CES_Guidance_Cpt::TAX_PROGRAMME, $data['programmes'] );
		}

		if ( CES_Guidance_Cpt::INSTITUTION === $post_type ) {
			if ( ! empty( $data['type'] ) ) {
				self::assign_terms( $post_id, CES_Guidance_Cpt::TAX_INST_TYPE, $data['type'] );
			}

			if ( ! empty( $data['state'] ) ) {
				self::assign_terms( $post_id, CES_Guidance_Cpt::TAX_STATE, $data['state'] );
			}

			$ownership = isset( $data['ownership'] ) ? sanitize_key( $data['ownership'] ) : '';

			update_post_meta(
				$post_id,
				CES_Guidance_Cpt::META_OWNERSHIP,
				array_key_exists( $ownership, CES_Guidance_Cpt::ownerships() ) ? $ownership : ''
			);

			update_post_meta( $post_id, CES_Guidance_Cpt::META_ABBR, sanitize_text_field( isset( $data['abbr'] ) ? $data['abbr'] : '' ) );
			update_post_meta( $post_id, CES_Guidance_Cpt::META_WEBSITE, esc_url_raw( isset( $data['website'] ) ? $data['website'] : '' ) );
			update_post_meta( $post_id, CES_Guidance_Cpt::META_CUTOFF, absint( isset( $data['cutoff'] ) ? $data['cutoff'] : 0 ) );
		}

		if ( CES_Guidance_Cpt::DEPARTMENT === $post_type ) {
			update_post_meta( $post_id, CES_Guidance_Cpt::META_DURATION, sanitize_text_field( isset( $data['duration'] ) ? $data['duration'] : '' ) );
			update_post_meta( $post_id, CES_Guidance_Cpt::META_UTME, sanitize_textarea_field( isset( $data['utme'] ) ? $data['utme'] : '' ) );
			update_post_meta( $post_id, CES_Guidance_Cpt::META_OLEVEL, sanitize_textarea_field( isset( $data['olevel'] ) ? $data['olevel'] : '' ) );
			update_post_meta( $post_id, CES_Guidance_Cpt::META_DIRECT, sanitize_textarea_field( isset( $data['direct_entry'] ) ? $data['direct_entry'] : '' ) );
			update_post_meta( $post_id, CES_Guidance_Cpt::META_CAREERS, self::to_lines( isset( $data['careers'] ) ? $data['careers'] : '' ) );
		}

		if ( CES_Guidance_Cpt::CAREER === $post_type ) {
			update_post_meta( $post_id, CES_Guidance_Cpt::META_SALARY, sanitize_text_field( isset( $data['salary'] ) ? $data['salary'] : '' ) );
			update_post_meta( $post_id, CES_Guidance_Cpt::META_STUDY, sanitize_text_field( isset( $data['study'] ) ? $data['study'] : '' ) );
			update_post_meta( $post_id, CES_Guidance_Cpt::META_SKILLS, self::to_lines( isset( $data['skills'] ) ? $data['skills'] : '' ) );
		}

		return $outcome;
	}

	/**
	 * Find an existing post of a type by its exact title.
	 *
	 * `get_page_by_title()` is deprecated, so this uses the `title` query
	 * parameter instead, which matches on the post title exactly.
	 *
	 * @param string $title     Post title.
	 * @param string $post_type Post type.
	 * @return WP_Post|null
	 */
	private static function find_by_title( $title, $post_type ) {
		$found = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'title'            => $title,
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		return ( ! empty( $found ) && $found[0] instanceof WP_Post ) ? $found[0] : null;
	}

	/**
	 * Turn a separated cell into newline-separated text.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	private static function to_lines( $value ) {
		$parts = preg_split( '/\s*[;|]\s*/', (string) $value );

		if ( ! is_array( $parts ) ) {
			return sanitize_textarea_field( (string) $value );
		}

		$parts = array_filter( array_map( 'trim', $parts ) );

		return sanitize_textarea_field( implode( "\n", $parts ) );
	}

	/**
	 * Assign terms to a post, creating any that do not exist.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $value    Separated list of term names.
	 * @return void
	 */
	private static function assign_terms( $post_id, $taxonomy, $value ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$parts = preg_split( '/\s*[;|]\s*/', (string) $value );

		if ( ! is_array( $parts ) ) {
			return;
		}

		$names = array();

		foreach ( $parts as $name ) {
			$name = sanitize_text_field( trim( $name ) );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		if ( empty( $names ) ) {
			return;
		}

		wp_set_object_terms( $post_id, $names, $taxonomy, false );
	}

	/**
	 * Store the report and return to the screen.
	 *
	 * @param array $report Report array.
	 * @return void
	 */
	private static function finish( array $report ) {
		$key = wp_generate_password( 10, false, false );

		set_transient( self::REPORT_KEY . $key, $report, 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'report', $key, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Stream a sample CSV for one type.
	 *
	 * @return void
	 */
	public static function handle_sample() {
		self::guard();
		check_admin_referer( 'ces_guidance_sample' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$post_type = isset( $_GET['ces_type'] ) ? sanitize_key( wp_unslash( $_GET['ces_type'] ) ) : CES_Guidance_Cpt::INSTITUTION;
		$columns   = self::columns();

		if ( ! isset( $columns[ $post_type ] ) ) {
			$post_type = CES_Guidance_Cpt::INSTITUTION;
		}

		$samples = array(
			CES_Guidance_Cpt::INSTITUTION => array(
				array(
					'University of Lagos',
					'UNILAG',
					'University',
					'Lagos',
					'federal',
					'200',
					'https://unilag.edu.ng',
					'Mechanical Engineering;Medicine and Surgery;Accounting',
					'A federal university in Akoka, Lagos.',
					'',
				),
			),
			CES_Guidance_Cpt::DEPARTMENT  => array(
				array(
					'Mechanical Engineering',
					'Mechanical Engineering',
					'5',
					'Mathematics, Physics and Chemistry',
					'Five credits including English, Mathematics, Physics and Chemistry',
					'A level passes in Mathematics and Physics',
					'Design engineer;Maintenance engineer;Energy analyst',
					'Machines, materials and energy, from design to maintenance.',
					'',
				),
			),
			CES_Guidance_Cpt::CAREER      => array(
				array(
					'Software Engineer',
					'Computer Science;Software Engineering',
					'₦250,000 – ₦1,200,000 monthly',
					'Problem solving;One programming language;Reading other people’s code',
					'Computer Science or Software Engineering',
					'Builds and maintains the software other people rely on.',
					'',
				),
			),
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ces-' . str_replace( 'ces_', '', $post_type ) . '-sample.csv"' );

		$handle = fopen( 'php://output', 'w' );

		if ( ! $handle ) {
			exit;
		}

		fputcsv( $handle, $columns[ $post_type ] );

		foreach ( $samples[ $post_type ] as $row ) {
			fputcsv( $handle, $row );
		}

		fclose( $handle );
		exit;
	}
}
