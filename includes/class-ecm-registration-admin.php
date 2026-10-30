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

		if ( 'delete' === $operation ) {
			if ( '1' !== (string) ( $_POST['confirm_delete'] ?? '' ) ) {
				self::redirect( 'confirm_required', 0 );
			}
			$count = self::delete_registrations( $ids );
		} else {
			$count = self::set_test_status( $ids, 'mark_test' === $operation );
		}

		ECM_Diagnostics::record(
			'registration_admin',
			'Registration bulk operation completed',
			array( 'operation' => $operation, 'count' => $count, 'user_id' => get_current_user_id() )
		);
		self::redirect( $operation, $count );
	}

	private static function set_test_status( array $ids, bool $is_test ): int {
		global $wpdb;
		$table = ECM_DB::table( 'registrations' );
		$list  = implode( ',', array_map( 'absint', $ids ) );
		if ( $is_test ) {
			$result = $wpdb->query( "UPDATE {$table} SET is_test=1,dedupe_phone_hash=NULL,updated_at=UTC_TIMESTAMP() WHERE id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// Duplicate checks still use the participant identity when a test flag is
			// removed. Keeping the hash NULL avoids introducing a unique-key conflict
			// when this phone was intentionally used more than once during testing.
			$result = $wpdb->query( "UPDATE {$table} SET is_test=0,updated_at=UTC_TIMESTAMP() WHERE id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( false === $result ) {
			wp_die( 'تغییر وضعیت ثبت‌نام‌ها انجام نشد. دیتابیس را بررسی کنید.' );
		}
		return (int) $result;
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

	private static function redirect( string $operation, int $count ): void {
		$fallback = admin_url( 'admin.php?page=ecm-reports&ecm_view=registrations' );
		$return   = wp_validate_redirect( wp_unslash( $_POST['return_url'] ?? '' ), $fallback );
		$return   = remove_query_arg( array( 'ecm_bulk_result', 'ecm_bulk_count' ), $return );
		wp_safe_redirect( add_query_arg( array( 'ecm_bulk_result' => $operation, 'ecm_bulk_count' => max( 0, $count ) ), $return ) );
		exit;
	}
}
