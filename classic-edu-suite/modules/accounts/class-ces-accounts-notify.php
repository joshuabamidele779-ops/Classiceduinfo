<?php
/**
 * Every message this module sends, in one place.
 *
 * Four audiences:
 *
 *   - **The parent**, asked to confirm their ward, by WhatsApp where the
 *     school has the Cloud API, by email where an address was given, and
 *     otherwise by a message the student forwards themselves.
 *   - **The student**, told when they are approved, when they are refused,
 *     and nudged when nobody has answered.
 *   - **Staff**, told when an account is sitting in the review queue.
 *
 * The wording matters more than it looks. A parent reads this on a phone,
 * once, while doing something else, from a school they may only half
 * recognise. So: who it is about, what the site is, what we want, and the two
 * ways to answer — nothing else, and no marketing.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends the module's messages.
 */
class CES_Accounts_Notify {

	/**
	 * The site name, as parents would recognise it.
	 *
	 * @return string
	 */
	public static function site_name() {
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return '' !== trim( $name ) ? $name : __( 'our study site', 'classic-edu-suite' );
	}

	/**
	 * Where staff notifications go.
	 *
	 * @return string
	 */
	public static function admin_email() {
		$configured = sanitize_email( (string) CES_Helpers::setting( 'accounts_notify_admin_email', '' ) );

		if ( is_email( $configured ) ) {
			return $configured;
		}

		return sanitize_email( (string) get_option( 'admin_email' ) );
	}

	/* ---------------------------------------------------------------------
	 * To the parent.
	 * ------------------------------------------------------------------ */

	/**
	 * The approval request, as plain text.
	 *
	 * Used for WhatsApp, for the message the student forwards, and as the
	 * plain-text part of the email.
	 *
	 * @param int    $user_id  Student.
	 * @param string $code     Plain six-digit code, '' to leave it out.
	 * @param string $token    Plain approval token.
	 * @param bool   $reminder Whether this is the follow-up.
	 * @return string
	 */
	public static function parent_message( $user_id, $code = '', $token = '', $reminder = false ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );
		$contact = CES_Parent_Contact::get( $user_id );
		$record  = CES_Accounts_Status::record( $user_id );

		$student = $user ? $user->display_name : __( 'your child', 'classic-edu-suite' );
		$parent  = '' !== trim( $contact['name'] ) ? $contact['name'] : __( 'Good day', 'classic-edu-suite' );
		$link    = '' !== $token ? CES_Accounts_Verification::approval_link( $token ) : '';
		$site    = self::site_name();

		$lines = array();

		$lines[] = sprintf(
			/* translators: %s: parent or guardian name. */
			__( 'Hello %s,', 'classic-edu-suite' ),
			$parent
		);
		$lines[] = '';

		if ( $reminder ) {
			$lines[] = sprintf(
				/* translators: 1: student name, 2: site name. */
				__( 'We wrote to you about %1$s’s free study account on %2$s and have not heard back yet.', 'classic-edu-suite' ),
				$student,
				$site
			);
		} else {
			$lines[] = sprintf(
				/* translators: 1: student name, 2: site name. */
				__( '%1$s has opened a free study account on %2$s and gave us your number as their parent or guardian.', 'classic-edu-suite' ),
				$student,
				$site
			);
		}

		if ( '' !== $record['class_label'] ) {
			$lines[] = sprintf(
				/* translators: %s: class or level, e.g. "SS 3". */
				__( 'Class given: %s.', 'classic-edu-suite' ),
				$record['class_label']
			);
		}

		$lines[] = '';
		$lines[] = __( 'Nothing is open to them until you say yes.', 'classic-edu-suite' );
		$lines[] = '';

		if ( '' !== $link ) {
			$lines[] = __( 'To confirm, open this link:', 'classic-edu-suite' );
			$lines[] = $link;
			$lines[] = '';
		}

