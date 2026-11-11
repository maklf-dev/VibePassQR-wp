<?php

defined( 'ABSPATH' ) || exit;

/** Secure, single-use OTP recovery without a third-party login plugin. */
final class ECM_OTP {
	private const ACTION_NONCE = 'ecm_recovery_otp';
	private static string $activity_stage = 'unknown';
	private static int $activity_event_id = 0;

	public static function hooks(): void {
		foreach ( array( 'request', 'resend', 'verify' ) as $action ) {
			add_action( 'wp_ajax_nopriv_ecm_otp_' . $action, array( __CLASS__, 'ajax_' . $action ) );
			add_action( 'wp_ajax_ecm_otp_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		add_action( 'ecm_sms_sweep', array( __CLASS__, 'cleanup' ), 20 );
	}

	public static function nonce(): string {
		return wp_create_nonce( self::ACTION_NONCE );
	}

	public static function ajax_request(): void {
		self::$activity_stage = 'request';
		self::guard();
		$event = self::posted_event();
		self::$activity_event_id = (int) $event->id;
		$config = ECM_Events::config( $event );
		if ( empty( $config['recovery_otp_enabled'] ) ) {
			self::error( 'disabled', 'بازیابی پیامکی برای این رویداد فعال نیست.', 400 );
		}
		$identifier = sanitize_text_field( wp_unslash( $_POST['identifier'] ?? '' ) );
		if ( '' === trim( $identifier ) ) {
			self::error( 'identifier_required', 'شماره موبایل یا کد پیگیری را وارد کنید.', 400 );
		}
		if ( ! self::rate_limit( 'request-browser-' . (int) $event->id, min( 10, max( 1, absint( $config['recovery_otp_max_sends'] ?? 5 ) ) ), 30 * MINUTE_IN_SECONDS ) ) {
			self::error( 'rate_limited', (string) $config['recovery_otp_rate_message'], 429 );
		}
		$resolved = self::resolve_identifier( $identifier, (int) $event->id );
		$recipient = (string) ( $resolved['recipient'] ?? '' );
		if ( ! $recipient ) {
			self::error(
				'identifier_not_found',
				'شماره در این رویداد ثبت نشده است.',
				404,
				array(
					'registration_url'   => esc_url_raw( ECM_Events::registration_url( $event ) ),
					'registration_label' => 'بازگشت به صفحه ثبت‌نام',
				)
			);
		}
		if ( $recipient && ! self::rate_limit( 'request-phone-' . hash_hmac( 'sha256', $recipient, wp_salt( 'nonce' ) ), min( 10, max( 1, absint( $config['recovery_otp_max_sends'] ?? 5 ) ) ), HOUR_IN_SECONDS ) ) {
			self::error( 'rate_limited', (string) $config['recovery_otp_rate_message'], 429 );
		}
		$challenge = bin2hex( random_bytes( 24 ) );
		$code = (string) random_int( 100000, 999999 );
		$now = time();
		$expires = min( 900, max( 120, absint( $config['recovery_otp_expiry'] ?? 300 ) ) );
		$resend = min( $expires - 30, max( 30, absint( $config['recovery_otp_resend'] ?? 120 ) ) );
		global $wpdb;
		$inserted = $wpdb->insert(
			ECM_DB::table( 'otp' ),
			array(
				'challenge_hash' => hash( 'sha256', $challenge ),
				'event_id' => (int) $event->id,
				'registration_id' => absint( $resolved['registration_id'] ?? 0 ),
				'participant_id' => absint( $resolved['participant_id'] ?? 0 ),
				'phone_hash' => $recipient ? hash_hmac( 'sha256', $recipient, wp_salt( 'auth' ) ) : hash( 'sha256', $challenge ),
				'recipient' => $recipient,
				'otp_hash' => wp_hash_password( $code ),
				'browser_hash' => self::browser_hash(),
				'status' => 'active',
				'attempts' => 0,
				'send_count' => 1,
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $expires ),
				'resend_at' => gmdate( 'Y-m-d H:i:s', $now + $resend ),
				'created_at' => ECM_Utils::now_utc(),
				'updated_at' => ECM_Utils::now_utc(),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			self::error( 'storage_error', 'ساخت درخواست امن بازیابی انجام نشد؛ دوباره تلاش کنید.', 500 );
		}
		if ( $recipient ) {
			$result = ECM_SMS::send_secure_pattern(
				(string) ( $config['recovery_otp_pattern_profile'] ?? 'recovery_otp' ),
				$recipient,
				array( 'code' => $code ),
				array( 'event_id' => (int) $event->id, 'registration_id' => absint( $resolved['registration_id'] ?? 0 ), 'participant_id' => absint( $resolved['participant_id'] ?? 0 ) )
			);
			if ( is_wp_error( $result ) ) {
				$wpdb->update( ECM_DB::table( 'otp' ), array( 'status' => 'send_failed', 'otp_hash' => '', 'updated_at' => ECM_Utils::now_utc() ), array( 'challenge_hash' => hash( 'sha256', $challenge ) ), array( '%s', '%s', '%s' ), array( '%s' ) );
				ECM_Diagnostics::record( 'sms', 'Recovery OTP send failed', array( 'event_id' => (int) $event->id, 'error_code' => sanitize_key( $result->get_error_code() ) ) );
				self::error( 'send_failed', (string) $config['recovery_otp_send_failed_message'], 503 );
			}
		}
		self::activity_success();
		wp_send_json_success(
			array(
				'challenge' => $challenge,
				'expires_at' => $now + $expires,
				'resend_at' => $now + $resend,
				'message' => (string) $config['recovery_otp_sent_message'],
			)
		);
	}

	public static function ajax_resend(): void {
		self::$activity_stage = 'resend';
		self::guard();
		$row = self::challenge_row();
		$event = $row ? ECM_Events::event( (int) $row->event_id ) : null;
		self::$activity_event_id = $event ? (int) $event->id : 0;
		$config = ECM_Events::config( $event );
		if ( $row && $event && ! ECM_Events::allows( $event, 'recovery' ) ) {
			self::error( 'event_unavailable', 'بازیابی این رویداد غیرفعال است.', 403 );
		}
		if ( ! $row || ! $event || 'active' !== (string) $row->status || ! hash_equals( (string) $row->browser_hash, self::browser_hash() ) ) {
			self::error( 'invalid_challenge', (string) $config['recovery_otp_expired_message'], 400 );
		}
		$now = time();
		if ( strtotime( (string) $row->expires_at . ' UTC' ) <= $now ) {
			self::expire( (int) $row->id );
			self::error( 'expired', (string) $config['recovery_otp_expired_message'], 410 );
		}
		if ( strtotime( (string) $row->resend_at . ' UTC' ) > $now ) {
			self::error( 'resend_wait', 'زمان درخواست مجدد هنوز نرسیده است.', 429, array( 'resend_at' => strtotime( (string) $row->resend_at . ' UTC' ) ) );
		}
		$max_sends = min( 10, max( 1, absint( $config['recovery_otp_max_sends'] ?? 5 ) ) );
		if ( (int) $row->send_count >= $max_sends || ! self::rate_limit( 'resend-' . (int) $row->event_id, $max_sends, HOUR_IN_SECONDS ) ) {
			self::error( 'rate_limited', (string) $config['recovery_otp_rate_message'], 429 );
		}
		global $wpdb;
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . ECM_DB::table( 'otp' ) . " SET status='resending',updated_at=%s WHERE id=%d AND status='active' AND resend_at<=%s", ECM_Utils::now_utc(), (int) $row->id, ECM_Utils::now_utc() ) );
		if ( 1 !== $claimed ) {
			self::error( 'resend_busy', 'درخواست ارسال مجدد دیگری در حال پردازش است.', 409 );
		}
		$code = (string) random_int( 100000, 999999 );
		$expires = min( 900, max( 120, absint( $config['recovery_otp_expiry'] ?? 300 ) ) );
		$resend = min( $expires - 30, max( 30, absint( $config['recovery_otp_resend'] ?? 120 ) ) );
		if ( $row->recipient ) {
			$result = ECM_SMS::send_secure_pattern( (string) $config['recovery_otp_pattern_profile'], (string) $row->recipient, array( 'code' => $code ), array( 'event_id' => (int) $row->event_id, 'registration_id' => (int) $row->registration_id, 'participant_id' => (int) $row->participant_id ) );
			if ( is_wp_error( $result ) ) {
				$wpdb->update( ECM_DB::table( 'otp' ), array( 'status' => 'active', 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => (int) $row->id, 'status' => 'resending' ), array( '%s', '%s' ), array( '%d', '%s' ) );
				self::error( 'send_failed', (string) $config['recovery_otp_send_failed_message'], 503 );
			}
		}
		$wpdb->update( ECM_DB::table( 'otp' ), array( 'status' => 'active', 'otp_hash' => wp_hash_password( $code ), 'attempts' => 0, 'send_count' => (int) $row->send_count + 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $expires ), 'resend_at' => gmdate( 'Y-m-d H:i:s', $now + $resend ), 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => (int) $row->id, 'status' => 'resending' ), array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' ), array( '%d', '%s' ) );
		self::activity_success();
		wp_send_json_success( array( 'expires_at' => $now + $expires, 'resend_at' => $now + $resend, 'message' => (string) $config['recovery_otp_sent_message'] ) );
	}

