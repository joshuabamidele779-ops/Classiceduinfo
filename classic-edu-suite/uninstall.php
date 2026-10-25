<?php
/**
 * Uninstall routine.
 *
 * Only destroys data when the site owner explicitly opted in by enabling
 * "Delete all data on uninstall" in the settings. The default is to keep
 * everything, because coin balances and exam results are records a school
 * does not want silently erased by a plugin delete.
 *
 * @package ClassicEduSuite
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ces_settings = get_option( 'ces_settings', array() );
$ces_purge    = is_array( $ces_settings ) && ! empty( $ces_settings['delete_data_on_uninstall'] );

if ( ! $ces_purge ) {
	return;
}

global $wpdb;

// ---------------------------------------------------------------------
// Custom tables.
// ---------------------------------------------------------------------
$ces_tables = array(
	'attempt_answers',
	'attempts',
	'wallet_ledger',
	'referrals',
	'video_views',
	'quote_log',
	'parent_verifications',
	'account_events',
	'class_members',
	'chat_messages',
);

foreach ( $ces_tables as $ces_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ces_' . $ces_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}

// ---------------------------------------------------------------------
// Plugin content.
// ---------------------------------------------------------------------
$ces_post_types = array(
	'ces_question',
	'ces_class',
	'ces_session',
	'ces_exam',
	'ces_course',
	'ces_lesson',
	'ces_quote',
	'ces_resource',
	'ces_institution',
	'ces_department',
	'ces_career',
);

foreach ( $ces_post_types as $ces_post_type ) {
	$ces_ids = get_posts(
		array(
			'post_type'        => $ces_post_type,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);

	foreach ( $ces_ids as $ces_id ) {
		wp_delete_post( $ces_id, true );
	}
}

// ---------------------------------------------------------------------
// Taxonomy terms.
// ---------------------------------------------------------------------
foreach ( array(
	'ces_subject',
	'ces_exam_type',
	'ces_topic',
	'ces_year',
	'ces_syllabus',
	'ces_level',
	'ces_quote_category',
	'ces_resource_type',
	'ces_programme',
	'ces_institution_type',
	'ces_state',
) as $ces_tax ) {
	$ces_terms = get_terms(
		array(
			'taxonomy'   => $ces_tax,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);

	if ( is_wp_error( $ces_terms ) ) {
		continue;
	}

	foreach ( $ces_terms as $ces_term_id ) {
		wp_delete_term( $ces_term_id, $ces_tax );
	}
}

// ---------------------------------------------------------------------
// Options, user meta, roles, cron.
// ---------------------------------------------------------------------
foreach ( array(
	'ces_settings',
	'ces_db_version',
	'ces_stream_secret',
	'ces_activated_at',
	'ces_flush_rewrite',
	'ces_quotes_seeded',
	'ces_cbt_terms_seeded',
	'ces_hub_terms_seeded',
	'ces_hub_bank_version',
	'ces_guidance_terms_seeded',
) as $ces_option ) {
	delete_option( $ces_option );
}

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ces\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ces\_%' OR option_name LIKE '\_transient\_timeout\_ces\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

remove_role( 'ces_instructor' );
remove_role( 'ces_consultant' );

$ces_admin = get_role( 'administrator' );
if ( $ces_admin ) {
	foreach ( array( 'ces_manage', 'ces_manage_cbt', 'ces_manage_lms', 'ces_manage_quotes', 'ces_manage_wallet', 'ces_manage_settings', 'ces_view_reports', 'ces_manage_hub', 'ces_manage_guidance', 'ces_manage_parents', 'ces_manage_accounts', 'ces_manage_classroom', 'ces_moderate_chat', 'ces_consultant' ) as $ces_cap ) {
		$ces_admin->remove_cap( $ces_cap );
	}
}

foreach ( array( 'ces_daily_maintenance', 'ces_process_video_queue', 'ces_rotate_daily_quote', 'ces_expire_stale_attempts', 'ces_accounts_maintenance', 'ces_classroom_maintenance' ) as $ces_hook ) {
	wp_clear_scheduled_hook( $ces_hook );
}

// ---------------------------------------------------------------------
// Private media. Uploaded video source files and HLS output.
// ---------------------------------------------------------------------
$ces_uploads = wp_get_upload_dir();

if ( empty( $ces_uploads['error'] ) ) {
	$ces_private = trailingslashit( $ces_uploads['basedir'] ) . 'ces-private';

	if ( is_dir( $ces_private ) ) {
		$ces_iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $ces_private, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $ces_iterator as $ces_file ) {
			if ( $ces_file->isDir() ) {
				@rmdir( $ces_file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				@unlink( $ces_file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		@rmdir( $ces_private ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
