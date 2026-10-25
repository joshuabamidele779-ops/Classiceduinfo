<?php
/**
 * The Parents admin screen.
 *
 * One list of students with their parent's number, the progress summary that
 * would be sent, and a button that opens WhatsApp with the message already
 * written. Staff press the button, read it, and send — which is both faster
 * and safer than automated blasting, and needs no API approval.
 *
 * Where the Cloud API is configured, a "Send" button appears alongside.
 *
 * @package ClassicEduSuite\Parents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen and its post handlers.
 */
class CES_Parents_Admin {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'ces-parents';

	/**
	 * Capability required to see parent contact details.
	 */
	const CAP = 'ces_manage_parents';

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 25;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_admin_submenus', array( __CLASS__, 'register_submenu' ) );
		add_filter( 'ces_overview_cards', array( __CLASS__, 'overview_card' ) );

		add_action( 'admin_post_ces_parents_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_ces_parents_chat', array( __CLASS__, 'handle_chat' ) );
		add_action( 'admin_post_ces_parents_send', array( __CLASS__, 'handle_send' ) );
	}

	/**
	 * Add the submenu entry.
	 *
	 * @param array $items Submenus so far.
	 * @return array
	 */
	public static function register_submenu( $items ) {
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$items[] = array(
			'slug'       => self::PAGE,
			'title'      => __( 'Parents', 'classic-edu-suite' ),
			'menu_title' => __( 'Parents', 'classic-edu-suite' ),
			'capability' => self::CAP,
			'callback'   => array( __CLASS__, 'render_page' ),
			'position'   => 45,
		);

		return $items;
	}

	/**
	 * Headline number on the plugin overview screen.
	 *
	 * @param array $cards Cards so far.
	 * @return array
	 */
	public static function overview_card( $cards ) {
		if ( ! is_array( $cards ) ) {
			$cards = array();
		}

		$coverage = CES_Parent_Contact::coverage();

		$cards[] = array(
			'label' => __( 'Parent numbers on file', 'classic-edu-suite' ),
			'value' => number_format_i18n( $coverage['with'] ) . ' / ' . number_format_i18n( $coverage['total'] ),
			'href'  => admin_url( 'admin.php?page=' . self::PAGE ),
		);

		return $cards;
	}

	/**
	 * Guard every handler on this screen.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! CES_Helpers::can_manage( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view parent contact details.', 'classic-edu-suite' ), 403 );
		}
	}

	/* ---------------------------------------------------------------------
	 * The screen.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the Parents screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$have   = isset( $_GET['have'] ) ? sanitize_key( wp_unslash( $_GET['have'] ) ) : 'yes';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$days   = isset( $_GET['days'] ) ? max( 1, min( 365, absint( $_GET['days'] ) ) ) : CES_Parents_Report::WINDOW_DAYS;
		$notice = isset( $_GET['ces_msg'] ) ? sanitize_key( wp_unslash( $_GET['ces_msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $have, array( 'yes', 'no', 'any' ), true ) ) {
			$have = 'yes';
		}

		$coverage = CES_Parent_Contact::coverage();

		$result = CES_Parent_Contact::query_students(
			array(
				'have'   => $have,
				'search' => $search,
				'number' => self::PER_PAGE,
				'offset' => ( $paged - 1 ) * self::PER_PAGE,
			)
		);

		echo '<div class="wrap ces-wrap ces-parents-admin">';
		echo '<h1>' . esc_html__( 'Parents & Guardians', 'classic-edu-suite' ) . '</h1>';
		echo '<p class="ces-sub">' . esc_html__( 'Every student’s parent contact, with a ready-written progress update you can send on WhatsApp.', 'classic-edu-suite' ) . '</p>';

		self::render_notice( $notice );

		// Coverage cards.
		echo '<div class="ces-cards">';
		echo '<div class="ces-card"><span class="ces-card__value">' . esc_html( number_format_i18n( $coverage['with'] ) ) . '</span><span class="ces-card__label">' . esc_html__( 'With a WhatsApp number', 'classic-edu-suite' ) . '</span></div>';
		echo '<div class="ces-card"><span class="ces-card__value">' . esc_html( number_format_i18n( $coverage['without'] ) ) . '</span><span class="ces-card__label">' . esc_html__( 'Still missing one', 'classic-edu-suite' ) . '</span></div>';
		echo '<div class="ces-card"><span class="ces-card__value">' . esc_html( number_format_i18n( $coverage['total'] ) ) . '</span><span class="ces-card__label">' . esc_html__( 'Accounts', 'classic-edu-suite' ) . '</span></div>';
		echo '</div>';

		// Filters.
		echo '<form method="get" class="ces-parents-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		echo '<select name="have">';
		foreach ( array(
			'yes' => __( 'Has a parent number', 'classic-edu-suite' ),
			'no'  => __( 'Missing a parent number', 'classic-edu-suite' ),
			'any' => __( 'All students', 'classic-edu-suite' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $have, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search student name or email', 'classic-edu-suite' ) . '" /> ';
		echo '<label>' . esc_html__( 'Window (days)', 'classic-edu-suite' ) . ' <input type="number" name="days" class="small-text" min="1" max="365" value="' . esc_attr( $days ) . '" /></label> ';
		submit_button( __( 'Filter', 'classic-edu-suite' ), 'secondary', '', false );
		echo '</form>';

		// Export.
		echo '<p class="ces-parents-export">';
		echo '<a class="button" href="' . esc_url(
			wp_nonce_url(
				admin_url( 'admin-post.php?action=ces_parents_export&have=' . rawurlencode( $have ) ),
				'ces_parents_export'
			)
		) . '">' . esc_html__( 'Download CSV', 'classic-edu-suite' ) . '</a> ';
		echo '<span class="description">' . esc_html__( 'Personal data. Keep the file off shared drives and delete it when you are done.', 'classic-edu-suite' ) . '</span>';
		echo '</p>';

		self::render_table( $result['users'], $days );

		self::render_pagination( (int) $result['total'], $paged, $have, $search, $days );

		echo '</div>';
	}

	/**
	 * Print the result of a redirect-back action.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private static function render_notice( $notice ) {
		if ( '' === $notice ) {
			return;
		}

		$map = array(
			'sent'    => array( 'success', __( 'Message sent.', 'classic-edu-suite' ) ),
			'logged'  => array( 'success', __( 'Opened WhatsApp and logged the contact.', 'classic-edu-suite' ) ),
			'failed'  => array( 'error', __( 'The message could not be sent. Use the chat link instead.', 'classic-edu-suite' ) ),
			'nonumber' => array( 'error', __( 'That student has no parent number on file.', 'classic-edu-suite' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		echo '<div class="notice notice-' . esc_attr( $map[ $notice ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $notice ][1] ) . '</p></div>';
	}

	/**
	 * The student table.
	 *
	 * @param array $users WP_User objects.
	 * @param int   $days  Report window.
	 * @return void
	 */
	private static function render_table( array $users, $days ) {
		echo '<table class="widefat striped ces-parents-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Student', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Parent / guardian', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Recent activity', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Last messaged', 'classic-edu-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $users ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No students match that filter.', 'classic-edu-suite' ) . '</td></tr>';
			echo '</tbody></table>';

			return;
		}

		$can_send = CES_Parents_Whatsapp::enabled();

		foreach ( $users as $user ) {
			if ( ! ( $user instanceof WP_User ) ) {
				continue;
			}

			$contact = CES_Parent_Contact::get( $user->ID );
			$summary = CES_Parents_Report::summary( $user->ID, $days );

			echo '<tr>';

			// Student.
			echo '<td><strong>' . esc_html( $user->display_name ) . '</strong><br />';
			echo '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->user_email ) . '</a></td>';

			// Parent.
			echo '<td>';
			if ( $contact['phone'] ) {
				echo esc_html( $contact['name'] ) . '<br />';
				echo '<code>' . esc_html( $contact['phone_pretty'] ) . '</code><br />';
				echo '<span class="description">' . esc_html( $contact['relation_label'] );
				if ( ! $contact['consent'] ) {
					echo ' · ' . esc_html__( 'no consent recorded', 'classic-edu-suite' );
				}
				echo '</span>';
			} else {
				echo '<span class="description">' . esc_html__( 'Not provided', 'classic-edu-suite' ) . '</span>';
			}
			echo '</td>';

			// Activity.
			echo '<td>';
			echo esc_html(
				sprintf(
					/* translators: 1: exam count, 2: average score. */
					__( '%1$d exam(s), avg %2$s%%', 'classic-edu-suite' ),
					$summary['exams'],
					number_format_i18n( $summary['average'], 1 )
				)
			);
			echo '<br /><span class="description">' . esc_html(
				sprintf(
					/* translators: %s: human time difference. */
					__( 'Last active: %s', 'classic-edu-suite' ),
					$summary['last_active_h']
				)
			) . '</span>';
			echo '</td>';

			// Last messaged.
			echo '<td>';
			if ( $contact['last_contacted'] ) {
				echo esc_html( $contact['last_contacted'] ) . '<br />';
				echo '<span class="description">' . esc_html(
					sprintf(
						/* translators: %d: message count. */
						_n( '%d message', '%d messages', $contact['contact_count'], 'classic-edu-suite' ),
						$contact['contact_count']
					)
				) . '</span>';
			} else {
				echo '<span class="description">' . esc_html__( 'Never', 'classic-edu-suite' ) . '</span>';
			}
			echo '</td>';

			// Actions.
			echo '<td class="ces-parents-actions">';

			if ( $contact['phone'] ) {
				$chat_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=ces_parents_chat&user=' . (int) $user->ID . '&days=' . (int) $days ),
					'ces_parents_chat_' . (int) $user->ID
				);

				echo '<a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' . esc_url( $chat_url ) . '">' . esc_html__( 'Open WhatsApp', 'classic-edu-suite' ) . '</a> ';

				if ( $can_send ) {
					$send_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=ces_parents_send&user=' . (int) $user->ID . '&days=' . (int) $days ),
						'ces_parents_send_' . (int) $user->ID
					);

					echo '<a class="button" href="' . esc_url( $send_url ) . '">' . esc_html__( 'Send', 'classic-edu-suite' ) . '</a>';
				}

				echo '<details class="ces-parents-preview"><summary>' . esc_html__( 'Preview message', 'classic-edu-suite' ) . '</summary>';
				echo '<pre>' . esc_html( CES_Parents_Report::message( $user->ID, '', $days ) ) . '</pre>';
				echo '</details>';
			} else {
				echo '<span class="description">' . esc_html__( 'Ask the student to add it from their dashboard.', 'classic-edu-suite' ) . '</span>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Simple pager.
	 *
	 * @param int    $total  Total rows.
	 * @param int    $paged  Current page.
	 * @param string $have   Filter value.
	 * @param string $search Search term.
	 * @param int    $days   Window.
	 * @return void
	 */
	private static function render_pagination( $total, $paged, $have, $search, $days ) {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		$base = admin_url( 'admin.php?page=' . self::PAGE );
		$base = add_query_arg(
			array(
				'have' => $have,
				's'    => $search,
				'days' => $days,
			),
			$base
		);

		echo '<div class="tablenav"><div class="tablenav-pages">';

		echo '<span class="displaying-num">' . esc_html(
			sprintf(
				/* translators: %s: number of students. */
				_n( '%s student', '%s students', $total, 'classic-edu-suite' ),
				number_format_i18n( $total )
			)
		) . '</span> ';

		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $base ) ) . '">&laquo; ' . esc_html__( 'Previous', 'classic-edu-suite' ) . '</a> ';
		}

		echo '<span class="paging-input">' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: total pages. */
				__( 'Page %1$d of %2$d', 'classic-edu-suite' ),
				$paged,
				$pages
			)
		) . '</span> ';

		if ( $paged < $pages ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $base ) ) . '">' . esc_html__( 'Next', 'classic-edu-suite' ) . ' &raquo;</a>';
		}

		echo '</div></div>';
	}

	/* ---------------------------------------------------------------------
	 * Handlers.
	 * ------------------------------------------------------------------ */

	/**
	 * Log the contact, then bounce the browser to WhatsApp.
	 *
	 * @return void
	 */
	public static function handle_chat() {
		self::guard();

		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$days    = isset( $_GET['days'] ) ? max( 1, min( 365, absint( $_GET['days'] ) ) ) : CES_Parents_Report::WINDOW_DAYS;

		check_admin_referer( 'ces_parents_chat_' . $user_id );

		$row = CES_Parents_Report::outbox_row( $user_id, $days );

		if ( ! $row || '' === $row['wa_link'] ) {
			self::redirect_back( 'nonumber' );
		}

		CES_Parent_Contact::mark_contacted( $user_id );

		// wa.me is external, so wp_safe_redirect would refuse it.
		wp_redirect( $row['wa_link'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Send through the Cloud API.
	 *
	 * @return void
	 */
	public static function handle_send() {
		self::guard();

		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$days    = isset( $_GET['days'] ) ? max( 1, min( 365, absint( $_GET['days'] ) ) ) : CES_Parents_Report::WINDOW_DAYS;

		check_admin_referer( 'ces_parents_send_' . $user_id );

		$row = CES_Parents_Report::outbox_row( $user_id, $days );

		if ( ! $row ) {
			self::redirect_back( 'nonumber' );
		}

		$result = CES_Parents_Whatsapp::send( $row['contact']['phone'], $row['message'] );

		if ( is_wp_error( $result ) ) {
			self::redirect_back( 'failed' );
		}

		CES_Parent_Contact::mark_contacted( $user_id );

		self::redirect_back( 'sent' );
	}

	/**
	 * Stream the parent list as CSV.
	 *
	 * @return void
	 */
	public static function handle_export() {
		self::guard();
		check_admin_referer( 'ces_parents_export' );

		$have = isset( $_GET['have'] ) ? sanitize_key( wp_unslash( $_GET['have'] ) ) : 'yes';

		if ( ! in_array( $have, array( 'yes', 'no', 'any' ), true ) ) {
			$have = 'yes';
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ces-parents-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$handle = fopen( 'php://output', 'w' );

		if ( ! $handle ) {
			exit;
		}

		fputcsv(
			$handle,
			array(
				'student_id',
				'student_name',
				'student_email',
				'parent_name',
				'parent_whatsapp',
				'relationship',
				'consent',
				'exams_30d',
				'average_30d',
				'last_active',
				'last_contacted',
			)
		);

		$offset = 0;

		do {
			$batch = CES_Parent_Contact::query_students(
				array(
					'have'   => $have,
					'number' => 100,
					'offset' => $offset,
				)
			);

			foreach ( $batch['users'] as $user ) {
				if ( ! ( $user instanceof WP_User ) ) {
					continue;
				}

				$contact = CES_Parent_Contact::get( $user->ID );
				$summary = CES_Parents_Report::summary( $user->ID );

				fputcsv(
					$handle,
					array(
						$user->ID,
						$user->display_name,
						$user->user_email,
						$contact['name'],
						// Leading apostrophe keeps Excel from eating the number.
						"'" . $contact['phone'],
						$contact['relation_label'],
						$contact['consent'] ? 'yes' : 'no',
						$summary['exams'],
						$summary['average'],
						$summary['last_active'],
						$contact['last_contacted'],
					)
				);
			}

			$offset += 100;
		} while ( ! empty( $batch['users'] ) && $offset < (int) $batch['total'] );

		fclose( $handle );
		exit;
	}

	/**
	 * Return to the screen with a notice.
	 *
	 * @param string $message Notice key.
	 * @return void
	 */
	private static function redirect_back( $message ) {
		wp_safe_redirect(
			add_query_arg(
				'ces_msg',
				sanitize_key( $message ),
				admin_url( 'admin.php?page=' . self::PAGE )
			)
		);
		exit;
	}
}
