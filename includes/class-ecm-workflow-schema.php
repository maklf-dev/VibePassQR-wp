<?php

defined( 'ABSPATH' ) || exit;

/**
 * Additive-only Workflow schema. No existing registration, participant, ticket,
 * SMS, event configuration or QR content is rewritten by this migration.
 */
final class ECM_Workflow_Schema {
	public const SCHEMA_VERSION = '1';

	public static function installed(): bool {
		return self::SCHEMA_VERSION === (string) get_option( 'ecm_workflow_schema_version', '' );
	}

	/**
	 * Safe to retry after an interrupted update. Uses a MySQL advisory lock to
	 * prevent concurrent admins from running the same DDL statements.
	 */
	public static function install(): bool {
		if ( self::installed() ) {
			return true;
		}
		global $wpdb;
		$lock_name = 'ecm_flow_' . substr( hash( 'sha256', $wpdb->prefix . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 42 );
		$locked = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock_name ) );
		if ( '1' !== $locked ) {
			return false;
		}
		try {
			if ( self::installed() ) {
				return true;
			}
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset = $wpdb->get_charset_collate();
			$versions = ECM_DB::table( 'flow_versions' );
			$instances = ECM_DB::table( 'flow_instances' );
			$files = ECM_DB::table( 'flow_files' );
			$audit = ECM_DB::table( 'events' );

			// Workflow versions are immutable once published. Existing instances
			// always reference the exact version with which they began.
			dbDelta( "CREATE TABLE {$versions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				version_number int(10) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				definition_json longtext NOT NULL,
				content_hash char(64) NOT NULL,
				created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				published_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_version (event_id,version_number),
				KEY event_status (event_id,status)
			) ENGINE=InnoDB {$charset};" );

			// The public tracking code is never stored as plaintext. Phone lookup
			// uses a keyed digest, not a reversibly encoded telephone number.
			dbDelta( "CREATE TABLE {$instances} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				flow_version_id bigint(20) unsigned NOT NULL,
				current_step_key varchar(80) NOT NULL,
				state varchar(32) NOT NULL DEFAULT 'draft',
				tracking_hash char(64) NOT NULL,
				identifier_digest char(64) NOT NULL,
				data_json longtext NOT NULL,
				linked_registration_id bigint(20) unsigned DEFAULT NULL,
				lock_version int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				expires_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY tracking_hash (tracking_hash),
				UNIQUE KEY linked_registration_id (linked_registration_id),
				KEY event_state_updated (event_id,state,updated_at),
				KEY event_identity (event_id,identifier_digest),
				KEY state_expiry (state,expires_at),
				KEY flow_version_id (flow_version_id)
			) ENGINE=InnoDB {$charset};" );

			// File data itself must later live outside the public uploads tree.
			// Phase 2 creates only its metadata table; no public upload handler.
			dbDelta( "CREATE TABLE {$files} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				instance_id bigint(20) unsigned NOT NULL,
				file_reference varchar(255) NOT NULL,
				mime_type varchar(80) NOT NULL,
				sha256 char(64) NOT NULL,
				size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				version_no int(10) unsigned NOT NULL DEFAULT 1,
				status varchar(24) NOT NULL DEFAULT 'pending',
				uploaded_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY instance_version (instance_id,version_no),
				KEY instance_status (instance_id,status)
			) ENGINE=InnoDB {$charset};" );

			foreach ( array( $versions, $instances, $files ) as $table ) {
				$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
				if ( $table !== $found ) {
					return false;
				}
			}

			// Extends the existing audit trail; never recreates its records.
			$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$audit} LIKE %s", 'flow_instance_id' ) );
			if ( ! $column ) {
				if ( false === $wpdb->query( "ALTER TABLE {$audit} ADD COLUMN flow_instance_id bigint(20) unsigned NOT NULL DEFAULT 0" ) ) {
					return false;
				}
			}
			$index = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$audit} WHERE Key_name=%s", 'flow_instance_type' ) );
			if ( ! $index ) {
				if ( false === $wpdb->query( "ALTER TABLE {$audit} ADD KEY flow_instance_type (flow_instance_id,event_type,created_at)" ) ) {
					return false;
				}
			}
			update_option( 'ecm_workflow_schema_version', self::SCHEMA_VERSION, false );
			// Intentional: the workflow activation flag is NOT created or enabled.
			return true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}
