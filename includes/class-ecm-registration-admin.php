<?php

defined( 'ABSPATH' ) || exit;

/**
 * Administrator-only registration maintenance.
 *
 * This class deliberately checks the actual WordPress administrator role
 * instead of an assignable ECM capability. Test registrations stay fully
 * functional, but are excluded from operational reports and duplicate-phone
 * enforcement.
 */
final class ECM_Registration_Admin {
	public static function hooks(): void {
		add_action( 'admin_post_ecm_registration_bulk', array( __CLASS__, 'handle_bulk_action' ) );
	}

	public static function is_site_administrator( ?WP_User $user = null ): bool {
		$user = $user ?: wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		return in_array( 'administrator', (array) $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) );
	}

	public static function handle_bulk_action(): void {
		if ( ! self::is_site_administrator() ) {
			wp_die( 'این عملیات فقط برای مدیر کل وردپرس مجاز است.', 'دسترسی غیرمجاز', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ecm_registration_bulk', 'ecm_registration_bulk_nonce' );

		$operation = sanitize_key( wp_unslash( $_POST['bulk_operation'] ?? '' ) );
		$ids       = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['registration_ids'] ?? array() ) ) ) ) );
		if ( ! $ids || ! in_array( $operation, array( 'mark_test', 'unmark_test', 'delete' ), true ) ) {
			self::redirect( 'invalid', 0 );
		}

		$skipped = 0;
		if ( 'delete' === $operation ) {
			if ( '1' !== (string) ( $_POST['confirm_delete'] ?? '' ) ) {
				self::redirect( 'confirm_required', 0 );
			}
			$count = self::delete_registrations( $ids );
		} else {
			list( $count, $skipped ) = self::set_test_status( $ids, 'mark_test' === $operation );
		}

		ECM_Diagnostics::record(
			'registration_admin',
			'Registration bulk operation completed',
			array( 'operation' => $operation, 'count' => $count, 'skipped' => $skipped, 'user_id' => get_current_user_id() )
		);
		self::redirect( $operation, $count, $skipped );
	}

	private static function set_test_status( array $ids, bool $is_test ): array {
		global $wpdb;
		$table = ECM_DB::table( 'registrations' );
		$workflow = ECM_DB::table( 'workflow' );
		$sms = ECM_DB::table( 'sms' );
		$count = $skipped = 0;
		foreach ( $ids as $id ) {
			$result = ECM_Flow::atomic( static function () use ( $wpdb, $table, $workflow, $sms, $id, $is_test ) {
				$row = ECM_Flow::row( $id, true );
				$registration = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d FOR UPDATE", $id ) );
				if ( ! $registration || (bool) $registration->is_test === $is_test ) { return false; }
				$updates = array( 'is_test' => (int) $is_test, 'updated_at' => ECM_Utils::now_utc() );
				if ( $is_test ) { $updates['dedupe_phone_hash'] = null; }
				if ( $row ) {
					$details = ECM_Flow::decode( $row->details );
					$phone = ECM_Events::normalize_phone( (string) ( $details['form']['mobile'] ?? '' ) );
					$owner = ! $is_test && $phone ? ECM_Flow::by_phone( (int) $row->event_id, $phone ) : null;
					if ( ! $is_test && ( ! $phone || ( $owner && (int) $owner->registration_id !== $id ) ) ) {
						return new WP_Error( 'workflow_phone_conflict', 'شماره پرونده معتبر نیست یا پرونده اصلی دیگری با این شماره وجود دارد.' );
					}
					$details['session_epoch'] = wp_generate_uuid4();
					$next = $is_test ? null : ECM_Flow_Reminders::next( ECM_Flow::settings( (int) $row->event_id ), $row->state, $row->updated_at, $details );
					ECM_Flow::write( $wpdb->update( $workflow, array(
						'phone_hash' => $is_test ? null : ECM_Flow::phone_hash( $phone ),
						'revision' => (int) $row->revision + 1, 'next_reminder' => $next,
						'reminder_no' => 0, 'details' => wp_json_encode( $details, JSON_UNESCAPED_UNICODE ),
					), array( 'registration_id' => $id ) ) );
					if ( $is_test ) {
						ECM_Flow::write( $wpdb->query( $wpdb->prepare( "UPDATE {$sms} SET status='cancelled',error_code='registration_marked_test',next_attempt_at=NULL,updated_at=%s WHERE registration_id=%d AND status IN ('queued','retry_wait','paused')", ECM_Utils::now_utc(), $id ) ) );
					}
				}
				ECM_Flow::write( $wpdb->update( $table, $updates, array( 'id' => $id ) ) );
				ECM_Utils::log( $id, 'registration_test_status_changed', array( 'is_test' => $is_test, 'actor' => get_current_user_id() ) );
				return true;
			} );
			if ( is_wp_error( $result ) ) { ++$skipped; }
			if ( true === $result ) { ++$count; }
		}
		return array( $count, $skipped );
	}

	/** Never allow test tooling to delete a live registration, even with a forged ID. */
	public static function delete_test_registrations( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) { return 0; }
		$table = ECM_DB::table( 'registrations' );
		$id_list = implode( ',', $ids );
		$events = ECM_DB::table( 'events' );
		$allowed = array_map( 'absint', $wpdb->get_col( "SELECT r.id FROM {$table} r WHERE r.is_test=1 AND r.id IN ({$id_list}) AND EXISTS (SELECT 1 FROM {$events} ev WHERE ev.registration_id=r.id AND ev.event_type='admin_test_created')" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $allowed ? self::delete_registrations( $allowed ) : 0;
	}

	private static function delete_registrations( array $ids ): int {
		global $wpdb;
		$ids      = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$id_list  = implode( ',', $ids );
		$tables   = array();
		foreach ( array( 'registrations', 'registration_meta', 'participants', 'people', 'attendance', 'events', 'sms', 'sms_attempts', 'files', 'ticket_tokens', 'links', 'otp' ) as $suffix ) {
			$tables[ $suffix ] = ECM_DB::table( $suffix );
		}
		$existing = array_map( 'absint', $wpdb->get_col( "SELECT id FROM {$tables['registrations']} WHERE id IN ({$id_list})" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $existing ) {
			return 0;
		}
		$id_list   = implode( ',', $existing );
		$person_ids = array_map( 'absint', $wpdb->get_col( "SELECT person_id FROM {$tables['participants']} WHERE registration_id IN ({$id_list})" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sms_ids    = array_map( 'absint', $wpdb->get_col( "SELECT id FROM {$tables['sms']} WHERE registration_id IN ({$id_list})" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$files      = $wpdb->get_results( "SELECT relative_path FROM {$tables['files']} WHERE registration_id IN ({$id_list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->query( 'START TRANSACTION' );
		try {
			ECM_Flow::delete_for_registrations( $existing );
			if ( $sms_ids ) {
				$sms_list = implode( ',', $sms_ids );
				self::query_or_throw( "DELETE FROM {$tables['sms_attempts']} WHERE sms_id IN ({$sms_list})" );
			}
			foreach ( array( 'otp', 'attendance', 'events', 'ticket_tokens', 'links', 'registration_meta', 'files', 'sms', 'participants' ) as $suffix ) {
				self::query_or_throw( "DELETE FROM {$tables[$suffix]} WHERE registration_id IN ({$id_list})" );
			}
			self::query_or_throw( "DELETE FROM {$tables['registrations']} WHERE id IN ({$id_list})" );
			if ( $person_ids ) {
				$person_list = implode( ',', array_values( array_unique( $person_ids ) ) );
				self::query_or_throw( "DELETE pe FROM {$tables['people']} pe LEFT JOIN {$tables['participants']} p ON p.person_id=pe.id WHERE pe.id IN ({$person_list}) AND p.id IS NULL" );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'commit_failed' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			ECM_Diagnostics::record( 'registration_admin', 'Registration delete rolled back', array( 'error_code' => sanitize_key( $error->getMessage() ) ) );
			wp_die( 'حذف کامل نشد و دیتابیس به وضعیت قبل بازگردانده شد.' );
		}

		foreach ( $files as $file ) {
			$path = self::safe_file_path( (string) $file->relative_path );
			if ( $path ) {
				wp_delete_file( $path );
			}
		}
		return count( $existing );
	}

	private static function query_or_throw( string $sql ): void {
		global $wpdb;
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			throw new RuntimeException( 'database_delete_failed' );
		}
	}

	private static function safe_file_path( string $relative_path ): string {
		if ( ! preg_match( '/^[a-z0-9_-]+\/[a-f0-9]{32}\.ecm$/', $relative_path ) ) {
			return '';
		}
		$base = realpath( wp_upload_dir()['basedir'] );
		$path = $base ? realpath( $base . '/' . $relative_path ) : false;
		return $base && $path && str_starts_with( $path, trailingslashit( $base ) ) ? $path : '';
	}

	private static function redirect( string $operation, int $count, int $skipped = 0 ): void {
		$fallback = admin_url( 'admin.php?page=ecm-reports&ecm_view=registrations' );
		$return   = wp_validate_redirect( wp_unslash( $_POST['return_url'] ?? '' ), $fallback );
		$return   = remove_query_arg( array( 'ecm_bulk_result', 'ecm_bulk_count', 'ecm_bulk_skipped' ), $return );
		wp_safe_redirect( add_query_arg( array( 'ecm_bulk_result' => $operation, 'ecm_bulk_count' => max( 0, $count ), 'ecm_bulk_skipped' => $skipped ), $return ) );
		exit;
	}
}
