<?php
/**
 * The parent's page: approve, refuse, and afterwards see progress.
 *
 * Reached from one link, with no account and no password, because a parent
 * will not create an account to answer a question they did not ask for. The
 * link carries a 48-character token; the token is the credential.
 *
 * Two kinds of token arrive here:
 *
 *   - An **approval token**, tied to one open request. It shows the decision
 *     page, and is spent the moment the parent answers.
 *   - A **progress token**, issued to the parent once they have approved and
 *     kept on the student's account until revoked. It shows the same short
 *     progress summary the school would send them, lets them stop the
 *     messages, and lets them withdraw consent altogether.
 *
 * Every decision is a POST. This matters more than it sounds: WhatsApp, Gmail
 * and most link scanners fetch a URL to build a preview, and a GET that
 * approved an account would mean WhatsApp's own crawler approving students.
 *
 * The page renders inside the theme when `accounts_portal_page_id` points at
 * a page carrying `[ces_parent_portal]`, and otherwise as a self-contained
 * page this class prints itself — so the feature works on an install where
 * nobody got round to making that page.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * The parent-facing approval and progress page.
 */
class CES_Accounts_Portal {

	/**
	 * admin-post action for a decision.
	 */
	const ACTION_DECIDE = 'ces_parent_decide';

	/**
	 * admin-post action for the message preferences.
	 */
	const ACTION_PREFS = 'ces_parent_prefs';

