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
		// Only validated scalar values are accepted; repeatable guest rows are sanitized
		// and encoded by the admin-only preview form before reaching this API.
		if ( count( $validated_fields ) > 50 ) {
			return new WP_Error( 'ecm_flow_data_too_large', 'تعداد فیلدها بیش از حد مجاز است.' );
		}
		$data = array();
		foreach ( $validated_fields as $key => $value ) {
			if ( ! is_string( $key ) || ( '__guests' !== $key && ! preg_match( '/^[a-z][a-z0-9_]{0,79}$/D', $key ) )
				|| ! is_scalar( $value ) ) {
				return new WP_Error( 'ecm_flow_invalid_data', 'داده فرم در این مرحله معتبر نیست.' );
			}
			if ( '__guests' === $key ) {
				$decoded_guests = json_decode( (string) $value, true );
				if ( ! is_array( $decoded_guests ) || count( $decoded_guests ) > 20 ) {
					return new WP_Error( 'ecm_flow_invalid_guests', 'اطلاعات مهمان‌ها معتبر نیست.' );
				}
				$data[ $key ] = substr( (string) $value, 0, 16000 );
			} else {
				$data[ $key ] = substr( sanitize_textarea_field( (string) $value ), 0, 2048 );
			}
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
	/** Locked, audited acceptance of the exact immutable agreement version. */
	public static function accept_agreement( int $instance_id, int $revision ): bool|WP_Error {
		if ( ! self::preview_allowed() ) {
			return new WP_Error( 'ecm_flow_forbidden', 'این عملیات تنها در پیش‌نمایش مدیریت مجاز است.' );
		}
		global $wpdb;
		$instances = ECM_DB::table( 'flow_instances' );
		$audit = ECM_DB::table( 'events' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ecm_flow_transaction', 'امکان آغاز تراکنش وجود ندارد.' );
		}
		$committed = false;
		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$instances} WHERE id=%d FOR UPDATE", $instance_id ) );
			if ( ! $row || (int) $row->lock_version !== $revision || 'awaiting_agreement' !== $row->state ) {
				return new WP_Error( 'ecm_flow_stale_state', 'وضعیت یا نسخه درخواست تغییر کرده است.' );
			}
			$version = $wpdb->get_row( $wpdb->prepare( 'SELECT definition_json FROM ' . ECM_DB::table( 'flow_versions' ) . ' WHERE id=%d AND event_id=%d', (int) $row->flow_version_id, (int) $row->event_id ) );
			$definition = $version ? json_decode( (string) $version->definition_json, true ) : null;
			$agreement = $definition['steps'][1] ?? array();
			$receipt = $definition['steps'][2] ?? array();
			if ( ( $agreement['key'] ?? '' ) !== $row->current_step_key || ( $agreement['type'] ?? '' ) !== 'agreement' || ( $receipt['type'] ?? '' ) !== 'receipt' ) {
				return new WP_Error( 'ecm_flow_invalid_step', 'تعریف مراحل با وضعیت درخواست هماهنگ نیست.' );
			}
			$hash = hash( 'sha256', wp_json_encode( array( $agreement['html'] ?? '', $agreement['consent_label'] ?? '', (int) $row->flow_version_id ), JSON_UNESCAPED_UNICODE ) );
			$now = ECM_Utils::now_utc();
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$instances} SET state='awaiting_receipt',current_step_key=%s,lock_version=lock_version+1,updated_at=%s WHERE id=%d AND lock_version=%d AND state='awaiting_agreement'", $receipt['key'], $now, $instance_id, $revision ) );
			if ( 1 !== $updated ) {
				return new WP_Error( 'ecm_flow_concurrency', 'درخواست هم‌زمان دیگری دریافت شده است.' );
			}
			if ( ! $wpdb->insert( $audit, array( 'registration_id'=>0,'domain_event_id'=>(int) $row->event_id,'flow_instance_id'=>$instance_id,'actor_user_id'=>get_current_user_id(),'correlation_id'=>'flow:' . $row->public_id,'event_type'=>'workflow_agreement_accepted','meta'=>wp_json_encode( array( 'from'=>'awaiting_agreement','to'=>'awaiting_receipt','agreement_hash'=>$hash,'flow_version_id'=>(int) $row->flow_version_id,'revision'=>$revision+1 ) ),'created_at'=>$now ) ) ) {
				return new WP_Error( 'ecm_flow_audit_failed', 'ثبت سابقه پذیرش سند موفق نبود.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return new WP_Error( 'ecm_flow_commit', 'تثبیت تراکنش ناموفق بود.' );
			}
			$committed = true;
			return true;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	/** One private receipt + review queue state; no registration/card/SMS issuance. */
	public static function submit_receipt( int $instance_id, int $revision, array $file ): bool|WP_Error {
		if ( ! self::preview_allowed() ) {
			return new WP_Error( 'ecm_flow_forbidden', 'این عملیات تنها در پیش‌نمایش مدیریت مجاز است.' );
		}
		$validated = ECM_Workflow_Receipts::validate_upload( $file );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$root = ECM_Workflow_Receipts::private_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		global $wpdb;
		$instances = ECM_DB::table( 'flow_instances' );
		$files = ECM_DB::table( 'flow_files' );
		$audit = ECM_DB::table( 'events' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ecm_flow_transaction', 'امکان آغاز تراکنش وجود ندارد.' );
		}
		$committed = false;
		$moved = null;
		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$instances} WHERE id=%d FOR UPDATE", $instance_id ) );
			if ( ! $row || (int) $row->lock_version !== $revision || 'awaiting_receipt' !== $row->state ) {
				return new WP_Error( 'ecm_flow_stale_state', 'درخواست در وضعیت بارگذاری رسید نیست.' );
			}
			$version = $wpdb->get_row( $wpdb->prepare( 'SELECT definition_json FROM ' . ECM_DB::table( 'flow_versions' ) . ' WHERE id=%d AND event_id=%d', (int) $row->flow_version_id, (int) $row->event_id ) );
			$definition = $version ? json_decode( (string) $version->definition_json, true ) : null;
			if ( ( $definition['steps'][2]['key'] ?? '' ) !== $row->current_step_key || ( $definition['steps'][2]['type'] ?? '' ) !== 'receipt' || ( $definition['steps'][3]['type'] ?? '' ) !== 'result' ) {
				return new WP_Error( 'ecm_flow_invalid_step', 'فرایند رسید معتبر نیست.' );
			}
			$moved = ECM_Workflow_Receipts::move_private( $validated );
			if ( is_wp_error( $moved ) ) {
				return $moved;
			}
			$now = ECM_Utils::now_utc();
			$prev = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(version_no),0) FROM {$files} WHERE instance_id=%d", $instance_id ) );
			if ( ! $wpdb->insert( $files, array( 'instance_id'=>$instance_id, 'file_reference'=>$moved['reference'], 'mime_type'=>$validated['mime'], 'sha256'=>$validated['sha256'], 'size_bytes'=>$validated['size'], 'version_no'=>$prev+1, 'status'=>'pending', 'uploaded_at'=>$now ) ) ) {
				return new WP_Error( 'ecm_flow_receipt_database', 'ثبت مشخصات رسید در دیتابیس موفق نبود.' );
			}
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$instances} SET state='under_review',current_step_key=%s,lock_version=lock_version+1,updated_at=%s WHERE id=%d AND lock_version=%d AND state='awaiting_receipt'", $definition['steps'][3]['key'], $now, $instance_id, $revision ) );
			if ( 1 !== $updated ) {
				return new WP_Error( 'ecm_flow_concurrency', 'درخواست هم‌زمان دیگری در حال بررسی است.' );
			}
			if ( ! $wpdb->insert( $audit, array( 'registration_id'=>0,'domain_event_id'=>(int) $row->event_id,'flow_instance_id'=>$instance_id,'actor_user_id'=>get_current_user_id(),'correlation_id'=>'flow:' . $row->public_id,'event_type'=>'workflow_receipt_uploaded','meta'=>wp_json_encode( array( 'from'=>'awaiting_receipt','to'=>'under_review','mime'=>$validated['mime'],'size_bytes'=>$validated['size'],'sha256'=>$validated['sha256'],'revision'=>$revision+1 ) ),'created_at'=>$now ) ) ) {
				return new WP_Error( 'ecm_flow_audit_failed', 'ثبت سابقه بارگذاری رسید ممکن نشد.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return new WP_Error( 'ecm_flow_commit', 'تثبیت تراکنش موفق نبود.' );
			}
			$committed = true;
			return true;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
				if ( is_array( $moved ) && ! empty( $moved['path'] ) && is_file( $moved['path'] ) ) {
					@unlink( $moved['path'] );
				}
			}
		}
	}

	private static function preview_allowed(): bool {
		return ECM_Workflow::enabled() && current_user_can( 'manage_ecm_events' );
	}

}

