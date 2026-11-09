<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Activator {
	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$queries = array();
		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'registrations' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id varchar(32) NOT NULL,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			primary_person_id bigint(20) unsigned NOT NULL DEFAULT 0,
			primary_participant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ticket_template_id bigint(20) unsigned NOT NULL DEFAULT 0,
			is_test tinyint(1) unsigned NOT NULL DEFAULT 0,
				dedupe_phone_hash char(64) DEFAULT NULL,
			form_data longtext NOT NULL,
			amount_original decimal(20,6) NOT NULL DEFAULT 0,
			currency_label varchar(40) NOT NULL DEFAULT 'ریال',
			exchange_rate decimal(20,6) NOT NULL DEFAULT 1,
			amount_rial bigint(20) unsigned NOT NULL DEFAULT 0,
			payment_status varchar(30) NOT NULL DEFAULT 'pending',
			payment_reference varchar(100) DEFAULT NULL,
			paid_at datetime DEFAULT NULL,
			access_hash varchar(255) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY event_id (event_id),
			KEY event_created (event_id,created_at),
			KEY event_payment_created (event_id,payment_status,created_at),
			KEY primary_person_id (primary_person_id),
			KEY primary_participant_id (primary_participant_id),
			KEY ticket_template_id (ticket_template_id),
			KEY test_event_created (is_test,event_id,created_at),
				UNIQUE KEY event_dedupe_phone (event_id,dedupe_phone_hash),
			KEY payment_status (payment_status),
			KEY created_at (created_at)
		) {$charset};";

		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'sessions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(190) NOT NULL,
			start_at_utc datetime NOT NULL,
			end_at_utc datetime NOT NULL,
			max_entries int(10) unsigned NOT NULL DEFAULT 1,
			success_message text NOT NULL,
			repeat_message text NOT NULL,
			attendance_mode varchar(20) NOT NULL DEFAULT 'inherit',
			sms_config longtext DEFAULT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY active_window (enabled,start_at_utc,end_at_utc),
			KEY event_window (event_id,enabled,start_at_utc,end_at_utc)
		) {$charset};";

		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'attendance' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			registration_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			participant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			session_id bigint(20) unsigned NOT NULL,
			entry_no int(10) unsigned NOT NULL DEFAULT 1,
			status varchar(30) NOT NULL DEFAULT 'success',
			request_hash char(64) DEFAULT NULL,
			recorded_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recorder_name varchar(190) DEFAULT NULL,
			recorder_code varchar(100) DEFAULT NULL,
			method varchar(30) NOT NULL DEFAULT 'self_service',
			device_type varchar(30) NOT NULL DEFAULT '',
			device_label varchar(190) NOT NULL DEFAULT '',
			user_agent varchar(500) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY registration_participant_session_entry (registration_id,participant_id,session_id,entry_no),
			UNIQUE KEY request_hash (request_hash),
			KEY event_id (event_id),
			KEY event_session_created (event_id,session_id,created_at),
			KEY registration_session_created (registration_id,session_id,created_at),
			KEY session_participant (session_id,participant_id),
			KEY participant_id (participant_id),
			KEY recorded_by_user_id (recorded_by_user_id),
			KEY recorder_created (recorded_by_user_id,created_at),
			KEY created_at (created_at)
		) {$charset};";

		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'registration_meta' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			registration_id bigint(20) unsigned NOT NULL,
			field_key varchar(100) NOT NULL,
			field_hash char(64) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY registration_field (registration_id,field_key),
			KEY field_lookup (field_key,field_hash)
		) {$charset};";

		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			registration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			domain_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			participant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			correlation_id varchar(64) DEFAULT NULL,
			event_type varchar(60) NOT NULL,
			meta longtext DEFAULT NULL,
			ip_hash char(64) DEFAULT NULL,
			user_agent varchar(500) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY registration_type (registration_id,event_type),
			KEY domain_event_id (domain_event_id),
			KEY domain_type_created (domain_event_id,event_type,created_at),
			KEY participant_id (participant_id),
			KEY actor_user_id (actor_user_id),
			KEY created_at (created_at)
		) {$charset};";

		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'event_definitions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id varchar(32) NOT NULL,
			slug varchar(190) NOT NULL,
			title varchar(190) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			enabled tinyint(1) NOT NULL DEFAULT 1,
			disabled_title varchar(190) NOT NULL DEFAULT 'رویداد غیرفعال است',
			disabled_message text DEFAULT NULL,
			attendance_mode varchar(20) NOT NULL DEFAULT 'self_service',
			show_operator_login tinyint(1) NOT NULL DEFAULT 0,
			guest_limit smallint(5) unsigned NOT NULL DEFAULT 0,
			group_checkin_mode varchar(30) NOT NULL DEFAULT 'selective',
			unauthorized_title varchar(190) NOT NULL,
			unauthorized_message text NOT NULL,
			login_message text NOT NULL,
			config_json longtext DEFAULT NULL,
			created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY slug (slug),
			KEY status (status),
			KEY attendance_mode (attendance_mode)
		) {$charset};";

		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'people' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id varchar(32) NOT NULL,
			full_name varchar(190) NOT NULL DEFAULT '',
			normalized_phone varchar(30) NOT NULL DEFAULT '',
			phone_hash char(64) DEFAULT NULL,
			email varchar(190) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY phone_hash (phone_hash)
		) {$charset};";

		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'participants' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id varchar(32) NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			registration_id bigint(20) unsigned NOT NULL,
			person_id bigint(20) unsigned NOT NULL,
			display_name varchar(190) NOT NULL DEFAULT '',
			participant_type varchar(20) NOT NULL DEFAULT 'primary',
			invited_by_participant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			guest_order smallint(5) unsigned NOT NULL DEFAULT 0,
			access_hash varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY event_id (event_id),
			KEY registration_id (registration_id),
			KEY registration_status (registration_id,status),
			KEY event_type_status (event_id,participant_type,status),
			KEY person_id (person_id),
			KEY invited_by_participant_id (invited_by_participant_id),
			KEY participant_status (participant_type,status)
		) {$charset};";

		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'event_operator_acl' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			principal_type varchar(20) NOT NULL,
			principal_key varchar(190) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_principal (event_id,principal_type,principal_key),
			KEY principal_lookup (principal_type,principal_key)
		) {$charset};";

		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'ticket_templates' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			version_key char(64) NOT NULL,
			source_type varchar(20) NOT NULL DEFAULT 'bundled',
			relative_path varchar(255) NOT NULL,
			original_name varchar(190) NOT NULL DEFAULT '',
			file_hash char(64) NOT NULL,
			layout_json longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_version (event_id,version_key),
			KEY event_status (event_id,status),
			KEY created_at (created_at)
		) {$charset};";

		$queries[] = 'CREATE TABLE ' . ECM_DB::table( 'ticket_tokens' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			participant_id bigint(20) unsigned NOT NULL,
			registration_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			scope varchar(20) NOT NULL,
			token_hash char(64) NOT NULL,
			token_cipher text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			UNIQUE KEY participant_scope (participant_id,scope),
			KEY registration_scope (registration_id,scope),
			KEY event_scope_status (event_id,scope,status)
		) {$charset};";

		foreach ( $queries as $query ) {
			dbDelta( $query );
		}

		// The legacy unique key was registration-scoped. It must be replaced so
		// the host and each guest can check in independently for the same session.
		$attendance_table = ECM_DB::table( 'attendance' );
		$legacy_index = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$attendance_table} WHERE Key_name=%s", 'registration_session_entry' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new_index    = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$attendance_table} WHERE Key_name=%s", 'registration_participant_session_entry' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $new_index ) {
			$wpdb->query( "ALTER TABLE {$attendance_table} ADD UNIQUE KEY registration_participant_session_entry (registration_id,participant_id,session_id,entry_no)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$new_index = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$attendance_table} WHERE Key_name=%s", 'registration_participant_session_entry' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $new_index && $legacy_index ) {
			$wpdb->query( "ALTER TABLE {$attendance_table} DROP INDEX registration_session_entry" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Keep the name printed on each issued card independent from the shared
		// phone identity. This is required when multiple guests use one number.
		$participants_table = ECM_DB::table( 'participants' );
		$people_table       = ECM_DB::table( 'people' );
		$wpdb->query( "UPDATE {$participants_table} p INNER JOIN {$people_table} pe ON pe.id=p.person_id SET p.display_name=pe.full_name WHERE p.display_name=''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		ECM_Capabilities::install();

		if ( ! get_option( 'ecm_settings' ) ) {
			add_option( 'ecm_settings', ECM_Utils::defaults(), '', false );
		}
		ECM_Events::install();
		ECM_Tickets::install();
		self::create_pages();
		ECM_Upgrade::install();
		// Additive migration only. A failed schema install must not mark this
		// release as complete; next authorized admin request may retry.
		if ( ECM_Workflow_Schema::install() ) {
			update_option( 'ecm_db_version', ECM_VERSION, false );
		}

		flush_rewrite_rules();
	}

	public static function create_pages(): void {
		$definitions = array(
			'registration' => array( 'ثبت‌نام رویداد', '[ecm_registration]' ),
			'recovery'     => array( 'بازیابی کارت', '[ecm_recovery]' ),
			'card'         => array( 'کارت ورود', '[ecm_card]' ),
			'attendance'   => array( 'ثبت حضور', '[ecm_attendance]' ),
			'manager'      => array( 'گزارش رویداد', '[ecm_manager]' ),
			'portal'       => array( 'پنل اختصاصی رویداد', '[ecm_portal]' ),
		);
		$pages = (array) get_option( 'ecm_pages', array() );
		foreach ( $definitions as $key => $definition ) {
			if ( ! empty( $pages[ $key ] ) && get_post_status( (int) $pages[ $key ] ) && 'trash' !== get_post_status( (int) $pages[ $key ] ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_title'   => $definition[0],
					'post_content' => $definition[1],
					'post_status'  => 'publish',
					'post_type'    => 'page',
                    'post_name'    => ECM_Upgrade::slugs()[$key],
				)
			);
			if ( ! is_wp_error( $id ) ) {
				$pages[ $key ] = (int) $id;
                update_post_meta($id,'_ecm_created',1);
			}
		}
		update_option( 'ecm_pages', $pages, false );
	}

	public static function deactivate(): void {
        wp_clear_scheduled_hook('ecm_sms_sweep');
		wp_clear_scheduled_hook( 'ecm_migrate_participants' );
		flush_rewrite_rules();
	}
}