		if ( '' !== $code ) {
			$lines[] = sprintf(
				/* translators: %s: six-digit code. */
				__( 'Or read this code out to them: %s', 'classic-edu-suite' ),
				$code
			);
			$lines[] = '';
		}

		$lines[] = __( 'If you do not know this person, open the link and press “No, I do not know this person”. We will close the account.', 'classic-edu-suite' );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: site name. */
			__( '— %s', 'classic-edu-suite' ),
			$site
		);

		$text = implode( "\n", $lines );

		/**
		 * Filter the approval request sent to a parent.
		 *
		 * @param string $text    Message body.
		 * @param int    $user_id Student.
		 * @param string $link    Approval URL.
		 */
		return (string) apply_filters( 'ces_accounts_parent_message', $text, $user_id, $link );
	}

	/**
	 * Send the approval request on every channel available.
	 *
	 * @param int    $user_id  Student.
	 * @param string $code     Plain code, '' when there is none to send.
	 * @param string $token    Plain approval token.
	 * @param bool   $reminder Whether this is the follow-up.
	 * @return string[] Channels that reported success.
	 */
	public static function parent_request( $user_id, $code = '', $token = '', $reminder = false ) {
		$user_id = absint( $user_id );
		$sent    = array();

		if ( CES_Accounts_Verification::opted_out( $user_id ) ) {
			CES_Accounts_Status::log( $user_id, 'parent_opted_out', array( 'actor_id' => 0 ) );

			return $sent;
		}

		$contact = CES_Parent_Contact::get( $user_id );
		$message = self::parent_message( $user_id, $code, $token, $reminder );

		// 1. WhatsApp, where the school has an account for it.
		if ( class_exists( 'CES_Parents_Whatsapp' ) && CES_Parents_Whatsapp::enabled() && '' !== $contact['phone'] ) {
			$result = CES_Parents_Whatsapp::send( $contact['phone'], $message );

			if ( is_wp_error( $result ) ) {
				CES_Helpers::log( 'Parent approval request failed on WhatsApp', $result->get_error_message() );
			} else {
				$sent[] = 'whatsapp';
			}
		}

		// 2. Email, where the student gave an address for them.
		$parent_email = CES_Accounts_Verification::parent_email( $user_id );

		if ( '' !== $parent_email ) {
			$ok = self::email(
				$parent_email,
				$reminder
					? sprintf(
						/* translators: %s: site name. */
						__( 'Reminder: please confirm your child’s account on %s', 'classic-edu-suite' ),
						self::site_name()
					)
					: sprintf(
						/* translators: %s: site name. */
						__( 'Please confirm your child’s account on %s', 'classic-edu-suite' ),
						self::site_name()
					),
				self::parent_email_html( $user_id, $code, $token, $reminder ),
				$message
			);

			if ( $ok ) {
				$sent[] = 'email';
			}
		}

		return $sent;
	}

	/**
	 * The HTML body of the parent's email.
	 *
	 * @param int    $user_id  Student.
	 * @param string $code     Plain code.
	 * @param string $token    Plain token.
	 * @param bool   $reminder Whether this is the follow-up.
	 * @return string
	 */
	private static function parent_email_html( $user_id, $code, $token, $reminder = false ) {
		$user    = get_userdata( absint( $user_id ) );
		$student = $user ? $user->display_name : __( 'your child', 'classic-edu-suite' );
		$link    = '' !== $token ? CES_Accounts_Verification::approval_link( $token ) : '';
		$record  = CES_Accounts_Status::record( absint( $user_id ) );

		$body = '<p>' . esc_html(
			$reminder
				? sprintf(
					/* translators: 1: student name, 2: site name. */
					__( 'We wrote to you about %1$s’s free study account on %2$s and have not heard back yet.', 'classic-edu-suite' ),
					$student,
					self::site_name()
				)
				: sprintf(
					/* translators: 1: student name, 2: site name. */
					__( '%1$s has opened a free study account on %2$s and gave us your email address as their parent or guardian.', 'classic-edu-suite' ),
					$student,
					self::site_name()
				)
		) . '</p>';

		if ( '' !== $record['class_label'] ) {
			$body .= '<p>' . esc_html(
				sprintf(
					/* translators: %s: class or level. */
					__( 'Class given: %s', 'classic-edu-suite' ),
					$record['class_label']
				)
			) . '</p>';
		}

		$body .= '<p><strong>' . esc_html__( 'Nothing is open to them until you say yes.', 'classic-edu-suite' ) . '</strong></p>';

		if ( '' !== $link ) {
			$body .= '<p style="margin:28px 0;"><a href="' . esc_url( $link ) . '" style="background:#2c62cd;color:#ffffff;'
				. 'padding:13px 22px;border-radius:999px;text-decoration:none;font-weight:600;display:inline-block;">'
				. esc_html__( 'Yes, confirm this account', 'classic-edu-suite' ) . '</a></p>';
			$body .= '<p style="font-size:13px;color:#475569;">' . esc_html__( 'The same page lets you refuse, and shows exactly what we would send you.', 'classic-edu-suite' ) . '</p>';
		}

		if ( '' !== $code ) {
			$body .= '<p>' . esc_html__( 'If your child is with you, you can simply read this code to them instead:', 'classic-edu-suite' ) . '</p>';
			$body .= '<p style="font-size:30px;letter-spacing:6px;font-weight:700;margin:6px 0 22px;">' . esc_html( $code ) . '</p>';
		}

		$body .= '<p style="font-size:13px;color:#475569;">' . esc_html__( 'If you do not know this person, open the link and press “No, I do not know this person”. We will close the account and you will hear nothing further.', 'classic-edu-suite' ) . '</p>';

		return self::wrap_email( __( 'Please confirm your child’s account', 'classic-edu-suite' ), $body );
	}

	/* ---------------------------------------------------------------------
	 * To the student.
	 * ------------------------------------------------------------------ */

	/**
	 * Tell a student their account is open.
	 *
	 * @param int $user_id Student.
	 * @return bool
	 */
	public static function student_approved( $user_id ) {
		$user = get_userdata( absint( $user_id ) );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}

		$body  = '<p>' . esc_html(
			sprintf(
				/* translators: %s: student first name or display name. */
				__( 'Good news, %s — your parent or guardian has confirmed your account.', 'classic-edu-suite' ),
				$user->display_name
			)
		) . '</p>';
		$body .= '<p>' . esc_html__( 'Everything is now open to you: past questions, practice exams, the study hub and the video library.', 'classic-edu-suite' ) . '</p>';
		$body .= '<p style="margin:28px 0;"><a href="' . esc_url( CES_Helpers::dashboard_url() ) . '" style="background:#2c62cd;color:#ffffff;'
			. 'padding:13px 22px;border-radius:999px;text-decoration:none;font-weight:600;display:inline-block;">'
			. esc_html__( 'Start practising', 'classic-edu-suite' ) . '</a></p>';

		return self::email(
			$user->user_email,
			sprintf(
				/* translators: %s: site name. */
				__( 'Your account on %s is open', 'classic-edu-suite' ),
				self::site_name()
			),
			self::wrap_email( __( 'You are all set', 'classic-edu-suite' ), $body )
		);
	}

	/**
	 * Tell a student they have been refused, and what to do about it.
	 *
	 * @param int    $user_id Student.
	 * @param string $reason  Reason recorded.
	 * @return bool
	 */
	public static function student_declined( $user_id, $reason = '' ) {
		$user = get_userdata( absint( $user_id ) );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}

		$body = '<p>' . esc_html__( 'Your study account has not been approved, so practice is still closed.', 'classic-edu-suite' ) . '</p>';

		if ( '' !== trim( (string) $reason ) ) {
			$body .= '<p><em>' . esc_html( $reason ) . '</em></p>';
		}

		$body .= '<p>' . esc_html__( 'If this is a mistake — a wrong number, or a parent who pressed the wrong button — you can start again from your dashboard, or reply to this email and we will sort it out.', 'classic-edu-suite' ) . '</p>';
		$body .= '<p><a href="' . esc_url( CES_Helpers::dashboard_url( CES_Accounts_Dashboard::SLUG ) ) . '">' . esc_html__( 'Open my dashboard', 'classic-edu-suite' ) . '</a></p>';

		return self::email(
			$user->user_email,
			sprintf(
				/* translators: %s: site name. */
				__( 'About your account on %s', 'classic-edu-suite' ),
				self::site_name()
			),
			self::wrap_email( __( 'Your account was not approved', 'classic-edu-suite' ), $body )
		);
	}

	/**
	 * Nudge a student whose parent has not answered.
	 *
	 * Sent only where the site cannot reach the parent itself, because then
	 * the student is the delivery mechanism and they are the one who has to
	 * act.
	 *
	 * @param int $user_id Student.
	 * @return bool
	 */
	public static function student_nudge( $user_id ) {
		$user = get_userdata( absint( $user_id ) );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}

		$contact = CES_Parent_Contact::get( (int) $user->ID );

		$body  = '<p>' . esc_html__( 'Your parent or guardian has not confirmed your account yet, so practice is still locked.', 'classic-edu-suite' ) . '</p>';
		$body .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: formatted phone number. */
				__( 'We are waiting on %s. Open your dashboard and press the WhatsApp button to send the request again — it takes one tap.', 'classic-edu-suite' ),
				$contact['phone_pretty']
			)
		) . '</p>';
		$body .= '<p style="margin:28px 0;"><a href="' . esc_url( CES_Helpers::dashboard_url( CES_Accounts_Dashboard::SLUG ) ) . '" style="background:#2c62cd;'
			. 'color:#ffffff;padding:13px 22px;border-radius:999px;text-decoration:none;font-weight:600;display:inline-block;">'
			. esc_html__( 'Send it again', 'classic-edu-suite' ) . '</a></p>';

		return self::email(
			$user->user_email,
			__( 'One step left on your study account', 'classic-edu-suite' ),
			self::wrap_email( __( 'Almost there', 'classic-edu-suite' ), $body )
		);
	}

	/* ---------------------------------------------------------------------
	 * To staff.
	 * ------------------------------------------------------------------ */

	/**
	 * Tell staff an account is waiting for review.
	 *
	 * @param int $user_id Student.
	 * @return bool
	 */
	public static function admin_pending( $user_id ) {
		if ( ! CES_Helpers::enabled( 'accounts_notify_admin' ) ) {
			return false;
		}

		$to = self::admin_email();

		if ( ! is_email( $to ) ) {
			return false;
		}

		$user = get_userdata( absint( $user_id ) );

		if ( ! $user ) {
			return false;
		}

		$record  = CES_Accounts_Status::record( (int) $user->ID );
		$contact = CES_Parent_Contact::get( (int) $user->ID );
		$flags   = CES_Accounts_Status::flags( (int) $user->ID );
		$labels  = CES_Accounts_Status::flag_labels();

		$body  = '<p>' . esc_html__( 'An account is waiting for staff review.', 'classic-edu-suite' ) . '</p>';
		$body .= '<table cellpadding="6" style="border-collapse:collapse;font-size:14px;">';
		$body .= '<tr><td><strong>' . esc_html__( 'Student', 'classic-edu-suite' ) . '</strong></td><td>' . esc_html( $user->display_name ) . ' &lt;' . esc_html( $user->user_email ) . '&gt;</td></tr>';
		$body .= '<tr><td><strong>' . esc_html__( 'Class', 'classic-edu-suite' ) . '</strong></td><td>' . esc_html( $record['class_label'] ) . '</td></tr>';
		$body .= '<tr><td><strong>' . esc_html__( 'Parent', 'classic-edu-suite' ) . '</strong></td><td>' . esc_html( $contact['name'] ) . ' · ' . esc_html( $contact['phone_pretty'] ) . '</td></tr>';
		$body .= '<tr><td><strong>' . esc_html__( 'Confirmed', 'classic-edu-suite' ) . '</strong></td><td>' . esc_html( $record['verified_at'] ) . ' (' . esc_html( $record['verified_via'] ) . ')</td></tr>';
		$body .= '</table>';

		if ( ! empty( $flags ) ) {
			$body .= '<p><strong>' . esc_html__( 'Flagged for:', 'classic-edu-suite' ) . '</strong></p><ul>';

			foreach ( $flags as $flag ) {
				$body .= '<li>' . esc_html( isset( $labels[ $flag ] ) ? $labels[ $flag ] : $flag ) . '</li>';
			}

			$body .= '</ul>';
		}

		$body .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=ces-approvals' ) ) . '">' . esc_html__( 'Open the Approvals screen', 'classic-edu-suite' ) . '</a></p>';

		return self::email(
			$to,
			sprintf(
				/* translators: %s: student display name. */
				__( 'Approval needed: %s', 'classic-edu-suite' ),
				$user->display_name
			),
			self::wrap_email( __( 'Account waiting for review', 'classic-edu-suite' ), $body )
		);
	}

	/* ---------------------------------------------------------------------
	 * Sending.
	 * ------------------------------------------------------------------ */

	/**
	 * Send one HTML email, with a plain-text fallback in the body.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject line.
	 * @param string $html    Full HTML body.
	 * @param string $plain   Optional plain-text version, logged on failure.
	 * @return bool
	 */
	public static function email( $to, $subject, $html, $plain = '' ) {
		$to = sanitize_email( (string) $to );

		if ( ! is_email( $to ) ) {
			return false;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $to, wp_specialchars_decode( (string) $subject, ENT_QUOTES ), $html, $headers );

		if ( ! $sent ) {
			CES_Helpers::log( 'Account email could not be sent', array( 'to' => $to, 'subject' => $subject, 'plain' => $plain ) );
		}

		return (bool) $sent;
	}

	/**
	 * Wrap a body fragment in the email shell.
	 *
	 * Deliberately plain: inline styles only, one column, no images, no web
	 * fonts. It has to survive Gmail on a slow connection.
	 *
	 * @param string $heading Heading line.
	 * @param string $body    Body HTML.
	 * @return string
	 */
	public static function wrap_email( $heading, $body ) {
		$primary = class_exists( 'CES_Assets' ) ? CES_Assets::brand_primary() : '#2c62cd';

		$html  = '<!DOCTYPE html><html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /></head>';
		$html .= '<body style="margin:0;padding:24px 12px;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">';
		$html .= '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:14px;padding:28px 24px;">';
		$html .= '<p style="margin:0 0 4px;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:' . esc_attr( $primary ) . ';">'
			. esc_html( self::site_name() ) . '</p>';
		$html .= '<h1 style="margin:0 0 18px;font-size:21px;line-height:1.3;">' . esc_html( $heading ) . '</h1>';
		$html .= '<div style="font-size:15px;line-height:1.6;">' . $body . '</div>';
		$html .= '<hr style="border:none;border-top:1px solid #e2e8f0;margin:26px 0 14px;" />';
		$html .= '<p style="font-size:12px;color:#64748b;line-height:1.6;margin:0;">'
			. esc_html(
				sprintf(
					/* translators: %s: site name. */
					__( 'Sent by %s. We only ever use a parent’s number or email to talk about their own child.', 'classic-edu-suite' ),
					self::site_name()
				)
			)
			. ' <a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . esc_attr( $primary ) . ';">' . esc_html( home_url( '/' ) ) . '</a></p>';
		$html .= '</div></body></html>';

		return $html;
	}
}