	/**
	 * Shortcode for the themed version of this page.
	 */
	const SHORTCODE = 'ces_parent_portal';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );

		foreach ( array( self::ACTION_DECIDE, self::ACTION_PREFS ) as $action ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'route' ) );
			add_action( 'admin_post_nopriv_' . $action, array( __CLASS__, 'route' ) );
		}
	}

	/**
	 * The token on this request, if any.
	 *
	 * @return string
	 */
	public static function token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token *is* the credential, and this is a read-only view.
		$raw = isset( $_GET[ CES_Accounts_Verification::QUERY_TOKEN ] ) ? wp_unslash( $_GET[ CES_Accounts_Verification::QUERY_TOKEN ] ) : '';

		return preg_replace( '/[^A-Za-z0-9]/', '', (string) $raw );
	}

	/**
	 * Catch a token arriving on a URL that is not the portal page.
	 *
	 * @return void
	 */
	public static function maybe_render() {
		$token = self::token();

		if ( '' === $token ) {
			return;
		}

		$page_id = CES_Helpers::setting_int( 'accounts_portal_page_id', 0 );

		// A themed page exists and we are on it: leave it to the shortcode.
		if ( $page_id > 0 && is_page( $page_id ) ) {
			return;
		}

		// A themed page exists but the link points elsewhere: send them there
		// rather than printing a page that ignores the school's own design.
		if ( $page_id > 0 ) {
			$permalink = get_permalink( $page_id );

			if ( $permalink ) {
				wp_safe_redirect( add_query_arg( CES_Accounts_Verification::QUERY_TOKEN, $token, $permalink ) );
				exit;
			}
		}

		self::standalone( self::render( $token ) );
	}

	/**
	 * `[ces_parent_portal]`.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		unset( $atts );

		if ( class_exists( 'CES_Assets' ) ) {
			CES_Assets::enqueue_base();
		}

		if ( class_exists( 'CES_Accounts_Module' ) ) {
			CES_Accounts_Module::enqueue_style();
		}

		$token = self::token();

		if ( '' === $token ) {
			return '<div class="ces-notice"><p>'
				. esc_html__( 'Open this page from the link in the message we sent you.', 'classic-edu-suite' )
				. '</p></div>';
		}

		return self::render( $token );
	}

	/* ---------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	/**
	 * Work out which page this token deserves.
	 *
	 * @param string $token Plain token.
	 * @return string Markup.
	 */
	public static function render( $token ) {
		nocache_headers();

		if ( ! CES_Helpers::rate_limit( 'parent_portal_view', 60, 5 * MINUTE_IN_SECONDS ) ) {
			return self::panel(
				__( 'Please try again shortly', 'classic-edu-suite' ),
				'<p>' . esc_html__( 'This page has been opened a great many times from your connection. Wait a few minutes and open the link again.', 'classic-edu-suite' ) . '</p>'
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		$done = isset( $_GET['ces_done'] ) ? sanitize_key( wp_unslash( $_GET['ces_done'] ) ) : '';

		$row = CES_Accounts_Verification::find_by_token( $token );

		if ( $row && 'pending' === (string) $row['status'] && ! CES_Accounts_Verification::is_expired( $row ) ) {
			return self::render_decision( $row, $token );
		}

		// A progress token: issued after approval, good until revoked.
		$user_id = CES_Accounts_Verification::user_by_portal_token( $token );

		if ( $user_id ) {
			return self::render_progress( $user_id, $token, $done );
		}

		if ( $row ) {
			return self::render_spent( $row, $done );
		}

		return self::render_unknown();
	}

	/**
	 * The decision page.
	 *
	 * @param array  $row   Open request row.
	 * @param string $token Plain token.
	 * @return string
	 */
	public static function render_decision( array $row, $token ) {
		$user_id = (int) $row['user_id'];
		$user    = get_userdata( $user_id );
		$contact = CES_Parent_Contact::get( $user_id );
		$record  = CES_Accounts_Status::record( $user_id );
		$student = $user ? $user->display_name : __( 'this student', 'classic-edu-suite' );

		ob_start();
		?>
		<p class="ces-portal__kicker"><?php echo esc_html( CES_Accounts_Notify::site_name() ); ?></p>
		<h1 class="ces-portal__title"><?php esc_html_e( 'Is this your child?', 'classic-edu-suite' ); ?></h1>

		<p class="ces-portal__lead">
			<?php
			printf(
				/* translators: %s: student name. */
				esc_html__( '%s has opened a free study account and gave your number as their parent or guardian. Nothing is open to them until you answer.', 'classic-edu-suite' ),
				'<strong>' . esc_html( $student ) . '</strong>'
			);
			?>
		</p>

		<ul class="ces-portal__facts">
			<li>
				<span><?php esc_html_e( 'Student', 'classic-edu-suite' ); ?></span>
				<strong><?php echo esc_html( $student ); ?></strong>
			</li>
			<?php if ( '' !== $record['class_label'] ) : ?>
				<li>
					<span><?php esc_html_e( 'Class given', 'classic-edu-suite' ); ?></span>
					<strong><?php echo esc_html( $record['class_label'] ); ?></strong>
				</li>
			<?php endif; ?>
			<li>
				<span><?php esc_html_e( 'They say you are their', 'classic-edu-suite' ); ?></span>
				<strong><?php echo esc_html( $contact['relation_label'] ); ?></strong>
			</li>
			<li>
				<span><?php esc_html_e( 'Signed up', 'classic-edu-suite' ); ?></span>
				<strong><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $row['created_at'] ) ); ?></strong>
			</li>
		</ul>

		<div class="ces-portal__promise">
			<h2><?php esc_html_e( 'If you say yes', 'classic-edu-suite' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'They get past questions, practice exams and syllabus videos.', 'classic-edu-suite' ); ?></li>
				<li><?php esc_html_e( 'You get a short progress note — papers sat and average score — no more than once a week.', 'classic-edu-suite' ); ?></li>
				<li><?php esc_html_e( 'You can stop those messages, or withdraw this consent, at any time from the same link.', 'classic-edu-suite' ); ?></li>
				<li><?php esc_html_e( 'We never pass your number to anyone else, and we never send you advertising.', 'classic-edu-suite' ); ?></li>
			</ul>
		</div>

		<form class="ces-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION_DECIDE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DECIDE ); ?>" />
			<input type="hidden" name="ces_token" value="<?php echo esc_attr( $token ); ?>" />

			<button type="submit" name="decision" value="approve" class="ces-btn ces-btn--primary ces-btn--block">
				<?php esc_html_e( 'Yes — this is my child', 'classic-edu-suite' ); ?>
			</button>

			<button type="submit" name="decision" value="decline" class="ces-btn ces-btn--ghost ces-btn--block">
				<?php esc_html_e( 'No — I do not know this person', 'classic-edu-suite' ); ?>
			</button>
		</form>

		<p class="ces-portal__note">
			<?php esc_html_e( 'Pressing “No” closes the account straight away and stops every message to this number.', 'classic-edu-suite' ); ?>
		</p>
		<?php

		return self::panel( '', (string) ob_get_clean() );
	}

	/**
	 * The progress page, for a parent who has already approved.
	 *
	 * @param int    $user_id Student.
	 * @param string $token   Plain progress token.
	 * @param string $done    Flag from our own redirect.
	 * @return string
	 */
	public static function render_progress( $user_id, $token, $done = '' ) {
		$user    = get_userdata( absint( $user_id ) );
		$contact = CES_Parent_Contact::get( $user_id );
		$student = $user ? $user->display_name : __( 'your child', 'classic-edu-suite' );
		$opted   = CES_Accounts_Verification::opted_out( $user_id );
		$status  = CES_Accounts_Status::status( $user_id );

		$summary = class_exists( 'CES_Parents_Report' )
			? CES_Parents_Report::summary( $user_id )
			: array();

		ob_start();
		?>
		<p class="ces-portal__kicker"><?php echo esc_html( CES_Accounts_Notify::site_name() ); ?></p>
		<h1 class="ces-portal__title">
			<?php
			printf(
				/* translators: %s: student name. */
				esc_html__( '%s’s progress', 'classic-edu-suite' ),
				esc_html( $student )
			);
			?>
		</h1>

		<?php if ( 'approved' === $done ) : ?>
			<div class="ces-notice ces-notice--success">
				<p><?php esc_html_e( 'Thank you. Their account is open, and this page is yours to keep — bookmark it to check on them any time.', 'classic-edu-suite' ); ?></p>
			</div>
		<?php elseif ( 'prefs' === $done ) : ?>
			<div class="ces-notice ces-notice--success">
				<p><?php esc_html_e( 'Saved.', 'classic-edu-suite' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'suspended' === $status || 'declined' === $status ) : ?>
			<div class="ces-notice ces-notice--error">
				<p><?php esc_html_e( 'This account is currently closed. If that is not what you wanted, please contact the school.', 'classic-edu-suite' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( isset( $summary['exams'], $summary['average'], $summary['best'] ) ) : ?>
			<ul class="ces-portal__stats">
				<li>
					<strong><?php echo esc_html( number_format_i18n( (int) $summary['exams'] ) ); ?></strong>
					<span><?php esc_html_e( 'practice papers this month', 'classic-edu-suite' ); ?></span>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( (float) $summary['average'], 1 ) ); ?>%</strong>
					<span><?php esc_html_e( 'average score', 'classic-edu-suite' ); ?></span>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( (float) $summary['best'], 1 ) ); ?>%</strong>
					<span><?php esc_html_e( 'best score', 'classic-edu-suite' ); ?></span>
				</li>
				<li>
					<strong>
						<?php
						$ces_last = isset( $summary['last_active_h'] ) ? (string) $summary['last_active_h'] : '';
						echo esc_html( '' !== $ces_last ? $ces_last : __( 'not yet', 'classic-edu-suite' ) );
						?>
					</strong>
					<span><?php esc_html_e( 'last studied', 'classic-edu-suite' ); ?></span>
				</li>
			</ul>

			<?php if ( ! empty( $summary['strongest'] ) || ! empty( $summary['weakest'] ) ) : ?>
				<p class="ces-portal__lead">
					<?php
					if ( ! empty( $summary['strongest'] ) ) {
						printf(
							/* translators: %s: subject name. */
							esc_html__( 'Strongest subject so far: %s.', 'classic-edu-suite' ),
							'<strong>' . esc_html( $summary['strongest'] ) . '</strong>'
						);
						echo ' ';
					}

					if ( ! empty( $summary['weakest'] ) ) {
						printf(
							/* translators: %s: subject name. */
							esc_html__( 'The one to help them with: %s.', 'classic-edu-suite' ),
							'<strong>' . esc_html( $summary['weakest'] ) . '</strong>'
						);
					}
					?>
				</p>
			<?php endif; ?>
		<?php else : ?>
			<p class="ces-portal__lead"><?php esc_html_e( 'There is nothing to report yet. Check back once they have sat a paper or two.', 'classic-edu-suite' ); ?></p>
		<?php endif; ?>

		<div class="ces-portal__prefs">
			<h2><?php esc_html_e( 'Your choices', 'classic-edu-suite' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION_PREFS ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_PREFS ); ?>" />
				<input type="hidden" name="ces_token" value="<?php echo esc_attr( $token ); ?>" />

				<p class="ces-field ces-field--check">
					<label>
						<input type="checkbox" name="ces_opt_out" value="1" <?php checked( $opted, true ); ?> />
						<?php esc_html_e( 'Stop sending me progress messages.', 'classic-edu-suite' ); ?>
					</label>
					<span class="ces-field__hint"><?php esc_html_e( 'Their account stays open. You simply stop hearing from us.', 'classic-edu-suite' ); ?></span>
				</p>

				<button type="submit" name="ces_pref_action" value="save" class="ces-btn ces-btn--primary">
					<?php esc_html_e( 'Save', 'classic-edu-suite' ); ?>
				</button>
			</form>

			<details class="ces-portal__withdraw">
				<summary><?php esc_html_e( 'I want to withdraw my consent completely', 'classic-edu-suite' ); ?></summary>

				<p><?php esc_html_e( 'This closes their study account and deletes your number from it. They will not be able to practise here until a parent confirms them again.', 'classic-edu-suite' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::ACTION_PREFS ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_PREFS ); ?>" />
					<input type="hidden" name="ces_token" value="<?php echo esc_attr( $token ); ?>" />

					<p class="ces-field ces-field--check">
						<label>
							<input type="checkbox" name="ces_confirm" value="1" required />
							<?php esc_html_e( 'Yes, I understand this closes their account.', 'classic-edu-suite' ); ?>
						</label>
					</p>

					<button type="submit" name="ces_pref_action" value="withdraw" class="ces-btn ces-btn--ghost">
						<?php esc_html_e( 'Withdraw my consent', 'classic-edu-suite' ); ?>
					</button>
				</form>
			</details>
		</div>

		<p class="ces-portal__note">
			<?php
			printf(
				/* translators: %s: formatted phone number. */
				esc_html__( 'This page was opened with the private link we sent to %s. Anyone with that link can see it, so please do not pass it on.', 'classic-edu-suite' ),
				esc_html( $contact['phone_pretty'] )
			);
			?>
		</p>
		<?php

		return self::panel( '', (string) ob_get_clean() );
	}

	/**
	 * A token that has already been answered.
	 *
	 * @param array  $row  The row it belonged to.
	 * @param string $done Flag from our own redirect.
	 * @return string
	 */
	public static function render_spent( array $row, $done = '' ) {
		$status = (string) $row['status'];

		if ( 'declined' === $status || 'declined' === $done ) {
			return self::panel(
				__( 'Thank you — that is noted', 'classic-edu-suite' ),
				'<p>' . esc_html__( 'We have closed that account and you will hear nothing further about it from us.', 'classic-edu-suite' ) . '</p>'
				. '<p>' . esc_html__( 'If you pressed that by mistake, please contact the school and they can put it right.', 'classic-edu-suite' ) . '</p>'
			);
		}

		if ( 'verified' === $status ) {
			return self::panel(
				__( 'Already confirmed', 'classic-edu-suite' ),
				'<p>' . esc_html__( 'This account has been confirmed — thank you. There is nothing more to do.', 'classic-edu-suite' ) . '</p>'
			);
		}

		return self::panel(
			__( 'That link has expired', 'classic-edu-suite' ),
			'<p>' . esc_html__( 'Links last a few days. Ask your child to send the request again from their dashboard, and a fresh one will reach you.', 'classic-edu-suite' ) . '</p>'
		);
	}

	/**
	 * A token we have never seen.
	 *
	 * Deliberately says the same thing as an expired link: a page that tells
	 * the difference tells a guesser the difference too.
	 *
	 * @return string
	 */
	public static function render_unknown() {
		return self::panel(
			__( 'That link is not valid', 'classic-edu-suite' ),
			'<p>' . esc_html__( 'It may have expired, or already been used. Ask your child to send the request again from their dashboard.', 'classic-edu-suite' ) . '</p>'
		);
	}

	/* ---------------------------------------------------------------------
	 * Decisions.
	 * ------------------------------------------------------------------ */

	/**
	 * Handle both posted forms.
	 *
	 * @return void
	 */
	public static function route() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately below.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		if ( ! in_array( $action, array( self::ACTION_DECIDE, self::ACTION_PREFS ), true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is read and verified immediately below.
		$token = isset( $_POST['ces_token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['ces_token'] ) ) : '';
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $token ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// A nonce lasts a day; the parent's link lasts three. Rather than
		// dying on a parent who left the page open overnight, send them back
		// to their own link, which re-renders with a fresh nonce.
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_safe_redirect( CES_Accounts_Verification::approval_link( $token ) );
			exit;
		}

		if ( ! CES_Helpers::rate_limit( 'parent_portal_post', 20, 10 * MINUTE_IN_SECONDS ) ) {
			wp_die( esc_html__( 'Too many attempts. Please wait a few minutes and open the link again.', 'classic-edu-suite' ), '', array( 'response' => 429 ) );
		}

		if ( self::ACTION_DECIDE === $action ) {
			self::handle_decision( $token );
		}

		self::handle_prefs( $token );
	}

	/**
	 * Record a parent's yes or no.
	 *
	 * @param string $token Plain approval token.
	 * @return void
	 */
	private static function handle_decision( $token ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$posted   = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$decision = 'decline' === $posted ? 'decline' : 'approve';

		$row = CES_Accounts_Verification::find_by_token( $token );

		if ( ! $row ) {
			self::finish( CES_Accounts_Verification::approval_link( $token ), '' );
		}

		$user_id = (int) $row['user_id'];
		$result  = CES_Accounts_Verification::decide_by_token( $row, $decision );

		if ( is_wp_error( $result ) ) {
			self::finish( CES_Accounts_Verification::approval_link( $token ), '' );
		}

		if ( 'approve' === $decision ) {
			// Hand them the keeping link rather than the spent one.
			$portal = CES_Accounts_Verification::portal_link( $user_id );

			self::finish( $portal ? $portal : CES_Accounts_Verification::approval_link( $token ), 'approved' );
		}

		// A parent who said no should also stop hearing from us.
		update_user_meta( $user_id, CES_Accounts_Verification::META_OPT_OUT, '1' );

		self::finish( CES_Accounts_Verification::approval_link( $token ), 'declined' );
	}

	/**
	 * Save the message preferences, or withdraw consent entirely.
	 *
	 * @param string $token Plain progress token.
	 * @return void
	 */
	private static function handle_prefs( $token ) {
		$user_id = CES_Accounts_Verification::user_by_portal_token( $token );

		if ( ! $user_id ) {
			self::finish( home_url( '/' ), '' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in route().
		$what    = isset( $_POST['ces_pref_action'] ) ? sanitize_key( wp_unslash( $_POST['ces_pref_action'] ) ) : 'save';
		$opt_out = ! empty( $_POST['ces_opt_out'] );
		$confirm = ! empty( $_POST['ces_confirm'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'withdraw' === $what && $confirm ) {
			CES_Accounts_Status::log(
				$user_id,
				'consent_withdrawn',
				array(
					'actor_id' => 0,
					'channel'  => 'portal',
				)
			);

			CES_Accounts_Status::set_status(
				$user_id,
				'suspended',
				array(
					'actor_id' => 0,
					'channel'  => 'portal',
					'event'    => 'consent_withdrawn',
					'detail'   => __( 'The parent or guardian withdrew their consent.', 'classic-edu-suite' ),
				)
			);

			update_user_meta( $user_id, CES_Accounts_Status::META_DECLINE_REASON, __( 'Your parent or guardian withdrew their consent. Speak to them, then ask a member of staff to reopen your account.', 'classic-edu-suite' ) );

			// The number goes, because consent to hold it has gone with it.
			CES_Parent_Contact::delete( $user_id );
			delete_user_meta( $user_id, CES_Accounts_Verification::META_PARENT_EMAIL );
			delete_user_meta( $user_id, CES_Accounts_Verification::META_PORTAL_TOKEN );
			CES_Accounts_Verification::cancel_open( $user_id, 'consent_withdrawn' );

			self::finish( home_url( '/' ), 'withdrawn', __( 'Your consent has been withdrawn and your number deleted. Thank you for letting us know.', 'classic-edu-suite' ) );
		}

		update_user_meta( $user_id, CES_Accounts_Verification::META_OPT_OUT, $opt_out ? '1' : '0' );

		CES_Accounts_Status::log(
			$user_id,
			$opt_out ? 'parent_opted_out' : 'parent_opted_in',
			array(
				'actor_id' => 0,
				'channel'  => 'portal',
			)
		);

		self::finish( CES_Accounts_Verification::approval_link( $token ), 'prefs' );
	}

	/**
	 * Redirect out of a POST, or print a final message when there is nowhere
	 * left to send them.
	 *
	 * @param string $url     Where to go.
	 * @param string $flag    Value for `ces_done`.
	 * @param string $message Optional standalone message instead of a redirect.
	 * @return void
	 */
	private static function finish( $url, $flag = '', $message = '' ) {
		if ( '' !== $message ) {
			self::standalone( self::panel( __( 'Thank you', 'classic-edu-suite' ), '<p>' . esc_html( $message ) . '</p>' ) );
		}

		$target = $url ? $url : home_url( '/' );

		if ( '' !== $flag ) {
			$target = add_query_arg( 'ces_done', $flag, $target );
		}

		wp_safe_redirect( $target );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Chrome.
	 * ------------------------------------------------------------------ */

	/**
	 * Wrap a fragment in the portal panel.
	 *
	 * @param string $heading Optional heading, when the body has none.
	 * @param string $body    Body markup, already escaped.
	 * @return string
	 */
	public static function panel( $heading, $body ) {
		$html = '<div class="ces-portal">';

		if ( '' !== trim( (string) $heading ) ) {
			$html .= '<p class="ces-portal__kicker">' . esc_html( CES_Accounts_Notify::site_name() ) . '</p>';
			$html .= '<h1 class="ces-portal__title">' . esc_html( $heading ) . '</h1>';
		}

		$html .= $body;
		$html .= '<p class="ces-portal__footer"><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( home_url( '/' ) ) . '</a></p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Print a self-contained page and stop.
	 *
	 * Used when no portal page has been created. Everything is inline, so it
	 * does not depend on the theme having loaded anything.
	 *
	 * @param string $body Panel markup.
	 * @return void
	 */
	private static function standalone( $body ) {
		nocache_headers();
		status_header( 200 );

		$primary = class_exists( 'CES_Assets' ) ? CES_Assets::brand_primary() : '#2c62cd';

		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<meta name="robots" content="noindex, nofollow, noarchive" />';
		echo '<title>' . esc_html( CES_Accounts_Notify::site_name() ) . '</title>';
		echo '<style>' . self::standalone_css( $primary ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated CSS, no user input.
		echo '</head><body>';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderers.
		echo '</body></html>';

		exit;
	}

	/**
	 * The inline stylesheet for the standalone page.
	 *
	 * A trimmed copy of the module's own styling, because on this page there
	 * is no theme and no enqueue to rely on.
	 *
	 * @param string $primary Brand colour.
	 * @return string CSS.
	 */
	private static function standalone_css( $primary ) {
		return 'body{margin:0;background:#f1f5f9;color:#1f2937;font:16px/1.6 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;padding:24px 14px}'
			. '.ces-portal{max-width:560px;margin:0 auto;background:#fff;border-radius:16px;padding:26px 22px;box-shadow:0 1px 3px rgba(15,23,42,.08)}'
			. '.ces-portal__kicker{margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . $primary . '}'
			. '.ces-portal__title{margin:0 0 14px;font-size:24px;line-height:1.25}'
			. '.ces-portal__lead{margin:0 0 18px}'
			. '.ces-portal__facts{list-style:none;margin:0 0 20px;padding:0;border-top:1px solid #e2e8f0}'
			. '.ces-portal__facts li{display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:15px}'
			. '.ces-portal__facts span{color:#64748b}'
			. '.ces-portal__stats{list-style:none;display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:0 0 20px;padding:0}'
			. '.ces-portal__stats li{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px}'
			. '.ces-portal__stats strong{display:block;font-size:22px;line-height:1.2}'
			. '.ces-portal__stats span{font-size:13px;color:#64748b}'
			. '.ces-portal__promise{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;margin:0 0 20px}'
			. '.ces-portal__promise h2{margin:0 0 8px;font-size:16px}'
			. '.ces-portal__promise ul,.ces-portal__prefs ul{margin:0;padding-left:20px;font-size:15px}'
			. '.ces-portal__promise li{margin:0 0 6px}'
			. '.ces-btn{display:inline-block;padding:13px 20px;border:1px solid transparent;border-radius:999px;font-weight:600;'
			. 'text-decoration:none;text-align:center;cursor:pointer;font-size:16px;font-family:inherit}'
			. '.ces-btn--block{display:block;width:100%;margin:0 0 10px}'
			. '.ces-btn--primary{background:' . $primary . ';color:#fff}'
			. '.ces-btn--ghost{background:#fff;color:#334155;border-color:#cbd5e1}'
			. '.ces-portal__note,.ces-portal__footer{font-size:13px;color:#64748b;margin:16px 0 0}'
			. '.ces-portal__prefs{border-top:1px solid #e2e8f0;margin-top:22px;padding-top:18px}'
			. '.ces-portal__prefs h2{font-size:17px;margin:0 0 12px}'
			. '.ces-portal__withdraw{margin-top:16px;font-size:15px}'
			. '.ces-portal__withdraw summary{cursor:pointer;color:#64748b}'
			. '.ces-field--check label{display:flex;gap:8px;align-items:flex-start}'
			. '.ces-field__hint{display:block;font-size:13px;color:#64748b;margin-top:4px}'
			. '.ces-notice{border:1px solid #cbd5e1;border-left-width:4px;border-radius:8px;padding:10px 14px;margin:0 0 16px;background:#f8fafc}'
			. '.ces-notice p{margin:0}'
			. '.ces-notice--success{border-left-color:#16a34a}'
			. '.ces-notice--error{border-left-color:#dc2626}';
	}
}