	public static function ajax_verify(): void {
		self::$activity_stage = 'verify';
		self::guard();
		if ( ! self::rate_limit( 'verify', 20, 15 * MINUTE_IN_SECONDS ) ) {
			self::error( 'rate_limited', 'تعداد تلاش‌ها بیش از حد مجاز است؛ کمی بعد دوباره امتحان کنید.', 429 );
		}
		$row = self::challenge_row();
		$event = $row ? ECM_Events::event( (int) $row->event_id ) : null;
		self::$activity_event_id = $event ? (int) $event->id : 0;
		$config = ECM_Events::config( $event );
		if ( $row && $event && ! ECM_Events::allows( $event, 'recovery' ) ) {
			self::error( 'event_unavailable', 'بازیابی این رویداد غیرفعال است.', 403 );
		}
		if ( ! $row || ! $event || 'active' !== (string) $row->status || ! hash_equals( (string) $row->browser_hash, self::browser_hash() ) ) {
			self::error( 'invalid_challenge', (string) $config['recovery_otp_expired_message'], 400 );
		}
		if ( strtotime( (string) $row->expires_at . ' UTC' ) <= time() ) {
			self::expire( (int) $row->id );
			self::error( 'expired', (string) $config['recovery_otp_expired_message'], 410 );
		}
		$max_attempts = min( 10, max( 3, absint( $config['recovery_otp_max_attempts'] ?? 5 ) ) );
		$code = preg_replace( '/\D+/', '', ECM_Utils::normalize_digits( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) ) );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . ECM_DB::table( 'otp' ) . ' SET attempts=attempts+1,updated_at=%s WHERE id=%d AND status=%s', ECM_Utils::now_utc(), (int) $row->id, 'active' ) );
		if ( (int) $row->attempts + 1 > $max_attempts || ! preg_match( '/^\d{6}$/', $code ) || ! wp_check_password( $code, (string) $row->otp_hash ) ) {
			if ( (int) $row->attempts + 1 >= $max_attempts ) {
				$wpdb->update( ECM_DB::table( 'otp' ), array( 'status' => 'locked', 'otp_hash' => '', 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => (int) $row->id ), array( '%s', '%s', '%s' ), array( '%d' ) );
			}
			self::error( 'invalid_code', (string) $config['recovery_otp_invalid_message'], 400, array( 'attempts_left' => max( 0, $max_attempts - (int) $row->attempts - 1 ) ) );
		}
		$participant = (int) $row->participant_id ? ECM_DB::participant( (int) $row->participant_id ) : null;
		if ( ! $participant && $row->recipient ) {
			$tracking = sanitize_text_field( wp_unslash( $_POST['tracking_code'] ?? '' ) );
			$participant = ECM_Participants::recover_by_phone( (string) $row->recipient, (int) $row->event_id, $tracking );
		}
		if ( is_wp_error( $participant ) ) {
			self::error( 'tracking_required', (string) $config['recovery_otp_shared_message'], 400, array( 'shared' => true ) );
		}
		if ( ! $participant ) {
			self::error( 'invalid_code', (string) $config['recovery_otp_invalid_message'], 400 );
		}
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . ECM_DB::table( 'otp' ) . " SET status='verifying',updated_at=%s WHERE id=%d AND status='active'", ECM_Utils::now_utc(), (int) $row->id ) );
		if ( 1 !== $claimed ) {
			self::error( 'already_used', 'این کد قبلاً استفاده شده است.', 409 );
		}
		if ( ! ECM_Participants::grant_recovered_card( $participant ) ) {
			$wpdb->update( ECM_DB::table( 'otp' ), array( 'status' => 'active', 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => (int) $row->id, 'status' => 'verifying' ), array( '%s', '%s' ), array( '%d', '%s' ) );
			self::error( 'grant_failed', 'بازکردن امن کارت انجام نشد؛ دوباره بازیابی کنید.', 500 );
		}
		$wpdb->update( ECM_DB::table( 'otp' ), array( 'status' => 'verified', 'verified_at' => ECM_Utils::now_utc(), 'otp_hash' => '', 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => (int) $row->id, 'status' => 'verifying' ), array( '%s', '%s', '%s', '%s' ), array( '%d', '%s' ) );
		ECM_Utils::log( (int) $participant->registration_id, 'card_recovered', array( 'via' => 'otp', 'participant_id' => (int) $participant->id, 'participant_type' => (string) $participant->participant_type ) );
		$args = array( 'id' => (int) $participant->registration_id );
		if ( 'guest' === (string) $participant->participant_type ) {
			$args['participant'] = (string) $participant->public_id;
			$args['scope'] = 'guest';
		}
		self::activity_success();
		wp_send_json_success( array( 'redirect' => ECM_Utils::page_url( 'card', $args ) ) );
	}

	public static function cleanup(): void {
		global $wpdb;
		$table = ECM_DB::table( 'otp' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at<%s OR (status<>'active' AND updated_at<%s)", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function resolve_identifier( string $identifier, int $event_id ): array {
		global $wpdb;
		$participants = ECM_DB::table( 'participants' );
		$people = ECM_DB::table( 'people' );
		$registrations = ECM_DB::table( 'registrations' );
		$phone = ECM_Events::normalize_phone( $identifier );
		if ( $phone ) {
			$phone_hash = hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.id,p.registration_id,pe.normalized_phone FROM {$participants} p INNER JOIN {$people} pe ON pe.id=p.person_id INNER JOIN {$registrations} r ON r.id=p.registration_id WHERE p.event_id=%d AND pe.phone_hash=%s AND p.status='active' AND r.is_test=0 AND r.payment_status IN ('paid','not_required','offline_confirmed') ORDER BY p.id DESC LIMIT 3", $event_id, $phone_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $rows ) {
				return array( 'recipient' => ECM_SMS::phone( (string) $rows[0]->normalized_phone ), 'registration_id' => (int) $rows[0]->registration_id, 'participant_id' => 1 === count( $rows ) ? (int) $rows[0]->id : 0, 'ambiguous' => 1 < count( $rows ) );
			}
			return array();
		}
		$tracking = strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( ECM_Utils::normalize_digits( trim( $identifier ) ) ) ) );
		if ( ! $tracking ) {
			return array();
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT p.id,p.registration_id,pe.normalized_phone FROM {$participants} p INNER JOIN {$people} pe ON pe.id=p.person_id INNER JOIN {$registrations} r ON r.id=p.registration_id WHERE p.event_id=%d AND (p.public_id=%s OR (p.participant_type='primary' AND r.public_id=%s)) AND p.status='active' AND r.is_test=0 AND r.payment_status IN ('paid','not_required','offline_confirmed') LIMIT 1", $event_id, $tracking, $tracking ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? array( 'recipient' => ECM_SMS::phone( (string) $row->normalized_phone ), 'registration_id' => (int) $row->registration_id, 'participant_id' => (int) $row->id, 'ambiguous' => false ) : array();
	}

	private static function posted_event(): object {
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$event = ECM_Events::event( $event_id ?: ECM_Events::default_event_id() );
		if ( ! $event || ! ECM_Events::allows( $event, 'recovery' ) ) {
			self::error( 'event_unavailable', 'رویداد در دسترس نیست.', 404 );
		}
		return $event;
	}

	private static function challenge_row(): ?object {
		$challenge = sanitize_text_field( wp_unslash( $_POST['challenge'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $challenge ) ) {
			return null;
		}
		global $wpdb;
		$table = ECM_DB::table( 'otp' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE challenge_hash=%s LIMIT 1", hash( 'sha256', $challenge ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	private static function browser_hash(): string {
		$client = sanitize_text_field( wp_unslash( $_COOKIE['ecm_client'] ?? '' ) );
		$agent = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 190 );
		return hash_hmac( 'sha256', $client . '|' . $agent, wp_salt( 'secure_auth' ) );
	}

	private static function rate_limit( string $scope, int $limit, int $window ): bool {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		$key = 'ecm_otp_' . md5( $scope . '|' . self::browser_hash() . '|' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ) );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	private static function guard(): void {
		check_ajax_referer( self::ACTION_NONCE, 'nonce' );
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			self::error( 'method_not_allowed', 'درخواست معتبر نیست.', 405 );
		}
	}

	private static function expire( int $id ): void {
		global $wpdb;
		$wpdb->update( ECM_DB::table( 'otp' ), array( 'status' => 'expired', 'otp_hash' => '', 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );
	}

	private static function error( string $code, string $message, int $status, array $extra = array() ): void {
		$event_id = self::$activity_event_id ?: absint( $_POST['event_id'] ?? 0 );
		ECM_Activity::record(
			'operation',
			array(
				'operation'  => 'otp_' . sanitize_key( self::$activity_stage ) . '_failed',
				'event_id'   => $event_id,
				'is_error'   => true,
				'error_code' => sanitize_key( $code ),
				'http_status'=> $status,
				'route'      => 'ajax_ecm_otp_' . sanitize_key( self::$activity_stage ),
			)
		);
		wp_send_json_error( array_merge( array( 'code' => sanitize_key( $code ), 'message' => sanitize_text_field( $message ) ), $extra ), $status );
	}

	private static function activity_success(): void {
		ECM_Activity::record(
			'operation',
			array(
				'operation' => 'otp_' . sanitize_key( self::$activity_stage ) . '_success',
				'event_id'  => self::$activity_event_id,
				'route'     => 'ajax_ecm_otp_' . sanitize_key( self::$activity_stage ),
			)
		);
	}
}
