<?php

defined( 'ABSPATH' ) || exit;

/**
 * Stage-only registration bridge. All canonical records, guest cards and token
 * rows are written in ONE transaction; legacy form and recovery are unchanged.
 * The workflow instance's linked_registration_id is the idempotency marker.
 */
final class ECM_Workflow_Issuance {
	public static function approve( int $instance_id, int $expected_revision ): int|WP_Error {
		if ( ! ECM_Workflow_Review::issuance_allowed() || $instance_id < 1 ) {
			return new WP_Error( 'ecm_flow_issuance_disabled', 'صدور واقعی کارت در این محیط فعال نشده است.' );
		}
		global $wpdb;
		$instances = ECM_DB::table( 'flow_instances' );
		$files = ECM_DB::table( 'flow_files' );
		$registrations = ECM_DB::table( 'registrations' );
		$participants = ECM_DB::table( 'participants' );
		$people = ECM_DB::table( 'people' );
		// `ensure_registration_entities()` can also update old attendance rows.
		// Do not promise an atomic approval when any table touched by the bridge
		// is MyISAM or missing. This check performs reads only.
		if ( ! self::transactional_tables_ready() ) {
			ECM_Diagnostics::record( 'workflow', 'Approval blocked: transactional storage not verified', array( 'instance_id' => $instance_id ) );
			return new WP_Error( 'ecm_flow_nontransactional_storage', 'تأیید متوقف شد؛ تراکنشی بودن تمام جدول‌های لازم تأیید نشد. با مدیر سرور بررسی کنید.' );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ecm_flow_transaction', 'تراکنش تأیید آغاز نشد.' );
		}
		$committed = false;
		$registration_id = 0;
		$guest_participants = array();
		try {
			$instance = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$instances} WHERE id=%d FOR UPDATE", $instance_id ) );
			if ( ! $instance || 'under_review' !== $instance->state || ! ECM_Workflow::can_transition( (string) $instance->state, 'approved' )
				|| (int) $instance->lock_version !== $expected_revision || (int) $instance->linked_registration_id !== 0 ) {
				return new WP_Error( 'ecm_flow_stale_state', 'این درخواست قبلاً بررسی شده یا وضعیت آن تغییر کرده است.' );
			}
			$event = ECM_Events::event( (int) $instance->event_id );
			if ( ! $event || 'custom_workflow' !== ECM_Workflow::mode_for_event( $event )
				|| 'published' !== (string) $event->status || ! (int) $event->enabled ) {
				return new WP_Error( 'ecm_flow_event_disabled', 'رویداد برای صدور کارت معتبر نیست.' );
			}
			$version = $wpdb->get_row( $wpdb->prepare( 'SELECT definition_json FROM ' . ECM_DB::table( 'flow_versions' ) . ' WHERE id=%d AND event_id=%d', $instance->flow_version_id, $instance->event_id ) );
			$definition = $version ? json_decode( (string) $version->definition_json, true ) : null;
			if ( ! is_array( $definition ) || array_column( (array) ( $definition['steps'] ?? array() ), 'type' ) !== array( 'form', 'agreement', 'receipt', 'result' ) ) {
				return new WP_Error( 'ecm_flow_invalid_version', 'نسخه فرایند برای صدور کارت پشتیبانی نمی‌شود.' );
			}
			$consent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ECM_DB::table( 'events' ) . " WHERE flow_instance_id=%d AND event_type='workflow_agreement_accepted'", $instance_id ) );
			if ( $consent < 1 ) {
				return new WP_Error( 'ecm_flow_agreement_missing', 'سابقه پذیرش میثاق‌نامه ثبت نشده است.' );
			}
			$file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$files} WHERE instance_id=%d ORDER BY version_no DESC,id DESC LIMIT 1 FOR UPDATE", $instance_id ) );
			if ( ! $file || 'pending' !== $file->status || ! self::receipt_valid( $file ) ) {
				return new WP_Error( 'ecm_flow_receipt_missing', 'رسید معتبر و آماده بررسی وجود ندارد.' );
			}
			$raw = json_decode( (string) $instance->data_json, true );
			if ( ! is_array( $raw ) || count( $raw ) > 50 ) {
				return new WP_Error( 'ecm_flow_invalid_form', 'اطلاعات فرم درخواست معتبر نیست.' );
			}
			$settings = ECM_Events::config( $event );
			$schema_hash = hash( 'sha256', wp_json_encode( (array) ( $settings['fields'] ?? array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			if ( empty( $raw['__schema_hash'] ) || ! hash_equals( $schema_hash, (string) $raw['__schema_hash'] ) ) {
				return new WP_Error( 'ecm_flow_form_schema_changed', 'فیلدهای رویداد از زمان شروع درخواست تغییر کرده‌اند؛ قبل از صدور کارت باید فرم بررسی و مجدداً تطبیق داده شود.' );
			}
			unset( $raw['__schema_hash'] );

			$sms_key = sanitize_key( (string) ( $settings['sms_field'] ?? 'mobile' ) );
			$mobile = ECM_Utils::normalize_mobile( (string) ( $raw[ $sms_key ] ?? '' ) );
			$identity_hash = $mobile ? ECM_Workflow::identity_digest( (int) $event->id, $mobile ) : null;
			if ( ! $mobile || is_wp_error( $identity_hash ) || ! hash_equals( (string) $instance->identifier_digest, (string) $identity_hash ) ) {
				return new WP_Error( 'ecm_flow_identity_mismatch', 'شماره موبایل درخواست با شناسه ذخیره‌شده مطابقت ندارد.' );
			}
			$guests = array();
			if ( isset( $raw['__guests'] ) ) {
				$guests = json_decode( (string) $raw['__guests'], true );
				if ( ! is_array( $guests ) || count( $guests ) > min( 20, max( 0, (int) $event->guest_limit ) ) ) {
					return new WP_Error( 'ecm_flow_guests_invalid', 'اطلاعات یا تعداد مهمان‌ها معتبر نیست.' );
				}
			}
			unset( $raw['__guests'] );
			$data = array();
			$field_types = array();
			foreach ( (array) ( $settings['fields'] ?? array() ) as $field ) {
				$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
				$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
				if ( ! $key || 'html' === $type ) { continue; }
				$field_types[ $key ] = $type;
				if ( 'guest' === $type ) {
					$data[ $key ] = array();
					foreach ( $guests as $g ) {
						if ( ! is_array( $g ) || (string) ( $g['key'] ?? '' ) !== $key ) { continue; }
						$name = sanitize_text_field( (string) ( $g['name'] ?? '' ) );
						$phone = ECM_Utils::normalize_mobile( (string) ( $g['phone'] ?? '' ) );
						if ( ! $name || ! $phone ) { return new WP_Error( 'ecm_flow_guest_invalid', 'اطلاعات مهمان معتبر نیست.' ); }
						$data[$key][] = array( 'name' => $name, 'phone' => $phone );
					}
				} else {
					$value = isset( $raw[$key] ) && is_scalar( $raw[$key] ) ? substr( sanitize_text_field( (string) $raw[$key] ), 0, 2048 ) : '';
					if ( 'checkbox' === $type ) {
						$selected = '' === $value ? array() : array_map( 'trim', explode( '،', $value ) );
						if ( array_diff( $selected, (array) ( $field['options'] ?? array() ) ) ) {
							return new WP_Error( 'ecm_flow_invalid_choice', 'مقدار گزینه چندانتخابی نامعتبر است.' );
						}
						$data[ $key ] = array_values( array_filter( $selected, static fn( $item ) => '' !== $item ) );
					} else {
						$data[ $key ] = $value;
					}
				}

			}
			$phone_hash = hash_hmac( 'sha256', $mobile, wp_salt( 'auth' ) );
			$dedupe_hash = null;
			if ( ! empty( $settings['prevent_duplicate_phone'] ) ) {
				$duplicate = $wpdb->get_var( $wpdb->prepare(
					"SELECT r.id FROM {$registrations} r LEFT JOIN {$participants} p ON p.registration_id=r.id AND p.participant_type='primary' LEFT JOIN {$people} pe ON pe.id=p.person_id WHERE r.event_id=%d AND r.is_test=0 AND (r.dedupe_phone_hash=%s OR pe.phone_hash=%s) AND r.payment_status IN ('paid','not_required','offline_confirmed','pending') LIMIT 1",
					(int) $event->id, $phone_hash, $phone_hash
				) );
				if ( $duplicate ) { return new WP_Error( 'ecm_flow_duplicate_registration', 'این شماره موبایل قبلاً در رویداد ثبت شده است.' ); }
				$dedupe_hash = $phone_hash;
			}
			$secret = wp_generate_password( 40, false, false );
			$now = ECM_Utils::now_utc();
			$amount = (string) ( $settings['amount'] ?? '0' );
			$rate = (string) ( $settings['rial_rate'] ?? '1' );
			$inserted = $wpdb->insert( $registrations, array(
				'public_id' => ECM_Utils::public_id(),
				'event_id' => (int) $event->id,
				'ticket_template_id' => ECM_Tickets::current_template_id( $event ),
				'dedupe_phone_hash' => $dedupe_hash,
				'form_data' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
				'amount_original' => ECM_Utils::decimal( $amount ),
				'currency_label' => sanitize_text_field( (string) ( $settings['currency_label'] ?? 'ریال' ) ),
				'exchange_rate' => ECM_Utils::decimal( $rate ),
				'amount_rial' => ECM_Utils::rial_amount( $amount, $rate ),
				'payment_status' => 'offline_confirmed',
				'payment_reference' => 'workflow:' . $instance_id,
				'access_hash' => wp_hash_password( $secret ),
				'created_at' => $now,
				'updated_at' => $now,
			) );
			if ( ! $inserted ) {
				return new WP_Error( 'ecm_flow_registration_insert', 'ثبت نهایی ناموفق بود؛ احتمال تکراری‌بودن شماره موبایل را بررسی کنید.' );
			}
			$registration_id = (int) $wpdb->insert_id;
			$primary = ECM_Events::ensure_registration_entities( $registration_id, $data );
			if ( ! $primary || (int) $primary->registration_id !== $registration_id ) {
				return new WP_Error( 'ecm_flow_primary_failed', 'ثبت هویت اصلی کامل نشد.' );
			}
			$guest_fields = array_keys( array_filter( $field_types, static fn( string $type ): bool => 'guest' === $type ) );
			$guest_clean = array();
			foreach ( $guests as $guest ) {
				if ( ! is_array( $guest ) || ! in_array( (string) ( $guest['key'] ?? '' ), $guest_fields, true ) ) { return new WP_Error( 'ecm_flow_guest_invalid', 'فیلد مهمان معتبر نیست.' ); }
				$name = substr( sanitize_text_field( (string) ( $guest['name'] ?? '' ) ), 0, 190 );
				$phone = ECM_Utils::normalize_mobile( (string) ( $guest['phone'] ?? '' ) );
				if ( ! $name || ! $phone ) { return new WP_Error( 'ecm_flow_guest_invalid', 'نام یا شماره مهمان نامعتبر است.' ); }
				$guest_clean[] = array( 'name' => $name, 'phone' => $phone );
			}
			$guest_result = ECM_Participants::create_guests( $registration_id, $guest_clean, true );
			if ( is_wp_error( $guest_result ) ) { return $guest_result; }
			$guest_participants = $guest_result;
			// Index recovery fields in the same transaction, matching Classic hashes.
			foreach ( $data as $key => $value ) {
				$normalized = trim( ECM_Utils::normalize_digits( ECM_Utils::field_value( $data, $key ) ) );
				$normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $normalized ) : strtolower( $normalized );
				$normalized = preg_replace( '/\s+/u', ' ', $normalized );
				if ( 'tel' === ( $field_types[$key] ?? '' ) ) { $normalized = preg_replace( '/[^0-9+]/', '', $normalized ); }
				if ( ! $wpdb->insert( ECM_DB::table( 'registration_meta' ), array(
					'registration_id' => $registration_id, 'field_key' => $key,
					'field_hash' => hash_hmac( 'sha256', $normalized, wp_salt( 'auth' ) ),
				) ) ) { return new WP_Error( 'ecm_flow_field_index', 'ثبت ایندکس فیلدها موفق نبود.' ); }
			}
			// Ensure usable QR tokens before committing an approved workflow.
			foreach ( array_merge( array( $primary ), $guest_participants ) as $participant ) {
				if ( ! $participant || ! ECM_Tickets::card_url( $participant ) || ! ECM_Tickets::attendance_url( $participant ) ) {
					return new WP_Error( 'ecm_flow_token_failed', 'ایجاد توکن‌های کارت و حضور ممکن نشد.' );
				}
			}
			if ( false === $wpdb->update( $files, array( 'status' => 'approved' ), array( 'id' => (int) $file->id ) ) ) {
				return new WP_Error( 'ecm_flow_receipt_status', 'ثبت نتیجه بررسی فایل ناموفق بود.' );
			}
			$updated = $wpdb->query( $wpdb->prepare(
					"UPDATE {$instances} SET state='approved',linked_registration_id=%d,lock_version=lock_version+1,updated_at=%s WHERE id=%d AND lock_version=%d AND state='under_review' AND linked_registration_id IS NULL",
					$registration_id, $now, $instance_id, $expected_revision
				) );
			if ( 1 !== $updated ) { return new WP_Error( 'ecm_flow_concurrency', 'درخواست هم‌زمان دیگری وجود دارد.' ); }
			if ( ! $wpdb->insert( ECM_DB::table( 'events' ), array(
				'registration_id' => $registration_id, 'domain_event_id' => (int) $event->id,
				'flow_instance_id' => $instance_id, 'actor_user_id' => get_current_user_id(),
				'correlation_id' => 'flow:' . $instance->public_id,
				'event_type' => 'workflow_approved',
				'meta' => wp_json_encode( array( 'from' => 'under_review', 'to' => 'approved', 'linked_registration_id' => $registration_id, 'receipt_sha256' => $file->sha256, 'revision' => $expected_revision + 1 ), JSON_UNESCAPED_UNICODE ),
				'created_at' => $now,
			) ) ) { return new WP_Error( 'ecm_flow_audit_failed', 'ثبت سابقه تأیید موفق نبود.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { return new WP_Error( 'ecm_flow_commit', 'تثبیت تراکنش صدور کارت ناموفق بود.' ); }
			$committed = true;
		} finally {
			if ( ! $committed ) { $wpdb->query( 'ROLLBACK' ); }
		}
		// Side effects run *after* the transaction, only on first approval.
		// No live SMS in alpha staging without a second explicit server-side flag.
		try {
			ECM_Utils::log( $registration_id, 'registration_created', array( 'source' => 'workflow_review', 'workflow_instance_id' => $instance_id, 'guest_count' => count( $guest_participants ) ) );
			ECM_Utils::log( $registration_id, 'registration_completed', array( 'source' => 'workflow_review', 'workflow_instance_id' => $instance_id, 'payment_status' => 'offline_confirmed' ) );
			if ( defined( 'ECM_WORKFLOW_STAGING_SMS' ) && true === ECM_WORKFLOW_STAGING_SMS ) {
				$registration = ECM_DB::registration( $registration_id );
				if ( $registration ) { do_action( 'ecm_registration_completed', $registration_id, $registration ); }
			}
		} catch ( Throwable $error ) {
			ECM_Diagnostics::record( 'workflow', 'Post-commit notification or audit callback failed', array( 'registration_id' => $registration_id, 'instance_id' => $instance_id, 'error_type' => get_class( $error ) ) );
		}
		return $registration_id;
	}

	/** Verify every table that a final approval may insert or update, including audit and legacy attendance. */
	private static function transactional_tables_ready(): bool {
		global $wpdb;
		$names = array_map( static fn( string $suffix ): string => ECM_DB::table( $suffix ), array(
			'flow_instances', 'flow_files', 'registrations', 'people', 'participants',
			'registration_meta', 'ticket_tokens', 'attendance', 'events',
		) );
		$holders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$found = $wpdb->get_results( $wpdb->prepare(
			"SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$holders})",
			$names
		) );
		if ( ! is_array( $found ) || count( $found ) !== count( $names ) ) { return false; }
		$remaining = array_fill_keys( $names, true );
		foreach ( $found as $table ) {
			$name = (string) ( $table->TABLE_NAME ?? '' );
			if ( ! isset( $remaining[ $name ] ) || 'innodb' !== strtolower( (string) ( $table->ENGINE ?? '' ) ) ) {
				return false;
			}
			unset( $remaining[ $name ] );
		}
		return ! $remaining;
	}

	private static function receipt_valid( object $file ): bool {
		$root = ECM_Workflow_Receipts::private_root();
		if ( is_wp_error( $root ) || ! preg_match( '/^[a-f0-9]{48}\.(?:jpg|png|webp)$/D', (string) $file->file_reference ) ) { return false; }
		$path = $root . DIRECTORY_SEPARATOR . $file->file_reference;
		$real = realpath( $path );
		return ! is_link( $path ) && (bool) $real && str_starts_with( $real, $root . DIRECTORY_SEPARATOR )
			&& is_file( $real ) && (int) filesize( $real ) === (int) $file->size_bytes
			&& in_array( (string) $file->mime_type, array( 'image/jpeg', 'image/png', 'image/webp' ), true )
			&& hash_equals( (string) $file->sha256, hash_file( 'sha256', $real ) );
	}
}
