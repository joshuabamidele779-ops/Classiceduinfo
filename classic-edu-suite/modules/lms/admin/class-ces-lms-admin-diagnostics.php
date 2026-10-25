<?php
/**
 * Video diagnostics: what this particular host can and cannot do.
 *
 * Self-hosting video on shared LiteSpeed hosting fails in a handful of
 * specific, boring ways — no ffmpeg, `exec()` disabled, an unwritable uploads
 * directory, or PHP streaming every byte itself because the server's internal
 * file-send header is not enabled. Each of those has a different fix, and none
 * of them is visible from the lesson editor, so they all get tested here.
 *
 * The delivery test is the one that matters most. With `readfile()` delivery
 * every student watching holds a PHP worker for the entire video; shared
 * hosting allows only a handful of workers, so a class of thirty students
 * takes the whole site down. The test makes a loopback request for a known
 * 64-byte payload using each candidate method and pins the best one that
 * actually returns those bytes.
 *
 * @package ClassicEduSuite\Lms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Environment diagnostics and the delivery-method test.
 */
class CES_Lms_Admin_Diagnostics {

	/**
	 * Capability required for this screen.
	 */
	const CAP = 'ces_manage_lms';

	/**
	 * admin-post action name.
	 */
	const ACTION = 'ces_lms_diagnostics';

	/**
	 * Nonce action for this screen's forms.
	 */
	const NONCE = 'ces_lms_diagnostics';

	/**
	 * Transient holding the last delivery test result.
	 */
	const RESULT = 'ces_lms_delivery_test';

	/**
	 * Register the form handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle a posted diagnostics action.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'classic-edu-suite' ) );
		}

		check_admin_referer( self::NONCE );

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( (string) $_POST['task'] ) ) : '';

		switch ( $task ) {
			case 'delivery':
				$results = self::run_delivery_test();
				set_transient( self::RESULT, $results, HOUR_IN_SECONDS );

				if ( ! empty( $results['chosen'] ) ) {
					update_option( CES_Lms_Stream::DELIVERY_OPTION, $results['chosen'], false );
				}
				break;

			case 'recheck':
				CES_Lms_Transcode::flush_capabilities();
				CES_Lms_Storage_Factory::flush();
				delete_transient( self::RESULT );
				break;

			case 'pin':
				$method  = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( (string) $_POST['method'] ) ) : '';
				$methods = CES_Lms_Stream::delivery_methods();

				if ( isset( $methods[ $method ] ) ) {
					update_option( CES_Lms_Stream::DELIVERY_OPTION, $method, false );
				}
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ces-lms-diagnostics' ) );
		exit;
	}

	// ------------------------------------------------------------------
	// The delivery test.
	// ------------------------------------------------------------------

	/**
	 * Try each delivery method over a loopback request.
	 *
	 * A method passes only when the response body is exactly the probe
	 * payload. That is the whole point: a misconfigured `X-Sendfile` header
	 * typically returns an empty body with a 200, which looks fine to anything
	 * that only checks the status code and would silently serve empty video.
	 *
	 * @return array {
	 *     @type array  $methods Method key => array( ok, note ).
	 *     @type string $chosen  The method that was pinned, or ''.
	 *     @type int    $when    Timestamp of the test.
	 * }
	 */
	public static function run_delivery_test() {
		$expected = CES_Lms_Stream::probe_payload();
		$methods  = CES_Lms_Stream::delivery_methods();
		$results  = array();
		$chosen   = '';

		foreach ( $methods as $key => $label ) {
			$url = CES_Lms_Stream::build_url(
				CES_Lms_Stream::ACTION_PROBE,
				0,
				'probe.bin',
				'',
				array(
					'm' => $key,
					'k' => CES_Lms_Stream::probe_key( $key ),
				)
			);

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'sslverify'   => false,
					'redirection' => 0,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$results[ $key ] = array(
					'ok'   => false,
					'note' => $response->get_error_message(),
				);

				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			if ( 200 !== $code ) {
				$results[ $key ] = array(
					'ok'   => false,
					'note' => sprintf(
						/* translators: %d: HTTP status code. */
						__( 'The server answered with HTTP %d.', 'classic-edu-suite' ),
						$code
					),
				);

				continue;
			}

			if ( $body !== $expected ) {
				$results[ $key ] = array(
					'ok'   => false,
					'note' => '' === $body
						? __( 'The response was empty, which is what a server does when this header is accepted but not enabled. Using this method would serve blank video.', 'classic-edu-suite' )
						: __( 'The response did not match the test payload.', 'classic-edu-suite' ),
				);

				continue;
			}

			$results[ $key ] = array(
				'ok'   => true,
				'note' => __( 'Served the test payload correctly.', 'classic-edu-suite' ),
			);

			// `delivery_methods()` is ordered best-first, so the first pass wins.
			if ( '' === $chosen ) {
				$chosen = $key;
			}
		}

		return array(
			'methods' => $results,
			'chosen'  => $chosen,
			'when'    => time(),
		);
	}

