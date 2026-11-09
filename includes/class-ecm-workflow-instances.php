<?php

defined( 'ABSPATH' ) || exit;

/**
 * Phase-2 persistence adapter. Admin-only until the public form validator,
 * consent audit, receipt security and OTP proof are fully integrated.
 */
final class ECM_Workflow_Instances {
	/** Starts an internal draft; it cannot issue a card or trigger a message. */
	public static function create_draft( int $event_id, string $mobile, array $validated_fields = array() ): array|WP_Error {
		if ( ! current_user_can( 'manage_ecm_events' ) ) {
			return new WP_Error( 'ecm_flow_forbidden', 'دسترسی مدیریت رویداد لازم است.' );
		}
		if ( ! ECM_Workflow::enabled() ) {
			return new WP_Error( 'ecm_flow_disabled', 'موتور فرایند غیرفعال است.' );
		}
		$event = ECM_Events::event( $event_id );
		if ( ! $event || 'published' !== (string) $event->status || ! (int) $event->enabled
			|| 'custom_workflow' !== ECM_Workflow::mode_for_event( $event ) ) {
			return new WP_Error( 'ecm_flow_event_not_ready', 'این رویداد برای فرایند جدید آماده نیست.' );
		}
		$version = ECM_Workflow_Versions::published( $event_id );
		if ( ! $version ) {
			return new WP_Error( 'ecm_flow_version_missing', 'نسخه منتشرشده برای این فرایند یافت نشد.' );
		}
		$definition = json_decode( (string) $version->definition_json, true );
		if ( ! is_array( $definition ) || empty( $definition['steps'][0]['key'] ) ) {
			return new WP_Error( 'ecm_flow_corrupt_version', 'تعریف فرایند معتبر نیست.' );
		}
		$digest = ECM_Workflow::identity_digest( $event_id, $mobile );
		if ( is_wp_error( $digest ) ) {
			return $digest;
		}
		// Only simple, explicitly validated scalar fields are accepted in phase 2.
		// The production field-schema and guest validator belong to phase 3.
		if ( count( $validated_fields ) > 50 ) {
			return new WP_Error( 'ecm_flow_data_too_large', 'تعداد فیلدها بیش از حد مجاز است.' );
		}
		$data = array();
		foreach ( $validated_fields as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{0,79}$/D', $key )
				|| ! is_scalar( $value ) ) {
				return new WP_Error( 'ecm_flow_invalid_data', 'داده فرم در این مرحله معتبر نیست.' );
			}
			$data[ $key ] = substr( sanitize_textarea_field( (string) $value ), 0, 2048 );
		}
		$encoded = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! $encoded || strlen( $encoded ) > 32768 ) {
			return new WP_Error( 'ecm_flow_data_too_large', 'حجم داده ثبت‌نام بیش از حد مجاز است.' );
		}
		$uuid = wp_generate_uuid4();
		$tracking = strtoupper( bin2hex( random_bytes( 16 ) ) );
		$now = ECM_Utils::now_utc();
		global $wpdb;
		$ok = $wpdb->insert( ECM_DB::table( 'flow_instances' ), array(
			'public_id'              => $uuid,
			'event_id'               => $event_id,
			'flow_version_id'        => (int) $version->id,
			'current_step_key'       => (string) $definition['steps'][0]['key'],
			'state'                  => 'draft',
			'tracking_hash'          => ECM_Workflow::tracking_digest( $tracking ),
			'identifier_digest'      => $digest,
			'data_json'              => $encoded,
			'lock_version'           => 0,
			'created_at'             => $now,
			'updated_at'             => $now,
			'expires_at'             => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 7 ),
		) );
		if ( ! $ok ) {
			return new WP_Error( 'ecm_flow_create_failed', 'ایجاد پیش‌نویس ممکن نشد.' );
		}
		return array( 'id' => (int) $wpdb->insert_id, 'public_id' => $uuid, 'tracking_code' => $tracking );
	}

	/** Only a minimal admin-owned draft transition is supported in this alpha. */
	public static function advance_draft( int $instance_id, int $expected_version ): bool|WP_Error {
		if ( ! current_user_can( 'manage_ecm_events' ) || ! ECM_Workflow::enabled() ) {
			return new WP_Error( 'ecm_flow_forbidden', 'این عملیات در دسترس نیست.' );
		}
		if ( $instance_id < 1 || $expected_version < 0 ) {
			return new WP_Error( 'ecm_flow_invalid_request', 'درخواست معتبر نیست.' );
		}
		global $wpdb;
		$table = ECM_DB::table( 'flow_instances' );
		$events = ECM_DB::table( 'events' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ecm_flow_transaction', 'امکان آغاز تراکنش نیست.' );
		}
		$committed = false;
		try {
			$instance = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d FOR UPDATE", $instance_id ) );
			if ( ! $instance || (int) $instance->lock_version !== $expected_version
				|| ! ECM_Workflow::may_persist_transition( (string) $instance->state, 'awaiting_agreement' ) ) {
				return new WP_Error( 'ecm_flow_stale_state', 'وضعیت درخواست تغییر کرده یا این انتقال مجاز نیست.' );
			}
			$version = $wpdb->get_row( $wpdb->prepare(
				'SELECT definition_json FROM ' . ECM_DB::table( 'flow_versions' ) . ' WHERE id=%d AND event_id=%d',
				(int) $instance->flow_version_id, (int) $instance->event_id
			) );
			$definition = $version ? json_decode( (string) $version->definition_json, true ) : null;
			if ( ! is_array( $definition ) || empty( $definition['steps'][1]['key'] )
				|| ( $definition['steps'][1]['type'] ?? '' ) !== 'agreement' ) {
				return new WP_Error( 'ecm_flow_bad_version', 'نسخه فرایند معتبر نیست.' );
			}
			$new_step = (string) $definition['steps'][1]['key'];
			$now = ECM_Utils::now_utc();
			$affected = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET state=%s,current_step_key=%s,lock_version=lock_version+1,updated_at=%s WHERE id=%d AND lock_version=%d AND state=%s",
				'awaiting_agreement', $new_step, $now, $instance_id, $expected_version, 'draft'
			) );
			if ( 1 !== $affected ) {
				return new WP_Error( 'ecm_flow_concurrency', 'درخواست هم‌زمان دیگری ثبت شده است.' );
			}
			$logged = $wpdb->insert( $events, array(
				'registration_id'  => 0,
				'domain_event_id'  => (int) $instance->event_id,
				'flow_instance_id' => $instance_id,
				'actor_user_id'    => get_current_user_id(),
				'correlation_id'   => 'flow:' . (string) $instance->public_id,
				'event_type'       => 'workflow_stage',
				'meta'             => wp_json_encode( array( 'from' => 'draft', 'to' => 'awaiting_agreement', 'revision' => $expected_version + 1 ) ),
				'created_at'       => $now,
			) );
			if ( ! $logged ) {
				return new WP_Error( 'ecm_flow_audit_failed', 'ثبت تاریخچه تغییر وضعیت انجام نشد.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return new WP_Error( 'ecm_flow_commit', 'ثبت نهایی تراکنش موفق نبود.' );
			}
			$committed = true;
			return true;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}
}
