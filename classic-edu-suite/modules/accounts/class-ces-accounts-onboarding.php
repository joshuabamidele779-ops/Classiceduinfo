<?php
/**
 * The three screens a new student sees before the site opens up.
 *
 *   Step 1  Who you are, and who your parent is.
 *   Step 2  Waiting on the parent — with the one-tap WhatsApp button and the
 *           box for the six-digit code.
 *   Step 3  Approved, or refused with a way back.
 *
 * All of it posts to `admin-post.php` and works with JavaScript switched off,
 * because the student most likely to be stuck here is the one on the worst
 * phone and the worst connection. The parent fields reuse
 * `CES_Parent_Contact`, so there is exactly one phone normaliser and one set
 * of validation rules in the plugin.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and processes student onboarding.
 */
class CES_Accounts_Onboarding {

	/**
	 * admin-post action: save the details and start the request.
	 */
	const ACTION_SAVE = 'ces_account_onboard';

	/**
	 * admin-post action: check a six-digit code.
	 */
	const ACTION_CODE = 'ces_account_code';

	/**
	 * admin-post action: send the request again.
	 */
	const ACTION_RESEND = 'ces_account_resend';

	/**
	 * admin-post action: go back and change the parent's details.
	 */
	const ACTION_RESTART = 'ces_account_restart';

	/**
	 * Shortcode rendering whichever step the student is on.
	 */
	const SHORTCODE = 'ces_account_setup';

	/**
	 * Transient prefix for a one-page-load message.
	 */
	const FLASH_KEY = 'ces_onboard_flash_';

