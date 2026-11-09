<?php

defined( 'ABSPATH' ) || exit;

/** Version publishing without modifying existing workflow instances. */
final class ECM_Workflow_Versions {
	public static function published( int $event_id ): ?object {
		if ( $event_id < 1 || ! ECM_Workflow_Schema::installed() ) {
			return null;
		}
		global $wpdb;
		$table = ECM_DB::table( 'flow_versions' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE event_id=%d AND status='published' ORDER BY version_number DESC LIMIT 1",
			$event_id
		) );
		return $row ?: null;
	}

	/**
	 * This API is not exposed to public forms. Admin-only, and feature-flagged.
	 * A locked event row serializes simultaneous publishes for one event.
	 */
	public static function publish( int $event_id, array $definition ): int|WP_Error {
		if ( ! current_user_can( 'manage_ecm_events' ) ) {
			return new WP_Error( 'ecm_flow_forbidden', 'دسترسی مدیریت رویداد لازم است.' );
		}
		if ( ! ECM_Workflow::enabled() ) {
			return new WP_Error( 'ecm_flow_disabled', 'موتور فرایند هنوز فعال نشده است.' );
		}
		$validated = ECM_Workflow::validate_definition( $definition );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$event = ECM_Events::event( $event_id );
		if ( ! $event ) {
			return new WP_Error( 'ecm_flow_event_missing', 'رویداد پیدا نشد.' );
		}
		$encoded = wp_json_encode( $validated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! $encoded ) {
			return new WP_Error( 'ecm_flow_invalid_json', 'ذخیره تعریف فرایند ممکن نشد.' );
		}
		$hash = hash( 'sha256', $encoded );
		global $wpdb;
		$events = ECM_DB::table( 'event_definitions' );
		$table = ECM_DB::table( 'flow_versions' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ecm_flow_transaction', 'تراکنش دیتابیس آغاز نشد.' );
		}
		$committed = false;
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$events} WHERE id=%d FOR UPDATE", $event_id ) );
			if ( (int) $locked !== $event_id ) {
				return new WP_Error( 'ecm_flow_event_locked', 'قفل‌گذاری روی رویداد انجام نشد.' );
			}
			$previous = $wpdb->get_row( $wpdb->prepare(
				"SELECT id,content_hash FROM {$table} WHERE event_id=%d AND status='published' ORDER BY version_number DESC LIMIT 1 FOR UPDATE",
				$event_id
			) );
			if ( $previous && hash_equals( (string) $previous->content_hash, $hash ) ) {
				// A same-content publish is idempotent: do not invent a new version.
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					return new WP_Error( 'ecm_flow_commit', 'تثبیت تراکنش ناموفق بود.' );
				}
				$committed = true;
				return (int) $previous->id;
			}
			$version = 1 + (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(MAX(version_number),0) FROM {$table} WHERE event_id=%d",
				$event_id
			) );
			$now = ECM_Utils::now_utc();
			if ( false === $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET status='archived' WHERE event_id=%d AND status='published'",
				$event_id
			) ) ) {
				return new WP_Error( 'ecm_flow_archive_failed', 'بایگانی نسخه قبلی موفق نبود.' );
			}
			$ok = $wpdb->insert( $table, array(
				'event_id'           => $event_id,
				'version_number'     => $version,
				'status'             => 'published',
				'definition_json'    => $encoded,
				'content_hash'      => $hash,
				'created_by_user_id' => get_current_user_id(),
				'created_at'         => $now,
				'published_at'       => $now,
			) );
			if ( ! $ok ) {
				return new WP_Error( 'ecm_flow_insert_failed', 'ثبت نسخه جدید ناموفق بود.' );
			}
			$id = (int) $wpdb->insert_id;
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return new WP_Error( 'ecm_flow_commit', 'تثبیت تراکنش ناموفق بود.' );
			}
			$committed = true;
			ECM_Diagnostics::record( 'migration', 'Workflow definition published', array(
				'event_id' => $event_id,
				'flow_version_id' => $id,
				'version_number' => $version,
			) );
			return $id;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}
}
