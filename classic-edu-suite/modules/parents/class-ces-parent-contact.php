<?php
/**
 * Parent / guardian contact record.
 *
 * One parent contact per student, stored in user meta rather than a table so
 * the record travels with the user, is removed by WordPress when the account
 * is deleted, and never needs a schema change.
 *
 * The WhatsApp number is normalised to E.164 digits *without* the leading
 * plus (`2348031234567`), which is the form wa.me and the WhatsApp Cloud API
 * both want. Nigerian students type their numbers six different ways; the
 * normaliser accepts all of them and stores one.
 *
 * @package ClassicEduSuite\Parents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read, validate and write a student's parent contact.
 */
class CES_Parent_Contact {

	/**
	 * Parent / guardian full name.
	 */
	const META_NAME = 'ces_parent_name';

	/**
	 * Normalised WhatsApp number, digits only, country code included.
	 */
	const META_PHONE = 'ces_parent_whatsapp';

	/**
	 * Exactly what the student typed, kept for support queries.
	 */
	const META_PHONE_RAW = 'ces_parent_whatsapp_raw';

	/**
	 * Relationship slug.
	 */
	const META_RELATION = 'ces_parent_relationship';

	/**
	 * Whether the student confirmed the parent agrees to be contacted.
	 */
	const META_CONSENT = 'ces_parent_consent';

	/**
	 * When the record was last written.
	 */
	const META_UPDATED = 'ces_parent_updated_at';

	/**
	 * When staff last messaged this parent, as a MySQL datetime.
	 */
	const META_LAST_CONTACTED = 'ces_parent_last_contacted';

	/**
	 * How many times staff have messaged this parent.
	 */
	const META_CONTACT_COUNT = 'ces_parent_contact_count';

	/**
	 * Set when the student asks not to be prompted again this session.
	 */
	const META_DISMISSED = 'ces_parent_prompt_dismissed';

	/**
	 * Relationship options offered to the student.
	 *
	 * @return array Map of slug => label.
	 */
	public static function relationships() {
		return array(
			'mother'   => __( 'Mother', 'classic-edu-suite' ),
			'father'   => __( 'Father', 'classic-edu-suite' ),
			'guardian' => __( 'Guardian', 'classic-edu-suite' ),
			'sibling'  => __( 'Elder brother / sister', 'classic-edu-suite' ),
			'sponsor'  => __( 'Sponsor', 'classic-edu-suite' ),
			'other'    => __( 'Other', 'classic-edu-suite' ),
		);
	}

	/**
	 * Coerce a relationship value to a known slug.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_relationship( $value ) {
		$value = sanitize_key( (string) $value );

		return array_key_exists( $value, self::relationships() ) ? $value : 'guardian';
	}

	/**
	 * The default country calling code, digits only.
	 *
	 * @return string
	 */
	public static function default_country_code() {
		$code = preg_replace( '/\D/', '', (string) CES_Helpers::setting( 'parents_default_country', '234' ) );

		return '' === $code ? '234' : $code;
	}

	/**
	 * Normalise a typed phone number to E.164 digits, no leading plus.
	 *
	 * Accepts, for a Nigerian install:
	 *   0803 123 4567   -> 2348031234567
	 *   +234 803 123 4567 -> 2348031234567
	 *   234-803-123-4567  -> 2348031234567
	 *   8031234567        -> 2348031234567
	 *
	 * A number that already carries a different country code (a parent abroad)
	 * is kept as typed, provided it is a plausible international length.
	 *
	 * @param string $raw          What the student typed.
	 * @param string $country_code Optional country code override, digits only.
	 * @return string Normalised digits, or '' when the number cannot be trusted.
	 */
	public static function normalize_phone( $raw, $country_code = '' ) {
		$raw = (string) $raw;

		// A leading plus is the only punctuation that carries meaning.
		$had_plus = ( 0 === strpos( trim( $raw ), '+' ) );
		$digits   = preg_replace( '/\D/', '', $raw );

		if ( '' === $digits ) {
			return '';
		}

		$cc     = $country_code ? preg_replace( '/\D/', '', (string) $country_code ) : self::default_country_code();
		$cc_len = strlen( $cc );

		// Nigerian mobile numbers are 10 digits after the country code; most
		// national numbering plans this plugin will meet sit in 8-12.
		$national_min = 8;
		$national_max = 12;

		if ( $had_plus ) {
			// Trust an explicitly international number as typed.
			return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? $digits : '';
		}

		// 00 prefix is the other way of writing a plus.
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );

			return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? $digits : '';
		}

		// Already carries our own country code.
		if ( $cc_len && 0 === strpos( $digits, $cc ) ) {
			$national = substr( $digits, $cc_len );

			// Guard against a national number that merely starts with the same
			// digits, e.g. 2348... is fine but 234567890 (9 digits) is not.
			if ( strlen( $national ) >= $national_min && strlen( $national ) <= $national_max ) {
				return $cc . ltrim( $national, '0' );
			}
		}

		// Local trunk form: 0803...
		if ( 0 === strpos( $digits, '0' ) ) {
			$national = ltrim( $digits, '0' );

			if ( strlen( $national ) < $national_min || strlen( $national ) > $national_max ) {
				return '';
			}

			return $cc . $national;
		}

		// Bare national number: 803...
		if ( strlen( $digits ) >= $national_min && strlen( $digits ) <= $national_max ) {
			return $cc . $digits;
		}

		// Long enough to already be international without a plus.
		if ( strlen( $digits ) >= 11 && strlen( $digits ) <= 15 ) {
			return $digits;
		}

		return '';
	}

	/**
	 * Format a stored number for display: +234 803 123 4567.
	 *
	 * @param string $digits Normalised digits.
	 * @return string
	 */
	public static function format_phone( $digits ) {
		$digits = preg_replace( '/\D/', '', (string) $digits );

		if ( '' === $digits ) {
			return '';
		}

		$cc = self::default_country_code();

		if ( 0 === strpos( $digits, $cc ) ) {
			$national = substr( $digits, strlen( $cc ) );
			$national = trim( chunk_split( $national, 3, ' ' ) );

			return '+' . $cc . ' ' . $national;
		}

		return '+' . $digits;
	}

	/**
	 * The whole record for one student.
	 *
	 * @param int $user_id Student.
	 * @return array {
	 *     @type int    $user_id
	 *     @type string $name
	 *     @type string $phone        Normalised digits, '' when unset.
	 *     @type string $phone_pretty Display form.
	 *     @type string $phone_raw    As typed.
	 *     @type string $relationship Slug.
	 *     @type string $relation_label
	 *     @type bool   $consent
	 *     @type string $updated_at
	 *     @type string $last_contacted
	 *     @type int    $contact_count
	 * }
	 */
	public static function get( $user_id ) {
		$user_id = absint( $user_id );

		$phone        = (string) get_user_meta( $user_id, self::META_PHONE, true );
		$relationship = self::sanitize_relationship( get_user_meta( $user_id, self::META_RELATION, true ) );
		$labels       = self::relationships();

		return array(
			'user_id'        => $user_id,
			'name'           => (string) get_user_meta( $user_id, self::META_NAME, true ),
			'phone'          => $phone,
			'phone_pretty'   => self::format_phone( $phone ),
			'phone_raw'      => (string) get_user_meta( $user_id, self::META_PHONE_RAW, true ),
			'relationship'   => $relationship,
			'relation_label' => isset( $labels[ $relationship ] ) ? $labels[ $relationship ] : '',
			'consent'        => '1' === (string) get_user_meta( $user_id, self::META_CONSENT, true ),
			'updated_at'     => (string) get_user_meta( $user_id, self::META_UPDATED, true ),
			'last_contacted' => (string) get_user_meta( $user_id, self::META_LAST_CONTACTED, true ),
			'contact_count'  => (int) get_user_meta( $user_id, self::META_CONTACT_COUNT, true ),
		);
	}

	/**
	 * Whether a student has a usable parent number on file.
	 *
	 * @param int $user_id Student.
	 * @return bool
	 */
	public static function has_number( $user_id ) {
		return '' !== (string) get_user_meta( absint( $user_id ), self::META_PHONE, true );
	}

	/**
	 * Validate a submitted set of fields.
	 *
	 * @param array $input Raw input: name, phone, relationship, consent.
	 * @return array List of human readable errors, keyed by field. Empty when valid.
	 */
	public static function validate( array $input ) {
		$errors = array();

		$name  = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';
		$phone = isset( $input['phone'] ) ? (string) $input['phone'] : '';

		if ( strlen( $name ) < 2 ) {
			$errors['name'] = __( 'Please enter your parent or guardian’s name.', 'classic-edu-suite' );
		}

		if ( strlen( $name ) > 80 ) {
			$errors['name'] = __( 'That name is too long.', 'classic-edu-suite' );
		}

		if ( '' === trim( $phone ) ) {
			$errors['phone'] = __( 'Please enter your parent or guardian’s WhatsApp number.', 'classic-edu-suite' );
		} elseif ( '' === self::normalize_phone( $phone ) ) {
			$errors['phone'] = __( 'That does not look like a working WhatsApp number. Use the full number, for example 0803 123 4567.', 'classic-edu-suite' );
		}

		if ( CES_Helpers::enabled( 'parents_consent_required' ) && empty( $input['consent'] ) ) {
			$errors['consent'] = __( 'Please confirm your parent or guardian is happy for us to message them.', 'classic-edu-suite' );
		}

		return $errors;
	}

	/**
	 * Write a validated record.
	 *
	 * Validation is re-run here, so a caller that forgets to validate still
	 * cannot write rubbish.
	 *
	 * @param int   $user_id Student.
	 * @param array $input   Raw input: name, phone, relationship, consent.
	 * @return true|WP_Error
	 */
	public static function save( $user_id, array $input ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'ces_parent_user', __( 'That account could not be found.', 'classic-edu-suite' ), array( 'status' => 404 ) );
		}

		$errors = self::validate( $input );

		if ( ! empty( $errors ) ) {
			$error = new WP_Error();

			foreach ( $errors as $field => $message ) {
				$error->add( 'ces_parent_' . $field, $message, array( 'status' => 400 ) );
			}

			return $error;
		}

		$raw    = (string) $input['phone'];
		$phone  = self::normalize_phone( $raw );
		$before = self::get( $user_id );

		update_user_meta( $user_id, self::META_NAME, sanitize_text_field( (string) $input['name'] ) );
		update_user_meta( $user_id, self::META_PHONE, $phone );
		update_user_meta( $user_id, self::META_PHONE_RAW, sanitize_text_field( $raw ) );
		update_user_meta( $user_id, self::META_RELATION, self::sanitize_relationship( isset( $input['relationship'] ) ? $input['relationship'] : '' ) );
		update_user_meta( $user_id, self::META_CONSENT, empty( $input['consent'] ) ? '0' : '1' );
		update_user_meta( $user_id, self::META_UPDATED, CES_Helpers::now() );
		delete_user_meta( $user_id, self::META_DISMISSED );

		/**
		 * Fires after a parent contact is written.
		 *
		 * @param int   $user_id Student.
		 * @param array $after   The new record.
		 * @param array $before  The record as it was.
		 */
		do_action( 'ces_parent_contact_saved', $user_id, self::get( $user_id ), $before );

		return true;
	}

	/**
	 * Erase a student's parent contact.
	 *
	 * @param int $user_id Student.
	 * @return void
	 */
	public static function delete( $user_id ) {
		$user_id = absint( $user_id );

		foreach ( array( self::META_NAME, self::META_PHONE, self::META_PHONE_RAW, self::META_RELATION, self::META_CONSENT, self::META_UPDATED ) as $key ) {
			delete_user_meta( $user_id, $key );
		}

		/**
		 * Fires after a parent contact is erased.
		 *
		 * @param int $user_id Student.
		 */
		do_action( 'ces_parent_contact_deleted', $user_id );
	}

	/**
	 * A click-to-chat link that opens WhatsApp with the message pre-filled.
	 *
	 * Works on phone and desktop and needs no API access, which is why it is
	 * the default way staff reach a parent.
	 *
	 * @param string $phone   Normalised digits.
	 * @param string $message Message body, plain text.
	 * @return string URL, or '' when there is no number.
	 */
	public static function wa_link( $phone, $message = '' ) {
		$phone = preg_replace( '/\D/', '', (string) $phone );

		if ( '' === $phone ) {
			return '';
		}

		$url = 'https://wa.me/' . $phone;

		if ( '' !== trim( (string) $message ) ) {
			$url = add_query_arg( 'text', rawurlencode( (string) $message ), $url );
		}

		return $url;
	}

	/**
	 * Record that staff have messaged this parent.
	 *
	 * @param int $user_id Student.
	 * @return void
	 */
	public static function mark_contacted( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		update_user_meta( $user_id, self::META_LAST_CONTACTED, CES_Helpers::now() );
		update_user_meta( $user_id, self::META_CONTACT_COUNT, (int) get_user_meta( $user_id, self::META_CONTACT_COUNT, true ) + 1 );
	}

	/**
	 * Students who do or do not have a parent number, for the admin screen.
	 *
	 * @param array $args {
	 *     @type string $have   'yes' | 'no' | 'any'
	 *     @type string $search Name, email or number fragment.
	 *     @type int    $number Page size.
	 *     @type int    $offset Offset.
	 * }
	 * @return array `array( 'users' => WP_User[], 'total' => int )`
	 */
	public static function query_students( array $args = array() ) {
		$args = array_merge(
			array(
				'have'   => 'any',
				'search' => '',
				'number' => 25,
				'offset' => 0,
			),
			$args
		);

		$query_args = array(
			'number'      => max( 1, min( 200, (int) $args['number'] ) ),
			'offset'      => max( 0, (int) $args['offset'] ),
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'count_total' => true,
			'fields'      => 'all',
		);

		$search = trim( (string) $args['search'] );

		if ( '' !== $search ) {
			$query_args['search']         = '*' . esc_attr( $search ) . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}

		$meta_query = array();

		if ( 'yes' === $args['have'] ) {
			$meta_query[] = array(
				'key'     => self::META_PHONE,
				'value'   => '',
				'compare' => '!=',
			);
		} elseif ( 'no' === $args['have'] ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => self::META_PHONE,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::META_PHONE,
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new WP_User_Query( $query_args );

		return array(
			'users' => (array) $query->get_results(),
			'total' => (int) $query->get_total(),
		);
	}

	/**
	 * How many students have a parent number on file.
	 *
	 * @return array `array( 'with' => int, 'without' => int, 'total' => int )`
	 */
	public static function coverage() {
		$cached = get_transient( 'ces_parent_coverage' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$with = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
				self::META_PHONE
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$out = array(
			'with'    => $with,
			'without' => max( 0, $total - $with ),
			'total'   => $total,
		);

		set_transient( 'ces_parent_coverage', $out, 5 * MINUTE_IN_SECONDS );

		return $out;
	}
}