	/**
	 * Student's own phone number, asked for but never required.
	 */
	const META_OWN_PHONE = 'ces_student_phone';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( array( self::ACTION_SAVE, self::ACTION_CODE, self::ACTION_RESEND, self::ACTION_RESTART ) as $action ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'route' ) );
			add_action( 'admin_post_nopriv_' . $action, array( __CLASS__, 'reject_guest' ) );
		}

		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
	}

	/* ---------------------------------------------------------------------
	 * Request handling.
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch whichever form was posted.
	 *
	 * @return void
	 */
	public static function route() {
		if ( ! is_user_logged_in() ) {
			self::reject_guest();
		}

		$user_id = get_current_user_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked per action below.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		check_admin_referer( $action . '_' . $user_id );

		switch ( $action ) {
			case self::ACTION_SAVE:
				self::handle_save( $user_id );
				break;

			case self::ACTION_CODE:
				self::handle_code( $user_id );
				break;

			case self::ACTION_RESEND:
				self::handle_resend( $user_id );
				break;

			case self::ACTION_RESTART:
				self::handle_restart( $user_id );
				break;
		}

		self::bounce();
	}

	/**
	 * Step 1: store the details and ask the parent.
	 *
	 * @param int $user_id Student.
	 * @return void
	 */
	private static function handle_save( $user_id ) {
		if ( ! CES_Helpers::rate_limit( 'account_onboard', 20, 10 * MINUTE_IN_SECONDS ) ) {
			self::flash( $user_id, array( 'errors' => array( __( 'Too many attempts. Please wait a few minutes.', 'classic-edu-suite' ) ) ) );

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in route().
		$input = array(
			'student_name'  => isset( $_POST['ces_student_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_student_name'] ) ) : '',
			'class'         => isset( $_POST['ces_student_class'] ) ? sanitize_key( wp_unslash( $_POST['ces_student_class'] ) ) : '',
			'dob'           => isset( $_POST['ces_student_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_student_dob'] ) ) : '',
			'own_phone'     => isset( $_POST['ces_student_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_student_phone'] ) ) : '',
			'name'          => isset( $_POST['ces_parent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_parent_name'] ) ) : '',
			'phone'         => isset( $_POST['ces_parent_whatsapp'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_parent_whatsapp'] ) ) : '',
			'parent_email'  => isset( $_POST['ces_parent_email'] ) ? sanitize_email( wp_unslash( $_POST['ces_parent_email'] ) ) : '',
			'relationship'  => isset( $_POST['ces_parent_relationship'] ) ? sanitize_key( wp_unslash( $_POST['ces_parent_relationship'] ) ) : 'guardian',
			'consent'       => ! empty( $_POST['ces_parent_consent'] ),
			'terms'         => ! empty( $_POST['ces_terms'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$errors = self::validate( $input );

		if ( ! empty( $errors ) ) {
			self::flash( $user_id, array( 'errors' => array_values( $errors ), 'values' => $input ) );

			return;
		}

		// The student's own details first, so a later failure on the parent
		// fields does not lose them.
		if ( '' !== $input['student_name'] ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $input['student_name'],
				)
			);
		}

		if ( '' !== $input['class'] ) {
			update_user_meta( $user_id, CES_Accounts_Status::META_CLASS, $input['class'] );
		}

		if ( '' !== $input['dob'] ) {
			update_user_meta( $user_id, CES_Accounts_Status::META_DOB, $input['dob'] );
		}

		if ( '' !== $input['own_phone'] ) {
			update_user_meta( $user_id, self::META_OWN_PHONE, CES_Parent_Contact::normalize_phone( $input['own_phone'] ) );
		}

		if ( '' !== $input['parent_email'] ) {
			update_user_meta( $user_id, CES_Accounts_Verification::META_PARENT_EMAIL, $input['parent_email'] );
		} else {
			delete_user_meta( $user_id, CES_Accounts_Verification::META_PARENT_EMAIL );
		}

		$saved = CES_Parent_Contact::save(
			$user_id,
			array(
				'name'         => $input['name'],
				'phone'        => $input['phone'],
				'relationship' => $input['relationship'],
				'consent'      => $input['consent'],
			)
		);

		if ( is_wp_error( $saved ) ) {
			self::flash( $user_id, array( 'errors' => array_values( $saved->get_error_messages() ), 'values' => $input ) );

			return;
		}

		CES_Accounts_Status::log(
			$user_id,
			'onboarding_submitted',
			array(
				'actor_id' => $user_id,
				'detail'   => CES_Accounts_Status::class_label( $input['class'] ),
			)
		);

		$started = CES_Accounts_Verification::start( $user_id );

		if ( is_wp_error( $started ) ) {
			self::flash( $user_id, array( 'errors' => array_values( $started->get_error_messages() ) ) );

			return;
		}

		self::flash( $user_id, array( 'sent' => isset( $started['sent'] ) ? (array) $started['sent'] : array() ) );
	}

	/**
	 * Step 2: a six-digit code was typed in.
	 *
	 * @param int $user_id Student.
	 * @return void
	 */
	private static function handle_code( $user_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$code = isset( $_POST['ces_code'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_code'] ) ) : '';

		$result = CES_Accounts_Verification::verify_code( $user_id, $code );

		if ( is_wp_error( $result ) ) {
			self::flash( $user_id, array( 'errors' => array_values( $result->get_error_messages() ) ) );

			return;
		}

		self::flash( $user_id, array( 'verified' => true ) );
	}

	/**
	 * Step 2: send it again.
	 *
	 * @param int $user_id Student.
	 * @return void
	 */
	private static function handle_resend( $user_id ) {
		$result = CES_Accounts_Verification::resend( $user_id );

		if ( is_wp_error( $result ) ) {
			self::flash( $user_id, array( 'errors' => array_values( $result->get_error_messages() ) ) );

			return;
		}

		self::flash(
			$user_id,
			array(
				'sent'    => isset( $result['sent'] ) ? (array) $result['sent'] : array(),
				'resent'  => true,
			)
		);
	}

	/**
	 * Step 2: the number was wrong — go back to step 1.
	 *
	 * @param int $user_id Student.
	 * @return void
	 */
	private static function handle_restart( $user_id ) {
		CES_Accounts_Verification::cancel_open( $user_id, 'student_restarted' );

		CES_Accounts_Status::set_status(
			$user_id,
			'onboarding',
			array(
				'actor_id' => $user_id,
				'event'    => 'student_restarted',
			)
		);

		self::flash( $user_id, array( 'restarted' => true ) );
	}

	/**
	 * Validate step 1.
	 *
	 * @param array $input Submitted values.
	 * @return array Field => message.
	 */
	public static function validate( array $input ) {
		$errors = array();

		if ( strlen( trim( (string) $input['student_name'] ) ) < 2 ) {
			$errors['student_name'] = __( 'Please tell us your full name, as it appears on your exam registration.', 'classic-edu-suite' );
		}

		if ( CES_Helpers::enabled( 'accounts_class_required' ) && '' === CES_Accounts_Status::sanitize_class( $input['class'] ) ) {
			$errors['class'] = __( 'Please choose the class or level you are in.', 'classic-edu-suite' );
		}

		if ( CES_Helpers::enabled( 'accounts_dob_required' ) ) {
			$stamp = strtotime( (string) $input['dob'] );

			if ( ! $stamp || $stamp > time() ) {
				$errors['dob'] = __( 'Please give your date of birth.', 'classic-edu-suite' );
			}
		}

		if ( '' !== trim( (string) $input['parent_email'] ) && ! is_email( (string) $input['parent_email'] ) ) {
			$errors['parent_email'] = __( 'That parent email address does not look right. Leave it empty if you are not sure.', 'classic-edu-suite' );
		}

		if ( '' !== trim( (string) $input['own_phone'] ) && '' === CES_Parent_Contact::normalize_phone( (string) $input['own_phone'] ) ) {
			$errors['own_phone'] = __( 'That phone number does not look right. Leave it empty if you would rather not say.', 'classic-edu-suite' );
		}

		// The parent fields are validated by the contact class, so there is
		// one rule set, not two.
		foreach ( CES_Parent_Contact::validate(
			array(
				'name'    => $input['name'],
				'phone'   => $input['phone'],
				'consent' => $input['consent'],
			)
		) as $field => $message ) {
			$errors[ 'parent_' . $field ] = $message;
		}

		if ( '' !== trim( (string) CES_Helpers::setting( 'accounts_terms_url', '' ) ) && empty( $input['terms'] ) ) {
			$errors['terms'] = __( 'Please tick to say you accept the terms of use.', 'classic-edu-suite' );
		}

		// A student must not pass their own number off as their parent's. It
		// is the one shortcut that defeats the whole point of this screen.
		$own    = CES_Parent_Contact::normalize_phone( (string) $input['own_phone'] );
		$parent = CES_Parent_Contact::normalize_phone( (string) $input['phone'] );

		if ( '' !== $own && $own === $parent ) {
			$errors['parent_phone'] = __( 'That is the same number you gave as your own. Please give your parent or guardian’s number.', 'classic-edu-suite' );
		}

		/**
		 * Filter onboarding validation errors.
		 *
		 * @param array $errors Field => message.
		 * @param array $input  Submitted values.
		 */
		return (array) apply_filters( 'ces_accounts_onboarding_errors', $errors, $input );
	}

	/* ---------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	/**
	 * `[ces_account_setup]` — whichever step applies.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		unset( $atts );

		if ( class_exists( 'CES_Assets' ) ) {
			CES_Assets::enqueue_base();
		}

		if ( ! is_user_logged_in() ) {
			return '<div class="ces-notice"><p>'
				. esc_html__( 'Sign in first, then we will set your account up.', 'classic-edu-suite' )
				. '</p>' . CES_Accounts_Login::button_html() . '</div>';
		}

		return self::render( get_current_user_id() );
	}

	/**
	 * The right screen for where this student has got to.
	 *
	 * @param int $user_id Student.
	 * @return string
	 */
	public static function render( $user_id ) {
		if ( class_exists( 'CES_Accounts_Module' ) ) {
			CES_Accounts_Module::enqueue_style();
		}

		$user_id = absint( $user_id );
		$flash   = self::take_flash( $user_id );
		$status  = CES_Accounts_Status::status( $user_id, true );

		$html = '<div class="ces-onboard">';

		$html .= self::notices( $flash );

		switch ( $status ) {
			case 'approved':
				$html .= self::render_approved( $user_id );
				break;

			case 'awaiting_parent':
				$html .= self::render_waiting( $user_id, $flash );
				break;

			case 'awaiting_admin':
				$html .= self::render_review( $user_id );
				break;

			case 'declined':
				$html .= self::render_declined( $user_id );
				break;

			case 'suspended':
				$html .= self::render_suspended( $user_id );
				break;

			default:
				$html .= self::render_form( $user_id, $flash );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * The flash notices for this page load.
	 *
	 * @param array $flash Flash payload.
	 * @return string
	 */
	private static function notices( array $flash ) {
		$html = '';

		if ( ! empty( $flash['errors'] ) ) {
			$html .= '<div class="ces-notice ces-notice--error"><ul>';

			foreach ( (array) $flash['errors'] as $message ) {
				$html .= '<li>' . esc_html( $message ) . '</li>';
			}

			$html .= '</ul></div>';
		}

		if ( ! empty( $flash['verified'] ) ) {
			$html .= '<div class="ces-notice ces-notice--success"><p>'
				. esc_html__( 'That is the right code. Thank you — your account is confirmed.', 'classic-edu-suite' )
				. '</p></div>';
		}

		if ( ! empty( $flash['resent'] ) ) {
			$html .= '<div class="ces-notice ces-notice--success"><p>'
				. esc_html__( 'A new request is ready. Only the newest message works, so ask your parent to use that one.', 'classic-edu-suite' )
				. '</p></div>';
		}

		if ( ! empty( $flash['restarted'] ) ) {
			$html .= '<div class="ces-notice"><p>'
				. esc_html__( 'Change the details below and we will ask again.', 'classic-edu-suite' )
				. '</p></div>';
		}

		return $html;
	}

	/**
	 * Step 1: the form.
	 *
	 * @param int   $user_id Student.
	 * @param array $flash   Flash payload, for redisplaying a failed post.
	 * @return string
	 */
	public static function render_form( $user_id, array $flash = array() ) {
		$user    = get_userdata( $user_id );
		$contact = CES_Parent_Contact::get( $user_id );
		$record  = CES_Accounts_Status::record( $user_id );
		$values  = isset( $flash['values'] ) && is_array( $flash['values'] ) ? $flash['values'] : array();

		$get = static function ( $key, $fallback ) use ( $values ) {
			return isset( $values[ $key ] ) ? (string) $values[ $key ] : (string) $fallback;
		};

		$terms_url   = trim( (string) CES_Helpers::setting( 'accounts_terms_url', '' ) );
		$privacy_url = trim( (string) CES_Helpers::setting( 'accounts_privacy_url', '' ) );

		ob_start();
		?>
		<div class="ces-onboard__head">
			<p class="ces-onboard__step"><?php esc_html_e( 'Step 1 of 2', 'classic-edu-suite' ); ?></p>
			<h2 class="ces-onboard__title"><?php esc_html_e( 'One last thing before you start', 'classic-edu-suite' ); ?></h2>
			<p class="ces-onboard__lead">
				<?php esc_html_e( 'We ask every student for a parent or guardian, and we ask that parent to confirm it is really them. It takes them one tap, and it is what keeps this a place for students and their families only.', 'classic-edu-suite' ); ?>
			</p>
		</div>

		<form class="ces-onboard__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION_SAVE . '_' . $user_id ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::current_url() ); ?>" />

			<fieldset class="ces-onboard__group">
				<legend><?php esc_html_e( 'About you', 'classic-edu-suite' ); ?></legend>

				<p class="ces-field">
					<label for="ces-student-name"><?php esc_html_e( 'Your full name', 'classic-edu-suite' ); ?></label>
					<input type="text" id="ces-student-name" name="ces_student_name" required
						value="<?php echo esc_attr( $get( 'student_name', $user ? $user->display_name : '' ) ); ?>" />
					<span class="ces-field__hint"><?php esc_html_e( 'Use the name on your exam registration, so your results match.', 'classic-edu-suite' ); ?></span>
				</p>

				<p class="ces-field">
					<label for="ces-student-class"><?php esc_html_e( 'Class or level', 'classic-edu-suite' ); ?></label>
					<select id="ces-student-class" name="ces_student_class" <?php echo CES_Helpers::enabled( 'accounts_class_required' ) ? 'required' : ''; ?>>
						<option value=""><?php esc_html_e( 'Choose…', 'classic-edu-suite' ); ?></option>
						<?php foreach ( CES_Accounts_Status::class_options() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $get( 'class', $record['class'] ), $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<?php if ( CES_Helpers::enabled( 'accounts_dob_required' ) ) : ?>
					<p class="ces-field">
						<label for="ces-student-dob"><?php esc_html_e( 'Date of birth', 'classic-edu-suite' ); ?></label>
						<input type="date" id="ces-student-dob" name="ces_student_dob" required
							value="<?php echo esc_attr( $get( 'dob', $record['dob'] ) ); ?>" />
					</p>
				<?php endif; ?>

				<p class="ces-field">
					<label for="ces-student-phone"><?php esc_html_e( 'Your own phone number', 'classic-edu-suite' ); ?> <span class="ces-field__optional"><?php esc_html_e( 'optional', 'classic-edu-suite' ); ?></span></label>
					<input type="tel" id="ces-student-phone" name="ces_student_phone" inputmode="tel" placeholder="0803 123 4567"
						value="<?php echo esc_attr( $get( 'own_phone', CES_Parent_Contact::format_phone( (string) get_user_meta( $user_id, self::META_OWN_PHONE, true ) ) ) ); ?>" />
					<span class="ces-field__hint"><?php esc_html_e( 'Only used if we cannot reach you by email. It must be different from your parent’s.', 'classic-edu-suite' ); ?></span>
				</p>
			</fieldset>

			<fieldset class="ces-onboard__group">
				<legend><?php esc_html_e( 'Your parent or guardian', 'classic-edu-suite' ); ?></legend>

				<p class="ces-field">
					<label for="ces-parent-name"><?php esc_html_e( 'Their full name', 'classic-edu-suite' ); ?></label>
					<input type="text" id="ces-parent-name" name="ces_parent_name" required
						value="<?php echo esc_attr( $get( 'name', $contact['name'] ) ); ?>" />
				</p>

				<p class="ces-field">
					<label for="ces-parent-relationship"><?php esc_html_e( 'Who are they to you?', 'classic-edu-suite' ); ?></label>
					<select id="ces-parent-relationship" name="ces_parent_relationship">
						<?php foreach ( CES_Parent_Contact::relationships() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $get( 'relationship', $contact['relationship'] ), $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="ces-field">
					<label for="ces-parent-phone"><?php esc_html_e( 'Their WhatsApp number', 'classic-edu-suite' ); ?></label>
					<input type="tel" id="ces-parent-phone" name="ces_parent_whatsapp" required inputmode="tel" placeholder="0803 123 4567"
						value="<?php echo esc_attr( $get( 'phone', $contact['phone_raw'] ? $contact['phone_raw'] : $contact['phone'] ) ); ?>" />
					<span class="ces-field__hint"><?php esc_html_e( 'The number WhatsApp is actually on. 0803… or +234 803… both work.', 'classic-edu-suite' ); ?></span>
				</p>

				<?php if ( CES_Helpers::enabled( 'accounts_parent_email_enabled' ) ) : ?>
					<p class="ces-field">
						<label for="ces-parent-email"><?php esc_html_e( 'Their email address', 'classic-edu-suite' ); ?> <span class="ces-field__optional"><?php esc_html_e( 'optional', 'classic-edu-suite' ); ?></span></label>
						<input type="email" id="ces-parent-email" name="ces_parent_email" autocomplete="off"
							value="<?php echo esc_attr( $get( 'parent_email', CES_Accounts_Verification::parent_email( $user_id ) ) ); ?>" />
						<span class="ces-field__hint"><?php esc_html_e( 'If you give this, we can write to them ourselves instead of asking you to forward a message.', 'classic-edu-suite' ); ?></span>
					</p>
				<?php endif; ?>

				<p class="ces-field ces-field--check">
					<label>
						<input type="checkbox" name="ces_parent_consent" value="1" required
							<?php checked( $contact['consent'] || ! empty( $values['consent'] ), true ); ?> />
						<?php esc_html_e( 'They know I am using this site and are happy to be contacted about my progress.', 'classic-edu-suite' ); ?>
					</label>
				</p>

				<?php if ( '' !== $terms_url ) : ?>
					<p class="ces-field ces-field--check">
						<label>
							<input type="checkbox" name="ces_terms" value="1" required <?php checked( ! empty( $values['terms'] ), true ); ?> />
							<?php
							printf(
								/* translators: 1: opening link tag, 2: closing link tag. */
								esc_html__( 'I accept the %1$sterms of use%2$s.', 'classic-edu-suite' ),
								'<a href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">',
								'</a>'
							);
							?>
						</label>
					</p>
				<?php endif; ?>
			</fieldset>

			<div class="ces-onboard__promise">
				<h3><?php esc_html_e( 'What we send them, and what we never send', 'classic-edu-suite' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'One message now, asking them to confirm it is them.', 'classic-edu-suite' ); ?></li>
					<li><?php esc_html_e( 'After that, a short progress note — how many papers you sat and your average score. No more than once a week.', 'classic-edu-suite' ); ?></li>
					<li><?php esc_html_e( 'Never your answers, your messages, your coin balance or what you searched for.', 'classic-edu-suite' ); ?></li>
					<li><?php esc_html_e( 'Never their number to anyone outside this school. They can stop the messages at any time.', 'classic-edu-suite' ); ?></li>
				</ul>
				<?php if ( '' !== $privacy_url ) : ?>
					<p><a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Read the full privacy notice', 'classic-edu-suite' ); ?></a></p>
				<?php endif; ?>
			</div>

			<p class="ces-onboard__actions">
				<button type="submit" class="ces-btn ces-btn--primary ces-btn--block">
					<?php esc_html_e( 'Ask my parent to confirm', 'classic-edu-suite' ); ?>
				</button>
			</p>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Step 2: waiting on the parent.
	 *
	 * @param int   $user_id Student.
	 * @param array $flash   Flash payload.
	 * @return string
	 */
	public static function render_waiting( $user_id, array $flash = array() ) {
		$contact = CES_Parent_Contact::get( $user_id );
		$row     = CES_Accounts_Verification::open_row( $user_id );
		$wa_link = CES_Accounts_Verification::parent_wa_link( $user_id );
		$direct  = CES_Accounts_Verification::can_send_directly( $user_id );
		$sent    = isset( $flash['sent'] ) ? (array) $flash['sent'] : array();

		ob_start();
		?>
		<div class="ces-onboard__head">
			<p class="ces-onboard__step"><?php esc_html_e( 'Step 2 of 2', 'classic-edu-suite' ); ?></p>
			<h2 class="ces-onboard__title"><?php esc_html_e( 'Waiting for your parent', 'classic-edu-suite' ); ?></h2>
			<p class="ces-onboard__lead">
				<?php
				printf(
					/* translators: 1: parent name, 2: formatted phone number. */
					esc_html__( 'We need %1$s on %2$s to confirm you. Until then, practice stays locked.', 'classic-edu-suite' ),
					'<strong>' . esc_html( $contact['name'] ) . '</strong>',
					'<strong>' . esc_html( $contact['phone_pretty'] ) . '</strong>'
				);
				?>
			</p>
		</div>

		<?php if ( ! empty( $sent ) ) : ?>
			<div class="ces-notice ces-notice--success">
				<p>
					<?php
					if ( in_array( 'whatsapp', $sent, true ) && in_array( 'email', $sent, true ) ) {
						esc_html_e( 'Sent to them on WhatsApp and by email.', 'classic-edu-suite' );
					} elseif ( in_array( 'whatsapp', $sent, true ) ) {
						esc_html_e( 'Sent to them on WhatsApp.', 'classic-edu-suite' );
					} else {
						esc_html_e( 'Sent to them by email.', 'classic-edu-suite' );
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! $row ) : ?>
			<div class="ces-notice ces-notice--error">
				<p><?php esc_html_e( 'The last request has run out of time. Press “Send the request again” at the bottom of this page and your parent will get a fresh one.', 'classic-edu-suite' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $wa_link ) : ?>
			<div class="ces-onboard__card ces-onboard__card--action">
				<h3><?php echo $direct ? esc_html__( 'Give them a nudge yourself', 'classic-edu-suite' ) : esc_html__( 'Send it to them now', 'classic-edu-suite' ); ?></h3>
				<p>
					<?php
					echo $direct
						? esc_html__( 'Already sent, but a message from you is harder to ignore. This opens WhatsApp with everything written — just press send.', 'classic-edu-suite' )
						: esc_html__( 'This opens WhatsApp with the message already written and addressed to them. Press send, then wait for them to tap the link or read you the code.', 'classic-edu-suite' );
					?>
				</p>
				<p>
					<a class="ces-btn ces-btn--whatsapp ces-btn--block" href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open WhatsApp and send', 'classic-edu-suite' ); ?>
					</a>
				</p>
				<p class="ces-onboard__hint"><?php esc_html_e( 'Nothing you do here can approve the account for them — they still have to answer on their own phone.', 'classic-edu-suite' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="ces-onboard__card">
			<h3><?php esc_html_e( 'They read you a code?', 'classic-edu-suite' ); ?></h3>
			<p><?php esc_html_e( 'Type the six digits from their message here.', 'classic-edu-suite' ); ?></p>

			<form class="ces-onboard__code" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION_CODE . '_' . $user_id ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CODE ); ?>" />
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::current_url() ); ?>" />

				<label class="screen-reader-text" for="ces-code"><?php esc_html_e( 'Six-digit code', 'classic-edu-suite' ); ?></label>
				<input type="text" id="ces-code" name="ces_code" class="ces-code-input" inputmode="numeric" pattern="[0-9]*"
					maxlength="6" autocomplete="one-time-code" placeholder="000000" required />

				<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Confirm', 'classic-edu-suite' ); ?></button>
			</form>

			<?php if ( $row ) : ?>
				<p class="ces-onboard__hint">
					<?php
					$minutes = max( 1, CES_Helpers::setting_int( 'accounts_code_ttl_minutes', 60 ) );
					printf(
						/* translators: %d: number of minutes. */
						esc_html( _n( 'The code lasts about %d minute. The link in their message lasts longer.', 'The code lasts about %d minutes. The link in their message lasts longer.', $minutes, 'classic-edu-suite' ) ),
						(int) $minutes
					);
					?>
				</p>
			<?php endif; ?>
		</div>

		<div class="ces-onboard__card ces-onboard__card--quiet">
			<h3><?php esc_html_e( 'Something not right?', 'classic-edu-suite' ); ?></h3>
			<ul class="ces-onboard__options">
				<li>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::ACTION_RESEND . '_' . $user_id ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESEND ); ?>" />
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::current_url() ); ?>" />
						<button type="submit" class="ces-link-btn"><?php esc_html_e( 'Send the request again', 'classic-edu-suite' ); ?></button>
						<span class="ces-onboard__hint"><?php esc_html_e( 'Makes a new code and a new link. The old ones stop working.', 'classic-edu-suite' ); ?></span>
					</form>
				</li>
				<li>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::ACTION_RESTART . '_' . $user_id ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESTART ); ?>" />
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::current_url() ); ?>" />
						<button type="submit" class="ces-link-btn"><?php esc_html_e( 'Change my parent’s number', 'classic-edu-suite' ); ?></button>
						<span class="ces-onboard__hint"><?php esc_html_e( 'If you typed it wrongly, or they have a different WhatsApp number.', 'classic-edu-suite' ); ?></span>
					</form>
				</li>
			</ul>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Parent said yes, staff review is pending.
	 *
	 * @param int $user_id Student.
	 * @return string
	 */
	public static function render_review( $user_id ) {
		$record = CES_Accounts_Status::record( $user_id );

		ob_start();
		?>
		<div class="ces-onboard__head">
			<h2 class="ces-onboard__title"><?php esc_html_e( 'Your parent has confirmed — thank you', 'classic-edu-suite' ); ?></h2>
			<p class="ces-onboard__lead"><?php esc_html_e( 'A member of staff checks each new account by hand. That is usually the same day, and you will get an email the moment it is done.', 'classic-edu-suite' ); ?></p>
		</div>

		<div class="ces-onboard__card">
			<p class="ces-onboard__status">
				<span class="ces-pill ces-pill--wait"><?php echo esc_html( $record['status_label'] ); ?></span>
			</p>
			<?php if ( ! empty( $record['flags'] ) ) : ?>
				<p class="ces-onboard__hint"><?php esc_html_e( 'There is something on your account a person needs to look at. If it takes more than a day, contact the school.', 'classic-edu-suite' ); ?></p>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Approved.
	 *
	 * @param int $user_id Student.
	 * @return string
	 */
	public static function render_approved( $user_id ) {
		$record  = CES_Accounts_Status::record( $user_id );
		$contact = CES_Parent_Contact::get( $user_id );

		ob_start();
		?>
		<div class="ces-onboard__head">
			<h2 class="ces-onboard__title"><?php esc_html_e( 'Your account is open', 'classic-edu-suite' ); ?></h2>
			<p class="ces-onboard__lead"><?php esc_html_e( 'Everything is unlocked. Good luck with the revision.', 'classic-edu-suite' ); ?></p>
		</div>

		<div class="ces-onboard__card">
			<ul class="ces-onboard__facts">
				<li>
					<span><?php esc_html_e( 'Status', 'classic-edu-suite' ); ?></span>
					<strong><span class="ces-pill ces-pill--ok"><?php echo esc_html( $record['status_label'] ); ?></span></strong>
				</li>
				<?php if ( '' !== $contact['phone'] ) : ?>
					<li>
						<span><?php esc_html_e( 'Confirmed by', 'classic-edu-suite' ); ?></span>
						<strong><?php echo esc_html( $contact['name'] . ' · ' . $contact['phone_pretty'] ); ?></strong>
					</li>
				<?php endif; ?>
				<?php if ( '' !== $record['class_label'] ) : ?>
					<li>
						<span><?php esc_html_e( 'Class', 'classic-edu-suite' ); ?></span>
						<strong><?php echo esc_html( $record['class_label'] ); ?></strong>
					</li>
				<?php endif; ?>
			</ul>

			<p>
				<a class="ces-btn ces-btn--primary" href="<?php echo esc_url( CES_Helpers::dashboard_url() ); ?>">
					<?php esc_html_e( 'Go to my dashboard', 'classic-edu-suite' ); ?>
				</a>
			</p>
		</div>

		<div class="ces-onboard__card ces-onboard__card--quiet">
			<h3><?php esc_html_e( 'Changed phone?', 'classic-edu-suite' ); ?></h3>
			<p><?php esc_html_e( 'If your parent’s WhatsApp number changes, update it on the Parent / Guardian tab. You will not have to be approved again.', 'classic-edu-suite' ); ?></p>
			<p><a href="<?php echo esc_url( CES_Helpers::dashboard_url( 'parent' ) ); ?>"><?php esc_html_e( 'Open the Parent / Guardian tab', 'classic-edu-suite' ); ?></a></p>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Declined, with a way back.
	 *
	 * @param int $user_id Student.
	 * @return string
	 */
	public static function render_declined( $user_id ) {
		$record = CES_Accounts_Status::record( $user_id );

		ob_start();
		?>
		<div class="ces-onboard__head">
			<h2 class="ces-onboard__title"><?php esc_html_e( 'Your account is not approved', 'classic-edu-suite' ); ?></h2>
			<p class="ces-onboard__lead"><?php esc_html_e( 'Practice is closed for now. This is usually a wrong number or a parent who pressed the wrong button, and it can be put right.', 'classic-edu-suite' ); ?></p>
		</div>

		<div class="ces-onboard__card">
			<?php if ( '' !== $record['decline_reason'] ) : ?>
				<p class="ces-onboard__reason"><em><?php echo esc_html( $record['decline_reason'] ); ?></em></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION_RESTART . '_' . $user_id ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESTART ); ?>" />
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::current_url() ); ?>" />
				<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Try again with the right details', 'classic-edu-suite' ); ?></button>
			</form>

			<p class="ces-onboard__hint"><?php esc_html_e( 'If your parent did mean to refuse, please do not ask again — speak to them first, or contact the school.', 'classic-edu-suite' ); ?></p>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Suspended by staff.
	 *
	 * @param int $user_id Student.
	 * @return string
	 */
	public static function render_suspended( $user_id ) {
		$record = CES_Accounts_Status::record( $user_id );

		ob_start();
		?>
		<div class="ces-onboard__head">
			<h2 class="ces-onboard__title"><?php esc_html_e( 'Your access is paused', 'classic-edu-suite' ); ?></h2>
			<p class="ces-onboard__lead"><?php esc_html_e( 'A member of staff has paused this account. Please contact the school — they can switch it back on.', 'classic-edu-suite' ); ?></p>
		</div>

		<?php if ( '' !== $record['decline_reason'] ) : ?>
			<div class="ces-onboard__card"><p><em><?php echo esc_html( $record['decline_reason'] ); ?></em></p></div>
		<?php endif; ?>
		<?php

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Plumbing.
	 * ------------------------------------------------------------------ */

	/**
	 * Refuse a logged-out post.
	 *
	 * @return void
	 */
	public static function reject_guest() {
		wp_safe_redirect( CES_Helpers::login_url( CES_Helpers::dashboard_url() ) );
		exit;
	}

	/**
	 * Send the student back to where the form was.
	 *
	 * @return void
	 */
	private static function bounce() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$target = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';

		if ( '' === $target ) {
			$target = CES_Helpers::dashboard_url( CES_Accounts_Dashboard::SLUG );
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Stash a message for the next page load.
	 *
	 * @param int   $user_id Student.
	 * @param array $data    Payload.
	 * @return void
	 */
	private static function flash( $user_id, array $data ) {
		set_transient( self::FLASH_KEY . absint( $user_id ), $data, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Read and clear the stashed message.
	 *
	 * @param int $user_id Student.
	 * @return array
	 */
	private static function take_flash( $user_id ) {
		$key  = self::FLASH_KEY . absint( $user_id );
		$data = get_transient( $key );

		if ( ! is_array( $data ) ) {
			return array();
		}

		delete_transient( $key );

		return $data;
	}

	/**
	 * The URL being viewed, for the redirect field.
	 *
	 * @return string
	 */
	private static function current_url() {
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$url = home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );

			if ( $url ) {
				return $url;
			}
		}

		return CES_Helpers::dashboard_url( CES_Accounts_Dashboard::SLUG );
	}
}
