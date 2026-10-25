<?php
/**
 * Optional WhatsApp Cloud API sender.
 *
 * Off by default, and the plugin works fully without it: the click-to-chat
 * link in the admin screen opens WhatsApp with the message already typed,
 * which costs nothing and needs no approval.
 *
 * Turn this on only once you have a WhatsApp Business account, a phone number
 * ID and a *approved message template*. Meta does not allow a business to open
 * a conversation with free text — the first message to a parent who has not
 * written to you in the last 24 hours must be a template. This class therefore
 * sends a template when one is configured and plain text only as a reply
 * inside an open conversation.
 *
 * The access token lives in the settings option and is never printed, logged
 * or sent to the browser.
 *
 * @package ClassicEduSuite\Parents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin, defensive wrapper over the Cloud API messages endpoint.
 */
class CES_Parents_Whatsapp {

	/**
	 * Graph API version this code is written against.
	 */
	const API_VERSION = 'v21.0';

	/**
	 * Whether automated sending is configured and switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return CES_Helpers::enabled( 'parents_wa_api_enabled' )
			&& '' !== trim( (string) CES_Helpers::setting( 'parents_wa_phone_id', '' ) )
			&& '' !== trim( (string) CES_Helpers::setting( 'parents_wa_token', '' ) );
	}

	/**
	 * Whether a template is configured, which is what cold outreach needs.
	 *
	 * @return bool
	 */
	public static function has_template() {
		return '' !== trim( (string) CES_Helpers::setting( 'parents_wa_template', '' ) );
	}

	/**
	 * Send a message to one parent.
	 *
	 * @param string $phone   Normalised digits, country code included.
	 * @param string $message Plain text body. Used as-is for a text send, and
	 *                        as the single body parameter for a template send.
	 * @param array  $args    Optional. `force_text` => bool to skip the template.
	 * @return array|WP_Error `array( 'id' => string, 'mode' => 'template'|'text' )`.
	 */
	public static function send( $phone, $message, array $args = array() ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'ces_wa_disabled', __( 'WhatsApp sending is not switched on.', 'classic-edu-suite' ) );
		}

		$phone = preg_replace( '/\D/', '', (string) $phone );

		if ( '' === $phone ) {
			return new WP_Error( 'ces_wa_phone', __( 'That parent has no usable number on file.', 'classic-edu-suite' ) );
		}

		$message = trim( (string) $message );

		if ( '' === $message ) {
			return new WP_Error( 'ces_wa_empty', __( 'There is nothing to send.', 'classic-edu-suite' ) );
		}

		$use_template = self::has_template() && empty( $args['force_text'] );

		if ( $use_template ) {
			$payload = self::template_payload( $phone, $message );
			$mode    = 'template';
		} else {
			$payload = array(
				'messaging_product' => 'whatsapp',
				'recipient_type'    => 'individual',
				'to'                => $phone,
				'type'              => 'text',
				'text'              => array(
					'preview_url' => false,
					'body'        => self::clip( $message, 4000 ),
				),
			);
			$mode    = 'text';
		}

		$phone_id = rawurlencode( trim( (string) CES_Helpers::setting( 'parents_wa_phone_id', '' ) ) );
		$endpoint = 'https://graph.facebook.com/' . self::API_VERSION . '/' . $phone_id . '/messages';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . trim( (string) CES_Helpers::setting( 'parents_wa_token', '' ) ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// The token could be in neither of these, but be explicit anyway.
			CES_Helpers::log( 'WhatsApp send failed', $response->get_error_message() );

			return new WP_Error( 'ces_wa_transport', __( 'WhatsApp could not be reached. Try the chat link instead.', 'classic-edu-suite' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = '';

			if ( is_array( $body ) && isset( $body['error']['message'] ) ) {
				$detail = (string) $body['error']['message'];
			}

			CES_Helpers::log( 'WhatsApp API rejected a send', array( 'status' => $code, 'detail' => $detail ) );

			return new WP_Error(
				'ces_wa_rejected',
				$detail ? $detail : sprintf(
					/* translators: %d: HTTP status code. */
					__( 'WhatsApp refused the message (HTTP %d).', 'classic-edu-suite' ),
					$code
				)
			);
		}

		$id = '';

		if ( is_array( $body ) && ! empty( $body['messages'][0]['id'] ) ) {
			$id = (string) $body['messages'][0]['id'];
		}

		return array(
			'id'   => $id,
			'mode' => $mode,
		);
	}

	/**
	 * Build a template send payload.
	 *
	 * The template is expected to carry one body parameter, into which the
	 * whole summary sentence is placed. Templates with a different shape can
	 * be rewritten through the `ces_parent_wa_payload` filter.
	 *
	 * @param string $phone   Normalised digits.
	 * @param string $message Body text.
	 * @return array
	 */
	private static function template_payload( $phone, $message ) {
		$name = trim( (string) CES_Helpers::setting( 'parents_wa_template', '' ) );
		$lang = trim( (string) CES_Helpers::setting( 'parents_wa_language', 'en' ) );

		$payload = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $phone,
			'type'              => 'template',
			'template'          => array(
				'name'       => $name,
				'language'   => array( 'code' => $lang ? $lang : 'en' ),
				'components' => array(
					array(
						'type'       => 'body',
						'parameters' => array(
							array(
								'type' => 'text',
								// Template parameters may not contain newlines.
								'text' => self::clip( str_replace( array( "\r\n", "\n", "\t" ), ' ', $message ), 900 ),
							),
						),
					),
				),
			),
		);

		/**
		 * Filter the Cloud API request body before it is sent.
		 *
		 * @param array  $payload Request body.
		 * @param string $phone   Recipient digits.
		 * @param string $message Message text.
		 */
		return (array) apply_filters( 'ces_parent_wa_payload', $payload, $phone, $message );
	}

	/**
	 * Trim a string to a maximum length without cutting mid-word.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		$text = (string) $text;

		if ( strlen( $text ) <= $max ) {
			return $text;
		}

		$cut   = substr( $text, 0, $max );
		$space = strrpos( $cut, ' ' );

		return ( false !== $space && $space > ( $max / 2 ) ) ? substr( $cut, 0, $space ) : $cut;
	}
}
