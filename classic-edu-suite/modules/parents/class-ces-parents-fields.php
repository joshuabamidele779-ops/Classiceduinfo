<?php
/**
 * Where the parent number is actually collected.
 *
 * Three places, because no single one of them covers every student:
 *
 * 1. The WordPress and WooCommerce registration forms, when the site uses
 *    them. Validated server-side through `registration_errors`.
 * 2. A front-end form, rendered on the student dashboard and by the
 *    `[ces_parent_contact]` shortcode. This is the one that matters: the site
 *    runs Profile Builder on `/login/`, which renders its own markup and will
 *    not show our fields, so every student who registers there lands on the
 *    dashboard with the form waiting for them.
 * 3. The admin user profile screen, for staff filling gaps by hand.
 *
 * The form posts to `admin-post.php` and needs no JavaScript, so it still
 * works on a feature phone browser with a dead connection to the REST API.
 *
 * @package ClassicEduSuite\Parents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and processes the parent contact form everywhere it appears.
 */
class CES_Parents_Fields {

	/**
	 * admin-post action for the front-end form.
	 */
	const ACTION = 'ces_parent_save';

	/**
	 * Transient prefix holding a failed submission for redisplay.
	 */
	const FLASH_KEY = 'ces_parent_flash_';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Front-end form handler, logged in only.
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'reject_guest' ) );

		// Core registration form.
		add_action( 'register_form', array( __CLASS__, 'render_registration_fields' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'validate_registration' ), 10, 3 );

		// WooCommerce registration form.
		add_action( 'woocommerce_register_form', array( __CLASS__, 'render_registration_fields' ) );
		add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'validate_registration' ), 10, 3 );

		// Whatever rendered the form, save what was posted.
		add_action( 'user_register', array( __CLASS__, 'save_on_register' ) );

		// Admin user profile.
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_fields' ) );

		// The shortcode students are pointed at from anywhere on the site.
		add_shortcode( 'ces_parent_contact', array( __CLASS__, 'shortcode' ) );
	}

	/* ---------------------------------------------------------------------
	 * Registration forms.
	 * ------------------------------------------------------------------ */

	/**
	 * Print the fields inside a registration form.
	 *
	 * @return void
	 */
	public static function render_registration_fields() {
		if ( ! CES_Helpers::enabled( 'parents_capture_enabled' ) ) {
			return;
		}

		$required = CES_Helpers::enabled( 'parents_required_at_signup' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- redisplaying a failed core registration post.
		$name  = isset( $_POST['ces_parent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_parent_name'] ) ) : '';
		$phone = isset( $_POST['ces_parent_whatsapp'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_parent_whatsapp'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<p class="ces-parent-field">';
		echo '<label for="ces_parent_name">' . esc_html__( 'Parent / guardian name', 'classic-edu-suite' );
		if ( $required ) {
			echo ' <span class="required">*</span>';
		}
		echo '<br /><input type="text" name="ces_parent_name" id="ces_parent_name" class="input" value="' . esc_attr( $name ) . '" size="25" autocomplete="off" /></label>';
		echo '</p>';

		echo '<p class="ces-parent-field">';
		echo '<label for="ces_parent_whatsapp">' . esc_html__( 'Parent / guardian WhatsApp number', 'classic-edu-suite' );
		if ( $required ) {
			echo ' <span class="required">*</span>';
		}
		echo '<br /><input type="tel" name="ces_parent_whatsapp" id="ces_parent_whatsapp" class="input" value="' . esc_attr( $phone ) . '" size="25" inputmode="tel" placeholder="0803 123 4567" /></label>';
		echo '</p>';

		echo '<p class="description ces-parent-note">' . esc_html( self::consent_text() ) . '</p>';
	}

	/**
	 * Block registration when the number is required and missing or bad.
	 *
	 * @param WP_Error $errors               Errors so far.
	 * @param string   $sanitized_user_login Login being registered.
	 * @param string   $user_email           Email being registered.
	 * @return WP_Error
	 */
	public static function validate_registration( $errors, $sanitized_user_login = '', $user_email = '' ) {
		unset( $sanitized_user_login, $user_email );

		if ( ! is_wp_error( $errors ) ) {
			$errors = new WP_Error();
		}

		if ( ! CES_Helpers::enabled( 'parents_capture_enabled' ) || ! CES_Helpers::enabled( 'parents_required_at_signup' ) ) {
			return $errors;
		}

		// Only police a form that actually carried our fields. A registration
		// posted by some other plugin's form is handled by the dashboard prompt
		// instead, and must not be made impossible here.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core verifies the registration nonce itself.
		if ( ! isset( $_POST['ces_parent_whatsapp'] ) ) {
			return $errors;
		}

		$input = array(
			'name'    => isset( $_POST['ces_parent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_parent_name'] ) ) : '',
			'phone'   => sanitize_text_field( wp_unslash( $_POST['ces_parent_whatsapp'] ) ),
			'consent' => 1,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		foreach ( CES_Parent_Contact::validate( $input ) as $field => $message ) {
			$errors->add( 'ces_parent_' . $field, '<strong>' . esc_html__( 'Error', 'classic-edu-suite' ) . '</strong>: ' . esc_html( $message ) );
		}

		return $errors;
	}

	/**
	 * Persist whatever the registration form carried.
	 *
	 * @param int $user_id Freshly created user.
	 * @return void
	 */
	public static function save_on_register( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the registering plugin owns the nonce.
		if ( empty( $_POST['ces_parent_whatsapp'] ) ) {
			return;
		}

		$input = array(
			'name'         => isset( $_POST['ces_parent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_parent_name'] ) ) : '',
			'phone'        => sanitize_text_field( wp_unslash( $_POST['ces_parent_whatsapp'] ) ),
			'relationship' => isset( $_POST['ces_parent_relationship'] ) ? sanitize_key( wp_unslash( $_POST['ces_parent_relationship'] ) ) : 'guardian',
			'consent'      => 1,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// A name is wanted but must never be the reason a signup is lost.
		if ( '' === trim( $input['name'] ) ) {
			$user          = get_userdata( $user_id );
			$input['name'] = $user ? sprintf(
				/* translators: %s: student display name. */
				__( 'Parent of %s', 'classic-edu-suite' ),
				$user->display_name
			) : __( 'Parent / guardian', 'classic-edu-suite' );
		}

		CES_Parent_Contact::save( $user_id, $input );
		delete_transient( 'ces_parent_coverage' );
	}

	/* ---------------------------------------------------------------------
	 * Admin profile screen.
	 * ------------------------------------------------------------------ */

	/**
	 * Print the fields on the user profile screen.
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public static function render_profile_fields( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		$record = CES_Parent_Contact::get( $user->ID );

		echo '<h2>' . esc_html__( 'Parent / guardian contact', 'classic-edu-suite' ) . '</h2>';
		wp_nonce_field( 'ces_parent_profile_' . $user->ID, 'ces_parent_profile_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th><label for="ces_parent_name">' . esc_html__( 'Name', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="ces_parent_name" id="ces_parent_name" value="' . esc_attr( $record['name'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th><label for="ces_parent_whatsapp">' . esc_html__( 'WhatsApp number', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<input type="tel" class="regular-text" name="ces_parent_whatsapp" id="ces_parent_whatsapp" value="' . esc_attr( $record['phone_raw'] ? $record['phone_raw'] : $record['phone'] ) . '" />';

		if ( $record['phone'] ) {
			echo '<p class="description">' . esc_html__( 'Stored as:', 'classic-edu-suite' ) . ' <code>' . esc_html( $record['phone'] ) . '</code> · ' . esc_html( $record['phone_pretty'] ) . '</p>';
		}

		echo '</td></tr>';

		echo '<tr><th><label for="ces_parent_relationship">' . esc_html__( 'Relationship', 'classic-edu-suite' ) . '</label></th><td>';
		echo '<select name="ces_parent_relationship" id="ces_parent_relationship">';
		foreach ( CES_Parent_Contact::relationships() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $record['relationship'], $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th>' . esc_html__( 'Consent', 'classic-edu-suite' ) . '</th><td>';
		echo '<label><input type="checkbox" name="ces_parent_consent" value="1" ' . checked( $record['consent'], true, false ) . ' /> ';
		echo esc_html__( 'The parent or guardian agreed to be contacted about this student.', 'classic-edu-suite' ) . '</label>';

		if ( $record['updated_at'] ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: date. */
					__( 'Last updated %s', 'classic-edu-suite' ),
					$record['updated_at']
				)
			) . '</p>';
		}

		if ( $record['last_contacted'] ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: date, 2: number of messages. */
					__( 'Last messaged %1$s · %2$d message(s) logged', 'classic-edu-suite' ),
					$record['last_contacted'],
					$record['contact_count']
				)
			) . '</p>';
		}

		echo '</td></tr>';
		echo '</tbody></table>';
	}

	/**
	 * Save the profile screen fields.
	 *
	 * @param int $user_id User being saved.
	 * @return void
	 */
	public static function save_profile_fields( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( empty( $_POST['ces_parent_profile_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['ces_parent_profile_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'ces_parent_profile_' . $user_id ) ) {
			return;
		}

		$phone = isset( $_POST['ces_parent_whatsapp'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_parent_whatsapp'] ) ) : '';

		// Clearing the number is a legitimate action — a parent withdrawing.
		if ( '' === trim( $phone ) ) {
			CES_Parent_Contact::delete( $user_id );
			delete_transient( 'ces_parent_coverage' );

			return;
		}

		CES_Parent_Contact::save(
			$user_id,
			array(
				'name'         => isset( $_POST['ces_parent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_parent_name'] ) ) : '',
				'phone'        => $phone,
				'relationship' => isset( $_POST['ces_parent_relationship'] ) ? sanitize_key( wp_unslash( $_POST['ces_parent_relationship'] ) ) : 'guardian',
				'consent'      => ! empty( $_POST['ces_parent_consent'] ),
			)
		);

		delete_transient( 'ces_parent_coverage' );
	}

	/* ---------------------------------------------------------------------
	 * Front-end form.
	 * ------------------------------------------------------------------ */

	/**
	 * `[ces_parent_contact]` — the standalone form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title' => '',
			),
			$atts,
			'ces_parent_contact'
		);

		if ( class_exists( 'CES_Assets' ) ) {
			CES_Assets::enqueue_base();
		}

		if ( ! is_user_logged_in() ) {
			return '<div class="ces-notice"><p>'
				. esc_html__( 'Sign in to add your parent or guardian’s number.', 'classic-edu-suite' )
				. ' <a href="' . esc_url( CES_Helpers::login_url() ) . '">' . esc_html__( 'Sign in', 'classic-edu-suite' ) . '</a></p></div>';
		}

		return self::render_form( get_current_user_id(), (string) $atts['title'] );
	}

	/**
	 * The consent sentence shown under the fields.
	 *
	 * @return string
	 */
	public static function consent_text() {
		$text = (string) CES_Helpers::setting(
			'parents_consent_text',
			'We send your parent or guardian a short progress update. We never share their number with anyone else.'
		);

		return trim( $text );
	}

	/**
	 * Render the form markup.
	 *
	 * @param int    $user_id Student.
	 * @param string $title   Optional heading override.
	 * @return string
	 */
	public static function render_form( $user_id, $title = '' ) {
		if ( method_exists( 'CES_Parents_Module', 'enqueue_style' ) ) {
			CES_Parents_Module::enqueue_style();
		}

		$user_id = absint( $user_id );
		$record  = CES_Parent_Contact::get( $user_id );
		$flash   = self::take_flash( $user_id );
		$errors  = isset( $flash['errors'] ) && is_array( $flash['errors'] ) ? $flash['errors'] : array();
		$values  = isset( $flash['values'] ) && is_array( $flash['values'] ) ? $flash['values'] : array();
		$saved   = ! empty( $flash['saved'] );

		$name  = isset( $values['name'] ) ? (string) $values['name'] : $record['name'];
		$phone = isset( $values['phone'] ) ? (string) $values['phone'] : ( $record['phone_raw'] ? $record['phone_raw'] : $record['phone'] );
		$rel   = isset( $values['relationship'] ) ? (string) $values['relationship'] : $record['relationship'];

		if ( '' === trim( (string) $title ) ) {
			$title = $record['phone']
				? __( 'Parent / guardian contact', 'classic-edu-suite' )
				: __( 'Add your parent or guardian', 'classic-edu-suite' );
		}

		$redirect = self::current_url();

		ob_start();
		?>
		<div class="ces-parent-card<?php echo $record['phone'] ? '' : ' ces-parent-card--prompt'; ?>">
			<h3 class="ces-parent-card__title"><?php echo esc_html( $title ); ?></h3>

			<?php if ( $saved ) : ?>
				<div class="ces-notice ces-notice--success"><p><?php esc_html_e( 'Saved. Thank you.', 'classic-edu-suite' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="ces-notice ces-notice--error">
					<ul>
						<?php foreach ( $errors as $message ) : ?>
							<li><?php echo esc_html( $message ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<p class="ces-parent-card__intro"><?php echo esc_html( self::consent_text() ); ?></p>

			<form class="ces-parent-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION . '_' . $user_id ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>" />

				<p class="ces-field">
					<label for="ces-parent-name"><?php esc_html_e( 'Parent / guardian name', 'classic-edu-suite' ); ?></label>
					<input type="text" id="ces-parent-name" name="ces_parent_name" value="<?php echo esc_attr( $name ); ?>" required />
				</p>

				<p class="ces-field">
					<label for="ces-parent-phone"><?php esc_html_e( 'Their WhatsApp number', 'classic-edu-suite' ); ?></label>
					<input type="tel" id="ces-parent-phone" name="ces_parent_whatsapp" value="<?php echo esc_attr( $phone ); ?>" inputmode="tel" placeholder="0803 123 4567" required />
					<span class="ces-field__hint"><?php esc_html_e( 'Use the number WhatsApp is on. 0803… or +234 803… both work.', 'classic-edu-suite' ); ?></span>
				</p>

				<p class="ces-field">
					<label for="ces-parent-relationship"><?php esc_html_e( 'Who are they to you?', 'classic-edu-suite' ); ?></label>
					<select id="ces-parent-relationship" name="ces_parent_relationship">
						<?php foreach ( CES_Parent_Contact::relationships() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $rel, $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<?php if ( CES_Helpers::enabled( 'parents_consent_required' ) ) : ?>
					<p class="ces-field ces-field--check">
						<label>
							<input type="checkbox" name="ces_parent_consent" value="1" <?php checked( $record['consent'], true ); ?> required />
							<?php esc_html_e( 'They are happy for us to message them about my progress.', 'classic-edu-suite' ); ?>
						</label>
					</p>
				<?php endif; ?>

				<p class="ces-parent-form__actions">
					<button type="submit" class="ces-btn ces-btn--primary"><?php esc_html_e( 'Save', 'classic-edu-suite' ); ?></button>
				</p>
			</form>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Handle the front-end form post.
	 *
	 * @return void
	 */
	public static function handle_submit() {
		if ( ! is_user_logged_in() ) {
			self::reject_guest();
		}

		$user_id = get_current_user_id();

		check_admin_referer( self::ACTION . '_' . $user_id );

		if ( ! CES_Helpers::rate_limit( 'parent_contact_save', 10, 5 * MINUTE_IN_SECONDS ) ) {
			self::flash(
				$user_id,
				array(
					'errors' => array( __( 'Too many attempts. Please wait a few minutes and try again.', 'classic-edu-suite' ) ),
				)
			);

			self::bounce();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$input = array(
			'name'         => isset( $_POST['ces_parent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_parent_name'] ) ) : '',
			'phone'        => isset( $_POST['ces_parent_whatsapp'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_parent_whatsapp'] ) ) : '',
			'relationship' => isset( $_POST['ces_parent_relationship'] ) ? sanitize_key( wp_unslash( $_POST['ces_parent_relationship'] ) ) : 'guardian',
			'consent'      => ! empty( $_POST['ces_parent_consent'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = CES_Parent_Contact::save( $user_id, $input );

		if ( is_wp_error( $result ) ) {
			self::flash(
				$user_id,
				array(
					'errors' => array_values( $result->get_error_messages() ),
					'values' => $input,
				)
			);

			self::bounce();
		}

		delete_transient( 'ces_parent_coverage' );

		self::flash( $user_id, array( 'saved' => true ) );
		self::bounce();
	}

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
	 * Send the student back where they came from.
	 *
	 * @return void
	 */
	private static function bounce() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$target = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';

		if ( '' === $target ) {
			$target = CES_Helpers::dashboard_url( 'parent' );
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Stash a submission result for one page load.
	 *
	 * @param int   $user_id Student.
	 * @param array $data    Flash payload.
	 * @return void
	 */
	private static function flash( $user_id, array $data ) {
		set_transient( self::FLASH_KEY . absint( $user_id ), $data, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Read and clear the stashed result.
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
	 * The URL the student is currently on, for the redirect field.
	 *
	 * @return string
	 */
	private static function current_url() {
		$url = '';

		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$url = home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		}

		return $url ? $url : CES_Helpers::dashboard_url( 'parent' );
	}
}
