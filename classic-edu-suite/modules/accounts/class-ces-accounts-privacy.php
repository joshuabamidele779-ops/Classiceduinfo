<?php
/**
 * WordPress privacy tools: export and erase.
 *
 * This module holds the only third-party personal data in the plugin — a
 * parent's name, phone number and sometimes email address, given by someone
 * else. So it is the one module that has to answer "what do you hold about
 * me, and will you delete it".
 *
 * Both tools are registered against the **student's** account, because that
 * is the account WordPress knows about. A parent who asks directly is handled
 * by staff from the Approvals screen, and the withdraw-consent button on the
 * parent's own page deletes their number without anyone's help.
 *
 * The audit trail is deliberately *not* erased by the anonymiser: a school
 * needs to be able to show that a parent was asked and did consent. It goes
 * when the account itself is deleted, which WordPress does for us.
 *
 * @package ClassicEduSuite\Accounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Personal-data exporter and eraser.
 */
class CES_Accounts_Privacy {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );

		// When an account goes, its approval history and requests go with it.
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ) );
	}

	/**
	 * Add our exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			$exporters = array();
		}

		$exporters['classic-edu-accounts'] = array(
			'exporter_friendly_name' => __( 'Classic EDU account and parent approval', 'classic-edu-suite' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add our eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			$erasers = array();
		}

		$erasers['classic-edu-accounts'] = array(
			'eraser_friendly_name' => __( 'Classic EDU account and parent approval', 'classic-edu-suite' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export everything this module holds about one person.
	 *
	 * @param string $email Email address being exported.
	 * @param int    $page  Page number, 1-based.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		unset( $page );

		$user = get_user_by( 'email', sanitize_email( (string) $email ) );

		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}

		$record  = CES_Accounts_Status::record( (int) $user->ID );
		$contact = CES_Parent_Contact::get( (int) $user->ID );

		$fields = array(
			array(
				'name'  => __( 'Account status', 'classic-edu-suite' ),
				'value' => $record['status_label'],
			),
			array(
				'name'  => __( 'How this account signs in', 'classic-edu-suite' ),
				'value' => 'google' === $record['provider'] ? __( 'Gmail (Google sign-in)', 'classic-edu-suite' ) : __( 'Username and password', 'classic-edu-suite' ),
			),
			array(
				'name'  => __( 'Class or level', 'classic-edu-suite' ),
				'value' => $record['class_label'],
			),
			array(
				'name'  => __( 'Date of birth', 'classic-edu-suite' ),
				'value' => $record['dob'],
			),
			array(
				'name'  => __( 'Approved on', 'classic-edu-suite' ),
				'value' => $record['approved_at'],
			),
			array(
				'name'  => __( 'Parent or guardian name', 'classic-edu-suite' ),
				'value' => $contact['name'],
			),
			array(
				'name'  => __( 'Parent or guardian relationship', 'classic-edu-suite' ),
				'value' => $contact['relation_label'],
			),
			array(
				'name'  => __( 'Parent or guardian WhatsApp number', 'classic-edu-suite' ),
				'value' => $contact['phone_pretty'],
			),
			array(
				'name'  => __( 'Parent or guardian email address', 'classic-edu-suite' ),
				'value' => CES_Accounts_Verification::parent_email( (int) $user->ID ),
			),
			array(
				'name'  => __( 'Parent confirmed on', 'classic-edu-suite' ),
				'value' => $record['verified_at'],
			),
			array(
				'name'  => __( 'Messages to the parent stopped at their request', 'classic-edu-suite' ),
				'value' => CES_Accounts_Verification::opted_out( (int) $user->ID ) ? __( 'Yes', 'classic-edu-suite' ) : __( 'No', 'classic-edu-suite' ),
			),
		);

		$data = array(
			array(
				'group_id'    => 'ces-account',
				'group_label' => __( 'Classic EDU account', 'classic-edu-suite' ),
				'item_id'     => 'ces-account-' . (int) $user->ID,
				'data'        => array_values(
					array_filter(
						$fields,
						static function ( $field ) {
							return '' !== trim( (string) $field['value'] );
						}
					)
				),
			),
		);

		// The approval history, in the student's own reading of it.
		$labels = CES_Accounts_Dashboard::event_labels();
		$items  = array();

		foreach ( CES_Accounts_Status::events( (int) $user->ID, 100 ) as $event ) {
			$slug = (string) $event['event'];

			$items[] = array(
				'name'  => (string) $event['created_at'],
				'value' => isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug,
			);
		}

		if ( ! empty( $items ) ) {
			$data[] = array(
				'group_id'    => 'ces-account-history',
				'group_label' => __( 'Classic EDU account history', 'classic-edu-suite' ),
				'item_id'     => 'ces-account-history-' . (int) $user->ID,
				'data'        => $items,
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase the parent's details and the approval requests.
	 *
	 * The student's own status is kept, but reset to `onboarding`, because an
	 * approval that rests on a number we no longer hold is not an approval.
	 *
	 * @param string $email Email address being erased.
	 * @param int    $page  Page number, 1-based.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );

		$user = get_user_by( 'email', sanitize_email( (string) $email ) );

		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = (int) $user->ID;

		CES_Accounts_Verification::delete_for_user( $user_id );
		CES_Parent_Contact::delete( $user_id );

		foreach ( array(
			CES_Accounts_Verification::META_PARENT_EMAIL,
			CES_Accounts_Verification::META_TOKEN,
			CES_Accounts_Verification::META_PORTAL_TOKEN,
			CES_Accounts_Verification::META_OPT_OUT,
			CES_Accounts_Onboarding::META_OWN_PHONE,
			CES_Accounts_Status::META_DOB,
			CES_Accounts_Login::META_AVATAR,
		) as $key ) {
			delete_user_meta( $user_id, $key );
		}

		CES_Accounts_Status::reset(
			$user_id,
			array(
				'actor_id' => get_current_user_id(),
				'detail'   => __( 'Parent details erased on request.', 'classic-edu-suite' ),
			)
		);

		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array(
				__( 'The parent or guardian contact details and every approval request were deleted. The record that an approval once happened is kept as a safeguarding record, without the number.', 'classic-edu-suite' ),
			),
			'done'           => true,
		);
	}

	/**
	 * Clean up after a deleted account.
	 *
	 * WordPress removes the user meta itself; the two custom tables are ours.
	 *
	 * @param int $user_id Account that was deleted.
	 * @return void
	 */
	public static function on_user_deleted( $user_id ) {
		CES_Accounts_Verification::delete_for_user( $user_id );
		CES_Accounts_Status::delete_events( $user_id );
		CES_Accounts_Status::flush_counts();
	}
}
