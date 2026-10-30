<?php

defined( 'ABSPATH' ) || exit;

final class ECM_DB {
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'ecm_' . $name;
	}

	public static function registration( int $id ): ?object {
		global $wpdb;
		$table = self::table( 'registrations' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function registration_by_public_id( string $public_id ): ?object {
		global $wpdb;
		$table = self::table( 'registrations' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", $public_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function decode_data( object $registration ): array {
		$data = json_decode( (string) $registration->form_data, true );
		return is_array( $data ) ? $data : array();
	}

	public static function active_session( int $event_id = 0 ): ?object {
		global $wpdb;
		$table = self::table( 'sessions' );
		$now   = ECM_Utils::now_utc();
		$event_id = $event_id ?: ECM_Events::default_event_id();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d AND enabled = 1 AND start_at_utc <= %s AND end_at_utc >= %s ORDER BY start_at_utc ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event_id,
				$now,
				$now
			)
		);
		return $row ?: null;
	}

	/**
	 * Resolves the current attendance window and distinguishes early/late scans.
	 */
	public static function attendance_window( int $event_id = 0 ): array {
		global $wpdb;
		$table = self::table( 'sessions' );
		$now   = ECM_Utils::now_utc();
		$event_id = $event_id ?: ECM_Events::default_event_id();
		$active = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id=%d AND enabled=1 AND start_at_utc<=%s AND end_at_utc>=%s ORDER BY start_at_utc ASC,id ASC LIMIT 1",
				$event_id,
				$now,
				$now
			)
		);
		if ( $active ) {
			return array( 'state' => 'active', 'session' => $active );
		}
		$future = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id=%d AND enabled=1 AND start_at_utc>%s ORDER BY start_at_utc ASC,id ASC LIMIT 1", $event_id, $now ) );
		if ( $future ) {
			return array( 'state' => 'early', 'session' => $future );
		}
		$past = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id=%d AND enabled=1 AND end_at_utc<%s ORDER BY end_at_utc DESC,id DESC LIMIT 1", $event_id, $now ) );
		if ( $past ) {
			return array( 'state' => 'late', 'session' => $past );
		}
		return array( 'state' => 'outside', 'session' => null );
	}

	public static function attendance_count( int $registration_id, int $session_id = 0 ): int {
		global $wpdb;
		$table = self::table( 'attendance' );
		if ( $session_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE registration_id = %d AND session_id = %d", $registration_id, $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE registration_id = %d", $registration_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function participant_attendance_count( int $participant_id, int $session_id = 0 ): int {
		global $wpdb;
		$table = self::table( 'attendance' );
		if ( $session_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE participant_id=%d AND session_id=%d", $participant_id, $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE participant_id=%d", $participant_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function primary_participant( int $registration_id ): ?object {
		global $wpdb;
		$table = self::table( 'participants' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE registration_id=%d AND participant_type='primary' ORDER BY id ASC LIMIT 1", $registration_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: ECM_Events::ensure_registration_entities( $registration_id );
	}

	public static function participant( int $participant_id ): ?object {
		global $wpdb;
		$table = self::table( 'participants' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $participant_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function participant_by_public_id( string $public_id ): ?object {
		global $wpdb;
		$table = self::table( 'participants' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id=%s", $public_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function participant_identity( int $participant_id ): ?object {
		global $wpdb;
		$participants = self::table( 'participants' );
		$people       = self::table( 'people' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT p.*,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name,pe.normalized_phone,pe.email FROM {$participants} p LEFT JOIN {$people} pe ON pe.id=p.person_id WHERE p.id=%d", $participant_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function participants_for_registration( int $registration_id ): array {
		global $wpdb;
		$participants = self::table( 'participants' );
		$people       = self::table( 'people' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name,pe.normalized_phone,pe.email FROM {$participants} p LEFT JOIN {$people} pe ON pe.id=p.person_id WHERE p.registration_id=%d AND p.status='active' ORDER BY CASE WHEN p.participant_type='primary' THEN 0 ELSE 1 END,p.guest_order ASC,p.id ASC",
				$registration_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
