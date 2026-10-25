<?php
/**
 * Two admin screens: consultant applications, and chat moderation.
 *
 * Both are deliberately plain lists with buttons. The moderation screen is
 * the one that matters most and it is built around one question — what needs
 * a human right now — so the queue is held messages and reported messages,
 * newest first, with the room and the author one click away.
 *
 * `ces_manage_classroom` and `ces_moderate_chat` are administrator
 * capabilities. Instructors get `ces_moderate_chat` as well, because keeping
 * an eye on a student room is ordinary teaching work; approving an outside
 * tutor is not.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and their post handlers.
 */
class CES_Classroom_Admin {

	/**
	 * Consultants screen slug.
	 */
	const PAGE = 'ces-consultants';

	/**
	 * Moderation screen slug.
	 */
	const PAGE_CHAT = 'ces-chat-moderation';

	/**
	 * Capability for the consultants screen.
	 */
	const CAP = 'ces_manage_classroom';

	/**
	 * Capability for the moderation screen.
	 */
	const CAP_CHAT = 'ces_moderate_chat';

	/**
	 * admin-post action for both screens.
	 */
	const ACTION = 'ces_classroom_action';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ces_admin_submenus', array( __CLASS__, 'register_submenus' ) );
		add_filter( 'ces_overview_cards', array( __CLASS__, 'overview_cards' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Add both screens to the Classic EDU menu.
	 *
	 * @param array $items Submenus so far.
	 * @return array
	 */
	public static function register_submenus( $items ) {
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$counts  = CES_Consultant::counts();
		$pending = isset( $counts['pending'] ) ? (int) $counts['pending'] : 0;

		$items[] = array(
			'slug'       => self::PAGE,
			'title'      => __( 'Consultants', 'classic-edu-suite' ),
			'menu_title' => $pending > 0
				? __( 'Consultants', 'classic-edu-suite' ) . ' <span class="awaiting-mod"><span class="pending-count">' . number_format_i18n( $pending ) . '</span></span>'
				: __( 'Consultants', 'classic-edu-suite' ),
			'capability' => self::CAP,
			'callback'   => array( __CLASS__, 'render_consultants' ),
			'position'   => 46,
		);

		$queue = CES_Chat::queue_count();

		$items[] = array(
			'slug'       => self::PAGE_CHAT,
			'title'      => __( 'Chat moderation', 'classic-edu-suite' ),
			'menu_title' => $queue > 0
				? __( 'Chat', 'classic-edu-suite' ) . ' <span class="awaiting-mod"><span class="pending-count">' . number_format_i18n( $queue ) . '</span></span>'
				: __( 'Chat', 'classic-edu-suite' ),
			'capability' => self::CAP_CHAT,
			'callback'   => array( __CLASS__, 'render_chat' ),
			'position'   => 47,
		);

		return $items;
	}

	/**
	 * Overview cards.
	 *
	 * @param array $cards Cards so far.
	 * @return array
	 */
	public static function overview_cards( $cards ) {
		if ( ! is_array( $cards ) ) {
			$cards = array();
		}

		$counts = CES_Consultant::counts();

		$cards[] = array(
			'label' => __( 'Consultants teaching here', 'classic-edu-suite' ),
			'value' => number_format_i18n( isset( $counts['approved'] ) ? (int) $counts['approved'] : 0 ),
			'href'  => admin_url( 'admin.php?page=' . self::PAGE ),
		);

		$queue = CES_Chat::queue_count();

		if ( $queue > 0 ) {
			$cards[] = array(
				'label' => __( 'Chat messages needing review', 'classic-edu-suite' ),
				'value' => number_format_i18n( $queue ),
				'href'  => admin_url( 'admin.php?page=' . self::PAGE_CHAT ),
			);
		}

		return $cards;
	}

	/* ---------------------------------------------------------------------
	 * Consultants.
	 * ------------------------------------------------------------------ */

	/**
	 * The consultants screen.
	 *
	 * @return void
	 */
	public static function render_consultants() {
		if ( ! CES_Helpers::can_manage( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage consultants.', 'classic-edu-suite' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$notice = isset( $_GET['ces_msg'] ) ? sanitize_key( wp_unslash( $_GET['ces_msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$result   = CES_Consultant::query( $status, $per_page, ( $paged - 1 ) * $per_page );
		$counts   = CES_Consultant::counts();

		echo '<div class="wrap ces-wrap ces-consultants">';
		echo '<h1>' . esc_html__( 'Educational consultants', 'classic-edu-suite' ) . '</h1>';
		echo '<p class="ces-sub">' . esc_html__( 'Outside tutors who host their own video lessons here and share a private link with their own students.', 'classic-edu-suite' ) . '</p>';

		self::notice( $notice );

		echo '<div class="ces-cards">';

		foreach ( array(
			'pending'   => __( 'Waiting for review', 'classic-edu-suite' ),
			'approved'  => __( 'Approved', 'classic-edu-suite' ),
			'declined'  => __( 'Declined', 'classic-edu-suite' ),
			'suspended' => __( 'Suspended', 'classic-edu-suite' ),
		) as $slug => $label ) {
			echo '<div class="ces-card">';
			echo '<span class="ces-card__value">' . esc_html( number_format_i18n( isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0 ) ) . '</span>';
			echo '<span class="ces-card__label">' . esc_html( $label ) . '</span>';
			echo '<a class="ces-card__link" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'status' => $slug ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Show', 'classic-edu-suite' ) . '</a>';
			echo '</div>';
		}

		echo '</div>';

		// Filter.
		echo '<form method="get" class="ces-parents-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		echo '<select name="status">';

		foreach ( array_merge( CES_Consultant::statuses(), array( 'all' => __( 'Everyone who applied', 'classic-edu-suite' ) ) ) as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $status, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select> ';
		submit_button( __( 'Filter', 'classic-edu-suite' ), 'secondary', '', false );
		echo '</form>';

		if ( empty( $result['users'] ) ) {
			echo '<p class="ces-empty">' . esc_html__( 'Nobody here.', 'classic-edu-suite' ) . '</p></div>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="ces_return" value="' . esc_url( self::current_url( self::PAGE ) ) . '" />';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Applicant', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'About', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Classes', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $result['users'] as $user ) {
			$profile = CES_Consultant::profile( (int) $user->ID, true );
			$stats   = CES_Consultant::stats( (int) $user->ID );

			echo '<tr>';

			echo '<td><strong>' . esc_html( $profile['name'] ) . '</strong><br />';
			echo '<span class="ces-muted">' . esc_html( $profile['email'] ) . '</span><br />';
			echo '<span class="ces-muted">' . esc_html( $profile['phone'] ) . '</span>';

			if ( '' !== $profile['site'] ) {
				echo '<br /><a href="' . esc_url( $profile['site'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'their link', 'classic-edu-suite' ) . '</a>';
			}

			echo '<br /><a href="' . esc_url( get_edit_user_link( (int) $user->ID ) ) . '">' . esc_html__( 'Edit user', 'classic-edu-suite' ) . '</a>';
			echo '</td>';

			echo '<td>';
			echo '<strong>' . esc_html( $profile['subjects'] ) . '</strong>';

			if ( '' !== $profile['org'] ) {
				echo '<br /><span class="ces-muted">' . esc_html( $profile['org'] ) . '</span>';
			}

			echo '<p>' . esc_html( wp_trim_words( $profile['bio'], 40 ) ) . '</p>';

			if ( '' !== $profile['experience'] ) {
				echo '<details><summary>' . esc_html__( 'What they told us', 'classic-edu-suite' ) . '</summary><p>' . esc_html( $profile['experience'] ) . '</p></details>';
			}

			echo '</td>';

			echo '<td>';
			printf(
				/* translators: 1: classes, 2: students, 3: lessons. */
				esc_html__( '%1$d classes, %2$d students, %3$d lessons', 'classic-edu-suite' ),
				(int) $stats['classes'],
				(int) $stats['students'],
				(int) $stats['lessons']
			);
			echo '</td>';

			echo '<td><span class="ces-pill ces-pill--' . esc_attr( self::pill( $profile['status'] ) ) . '">' . esc_html( $profile['status_label'] ) . '</span>';

			if ( '' !== $profile['reason'] ) {
				echo '<br /><span class="ces-muted">' . esc_html( $profile['reason'] ) . '</span>';
			}

			echo '</td>';

			echo '<td>';

			if ( 'approved' !== $profile['status'] ) {
				echo '<button type="submit" name="ces_do" value="approve:' . esc_attr( (string) $user->ID ) . '" class="button button-primary">' . esc_html__( 'Approve', 'classic-edu-suite' ) . '</button> ';
			}

			if ( 'approved' === $profile['status'] ) {
				echo '<button type="submit" name="ces_do" value="suspend:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Suspend', 'classic-edu-suite' ) . '</button> ';
			}

			if ( 'declined' !== $profile['status'] ) {
				echo '<button type="submit" name="ces_do" value="decline:' . esc_attr( (string) $user->ID ) . '" class="button">' . esc_html__( 'Decline', 'classic-edu-suite' ) . '</button>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p><label for="ces-reason">' . esc_html__( 'Reason, shown to them when you decline or suspend', 'classic-edu-suite' ) . '</label><br />';
		echo '<input type="text" id="ces-reason" name="ces_reason" class="regular-text" /></p>';

		echo '</form>';

		$pages = (int) ceil( $result['total'] / $per_page );

		if ( $pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'total'   => $pages,
						'current' => $paged,
					)
				)
			);
			echo '</div></div>';
		}

		self::render_classes_table();

		echo '</div>';
	}

	/**
	 * Every class on the site, for oversight.
	 *
	 * @return void
	 */
	private static function render_classes_table() {
		$classes = get_posts(
			array(
				'post_type'      => CES_Classroom_Cpt::CLASS_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		echo '<h2>' . esc_html__( 'Classes', 'classic-edu-suite' ) . '</h2>';

		if ( empty( $classes ) ) {
			echo '<p>' . esc_html__( 'No classes yet.', 'classic-edu-suite' ) . '</p>';

			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Class', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Tutor', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Students', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Lessons', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Chat', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $classes as $class_id ) {
			$owner = get_userdata( CES_Classroom_Cpt::owner( (int) $class_id ) );

			echo '<tr>';
			echo '<td><a href="' . esc_url( (string) get_permalink( (int) $class_id ) ) . '">' . esc_html( get_the_title( (int) $class_id ) ) . '</a>';
			echo '<br /><code>' . esc_html( CES_Classroom_Cpt::code( (int) $class_id ) ) . '</code>';

			if ( CES_Classroom_Cpt::is_archived( (int) $class_id ) ) {
				echo ' <span class="ces-pill">' . esc_html__( 'archived', 'classic-edu-suite' ) . '</span>';
			}

			echo '</td>';
			echo '<td>' . esc_html( $owner ? $owner->display_name : __( 'unknown', 'classic-edu-suite' ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( CES_Classroom_Enrolment::count( (int) $class_id ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( count( CES_Classroom_Cpt::lessons( (int) $class_id ) ) ) ) . '</td>';
			echo '<td>';

			if ( CES_Classroom_Cpt::chat_enabled( (int) $class_id ) ) {
				echo '<a href="' . esc_url(
					add_query_arg(
						array(
							'page' => self::PAGE_CHAT,
							'room' => CES_Chat::class_room( (int) $class_id ),
						),
						admin_url( 'admin.php' )
					)
				) . '">' . esc_html__( 'read', 'classic-edu-suite' ) . '</a>';
			} else {
				echo '<span class="ces-muted">' . esc_html__( 'off', 'classic-edu-suite' ) . '</span>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Chat moderation.
	 * ------------------------------------------------------------------ */

	/**
	 * The moderation screen.
	 *
	 * @return void
	 */
	public static function render_chat() {
		if ( ! CES_Helpers::can_manage( self::CAP_CHAT ) ) {
			wp_die( esc_html__( 'You do not have permission to moderate chat.', 'classic-edu-suite' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$room   = isset( $_GET['room'] ) ? CES_Chat::sanitize_room( wp_unslash( $_GET['room'] ) ) : '';
		$notice = isset( $_GET['ces_msg'] ) ? sanitize_key( wp_unslash( $_GET['ces_msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap ces-wrap ces-chat-admin">';
		echo '<h1>' . esc_html__( 'Chat moderation', 'classic-edu-suite' ) . '</h1>';

		self::notice( $notice );

		$queue = CES_Chat::queue_count();

		echo '<p class="ces-sub">';

		if ( $queue > 0 ) {
			echo esc_html(
				sprintf(
					/* translators: %d: number of messages. */
					_n( '%d message needs a decision.', '%d messages need a decision.', $queue, 'classic-edu-suite' ),
					$queue
				)
			);
		} else {
			echo esc_html__( 'Nothing is waiting. Below is the queue when there is one, and you can read any room directly.', 'classic-edu-suite' );
		}

		echo '</p>';

		// Room picker.
		echo '<form method="get" class="ces-parents-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_CHAT ) . '" />';
		echo '<select name="room">';
		echo '<option value="">' . esc_html__( 'The queue', 'classic-edu-suite' ) . '</option>';

		foreach ( CES_Chat::lobbies() as $slug => $lobby ) {
			$value = CES_Chat::lobby_room( $slug );
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $room, $value, false ) . '>' . esc_html( (string) $lobby['label'] ) . '</option>';
		}

		echo '</select> ';
		submit_button( __( 'Open', 'classic-edu-suite' ), 'secondary', '', false );
		echo '</form>';

		$rows = '' !== $room
			? array_reverse( self::room_rows( $room ) )
			: CES_Chat::queue( 60 );

		if ( empty( $rows ) ) {
			echo '<p class="ces-empty">' . esc_html__( 'Nothing to show.', 'classic-edu-suite' ) . '</p></div>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="ces_return" value="' . esc_url( self::current_url( self::PAGE_CHAT ) ) . '" />';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col" style="width:150px">' . esc_html__( 'When', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col" style="width:180px">' . esc_html__( 'Who', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Message', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col" style="width:160px">' . esc_html__( 'Room', 'classic-edu-suite' ) . '</th>';
		echo '<th scope="col" style="width:220px">' . esc_html__( 'Actions', 'classic-edu-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$user = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;

			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';

			echo '<td>';

			if ( $user ) {
				echo '<a href="' . esc_url( get_edit_user_link( (int) $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a>';
			} else {
				echo esc_html( '' !== (string) $row['author_name'] ? (string) $row['author_name'] : __( 'the system', 'classic-edu-suite' ) );
			}

			echo '<br /><span class="ces-tag">' . esc_html( (string) $row['author_kind'] ) . '</span>';
			echo '<br /><span class="ces-muted">' . esc_html( (string) $row['ip_address'] ) . '</span>';
			echo '</td>';

			echo '<td>' . esc_html( (string) $row['body'] );

			if ( (int) $row['flags'] > 0 ) {
				echo '<br /><span class="ces-flag">⚑ ' . esc_html(
					sprintf(
						/* translators: %d: number of reports. */
						_n( '%d report', '%d reports', (int) $row['flags'], 'classic-edu-suite' ),
						(int) $row['flags']
					)
				) . '</span>';
			}

			if ( CES_Chat::VISIBLE !== (string) $row['status'] ) {
				echo ' <span class="ces-pill ces-pill--wait">' . esc_html( (string) $row['status'] ) . '</span>';
			}

			echo '</td>';

			echo '<td>' . esc_html( CES_Chat::room_label( (string) $row['room'] ) ) . '<br /><code>' . esc_html( (string) $row['room'] ) . '</code></td>';

			echo '<td>';

			if ( CES_Chat::VISIBLE !== (string) $row['status'] ) {
				echo '<button type="submit" name="ces_do" value="show:' . esc_attr( (string) $row['id'] ) . '" class="button button-primary">' . esc_html__( 'Allow', 'classic-edu-suite' ) . '</button> ';
			}

			if ( CES_Chat::HIDDEN !== (string) $row['status'] ) {
				echo '<button type="submit" name="ces_do" value="hide:' . esc_attr( (string) $row['id'] ) . '" class="button">' . esc_html__( 'Hide', 'classic-edu-suite' ) . '</button> ';
			}

			echo '<button type="submit" name="ces_do" value="drop:' . esc_attr( (string) $row['id'] ) . '" class="button">' . esc_html__( 'Delete', 'classic-edu-suite' ) . '</button>';
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table></form>';
		echo '</div>';
	}

	/**
	 * Raw rows for one room, newest first.
	 *
	 * @param string $room Room string.
	 * @return array[]
	 */
	private static function room_rows( $room ) {
		global $wpdb;

		$table = CES_Chat::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE room = %s ORDER BY id DESC LIMIT 80",
				$room
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? array_reverse( $rows ) : array();
	}

	/* ---------------------------------------------------------------------
	 * Handlers.
	 * ------------------------------------------------------------------ */

	/**
	 * Apply a button from either screen.
	 *
	 * @return void
	 */
	public static function handle_action() {
		check_admin_referer( self::ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$do     = isset( $_POST['ces_do'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_do'] ) ) : '';
		$reason = isset( $_POST['ces_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_reason'] ) ) : '';
		$return = isset( $_POST['ces_return'] ) ? esc_url_raw( wp_unslash( $_POST['ces_return'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $do || false === strpos( $do, ':' ) ) {
			wp_safe_redirect( $return ? $return : admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
		}

		list( $what, $target ) = explode( ':', $do, 2 );

		$what   = sanitize_key( $what );
		$target = absint( $target );
		$done   = false;

		switch ( $what ) {
			case 'approve':
			case 'decline':
			case 'suspend':
				if ( ! CES_Helpers::can_manage( self::CAP ) ) {
					wp_die( esc_html__( 'You do not have permission to do that.', 'classic-edu-suite' ), '', array( 'response' => 403 ) );
				}

				if ( 'approve' === $what ) {
					$done = CES_Consultant::approve( $target );
				} else {
					$done = CES_Consultant::refuse( $target, $reason, 'suspend' === $what ? 'suspended' : 'declined' );
				}
				break;

			case 'show':
			case 'hide':
			case 'drop':
				if ( ! CES_Helpers::can_manage( self::CAP_CHAT ) ) {
					wp_die( esc_html__( 'You do not have permission to do that.', 'classic-edu-suite' ), '', array( 'response' => 403 ) );
				}

				$map  = array(
					'show' => CES_Chat::VISIBLE,
					'hide' => CES_Chat::HIDDEN,
					'drop' => 'deleted',
				);
				$done = CES_Chat::set_status( $target, $map[ $what ] );
				break;
		}

		$target_url = $return ? $return : admin_url( 'admin.php?page=' . self::PAGE );

		wp_safe_redirect( add_query_arg( 'ces_msg', $done ? $what : 'none', $target_url ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Small things.
	 * ------------------------------------------------------------------ */

	/**
	 * A notice after an action.
	 *
	 * @param string $slug Message slug.
	 * @return void
	 */
	private static function notice( $slug ) {
		if ( '' === $slug ) {
			return;
		}

		$messages = array(
			'approve' => __( 'Approved. They can open their Studio now.', 'classic-edu-suite' ),
			'decline' => __( 'Declined, and they have been told.', 'classic-edu-suite' ),
			'suspend' => __( 'Suspended. Their classes are closed.', 'classic-edu-suite' ),
			'show'    => __( 'Message allowed.', 'classic-edu-suite' ),
			'hide'    => __( 'Message hidden.', 'classic-edu-suite' ),
			'drop'    => __( 'Message deleted.', 'classic-edu-suite' ),
		);

		if ( ! isset( $messages[ $slug ] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Nothing was changed.', 'classic-edu-suite' ) . '</p></div>';

			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $slug ] ) . '</p></div>';
	}

	/**
	 * CSS suffix for a status pill.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function pill( $status ) {
		switch ( $status ) {
			case 'approved':
				return 'ok';

			case 'declined':
			case 'suspended':
				return 'bad';

			default:
				return 'wait';
		}
	}

	/**
	 * The URL of the screen being viewed.
	 *
	 * @param string $page Page slug.
	 * @return string
	 */
	private static function current_url( $page ) {
		$args = array( 'page' => $page );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- rebuilding the current view's own URL.
		foreach ( array( 'status', 'room', 'paged' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
