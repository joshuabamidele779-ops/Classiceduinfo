<?php
/**
 * Bulk import and export for the question bank.
 *
 * Nobody is going to hand-type five thousand past questions, so this is the
 * screen that actually decides whether the question bank gets filled. It
 * validates every row before writing anything: by default a single bad row
 * aborts the whole file and the screen lists what is wrong with each one, so
 * an import never ends up half done without saying so.
 *
 * @package ClassicEduSuite\Cbt
 */

defined( 'ABSPATH' ) || exit;

/**
 * CSV and JSON import/export.
 */
class CES_Cbt_Importer {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'ces-cbt-import';

	/**
	 * Transient key prefix for the last import report.
	 */
	const REPORT_KEY = 'ces_cbt_import_report_';

	/**
	 * Hard cap on rows per import, to stay inside shared-hosting limits.
	 */
	const MAX_ROWS = 5000;

	/**
	 * Cache of resolved term IDs, keyed `taxonomy|name`.
	 *
	 * @var array
	 */
	private static $term_cache = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_ces_cbt_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_ces_cbt_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_ces_cbt_sample', array( __CLASS__, 'handle_sample' ) );
	}

	/**
	 * The canonical CSV columns, in the order the sample file uses.
	 *
	 * @return string[]
	 */
	public static function columns() {
		return array(
			'id',
			'question',
			'type',
			'option_a',
			'option_b',
			'option_c',
			'option_d',
			'option_e',
			'correct',
			'explanation',
			'subject',
			'exam_type',
			'topic',
			'year',
			'difficulty',
			'marks',
			'image',
			'status',
		);
	}

	/**
	 * Option column letters recognised on import, `option_a` to `option_j`.
	 *
	 * @return string[]
	 */
	private static function option_letters() {
		return array_slice( range( 'a', 'j' ), 0, CES_Cbt_Question::MAX_OPTIONS );
	}

	/**
	 * Capability guard shared by every handler.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! CES_Helpers::can_manage( CES_Cbt_Cpt::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the question bank.', 'classic-edu-suite' ), 403 );
		}
	}

	/* ---------------------------------------------------------------------
	 * The screen.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the import / export screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		self::guard();

		$report_key = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report     = $report_key ? get_transient( self::REPORT_KEY . $report_key ) : false;

		echo '<div class="wrap ces-wrap ces-cbt-admin">';
		echo '<h1>' . esc_html__( 'CBT Import / Export', 'classic-edu-suite' ) . '</h1>';

		if ( is_array( $report ) ) {
			self::render_report( $report );
		}

		self::render_import_form();
		self::render_export_form();
		self::render_format_docs();

		echo '</div>';
	}

	/**
	 * Render the outcome of the last import.
	 *
	 * @param array $report Report array.
	 * @return void
	 */
	private static function render_report( array $report ) {
		$imported = isset( $report['imported'] ) ? (int) $report['imported'] : 0;
		$updated  = isset( $report['updated'] ) ? (int) $report['updated'] : 0;
		$skipped  = isset( $report['skipped'] ) ? (int) $report['skipped'] : 0;
		$errors   = isset( $report['errors'] ) && is_array( $report['errors'] ) ? $report['errors'] : array();
		$aborted  = ! empty( $report['aborted'] );

		$class = $aborted ? 'notice notice-error' : ( empty( $errors ) ? 'notice notice-success' : 'notice notice-warning' );

		echo '<div class="' . esc_attr( $class ) . '"><p><strong>';

		if ( $aborted ) {
			echo esc_html__( 'Nothing was imported. Fix the rows listed below and upload the file again.', 'classic-edu-suite' );
		} else {
			echo esc_html(
				sprintf(
					/* translators: 1: created count, 2: updated count, 3: skipped count. */
					__( 'Import finished: %1$d created, %2$d updated, %3$d skipped.', 'classic-edu-suite' ),
					$imported,
					$updated,
					$skipped
				)
			);
		}

		echo '</strong></p>';

		if ( ! empty( $errors ) ) {
			echo '<p>' . esc_html__( 'Row problems:', 'classic-edu-suite' ) . '</p>';
			echo '<ul class="ces-import-errors">';

			$shown = 0;

			foreach ( $errors as $error ) {
				if ( $shown >= 200 ) {
					echo '<li>' . esc_html__( '… more rows were affected; only the first 200 are listed.', 'classic-edu-suite' ) . '</li>';
					break;
				}

				$line     = isset( $error['row'] ) ? (int) $error['row'] : 0;
				$messages = isset( $error['messages'] ) ? (array) $error['messages'] : array();

				echo '<li><strong>' . esc_html(
					sprintf(
						/* translators: %d: CSV line number. */
						__( 'Line %d', 'classic-edu-suite' ),
						$line
					)
				) . ':</strong> ' . esc_html( implode( ' ', array_map( 'strval', $messages ) ) ) . '</li>';

				++$shown;
			}

			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * Render the upload form.
	 *
	 * @return void
	 */
	private static function render_import_form() {
		?>
		<h2><?php esc_html_e( 'Import questions', 'classic-edu-suite' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'ces_cbt_import' ); ?>
			<input type="hidden" name="action" value="ces_cbt_import" />

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="ces-cbt-file"><?php esc_html_e( 'CSV or JSON file', 'classic-edu-suite' ); ?></label></th>
						<td>
							<input type="file" id="ces-cbt-file" name="ces_cbt_file" accept=".csv,.json,.txt,text/csv,application/json" required />
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: maximum rows per import. */
										__( 'Up to %d rows per file. Split larger banks into several files.', 'classic-edu-suite' ),
										self::MAX_ROWS
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Bad rows', 'classic-edu-suite' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="skip_invalid" value="1" />
								<?php esc_html_e( 'Import the valid rows and skip the bad ones', 'classic-edu-suite' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Leave this off and a single bad row aborts the whole file, so you never get a half-filled bank.', 'classic-edu-suite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'classic-edu-suite' ); ?></th>
						<td>
							<select name="default_status">
								<option value="publish"><?php esc_html_e( 'Publish imported questions', 'classic-edu-suite' ); ?></option>
								<option value="draft"><?php esc_html_e( 'Save as drafts for review', 'classic-edu-suite' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Only questions with the publish status are ever served in an exam.', 'classic-edu-suite' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Import questions', 'classic-edu-suite' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the export form.
	 *
	 * @return void
	 */
	private static function render_export_form() {
		?>
		<hr />
		<h2><?php esc_html_e( 'Export questions', 'classic-edu-suite' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ces_cbt_export' ); ?>
			<input type="hidden" name="action" value="ces_cbt_export" />

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Format', 'classic-edu-suite' ); ?></th>
						<td>
							<label><input type="radio" name="format" value="csv" checked /> <?php esc_html_e( 'CSV', 'classic-edu-suite' ); ?></label>
							&nbsp;&nbsp;
							<label><input type="radio" name="format" value="json" /> <?php esc_html_e( 'JSON', 'classic-edu-suite' ); ?></label>
						</td>
					</tr>

					<?php foreach ( CES_Cbt_Cpt::taxonomies() as $taxonomy => $label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<?php
								wp_dropdown_categories(
									array(
										'show_option_all' => __( 'All', 'classic-edu-suite' ),
										'taxonomy'        => $taxonomy,
										'name'            => 'tax_' . $taxonomy,
										'value_field'     => 'term_id',
										'hierarchical'    => is_taxonomy_hierarchical( $taxonomy ),
										'hide_empty'      => false,
										'orderby'         => 'name',
									)
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button( __( 'Download export', 'classic-edu-suite' ), 'secondary' ); ?>
		</form>

		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ces_cbt_sample' ), 'ces_cbt_sample' ) ); ?>">
				<?php esc_html_e( 'Download sample CSV', 'classic-edu-suite' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render the column documentation.
	 *
	 * @return void
	 */
	private static function render_format_docs() {
		$rows = array(
			'id'          => __( 'Optional. An existing question ID to update instead of creating a new one.', 'classic-edu-suite' ),
			'question'    => __( 'Required. The question text. Basic HTML is allowed, including <img>, <sub>, <sup> and tables. For maths, write TeX between \\( and \\).', 'classic-edu-suite' ),
			'type'        => __( 'single (default), truefalse, or multi.', 'classic-edu-suite' ),
			'option_a…j'  => __( 'Option text. At least two are required, except for truefalse which generates its own True/False options.', 'classic-edu-suite' ),
			'correct'     => __( 'Required. The option letter, e.g. A. For multi, separate letters with a pipe: A|C. For truefalse use A/True or B/False. A 1-based number also works.', 'classic-edu-suite' ),
			'explanation' => __( 'Optional worked solution, shown on the result screen.', 'classic-edu-suite' ),
			'subject'     => __( 'Term name, e.g. Mathematics. Created if it does not exist.', 'classic-edu-suite' ),
			'exam_type'   => __( 'Term name, e.g. JAMB, WAEC, NECO, Post-UTME. Created if it does not exist.', 'classic-edu-suite' ),
			'topic'       => __( 'Term name. Use "Parent > Child" for a sub-topic. Created if it does not exist.', 'classic-edu-suite' ),
			'year'        => __( 'Term name, e.g. 2019.', 'classic-edu-suite' ),
			'difficulty'  => __( 'easy, medium (default) or hard.', 'classic-edu-suite' ),
			'marks'       => __( 'Defaults to 1. Scores are a percentage of the paper total, so mixed marks are fine.', 'classic-edu-suite' ),
			'image'       => __( 'Optional diagram: a media library attachment ID, or the URL of a file already in the media library. Remote URLs are not downloaded.', 'classic-edu-suite' ),
			'status'      => __( 'publish or draft. Overrides the status chosen above.', 'classic-edu-suite' ),
		);
		?>
		<hr />
		<h2><?php esc_html_e( 'CSV column format', 'classic-edu-suite' ); ?></h2>

		<p class="description">
			<?php esc_html_e( 'The first line must be a header row. Column order does not matter and names are case-insensitive; unknown columns are ignored. Comma, semicolon and tab separated files are all detected automatically.', 'classic-edu-suite' ); ?>
		</p>

		<table class="widefat striped ces-cbt-format">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Column', 'classic-edu-suite' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Meaning', 'classic-edu-suite' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $column => $meaning ) : ?>
					<tr>
						<td><code><?php echo esc_html( $column ); ?></code></td>
						<td><?php echo esc_html( $meaning ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Example', 'classic-edu-suite' ); ?></h3>
		<pre class="ces-cbt-sample">question,type,option_a,option_b,option_c,option_d,correct,explanation,subject,exam_type,topic,year,difficulty,marks
"If 2x + 3 = 11, find x.",single,2,4,6,8,B,"2x = 11 - 3 = 8, so x = 4.",Mathematics,JAMB,Algebra &gt; Linear Equations,2019,easy,1
"Water boils at 100 degrees Celsius at sea level.",truefalse,,,,,A,"At standard atmospheric pressure.",Chemistry,WAEC,States of Matter,2021,easy,1
"Which of these are noble gases?",multi,Helium,Nitrogen,Argon,Oxygen,A|C,"Helium and argon are in Group 18.",Chemistry,JAMB,Periodic Table,2020,medium,2</pre>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Import.
	 * ------------------------------------------------------------------ */

	/**
	 * Handle the upload.
	 *
	 * @return void
	 */
	public static function handle_import() {
		self::guard();
		check_admin_referer( 'ces_cbt_import' );

		$report = array(
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'aborted'  => false,
		);

		$file = isset( $_FILES['ces_cbt_file'] ) ? $_FILES['ces_cbt_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! $file || ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$report['aborted']  = true;
			$report['errors'][] = array(
				'row'      => 0,
				'messages' => array( __( 'No file was received. It may be larger than the server upload limit.', 'classic-edu-suite' ) ),
			);

			self::finish( $report );
		}

		$name      = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents || '' === trim( (string) $contents ) ) {
			$report['aborted']  = true;
			$report['errors'][] = array(
				'row'      => 0,
				'messages' => array( __( 'The file is empty or could not be read.', 'classic-edu-suite' ) ),
			);

			self::finish( $report );
		}

		// Strip a UTF-8 byte order mark, which Excel adds and which would
		// otherwise corrupt the first header name.
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $contents );

		if ( 'json' === $extension || '[' === substr( ltrim( $contents ), 0, 1 ) || '{' === substr( ltrim( $contents ), 0, 1 ) ) {
			$parsed = self::parse_json( $contents );
		} else {
			$parsed = self::parse_csv( $contents );
		}

		if ( is_wp_error( $parsed ) ) {
			$report['aborted']  = true;
			$report['errors'][] = array(
				'row'      => 0,
				'messages' => array( $parsed->get_error_message() ),
			);

			self::finish( $report );
		}

		if ( count( $parsed ) > self::MAX_ROWS ) {
			$report['aborted']  = true;
			$report['errors'][] = array(
				'row'      => 0,
				'messages' => array(
					sprintf(
						/* translators: 1: rows found, 2: maximum rows. */
						__( 'The file has %1$d rows, which is more than the %2$d row limit. Please split it.', 'classic-edu-suite' ),
						count( $parsed ),
						self::MAX_ROWS
					),
				),
			);

			self::finish( $report );
		}

		$default_status = isset( $_POST['default_status'] ) && 'draft' === $_POST['default_status'] ? 'draft' : 'publish';
		$skip_invalid   = ! empty( $_POST['skip_invalid'] );

		// Pass one: validate everything. Nothing is written yet.
		$valid = array();

		foreach ( $parsed as $row ) {
			$prepared = self::prepare_row( $row['data'] );

			if ( ! empty( $prepared['errors'] ) ) {
				$report['errors'][] = array(
					'row'      => (int) $row['line'],
					'messages' => $prepared['errors'],
				);

				continue;
			}

			$valid[] = array(
				'line'   => (int) $row['line'],
				'fields' => $prepared['fields'],
			);
		}

		if ( ! empty( $report['errors'] ) && ! $skip_invalid ) {
			$report['aborted'] = true;
			$report['skipped'] = count( $parsed );

			self::finish( $report );
		}

		$report['skipped'] = count( $report['errors'] );

		// Pass two: write.
		foreach ( $valid as $item ) {
			$outcome = self::write_question( $item['fields'], $default_status );

			if ( is_wp_error( $outcome ) ) {
				++$report['skipped'];

				$report['errors'][] = array(
					'row'      => (int) $item['line'],
					'messages' => array( $outcome->get_error_message() ),
				);

				continue;
			}

			if ( 'updated' === $outcome ) {
				++$report['updated'];
			} else {
				++$report['imported'];
			}
		}

		self::finish( $report );
	}

	/**
	 * Store the report and redirect back to the screen.
	 *
	 * @param array $report Report array.
	 * @return void
	 */
	private static function finish( array $report ) {
		$key = wp_generate_password( 12, false, false );

		set_transient( self::REPORT_KEY . $key, $report, 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE,
					'report' => $key,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Parse a CSV payload into header-keyed rows.
	 *
	 * @param string $contents Raw file contents.
	 * @return array|WP_Error List of `array( 'line' => int, 'data' => array )`.
	 */
	private static function parse_csv( $contents ) {
		$delimiter = self::detect_delimiter( $contents );

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return new WP_Error( 'ces_cbt_csv_open', __( 'Could not open the file for reading.', 'classic-edu-suite' ) );
		}

		fwrite( $handle, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );

		$header = fgetcsv( $handle, 0, $delimiter );

		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			return new WP_Error( 'ces_cbt_csv_header', __( 'The first line could not be read as a header row.', 'classic-edu-suite' ) );
		}

		$map = array();

		foreach ( $header as $index => $label ) {
			$key = strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $label ), '_' ) );

			if ( '' !== $key ) {
				$map[ $index ] = $key;
			}
		}

		if ( ! in_array( 'question', $map, true ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			return new WP_Error(
				'ces_cbt_csv_header',
				__( 'The header row has no "question" column. Download the sample CSV to see the expected columns.', 'classic-edu-suite' )
			);
		}

		$rows = array();
		$line = 1;

		while ( true ) {
			$values = fgetcsv( $handle, 0, $delimiter );

			if ( false === $values || null === $values ) {
				break;
			}

			++$line;

			// Skip blank lines.
			if ( 1 === count( $values ) && ( null === $values[0] || '' === trim( (string) $values[0] ) ) ) {
				continue;
			}

			$data = array();

			foreach ( $map as $index => $key ) {
				$data[ $key ] = isset( $values[ $index ] ) ? (string) $values[ $index ] : '';
			}

			$rows[] = array(
				'line' => $line,
				'data' => $data,
			);

			if ( count( $rows ) > self::MAX_ROWS ) {
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * Guess the delimiter from the header line.
	 *
	 * @param string $contents Raw file contents.
	 * @return string
	 */
	private static function detect_delimiter( $contents ) {
		$first = strtok( $contents, "\r\n" );
		$first = ( false === $first ) ? '' : $first;

		$counts = array(
			','  => substr_count( $first, ',' ),
			';'  => substr_count( $first, ';' ),
			"\t" => substr_count( $first, "\t" ),
			'|'  => substr_count( $first, '|' ),
		);

		arsort( $counts );

		$best = key( $counts );

		return ( $counts[ $best ] > 0 ) ? (string) $best : ',';
	}

	/**
	 * Parse a JSON payload into rows.
	 *
	 * Accepts a bare array of questions, or `{ "questions": [ … ] }`.
	 *
	 * @param string $contents Raw file contents.
	 * @return array|WP_Error
	 */
	private static function parse_json( $contents ) {
		$decoded = json_decode( $contents, true );

		if ( null === $decoded ) {
			return new WP_Error( 'ces_cbt_json', __( 'That file is not valid JSON.', 'classic-edu-suite' ) );
		}

		if ( isset( $decoded['questions'] ) && is_array( $decoded['questions'] ) ) {
			$decoded = $decoded['questions'];
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'ces_cbt_json', __( 'The JSON file must contain a list of questions.', 'classic-edu-suite' ) );
		}

		$rows = array();
		$line = 0;

		foreach ( $decoded as $item ) {
			++$line;

			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = array(
				'line' => $line,
				'data' => $item,
			);

			if ( count( $rows ) > self::MAX_ROWS ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Validate and normalize one row.
	 *
	 * @param array $data Raw row, keyed by column name.
	 * @return array `array( 'fields' => array, 'errors' => array )`.
	 */
	private static function prepare_row( array $data ) {
		$get = static function ( $key, $default = '' ) use ( $data ) {
			return array_key_exists( $key, $data ) ? $data[ $key ] : $default;
		};

		$body = (string) $get( 'question', $get( 'body', '' ) );
		$type = CES_Cbt_Question::sanitize_type( (string) $get( 'type', CES_Cbt_Question::TYPE_SINGLE ) );

		// Options: either option_a…option_j columns, or a JSON `options` array.
		$options = array();

		$raw_options = $get( 'options', null );

		if ( is_array( $raw_options ) ) {
			foreach ( $raw_options as $item ) {
				$options[] = is_array( $item ) ? $item : array( 'text' => (string) $item );
			}
		} else {
			foreach ( self::option_letters() as $letter ) {
				$value = (string) $get( 'option_' . $letter, '' );

				if ( '' === trim( $value ) ) {
					continue;
				}

				$options[] = array( 'text' => $value );
			}
		}

		if ( CES_Cbt_Question::TYPE_TRUEFALSE === $type ) {
			$options = CES_Cbt_Question::truefalse_options();
		}

		$raw_correct = $get( 'correct', $get( 'answer', '' ) );

		if ( is_array( $raw_correct ) ) {
			$correct_input = $raw_correct;
		} else {
			$correct_input = preg_split( '/[|,;\/\s]+/', (string) $raw_correct );
		}

		// Words are friendlier than letters in a spreadsheet, so accept both.
		$correct_input = array_map(
			static function ( $value ) {
				$value = strtolower( trim( (string) $value ) );

				if ( in_array( $value, array( 'true', 'yes', 't' ), true ) ) {
					return 'A';
				}

				if ( in_array( $value, array( 'false', 'no', 'f' ), true ) ) {
					return 'B';
				}

				return $value;
			},
			(array) $correct_input
		);

		$clean_options = CES_Cbt_Question::sanitize_options( $options );
		$correct       = CES_Cbt_Question::normalize_answer( $correct_input, wp_list_pluck( $clean_options, 'key' ) );

		$fields = array(
			'id'          => absint( $get( 'id', 0 ) ),
			'title'       => self::make_title( $body ),
			'body'        => $body,
			'type'        => $type,
			'options'     => $clean_options,
			'correct'     => $correct,
			'explanation' => (string) $get( 'explanation', '' ),
			'difficulty'  => CES_Cbt_Question::sanitize_difficulty( (string) $get( 'difficulty', 'medium' ) ),
			'marks'       => (float) $get( 'marks', 1 ),
			'image'       => self::resolve_attachment( $get( 'image', $get( 'image_url', '' ) ) ),
			'status'      => ( 'draft' === strtolower( trim( (string) $get( 'status', '' ) ) ) ) ? 'draft' : '',
			'terms'       => array(
				CES_Cbt_Cpt::TAX_SUBJECT   => (string) $get( 'subject', '' ),
				CES_Cbt_Cpt::TAX_EXAM_TYPE => (string) $get( 'exam_type', $get( 'examtype', '' ) ),
				CES_Cbt_Cpt::TAX_TOPIC     => (string) $get( 'topic', '' ),
				CES_Cbt_Cpt::TAX_YEAR      => (string) $get( 'year', '' ),
			),
		);

		$errors = CES_Cbt_Question::validate( $fields );

		if ( $fields['id'] ) {
			$existing = get_post( $fields['id'] );

			if ( ! $existing || CES_Cbt_Cpt::QUESTION !== $existing->post_type ) {
				$errors[] = sprintf(
					/* translators: %d: question ID from the file. */
					__( 'ID %d is not an existing question.', 'classic-edu-suite' ),
					$fields['id']
				);
			}
		}

		return array(
			'fields' => $fields,
			'errors' => $errors,
		);
	}

	/**
	 * Build a readable post title from the question body.
	 *
	 * @param string $body Question body.
	 * @return string
	 */
	private static function make_title( $body ) {
		$text = trim( wp_strip_all_tags( (string) $body ) );
		$text = preg_replace( '/\s+/', ' ', $text );

		if ( '' === $text ) {
			return __( 'Untitled question', 'classic-edu-suite' );
		}

		return wp_html_excerpt( $text, 120, '…' );
	}

	/**
	 * Resolve an attachment reference to an ID.
	 *
	 * Accepts an attachment ID or the URL of a file already in the media
	 * library. Remote files are deliberately not downloaded: sideloading
	 * thousands of images during an import would time out on shared hosting.
	 *
	 * @param mixed $value Raw column value.
	 * @return int
	 */
	private static function resolve_attachment( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( ctype_digit( $value ) ) {
			$id = absint( $value );

			return ( 'attachment' === get_post_type( $id ) ) ? $id : 0;
		}

		if ( 0 === strpos( $value, 'http' ) ) {
			$id = attachment_url_to_postid( $value );

			return $id ? (int) $id : 0;
		}

		return 0;
	}

	/**
	 * Create or update one question.
	 *
	 * @param array  $fields         Prepared fields from `prepare_row()`.
	 * @param string $default_status Status for new questions.
	 * @return string|WP_Error `created`, `updated`, or an error.
	 */
	private static function write_question( array $fields, $default_status ) {
		$status = $fields['status'] ? $fields['status'] : $default_status;

		$postarr = array(
			'post_type'    => CES_Cbt_Cpt::QUESTION,
			'post_title'   => $fields['title'],
			'post_content' => CES_Cbt_Question::sanitize_rich_text( $fields['body'] ),
			'post_status'  => $status,
		);

		if ( $fields['id'] ) {
			$postarr['ID'] = $fields['id'];
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

		if ( ! $post_id ) {
			return new WP_Error( 'ces_cbt_write_failed', __( 'The question could not be saved.', 'classic-edu-suite' ) );
		}

		CES_Cbt_Question::save_meta( $post_id, $fields );

		foreach ( $fields['terms'] as $taxonomy => $names ) {
			$names = trim( (string) $names );

			if ( '' === $names ) {
				continue;
			}

			$term_ids = array();

			foreach ( explode( '|', $names ) as $name ) {
				$term_id = self::resolve_term( $taxonomy, $name );

				if ( $term_id ) {
					$term_ids[] = $term_id;
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}

		return $outcome;
	}

	/**
	 * Find, or create, a term by name.
	 *
	 * For the hierarchical topic taxonomy, "Parent > Child" creates both.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $name     Term name, possibly a `Parent > Child` path.
	 * @return int Term ID, or 0.
	 */
	private static function resolve_term( $taxonomy, $name ) {
		$name = trim( (string) $name );

		if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$cache_key = $taxonomy . '|' . strtolower( $name );

		if ( isset( self::$term_cache[ $cache_key ] ) ) {
			return self::$term_cache[ $cache_key ];
		}

		$parts  = is_taxonomy_hierarchical( $taxonomy ) ? array_map( 'trim', explode( '>', $name ) ) : array( $name );
		$parent = 0;
		$leaf   = 0;

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$existing = term_exists( $part, $taxonomy, $parent ? $parent : null );

			if ( is_array( $existing ) && ! empty( $existing['term_id'] ) ) {
				$leaf = (int) $existing['term_id'];
			} else {
				$created = wp_insert_term( $part, $taxonomy, $parent ? array( 'parent' => $parent ) : array() );

				if ( is_wp_error( $created ) ) {
					// A race or a slug clash: fall back to whatever now exists.
					$existing = term_exists( $part, $taxonomy );
					$leaf     = ( is_array( $existing ) && ! empty( $existing['term_id'] ) ) ? (int) $existing['term_id'] : 0;
				} else {
					$leaf = isset( $created['term_id'] ) ? (int) $created['term_id'] : 0;
				}
			}

			if ( ! $leaf ) {
				break;
			}

			$parent = $leaf;
		}

		self::$term_cache[ $cache_key ] = $leaf;

		return $leaf;
	}

	/* ---------------------------------------------------------------------
	 * Export.
	 * ------------------------------------------------------------------ */

	/**
	 * Stream the question bank as CSV or JSON.
	 *
	 * @return void
	 */
	public static function handle_export() {
		self::guard();
		check_admin_referer( 'ces_cbt_export' );

		$format = ( isset( $_POST['format'] ) && 'json' === $_POST['format'] ) ? 'json' : 'csv';

		$filters = array();

		foreach ( array_keys( CES_Cbt_Cpt::taxonomies() ) as $taxonomy ) {
			$key     = 'tax_' . $taxonomy;
			$term_id = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;

			if ( $term_id ) {
				$filters[ $taxonomy ] = array( $term_id );
			}
		}

		$ids = CES_Cbt_Question::query_ids( $filters, '', -1, false );

		nocache_headers();

		$stamp = gmdate( 'Y-m-d' );

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="ces-questions-' . $stamp . '.json"' );

			$out = array();

			foreach ( $ids as $id ) {
				$row = self::export_row( $id );

				if ( $row ) {
					$out[] = $row;
				}
			}

			echo wp_json_encode( array( 'questions' => $out ) );
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ces-questions-' . $stamp . '.csv"' );

		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			exit;
		}

		// A BOM so Excel opens UTF-8 correctly.
		echo "\xEF\xBB\xBF";

		$columns = self::columns();

		fputcsv( $handle, $columns );

		foreach ( $ids as $id ) {
			$row = self::export_row( $id );

			if ( ! $row ) {
				continue;
			}

			$line = array();

			foreach ( $columns as $column ) {
				$value = isset( $row[ $column ] ) ? $row[ $column ] : '';

				if ( is_array( $value ) ) {
					$value = implode( '|', $value );
				}

				$line[] = self::csv_safe( (string) $value );
			}

			fputcsv( $handle, $line );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Build one export row.
	 *
	 * @param int $post_id Question post ID.
	 * @return array|null
	 */
	private static function export_row( $post_id ) {
		$question = CES_Cbt_Question::get( $post_id );

		if ( ! $question ) {
			return null;
		}

		$row = array(
			'id'          => $question['id'],
			'question'    => $question['body'],
			'type'        => $question['type'],
			'correct'     => implode( '|', $question['correct'] ),
			'explanation' => $question['explanation'],
			'subject'     => CES_Cbt_Cpt::term_names( $post_id, CES_Cbt_Cpt::TAX_SUBJECT ),
			'exam_type'   => CES_Cbt_Cpt::term_names( $post_id, CES_Cbt_Cpt::TAX_EXAM_TYPE ),
			'topic'       => CES_Cbt_Cpt::term_names( $post_id, CES_Cbt_Cpt::TAX_TOPIC ),
			'year'        => CES_Cbt_Cpt::term_names( $post_id, CES_Cbt_Cpt::TAX_YEAR ),
			'difficulty'  => $question['difficulty'],
			'marks'       => $question['marks'],
			'image'       => $question['image'] ? $question['image'] : '',
			'status'      => $question['status'],
		);

		$index = 0;

		foreach ( self::option_letters() as $letter ) {
			$row[ 'option_' . $letter ] = isset( $question['options'][ $index ]['text'] ) ? $question['options'][ $index ]['text'] : '';
			++$index;
		}

		return $row;
	}

	/**
	 * Neutralise spreadsheet formula injection in an exported cell.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	private static function csv_safe( $value ) {
		if ( '' === $value ) {
			return $value;
		}

		if ( in_array( substr( $value, 0, 1 ), array( '=', '+', '-', '@' ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Stream a small sample CSV.
	 *
	 * @return void
	 */
	public static function handle_sample() {
		self::guard();
		check_admin_referer( 'ces_cbt_sample' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ces-questions-sample.csv"' );

		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			exit;
		}

		echo "\xEF\xBB\xBF";

		$columns = array(
			'question',
			'type',
			'option_a',
			'option_b',
			'option_c',
			'option_d',
			'correct',
			'explanation',
			'subject',
			'exam_type',
			'topic',
			'year',
			'difficulty',
			'marks',
		);

		fputcsv( $handle, $columns );

		$samples = array(
			array(
				'If 2x + 3 = 11, find x.',
				'single',
				'2',
				'4',
				'6',
				'8',
				'B',
				'2x = 11 - 3 = 8, so x = 4.',
				'Mathematics',
				'JAMB',
				'Algebra > Linear Equations',
				'2019',
				'easy',
				'1',
			),
			array(
				'Water boils at 100 degrees Celsius at sea level.',
				'truefalse',
				'',
				'',
				'',
				'',
				'True',
				'That is the boiling point at standard atmospheric pressure.',
				'Chemistry',
				'WAEC',
				'States of Matter',
				'2021',
				'easy',
				'1',
			),
			array(
				'Which of these are noble gases?',
				'multi',
				'Helium',
				'Nitrogen',
				'Argon',
				'Oxygen',
				'A|C',
				'Helium and argon are both in Group 18 of the periodic table.',
				'Chemistry',
				'JAMB',
				'Periodic Table',
				'2020',
				'medium',
				'2',
			),
			array(
				'The expression \\( x^2 - 9 \\) factorises to which of the following?',
				'single',
				'\\( (x-3)(x-3) \\)',
				'\\( (x+3)(x-3) \\)',
				'\\( (x+9)(x-1) \\)',
				'\\( (x-9)(x+1) \\)',
				'B',
				'Difference of two squares: \\( a^2 - b^2 = (a+b)(a-b) \\).',
				'Mathematics',
				'JAMB',
				'Algebra > Factorisation',
				'2022',
				'medium',
				'1',
			),
		);

		foreach ( $samples as $sample ) {
			fputcsv( $handle, $sample );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
