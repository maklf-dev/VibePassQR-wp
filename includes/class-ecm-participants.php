<?php

defined( 'ABSPATH' ) || exit;

/**
 * Guest identity, card access and recovery helpers.
 */
final class ECM_Participants {
	/**
	 * @param array<int,array{name:string,phone:string}> $guests
	 * @return array<int,object>|WP_Error
	 */
	public static function create_guests( int $registration_id, array $guests, bool $within_transaction = false ): array|WP_Error {
		$registration = ECM_DB::registration( $registration_id );
		$primary      = ECM_DB::primary_participant( $registration_id );
		if ( ! $registration || ! $primary ) {
			return new WP_Error( 'missing_primary', 'هویت ثبت‌نام‌کننده اصلی ساخته نشد.' );
		}
		$event = ECM_Events::registration_event( $registration );
		$settings = ECM_Events::config( $event );
		$allow_duplicate_phone = ! empty( $settings['allow_duplicate_guest_phone'] );
		$limit = $event ? min( 20, max( 0, (int) ( $event->guest_limit ?? 0 ) ) ) : 0;
		$guests = array_slice( $guests, 0, $limit );
		if ( ! $guests ) {
			return array();
		}

		global $wpdb;
		$people       = ECM_DB::table( 'people' );
		$participants = ECM_DB::table( 'participants' );
		$registrations= ECM_DB::table( 'registrations' );
		$created      = array();
		if ( ! $within_transaction && false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'guest_transaction', 'امکان آغاز تراکنش مهمان‌ها نیست.' );
		}
		foreach ( $guests as $index => $guest ) {
			$name  = sanitize_text_field( (string) ( $guest['name'] ?? '' ) );
			$phone = ECM_Utils::normalize_mobile( (string) ( $guest['phone'] ?? '' ) );
			if ( ! $name || ! $phone ) {
				if ( ! $within_transaction ) { $wpdb->query( 'ROLLBACK' ); }
				return new WP_Error( 'invalid_guest', 'نام و شماره موبایل معتبر برای همه مهمان‌ها الزامی است.' );
			}
			$phone_hash = hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
			$person = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$people} WHERE phone_hash=%s LIMIT 1 FOR UPDATE", $phone_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$now = ECM_Utils::now_utc();
			if ( ! $person ) {
				if ( ! $wpdb->insert(
					$people,
					array( 'public_id' => self::unique_public_id( 'people' ), 'full_name' => $name, 'normalized_phone' => $phone, 'phone_hash' => $phone_hash, 'email' => '', 'created_at' => $now, 'updated_at' => $now ),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				) ) {
					$person = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$people} WHERE phone_hash=%s LIMIT 1 FOR UPDATE", $phone_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( ! $person ) {
						if ( ! $within_transaction ) { $wpdb->query( 'ROLLBACK' ); }
						return new WP_Error( 'guest_person_database', 'ذخیره هویت مهمان انجام نشد.' );
					}
				} else {
					$person = (object) array( 'id' => (int) $wpdb->insert_id );
				}
			} else {
				$wpdb->update( $people, array( 'updated_at' => $now ), array( 'id' => (int) $person->id ), array( '%s' ), array( '%d' ) );
			}
			if ( ! $allow_duplicate_phone ) {
				$test_exempt = $wpdb->get_var( $wpdb->prepare( "SELECT p.id FROM {$participants} p INNER JOIN {$registrations} r ON r.id=p.registration_id WHERE p.event_id=%d AND p.person_id=%d AND p.status='active' AND r.is_test=1 LIMIT 1", (int) $registration->event_id, (int) $person->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$already = $test_exempt ? null : $wpdb->get_var( $wpdb->prepare( "SELECT p.id FROM {$participants} p INNER JOIN {$registrations} r ON r.id=p.registration_id WHERE p.event_id=%d AND p.person_id=%d AND p.status='active' AND r.is_test=0 AND (p.registration_id=%d OR r.payment_status IN ('paid','not_required','offline_confirmed','pending')) LIMIT 1", (int) $registration->event_id, (int) $person->id, $registration_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $already ) {
					if ( ! $within_transaction ) { $wpdb->query( 'ROLLBACK' ); }
					return new WP_Error( 'duplicate_guest', (string) ( $settings['duplicate_guest_phone_message'] ?? 'این شماره موبایل قبلاً برای یکی از مهمانان همین رویداد ثبت شده است.' ) );
				}
			}
			$inserted = $wpdb->insert(
				$participants,
				array(
					'public_id'                  => self::unique_public_id( 'participants' ),
					'event_id'                  => (int) $registration->event_id,
					'registration_id'           => $registration_id,
					'person_id'                 => (int) $person->id,
					'display_name'              => $name,
					'participant_type'          => 'guest',
					'invited_by_participant_id' => (int) $primary->id,
					'guest_order'               => $index + 1,
					'access_hash'               => '',
					'status'                    => 'active',
					'created_at'                => $now,
					'updated_at'                => $now,
				),
				array( '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( ! $inserted ) {
				if ( ! $within_transaction ) { $wpdb->query( 'ROLLBACK' ); }
				return new WP_Error( 'guest_participant_database', 'ساخت کارت مهمان انجام نشد.' );
			}
			$created[] = ECM_DB::participant( (int) $wpdb->insert_id );
		}
		if ( ! $within_transaction && false === $wpdb->query( 'COMMIT' ) ) {
			return new WP_Error( 'guest_commit', 'تأیید نهایی اطلاعات مهمان‌ها انجام نشد.' );
		}
		return array_values( array_filter( $created ) );
	}

	public static function recover_guest( string $phone, int $event_id = 0 ): ?object {
		$phone = ECM_Events::normalize_phone( $phone );
		if ( ! $phone ) {
			return null;
		}
		global $wpdb;
		$people       = ECM_DB::table( 'people' );
		$participants = ECM_DB::table( 'participants' );
		$registrations= ECM_DB::table( 'registrations' );
		$where_event  = $event_id ? $wpdb->prepare( ' AND p.event_id=%d', $event_id ) : '';
		$hash         = hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
		$row = $wpdb->get_row(
			$wpdb->prepare(
					"SELECT p.* FROM {$participants} p INNER JOIN {$people} pe ON pe.id=p.person_id INNER JOIN {$registrations} r ON r.id=p.registration_id WHERE pe.phone_hash=%s AND p.participant_type='guest' AND p.status='active' AND r.payment_status IN ('paid','not_required','offline_confirmed'){$where_event} ORDER BY p.id DESC LIMIT 1",
				$hash
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/**
	 * Resolve a completed participant by phone. A phone shared by more than one
	 * participant requires the public tracking code, preventing one guest from
	 * receiving another guest's card.
	 *
	 * @return object|WP_Error|null
	 */
	public static function recover_by_phone( string $phone, int $event_id = 0, string $tracking_code = '' ) {
		$phone = ECM_Events::normalize_phone( $phone );
		if ( ! $phone ) {
			return null;
		}
		global $wpdb;
		$people        = ECM_DB::table( 'people' );
		$participants  = ECM_DB::table( 'participants' );
		$registrations = ECM_DB::table( 'registrations' );
		$where_event   = $event_id ? $wpdb->prepare( ' AND p.event_id=%d', $event_id ) : '';
		$hash          = hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
		$tracking_code = strtoupper( trim( ECM_Utils::normalize_digits( $tracking_code ) ) );
		if ( $tracking_code ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT p.* FROM {$participants} p INNER JOIN {$people} pe ON pe.id=p.person_id INNER JOIN {$registrations} r ON r.id=p.registration_id WHERE pe.phone_hash=%s AND p.public_id=%s AND p.status='active' AND r.payment_status IN ('paid','not_required','offline_confirmed'){$where_event} LIMIT 1",
					$hash,
					$tracking_code
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $row ?: null;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.* FROM {$participants} p INNER JOIN {$people} pe ON pe.id=p.person_id INNER JOIN {$registrations} r ON r.id=p.registration_id WHERE pe.phone_hash=%s AND p.status='active' AND r.payment_status IN ('paid','not_required','offline_confirmed'){$where_event} ORDER BY p.id DESC LIMIT 2",
				$hash
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return null;
		}
		if ( 1 < count( $rows ) ) {
			return new WP_Error( 'ambiguous_recovery', 'این شماره برای چند کارت ثبت شده است؛ کد پیگیری کارت را نیز وارد کنید.' );
		}
		return $rows[0];
	}

	public static function grant_recovered_card( object $participant ): bool {
		if ( 'guest' === (string) $participant->participant_type ) {
			return self::grant_guest_card( $participant );
		}
		if ( 'primary' !== (string) $participant->participant_type ) {
			return false;
		}
		global $wpdb;
		$secret  = wp_generate_password( 40, false, false );
		$updated = $wpdb->update(
			ECM_DB::table( 'registrations' ),
			array( 'access_hash' => wp_hash_password( $secret ), 'updated_at' => ECM_Utils::now_utc() ),
			array( 'id' => (int) $participant->registration_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return false;
		}
		ECM_Utils::set_card_grant( (int) $participant->registration_id, $secret );
		return true;
	}

	public static function grant_guest_card( object $participant ): bool {
		if ( 'guest' !== (string) $participant->participant_type ) {
			return false;
		}
		global $wpdb;
		$secret = wp_generate_password( 40, false, false );
		$updated = $wpdb->update(
			ECM_DB::table( 'participants' ),
			array( 'access_hash' => wp_hash_password( $secret ), 'updated_at' => ECM_Utils::now_utc() ),
			array( 'id' => (int) $participant->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return false;
		}
		ECM_Utils::set_participant_card_grant( (int) $participant->id, $secret );
		return true;
	}

	public static function cleanup_registration( int $registration_id ): void {
		global $wpdb;
		$participants = ECM_DB::table( 'participants' );
		$people       = ECM_DB::table( 'people' );
		$person_ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT person_id FROM {$participants} WHERE registration_id=%d", $registration_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( ECM_DB::table( 'ticket_tokens' ), array( 'registration_id' => $registration_id ), array( '%d' ) );
		$wpdb->delete( $participants, array( 'registration_id' => $registration_id ), array( '%d' ) );
		$wpdb->delete( ECM_DB::table( 'registration_meta' ), array( 'registration_id' => $registration_id ), array( '%d' ) );
		$wpdb->delete( ECM_DB::table( 'registrations' ), array( 'id' => $registration_id ), array( '%d' ) );
		foreach ( array_unique( $person_ids ) as $person_id ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$participants} WHERE person_id=%d", $person_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $used ) {
				$wpdb->delete( $people, array( 'id' => $person_id ), array( '%d' ) );
			}
		}
	}

	private static function unique_public_id( string $table_suffix ): string {
		global $wpdb;
		$table = ECM_DB::table( $table_suffix );
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$id = ECM_Utils::public_id();
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE public_id=%s LIMIT 1", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $id;
			}
		}
		return strtoupper( substr( hash( 'sha256', wp_generate_uuid4() ), 0, 10 ) );
	}
}
