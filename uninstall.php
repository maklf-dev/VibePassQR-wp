<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = (array) get_option( 'ecm_settings', array() );
foreach ( array_keys( wp_roles()->roles ) as $role_key ) {
	$role = get_role( $role_key );
	if ( $role ) {
		foreach(array('access_ecm','view_ecm_reports','manage_ecm_events','manage_ecm_attendance','record_ecm_attendance','view_own_ecm_attendance','manage_ecm_settings','manage_ecm_files','manage_ecm_sms','manage_ecm_diagnostics','repair_ecm_pages') as $cap)$role->remove_cap($cap);
	}
}
remove_role( 'ecm_manager' );
remove_role( 'ecm_attendance_operator' );
wp_clear_scheduled_hook('ecm_sms_sweep');
wp_unschedule_hook('ecm_sms_job');
wp_clear_scheduled_hook('ecm_migrate_participants');

if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$files_table=$wpdb->prefix.'ecm_files';$base=realpath(wp_upload_dir()['basedir']);
if($base && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($files_table)))===$files_table){
 foreach($wpdb->get_col("SELECT relative_path FROM {$files_table}") as $rel){
  if(!preg_match('/^[a-z0-9_-]+\/[a-f0-9]{32}\.ecm$/',$rel))continue;
  $path=realpath($base.'/'.$rel);if($path&&str_starts_with($path,trailingslashit($base)))wp_delete_file($path);
 }
}
foreach ( array( 'otp', 'ticket_tokens', 'links','files','sms_attempts','sms','registration_meta', 'events', 'attendance', 'participants', 'people', 'event_operator_acl', 'sessions', 'registrations', 'ticket_templates', 'event_definitions' ) as $suffix ) {
	$table = $wpdb->prefix . 'ecm_' . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$pages = (array) get_option( 'ecm_pages', array() );
foreach ( $pages as $page_id ) {
	if ( $page_id ) {
        if(get_post_meta((int)$page_id,'_ecm_created',true))wp_delete_post( (int) $page_id, true );
	}
}

delete_option( 'ecm_settings' );
delete_option( 'ecm_pages' );
delete_option( 'ecm_db_version' );
delete_option('ecm_sms_key');
delete_option('ecm_old_paths');
delete_option('ecm_diagnostics_file_token');
delete_option('ecm_activity_file_token');
delete_option('ecm_default_event_id');
delete_option('ecm_participant_migration_pending');
delete_option('ecm_default_acl_seeded');
delete_option('ecm_release_cleanup_030');
delete_option('ecm_sms_network_snapshot');
delete_option('ecm_sms_provider_snapshot');
delete_option('ecm_sms_pattern_seed_040');
delete_option('ecm_sms_pattern_seed_060');
delete_option('ecm_dynamic_ticket_storage_050');
delete_metadata('user',0,'ecm_report_columns','',true);

$template_root = trailingslashit( wp_upload_dir()['basedir'] ) . 'event-card-manager-ticket-templates';
if ( is_dir( $template_root ) ) {
	foreach ( glob( $template_root . '/event-*', GLOB_ONLYDIR ) ?: array() as $event_dir ) {
		if ( ! preg_match( '#/event-\d+$#', str_replace( '\\', '/', $event_dir ) ) ) continue;
		foreach ( glob( $event_dir . '/*.pdf' ) ?: array() as $template_file ) wp_delete_file( $template_file );
		foreach ( array( 'index.php', '.htaccess' ) as $guard_file ) {
			if ( is_file( $event_dir . '/' . $guard_file ) ) wp_delete_file( $event_dir . '/' . $guard_file );
		}
		rmdir( $event_dir );
	}
	foreach ( array( 'index.php', '.htaccess' ) as $guard_file ) {
		if ( is_file( $template_root . '/' . $guard_file ) ) wp_delete_file( $template_root . '/' . $guard_file );
	}
	rmdir( $template_root );
	$parent = dirname( $template_root );
	if ( is_dir( $parent ) && array() === array_diff( scandir( $parent ) ?: array(), array( '.', '..' ) ) ) rmdir( $parent );
}

$diagnostics_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'event-card-manager-diagnostics';
if ( is_dir( $diagnostics_dir ) ) {
	foreach ( glob( $diagnostics_dir . '/diagnostics-*.jsonl' ) ?: array() as $diagnostic_file ) {
		wp_delete_file( $diagnostic_file );
	}
	foreach ( array( 'index.php', '.htaccess' ) as $guard_file ) {
		if ( is_file( $diagnostics_dir . '/' . $guard_file ) ) {
			wp_delete_file( $diagnostics_dir . '/' . $guard_file );
		}
	}
	rmdir( $diagnostics_dir );
}

$activity_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'event-card-manager-activity';
if ( is_dir( $activity_dir ) ) {
	foreach ( glob( $activity_dir . '/activity-*.json' ) ?: array() as $activity_file ) {
		wp_delete_file( $activity_file );
	}
	foreach ( array( 'index.php', '.htaccess' ) as $guard_file ) {
		if ( is_file( $activity_dir . '/' . $guard_file ) ) {
			wp_delete_file( $activity_dir . '/' . $guard_file );
		}
	}
	rmdir( $activity_dir );
}
