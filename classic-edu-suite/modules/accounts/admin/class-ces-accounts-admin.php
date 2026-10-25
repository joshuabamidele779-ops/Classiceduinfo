<?php
/**
 * The Approvals screen.
 *
 * One queue, worked top to bottom: who is waiting, which parent they gave,
 * whether that parent has answered, and four buttons — approve, refuse, ask
 * again, or start over. Plus the two things a member of staff actually needs
 * when a parent rings the school: a WhatsApp link to that parent with the
 * request already written, and a *Confirm by phone* button that records who
 * vouched for it.
 *
 * `ces_manage_accounts` is granted to administrators only, not to the EDU
 * Instructor role: this screen shows third-party phone numbers and approving
 * a student is a safeguarding decision.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen and its post handlers.
 */
class CES_Accounts_Admin {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'ces-approvals';

	/**
	 * Capability required.
	 */
	const CAP = 'ces_manage_accounts';

	/**
	 * admin-post action for every button on this screen.
	 */
	const ACTION = 'ces_accounts_action';

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

		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_ces_accounts_export', array( __CLASS__, 'handle_export' ) );

		// The one thing staff look for on the user list: is this one in.
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
	}

	/**
	 * Add the submenu entry, with the waiting count on it.
	 *
	 * @param array $items Submenus so far.
	 * @return array
	 */
	public static function register_submenu( $items ) {
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$title   = __( 'Approvals', 'classic-edu-suite' );
		$counts  = CES_Accounts_Status::counts();
		$waiting = (int) $counts['awaiting_admin'] + (int) $counts['flagged'];

		$menu_title = $waiting > 0
			? $title . ' <span class="awaiting-mod"><span class="pending-count">' . number_format_i18n( $waiting ) . '</span></span>'
			: $title;

		$items[] = array(
			'slug'       => self::PAGE,
			'title'      => $title,
			'menu_title' => $menu_title,
			'capability' => self::CAP,
			'callback'   => array( __CLASS__, 'render_page' ),
			'position'   => 4,
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

		$counts = CES_Accounts_Status::counts();

		$cards[] = array(
			'label' => __( 'Students waiting for approval', 'classic-edu-suite' ),
			'value' => number_format_i18n( (int) $counts['pending'] ),
			'href'  => admin_url( 'admin.php?page=' . self::PAGE ),
		);

		return $cards;
	}

	/**
	 * Refuse anyone without the capability.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! CES_Helpers::can_manage( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to approve student accounts.', 'classic-edu-suite' ), '', array( 'response' => 403 ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * The screen.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the Approvals screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters on a GET.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'waiting';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$view   = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
		$notice = isset( $_GET['ces_msg'] ) ? sanitize_key( wp_unslash( $_GET['ces_msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap ces-wrap ces-approvals">';
		echo '<h1>' . esc_html__( 'Student approvals', 'classic-edu-suite' ) . '</h1>';

		self::render_notice( $notice );

		if ( $view > 0 ) {
			self::render_detail( $view );
			echo '</div>';

			return;
		}

		echo '<p class="ces-sub">' . esc_html__( 'Every student signs up with Gmail and names a parent or guardian. That parent is asked to confirm, and until they do the student cannot start a paper.', 'classic-edu-suite' ) . '</p>';

		self::render_health();
		self::render_cards();
		self::render_filters( $status, $search );
		self::render_table( $status, $search, $paged );

		echo '</div>';
	}

	/**
	 * Is this thing actually configured? Answered before anything else,
	 * because every support question starts here.
	 *
	 * @return void
	 */
	private static function render_health() {
		$google_on  = CES_Helpers::enabled( 'accounts_google_enabled' );
		$configured = CES_Accounts_Google::is_configured();
		$approval   = CES_Accounts_Status::approval_enabled();
		$wa         = class_exists( 'CES_Parents_Whatsapp' ) && CES_Parents_Whatsapp::enabled();
		$portal     = CES_Helpers::setting_int( 'accounts_portal_page_id', 0 );
		$settings   = admin_url( 'admin.php?page=ces-settings' );

		echo '<div class="ces-health">';
		echo '<h2>' . esc_html__( 'Setup', 'classic-edu-suite' ) . '</h2>';
		echo '<ul class="ces-health__list">';

		self::health_row(
			$google_on && $configured,
			__( 'Gmail sign-in is on and has its client ID and secret.', 'classic-edu-suite' ),
			$google_on
				? __( 'Gmail sign-in is on but the client ID or secret is missing, so the button is hidden.', 'classic-edu-suite' )
				: __( 'Gmail sign-in is switched off. Students sign in with a password only.', 'classic-edu-suite' ),
			$settings
		);

		self::health_row(
			$approval,
			__( 'Parent approval is required before a student can practise.', 'classic-edu-suite' ),
			__( 'Parent approval is switched off. Anyone who signs up gets straight in.', 'classic-edu-suite' ),
			$settings
		);

		self::health_row(
			$wa,
			__( 'Requests go out to parents on WhatsApp automatically.', 'classic-edu-suite' ),
			__( 'No WhatsApp API, so students forward the request themselves with one tap. This is the normal setup and costs nothing.', 'classic-edu-suite' ),
			$settings,
			true
		);

		self::health_row(
			$portal > 0,
			__( 'The parent approval page uses your theme.', 'classic-edu-suite' ),
			__( 'No parent page set, so the plugin prints a plain self-contained page. Make a page containing [ces_parent_portal] and put its ID in the settings to use your own design.', 'classic-edu-suite' ),
			$settings,
			true
		);

		echo '</ul>';

		echo '<p class="ces-health__uri"><strong>' . esc_html__( 'Redirect URI for Google Cloud Console:', 'classic-edu-suite' ) . '</strong><br />';
		echo '<code>' . esc_html( CES_Accounts_Google::redirect_uri() ) . '</code><br />';
		echo '<span class="description">' . esc_html__( 'Paste this into “Authorised redirect URIs” on your OAuth client, exactly as shown. Add the home page URL under “Authorised JavaScript origins”.', 'classic-edu-suite' ) . '</span></p>';

		echo '</div>';
	}

	/**
	 * One line of the setup list.
	 *
	 * @param bool   $ok       Whether this is satisfied.
	 * @param string $good     What to say when it is.
	 * @param string $bad      What to say when it is not.
	 * @param string $link     Where to go and fix it.
	 * @param bool   $optional Whether "not set" is merely information.
	 * @return void
	 */
	private static function health_row( $ok, $good, $bad, $link = '', $optional = false ) {
		$class = $ok ? 'is-ok' : ( $optional ? 'is-info' : 'is-warn' );
		$icon  = $ok ? '✔' : ( $optional ? 'ℹ' : '!' );

		echo '<li class="ces-health__item ' . esc_attr( $class ) . '">';
		echo '<span class="ces-health__icon" aria-hidden="true">' . esc_html( $icon ) . '</span> ';
		echo esc_html( $ok ? $good : $bad );

		if ( ! $ok && $link ) {
			echo ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Settings', 'classic-edu-suite' ) . '</a>';
		}

		echo '</li>';
	}

	/**
	 * The count cards.
	 *
	 * @return void
	 */
	private static function render_cards() {
		$counts = CES_Accounts_Status::counts();

		$cards = array(
			'awaiting_admin'  => __( 'Waiting for you', 'classic-edu-suite' ),
			'awaiting_parent' => __( 'Waiting for a parent', 'classic-edu-suite' ),
			'onboarding'      => __( 'Not started', 'classic-edu-suite' ),
			'approved'        => __( 'Approved', 'classic-edu-suite' ),
			'declined'        => __( 'Declined', 'classic-edu-suite' ),
			'flagged'         => __( 'Flagged', 'classic-edu-suite' ),
		);

		echo '<div class="ces-cards">';

		foreach ( $cards as $key => $label ) {
			$value = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;

			echo '<div class="ces-card">';
			echo '<span class="ces-card__value">' . esc_html( number_format_i18n( $value ) ) . '</span>';
			echo '<span class="ces-card__label">' . esc_html( $label ) . '</span>';
			echo '<a class="ces-card__link" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'status' => $key ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Show', 'classic-edu-suite' ) . '</a>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * The filter bar.
	 *
	 * @param string $status Current status filter.
	 * @param string $search Current search.
	 * @return void
	 */
	private static function render_filters( $status, $search ) {
		$options = array_merge(
			array(
				'waiting' => __( 'Everyone still waiting', 'classic-edu-suite' ),
				'flagged' => __( 'Flagged for review', 'classic-edu-suite' ),
			),
			CES_Accounts_Status::statuses(),
			array(
				'none' => __( 'No record (older accounts)', 'classic-edu-suite' ),
				'all'  => __( 'All accounts', 'classic-edu-suite' ),
			)
		);

		echo '<form method="get" class="ces-approvals__filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		echo '<select name="status">';

		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select> ';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name, email or parent number', 'classic-edu-suite' ) . '" /> ';
		submit_button( __( 'Filter', 'classic-edu-suite' ), 'secondary', '', false );
		echo ' <a class="button" href="' . esc_url(
			wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'ces_accounts_export',
						'status' => $status,
						's'      => $search,
					),
					admin_url( 'admin-post.php' )
				),
				'ces_accounts_export'
			)
		) . '">' . esc_html__( 'Export CSV', 'classic-edu-suite' ) . '</a>';
		echo '</form>';
	}

	/**
	 * The queue itself.
	 *
	 * @param string $status Status filter.
	 * @param string $search Search text.
	 * @param int    $paged  Page number.
	 * @return void
	 */
	private static function render_table( $status, $search, $paged ) {
		$result = self::query(
			array(
				'status' => $status,
				'search' => $search,
				'number' => self::PER_PAGE,
				'offset' => ( $paged - 1 ) * self::PER_PAGE,
			)
		);

		$users = $result['users'];
		$total = $result['total'];

		if ( empty( $users ) ) {
			echo '<p class="ces-empty">' . esc_html__( 'Nothing here. Either everybody is approved, or nobody has signed up yet.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="ces_return" value="' . esc_url( self::current_admin_url() ) . '" />';

		echo '<div class="tablenav top"><div class="alignleft actions">';
		echo '<select name="ces_bulk">';
		echo '<option value="">' . esc_html__( 'Bulk action…', 'classic-edu-suite' ) . '</option>';
		echo '<option value="approve">' . esc_html__( 'Approve', 'classic-edu-suite' ) . '</option>';
		echo '<option value="decline">' . esc_html__( 'Decline', 'classic-edu-suite' ) . '</option>';
		echo '<option value="resend">' . esc_html__( 'Ask the parent again', 'classic-edu-suite' ) . '</option>';
		echo '<option value="reset">' . esc_html__( 'Start their signup over', 'classic-edu-suite' ) . '</option>';
		echo '</select> ';
		submit_button( __( 'Apply', 'classic-edu-suite' ), 'secondary', 'ces_do_bulk', false );
		echo '</div></div>';

		echo '<table class="wp-list-table widefat fixed striped ces-approvals__table"><thead><tr>';
		// WordPress's own admin script binds the select-all behaviour to a
		// checkbox in a list table's thead, so no JavaScript of ours is needed.
		echo '<td class="manage-column check-column"><label class="screen-reader-text" for="cb-select-all-1">'
			. esc_html__( 'Select all', 'classic-edu-suite' ) . '</label><input id="cb-select-all-1" type="checkbox" /></td>';
		echo '<th scope="col">' . esc_html__( 'Student', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Parent / guardian', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $users as $user ) {
			self::render_row( $user );
		}

		echo '</tbody></table>';
		echo '</form>';

		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'total'     => $pages,
						'current'   => $paged,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * One row of the queue.
	 *
	 * @param WP_User $user Student.
	 * @return void
	 */
	private static function render_row( WP_User $user ) {
		$record  = CES_Accounts_Status::record( (int) $user->ID );
		$contact = CES_Parent_Contact::get( (int) $user->ID );
		$row     = CES_Accounts_Verification::open_row( (int) $user->ID );
		$labels  = CES_Accounts_Status::flag_labels();
		$detail  = add_query_arg( array( 'page' => self::PAGE, 'view' => (int) $user->ID ), admin_url( 'admin.php' ) );

		echo '<tr>';

		echo '<th scope="row" class="check-column"><input type="checkbox" name="users[]" value="' . esc_attr( (string) $user->ID ) . '" /></th>';

		// Student.
		echo '<td>';
		echo '<strong><a href="' . esc_url( $detail ) . '">' . esc_html( $user->display_name ) . '</a></strong><br />';
		echo '<span class="ces-muted">' . esc_html( $user->user_email ) . '</span><br />';

		if ( 'google' === $record['provider'] ) {
			echo '<span class="ces-tag">' . esc_html__( 'Gmail', 'classic-edu-suite' ) . '</span> ';
		}

		if ( '' !== $record['class_label'] ) {
			echo '<span class="ces-tag">' . esc_html( $record['class_label'] ) . '</span> ';
		}

		echo '<br /><span class="ces-muted">' . esc_html(
			sprintf(
				/* translators: %s: human time difference. */
				__( 'signed up %s ago', 'classic-edu-suite' ),
				human_time_diff( (int) strtotime( (string) $user->user_registered . ' UTC' ) )
			)
		) . '</span>';
		echo '</td>';

		// Parent.
		echo '<td>';

		if ( '' !== $contact['phone'] ) {
			echo esc_html( $contact['name'] ) . '<br />';
			echo '<a href="' . esc_url( CES_Parent_Contact::wa_link( $contact['phone'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $contact['phone_pretty'] ) . '</a><br />';
			echo '<span class="ces-muted">' . esc_html( $contact['relation_label'] ) . '</span>';

			if ( '' !== CES_Accounts_Verification::parent_email( (int) $user->ID ) ) {
				echo '<br /><span class="ces-muted">' . esc_html( CES_Accounts_Verification::parent_email( (int) $user->ID ) ) . '</span>';
			}
		} else {
			echo '<em>' . esc_html__( 'None given yet', 'classic-edu-suite' ) . '</em>';
		}

		echo '</td>';

		// Status.
		echo '<td>';
		echo '<span class="ces-pill ces-pill--' . esc_attr( self::pill_class( $record['status'] ) ) . '">' . esc_html( $record['status_label'] ) . '</span>';

		if ( '' !== $record['verified_at'] ) {
			echo '<br /><span class="ces-muted">' . esc_html(
				sprintf(
					/* translators: 1: date, 2: channel. */
					__( 'parent said yes %1$s (%2$s)', 'classic-edu-suite' ),
					mysql2date( get_option( 'date_format' ), $record['verified_at'] ),
					$record['verified_via']
				)
			) . '</span>';
		}

		if ( $row ) {
			echo '<br /><span class="ces-muted">' . esc_html(
				sprintf(
					/* translators: %d: number of times the request was sent. */
					_n( 'asked %d time', 'asked %d times', (int) $row['send_count'], 'classic-edu-suite' ),
					(int) $row['send_count']
				)
			) . '</span>';

			echo '<br /><span class="ces-muted">' . esc_html(
				'direct' === CES_Accounts_Verification::delivery( $row )
					? __( 'we messaged the parent', 'classic-edu-suite' )
					: __( 'the student forwarded it', 'classic-edu-suite' )
			) . '</span>';
		}

		foreach ( $record['flags'] as $flag ) {
			echo '<br /><span class="ces-flag">⚑ ' . esc_html( isset( $labels[ $flag ] ) ? $labels[ $flag ] : $flag ) . '</span>';
		}

		echo '</td>';

		// Actions. Buttons, not links: every one of these writes.
		echo '<td class="ces-approvals__actions">';
		echo '<button type="submit" name="ces_do" value="approve:' . esc_attr( (string) $user->ID ) . '" class="button button-primary">' . esc_html__( 'Approve', 'classic-edu-suite' ) . '</button> ';
		echo '<button type="submit" name="ces_do" value="confirm:' . esc_attr( (string) $user->ID ) . '" class="button" title="' . esc_attr__( 'Record that you spoke to the parent yourself', 'classic-edu-suite' ) . '">' . esc_html__( 'Confirm by phone', 'classic-edu-suite' ) . '</button> ';
		echo '<button type="submit" name="ces_do" value="decline:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Decline', 'classic-edu-suite' ) . '</button> ';

		if ( '' !== $contact['phone'] ) {
			echo '<button type="submit" name="ces_do" value="resend:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Ask again', 'classic-edu-suite' ) . '</button> ';
		}

		echo '<button type="submit" name="ces_do" value="reset:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Start over', 'classic-edu-suite' ) . '</button>';
		echo '<br /><a href="' . esc_url( $detail ) . '">' . esc_html__( 'History', 'classic-edu-suite' ) . '</a> · ';
		echo '<a href="' . esc_url( get_edit_user_link( (int) $user->ID ) ) . '">' . esc_html__( 'Edit user', 'classic-edu-suite' ) . '</a>';
		echo '</td>';

		echo '</tr>';
	}

	/**
	 * CSS suffix for a status pill.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function pill_class( $status ) {
		switch ( $status ) {
			case 'approved':
				return 'ok';

			case 'declined':
			case 'suspended':
				return 'bad';

			case 'awaiting_admin':
				return 'review';

			default:
				return 'wait';
		}
	}

	/* ---------------------------------------------------------------------
	 * One student in full.
	 * ------------------------------------------------------------------ */

	/**
	 * The detail view: everything on file, and the audit trail.
	 *
	 * @param int $user_id Student.
	 * @return void
	 */
	private static function render_detail( $user_id ) {
		$user = get_userdata( absint( $user_id ) );

		if ( ! $user ) {
			echo '<p>' . esc_html__( 'That account no longer exists.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		$record  = CES_Accounts_Status::record( (int) $user->ID );
		$contact = CES_Parent_Contact::get( (int) $user->ID );
		$events  = CES_Accounts_Status::events( (int) $user->ID, 100 );
		$labels  = CES_Accounts_Status::flag_labels();
		$portal  = CES_Accounts_Verification::portal_link( (int) $user->ID );
		$last    = CES_Accounts_Verification::last_row( (int) $user->ID );

		echo '<p><a href="' . esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'Back to the queue', 'classic-edu-suite' ) . '</a></p>';

		echo '<h2>' . esc_html( $user->display_name ) . ' <span class="ces-pill ces-pill--' . esc_attr( self::pill_class( $record['status'] ) ) . '">' . esc_html( $record['status_label'] ) . '</span></h2>';

		echo '<table class="widefat ces-detail"><tbody>';
		self::detail_row( __( 'Email', 'classic-edu-suite' ), $user->user_email );
		self::detail_row( __( 'Signs in with', 'classic-edu-suite' ), 'google' === $record['provider'] ? __( 'Gmail', 'classic-edu-suite' ) : __( 'Password', 'classic-edu-suite' ) );
		self::detail_row( __( 'Class', 'classic-edu-suite' ), $record['class_label'] );
		self::detail_row( __( 'Date of birth', 'classic-edu-suite' ), $record['dob'] );
		self::detail_row( __( 'Registered', 'classic-edu-suite' ), $user->user_registered );
		self::detail_row( __( 'Parent / guardian', 'classic-edu-suite' ), trim( $contact['name'] . ' · ' . $contact['relation_label'] ) );
		self::detail_row( __( 'Parent number', 'classic-edu-suite' ), $contact['phone_pretty'] );
		self::detail_row( __( 'Parent email', 'classic-edu-suite' ), CES_Accounts_Verification::parent_email( (int) $user->ID ) );
		self::detail_row( __( 'Consent ticked', 'classic-edu-suite' ), $contact['consent'] ? __( 'Yes', 'classic-edu-suite' ) : __( 'No', 'classic-edu-suite' ) );
		self::detail_row( __( 'Parent confirmed', 'classic-edu-suite' ), $record['verified_at'] ? $record['verified_at'] . ' (' . $record['verified_via'] . ')' : '' );

		if ( is_array( $last ) ) {
			self::detail_row(
				__( 'How the request got there', 'classic-edu-suite' ),
				'direct' === CES_Accounts_Verification::delivery( $last )
					? __( 'We messaged the parent ourselves, so the answer came from their own phone or inbox.', 'classic-edu-suite' )
					: __( 'The student forwarded it, so they could read the link and the code. Ring the parent if you need to be certain.', 'classic-edu-suite' )
			);
		}
		self::detail_row( __( 'Approved', 'classic-edu-suite' ), $record['approved_at'] );
		self::detail_row( __( 'Reason given', 'classic-edu-suite' ), $record['decline_reason'] );
		self::detail_row( __( 'Messages stopped by parent', 'classic-edu-suite' ), CES_Accounts_Verification::opted_out( (int) $user->ID ) ? __( 'Yes', 'classic-edu-suite' ) : __( 'No', 'classic-edu-suite' ) );

		if ( ! empty( $record['flags'] ) ) {
			$list = array();

			foreach ( $record['flags'] as $flag ) {
				$list[] = isset( $labels[ $flag ] ) ? $labels[ $flag ] : $flag;
			}

			self::detail_row( __( 'Flagged', 'classic-edu-suite' ), implode( ' · ', $list ) );
		}

		if ( '' !== $portal ) {
			echo '<tr><th scope="row">' . esc_html__( 'Parent’s progress link', 'classic-edu-suite' ) . '</th><td><code>' . esc_html( $portal ) . '</code><p class="description">'
				. esc_html__( 'Private to that parent. Send it only to the number on file.', 'classic-edu-suite' ) . '</p></td></tr>';
		}

		echo '</tbody></table>';

		// Actions.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ces-detail__actions">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="ces_return" value="' . esc_url( self::current_admin_url() ) . '" />';

		echo '<p><label for="ces-reason">' . esc_html__( 'Note or reason (shown to the student when you decline)', 'classic-edu-suite' ) . '</label><br />';
		echo '<input type="text" id="ces-reason" name="ces_reason" class="regular-text" /></p>';

		echo '<p>';
		echo '<button type="submit" name="ces_do" value="approve:' . esc_attr( (string) $user->ID ) . '" class="button button-primary">' . esc_html__( 'Approve', 'classic-edu-suite' ) . '</button> ';
		echo '<button type="submit" name="ces_do" value="confirm:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Confirm by phone', 'classic-edu-suite' ) . '</button> ';
		echo '<button type="submit" name="ces_do" value="decline:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Decline', 'classic-edu-suite' ) . '</button> ';
		echo '<button type="submit" name="ces_do" value="suspend:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Pause access', 'classic-edu-suite' ) . '</button> ';
		echo '<button type="submit" name="ces_do" value="resend:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Ask the parent again', 'classic-edu-suite' ) . '</button> ';
		echo '<button type="submit" name="ces_do" value="reset:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Start over', 'classic-edu-suite' ) . '</button> ';
		echo '<button type="submit" name="ces_do" value="clearflags:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Clear flags', 'classic-edu-suite' ) . '</button>';
		echo '</p>';
		echo '</form>';

		if ( '' !== $contact['phone'] ) {
			$wa = CES_Parent_Contact::wa_link(
				$contact['phone'],
				CES_Accounts_Notify::parent_message( (int) $user->ID, '', (string) get_user_meta( (int) $user->ID, CES_Accounts_Verification::META_TOKEN, true ) )
			);

			echo '<p><a class="button" href="' . esc_url( $wa ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open WhatsApp to this parent', 'classic-edu-suite' ) . '</a>';
			echo ' <span class="description">' . esc_html__( 'The request is written for you. Read it, then send.', 'classic-edu-suite' ) . '</span></p>';
		}

		// Audit trail.
		echo '<h3>' . esc_html__( 'History', 'classic-edu-suite' ) . '</h3>';

		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'Nothing recorded yet.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped ces-events"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'When', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'What', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'By', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Detail', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'IP', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			$actor = (int) $event['actor_id'];
			$who   = __( 'the parent or the system', 'classic-edu-suite' );

			if ( $actor > 0 ) {
				$actor_user = get_userdata( $actor );
				$who        = $actor_user ? $actor_user->display_name : sprintf( '#%d', $actor );
			}

			echo '<tr>';
			echo '<td>' . esc_html( (string) $event['created_at'] ) . '</td>';
			echo '<td><code>' . esc_html( (string) $event['event'] ) . '</code></td>';
			echo '<td>' . esc_html( $who ) . '</td>';
			echo '<td>' . esc_html( (string) $event['detail'] ) . ( '' !== (string) $event['channel'] ? ' <em>(' . esc_html( (string) $event['channel'] ) . ')</em>' : '' ) . '</td>';
			echo '<td><span class="ces-muted">' . esc_html( (string) $event['ip_address'] ) . '</span></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One row of the detail table, skipped when empty.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private static function detail_row( $label, $value ) {
		if ( '' === trim( (string) $value ) ) {
			return;
		}

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/* ---------------------------------------------------------------------
	 * Querying.
	 * ------------------------------------------------------------------ */

	/**
	 * Students matching a status filter.
	 *
	 * @param array $args {
	 *     @type string $status waiting | flagged | none | all | a status slug.
	 *     @type string $search Name, email or parent number fragment.
	 *     @type int    $number Page size.
	 *     @type int    $offset Offset.
	 * }
	 * @return array `array( 'users' => WP_User[], 'total' => int )`
	 */
	public static function query( array $args = array() ) {
		$args = array_merge(
			array(
				'status' => 'waiting',
				'search' => '',
				'number' => self::PER_PAGE,
				'offset' => 0,
			),
			$args
		);

		$query_args = array(
			'number'      => max( 1, min( 500, (int) $args['number'] ) ),
			'offset'      => max( 0, (int) $args['offset'] ),
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'count_total' => true,
			'fields'      => 'all',
		);

		$search = trim( (string) $args['search'] );

		if ( '' !== $search ) {
			$digits = preg_replace( '/\D/', '', $search );

			// A number typed in is almost certainly a parent's.
			if ( strlen( $digits ) >= 7 ) {
				$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => CES_Parent_Contact::META_PHONE,
						'value'   => $digits,
						'compare' => 'LIKE',
					),
				);
			} else {
				$query_args['search']         = '*' . esc_attr( $search ) . '*';
				$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
			}
		}

		$status = sanitize_key( (string) $args['status'] );
		$meta   = array();

		if ( 'waiting' === $status ) {
			$meta[] = array(
				'key'     => CES_Accounts_Status::META_STATUS,
				'value'   => array( 'onboarding', 'awaiting_parent', 'awaiting_admin' ),
				'compare' => 'IN',
			);
		} elseif ( 'flagged' === $status ) {
			$meta[] = array(
				'key'     => CES_Accounts_Status::META_FLAGS,
				'compare' => 'EXISTS',
			);
		} elseif ( 'none' === $status ) {
			$meta[] = array(
				'key'     => CES_Accounts_Status::META_STATUS,
				'compare' => 'NOT EXISTS',
			);
		} elseif ( 'all' !== $status && array_key_exists( $status, CES_Accounts_Status::statuses() ) ) {
			$meta[] = array(
				'key'   => CES_Accounts_Status::META_STATUS,
				'value' => $status,
			);
		}

		if ( ! empty( $meta ) ) {
			if ( isset( $query_args['meta_query'] ) ) {
				$query_args['meta_query'] = array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'relation' => 'AND' ),
					$query_args['meta_query'],
					$meta
				);
			} else {
				$query_args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}
		}

		$query = new WP_User_Query( $query_args );

		return array(
			'users' => (array) $query->get_results(),
			'total' => (int) $query->get_total(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Handlers.
	 * ------------------------------------------------------------------ */

	/**
	 * Apply a single-row button or a bulk action.
	 *
	 * @return void
	 */
	public static function handle_action() {
		self::guard();
		check_admin_referer( self::ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$single = isset( $_POST['ces_do'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_do'] ) ) : '';
		$bulk   = isset( $_POST['ces_bulk'] ) ? sanitize_key( wp_unslash( $_POST['ces_bulk'] ) ) : '';
		$reason = isset( $_POST['ces_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_reason'] ) ) : '';
		$ids    = isset( $_POST['users'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['users'] ) ) : array();
		$return = isset( $_POST['ces_return'] ) ? esc_url_raw( wp_unslash( $_POST['ces_return'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$done = 0;
		$what = '';

		if ( '' !== $single && false !== strpos( $single, ':' ) ) {
			list( $what, $who ) = explode( ':', $single, 2 );

			$what = sanitize_key( $what );
			$done = self::apply( $what, array( absint( $who ) ), $reason );
		} elseif ( '' !== $bulk && ! empty( $ids ) ) {
			$what = $bulk;
			$done = self::apply( $bulk, $ids, $reason );
		}

		$target = $return ? $return : add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );

		$target = add_query_arg(
			array(
				'ces_msg'   => $done > 0 ? sanitize_key( $what ) : 'none',
				'ces_count' => (int) $done,
			),
			$target
		);

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Carry out one action against a list of students.
	 *
	 * @param string $what   Action slug.
	 * @param int[]  $ids    Student IDs.
	 * @param string $reason Note or reason.
	 * @return int How many were acted on.
	 */
	private static function apply( $what, array $ids, $reason = '' ) {
		$actor = get_current_user_id();
		$done  = 0;

		foreach ( $ids as $user_id ) {
			$user_id = absint( $user_id );

			if ( ! $user_id || ! get_userdata( $user_id ) ) {
				continue;
			}

			switch ( $what ) {
				case 'approve':
					CES_Accounts_Status::clear_flags( $user_id );
					CES_Accounts_Status::approve( $user_id, array( 'actor_id' => $actor, 'channel' => 'staff' ) );
					++$done;
					break;

				case 'confirm':
					CES_Accounts_Verification::confirm_by_staff(
						$user_id,
						'' !== $reason ? $reason : __( 'Confirmed with the parent by a member of staff.', 'classic-edu-suite' ),
						$actor
					);
					++$done;
					break;

				case 'decline':
					CES_Accounts_Status::decline(
						$user_id,
						'' !== $reason ? $reason : __( 'A member of staff could not confirm your parent or guardian.', 'classic-edu-suite' ),
						array( 'actor_id' => $actor, 'channel' => 'staff' )
					);
					++$done;
					break;

				case 'suspend':
					if ( '' !== $reason ) {
						update_user_meta( $user_id, CES_Accounts_Status::META_DECLINE_REASON, $reason );
					}

					CES_Accounts_Status::set_status(
						$user_id,
						'suspended',
						array(
							'actor_id' => $actor,
							'channel'  => 'staff',
							'detail'   => $reason,
							'event'    => 'suspended',
						)
					);
					++$done;
					break;

				case 'resend':
					$result = CES_Accounts_Verification::resend( $user_id );

					if ( ! is_wp_error( $result ) ) {
						++$done;
					}
					break;

				case 'reset':
					CES_Accounts_Status::reset( $user_id, array( 'actor_id' => $actor, 'detail' => $reason ) );
					++$done;
					break;

				case 'clearflags':
					CES_Accounts_Status::clear_flags( $user_id );
					CES_Accounts_Status::log( $user_id, 'flags_cleared', array( 'actor_id' => $actor, 'detail' => $reason ) );
					++$done;
					break;
			}
		}

		CES_Accounts_Status::flush_counts();

		return $done;
	}

	/**
	 * Export the current filter as CSV.
	 *
	 * The file is other people's phone numbers. Download it when you need it,
	 * keep it off shared drives, and delete it afterwards.
	 *
	 * @return void
	 */
	public static function handle_export() {
		self::guard();
		check_admin_referer( 'ces_accounts_export' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'waiting';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = self::query(
			array(
				'status' => $status,
				'search' => $search,
				'number' => 500,
				'offset' => 0,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="student-approvals-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				__( 'Student', 'classic-edu-suite' ),
				__( 'Email', 'classic-edu-suite' ),
				__( 'Class', 'classic-edu-suite' ),
				__( 'Status', 'classic-edu-suite' ),
				__( 'Parent name', 'classic-edu-suite' ),
				__( 'Relationship', 'classic-edu-suite' ),
				__( 'Parent number', 'classic-edu-suite' ),
				__( 'Parent email', 'classic-edu-suite' ),
				__( 'Parent confirmed', 'classic-edu-suite' ),
				__( 'Confirmed via', 'classic-edu-suite' ),
				__( 'Approved at', 'classic-edu-suite' ),
				__( 'Flags', 'classic-edu-suite' ),
				__( 'Registered', 'classic-edu-suite' ),
			)
		);

		foreach ( $result['users'] as $user ) {
			$record  = CES_Accounts_Status::record( (int) $user->ID );
			$contact = CES_Parent_Contact::get( (int) $user->ID );

			fputcsv(
				$out,
				array(
					$user->display_name,
					$user->user_email,
					$record['class_label'],
					$record['status_label'],
					$contact['name'],
					$contact['relation_label'],
					$contact['phone'],
					CES_Accounts_Verification::parent_email( (int) $user->ID ),
					$record['verified_at'],
					$record['verified_via'],
					$record['approved_at'],
					implode( ' ', $record['flags'] ),
					$user->user_registered,
				)
			);
		}

		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Users list column.
	 * ------------------------------------------------------------------ */

	/**
	 * Add an approval column to the Users screen.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function users_column( $columns ) {
		if ( ! is_array( $columns ) || ! CES_Accounts_Status::approval_enabled() ) {
			return $columns;
		}

		$columns['ces_approval'] = __( 'Approval', 'classic-edu-suite' );

		return $columns;
	}

	/**
	 * Fill it in.
	 *
	 * @param string $value   Value so far.
	 * @param string $column  Column name.
	 * @param int    $user_id User.
	 * @return string
	 */
	public static function users_column_value( $value, $column, $user_id ) {
		if ( 'ces_approval' !== $column ) {
			return $value;
		}

		if ( ! CES_Helpers::can_manage( self::CAP ) ) {
			return '—';
		}

		$status = CES_Accounts_Status::raw_status( (int) $user_id );

		if ( '' === $status ) {
			return '<span class="ces-muted">' . esc_html__( 'no record', 'classic-edu-suite' ) . '</span>';
		}

		$link = add_query_arg( array( 'page' => self::PAGE, 'view' => (int) $user_id ), admin_url( 'admin.php' ) );

		return '<a href="' . esc_url( $link ) . '"><span class="ces-pill ces-pill--' . esc_attr( self::pill_class( $status ) ) . '">'
			. esc_html( CES_Accounts_Status::label( $status ) ) . '</span></a>';
	}

	/* ---------------------------------------------------------------------
	 * Small things.
	 * ------------------------------------------------------------------ */

	/**
	 * The admin notice after an action.
	 *
	 * @param string $slug Message slug.
	 * @return void
	 */
	private static function render_notice( $slug ) {
		if ( '' === $slug ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$count = isset( $_GET['ces_count'] ) ? absint( $_GET['ces_count'] ) : 0;

		$messages = array(
			'approve'    => __( '%d account approved.', 'classic-edu-suite' ),
			'confirm'    => __( '%d parent recorded as confirmed by phone.', 'classic-edu-suite' ),
			'decline'    => __( '%d account declined.', 'classic-edu-suite' ),
			'suspend'    => __( '%d account paused.', 'classic-edu-suite' ),
			'resend'     => __( 'Request sent again for %d account.', 'classic-edu-suite' ),
			'reset'      => __( '%d signup started over.', 'classic-edu-suite' ),
			'clearflags' => __( 'Flags cleared on %d account.', 'classic-edu-suite' ),
		);

		if ( 'none' === $slug || ! isset( $messages[ $slug ] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>'
				. esc_html__( 'Nothing was changed. Choose an action and tick at least one student.', 'classic-edu-suite' )
				. '</p></div>';

			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html( sprintf( $messages[ $slug ], $count ) )
			. '</p></div>';
	}

	/**
	 * The URL of the screen being viewed, for the return field.
	 *
	 * @return string
	 */
	private static function current_admin_url() {
		$args = array( 'page' => self::PAGE );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- rebuilding the current view's own URL.
		foreach ( array( 'status', 's', 'paged', 'view' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