	// ------------------------------------------------------------------
	// Rendering.
	// ------------------------------------------------------------------

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'classic-edu-suite' ) );
		}

		echo '<div class="wrap ces-wrap ces-lms-admin">';
		echo '<h1>' . esc_html__( 'Video Diagnostics', 'classic-edu-suite' ) . '</h1>';

		self::render_environment();
		self::render_delivery();
		self::render_storage();

		echo '<p>';
		self::button( 'recheck', __( 'Re-run every check', 'classic-edu-suite' ) );
		echo '</p>';

		echo '</div>';
	}

	/**
	 * ffmpeg, shell access and the private directory.
	 *
	 * @return void
	 */
	private static function render_environment() {
		$caps     = CES_Lms_Transcode::capabilities( true );
		$writable = CES_Lms_Paths::ensure();
		$base     = CES_Lms_Paths::base();

		echo '<h2>' . esc_html__( 'This server', 'classic-edu-suite' ) . '</h2>';

		echo '<table class="widefat striped ces-lms-table"><tbody>';

		self::row(
			__( 'Can PHP start programs?', 'classic-edu-suite' ),
			(bool) $caps['shell'],
			$caps['shell']
				? sprintf(
					/* translators: %s: PHP function name. */
					__( 'Yes, using %s.', 'classic-edu-suite' ),
					(string) $caps['shell_fn']
				)
				: __( 'No. exec, shell_exec and proc_open are all disabled, so HLS packaging is impossible on this host.', 'classic-edu-suite' )
		);

		self::row(
			__( 'ffmpeg', 'classic-edu-suite' ),
			'' !== $caps['ffmpeg'],
			'' !== $caps['ffmpeg']
				? (string) $caps['ffmpeg']
				: __( 'Not found. Lessons will stream as single protected files instead of an adaptive ladder.', 'classic-edu-suite' )
		);

		self::row(
			__( 'ffprobe', 'classic-edu-suite' ),
			'' !== $caps['ffprobe'],
			'' !== $caps['ffprobe']
				? (string) $caps['ffprobe']
				: __( 'Not found. Lesson durations have to be typed in by hand, and completion falls back to how far the student actually reached.', 'classic-edu-suite' )
		);

		self::row(
			__( 'Private video directory', 'classic-edu-suite' ),
			$writable,
			$writable
				? $base
				: __( 'Not writable. Uploads will fail until wp-content/uploads is writable by PHP.', 'classic-edu-suite' )
		);

		self::row(
			__( 'Upload limit per request', 'classic-edu-suite' ),
			true,
			sprintf(
				/* translators: 1: PHP upload_max_filesize, 2: our chunk size, 3: overall maximum. */
				__( 'PHP accepts %1$s per request; video is sent in %2$s chunks, so a lesson can be up to %3$s.', 'classic-edu-suite' ),
				size_format( (int) wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ) ),
				size_format( CES_Lms_Admin_Upload::chunk_size() ),
				size_format( CES_Lms_Admin_Upload::max_bytes() )
			)
		);

		echo '</tbody></table>';
	}

	/**
	 * The delivery method block.
	 *
	 * @return void
	 */
	private static function render_delivery() {
		$methods = CES_Lms_Stream::delivery_methods();
		$active  = CES_Lms_Stream::delivery_method();
		$result  = get_transient( self::RESULT );

		echo '<h2>' . esc_html__( 'How video reaches students', 'classic-edu-suite' ) . '</h2>';

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: human label of the active delivery method. */
				__( 'Currently using: %s', 'classic-edu-suite' ),
				isset( $methods[ $active ] ) ? $methods[ $active ] : $active
			)
		) . '</p>';

		if ( 'readfile' === $active ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'PHP is streaming every byte itself. Each student watching occupies a PHP worker for the whole video, and shared hosting only allows a few at once. Run the test below to find a server-level method.', 'classic-edu-suite' )
				. '</p></div>';
		}

		echo '<p>';
		self::button( 'delivery', __( 'Test delivery methods', 'classic-edu-suite' ), 'button button-primary' );
		echo '</p>';

		if ( ! is_array( $result ) || empty( $result['methods'] ) ) {
			echo '<p class="description">' . esc_html__( 'The test makes a request to this site for a known 64-byte file using each method, and accepts a method only when those exact bytes come back.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped ces-lms-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Method', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Detail', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Use', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $methods as $key => $label ) {
			$row = isset( $result['methods'][ $key ] ) ? $result['methods'][ $key ] : null;

			echo '<tr>';
			echo '<td><strong>' . esc_html( $label ) . '</strong></td>';

			if ( ! $row ) {
				echo '<td>&mdash;</td><td>&mdash;</td><td>&mdash;</td></tr>';
				continue;
			}

			echo '<td><span class="ces-pill ces-pill--' . ( $row['ok'] ? 'ready' : 'failed' ) . '">'
				. ( $row['ok'] ? esc_html__( 'Works', 'classic-edu-suite' ) : esc_html__( 'No', 'classic-edu-suite' ) )
				. '</span></td>';

			echo '<td>' . esc_html( (string) $row['note'] ) . '</td>';

			echo '<td>';

			if ( $row['ok'] && $key !== $active ) {
				self::pin_button( $key );
			} elseif ( $key === $active ) {
				echo esc_html__( 'in use', 'classic-edu-suite' );
			} else {
				echo '&mdash;';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: human time difference. */
				__( 'Tested %s ago.', 'classic-edu-suite' ),
				human_time_diff( (int) $result['when'], time() )
			)
		) . '</p>';

		if ( ! empty( $result['chosen'] ) && isset( $methods[ $result['chosen'] ] ) ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: chosen method label. */
					__( 'The test pinned: %s', 'classic-edu-suite' ),
					$methods[ $result['chosen'] ]
				)
			) . '</p>';
		}

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: internal location prefix. */
				__( 'The nginx method additionally needs an internal location mapped to the private uploads directory at %s. The LiteSpeed and Apache methods need no configuration beyond the module being enabled.', 'classic-edu-suite' ),
				CES_Lms_Stream::accel_prefix()
			)
		) . '</p>';
	}

	/**
	 * The storage driver block.
	 *
	 * @return void
	 */
	private static function render_storage() {
		$driver = CES_Lms_Storage_Factory::active();

		echo '<h2>' . esc_html__( 'Storage', 'classic-edu-suite' ) . '</h2>';

		if ( ! $driver ) {
			echo '<div class="notice notice-error inline"><p>'
				. esc_html__( 'No storage driver is configured. Choose one under Classic EDU → Settings.', 'classic-edu-suite' )
				. '</p></div>';

			return;
		}

		$config = $driver->check_config();

		echo '<table class="widefat striped ces-lms-table"><tbody>';

		self::row( __( 'Driver', 'classic-edu-suite' ), true, $driver->get_label() );

		self::row(
			__( 'Configuration', 'classic-edu-suite' ),
			! is_wp_error( $config ),
			is_wp_error( $config ) ? $config->get_error_message() : __( 'Complete.', 'classic-edu-suite' )
		);

		self::row(
			__( 'Encrypted DRM', 'classic-edu-suite' ),
			$driver->supports_drm(),
			$driver->supports_drm()
				? __( 'Available through this provider.', 'classic-edu-suite' )
				: __( 'No. Signed, expiring, IP-bound links and a visible watermark stop link sharing and download tools; they do not stop someone recording their own screen.', 'classic-edu-suite' )
		);

		echo '</tbody></table>';
	}

	/**
	 * One label/status/detail table row.
	 *
	 * @param string $label  Row label.
	 * @param bool   $ok     Whether this is a pass.
	 * @param string $detail Detail text.
	 * @return void
	 */
	private static function row( $label, $ok, $detail ) {
		echo '<tr>';
		echo '<td style="width:16em"><strong>' . esc_html( $label ) . '</strong></td>';
		echo '<td style="width:6em"><span class="ces-pill ces-pill--' . ( $ok ? 'ready' : 'failed' ) . '">'
			. ( $ok ? esc_html__( 'OK', 'classic-edu-suite' ) : esc_html__( 'Note', 'classic-edu-suite' ) )
			. '</span></td>';
		echo '<td>' . esc_html( (string) $detail ) . '</td>';
		echo '</tr>';
	}

	/**
	 * One self-contained POST button.
	 *
	 * @param string $task  Task key.
	 * @param string $label Button label.
	 * @param string $class Button classes.
	 * @return void
	 */
	private static function button( $task, $label, $class = 'button' ) {
		echo '<form method="post" class="ces-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="task" value="' . esc_attr( $task ) . '" />';
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * A button that pins one delivery method.
	 *
	 * @param string $method Method key.
	 * @return void
	 */
	private static function pin_button( $method ) {
		echo '<form method="post" class="ces-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="task" value="pin" />';
		echo '<input type="hidden" name="method" value="' . esc_attr( $method ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Use this', 'classic-edu-suite' ) . '</button>';
		echo '</form>';
	}
}
