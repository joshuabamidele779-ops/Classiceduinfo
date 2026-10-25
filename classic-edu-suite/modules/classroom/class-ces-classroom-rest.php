<?php
/**
 * Every REST route the classroom owns.
 *
 * Three prefixes inside `ces/v1`, kept separate because they are read at
 * very different rates: `/class/*` is ordinary CRUD, `/chat/*` is polled
 * every few seconds by anyone with a room open, and `/session/*` is polled
 * harder still by everyone in a live room.
 *
 * The live room deliberately has **one** polling endpoint,
 * `/session/<id>/state`, which returns playback, chat, hands, the poll and
 * the head-count together. Four endpoints would be four round trips every
 * four seconds on a connection that can barely afford one.
 *
 * Captions come back as a JSON string rather than as a `text/vtt` response:
 * the player turns them into a blob URL for the `<track>` element, which
 * keeps the whole thing inside the REST permission check instead of needing
 * a second guarded file endpoint.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route registration and handlers.
 */
class CES_Classroom_Rest {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * The namespace.
	 *
	 * @return string
	 */
	private static function ns() {
		return defined( 'CES_REST_NAMESPACE' ) ? CES_REST_NAMESPACE : 'ces/v1';
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = self::ns();

		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		// ---------------- Classes ----------------

		register_rest_route(
			$ns,
			'/class/classes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_consult' ),
				'callback'            => array( __CLASS__, 'create_class' ),
				'args'                => array(
					'title'       => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/class/classes/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_consult' ),
				'callback'            => array( __CLASS__, 'update_class' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			$ns,
			'/class/classes/(?P<id>\d+)/lessons',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_consult' ),
				'callback'            => array( __CLASS__, 'set_class_lessons' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			$ns,
			'/class/classes/(?P<id>\d+)/roster',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'can_consult' ),
				'callback'            => array( __CLASS__, 'get_roster' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			$ns,
			'/class/classes/(?P<id>\d+)/members/(?P<user>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_consult' ),
				'callback'            => array( __CLASS__, 'update_member' ),
				'args'                => array(
					'id'     => $id_arg['id'],
					'user'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'action' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/class/join',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'callback'            => array( __CLASS__, 'join_class' ),
				'args'                => array(
					'code' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/class/classes/(?P<id>\d+)/leave',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'callback'            => array( __CLASS__, 'leave_class' ),
				'args'                => $id_arg,
			)
		);

		// ---------------- Lessons ----------------

		register_rest_route(
			$ns,
			'/class/lessons',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_consult' ),
				'callback'            => array( __CLASS__, 'create_lesson' ),
				'args'                => array(
					'title'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'class_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		foreach ( array( 'plan', 'chapters', 'captions', 'checkpoints', 'publish', 'thumbnail', 'delete' ) as $action ) {
			register_rest_route(
				$ns,
				'/class/lessons/(?P<id>\d+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( __CLASS__, 'can_consult' ),
					'callback'            => array( __CLASS__, 'lesson_action' ),
					'args'                => array_merge(
						$id_arg,
						array(
							'ces_action' => array(
								'type'    => 'string',
								'default' => $action,
							),
						)
					),
				)
			);
		}

		register_rest_route(
			$ns,
			'/class/lessons/(?P<id>\d+)/review',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'can_consult' ),
				'callback'            => array( __CLASS__, 'get_review' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			$ns,
			'/class/lessons/(?P<id>\d+)/watch',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'callback'            => array( __CLASS__, 'get_watch_extras' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			$ns,
			'/class/lessons/(?P<id>\d+)/answer',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'callback'            => array( __CLASS__, 'answer_checkpoint' ),
				'args'                => array_merge(
					$id_arg,
					array(
						'index'  => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'answer' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);

		// ---------------- Chat ----------------

		register_rest_route(
			$ns,
			'/chat/messages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( __CLASS__, 'logged_in' ),
					'callback'            => array( __CLASS__, 'get_messages' ),
					'args'                => array(
						'room'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'after' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( __CLASS__, 'logged_in' ),
					'callback'            => array( __CLASS__, 'post_message' ),
					'args'                => array(
						'room'     => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'body'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'reply_to' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/chat/messages/(?P<id>\d+)/report',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'callback'            => array( __CLASS__, 'report_message' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			$ns,
			'/chat/messages/(?P<id>\d+)/moderate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_moderate' ),
				'callback'            => array( __CLASS__, 'moderate_message' ),
				'args'                => array_merge(
					$id_arg,
					array(
						'status' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					)
				),
			)
		);

		// ---------------- Live sessions ----------------

		register_rest_route(
			$ns,
			'/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_consult' ),
				'callback'            => array( __CLASS__, 'create_session' ),
				'args'                => array(
					'class_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/session/(?P<id>\d+)/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'callback'            => array( __CLASS__, 'session_state' ),
				'args'                => array_merge(
					$id_arg,
					array(
						'after' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'secs'  => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);

		foreach ( array( 'start', 'end', 'playback', 'hand', 'poll', 'vote', 'clear-hands' ) as $action ) {
			register_rest_route(
				$ns,
				'/session/(?P<id>\d+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( __CLASS__, 'logged_in' ),
					'callback'            => array( __CLASS__, 'session_action' ),
					'args'                => array_merge(
						$id_arg,
						array(
							'ces_action' => array(
								'type'    => 'string',
								'default' => $action,
							),
						)
					),
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Permission callbacks.
	 * ------------------------------------------------------------------ */

	/**
	 * Any signed-in user.
	 *
	 * @return bool
	 */
	public static function logged_in() {
		return is_user_logged_in() && CES_Helpers::enabled( 'classroom_enabled' );
	}

	/**
	 * A consultant or staff.
	 *
	 * @return bool
	 */
	public static function can_consult() {
		return self::logged_in() && CES_Consultant::is_consultant();
	}

	/**
	 * A chat moderator.
	 *
	 * @return bool
	 */
	public static function can_moderate() {
		return self::logged_in() && current_user_can( 'ces_moderate_chat' );
	}

	/**
	 * Confirm the current user owns a class, or is staff.
	 *
	 * @param int $class_id Class.
	 * @return true|WP_Error
	 */
	private static function own_class( $class_id ) {
		$class_id = (int) $class_id;

		if ( ! CES_Classroom_Cpt::is_class( $class_id ) ) {
			return new WP_Error( 'ces_class_missing', __( 'That class could not be found.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $class_id ) ) {
			return new WP_Error( 'ces_class_denied', __( 'That is not your class.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Classes.
	 * ------------------------------------------------------------------ */

	/**
	 * Create a class.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_class( $request ) {
		$user_id = get_current_user_id();

		if ( ! CES_Consultant::can_add_class( $user_id ) ) {
			return new WP_Error(
				'ces_class_limit',
				sprintf(
					/* translators: %d: class limit. */
					__( 'You have reached your limit of %d classes. Archive one you have finished with.', 'classic-edu-suite' ),
					CES_Consultant::class_limit( $user_id )
				),
				array( 'status' => 403 )
			);
		}

		$title = trim( (string) $request->get_param( 'title' ) );

		if ( mb_strlen( $title ) < 3 ) {
			return new WP_Error( 'ces_class_title', __( 'Give the class a name your students will recognise.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$class_id = wp_insert_post(
			array(
				'post_type'    => CES_Classroom_Cpt::CLASS_TYPE,
				'post_title'   => $title,
				'post_content' => (string) $request->get_param( 'description' ),
				'post_status'  => 'publish',
				'post_author'  => $user_id,
			),
			true
		);

		if ( is_wp_error( $class_id ) ) {
			return $class_id;
		}

		$class_id = (int) $class_id;

		CES_Classroom_Cpt::code( $class_id );
		update_post_meta( $class_id, CES_Classroom_Cpt::VISIBILITY, 'private' );
		update_post_meta( $class_id, CES_Classroom_Cpt::CHAT, '1' );

		// Seed the room before anybody joins it: the join itself posts a
		// system line, and the opener only runs in a room with nothing in it.
		CES_Chat_Crowd::seed( CES_Chat::class_room( $class_id ), $title );

		// The consultant is in their own roster, so the roster is the whole
		// room and not everyone-except-the-teacher.
		CES_Classroom_Enrolment::join( $class_id, $user_id, 'staff', 'consultant' );

		return rest_ensure_response( self::class_payload( $class_id ) );
	}

	/**
	 * Update a class's settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_class( $request ) {
		$class_id = (int) $request->get_param( 'id' );
		$own      = self::own_class( $class_id );

		if ( is_wp_error( $own ) ) {
			return $own;
		}

		$post = array( 'ID' => $class_id );

		$title = $request->get_param( 'title' );

		if ( null !== $title && '' !== trim( (string) $title ) ) {
			$post['post_title'] = sanitize_text_field( (string) $title );
		}

		$description = $request->get_param( 'description' );

		if ( null !== $description ) {
			$post['post_content'] = sanitize_textarea_field( (string) $description );
		}

		if ( count( $post ) > 1 ) {
			wp_update_post( $post );
		}

		$visibility = $request->get_param( 'visibility' );

		if ( null !== $visibility ) {
			update_post_meta( $class_id, CES_Classroom_Cpt::VISIBILITY, 'public' === $visibility ? 'public' : 'private' );
		}

		$approval = $request->get_param( 'approval' );

		if ( null !== $approval ) {
			update_post_meta( $class_id, CES_Classroom_Cpt::APPROVAL, 'open' === $approval ? 'open' : 'approve' );
		}

		$chat = $request->get_param( 'chat' );

		if ( null !== $chat ) {
			update_post_meta( $class_id, CES_Classroom_Cpt::CHAT, $chat ? '1' : '0' );
		}

		$welcome = $request->get_param( 'welcome' );

		if ( null !== $welcome ) {
			update_post_meta( $class_id, CES_Classroom_Cpt::WELCOME, sanitize_textarea_field( (string) $welcome ) );
		}

		$meet = $request->get_param( 'meet_url' );

		if ( null !== $meet ) {
			$url = esc_url_raw( (string) $meet );

			if ( '' === $url ) {
				delete_post_meta( $class_id, CES_Classroom_Cpt::MEET_URL );
			} else {
				update_post_meta( $class_id, CES_Classroom_Cpt::MEET_URL, $url );
			}
		}

		$archived = $request->get_param( 'archived' );

		if ( null !== $archived ) {
			if ( $archived ) {
				update_post_meta( $class_id, CES_Classroom_Cpt::ARCHIVED, '1' );
			} else {
				delete_post_meta( $class_id, CES_Classroom_Cpt::ARCHIVED );
			}
		}

		if ( $request->get_param( 'new_code' ) ) {
			update_post_meta( $class_id, CES_Classroom_Cpt::CODE, CES_Classroom_Cpt::mint_code() );
		}

		return rest_ensure_response( self::class_payload( $class_id ) );
	}

	/**
	 * Reorder or replace a class's lessons.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_class_lessons( $request ) {
		$class_id = (int) $request->get_param( 'id' );
		$own      = self::own_class( $class_id );

		if ( is_wp_error( $own ) ) {
			return $own;
		}

		$ids = (array) $request->get_param( 'lessons' );
		$out = array();

		foreach ( $ids as $lesson_id ) {
			$lesson_id = (int) $lesson_id;

			// Only lessons this person may edit, so a reorder cannot be used
			// to pull somebody else's video into your class.
			if ( $lesson_id > 0 && CES_Lms_Cpt::LESSON === get_post_type( $lesson_id ) && current_user_can( 'edit_post', $lesson_id ) ) {
				$out[] = $lesson_id;
				update_post_meta( $lesson_id, CES_Classroom_Cpt::LESSON_CLASS, $class_id );
			}
		}

		CES_Classroom_Cpt::set_lessons( $class_id, $out );

		return rest_ensure_response( self::class_payload( $class_id ) );
	}

	/**
	 * The roster.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_roster( $request ) {
		$class_id = (int) $request->get_param( 'id' );
		$own      = self::own_class( $class_id );

		if ( is_wp_error( $own ) ) {
			return $own;
		}

		$status = $request->get_param( 'status' );
		$status = $status ? sanitize_key( (string) $status ) : 'any';

		$out = array();

		foreach ( CES_Classroom_Enrolment::roster( $class_id, array( 'status' => $status, 'number' => 500 ) ) as $row ) {
			$out[] = array(
				'userId'   => (int) $row['user_id'],
				'name'     => $row['user']->display_name,
				'email'    => $row['user']->user_email,
				'status'   => (string) $row['status'],
				'role'     => (string) $row['role'],
				'source'   => (string) $row['source'],
				'joined'   => (string) $row['joined_at'],
				'lastSeen' => (string) $row['last_seen_at'],
				'lessons'  => (int) $row['lessons_done'],
				'minutes'  => (int) $row['minutes_watched'],
			);
		}

		return rest_ensure_response(
			array(
				'members' => $out,
				'counts'  => array(
					'active'  => CES_Classroom_Enrolment::count( $class_id ),
					'pending' => CES_Classroom_Enrolment::count( $class_id, CES_Classroom_Enrolment::PENDING ),
				),
			)
		);
	}

	/**
	 * Approve, block, remove or re-admit a member.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_member( $request ) {
		$class_id = (int) $request->get_param( 'id' );
		$own      = self::own_class( $class_id );

		if ( is_wp_error( $own ) ) {
			return $own;
		}

		$user_id = (int) $request->get_param( 'user' );
		$action  = (string) $request->get_param( 'action' );

		if ( $user_id === CES_Classroom_Cpt::owner( $class_id ) ) {
			return new WP_Error( 'ces_member_self', __( 'You cannot remove yourself from your own class.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		switch ( $action ) {
			case 'approve':
				CES_Classroom_Enrolment::approve( $class_id, $user_id );
				break;

			case 'block':
				CES_Classroom_Enrolment::set_status( $class_id, $user_id, CES_Classroom_Enrolment::BLOCKED );
				break;

			case 'remove':
				CES_Classroom_Enrolment::remove( $class_id, $user_id );
				break;

			default:
				return new WP_Error( 'ces_member_action', __( 'That action is not recognised.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'counts'  => array(
					'active'  => CES_Classroom_Enrolment::count( $class_id ),
					'pending' => CES_Classroom_Enrolment::count( $class_id, CES_Classroom_Enrolment::PENDING ),
				),
			)
		);
	}

	/**
	 * Join a class by its code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function join_class( $request ) {
		if ( ! CES_Helpers::rate_limit( 'class_join', 20, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ces_class_throttled', __( 'Too many tries. Wait a few minutes.', 'classic-edu-suite' ), array( 'status' => 429 ) );
		}

		$class_id = CES_Classroom_Cpt::by_code( (string) $request->get_param( 'code' ) );

		if ( ! $class_id ) {
			return new WP_Error( 'ces_class_code', __( 'No class has that code. Check it with your tutor.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$row = CES_Classroom_Enrolment::join( $class_id, get_current_user_id(), 'code' );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return rest_ensure_response(
			array(
				'classId' => $class_id,
				'status'  => (string) $row['status'],
				'url'     => CES_Classroom_Cpt::share_link( $class_id ),
				'title'   => get_the_title( $class_id ),
			)
		);
	}

	/**
	 * Leave a class.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function leave_class( $request ) {
		$class_id = (int) $request->get_param( 'id' );

		if ( ! CES_Classroom_Cpt::is_class( $class_id ) ) {
			return new WP_Error( 'ces_class_missing', __( 'That class could not be found.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		CES_Classroom_Enrolment::leave( $class_id, get_current_user_id() );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * A class as the Studio sees it.
	 *
	 * @param int $class_id Class.
	 * @return array
	 */
	public static function class_payload( $class_id ) {
		$class_id = (int) $class_id;

		return array(
			'id'          => $class_id,
			'title'       => get_the_title( $class_id ),
			'description' => (string) get_post_field( 'post_content', $class_id ),
			'code'        => CES_Classroom_Cpt::code( $class_id ),
			'shareUrl'    => CES_Classroom_Cpt::share_link( $class_id ),
			'visibility'  => CES_Classroom_Cpt::is_public( $class_id ) ? 'public' : 'private',
			'approval'    => CES_Classroom_Cpt::needs_approval( $class_id ) ? 'approve' : 'open',
			'chat'        => CES_Classroom_Cpt::chat_enabled( $class_id ),
			'archived'    => CES_Classroom_Cpt::is_archived( $class_id ),
			'welcome'     => (string) get_post_meta( $class_id, CES_Classroom_Cpt::WELCOME, true ),
			'meetUrl'     => (string) get_post_meta( $class_id, CES_Classroom_Cpt::MEET_URL, true ),
			'members'     => CES_Classroom_Enrolment::count( $class_id ),
			'pending'     => CES_Classroom_Enrolment::count( $class_id, CES_Classroom_Enrolment::PENDING ),
			'lessons'     => CES_Classroom_Access::lesson_cards( $class_id ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Lessons.
	 * ------------------------------------------------------------------ */

	/**
	 * Create a draft lesson, ready for the uploader to attach a video to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_lesson( $request ) {
		$title = trim( (string) $request->get_param( 'title' ) );

		if ( mb_strlen( $title ) < 3 ) {
			return new WP_Error( 'ces_lesson_title', __( 'Give the lesson a title first.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$class_id = (int) $request->get_param( 'class_id' );

		if ( $class_id > 0 ) {
			$own = self::own_class( $class_id );

			if ( is_wp_error( $own ) ) {
				return $own;
			}
		}

		$lesson_id = wp_insert_post(
			array(
				'post_type'   => CES_Lms_Cpt::LESSON,
				'post_title'  => $title,
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $lesson_id ) ) {
			return $lesson_id;
		}

		$lesson_id = (int) $lesson_id;

		if ( $class_id > 0 ) {
			CES_Classroom_Cpt::add_lesson( $class_id, $lesson_id );
		}

		return rest_ensure_response(
			array(
				'lessonId' => $lesson_id,
				'title'    => $title,
				'classId'  => $class_id,
				'editUrl'  => add_query_arg(
					array(
						'tab'    => CES_Classroom_Studio::SLUG,
						'lesson' => $lesson_id,
					),
					CES_Helpers::dashboard_url( CES_Classroom_Studio::SLUG )
				),
			)
		);
	}

	/**
	 * Everything that writes to one lesson's extras.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function lesson_action( $request ) {
		$lesson_id = (int) $request->get_param( 'id' );
		$action    = sanitize_key( (string) $request->get_param( 'ces_action' ) );

		if ( ! CES_Classroom_Lesson_Tools::can_edit( $lesson_id ) ) {
			return new WP_Error( 'ces_lesson_denied', __( 'That is not your lesson.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		switch ( $action ) {
			case 'plan':
				$result = CES_Classroom_Lesson_Tools::save_plan( $lesson_id, (array) $request->get_param( 'plan' ) );
				break;

			case 'chapters':
				$result = CES_Classroom_Lesson_Tools::save_chapters( $lesson_id, (string) $request->get_param( 'text' ) );
				break;

			case 'captions':
				$result = CES_Classroom_Lesson_Tools::save_captions( $lesson_id, (string) $request->get_param( 'text' ) );
				break;

			case 'checkpoints':
				$result = CES_Classroom_Lesson_Tools::save_checkpoints( $lesson_id, (array) $request->get_param( 'checkpoints' ) );
				break;

			case 'publish':
				$result = self::publish_lesson( $lesson_id, (bool) $request->get_param( 'publish' ) );
				break;

			case 'thumbnail':
				$result = self::save_thumbnail( $lesson_id, (string) $request->get_param( 'image' ) );
				break;

			case 'delete':
				wp_trash_post( $lesson_id );
				$result = true;
				break;

			default:
				return new WP_Error( 'ces_lesson_action', __( 'That action is not recognised.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'result' => $result,
				'review' => CES_Classroom_Lesson_Tools::review( $lesson_id ),
			)
		);
	}

	/**
	 * Publish or unpublish a lesson.
	 *
	 * @param int  $lesson_id Lesson.
	 * @param bool $publish   True to publish.
	 * @return true|WP_Error
	 */
	private static function publish_lesson( $lesson_id, $publish ) {
		if ( $publish && CES_Helpers::enabled( 'classroom_require_plan' ) ) {
			$plan = CES_Classroom_Lesson_Tools::plan( $lesson_id );

			if ( mb_strlen( trim( $plan['objective'] ) ) < 15 ) {
				return new WP_Error(
					'ces_lesson_plan_required',
					__( 'Write the lesson objective before publishing. It is the line students see above the player.', 'classic-edu-suite' ),
					array( 'status' => 400 )
				);
			}
		}

		wp_update_post(
			array(
				'ID'          => (int) $lesson_id,
				'post_status' => $publish ? 'publish' : 'draft',
			)
		);

		return true;
	}

	/**
	 * Store a captured frame as the lesson's cover image.
	 *
	 * @param int    $lesson_id Lesson.
	 * @param string $image     Data URL from the recorder's canvas.
	 * @return true|WP_Error
	 */
	private static function save_thumbnail( $lesson_id, $image ) {
		if ( ! preg_match( '#^data:image/(png|jpeg);base64,#', $image, $m ) ) {
			return new WP_Error( 'ces_thumb_type', __( 'That image could not be read.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$encoded = substr( $image, strpos( $image, ',' ) + 1 );
		$binary  = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $binary || strlen( $binary ) < 1024 ) {
			return new WP_Error( 'ces_thumb_empty', __( 'That image could not be read.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		if ( strlen( $binary ) > 3145728 ) {
			return new WP_Error( 'ces_thumb_large', __( 'That image is too large.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		$extension = 'png' === $m[1] ? 'png' : 'jpg';
		$filename  = sprintf( 'lesson-%d-cover-%s.%s', (int) $lesson_id, wp_generate_password( 6, false, false ), $extension );

		$upload = wp_upload_bits( $filename, null, $binary );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'ces_thumb_upload', (string) $upload['error'], array( 'status' => 500 ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'png' === $extension ? 'image/png' : 'image/jpeg',
				'post_title'     => get_the_title( $lesson_id ),
				'post_status'    => 'inherit',
				'post_parent'    => (int) $lesson_id,
			),
			$upload['file'],
			(int) $lesson_id,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		set_post_thumbnail( (int) $lesson_id, (int) $attachment_id );

		return true;
	}

	/**
	 * The quality review for a lesson.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_review( $request ) {
		$lesson_id = (int) $request->get_param( 'id' );

		if ( ! CES_Classroom_Lesson_Tools::can_edit( $lesson_id ) ) {
			return new WP_Error( 'ces_lesson_denied', __( 'That is not your lesson.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response(
			array(
				'review'   => CES_Classroom_Lesson_Tools::review( $lesson_id ),
				'plan'     => CES_Classroom_Lesson_Tools::plan( $lesson_id ),
				'chapters' => CES_Classroom_Lesson_Tools::chapters_text( $lesson_id ),
				'captions' => CES_Classroom_Lesson_Tools::vtt( $lesson_id ),
				'points'   => CES_Classroom_Lesson_Tools::checkpoints( $lesson_id, true ),
				'status'   => get_post_status( $lesson_id ),
			)
		);
	}

	/**
	 * The chapters, captions and checkpoints a watching student needs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_watch_extras( $request ) {
		$lesson_id = (int) $request->get_param( 'id' );

		if ( ! CES_Classroom_Access::can_watch( $lesson_id ) ) {
			return new WP_Error( 'ces_lesson_denied', __( 'You are not in this class.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		CES_Classroom_Access::maybe_grant( $lesson_id );

		$plan = CES_Classroom_Lesson_Tools::plan( $lesson_id );

		return rest_ensure_response(
			array(
				'objective' => $plan['objective'],
				'chapters'  => CES_Classroom_Lesson_Tools::chapters( $lesson_id ),
				'captions'  => CES_Classroom_Lesson_Tools::vtt( $lesson_id ),
				'points'    => CES_Classroom_Lesson_Tools::checkpoints( $lesson_id ),
			)
		);
	}

	/**
	 * Mark a checkpoint answer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function answer_checkpoint( $request ) {
		$result = CES_Classroom_Lesson_Tools::answer_checkpoint(
			(int) $request->get_param( 'id' ),
			(int) $request->get_param( 'index' ),
			(int) $request->get_param( 'answer' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/* ---------------------------------------------------------------------
	 * Chat.
	 * ------------------------------------------------------------------ */

	/**
	 * Read a room.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_messages( $request ) {
		$room = CES_Chat::sanitize_room( (string) $request->get_param( 'room' ) );

		if ( '' === $room || ! CES_Chat::can_read( $room ) ) {
			return new WP_Error( 'ces_chat_denied', __( 'You cannot read that room.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$after = (int) $request->get_param( 'after' );

		// Somebody is looking, so this is the moment to fill a quiet room.
		CES_Chat_Crowd::maybe_speak( $room );

		return rest_ensure_response(
			array(
				'messages' => CES_Chat::messages( $room, $after ),
				'latest'   => CES_Chat::latest_id( $room ),
				'canPost'  => CES_Chat::can_post( $room ),
				'people'   => CES_Chat::active_people( $room ),
				'slowmode' => max( 0, CES_Helpers::setting_int( 'classroom_chat_slowmode', 5 ) ),
			)
		);
	}

	/**
	 * Say something.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_message( $request ) {
		$room = CES_Chat::sanitize_room( (string) $request->get_param( 'room' ) );

		$message = CES_Chat::post(
			$room,
			get_current_user_id(),
			(string) $request->get_param( 'body' ),
			(int) $request->get_param( 'reply_to' )
		);

		if ( is_wp_error( $message ) ) {
			return $message;
		}

		return rest_ensure_response(
			array(
				'message' => $message,
				'latest'  => CES_Chat::latest_id( $room ),
			)
		);
	}

	/**
	 * Report a message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function report_message( $request ) {
		$result = CES_Chat::report( (int) $request->get_param( 'id' ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Hide, restore or delete a message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function moderate_message( $request ) {
		$id     = (int) $request->get_param( 'id' );
		$status = (string) $request->get_param( 'status' );

		if ( ! CES_Chat::set_status( $id, $status ) ) {
			return new WP_Error( 'ces_chat_moderate', __( 'That could not be changed.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'queue' => CES_Chat::queue_count(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Live sessions.
	 * ------------------------------------------------------------------ */

	/**
	 * Schedule a session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_session( $request ) {
		$class_id = (int) $request->get_param( 'class_id' );
		$own      = self::own_class( $class_id );

		if ( is_wp_error( $own ) ) {
			return $own;
		}

		$session_id = CES_Classroom_Session::create(
			$class_id,
			array(
				'title'     => (string) $request->get_param( 'title' ),
				'lesson_id' => (int) $request->get_param( 'lesson_id' ),
				'start'     => (string) $request->get_param( 'start' ),
				'minutes'   => (int) $request->get_param( 'minutes' ),
				'meet_url'  => (string) $request->get_param( 'meet_url' ),
			)
		);

		if ( is_wp_error( $session_id ) ) {
			return $session_id;
		}

		return rest_ensure_response(
			array(
				'sessionId' => $session_id,
				'title'     => get_the_title( $session_id ),
				'url'       => CES_Classroom_Session::room_url( $session_id ),
				'start'     => CES_Classroom_Session::start_at( $session_id ),
			)
		);
	}

	/**
	 * The one polling endpoint the live room uses.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function session_state( $request ) {
		$session_id = (int) $request->get_param( 'id' );

		if ( ! CES_Classroom_Session::can_attend( $session_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'You are not in this class.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		$seconds = (int) $request->get_param( 'secs' );

		if ( $seconds > 0 ) {
			CES_Classroom_Session::mark_present( $session_id, get_current_user_id(), $seconds );
		}

		return rest_ensure_response(
			CES_Classroom_Session::room_state( $session_id, (int) $request->get_param( 'after' ) )
		);
	}

	/**
	 * Everything that changes a session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function session_action( $request ) {
		$session_id = (int) $request->get_param( 'id' );
		$action     = sanitize_key( str_replace( '-', '_', (string) $request->get_param( 'ces_action' ) ) );

		if ( ! CES_Classroom_Session::can_attend( $session_id ) ) {
			return new WP_Error( 'ces_session_denied', __( 'You are not in this class.', 'classic-edu-suite' ), array( 'status' => 403 ) );
		}

		switch ( $action ) {
			case 'start':
				$result = CES_Classroom_Session::start( $session_id );
				break;

			case 'end':
				$result = CES_Classroom_Session::end( $session_id );
				break;

			case 'playback':
				$result = CES_Classroom_Session::set_playback(
					$session_id,
					(float) $request->get_param( 'position' ),
					(bool) $request->get_param( 'playing' )
				);
				break;

			case 'hand':
				$result = CES_Classroom_Session::set_hand(
					$session_id,
					get_current_user_id(),
					(bool) $request->get_param( 'up' )
				);
				break;

			case 'clear_hands':
				$result = CES_Classroom_Session::clear_hands( $session_id );
				break;

			case 'poll':
				if ( $request->get_param( 'close' ) ) {
					$result = CES_Classroom_Session::close_poll( $session_id );
					break;
				}

				$result = CES_Classroom_Session::open_poll(
					$session_id,
					(string) $request->get_param( 'question' ),
					(array) $request->get_param( 'options' ),
					null === $request->get_param( 'correct' ) ? -1 : (int) $request->get_param( 'correct' )
				);
				break;

			case 'vote':
				$result = CES_Classroom_Session::vote( $session_id, get_current_user_id(), (int) $request->get_param( 'index' ) );
				break;

			default:
				return new WP_Error( 'ces_session_action', __( 'That action is not recognised.', 'classic-edu-suite' ), array( 'status' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( CES_Classroom_Session::room_state( $session_id, (int) $request->get_param( 'after' ) ) );
	}
}
