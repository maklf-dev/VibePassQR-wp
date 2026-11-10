<?php

defined( 'ABSPATH' ) || exit;

/**
 * Faraz SMS / IPPanel Edge integration.
 */
final class ECM_SMS {
	private const API_BASE = 'https://edge.ippanel.com/v1';

	public static function hooks(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'ecm_registration_completed', array( __CLASS__, 'registration_completed' ), 10, 2 );
		add_action( 'ecm_event_logged', array( __CLASS__, 'event' ), 10, 3 );
		add_action( 'ecm_sms_job', array( __CLASS__, 'send' ), 10, 1 );
		add_action( 'ecm_sms_sweep', array( __CLASS__, 'sweep' ) );
		add_action( 'admin_post_ecm_sms_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_ecm_sms_test', array( __CLASS__, 'run_test' ) );
		add_action( 'admin_post_ecm_sms_retry', array( __CLASS__, 'retry' ) );
		add_action( 'admin_post_ecm_sms_support_report', array( __CLASS__, 'download_support_report' ) );
		add_action( 'wp_ajax_ecm_sms_auto_test', array( __CLASS__, 'auto_test_ajax' ) );
		self::ensure_worker_schedule();
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules['ecm_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => 'هر دقیقه — Event Card Manager' );
		return $schedules;
	}

	private static function ensure_worker_schedule(): void {
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( 'ecm_sms_sweep' ) : null;
		if ( $event && 'ecm_minute' !== (string) $event->schedule ) {
			wp_clear_scheduled_hook( 'ecm_sms_sweep' );
			$event = null;
		}
		if ( ! $event && ! wp_next_scheduled( 'ecm_sms_sweep' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'ecm_minute', 'ecm_sms_sweep' );
		}
	}

	public static function states(): array {
		return array(
			'registration' => 'ثبت‌نام موفق و صدور کارت',
			'workflow_submitted' => 'دریافت درخواست جدید (Workflow)',
			'workflow_needs_correction' => 'درخواست اصلاح مدارک (Workflow)',
			'workflow_rejected' => 'رد درخواست (Workflow)',
			'attendance'   => 'ثبت حضور موفق',
			'recovery'     => 'بازیابی کارت',
			'repeat'       => 'ثبت حضور تکراری',
			'early'        => 'مراجعه زودتر از نوبت',
			'late'         => 'مراجعه پس از پایان نوبت‌ها',
			'outside'      => 'نبود نوبت قابل استفاده',
		);
	}

	public static function notification_label( string $state ): string {
		if ( 'otp_recovery' === $state ) {
			return 'کد تأیید بازیابی';
		}
		return self::states()[ $state ] ?? $state;
	}

	public static function event( int $registration_id, string $type, array $meta ): void {
		$map   = array(
			'attendance_success'        => 'attendance',
			'card_recovered'            => 'recovery',
			'attendance_repeat'         => 'repeat',
			'attendance_early'          => 'early',
			'attendance_late'           => 'late',
			'attendance_outside'        => 'outside',
			'attendance_outside_window' => 'outside',
		);
		$state = $map[ $type ] ?? '';
		if ( ! $state || ( 'card_recovered' === $type && 'sms_link' === ( $meta['via'] ?? '' ) ) ) {
			return;
		}
		$participant_id = absint( $meta['participant_id'] ?? 0 );
		$suffix = ! empty( $meta['attendance_id'] ) ? (string) absint( $meta['attendance_id'] ) : gmdate( 'YmdH' );
		self::queue( $state, $registration_id, $state . '-' . $registration_id . '-p' . $participant_id . '-' . $suffix, $meta );
	}

	/** Queue one independently scoped notification for the host and each guest. */
	public static function registration_completed( int $registration_id, object $registration ): void {
		$participants = ECM_DB::participants_for_registration( $registration_id );
		if ( ! $participants ) {
			self::queue( 'registration', $registration_id, 'registration-' . $registration_id );
			return;
		}
		foreach ( $participants as $participant ) {
			self::queue(
				'registration',
				$registration_id,
				'registration-' . $registration_id . '-p' . (int) $participant->id,
				array(
					'participant_id'   => (int) $participant->id,
					'participant_type' => (string) $participant->participant_type,
					'participant_name' => (string) $participant->full_name,
				)
			);
		}
	}

	public static function phone( string $value ): string {
		$value = preg_replace( '/[^0-9+]/', '', ECM_Utils::normalize_digits( $value ) );
		if ( str_starts_with( $value, '0098' ) ) {
			$value = '+98' . substr( $value, 4 );
		} elseif ( preg_match( '/^09\d{9}$/', $value ) ) {
			$value = '+98' . substr( $value, 1 );
		} elseif ( preg_match( '/^989\d{9}$/', $value ) ) {
			$value = '+' . $value;
		}
		return preg_match( '/^\+[1-9]\d{7,14}$/', $value ) ? $value : '';
	}

	public static function queue( string $state, int $registration_id, string $event_key, array $meta = array() ): void {
		$global = ECM_Utils::settings();
		if ( empty( $global['sms_enabled'] ) || ! isset( self::states()[ $state ] ) ) {
			return;
		}
		$registration = ECM_DB::registration( $registration_id );
		if ( ! $registration ) {
			return;
		}
		global $wpdb;
		$sms_table = ECM_DB::table( 'sms' );
		$event_key = sanitize_text_field( $event_key );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sms_table} WHERE event_key=%s LIMIT 1", $event_key ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		$event_config = ECM_Events::config_for_registration( $registration );
		$data        = ECM_DB::decode_data( $registration );
		$participant_id = ! empty( $meta['participant_id'] ) ? absint( $meta['participant_id'] ) : absint( $registration->primary_participant_id ?? 0 );
		$identity    = $participant_id ? ECM_DB::participant_identity( $participant_id ) : null;
		$settings    = self::effective_settings( $registration, $state, $meta );
		$recipient   = $identity && ! empty( $identity->normalized_phone ) ? self::phone( (string) $identity->normalized_phone ) : self::phone( ECM_Utils::field_value( $data, (string) ( $event_config['sms_field'] ?? $settings['sms_field'] ?? 'mobile' ) ) );
		if ( $identity ) {
			$meta['participant_id']   = (int) $identity->id;
			$meta['participant_type'] = (string) $identity->participant_type;
			$meta['participant_name'] = (string) $identity->full_name;
			$meta['participant_phone']= (string) $identity->normalized_phone;
			$meta['participant_public_id'] = (string) $identity->public_id;
		}
			$settings = self::audience_settings( $settings, $state, (string) ( $meta['participant_type'] ?? 'primary' ) );
			if ( empty( $settings[ 'sms_' . $state ] ) ) {
				return;
			}
		$vars      = self::variables( $registration, $data, $meta );
		$template  = (string) ( $settings[ 'sms_' . $state . '_text' ] ?? '' );
		$profile_id = sanitize_key( (string) ( $settings['sms_pattern_profile'][ $state ] ?? '' ) );
		$profile = $profile_id && isset( $settings['sms_pattern_profiles'][ $profile_id ] ) && is_array( $settings['sms_pattern_profiles'][ $profile_id ] ) ? $settings['sms_pattern_profiles'][ $profile_id ] : array();
		$link_params = ! empty( $profile['params'] ) ? $profile['params'] : ( $settings['sms_params'][ $state ] ?? array() );
		$needs_link = str_contains( $template, '{card_link}' ) || str_contains( wp_json_encode( $link_params ), '{card_link}' );
		$link_participant = $identity && 'guest' === (string) $identity->participant_type ? $identity : null;
		$vars['{card_link}'] = $needs_link ? ECM_Upgrade::link( $registration, $link_participant ) : '';
		$message = strtr( $template, $vars );
		$payload = self::payload( $state, $recipient, $message, $vars, $settings, $registration );
		$transport_key = sanitize_key( (string) ( $settings['sms_transport'] ?? 'edge' ) );
		if ( ! ECM_SMS_Transports::get( $transport_key ) ) {
			$transport_key = 'edge';
		}
		$paused  = ! empty( $settings['sms_transport_paused'] );
		$status  = $recipient && ! is_wp_error( $payload ) ? ( $paused ? 'paused' : 'queued' ) : 'failed';
		$error   = $recipient ? ( is_wp_error( $payload ) ? $payload->get_error_code() : ( $paused ? 'transport_paused' : '' ) ) : 'invalid_recipient';

		$inserted = $wpdb->insert(
			$sms_table,
			array(
				'registration_id' => $registration_id,
				'participant_id'  => $participant_id,
				'event_id'        => absint( $registration->event_id ?? 0 ),
				'session_id'      => absint( $meta['session_id'] ?? 0 ),
				'notification_type'=> sanitize_key( $state ),
				'transport'       => $transport_key,
				'event_key'       => $event_key,
				'recipient'       => $recipient,
				'message'         => $message,
				'payload'         => is_wp_error( $payload ) ? '{}' : wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'          => $status,
				'error_code'      => $error ?: null,
				'created_at'      => ECM_Utils::now_utc(),
				'updated_at'      => ECM_Utils::now_utc(),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			ECM_Diagnostics::record( 'sms', 'SMS queue insert skipped or failed', array( 'registration_id' => $registration_id, 'state' => $state, 'database_error' => (bool) $wpdb->last_error ) );
			return;
		}
		$sms_id = (int) $wpdb->insert_id;
		ECM_Diagnostics::record( 'sms', 'SMS queued', array( 'sms_id' => $sms_id, 'registration_id' => $registration_id, 'state' => $state, 'status' => $status ) );
		if ( 'failed' === $status ) {
			ECM_Utils::log(
				$registration_id,
				'sms_failed',
				array(
					'sms_id'         => $sms_id,
					'event_id'       => absint( $registration->event_id ?? 0 ),
					'participant_id' => $participant_id,
					'state'          => $state,
					'error'          => $error ?: 'queue_validation_failed',
				)
			);
		}
		if ( 'queued' !== $status ) {
			return;
		}
		// Automatic notifications never block registration or attendance requests.
		wp_schedule_single_event( time() + 1, 'ecm_sms_job', array( $sms_id ) );
	}


	/**
	 * Queue opt-in workflow notifications before a canonical registration exists.
	 * Uses the existing SMS table, attempts worker, profiles, retries and reports.
	 * Never inject tracking secrets, OTP, phone or receipt content into logs.
	 */
	public static function queue_workflow( int $instance_id, string $state ): void {
		if ( ! ECM_Workflow::enabled() || ! defined( 'ECM_WORKFLOW_STAGING_SMS' ) || true !== ECM_WORKFLOW_STAGING_SMS
			|| ! function_exists( 'wp_get_environment_type' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true )
			|| ! in_array( $state, array( 'workflow_submitted', 'workflow_needs_correction', 'workflow_rejected' ), true ) ) {
			return;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT id,event_id,lock_version,data_json,state FROM ' . ECM_DB::table( 'flow_instances' ) . ' WHERE id=%d', $instance_id
		) );
		if ( ! $row || ( 'workflow_submitted' === $state && 'under_review' !== $row->state )
			|| ( 'workflow_needs_correction' === $state && 'needs_correction' !== $row->state )
			|| ( 'workflow_rejected' === $state && 'rejected' !== $row->state ) ) { return; }
		$event = ECM_Events::event( (int) $row->event_id );
		if ( ! $event ) { return; }
		$settings = ECM_Utils::settings();
		if ( empty( $settings['sms_enabled'] ) ) { return; }
		$config = ECM_Events::config( $event );
		if ( empty( $config['sms_inherit'] ) ) {
			$settings['sms_mode'] = 'pattern' === ( $config['sms_mode'] ?? '' ) ? 'pattern' : 'webservice';
			$rule = (array) ( $config['sms_rules'][ $state ] ?? array() );
			self::apply_rule( $settings, $state, $rule );
		}
		if ( empty( $settings[ 'sms_' . $state ] ) ) { return; }
		$data = json_decode( (string) $row->data_json, true );
		if ( ! is_array( $data ) ) { return; }
		$mobile_field = sanitize_key( (string) ( $config['sms_field'] ?? 'mobile' ) );
		$mobile = ECM_Utils::normalize_mobile( (string) ( $data[ $mobile_field ] ?? '' ) );
		$recipient = $mobile ? self::phone( $mobile ) : '';
		if ( ! $recipient ) { return; }
		$vars = array(
			'{event_name}' => (string) $event->title,
			'{status}' => (string) ( self::states()[ $state ] ?? '' ),
			'{date}' => ECM_Utils::utc_to_local( ECM_Utils::now_utc(), 'Y/m/d H:i' ),
		);
		$template = (string) ( $settings[ 'sms_' . $state . '_text' ] ?? '' );
		$message = strtr( $template, $vars );
		$payload = self::payload( $state, $recipient, $message, $vars, $settings, null );
		$transport_key = sanitize_key( (string) ( $settings['sms_transport'] ?? 'edge' ) );
		if ( ! ECM_SMS_Transports::get( $transport_key ) ) { $transport_key = 'edge'; }
		$paused = ! empty( $settings['sms_transport_paused'] );
		$status = is_wp_error( $payload ) ? 'failed' : ( $paused ? 'paused' : 'queued' );
		$event_key = 'workflow-' . $instance_id . '-' . $state . '-r' . (int) $row->lock_version;
		$table = ECM_DB::table( 'sms' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_key=%s LIMIT 1", $event_key ) ) ) { return; }
		$ok = $wpdb->insert( $table, array(
			'registration_id' => 0, 'participant_id' => 0, 'event_id' => (int) $event->id, 'session_id' => 0,
			'notification_type' => $state, 'transport' => $transport_key, 'event_key' => $event_key,
			'recipient' => $recipient, 'message' => $message,
			'payload' => is_wp_error( $payload ) ? '{}' : wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'status' => $status, 'error_code' => is_wp_error( $payload ) ? sanitize_key( $payload->get_error_code() ) : ( $paused ? 'transport_paused' : null ),
			'created_at' => ECM_Utils::now_utc(), 'updated_at' => ECM_Utils::now_utc(),
		) );
		if ( ! $ok ) {
			ECM_Diagnostics::record( 'sms', 'Workflow SMS queue failed', array( 'instance_id'=>$instance_id, 'state'=>$state ) );
			return;
		}
		if ( 'queued' === $status ) { wp_schedule_single_event( time() + 1, 'ecm_sms_job', array( (int) $wpdb->insert_id ) ); }
	}

	/** Resolve global -> event -> session notification policy. */
	private static function effective_settings( object $registration, string $state, array $meta ): array {
		$settings = ECM_Utils::settings();
		$config   = ECM_Events::config_for_registration( $registration );
		if ( empty( $config['sms_inherit'] ) ) {
			$settings['sms_mode'] = 'pattern' === ( $config['sms_mode'] ?? '' ) ? 'pattern' : 'webservice';
			$rule = (array) ( $config['sms_rules'][ $state ] ?? array() );
			if ( $rule ) {
				self::apply_rule( $settings, $state, $rule );
			}
		}
		if ( ! empty( $meta['session_id'] ) && in_array( $state, array( 'attendance', 'repeat' ), true ) ) {
			$session_config = self::session_sms_config( absint( $meta['session_id'] ) );
			if ( ! empty( $session_config['override'] ) ) {
				if ( in_array( $session_config['mode'] ?? '', array( 'webservice', 'pattern' ), true ) ) {
					$settings['sms_mode'] = $session_config['mode'];
				}
				$rule = (array) ( $session_config['rules'][ $state ] ?? array() );
				if ( $rule ) {
					self::apply_rule( $settings, $state, $rule );
				}
			}
		}
		return $settings;
	}

	private static function apply_rule( array &$settings, string $state, array $rule ): void {
		$settings[ 'sms_' . $state ]           = empty( $rule['enabled'] ) ? 0 : 1;
		$settings[ 'sms_' . $state . '_text' ] = (string) ( $rule['text'] ?? '' );
		$settings['sms_patterns'][ $state ]     = (string) ( $rule['pattern'] ?? '' );
		$settings['sms_params'][ $state ]       = (array) ( $rule['params'] ?? array() );
		$settings['sms_pattern_profile'][ $state ] = sanitize_key( (string) ( $rule['pattern_profile'] ?? '' ) );
			$mode = sanitize_key( (string) ( $rule['mode'] ?? 'inherit' ) );
			$settings['sms_state_mode'][ $state ] = in_array( $mode, array( 'inherit', 'webservice', 'pattern' ), true ) ? $mode : 'inherit';
			$settings['sms_audience_mode'][ $state ] = 'separate' === ( $rule['audience_mode'] ?? '' ) ? 'separate' : 'shared';
			$guest_mode = sanitize_key( (string) ( $rule['guest_mode'] ?? 'inherit' ) );
			$settings['sms_guest_mode'][ $state ] = in_array( $guest_mode, array( 'inherit', 'webservice', 'pattern' ), true ) ? $guest_mode : 'inherit';
			$settings['sms_guest_text'][ $state ] = (string) ( $rule['guest_text'] ?? '' );
			$settings['sms_guest_patterns'][ $state ] = (string) ( $rule['guest_pattern'] ?? '' );
			$settings['sms_guest_params'][ $state ] = (array) ( $rule['guest_params'] ?? array() );
			$settings['sms_guest_pattern_profile'][ $state ] = sanitize_key( (string) ( $rule['guest_pattern_profile'] ?? '' ) );
	}

		private static function audience_settings( array $settings, string $state, string $participant_type ): array {
			if ( 'guest' !== $participant_type || 'separate' !== ( $settings['sms_audience_mode'][ $state ] ?? 'shared' ) ) {
				return $settings;
			}
			$settings[ 'sms_' . $state . '_text' ] = (string) ( $settings['sms_guest_text'][ $state ] ?? '' );
			$settings['sms_patterns'][ $state ] = (string) ( $settings['sms_guest_patterns'][ $state ] ?? '' );
			$settings['sms_params'][ $state ] = (array) ( $settings['sms_guest_params'][ $state ] ?? array() );
			$settings['sms_pattern_profile'][ $state ] = sanitize_key( (string) ( $settings['sms_guest_pattern_profile'][ $state ] ?? '' ) );
			$settings['sms_state_mode'][ $state ] = sanitize_key( (string) ( $settings['sms_guest_mode'][ $state ] ?? 'inherit' ) );
			return $settings;
		}

	private static function session_sms_config( int $session_id ): array {
		static $cache = array();
		if ( isset( $cache[ $session_id ] ) ) {
			return $cache[ $session_id ];
		}
		global $wpdb;
		$table = ECM_DB::table( 'sessions' );
		$json  = $wpdb->get_var( $wpdb->prepare( "SELECT sms_config FROM {$table} WHERE id=%d", $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data  = $json ? json_decode( (string) $json, true ) : array();
			$data = is_array( $data ) ? $data : array();
			foreach ( (array) ( $data['rules'] ?? array() ) as $state => $rule ) {
				$rule = is_array( $rule ) ? $rule : array();
				if ( empty( $rule['mode'] ) ) $rule['mode'] = ! empty( $rule['pattern_profile'] ) || ! empty( $rule['pattern'] ) ? 'pattern' : 'inherit';
				if ( empty( $rule['audience_mode'] ) ) $rule['audience_mode'] = 'shared';
				$data['rules'][ $state ] = $rule;
			}
			$cache[ $session_id ] = $data;
		return $cache[ $session_id ];
	}

	private static function variables( object $registration, array $data, array $meta ): array {
		$settings = array_merge( ECM_Utils::settings(), ECM_Events::config_for_registration( $registration ) );
		$event    = ECM_Events::registration_event( $registration );
		$session  = '';
		$session_time = '';
		if ( ! empty( $meta['session_id'] ) ) {
			global $wpdb;
			$table = ECM_DB::table( 'sessions' );
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT title,start_at_utc FROM {$table} WHERE id=%d", absint( $meta['session_id'] ) ) );
			if ( $row ) {
				$session      = (string) $row->title;
				$session_time = ECM_Utils::utc_to_local( (string) $row->start_at_utc, 'Y/m/d H:i:s' );
			}
		}
		if ( ! empty( $meta['session_time'] ) ) {
			$session_time = sanitize_text_field( (string) $meta['session_time'] );
		}
		$operator_name = '';
		$operator_code = '';
		$host_name = '';
		if ( ! empty( $registration->primary_participant_id ) ) {
			$host = ECM_DB::participant_identity( (int) $registration->primary_participant_id );
			$host_name = $host ? sanitize_text_field( (string) $host->full_name ) : '';
		}
		if ( ! empty( $meta['recorded_by_user_id'] ) ) {
			$operator = get_userdata( absint( $meta['recorded_by_user_id'] ) );
			if ( $operator ) {
				$operator_name = sanitize_text_field( $operator->display_name ?: $operator->user_login );
				$operator_code = 'U' . (int) $operator->ID;
			}
		}
		$vars = array(
			'{name}'          => ! empty( $meta['participant_name'] ) ? sanitize_text_field( (string) $meta['participant_name'] ) : ECM_Utils::field_value( $data, (string) $settings['name_field'] ),
			'{participant_type}'=> sanitize_text_field( (string) ( $meta['participant_type'] ?? 'primary' ) ),
			'{host_name}'      => $host_name,
			'{event_name}'    => $event ? (string) $event->title : (string) $settings['event_name'],
			'{tracking_code}' => ! empty( $meta['participant_public_id'] ) ? sanitize_text_field( (string) $meta['participant_public_id'] ) : (string) $registration->public_id,
			'{time}'          => ECM_Utils::utc_to_local( ECM_Utils::now_utc(), 'Y/m/d H:i:s' ),
			'{attendance_time}'=> ECM_Utils::utc_to_local( ECM_Utils::now_utc(), 'Y/m/d H:i:s' ),
			'{session_name}'  => $session,
			'{session_time}'  => $session_time,
			'{operator_name}' => $operator_name,
			'{operator_code}' => $operator_code,
			'{phone}'         => ! empty( $meta['participant_phone'] ) ? sanitize_text_field( (string) $meta['participant_phone'] ) : ECM_Utils::field_value( $data, (string) ( $settings['sms_field'] ?? 'mobile' ) ),
			'{payment_reference}' => sanitize_text_field( (string) ( $registration->payment_reference ?? '' ) ),
			'{payment_date}'  => ECM_Utils::utc_to_local( (string) ( $registration->paid_at ?: $registration->created_at ), 'Y/m/d H:i' ),
			'{payment_status}'=> sanitize_key( (string) ( $registration->payment_status ?? '' ) ),
		);
		foreach ( $data as $key => $value ) {
			$vars[ '{field:' . sanitize_key( (string) $key ) . '}' ] = ECM_Utils::field_value( $data, (string) $key );
		}
		return $vars;
	}

	private static function payload( string $state, string $recipient, string $message, array $vars, array $settings, ?object $registration = null ) {
		$transport_key = sanitize_key( (string) ( $settings['sms_transport'] ?? 'edge' ) );
		$sender_raw = sanitize_text_field( (string) ( $settings['sms_sender'] ?? '' ) );
		$sender = self::phone( $sender_raw );
		if ( 'edge' !== $transport_key && ! $sender ) {
			$sender = $sender_raw;
		}
			$state_mode = sanitize_key( (string) ( $settings['sms_state_mode'][ $state ] ?? 'inherit' ) );
			$mode = in_array( $state_mode, array( 'webservice', 'pattern' ), true ) ? $state_mode : ( 'pattern' === ( $settings['sms_mode'] ?? '' ) ? 'pattern' : 'webservice' );
			if ( 'pattern' === $mode ) {
			$profiles   = is_array( $settings['sms_pattern_profiles'] ?? null ) ? $settings['sms_pattern_profiles'] : array();
			$profile_id = sanitize_key( (string) ( $settings['sms_pattern_profile'][ $state ] ?? '' ) );
			$profile    = $profile_id && isset( $profiles[ $profile_id ] ) && is_array( $profiles[ $profile_id ] ) ? $profiles[ $profile_id ] : array();
			if ( $profile_id && ( ! $profile || empty( $profile['enabled'] ) ) ) {
				return new WP_Error( 'pattern_profile_disabled', 'پترن انتخاب‌شده حذف یا غیرفعال شده است.' );
			}
			if ( ! empty( $profile['sender'] ) ) {
				$profile_sender = sanitize_text_field( (string) $profile['sender'] );
				$normalized_sender = self::phone( $profile_sender );
				$sender = 'edge' === $transport_key ? $normalized_sender : ( $normalized_sender ?: $profile_sender );
				if ( 'edge' === $transport_key && ! $sender ) {
					return new WP_Error( 'invalid_sender', 'شماره خط اختصاصی پترن معتبر نیست.' );
				}
			}
			$code       = sanitize_text_field( (string) ( $profile['code'] ?? ( $settings['sms_patterns'][ $state ] ?? '' ) ) );
			if ( ! $code ) {
				return new WP_Error( 'pattern_missing', 'کد پترن این وضعیت وارد نشده است.' );
			}
			if ( ! empty( $profile['requires_payment'] ) && ( ! $registration || 'paid' !== (string) $registration->payment_status || empty( $registration->payment_reference ) ) ) {
				return new WP_Error( 'pattern_requires_payment', 'پترن انتخاب‌شده فقط برای ثبت‌نام دارای پرداخت موفق و شماره تراکنش قابل استفاده است.' );
			}
			$params = array();
			$param_map = ! empty( $profile['params'] ) && is_array( $profile['params'] ) ? $profile['params'] : (array) ( $settings['sms_params'][ $state ] ?? array() );
			foreach ( $param_map as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( $key ) {
					$resolved = sanitize_text_field( strtr( (string) $value, $vars ) );
					if ( '' === trim( $resolved ) ) {
						return new WP_Error( 'pattern_param_empty', 'پارامتر پترن «' . $key . '» مقدار ندارد.' );
					}
					if ( function_exists( 'mb_strlen' ) ? mb_strlen( $resolved, 'UTF-8' ) > 40 : strlen( $resolved ) > 40 ) {
						return new WP_Error( 'pattern_param_too_long', 'مقدار پارامتر پترن «' . $key . '» بیشتر از ۴۰ کاراکتر است.' );
					}
					$params[ $key ] = $resolved;
				}
			}
			if ( 'edge' === $transport_key && ! $sender ) {
				return new WP_Error( 'invalid_sender', 'شماره خط ارسال معتبر نیست.' );
			}
			return array(
				'sending_type' => 'pattern',
				'from_number'  => $sender,
				'code'         => $code,
				'recipients'   => array( $recipient ),
				'params'       => (object) $params,
			);
		}
		if ( '' === trim( $message ) ) {
			return new WP_Error( 'message_empty', 'متن پیامک خالی است.' );
		}
		if ( 'edge' === $transport_key && ! $sender ) {
			return new WP_Error( 'invalid_sender', 'شماره خط ارسال معتبر نیست.' );
		}
		return array(
			'sending_type' => 'webservice',
			'from_number'  => $sender,
			'message'      => $message,
			'params'       => array( 'recipients' => array( $recipient ) ),
		);
	}

	/**
	 * Send a security-sensitive pattern immediately. The actual OTP value is
	 * never persisted in the queue, attempts table, diagnostics or audit log.
	 *
	 * @return array|WP_Error
	 */
	public static function send_secure_pattern( string $profile_id, string $recipient, array $params, array $context = array() ) {
		$settings = ECM_Utils::settings();
		if ( empty( $settings['sms_enabled'] ) ) {
			return new WP_Error( 'sms_disabled', 'سرویس پیامک فعال نیست.' );
		}
		if ( ! empty( $settings['sms_transport_paused'] ) ) {
			return new WP_Error( 'sms_paused', 'ارسال پیامک موقتاً متوقف است.' );
		}
		$profiles = is_array( $settings['sms_pattern_profiles'] ?? null ) ? $settings['sms_pattern_profiles'] : array();
		$profile_id = sanitize_key( $profile_id );
		$profile = isset( $profiles[ $profile_id ] ) && is_array( $profiles[ $profile_id ] ) ? $profiles[ $profile_id ] : array();
		if ( ! $profile || empty( $profile['enabled'] ) || empty( $profile['code'] ) ) {
			return new WP_Error( 'otp_pattern_missing', 'پترن کد تأیید فعال یا کامل نیست.' );
		}
		$recipient = self::phone( $recipient );
		if ( ! $recipient ) {
			return new WP_Error( 'invalid_recipient', 'شماره گیرنده معتبر نیست.' );
		}
		$transport_key = sanitize_key( (string) ( $settings['sms_transport'] ?? 'edge' ) );
		$transport = ECM_SMS_Transports::get( $transport_key );
		if ( ! $transport || ! $transport->available() ) {
			return new WP_Error( 'transport_unavailable', 'درگاه عملیاتی پیامک در دسترس نیست.' );
		}
		$profile_sender = trim( (string) ( $profile['sender'] ?? '' ) );
		$sender_raw = sanitize_text_field( '' !== $profile_sender ? $profile_sender : (string) ( $settings['sms_sender'] ?? '' ) );
		$sender = self::phone( $sender_raw );
		if ( 'edge' !== $transport_key && ! $sender ) {
			$sender = $sender_raw;
		}
		if ( 'edge' === $transport_key && ! $sender ) {
			return new WP_Error( 'invalid_sender', 'شماره خط ارسال پترن معتبر نیست.' );
		}
		$allowed_params = is_array( $profile['params'] ?? null ) ? array_keys( $profile['params'] ) : array_keys( $params );
		$types = is_array( $profile['param_types'] ?? null ) ? $profile['param_types'] : array();
		$resolved = array();
		foreach ( $allowed_params as $key ) {
			$key = sanitize_key( (string) $key );
			$value = sanitize_text_field( (string) ( $params[ $key ] ?? '' ) );
			if ( ! $key || '' === $value ) {
				return new WP_Error( 'pattern_param_empty', 'پارامتر الزامی پترن مقدار ندارد.' );
			}
			if ( 'integer' === ( $types[ $key ] ?? '' ) && ! preg_match( '/^\d+$/', ECM_Utils::normalize_digits( $value ) ) ) {
				return new WP_Error( 'pattern_param_type', 'پارامتر عددی پترن معتبر نیست.' );
			}
			$resolved[ $key ] = $value;
		}
		$payload = array(
			'sending_type' => 'pattern',
			'from_number'  => $sender,
			'code'         => sanitize_text_field( (string) $profile['code'] ),
			'recipients'   => array( $recipient ),
			'params'       => (object) $resolved,
		);
		$redacted_params = array_fill_keys( array_keys( $resolved ), '[redacted]' );
		$stored_payload = $payload;
		$stored_payload['params'] = (object) $redacted_params;
		global $wpdb;
		$table = ECM_DB::table( 'sms' );
		$event_key = 'otp-' . str_replace( '-', '', wp_generate_uuid4() );
		$inserted = $wpdb->insert(
			$table,
			array(
				'registration_id'  => absint( $context['registration_id'] ?? 0 ),
				'participant_id'   => absint( $context['participant_id'] ?? 0 ),
				'event_id'         => absint( $context['event_id'] ?? 0 ),
				'session_id'       => 0,
				'notification_type'=> 'otp_recovery',
				'transport'        => $transport_key,
				'event_key'        => $event_key,
				'recipient'        => $recipient,
				'message'          => '[کد تأیید محرمانه؛ محتوا ذخیره نشده است]',
				'payload'          => wp_json_encode( $stored_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'           => 'sending',
				'attempts'         => 1,
				'created_at'       => ECM_Utils::now_utc(),
				'updated_at'       => ECM_Utils::now_utc(),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'sms_log_failed', 'ثبت امن درخواست پیامک انجام نشد.' );
		}
		$sms_id = (int) $wpdb->insert_id;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $sms_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attempt_id = self::start_attempt( $row, $transport_key );
		$result = $transport->send( $payload );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			self::finish( $row, 'failed', sanitize_key( $result->get_error_code() ), is_array( $data ) ? absint( $data['http_code'] ?? 0 ) : 0, '', $result->get_error_message(), $attempt_id, is_array( $data ) ? absint( $data['latency_ms'] ?? 0 ) : 0, $transport_key );
			return $result;
		}
		if ( empty( $result['success'] ) ) {
			$error = sanitize_key( (string) ( $result['error_code'] ?? 'transport_rejected' ) );
			self::finish( $row, 'failed', $error, absint( $result['http_code'] ?? 0 ), sanitize_text_field( (string) ( $result['provider_id'] ?? '' ) ), (string) ( $result['summary'] ?? $result['message'] ?? '' ), $attempt_id, absint( $result['latency_ms'] ?? 0 ), $transport_key );
			return new WP_Error( $error, sanitize_text_field( (string) ( $result['message'] ?? 'ارسال کد تأیید پذیرفته نشد.' ) ) );
		}
		self::finish( $row, 'accepted', '', absint( $result['http_code'] ?? 0 ), sanitize_text_field( (string) ( $result['provider_id'] ?? '' ) ), (string) ( $result['summary'] ?? $result['message'] ?? '' ), $attempt_id, absint( $result['latency_ms'] ?? 0 ), $transport_key );
		return array( 'success' => true, 'sms_id' => $sms_id );
	}

	public static function send( int $sms_id ): void {
		global $wpdb;
		$table = ECM_DB::table( 'sms' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND status IN ('queued','retry_wait')", $sms_id ) );
		if ( ! $row ) {
			return;
		}
		$settings = ECM_Utils::settings();
		$state    = ! empty( $row->notification_type ) ? sanitize_key( (string) $row->notification_type ) : explode( '-', (string) $row->event_key, 2 )[0];
		if ( ! empty( $settings['sms_transport_paused'] ) ) {
			$wpdb->update( $table, array( 'status' => 'paused', 'error_code' => 'transport_paused', 'next_attempt_at' => null, 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => $sms_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
			return;
		}
		if ( empty( $settings['sms_enabled'] ) ) {
			$wpdb->update( $table, array( 'status' => 'cancelled', 'error_code' => 'state_disabled', 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => $sms_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
			return;
		}
		if ( $row->next_attempt_at && $row->next_attempt_at > ECM_Utils::now_utc() ) {
			return;
		}
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='sending',attempts=attempts+1,updated_at=%s WHERE id=%d AND status IN ('queued','retry_wait')", ECM_Utils::now_utc(), $sms_id ) );
		if ( 1 !== $claimed ) {
			return;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $sms_id ) );
		$transport_key = ! empty( $row->transport ) ? sanitize_key( (string) $row->transport ) : 'edge';
		$transport     = ECM_SMS_Transports::get( $transport_key );
		$attempt_id    = self::start_attempt( $row, $transport_key );
		if ( ! $transport ) {
			self::finish( $row, 'failed', 'transport_missing', 0, '', 'درگاه ذخیره‌شده در صف موجود نیست.', $attempt_id, 0, $transport_key );
			return;
		}
		$payload = json_decode( (string) $row->payload, true );
		if ( ! is_array( $payload ) ) {
			self::finish( $row, 'failed', 'payload_invalid', 0, '', 'ساختار پیام ذخیره‌شده معتبر نیست.', $attempt_id, 0, $transport_key );
			return;
		}
		$result = $transport->send( $payload );
		if ( is_wp_error( $result ) ) {
			$data       = $result->get_error_data();
			$retryable  = is_array( $data ) && ! empty( $data['retryable'] );
			$latency_ms = is_array( $data ) ? absint( $data['latency_ms'] ?? 0 ) : 0;
			$status     = $retryable ? self::retry_status( (int) $row->attempts ) : 'failed';
			self::finish( $row, $status, sanitize_key( $result->get_error_code() ), 0, '', $result->get_error_message(), $attempt_id, $latency_ms, $transport_key );
			return;
		}
		if ( ! empty( $result['success'] ) ) {
			self::finish( $row, 'accepted', '', absint( $result['http_code'] ?? 0 ), sanitize_text_field( (string) ( $result['provider_id'] ?? '' ) ), (string) ( $result['summary'] ?? $result['message'] ?? '' ), $attempt_id, absint( $result['latency_ms'] ?? 0 ), $transport_key );
			return;
		}
		$status = ! empty( $result['retryable'] ) ? self::retry_status( (int) $row->attempts ) : 'failed';
		self::finish( $row, $status, sanitize_key( (string) ( $result['error_code'] ?? 'transport_rejected' ) ), absint( $result['http_code'] ?? 0 ), sanitize_text_field( (string) ( $result['provider_id'] ?? '' ) ), (string) ( $result['summary'] ?? $result['message'] ?? '' ), $attempt_id, absint( $result['latency_ms'] ?? 0 ), $transport_key );
	}

	/** Configurable minute offsets (after attempt 1, 2, 3, etc.), bounded for WP-Cron. */
	public static function retry_delay_seconds( int $completed_attempts ): int {
		$raw = (string) ( ECM_Utils::settings()['sms_retry_delays'] ?? '5,10,15' );
		$minutes = array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ), static fn( int $x ): bool => $x >= 1 && $x <= 180 ) );
		if ( ! $minutes ) {
			$minutes = array( 5, 10, 15 );
		}
		$index = max( 0, min( count( $minutes ) - 1, $completed_attempts - 1 ) );
		return $minutes[ $index ] * MINUTE_IN_SECONDS;
	}

	private static function retry_status( int $attempts ): string {
		$maximum = min( 10, max( 1, absint( ECM_Utils::settings()['sms_max_attempts'] ?? 3 ) ) );
		return $attempts < $maximum ? 'retry_wait' : 'failed';
	}

	private static function start_attempt( object $row, string $transport ): int {
		global $wpdb;
		$inserted = $wpdb->insert(
			ECM_DB::table( 'sms_attempts' ),
			array(
				'sms_id'      => (int) $row->id,
				'attempt_no'  => (int) $row->attempts,
				'transport'   => sanitize_key( $transport ),
				'status'      => 'sending',
				'started_at'  => ECM_Utils::now_utc(),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	private static function finish( object $row, string $status, string $error, int $http_code, string $provider_id, string $summary, int $attempt_id = 0, int $latency_ms = 0, string $transport = '' ): void {
		global $wpdb;
		$next = null;
		if ( 'retry_wait' === $status ) {
			$delay = self::retry_delay_seconds( (int) $row->attempts );
			$next  = gmdate( 'Y-m-d H:i:s', time() + $delay );
			wp_schedule_single_event( time() + $delay, 'ecm_sms_job', array( (int) $row->id ) );
		}
		$values = array(
			'status'           => $status,
			'error_code'       => $error ?: null,
			'http_code'        => $http_code ?: null,
			'provider_id'      => $provider_id ?: null,
			'next_attempt_at'  => $next,
			'response_summary' => substr( sanitize_textarea_field( $summary ), 0, 1000 ),
			'updated_at'       => ECM_Utils::now_utc(),
		);
		$formats = array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' );
		if ( 'accepted' === $status ) {
			$values['accepted_at'] = ECM_Utils::now_utc();
			$formats[] = '%s';
		}
		$wpdb->update(
			ECM_DB::table( 'sms' ),
			$values,
			array( 'id' => (int) $row->id ),
			$formats,
			array( '%d' )
		);
		if ( $attempt_id ) {
			$wpdb->update(
				ECM_DB::table( 'sms_attempts' ),
				array( 'status' => $status, 'http_code' => $http_code ?: null, 'provider_id' => $provider_id ?: null, 'error_code' => $error ?: null, 'response_summary' => substr( sanitize_textarea_field( $summary ), 0, 1000 ), 'latency_ms' => $latency_ms, 'finished_at' => ECM_Utils::now_utc() ),
				array( 'id' => $attempt_id ),
				array( '%s', '%d', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
		}
		ECM_Diagnostics::record( 'sms', 'SMS transport finished', array( 'sms_id' => (int) $row->id, 'registration_id' => (int) $row->registration_id, 'transport' => $transport, 'status' => $status, 'error_code' => $error, 'http_code' => $http_code, 'attempts' => (int) $row->attempts, 'latency_ms' => $latency_ms ) );
		ECM_Utils::log( (int) $row->registration_id, 'sms_' . $status, array( 'sms_id' => (int) $row->id, 'provider_id' => $provider_id, 'error' => $error, 'http_code' => $http_code ) );
	}

	private static function request( string $method, string $path, ?array $payload = null, ?string $ip_mode = null, int $timeout = 20 ) {
		$transport = new ECM_SMS_Transport_Edge();
		return $transport->request( $method, $path, $payload, $ip_mode, $timeout );
	}

	private static function response_summary( array $body ): string {
		return (string) wp_json_encode(
			array( 'status' => $body['meta']['status'] ?? null, 'message_code' => $body['meta']['message_code'] ?? null, 'outbox_ids' => $body['data']['message_outbox_ids'] ?? array() ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	private static function failed_response( array $response, string $action ): array {
		$code     = (int) ( $response['code'] ?? 0 );
		$summary  = self::response_summary( (array) ( $response['decoded'] ?? array() ) );
		$category = 'provider_response';
		if ( in_array( $code, array( 401, 403 ), true ) ) {
			$category = 'authentication';
			$message  = 'IPPanel کلید را نپذیرفت یا این کلید اجازه انجام درخواست را ندارد. کلید Access Key را بدون Bearer، فاصله یا خط جدید بررسی کنید.';
		} elseif ( 422 === $code ) {
			$category = 'validation';
			$message  = 'ارتباط برقرار است، اما شماره فرستنده، گیرنده، متن یا پارامترهای پترن معتبر نیستند.';
		} elseif ( 429 === $code ) {
			$category = 'rate_limit';
			$message  = 'IPPanel به‌دلیل تعداد درخواست‌ها موقتاً ارسال را محدود کرده است؛ کمی بعد دوباره آزمایش کنید.';
		} elseif ( $code >= 500 ) {
			$category = 'provider_outage';
			$message  = 'سرویس Edge IPPanel در دسترس نیست یا دروازه بالادستی آن خطا دارد. با پاسخ HTTP ' . $code . ' نمی‌توان درستی یا نادرستی کلید را نتیجه گرفت.';
		} elseif ( $code <= 0 ) {
			$category = 'network';
			$message  = 'پاسخ HTTP معتبری دریافت نشد. DNS، فایروال خروجی هاست و دسترسی به edge.ippanel.com بررسی شود.';
		} else {
			$message = ( 'send' === $action ? 'ارسال توسط IPPanel پذیرفته نشد.' : 'اعتبارسنجی اتصال توسط IPPanel پذیرفته نشد.' );
		}
		return array(
			'success'   => false,
			'category'  => $category,
			'http_code' => $code,
			'message'   => $message . ' HTTP ' . $code . ' — ' . $summary,
		);
	}

	private static function dns_snapshot(): array {
		$output = array( 'a' => array(), 'aaaa' => array() );
		if ( function_exists( 'dns_get_record' ) ) {
			foreach ( (array) @dns_get_record( 'edge.ippanel.com', DNS_A ) as $record ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! empty( $record['ip'] ) && filter_var( $record['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					$output['a'][] = $record['ip'];
				}
			}
			if ( defined( 'DNS_AAAA' ) ) {
				foreach ( (array) @dns_get_record( 'edge.ippanel.com', DNS_AAAA ) as $record ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( ! empty( $record['ipv6'] ) && filter_var( $record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
						$output['aaaa'][] = strtolower( $record['ipv6'] );
					}
				}
			}
		}
		if ( ! $output['a'] && function_exists( 'gethostbynamel' ) ) {
			foreach ( (array) @gethostbynamel( 'edge.ippanel.com' ) as $ip ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					$output['a'][] = $ip;
				}
			}
		}
		$output['a']    = array_values( array_unique( array_slice( $output['a'], 0, 6 ) ) );
		$output['aaaa'] = array_values( array_unique( array_slice( $output['aaaa'], 0, 6 ) ) );
		return $output;
	}

	private static function tcp_probe( string $ip, string $family ): array {
		$target  = 'ipv6' === $family ? 'tcp://[' . $ip . ']:443' : 'tcp://' . $ip . ':443';
		$errno   = 0;
		$error   = '';
		$started = microtime( true );
		$socket  = function_exists( 'stream_socket_client' ) ? @stream_socket_client( $target, $errno, $error, 2.5, STREAM_CLIENT_CONNECT ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_resource( $socket ) ) {
			fclose( $socket );
			return array( 'family' => $family, 'ip' => $ip, 'success' => true, 'elapsed_ms' => $elapsed, 'error_code' => 0, 'error' => '' );
		}
		return array( 'family' => $family, 'ip' => $ip, 'success' => false, 'elapsed_ms' => $elapsed, 'error_code' => (int) $errno, 'error' => substr( sanitize_text_field( $error ?: 'TCP connection failed or stream sockets are unavailable.' ), 0, 240 ) );
	}

	private static function http_probe( string $mode ): array {
		$endpoint = '/api/number/numbers?page=1&per_page=1';
		$result   = self::request( 'GET', $endpoint, null, $mode, 7 );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return array(
				'mode'       => $mode,
				'network_ok' => false,
				'api_ok'     => false,
				'http_code'  => 0,
				'category'   => 'configuration_missing' === $result->get_error_code() ? 'configuration' : 'network',
				'elapsed_ms' => is_array( $data ) ? absint( $data['elapsed_ms'] ?? 0 ) : 0,
				'endpoint'   => $endpoint,
				'error'      => substr( sanitize_text_field( $result->get_error_message() ), 0, 300 ),
			);
		}
		$code = (int) $result['code'];
		if ( $code >= 200 && $code < 300 ) {
			$category = 'ok';
		} elseif ( in_array( $code, array( 401, 403 ), true ) ) {
			$category = 'authentication';
		} elseif ( 422 === $code ) {
			$category = 'validation';
		} elseif ( 429 === $code ) {
			$category = 'rate_limit';
		} elseif ( $code >= 500 ) {
			$category = 'provider_outage';
		} else {
			$category = 'provider_response';
		}
		return array(
			'mode'       => $mode,
			'network_ok' => $code > 0,
			'api_ok'     => 'ok' === $category,
			'http_code'  => $code,
			'category'   => $category,
			'elapsed_ms' => absint( $result['elapsed_ms'] ?? 0 ),
			'endpoint'   => $endpoint,
			'body_bytes' => absint( $result['body_bytes'] ?? 0 ),
			'headers'    => (array) ( $result['response_headers'] ?? array() ),
			'error'      => '',
		);
	}

	private static function set_transport_paused( bool $paused ): void {
		$settings = ECM_Utils::settings();
		$settings['sms_transport_paused'] = $paused ? 1 : 0;
		update_option( 'ecm_settings', $settings, false );
		self::sync_paused_jobs( $paused );
	}

	private static function sync_paused_jobs( bool $paused, bool $release_existing = false ): void {
		global $wpdb;
		$table = ECM_DB::table( 'sms' );
		if ( $paused ) {
			$wpdb->query( "UPDATE {$table} SET status='paused',error_code='transport_paused',next_attempt_at=NULL,updated_at=UTC_TIMESTAMP() WHERE status IN ('queued','retry_wait')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} elseif ( $release_existing ) {
			$transport = sanitize_key( (string) ( ECM_Utils::settings()['sms_transport'] ?? 'edge' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='queued',transport=%s,error_code=NULL,next_attempt_at=NULL,updated_at=UTC_TIMESTAMP() WHERE status='paused'", $transport ) );
			wp_schedule_single_event( time() + 1, 'ecm_sms_sweep' );
		}
	}

	private static function support_report( array $snapshot ): string {
		$target = (string) ( $snapshot['support_target'] ?? 'hosting' );
		$utc    = (string) ( $snapshot['generated_at_utc'] ?? gmdate( 'c' ) );
		$local  = ECM_Utils::utc_to_local( str_replace( 'T', ' ', substr( $utc, 0, 19 ) ) );
		$key_source = ECM_Upgrade::key_source();
		$lines  = array(
			'موضوع: خطای HTTP 502 در IPPanel Edge API — درخواست بررسی فنی',
			'',
			'سلام تیم پشتیبانی فنی IPPanel / فراز اس‌ام‌اس،',
			'',
			'خلاصه مشکل:',
			'سایت وردپرسی ما DNS مقصد را resolve می‌کند و اتصال TCP روی پورت 443 برقرار می‌شود. درخواست HTTPS نیز در هر دو حالت انتخاب خودکار IP و اجبار IPv4 به سرور Edge می‌رسد، اما endpoint رسمی با HTTP 502 پاسخ می‌دهد. بنابراین خطا پس از برقراری مسیر شبکه و در لایه Gateway/Upstream سرویس رخ می‌دهد؛ با پاسخ 502 نمی‌توان معتبر یا نامعتبر بودن Access Key را نتیجه‌گیری کرد.',
			! empty( $snapshot['transport_paused'] ) ? 'برای جلوگیری از تلاش‌های تکراری، ارسال خودکار پیامک در افزونه موقتاً متوقف شده و پیام‌های جدید با وضعیت paused نگهداری می‌شوند.' : 'ارسال خودکار پیامک در زمان تهیه این گزارش متوقف نشده است.',
			'',
			'مشخصات محیط آزمون:',
			'- Site host: ' . (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'- Test time (UTC): ' . $utc,
			'- Test time (site timezone ' . wp_timezone_string() . '): ' . $local,
			'- Plugin: Event Card Manager ' . ECM_VERSION,
			'- WordPress: ' . get_bloginfo( 'version' ),
			'- PHP: ' . PHP_VERSION,
			'- cURL: ' . ( function_exists( 'curl_version' ) ? (string) ( curl_version()['version'] ?? 'available' ) : 'not available' ),
			'- OpenSSL: ' . ( defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'unknown' ),
			'- Destination: edge.ippanel.com:443',
			'- API base: https://edge.ippanel.com/v1',
			'- Probe request: GET /v1/api/number/numbers?page=1&per_page=1',
			'- Authorization header: present; value intentionally redacted',
			'- Access Key source: ' . $key_source,
			'',
			'نتایج DNS:',
			'- A: ' . ( $snapshot['dns']['a'] ? implode( ', ', $snapshot['dns']['a'] ) : 'none' ),
			'- AAAA: ' . ( $snapshot['dns']['aaaa'] ? implode( ', ', $snapshot['dns']['aaaa'] ) : 'none' ),
			'',
			'نتایج TCP 443:',
		);
		foreach ( (array) ( $snapshot['tcp'] ?? array() ) as $probe ) {
			$lines[] = sprintf( '- %s %s: %s (%d ms)%s', strtoupper( (string) $probe['family'] ), (string) $probe['ip'], ! empty( $probe['success'] ) ? 'connected' : 'failed', (int) $probe['elapsed_ms'], empty( $probe['error'] ) ? '' : ' — ' . (string) $probe['error'] );
		}
		if ( empty( $snapshot['tcp'] ) ) {
			$lines[] = '- No resolved address was available for the TCP test.';
		}
		$lines[] = '';
		$lines[] = 'نتایج HTTPS/API:';
		foreach ( (array) ( $snapshot['http'] ?? array() ) as $probe ) {
			$lines[] = sprintf( '- %s: network=%s, HTTP=%d, category=%s, time=%d ms, response-body=%d bytes%s', strtoupper( (string) $probe['mode'] ), ! empty( $probe['network_ok'] ) ? 'ok' : 'failed', (int) $probe['http_code'], (string) $probe['category'], (int) $probe['elapsed_ms'], (int) ( $probe['body_bytes'] ?? 0 ), empty( $probe['error'] ) ? '' : ' — ' . (string) $probe['error'] );
			foreach ( (array) ( $probe['headers'] ?? array() ) as $name => $value ) {
				$lines[] = '  response-header ' . $name . ': ' . $value;
			}
		}
		$lines[] = '';
		$lines[] = 'جمع‌بندی افزونه: ' . (string) ( $snapshot['verdict'] ?? 'unknown' );
		$lines[] = 'مقصد پیشنهادی پیگیری: ' . ( 'none' === $target ? 'نیازی به تیکت نیست' : ( 'ippanel' === $target ? 'پشتیبانی فنی IPPanel / فراز اس‌ام‌اس' : ( 'configuration' === $target ? 'توسعه‌دهنده سایت / تنظیمات افزونه' : 'پشتیبانی هاست / دیتاسنتر' ) ) );
		$lines[] = '';
		if ( 'none' === $target ) {
			$lines[] = 'نتیجه: شبکه و API Key در زمان آزمون معتبر بوده‌اند. فقط در صورت تکرار خطا، همین گزارش همراه زمان رخداد ارسال شود.';
		} elseif ( 'hosting' === $target ) {
			$lines[] = 'درخواست بررسی: لطفاً DNS resolver سرور، outbound firewall، امکان اتصال خروجی PHP/cURL و TCP به پورت 443، مسیرهای IPv4/IPv6 و هرگونه محدودیت دیتاسنتر یا ModSecurity برای edge.ippanel.com را بررسی کنید. هر پاسخ HTTP—even 401/403—نشان می‌دهد مسیر شبکه برقرار است؛ خطای connect timeout پیش از احراز هویت رخ می‌دهد.';
		} elseif ( 'ippanel' === $target ) {
			$lines[] = 'درخواست‌های مشخص از پشتیبانی IPPanel:';
			$lines[] = '1) وضعیت Edge API و سرویس upstream را در زمان دقیق آزمون بررسی کنید.';
			$lines[] = '2) لاگ Gateway/Upstream مربوط به درخواست GET بالا را بررسی و در صورت وجود Request/Trace ID اعلام کنید.';
			$lines[] = '3) تأیید کنید Access Key این حساب مجوز endpoint فهرست خطوط را دارد و قالب هدر Authorization صحیح است.';
			$lines[] = '4) محدودیت IP، WAF، Rate Limit، وضعیت/اعتبار حساب و محدودیت دسترسی Edge API را بررسی کنید.';
			$lines[] = '5) اگر endpoint یا Base URL حساب ما متفاوت است، مقدار صحیح و نمونه درخواست معتبر را اعلام کنید.';
			$lines[] = 'در صورت نیاز به IP خروجی سرور، لطفاً اعلام کنید تا آن را از پشتیبانی هاست دریافت و در ادامه تیکت ارسال کنیم.';
		} else {
			$lines[] = 'درخواست بررسی: شبکه پاسخ داده است، اما تنظیمات یا اعتبارنامه نیازمند بازبینی توسعه‌دهنده است.';
		}
		$lines[] = '';
		$lines[] = 'نکته امنیتی: مقدار API Key، شماره گیرنده، متن پیامک و داده‌های شخصی کاربران عمداً در این گزارش درج نشده‌اند. لطفاً درخواست ارسال کلید کامل در تیکت نکنید؛ در صورت نیاز، روش امن بررسی یا شناسه انتهایی کلید را اعلام کنید.';
		return implode( "\n", $lines );
	}

	private static function deep_test( bool $pause_on_failure, bool $apply_ipv4 ): array {
		$dns  = self::dns_snapshot();
		$tcp  = array();
		if ( ! empty( $dns['a'][0] ) ) {
			$tcp[] = self::tcp_probe( $dns['a'][0], 'ipv4' );
		}
		if ( ! empty( $dns['aaaa'][0] ) ) {
			$tcp[] = self::tcp_probe( $dns['aaaa'][0], 'ipv6' );
		}
		$http = array( self::http_probe( 'auto' ), self::http_probe( 'ipv4' ) );
		if ( ! empty( $dns['aaaa'] ) ) {
			$http[] = self::http_probe( 'ipv6' );
		}
		$success        = false;
		$support_target = 'hosting';
		$category       = 'network';
		$verdict        = 'هیچ پاسخ HTTP از Edge API دریافت نشد؛ درخواست پیش از احراز هویت در مسیر شبکه متوقف شده است.';
		$auto           = $http[0];
		$ipv4           = $http[1];
		foreach ( $http as $probe ) {
			if ( ! empty( $probe['api_ok'] ) ) {
				$success = true;
				$category = 'ok';
				$support_target = 'none';
				$verdict = 'اتصال HTTPS و اعتبار API Key با پاسخ موفق endpoint رسمی تأیید شد.';
				break;
			}
		}
		if ( ! $success ) {
			$http_responses = array_values( array_filter( $http, static fn( array $probe ): bool => ! empty( $probe['http_code'] ) ) );
			if ( $http_responses ) {
				$chosen          = $http_responses[0];
				$category        = (string) $chosen['category'];
				$support_target  = in_array( $category, array( 'authentication', 'rate_limit', 'provider_outage', 'provider_response' ), true ) ? 'ippanel' : 'configuration';
				$verdict         = 'مسیر شبکه برقرار است، اما Edge API پاسخ موفق نداده است. دسته خطا: ' . $category . '، HTTP ' . (int) $chosen['http_code'] . '.';
			} elseif ( 'configuration' === ( $auto['category'] ?? '' ) ) {
				$category       = 'configuration';
				$support_target = 'configuration';
				$verdict        = 'کلید API تنظیم نشده و اعتبارسنجی API اجرا نشد؛ نتیجه DNS/TCP فقط وضعیت شبکه را نشان می‌دهد.';
			}
		}
		$ipv4_recommended = empty( $auto['network_ok'] ) && ! empty( $ipv4['network_ok'] );
		if ( $ipv4_recommended ) {
			$verdict .= ' مسیر IPv4 پاسخ داده ولی حالت خودکار پاسخ نداده است؛ استفاده محدود از IPv4 پیشنهاد می‌شود.';
			if ( $apply_ipv4 ) {
				$settings = ECM_Utils::settings();
				$settings['sms_ip_mode'] = 'ipv4';
				update_option( 'ecm_settings', $settings, false );
			}
		}
		$paused = ! empty( ECM_Utils::settings()['sms_transport_paused'] );
		if ( ! $success && $pause_on_failure ) {
			self::set_transport_paused( true );
			$paused = true;
		}
		$snapshot = array(
			'generated_at_utc' => gmdate( 'c' ),
			'dns'              => $dns,
			'tcp'              => $tcp,
			'http'             => $http,
			'success'          => $success,
			'category'         => $category,
			'verdict'          => $verdict,
			'support_target'   => $support_target,
			'ipv4_recommended' => $ipv4_recommended,
			'transport_paused' => $paused,
		);
		update_option( 'ecm_sms_network_snapshot', $snapshot, false );
		$report = self::support_report( $snapshot );
		return array(
			'success'        => $success,
			'category'       => $category,
			'http_code'      => max( array_map( static fn( array $probe ): int => (int) $probe['http_code'], $http ) ),
			'message'        => $verdict . ( $paused ? ' ارسال خودکار اکنون متوقف است و پیام‌های جدید با وضعیت paused نگه داشته می‌شوند.' : '' ),
			'details'        => $snapshot,
			'support_target' => $support_target,
			'support_report' => $report,
		);
	}

	public static function diagnostic_snapshot(): array {
		$snapshot = get_option( 'ecm_sms_network_snapshot', array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	public static function sweep(): void {
		$settings = ECM_Utils::settings();
		if ( ! empty( $settings['sms_transport_paused'] ) ) {
			return;
		}
		global $wpdb;
		$table = ECM_DB::table( 'sms' );
		$links = ECM_DB::table( 'links' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$links} WHERE expires_at<%s", ECM_Utils::now_utc() ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='retry_wait',error_code='worker_interrupted',next_attempt_at=%s WHERE status='sending' AND updated_at<%s", ECM_Utils::now_utc(), gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
		$batch = min( 100, max( 1, absint( $settings['sms_worker_batch'] ?? 25 ) ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status IN ('queued','retry_wait') AND (next_attempt_at IS NULL OR next_attempt_at<=%s) ORDER BY id ASC LIMIT %d", ECM_Utils::now_utc(), $batch ) );
		$started = microtime( true );
		foreach ( $ids as $id ) {
			self::send( (int) $id );
			if ( microtime( true ) - $started >= 40 ) {
				break;
			}
		}
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_ecm_sms' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'ecm_sms_save' );
		$settings = ECM_Utils::settings();
		$was_paused = ! empty( $settings['sms_transport_paused'] );
		$raw      = isset( $_POST['sms'] ) && is_array( $_POST['sms'] ) ? wp_unslash( $_POST['sms'] ) : array();
		$transport_keys = array_values( array_diff( array_keys( ECM_SMS_Transports::all() ), array( 'legacy_direct' ) ) );
		$settings['sms_enabled']  = empty( $raw['enabled'] ) ? 0 : 1;
		$settings['sms_sender']   = sanitize_text_field( $raw['sender'] ?? '' );
		$settings['sms_field']    = sanitize_key( $raw['field'] ?? 'mobile' );
		$settings['sms_mode']     = 'pattern' === ( $raw['mode'] ?? '' ) ? 'pattern' : 'webservice';
		$settings['sms_delivery'] = 'background';
		$settings['sms_transport']= in_array( sanitize_key( $raw['transport'] ?? '' ), $transport_keys, true ) ? sanitize_key( $raw['transport'] ) : ( in_array( 'gv_bridge', $transport_keys, true ) ? 'gv_bridge' : 'edge' );
		$settings['sms_ip_mode']  = 'ipv4' === ( $raw['ip_mode'] ?? '' ) ? 'ipv4' : 'auto';
		$settings['sms_transport_paused'] = empty( $raw['paused'] ) ? 0 : 1;
		$settings['sms_worker_batch'] = min( 100, max( 1, absint( $raw['worker_batch'] ?? 25 ) ) );
		$settings['sms_max_attempts'] = min( 10, max( 1, absint( $raw['max_attempts'] ?? 3 ) ) );
		$delays = array_slice( array_map( 'absint', preg_split( '/[,،\s]+/u', (string) ( $raw['retry_delays'] ?? '5,10,15' ), -1, PREG_SPLIT_NO_EMPTY ) ), 0, 9 );
		$settings['sms_retry_delays'] = $delays && count( array_filter( $delays, static fn( int $m ): bool => $m >= 1 && $m <= 180 ) ) === count( $delays ) ? implode( ',', $delays ) : '5,10,15';
		$profiles = array();
		foreach ( (array) ( $raw['profiles'] ?? array() ) as $profile_id => $profile_raw ) {
			$profile_id = sanitize_key( (string) $profile_id );
			$profile_raw = is_array( $profile_raw ) ? $profile_raw : array();
			$code = sanitize_text_field( (string) ( $profile_raw['code'] ?? '' ) );
			if ( ! $profile_id || ! $code || count( $profiles ) >= 50 ) {
				continue;
			}
			$profiles[ $profile_id ] = array(
				'label'            => sanitize_text_field( (string) ( $profile_raw['label'] ?? $profile_id ) ),
				'code'             => $code,
				'sender'           => sanitize_text_field( (string) ( $profile_raw['sender'] ?? '' ) ),
				'purpose'          => in_array( sanitize_key( (string) ( $profile_raw['purpose'] ?? 'general' ) ), array( 'general', 'registration', 'attendance', 'recovery', 'otp' ), true ) ? sanitize_key( (string) ( $profile_raw['purpose'] ?? 'general' ) ) : 'general',
				'params'           => self::parse_param_lines( (string) ( $profile_raw['params'] ?? '' ) ),
				'param_types'      => self::parse_param_types( (string) ( $profile_raw['param_types'] ?? '' ) ),
				'requires_payment' => empty( $profile_raw['requires_payment'] ) ? 0 : 1,
				'enabled'          => empty( $profile_raw['enabled'] ) ? 0 : 1,
			);
		}
		$settings['sms_pattern_profiles'] = $profiles;
		foreach ( self::states() as $state => $label ) {
			$settings[ 'sms_' . $state ]           = empty( $raw['states'][ $state ]['enabled'] ) ? 0 : 1;
			$settings[ 'sms_' . $state . '_text' ] = sanitize_textarea_field( $raw['states'][ $state ]['text'] ?? '' );
			$settings['sms_patterns'][ $state ]     = sanitize_text_field( $raw['states'][ $state ]['pattern'] ?? '' );
			$settings['sms_params'][ $state ]       = self::parse_param_lines( (string) ( $raw['states'][ $state ]['params'] ?? '' ) );
			$profile_id = sanitize_key( (string) ( $raw['states'][ $state ]['pattern_profile'] ?? '' ) );
			$settings['sms_pattern_profile'][ $state ] = isset( $profiles[ $profile_id ] ) ? $profile_id : '';
				$mode = sanitize_key( (string) ( $raw['states'][ $state ]['mode'] ?? 'inherit' ) );
				$settings['sms_state_mode'][ $state ] = $profile_id ? 'pattern' : ( in_array( $mode, array( 'inherit', 'webservice', 'pattern' ), true ) ? $mode : 'inherit' );
				$settings['sms_audience_mode'][ $state ] = 'separate' === ( $raw['states'][ $state ]['audience_mode'] ?? '' ) ? 'separate' : 'shared';
				$guest_mode = sanitize_key( (string) ( $raw['states'][ $state ]['guest_mode'] ?? 'inherit' ) );
				$guest_profile_id = sanitize_key( (string) ( $raw['states'][ $state ]['guest_pattern_profile'] ?? '' ) );
				$settings['sms_guest_mode'][ $state ] = $guest_profile_id ? 'pattern' : ( in_array( $guest_mode, array( 'inherit', 'webservice', 'pattern' ), true ) ? $guest_mode : 'inherit' );
				$settings['sms_guest_text'][ $state ] = sanitize_textarea_field( $raw['states'][ $state ]['guest_text'] ?? '' );
				$settings['sms_guest_patterns'][ $state ] = sanitize_text_field( $raw['states'][ $state ]['guest_pattern'] ?? '' );
				$settings['sms_guest_params'][ $state ] = self::parse_param_lines( (string) ( $raw['states'][ $state ]['guest_params'] ?? '' ) );
				$settings['sms_guest_pattern_profile'][ $state ] = isset( $profiles[ $guest_profile_id ] ) ? $guest_profile_id : '';
		}
		if ( ! defined( 'ECM_IPPANEL_API_KEY' ) ) {
			if ( ! empty( $_POST['remove_key'] ) ) {
				delete_option( 'ecm_sms_key' );
			} elseif ( ! empty( $_POST['api_key'] ) ) {
				try {
					update_option( 'ecm_sms_key', ECM_Upgrade::encrypt( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ), false );
				} catch ( RuntimeException $exception ) {
					wp_die( 'برای ذخیره امن کلید API، افزونه OpenSSL در PHP باید فعال باشد.' );
				}
			}
		}
		update_option( 'ecm_settings', $settings, false );
		$release_paused = empty( $settings['sms_transport_paused'] ) && ! empty( $raw['release_paused'] );
		if ( $was_paused !== (bool) $settings['sms_transport_paused'] || $release_paused ) {
			self::sync_paused_jobs( (bool) $settings['sms_transport_paused'], $release_paused );
		}
		ECM_Diagnostics::record( 'sms', 'SMS settings updated', array( 'enabled' => (bool) $settings['sms_enabled'], 'paused' => (bool) $settings['sms_transport_paused'], 'transport' => $settings['sms_transport'], 'mode' => $settings['sms_mode'], 'delivery' => $settings['sms_delivery'], 'ip_mode' => $settings['sms_ip_mode'], 'key_source' => ECM_Upgrade::key_source() ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ecm-sms', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function parse_param_lines( string $lines ): array {
		$output = array();
		foreach ( preg_split( '/\R/u', $lines ) ?: array() as $line ) {
			$parts = explode( '=', $line, 2 );
			$key   = sanitize_key( trim( $parts[0] ?? '' ) );
			if ( $key && isset( $parts[1] ) ) {
				$output[ $key ] = sanitize_text_field( trim( $parts[1] ) );
			}
		}
		return $output;
	}

	public static function parse_param_types( string $lines ): array {
		$output = array();
		foreach ( self::parse_param_lines( $lines ) as $key => $type ) {
			$type = sanitize_key( $type );
			$output[ $key ] = in_array( $type, array( 'string', 'integer', 'decimal', 'date', 'url' ), true ) ? $type : 'string';
		}
		return $output;
	}

	private static function normalize_test_result( $result, ?ECM_SMS_Transport_Interface $transport ): array {
		if ( is_wp_error( $result ) ) {
			$error_code = sanitize_key( $result->get_error_code() );
			$category   = str_contains( $error_code, 'auth' ) ? 'authentication' : ( str_contains( $error_code, 'insecure' ) || str_contains( $error_code, 'configuration' ) || str_contains( $error_code, 'configured' ) ? 'configuration' : 'transport' );
			$data       = $result->get_error_data();
			return array(
				'success'   => false,
				'category'  => $category,
				'http_code' => is_array( $data ) ? absint( $data['http_code'] ?? 0 ) : 0,
				'message'   => $result->get_error_code() . ' — ' . $result->get_error_message(),
				'transport' => $transport ? $transport->key() : 'missing',
			);
		}
		if ( ! is_array( $result ) ) {
			return array( 'success' => false, 'category' => 'transport', 'http_code' => 0, 'message' => 'درگاه پاسخ قابل پردازشی برنگرداند.', 'transport' => $transport ? $transport->key() : 'missing' );
		}
		$result['category']  = $result['category'] ?? ( ! empty( $result['success'] ) ? 'ok' : 'transport' );
		$result['transport'] = $result['transport'] ?? ( $transport ? $transport->key() : 'missing' );
		return $result;
	}

	private static function store_provider_snapshot( array $result, string $test ): array {
		$previous = get_option( 'ecm_sms_provider_snapshot', array() );
		$balance  = sanitize_text_field( (string) ( $result['balance'] ?? '' ) );
		if ( '' === $balance && is_array( $previous ) && sanitize_key( (string) ( $previous['transport'] ?? '' ) ) === sanitize_key( (string) ( $result['transport'] ?? '' ) ) ) {
			$balance = sanitize_text_field( (string) ( $previous['balance'] ?? '' ) );
		}
		$snapshot = array(
			'generated_at' => ECM_Utils::now_utc(),
			'test'         => sanitize_key( $test ),
			'transport'    => sanitize_key( (string) ( $result['transport'] ?? 'missing' ) ),
			'success'      => ! empty( $result['success'] ),
			'category'     => sanitize_key( (string) ( $result['category'] ?? 'unknown' ) ),
			'http_code'    => absint( $result['http_code'] ?? 0 ),
			'message'      => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
			'balance'      => $balance,
		);
		update_option( 'ecm_sms_provider_snapshot', $snapshot, false );
		return $snapshot;
	}

	private static function format_balance( string $balance ): string {
		$balance = str_replace( array( ',', '٬' ), '', ECM_Utils::normalize_digits( trim( $balance ) ) );
		return (string) preg_replace_callback(
			'/\d+(?:\.\d+)?/',
			static function ( array $matches ): string {
				$parts = explode( '.', $matches[0], 2 );
				$parts[0] = (string) preg_replace( '/\B(?=(\d{3})+(?!\d))/', ',', $parts[0] );
				return isset( $parts[1] ) ? $parts[0] . '.' . $parts[1] : $parts[0];
			},
			$balance
		);
	}

	public static function auto_test_ajax(): void {
		if ( ! current_user_can( 'manage_ecm_sms' ) ) {
			wp_send_json_error( array( 'message' => 'اجازه دسترسی ندارید.' ), 403 );
		}
		check_ajax_referer( 'ecm_sms_auto_test', 'nonce' );
		$settings      = ECM_Utils::settings();
		$transport_key = sanitize_key( (string) ( $settings['sms_transport'] ?? 'edge' ) );
		$transport     = ECM_SMS_Transports::get( $transport_key );
		$force         = ! empty( $_POST['force'] );
		$snapshot      = get_option( 'ecm_sms_provider_snapshot', array() );
		$generated     = is_array( $snapshot ) && ! empty( $snapshot['generated_at'] ) ? strtotime( (string) $snapshot['generated_at'] . ' UTC' ) : 0;
		if ( ! $force && is_array( $snapshot ) && in_array( $snapshot['test'] ?? '', array( 'automatic', 'connection' ), true ) && $transport_key === ( $snapshot['transport'] ?? '' ) && $generated && $generated > time() - 120 ) {
			$snapshot['balance_display'] = self::format_balance( (string) ( $snapshot['balance'] ?? '' ) );
			wp_send_json_success( $snapshot );
		}
		if ( ! $transport ) {
			$result = array( 'success' => false, 'category' => 'configuration', 'http_code' => 0, 'message' => 'درگاه انتخاب‌شده موجود نیست.', 'transport' => 'missing' );
		} else {
			$result = self::normalize_test_result( $transport->connection_test(), $transport );
		}
		$snapshot                    = self::store_provider_snapshot( $result, 'automatic' );
		$snapshot['balance_display'] = self::format_balance( (string) ( $snapshot['balance'] ?? '' ) );
		ECM_Diagnostics::record( 'sms', 'SMS automatic connection test finished', array( 'success' => ! empty( $snapshot['success'] ), 'transport' => $snapshot['transport'], 'category' => $snapshot['category'] ) );
		wp_send_json_success( $snapshot );
	}

	public static function run_test(): void {
		if ( ! current_user_can( 'manage_ecm_sms' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'ecm_sms_test' );
		$test      = sanitize_key( $_POST['test'] ?? 'connection' );
		$settings  = ECM_Utils::settings();
		$requested_transport = sanitize_key( (string) ( $_POST['test_transport'] ?? '' ) );
		$transport = ECM_SMS_Transports::get( $requested_transport ?: sanitize_key( (string) ( $settings['sms_transport'] ?? 'edge' ) ) );
		if ( ! $transport ) {
			$result = new WP_Error( 'transport_missing', 'درگاه انتخاب‌شده موجود نیست.', array( 'retryable' => false ) );
		} elseif ( 'deep' === $test && 'edge' === $transport->key() ) {
			$result = self::deep_test( ! empty( $_POST['pause_on_failure'] ), ! empty( $_POST['apply_ipv4'] ) );
		} elseif ( 'send' === $test ) {
			$result = self::test_send( $transport );
		} elseif ( 'link' === $test ) {
			$result = self::test_link_send( $transport );
		} elseif ( 'otp' === $test ) {
			$result = self::test_otp_send();
		} else {
			$result = $transport->connection_test();
		}
		$result = self::normalize_test_result( $result, $transport );
		set_transient( 'ecm_sms_test_' . get_current_user_id(), $result, 30 * MINUTE_IN_SECONDS );
		self::store_provider_snapshot( $result, $test );
		ECM_Diagnostics::record( 'sms', 'SMS manual test finished', array( 'test' => $test, 'success' => ! empty( $result['success'] ), 'category' => $result['category'] ?? 'unknown', 'http_code' => (int) ( $result['http_code'] ?? 0 ) ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ecm-sms', 'tested' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function test_otp_send() {
		$recipient = self::phone( sanitize_text_field( wp_unslash( $_POST['recipient'] ?? '' ) ) );
		$profile_id = sanitize_key( (string) ( $_POST['test_otp_profile'] ?? 'recovery_otp' ) );
		if ( ! $recipient ) {
			return new WP_Error( 'invalid_recipient', 'شماره تست معتبر نیست.' );
		}
		$code = (string) random_int( 100000, 999999 );
		$result = self::send_secure_pattern( $profile_id, $recipient, array( 'code' => $code ), array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'success' => true, 'category' => 'ok', 'http_code' => 0, 'message' => 'کد آزمایشی با پترن OTP پذیرفته شد. محتوای کد در گزارش ذخیره نشده است.', 'transport' => sanitize_key( (string) ( ECM_Utils::settings()['sms_transport'] ?? 'edge' ) ) );
	}

	public static function download_support_report(): void {
		if ( ! current_user_can( 'manage_ecm_sms' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'ecm_sms_support_report' );
		$result = get_transient( 'ecm_sms_test_' . get_current_user_id() );
		if ( ! is_array( $result ) || empty( $result['support_report'] ) ) {
			$snapshot = self::diagnostic_snapshot();
			$result   = $snapshot ? array( 'support_report' => self::support_report( $snapshot ) ) : array();
		}
		if ( empty( $result['support_report'] ) ) {
			wp_die( 'ابتدا تست تشخیصی کامل را اجرا کنید.' );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ecm-sms-support-' . gmdate( 'Ymd-His' ) . '.txt"' );
		echo (string) $result['support_report']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function test_send( ?ECM_SMS_Transport_Interface $transport = null ) {
		$settings  = ECM_Utils::settings();
		$transport = $transport ?: ECM_SMS_Transports::get( sanitize_key( (string) ( $settings['sms_transport'] ?? 'edge' ) ) );
		if ( ! $transport ) {
			return new WP_Error( 'transport_missing', 'درگاه انتخاب‌شده موجود نیست.' );
		}
		$recipient = self::phone( sanitize_text_field( wp_unslash( $_POST['recipient'] ?? '' ) ) );
		$message   = sanitize_textarea_field( wp_unslash( $_POST['test_message'] ?? 'پیام آزمایشی افزونه کارت رویداد' ) );
		$mode      = 'pattern' === sanitize_key( $_POST['test_send_mode'] ?? '' ) ? 'pattern' : 'webservice';
		if ( ! $recipient ) {
			return new WP_Error( 'invalid_recipient', 'شماره تست معتبر نیست.' );
		}
		$transport_key = $transport->key();
		$sender_raw = sanitize_text_field( (string) ( $settings['sms_sender'] ?? '' ) );
		$sender = self::phone( $sender_raw );
		if ( 'edge' !== $transport_key && ! $sender ) {
			$sender = $sender_raw;
		}
		$payload = array( 'sending_type' => 'webservice', 'from_number' => $sender, 'message' => $message, 'params' => array( 'recipients' => array( $recipient ) ) );
		if ( 'pattern' === $mode ) {
			$profile_id = sanitize_key( (string) ( $_POST['test_pattern_profile'] ?? '' ) );
			$profile = isset( $settings['sms_pattern_profiles'][ $profile_id ] ) && is_array( $settings['sms_pattern_profiles'][ $profile_id ] ) ? $settings['sms_pattern_profiles'][ $profile_id ] : array();
			if ( ! empty( $profile['sender'] ) ) {
				$profile_sender = sanitize_text_field( (string) $profile['sender'] );
				$normalized_sender = self::phone( $profile_sender );
				$sender = 'edge' === $transport_key ? $normalized_sender : ( $normalized_sender ?: $profile_sender );
			}
			$code = sanitize_text_field( (string) ( $profile['code'] ?? wp_unslash( $_POST['test_pattern_code'] ?? '' ) ) );
			if ( ! $code ) {
				return new WP_Error( 'pattern_missing', 'کد پترن تست وارد نشده است.' );
			}
			$params = self::parse_param_lines( (string) wp_unslash( $_POST['test_pattern_params'] ?? '' ) );
			if ( ! $params && ! empty( $profile['params'] ) ) {
				foreach ( (array) $profile['params'] as $key => $value ) {
					$params[ sanitize_key( (string) $key ) ] = 'string';
				}
			}
			if ( ! empty( $profile['params'] ) ) {
				$expected_keys = array_values( array_filter( array_map( 'sanitize_key', array_keys( (array) $profile['params'] ) ) ) );
				$actual_keys   = array_values( array_filter( array_map( 'sanitize_key', array_keys( $params ) ) ) );
				sort( $expected_keys );
				sort( $actual_keys );
				if ( $expected_keys !== $actual_keys ) {
					return new WP_Error( 'pattern_param_mismatch', 'نام پارامترهای تست باید دقیقاً با پترن انتخاب‌شده یکسان باشد: ' . implode( ', ', $expected_keys ) );
				}
			}
			foreach ( $params as $key => $value ) {
				if ( '' === trim( (string) $value ) || ( function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) > 40 : strlen( (string) $value ) > 40 ) ) {
					return new WP_Error( 'invalid_pattern_param', 'همه پارامترهای پترن باید مقدار داشته باشند و حداکثر ۴۰ کاراکتر باشند.' );
				}
			}
			$payload = array(
				'sending_type' => 'pattern',
				'from_number'  => $sender,
				'code'         => $code,
				'recipients'   => array( $recipient ),
				'params'       => (object) $params,
			);
		}
		if ( 'edge' === $transport_key && empty( $payload['from_number'] ) ) {
			return new WP_Error( 'invalid_sender', 'ابتدا شماره خط ارسال یا خط اختصاصی پترن را تنظیم کنید.' );
		}
			$response = $transport->send( $payload );
			self::record_test_request( 'test_' . $mode, $transport, $recipient, $message, $payload, $response );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'success'   => ! empty( $response['success'] ),
			'category'  => ! empty( $response['success'] ) ? 'ok' : 'transport',
			'http_code' => absint( $response['http_code'] ?? 0 ),
			'message'   => sanitize_text_field( (string) ( $response['message'] ?? 'پاسخی از درگاه دریافت شد.' ) ),
			'transport' => $transport->key(),
			'balance'   => sanitize_text_field( (string) ( $response['balance'] ?? '' ) ),
		);
	}

	private static function test_link_send( ?ECM_SMS_Transport_Interface $transport ) {
		if ( ! $transport ) {
			return new WP_Error( 'transport_missing', 'درگاه انتخاب‌شده موجود نیست.' );
		}
		$registration_id = absint( $_POST['test_registration_id'] ?? 0 );
		$participant_id  = absint( $_POST['test_participant_id'] ?? 0 );
		$registration    = ECM_DB::registration( $registration_id );
		if ( ! ECM_Utils::registration_is_complete( $registration ) ) {
			return new WP_Error( 'registration_invalid', 'ثبت‌نام کامل و قابل دریافت کارت پیدا نشد.' );
		}
		$participant = null;
		if ( $participant_id ) {
			$participant = ECM_DB::participant_identity( $participant_id );
			if ( ! $participant || (int) $participant->registration_id !== $registration_id ) {
				return new WP_Error( 'participant_invalid', 'شرکت‌کننده انتخاب‌شده متعلق به این ثبت‌نام نیست.' );
			}
		}
		if ( ! $participant && ! empty( $registration->primary_participant_id ) ) {
			$participant = ECM_DB::participant_identity( (int) $registration->primary_participant_id );
		}
		$recipient = self::phone( sanitize_text_field( wp_unslash( $_POST['recipient'] ?? '' ) ) );
		if ( ! $recipient ) {
			return new WP_Error( 'invalid_recipient', 'شماره تست معتبر نیست.' );
		}
		$link_participant = $participant && 'guest' === (string) $participant->participant_type ? $participant : null;
		$link = ECM_Upgrade::link( $registration, $link_participant );
		$template = sanitize_textarea_field( wp_unslash( $_POST['test_link_message'] ?? "{name} عزیز\nکارت شما آماده است:\n{card_link}\nکد پیگیری: {tracking_code}" ) );
		$message = strtr(
			$template,
			array(
				'{name}'          => $participant ? (string) $participant->full_name : '',
				'{card_link}'     => $link,
				'{tracking_code}' => $participant ? (string) $participant->public_id : (string) $registration->public_id,
			)
		);
		$settings   = ECM_Utils::settings();
		$sender_raw = sanitize_text_field( (string) ( $settings['sms_sender'] ?? '' ) );
		$sender     = self::phone( $sender_raw );
		if ( 'edge' !== $transport->key() && ! $sender ) {
			$sender = $sender_raw;
		}
		if ( ! $sender ) {
			return new WP_Error( 'invalid_sender', 'ابتدا شماره خط ارسال را ذخیره کنید.' );
		}
			$payload = array( 'sending_type' => 'webservice', 'from_number' => $sender, 'message' => $message, 'params' => array( 'recipients' => array( $recipient ) ) );
			$response = $transport->send( $payload );
			self::record_test_request( 'test_link', $transport, $recipient, $message, $payload, $response, $registration_id, $participant_id, absint( $registration->event_id ?? 0 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array( 'success' => ! empty( $response['success'] ), 'category' => ! empty( $response['success'] ) ? 'ok' : 'transport', 'http_code' => absint( $response['http_code'] ?? 0 ), 'message' => sanitize_text_field( (string) ( $response['message'] ?? 'پاسخی از درگاه دریافت شد.' ) ), 'transport' => $transport->key() );
	}

		private static function record_test_request( string $type, ECM_SMS_Transport_Interface $transport, string $recipient, string $message, array $payload, $response, int $registration_id = 0, int $participant_id = 0, int $event_id = 0 ): void {
			global $wpdb; $now = ECM_Utils::now_utc(); $success = is_array( $response ) && ! empty( $response['success'] );
			$error_code = is_wp_error( $response ) ? sanitize_key( $response->get_error_code() ) : sanitize_key( (string) ( $response['error_code'] ?? '' ) );
			$summary = is_wp_error( $response ) ? $response->get_error_message() : (string) ( $response['summary'] ?? $response['message'] ?? '' );
			$http = is_array( $response ) ? absint( $response['http_code'] ?? 0 ) : 0; $provider_id = is_array( $response ) ? sanitize_text_field( (string) ( $response['provider_id'] ?? '' ) ) : '';
			$wpdb->insert( ECM_DB::table( 'sms' ), array( 'registration_id' => $registration_id, 'participant_id' => $participant_id, 'event_id' => $event_id, 'session_id' => 0, 'notification_type' => sanitize_key( $type ), 'transport' => $transport->key(), 'event_key' => 'manual-' . wp_generate_uuid4(), 'recipient' => $recipient, 'message' => $message, 'payload' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'status' => $success ? 'accepted' : 'failed', 'provider_id' => $provider_id ?: null, 'error_code' => $error_code ?: null, 'http_code' => $http ?: null, 'attempts' => 1, 'response_summary' => sanitize_textarea_field( $summary ), 'accepted_at' => $success ? $now : null, 'created_at' => $now, 'updated_at' => $now ), array( '%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s' ) );
			if ( $wpdb->insert_id ) $wpdb->insert( ECM_DB::table( 'sms_attempts' ), array( 'sms_id' => (int) $wpdb->insert_id, 'attempt_no' => 1, 'transport' => $transport->key(), 'status' => $success ? 'accepted' : 'failed', 'http_code' => $http ?: null, 'provider_id' => $provider_id ?: null, 'error_code' => $error_code ?: null, 'response_summary' => sanitize_textarea_field( $summary ), 'latency_ms' => is_array( $response ) ? absint( $response['latency_ms'] ?? 0 ) : 0, 'started_at' => $now, 'finished_at' => $now ), array( '%d','%d','%s','%s','%d','%s','%s','%s','%d','%s','%s' ) );
		}

	public static function retry(): void {
		if ( ! current_user_can( 'manage_ecm_sms' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$id = absint( $_POST['sms_id'] ?? 0 );
		check_admin_referer( 'ecm_sms_retry_' . $id );
		global $wpdb;
		$notification_type = $wpdb->get_var( $wpdb->prepare( 'SELECT notification_type FROM ' . ECM_DB::table( 'sms' ) . ' WHERE id=%d', $id ) );
		if ( 'otp_recovery' === $notification_type ) {
			wp_die( 'کد تأیید منقضی یا ناموفق قابل ارسال مجدد از گزارش نیست؛ کاربر باید از صفحه بازیابی کد تازه دریافت کند.' );
		}
		$transport = sanitize_key( (string) ( ECM_Utils::settings()['sms_transport'] ?? 'edge' ) );
		$wpdb->update( ECM_DB::table( 'sms' ), array( 'status' => 'queued', 'transport' => $transport, 'error_code' => null, 'next_attempt_at' => null, 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => $id ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		wp_schedule_single_event( time() + 1, 'ecm_sms_job', array( $id ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ecm-sms', 'retried' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

		/** Variables shown in the editor. Event form fields are discovered dynamically. */
		private static function variable_catalog(): array {
			$variables = array(
				'{name}' => 'نام گیرنده', '{phone}' => 'موبایل گیرنده', '{host_name}' => 'نام معرف',
				'{participant_type}' => 'نوع شرکت‌کننده', '{event_name}' => 'نام رویداد', '{tracking_code}' => 'کد پیگیری کارت',
				'{card_link}' => 'لینک کوتاه کارت', '{payment_reference}' => 'شماره تراکنش', '{payment_date}' => 'زمان پرداخت',
				'{payment_status}' => 'وضعیت پرداخت', '{time}' => 'زمان جاری', '{attendance_time}' => 'زمان حضور',
				'{session_name}' => 'نام نوبت', '{session_time}' => 'زمان نوبت', '{operator_name}' => 'نام اپراتور', '{operator_code}' => 'کد اپراتور',
				'{code}' => 'کد یک‌بارمصرف؛ فقط در پترن OTP',
			);
			$field_labels = array();
			$global = ECM_Utils::settings();
			foreach ( (array) ( $global['fields'] ?? array() ) as $field ) {
				$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
				if ( $key && 'html' !== ( $field['type'] ?? '' ) ) $field_labels[ $key ] = (string) ( $field['label'] ?? $key );
			}
			foreach ( ECM_Events::all() as $event ) {
				$config = ECM_Events::config( $event );
				foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
					$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
					if ( $key && 'html' !== ( $field['type'] ?? '' ) ) $field_labels[ $key ] = (string) ( $field['label'] ?? $key );
				}
			}
			foreach ( $field_labels as $key => $label ) $variables[ '{field:' . $key . '}' ] = 'فیلد فرم: ' . $label;
			return $variables;
		}

		private static function pattern_select( string $name, string $selected, array $profiles ): void {
			?><select name="<?php echo esc_attr( $name ); ?>"><option value="">کد اختصاصی همین اعلان</option><?php foreach ( $profiles as $profile_id => $profile ) : if ( empty( $profile['enabled'] ) ) continue; ?><option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $selected, $profile_id ); ?>><?php echo esc_html( $profile['label'] ?? $profile_id ); ?></option><?php endforeach; ?></select><?php
		}

		private static function notification_editor( string $state, string $label, array $settings, array $profiles, bool $active ): void {
			$mode = sanitize_key( (string) ( $settings['sms_state_mode'][ $state ] ?? 'inherit' ) );
			$mode = in_array( $mode, array( 'inherit', 'webservice', 'pattern' ), true ) ? $mode : 'inherit';
			$audience = 'separate' === ( $settings['sms_audience_mode'][ $state ] ?? '' ) ? 'separate' : 'shared';
			$params = ''; foreach ( (array) ( $settings['sms_params'][ $state ] ?? array() ) as $key => $value ) $params .= $key . '=' . $value . "\n";
			$guest_params = ''; foreach ( (array) ( $settings['sms_guest_params'][ $state ] ?? array() ) as $key => $value ) $guest_params .= $key . '=' . $value . "\n";
			$selected_profile = sanitize_key( (string) ( $settings['sms_pattern_profile'][ $state ] ?? '' ) );
			$guest_profile = sanitize_key( (string) ( $settings['sms_guest_pattern_profile'][ $state ] ?? '' ) );
			$guest_mode = sanitize_key( (string) ( $settings['sms_guest_mode'][ $state ] ?? 'inherit' ) );
			?>
			<section class="ecm-notification-panel<?php echo $active ? ' is-active' : ''; ?>" data-sms-state-panel="<?php echo esc_attr( $state ); ?>" <?php echo $active ? '' : 'hidden'; ?>>
				<div class="ecm-panel__title"><div><h3><?php echo esc_html( $label ); ?></h3><p>روش ارسال و محتوای این رخداد مستقل از اعلان‌های دیگر ذخیره می‌شود.</p></div><label class="ecm-switch"><input type="checkbox" name="sms[states][<?php echo esc_attr( $state ); ?>][enabled]" value="1" <?php checked( ! empty( $settings[ 'sms_' . $state ] ) ); ?>><span>فعال</span></label></div>
				<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>روش ارسال این اعلان</span><select name="sms[states][<?php echo esc_attr( $state ); ?>][mode]" data-sms-mode-select><option value="inherit" <?php selected( $mode, 'inherit' ); ?>>طبق روش پیش‌فرض</option><option value="webservice" <?php selected( $mode, 'webservice' ); ?>>متن آزاد</option><option value="pattern" <?php selected( $mode, 'pattern' ); ?>>پترن تأییدشده</option></select><small>انتخاب پترن در اینجا دیگر تحت‌تأثیر روش پیش‌فرض قرار نمی‌گیرد.</small></label><label class="ecm-setting"><span>قالب معرف و مهمان</span><select name="sms[states][<?php echo esc_attr( $state ); ?>][audience_mode]" data-sms-audience-select><option value="shared" <?php selected( $audience, 'shared' ); ?>>قالب مشترک</option><option value="separate" <?php selected( $audience, 'separate' ); ?>>قالب مجزا برای مهمان</option></select><small>در حالت مجزا، پیام مهمان از بخش دوم خوانده می‌شود.</small></label></div>
				<div class="ecm-message-audience" data-sms-mode-scope><h4>قالب معرف / قالب مشترک</h4><div data-sms-free-fields><label class="ecm-setting"><span>متن پیامک</span><textarea rows="5" name="sms[states][<?php echo esc_attr( $state ); ?>][text]"><?php echo esc_textarea( $settings[ 'sms_' . $state . '_text' ] ?? '' ); ?></textarea><small>متغیرها هنگام ساخت پیام با اطلاعات همان شخص جایگزین می‌شوند.</small></label></div><div data-sms-pattern-fields><label class="ecm-setting"><span>پترن کتابخانه</span><?php self::pattern_select( 'sms[states][' . $state . '][pattern_profile]', $selected_profile, $profiles ); ?></label><div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>کد پترن اختصاصی</span><input type="text" dir="ltr" name="sms[states][<?php echo esc_attr( $state ); ?>][pattern]" value="<?php echo esc_attr( $settings['sms_patterns'][ $state ] ?? '' ); ?>"></label><label class="ecm-setting"><span>پارامترها؛ هر خط name=value</span><textarea rows="4" dir="ltr" name="sms[states][<?php echo esc_attr( $state ); ?>][params]"><?php echo esc_textarea( $params ); ?></textarea></label></div></div></div>
				<div class="ecm-message-audience ecm-message-audience--guest" data-sms-guest-fields <?php echo 'separate' === $audience ? '' : 'hidden'; ?>><h4>قالب اختصاصی مهمان</h4><div data-sms-mode-scope><label class="ecm-setting"><span>روش ارسال مهمان</span><select name="sms[states][<?php echo esc_attr( $state ); ?>][guest_mode]" data-sms-mode-select><option value="inherit" <?php selected( $guest_mode, 'inherit' ); ?>>مانند معرف</option><option value="webservice" <?php selected( $guest_mode, 'webservice' ); ?>>متن آزاد</option><option value="pattern" <?php selected( $guest_mode, 'pattern' ); ?>>پترن</option></select></label><div data-sms-free-fields><label class="ecm-setting"><span>متن مهمان</span><textarea rows="5" name="sms[states][<?php echo esc_attr( $state ); ?>][guest_text]"><?php echo esc_textarea( $settings['sms_guest_text'][ $state ] ?? '' ); ?></textarea></label></div><div data-sms-pattern-fields><label class="ecm-setting"><span>پترن مهمان</span><?php self::pattern_select( 'sms[states][' . $state . '][guest_pattern_profile]', $guest_profile, $profiles ); ?></label><div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>کد پترن مهمان</span><input type="text" dir="ltr" name="sms[states][<?php echo esc_attr( $state ); ?>][guest_pattern]" value="<?php echo esc_attr( $settings['sms_guest_patterns'][ $state ] ?? '' ); ?>"></label><label class="ecm-setting"><span>پارامترهای مهمان</span><textarea rows="4" dir="ltr" name="sms[states][<?php echo esc_attr( $state ); ?>][guest_params]"><?php echo esc_textarea( $guest_params ); ?></textarea></label></div></div></div></div>
			</section>
			<?php
		}

	public static function admin_page(): void {
		if ( ! current_user_can( 'manage_ecm_sms' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$settings               = ECM_Utils::settings();
		$selected_transport_key = sanitize_key( (string) ( $settings['sms_transport'] ?? 'edge' ) );
		$result                 = get_transient( 'ecm_sms_test_' . get_current_user_id() );
		if ( ! is_array( $result ) ) {
			$provider_snapshot = get_option( 'ecm_sms_provider_snapshot', array() );
			if ( is_array( $provider_snapshot ) && $selected_transport_key === ( $provider_snapshot['transport'] ?? '' ) ) {
				$result = $provider_snapshot;
			} elseif ( 'edge' === $selected_transport_key && ( $snapshot = self::diagnostic_snapshot() ) ) {
				$result = array( 'success' => ! empty( $snapshot['success'] ), 'category' => $snapshot['category'] ?? 'unknown', 'http_code' => 0, 'message' => $snapshot['verdict'] ?? '', 'details' => $snapshot, 'support_target' => $snapshot['support_target'] ?? 'hosting', 'support_report' => self::support_report( $snapshot ) );
			}
		}
		global $wpdb;
		$sms_table         = ECM_DB::table( 'sms' );
		$participant_table = ECM_DB::table( 'participants' );
		$people_table      = ECM_DB::table( 'people' );
		$event_table       = ECM_DB::table( 'event_definitions' );
		$session_table     = ECM_DB::table( 'sessions' );
		$logs = $wpdb->get_results( "SELECT q.id,q.registration_id,q.participant_id,q.notification_type,q.transport,q.recipient,q.status,q.provider_id,q.error_code,q.http_code,q.attempts,q.created_at,p.participant_type,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name,e.title AS event_title,s.title AS session_title FROM {$sms_table} q LEFT JOIN {$participant_table} p ON p.id=q.participant_id LEFT JOIN {$people_table} pe ON pe.id=p.person_id LEFT JOIN {$event_table} e ON e.id=q.event_id LEFT JOIN {$session_table} s ON s.id=q.session_id ORDER BY q.id DESC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$transports = ECM_SMS_Transports::all();
		$operational_transports = $transports;
		unset( $operational_transports['legacy_direct'] );
		$selected_transport = ECM_SMS_Transports::get( $selected_transport_key );
		$transport_diagnostics = $selected_transport ? $selected_transport->diagnostics() : array( 'available' => false, 'configured' => false, 'secure' => false, 'note' => 'درگاه انتخاب‌شده موجود نیست.' );
		$queue_stats = $wpdb->get_results( 'SELECT status,COUNT(*) AS total FROM ' . ECM_DB::table( 'sms' ) . ' GROUP BY status', OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<div class="wrap ecm-admin" dir="rtl">
			<div class="ecm-admin__header"><div><span>ECM NOTIFICATION CENTER</span><h1>پیامک و اتصال</h1><p>صف مستقل افزونه، درگاه قابل تعویض و گزارش امن ارسال‌ها.</p></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ecm-tools' ) ); ?>">عیب‌یابی</a></div>
			<div class="ecm-sms-auto-health" data-ecm-sms-auto-test data-nonce="<?php echo esc_attr( wp_create_nonce( 'ecm_sms_auto_test' ) ); ?>">
				<div><span class="ecm-sms-auto-health__pulse" aria-hidden="true"></span><div><strong data-auto-test-title>بررسی خودکار اتصال درگاه</strong><p data-auto-test-message>در حال دریافت پاسخ امن از درگاه فعال…</p></div></div>
				<div class="ecm-action-row"><span class="ecm-badge" data-auto-test-balance hidden></span><button type="button" class="button" data-auto-test-retry hidden>تلاش مجدد</button></div>
			</div>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p>تنظیمات پیامک ذخیره شد.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['tested'] ) && is_array( $result ) ) : ?><div class="notice <?php echo empty( $result['success'] ) ? 'notice-error' : 'notice-success'; ?>"><p><strong><?php echo empty( $result['success'] ) ? 'آزمون ناموفق بود.' : 'آزمون موفق بود.'; ?></strong> <?php echo esc_html( (string) ( $result['message'] ?? '' ) ); ?><?php if ( ! empty( $result['http_code'] ) ) : ?> <bdi>HTTP <?php echo esc_html( absint( $result['http_code'] ) ); ?></bdi><?php endif; ?></p></div><?php endif; ?>
				<details class="ecm-sms-compact-status"><summary><span><strong><?php echo esc_html( $selected_transport ? $selected_transport->label() : 'درگاه نامعتبر' ); ?></strong><small><?php echo empty( $settings['sms_enabled'] ) ? 'سرویس پیامک غیرفعال' : ( empty( $settings['sms_transport_paused'] ) ? 'سرویس فعال و صف در حال اجرا' : 'سرویس فعال؛ صف متوقف است' ); ?></small></span><span class="ecm-status-pills"><b><?php echo esc_html( number_format_i18n( (int) ( $queue_stats['queued']->total ?? 0 ) + (int) ( $queue_stats['retry_wait']->total ?? 0 ) ) ); ?> در صف</b><b class="is-danger"><?php echo esc_html( number_format_i18n( (int) ( $queue_stats['failed']->total ?? 0 ) ) ); ?> ناموفق</b></span></summary><div class="ecm-compact-facts"><span>آمادگی: <strong><?php echo ! empty( $transport_diagnostics['available'] ) && ! empty( $transport_diagnostics['configured'] ) && ! empty( $transport_diagnostics['secure'] ) ? 'آماده' : 'نیازمند بررسی'; ?></strong></span><span>پذیرفته‌شده: <strong><?php echo esc_html( number_format_i18n( (int) ( $queue_stats['accepted']->total ?? 0 ) ) ); ?></strong></span><span>متوقف‌شده: <strong><?php echo esc_html( number_format_i18n( (int) ( $queue_stats['paused']->total ?? 0 ) ) ); ?></strong></span><span>Worker: <strong>هر دقیقه</strong></span></div></details>
				<nav class="ecm-sms-section-nav" aria-label="دسترسی سریع مرکز پیامک"><a href="#ecm-sms-workspace">تنظیمات</a><a href="#ecm-sms-tests">مرکز آزمون</a><a href="#ecm-sms-logs">ارسال‌های اخیر</a><a href="<?php echo esc_url( ECM_Reports::url( 'sms', array(), true ) ); ?>">گزارش جامع</a></nav>
			<details class="ecm-panel is-active ecm-sms-provider-status ecm-advanced-box">
				<summary>جزئیات درگاه‌ها و بررسی‌های اتصال</summary><div class="ecm-advanced-box__body"><p>وجود افزونه، کامل بودن تنظیمات و امن بودن endpoint بدون نمایش رمز بررسی می‌شود.</p>
				<div class="ecm-provider-grid">
				<?php foreach ( $transports as $transport_key => $transport_item ) : $diagnostics = $transport_item->diagnostics(); ?>
					<article class="ecm-provider-card<?php echo $selected_transport_key === $transport_key ? ' is-selected' : ''; ?>">
						<div><span class="ecm-badge ecm-badge--<?php echo ! empty( $diagnostics['available'] ) && ! empty( $diagnostics['configured'] ) && ! empty( $diagnostics['secure'] ) ? 'accepted' : 'failed'; ?>"><?php echo ! empty( $diagnostics['available'] ) ? 'شناسایی شد' : 'در دسترس نیست'; ?></span><h3><?php echo esc_html( $transport_item->label() ); ?></h3></div>
						<dl><div><dt>پیکربندی</dt><dd><?php echo empty( $diagnostics['configured'] ) ? 'ناقص' : 'کامل'; ?></dd></div><div><dt>ارتباط امن</dt><dd><?php echo empty( $diagnostics['secure'] ) ? 'نیازمند اصلاح' : 'HTTPS'; ?></dd></div><?php if ( ! empty( $diagnostics['plugin_version'] ) ) : ?><div><dt>نسخه</dt><dd><?php echo esc_html( $diagnostics['plugin_version'] ); ?></dd></div><?php endif; ?></dl>
						<p><?php echo esc_html( (string) ( $diagnostics['note'] ?? '' ) ); ?></p>
					</article>
				<?php endforeach; ?>
				</div></div>
			</details>
			<?php if ( is_array( $result ) && ! empty( $result['details'] ) ) : $details = (array) $result['details']; ?>
			<section class="ecm-panel is-active ecm-network-result">
				<div class="ecm-panel__title"><div><h2>نتیجه تشخیص چندمرحله‌ای</h2><p>این گزارش API Key، شماره تلفن یا متن پیامک را نمایش نمی‌دهد.</p></div><span class="ecm-badge ecm-badge--<?php echo empty( $result['success'] ) ? 'failed' : 'accepted'; ?>"><?php echo esc_html( 'none' === ( $result['support_target'] ?? '' ) ? 'اتصال تأیید شد' : ( 'hosting' === ( $result['support_target'] ?? '' ) ? 'پیگیری از هاست' : ( 'ippanel' === ( $result['support_target'] ?? '' ) ? 'پیگیری از IPPanel' : 'بررسی تنظیمات' ) ) ); ?></span></div>
				<div class="ecm-admin-grid ecm-admin-grid--2 ecm-network-summary"><div><strong>DNS A</strong><code><?php echo esc_html( ! empty( $details['dns']['a'] ) ? implode( ', ', $details['dns']['a'] ) : 'بدون نتیجه' ); ?></code></div><div><strong>DNS AAAA</strong><code><?php echo esc_html( ! empty( $details['dns']['aaaa'] ) ? implode( ', ', $details['dns']['aaaa'] ) : 'بدون نتیجه' ); ?></code></div></div>
				<div class="ecm-table-scroll"><table class="widefat ecm-admin-table ecm-network-table"><thead><tr><th>آزمون</th><th>مسیر</th><th>نتیجه شبکه</th><th>HTTP</th><th>دسته</th><th>زمان</th><th>خطا</th></tr></thead><tbody>
				<?php foreach ( (array) ( $details['tcp'] ?? array() ) as $probe ) : ?><tr><td>TCP 443</td><td><bdi><?php echo esc_html( strtoupper( $probe['family'] ) . ' — ' . $probe['ip'] ); ?></bdi></td><td><?php echo empty( $probe['success'] ) ? 'ناموفق' : 'موفق'; ?></td><td>—</td><td>transport</td><td><?php echo esc_html( (int) $probe['elapsed_ms'] ); ?> ms</td><td><?php echo esc_html( $probe['error'] ?: '—' ); ?></td></tr><?php endforeach; ?>
				<?php foreach ( (array) ( $details['http'] ?? array() ) as $probe ) : ?><tr><td>HTTPS/API</td><td><?php echo esc_html( strtoupper( $probe['mode'] ) ); ?></td><td><?php echo empty( $probe['network_ok'] ) ? 'ناموفق' : 'موفق'; ?></td><td><?php echo esc_html( $probe['http_code'] ?: '—' ); ?></td><td><?php echo esc_html( $probe['category'] ); ?></td><td><?php echo esc_html( (int) $probe['elapsed_ms'] ); ?> ms</td><td><?php echo esc_html( $probe['error'] ?: '—' ); ?></td></tr><?php endforeach; ?>
				</tbody></table></div>
				<?php if ( ! empty( $result['support_report'] ) ) : ?><div class="ecm-support-report"><label class="ecm-setting"><span>متن آماده برای پشتیبانی</span><textarea id="ecm-support-report" rows="14" readonly><?php echo esc_textarea( $result['support_report'] ); ?></textarea></label><div class="ecm-action-row"><button type="button" class="button" data-copy-support data-copy-done="کپی شد">کپی متن گزارش</button><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ecm_sms_support_report' ), 'ecm_sms_support_report' ) ); ?>">دانلود فایل گزارش</a></div></div><?php endif; ?>
			</section>
			<?php endif; ?>

				<form id="ecm-sms-workspace" class="ecm-panel is-active ecm-sms-workspace" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-sms-workspace>
					<input type="hidden" name="action" value="ecm_sms_save"><?php wp_nonce_field( 'ecm_sms_save' ); ?>
					<div class="ecm-panel__title"><div><h2>تنظیمات پیامک</h2><p>اتصال، اعلان‌ها و پترن‌ها در سه بخش مستقل؛ همه تغییرات با دکمه پایین صفحه ذخیره می‌شوند.</p></div><label class="ecm-switch"><input type="checkbox" name="sms[enabled]" value="1" <?php checked( ! empty( $settings['sms_enabled'] ) ); ?>><span>سرویس پیامک فعال</span></label></div>
					<nav class="ecm-sms-workspace-tabs" role="tablist" aria-label="تنظیمات پیامک"><button type="button" class="is-active" data-sms-settings-tab="connection" role="tab">اتصال و صف</button><button type="button" data-sms-settings-tab="notifications" role="tab">اعلان‌ها</button><button type="button" data-sms-settings-tab="patterns" role="tab">کتابخانه پترن</button></nav>

					<section class="ecm-sms-settings-panel is-active" data-sms-settings-panel="connection">
						<div class="ecm-section-intro"><div><h3>اتصال و پردازش</h3><p>درگاه عملیاتی و رفتار صف را تنظیم کنید. کلید API هرگز در صفحه یا گزارش نمایش داده نمی‌شود.</p></div></div>
						<div class="ecm-admin-grid ecm-admin-grid--2">
							<label class="ecm-setting"><span>درگاه ارسال عملیاتی</span><select name="sms[transport]"><?php foreach ( $operational_transports as $transport_key => $transport_item ) : ?><option value="<?php echo esc_attr( $transport_key ); ?>" <?php selected( $selected_transport_key, $transport_key ); ?>><?php echo esc_html( $transport_item->label() ); ?></option><?php endforeach; ?></select><small>مسیر واسط GV برای هاست خارج از ایران پیشنهاد می‌شود؛ Edge به IP ایران نیاز دارد.</small></label>
							<label class="ecm-setting"><span>روش پیش‌فرض اعلان‌ها</span><select name="sms[mode]"><option value="webservice" <?php selected( $settings['sms_mode'], 'webservice' ); ?>>متن آزاد</option><option value="pattern" <?php selected( $settings['sms_mode'], 'pattern' ); ?>>پترن</option></select><small>هر اعلان می‌تواند این مقدار را مستقل بازنویسی کند.</small></label>
							<label class="ecm-setting"><span>شماره خط ارسال</span><input type="text" dir="ltr" name="sms[sender]" value="<?php echo esc_attr( $settings['sms_sender'] ); ?>" placeholder="+983000..."><small>شماره خط تأییدشده در پنل پیامکی؛ در واسط GV همان تنظیم افزونه واسط استفاده می‌شود.</small></label>
							<label class="ecm-setting"><span>فیلد موبایل پیش‌فرض</span><select name="sms[field]"><?php foreach ( (array) $settings['fields'] as $field ) : if ( 'html' === ( $field['type'] ?? '' ) ) continue; ?><option value="<?php echo esc_attr( $field['key'] ); ?>" <?php selected( $settings['sms_field'], $field['key'] ); ?>><?php echo esc_html( $field['label'] . ' — ' . $field['key'] ); ?></option><?php endforeach; ?></select><small>رویدادها فیلد موبایل اختصاصی خود را دارند؛ این مقدار برای داده‌های قدیمی است.</small></label>
							<label class="ecm-setting"><span>API Key جدید</span><input type="password" name="api_key" value="" autocomplete="new-password" <?php disabled( defined( 'ECM_IPPANEL_API_KEY' ) ); ?>><small><?php echo defined( 'ECM_IPPANEL_API_KEY' ) ? 'کلید امن از wp-config.php خوانده می‌شود.' : 'فقط برای اتصال مستقیم Edge؛ خالی یعنی کلید قبلی حفظ شود.'; ?></small></label>
							<label class="ecm-setting"><span>مسیر شبکه Edge</span><select name="sms[ip_mode]"><option value="auto" <?php selected( $settings['sms_ip_mode'] ?? 'auto', 'auto' ); ?>>خودکار</option><option value="ipv4" <?php selected( $settings['sms_ip_mode'] ?? 'auto', 'ipv4' ); ?>>اجبار IPv4</option></select><small>فقط درخواست‌های مستقیم IPPanel را تحت تأثیر قرار می‌دهد.</small></label>
							<label class="ecm-setting"><span>تعداد پردازش در هر Worker</span><input type="number" min="1" max="100" name="sms[worker_batch]" value="<?php echo esc_attr( absint( $settings['sms_worker_batch'] ?? 25 ) ); ?>"><small>برای بار بالا مقدار ۲۵ تا ۵۰ مناسب است؛ محدودیت سرویس‌دهنده را در نظر بگیرید.</small></label>
							<label class="ecm-setting"><span>حداکثر تلاش خودکار (شامل ارسال اول)</span><input type="number" min="1" max="10" name="sms[max_attempts]" value="<?php echo esc_attr( absint( $settings['sms_max_attempts'] ?? 3 ) ); ?>"><small>برای ۳ بار تلاش مجدد، مقدار ۴ تنظیم شود.</small></label><label class="ecm-setting"><span>فاصله تلاش‌های مجدد (دقیقه)</span><input type="text" dir="ltr" name="sms[retry_delays]" value="<?php echo esc_attr( (string) ( $settings['sms_retry_delays'] ?? '5,10,15' ) ); ?>" placeholder="5,10,15"><small>فواصل پس از تلاش اول، دوم و سوم. حد مجاز هر فاصله ۱ تا ۱۸۰ دقیقه است.</small></label>
						</div>
						<div class="ecm-sms-safety"><label class="ecm-switch"><input type="checkbox" name="sms[paused]" value="1" <?php checked( ! empty( $settings['sms_transport_paused'] ) ); ?>><span>ارسال خودکار موقتاً متوقف باشد</span></label><label><input type="checkbox" name="sms[release_paused]" value="1"> پس از فعال‌سازی، رکوردهای متوقف‌شده قبلی نیز آزاد شوند</label><?php if ( ! defined( 'ECM_IPPANEL_API_KEY' ) ) : ?><label><input type="checkbox" name="remove_key" value="1"> کلید ذخیره‌شده Edge حذف شود</label><?php endif; ?></div>
					</section>

					<section class="ecm-sms-settings-panel" data-sms-settings-panel="notifications" hidden>
						<div class="ecm-section-intro"><div><h3>اعلان‌ها و قالب‌ها</h3><p>نوع اعلان را انتخاب کنید؛ فعال‌سازی، روش ارسال و قالب معرف/مهمان همان‌جا تنظیم می‌شود.</p></div></div>
						<div class="ecm-variable-library"><div><strong>متغیرهای قابل استفاده</strong><small>برای کپی روی هر متغیر کلیک کنید.</small></div><div class="ecm-variable-chips"><?php foreach ( self::variable_catalog() as $token => $description ) : ?><button type="button" data-copy-variable="<?php echo esc_attr( $token ); ?>" title="<?php echo esc_attr( $description ); ?>"><code><?php echo esc_html( $token ); ?></code><span><?php echo esc_html( $description ); ?></span></button><?php endforeach; ?></div></div>
						<div class="ecm-notification-layout"><nav class="ecm-notification-tabs" role="tablist"><?php $first_state = true; foreach ( self::states() as $state => $label ) : ?><button type="button" class="<?php echo $first_state ? 'is-active' : ''; ?>" data-sms-state-tab="<?php echo esc_attr( $state ); ?>"><span><?php echo esc_html( $label ); ?></span><b class="<?php echo empty( $settings[ 'sms_' . $state ] ) ? 'is-off' : 'is-on'; ?>"><?php echo empty( $settings[ 'sms_' . $state ] ) ? 'خاموش' : 'فعال'; ?></b></button><?php $first_state = false; endforeach; ?></nav><div class="ecm-notification-content"><?php $first_state = true; foreach ( self::states() as $state => $label ) { self::notification_editor( $state, $label, $settings, (array) ( $settings['sms_pattern_profiles'] ?? array() ), $first_state ); $first_state = false; } ?></div></div>
					</section>

					<section class="ecm-sms-settings-panel" data-sms-settings-panel="patterns" hidden>
						<div class="ecm-panel__title"><div><h3>کتابخانه پترن‌ها</h3><p>هر پترن یک‌بار تعریف می‌شود و در هر اعلان، رویداد یا نوبت قابل انتخاب است.</p></div><button type="button" class="button button-primary" data-add-sms-pattern>افزودن پترن</button></div>
						<div class="ecm-pattern-library" data-sms-pattern-list="workspace">
						<?php foreach ( (array) ( $settings['sms_pattern_profiles'] ?? array() ) as $profile_id => $profile ) :
							$profile_params = ''; foreach ( (array) ( $profile['params'] ?? array() ) as $key => $value ) { $profile_params .= $key . '=' . $value . "\n"; }
							$profile_types = ''; foreach ( (array) ( $profile['param_types'] ?? array() ) as $key => $value ) { $profile_types .= $key . '=' . $value . "\n"; }
						?>
						<details class="ecm-pattern-card" data-sms-pattern><summary><span><strong><?php echo esc_html( $profile['label'] ?? $profile_id ); ?></strong><code><?php echo esc_html( $profile['code'] ?? '' ); ?></code></span><span class="ecm-status-pills"><b><?php echo empty( $profile['enabled'] ) ? 'غیرفعال' : 'فعال'; ?></b><?php if ( 'otp' === ( $profile['purpose'] ?? '' ) ) : ?><b>OTP</b><?php endif; ?><?php if ( ! empty( $profile['requires_payment'] ) ) : ?><b>مالی</b><?php endif; ?></span></summary><div class="ecm-pattern-card__body">
							<div class="ecm-pattern-card__head"><code><?php echo esc_html( $profile_id ); ?></code><button type="button" class="button-link-delete" data-remove-sms-pattern>حذف</button></div>
							<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>عنوان پترن</span><input type="text" name="sms[profiles][<?php echo esc_attr( $profile_id ); ?>][label]" value="<?php echo esc_attr( $profile['label'] ?? '' ); ?>"></label><label class="ecm-setting"><span>کد پترن</span><input type="text" dir="ltr" name="sms[profiles][<?php echo esc_attr( $profile_id ); ?>][code]" value="<?php echo esc_attr( $profile['code'] ?? '' ); ?>" required></label></div>
							<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>کاربرد پترن</span><select name="sms[profiles][<?php echo esc_attr( $profile_id ); ?>][purpose]"><option value="general" <?php selected( $profile['purpose'] ?? 'general', 'general' ); ?>>عمومی</option><option value="registration" <?php selected( $profile['purpose'] ?? '', 'registration' ); ?>>ثبت‌نام</option><option value="attendance" <?php selected( $profile['purpose'] ?? '', 'attendance' ); ?>>حضور</option><option value="recovery" <?php selected( $profile['purpose'] ?? '', 'recovery' ); ?>>بازیابی</option><option value="otp" <?php selected( $profile['purpose'] ?? '', 'otp' ); ?>>کد تأیید OTP</option></select><small>پترن OTP فقط برای تأیید بازیابی استفاده می‌شود.</small></label><label class="ecm-setting"><span>خط ارسال اختصاصی</span><input type="text" dir="ltr" name="sms[profiles][<?php echo esc_attr( $profile_id ); ?>][sender]" value="<?php echo esc_attr( $profile['sender'] ?? '' ); ?>" placeholder="+983000505"><small>خالی یعنی استفاده از خط پیش‌فرض.</small></label></div>
							<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>نگاشت پارامترها؛ هر خط name=value</span><textarea rows="5" dir="ltr" name="sms[profiles][<?php echo esc_attr( $profile_id ); ?>][params]"><?php echo esc_textarea( $profile_params ); ?></textarea><small>نمونه: <code>name={name}</code> یا برای OTP: <code>code={code}</code>.</small></label><label class="ecm-setting"><span>نوع پارامترها؛ هر خط name=type</span><textarea rows="5" dir="ltr" name="sms[profiles][<?php echo esc_attr( $profile_id ); ?>][param_types]"><?php echo esc_textarea( $profile_types ); ?></textarea><small>انواع مجاز: string، integer، decimal، date، url.</small></label></div>
							<div class="ecm-check-grid"><label><input type="checkbox" name="sms[profiles][<?php echo esc_attr( $profile_id ); ?>][enabled]" value="1" <?php checked( ! empty( $profile['enabled'] ) ); ?>> فعال</label><label><input type="checkbox" name="sms[profiles][<?php echo esc_attr( $profile_id ); ?>][requires_payment]" value="1" <?php checked( ! empty( $profile['requires_payment'] ) ); ?>> نیازمند پرداخت موفق</label></div>
						</div></details><?php endforeach; ?></div>
					</section>
					<div class="ecm-savebar"><button class="button button-primary button-hero" type="submit">ذخیره همه تنظیمات پیامک</button><span>تغییرات هر سه تب هم‌زمان ذخیره می‌شوند.</span></div>
				</form>

			<section id="ecm-sms-tests" class="ecm-sms-test-center"><div class="ecm-panel__title"><div><h2>مرکز آزمون</h2><p>هر روش را مستقل تست کنید؛ انتخاب اینجا درگاه عملیاتی ذخیره‌شده را تغییر نمی‌دهد.</p></div></div>
			<div class="ecm-admin-grid ecm-admin-grid--3 ecm-test-grid">
				<form class="ecm-panel is-active" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ecm_sms_test"><input type="hidden" name="test" value="connection"><?php wp_nonce_field('ecm_sms_test'); ?><h2>۱. اتصال و موجودی</h2><label class="ecm-setting"><span>روش آزمون</span><select name="test_transport"><?php foreach($transports as $key=>$item): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($selected_transport_key,$key); ?>><?php echo esc_html($item->label()); ?></option><?php endforeach; ?></select></label><button class="button button-primary">اجرای آزمون</button></form>
				<form class="ecm-panel is-active" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ecm_sms_test"><input type="hidden" name="test" value="send"><?php wp_nonce_field('ecm_sms_test'); ?><h2>۲. متن یا پترن</h2><label class="ecm-setting"><span>درگاه</span><select name="test_transport"><?php foreach($transports as $key=>$item): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($selected_transport_key,$key); ?>><?php echo esc_html($item->label()); ?></option><?php endforeach; ?></select></label><label class="ecm-setting"><span>شماره گیرنده</span><input type="tel" dir="ltr" name="recipient" placeholder="09xxxxxxxxx" required></label><label class="ecm-setting"><span>روش تست</span><select name="test_send_mode"><option value="webservice">متن آزاد</option><option value="pattern">پترن</option></select></label><label class="ecm-setting"><span>متن آزاد</span><textarea name="test_message" rows="2">پیام آزمایشی افزونه کارت رویداد</textarea></label><label class="ecm-setting"><span>پترن کتابخانه</span><select name="test_pattern_profile"><option value="">کد دستی</option><?php foreach((array)($settings['sms_pattern_profiles']??array()) as $pid=>$profile): ?><option value="<?php echo esc_attr($pid); ?>"><?php echo esc_html($profile['label']??$pid); ?></option><?php endforeach; ?></select></label><label class="ecm-setting"><span>کد پترن</span><input type="text" dir="ltr" name="test_pattern_code" value="is35i844i289qa9"></label><label class="ecm-setting"><span>پارامترهای تست</span><textarea name="test_pattern_params" dir="ltr" rows="5">name=کاربر آزمایشی&#10;phone=09120000000&#10;pay=TEST-12345&#10;date=1405/07/27 21:00</textarea></label><button class="button button-primary">ارسال آزمایشی</button></form>
				<form class="ecm-panel is-active" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ecm_sms_test"><input type="hidden" name="test" value="link"><?php wp_nonce_field('ecm_sms_test'); ?><h2>۳. لینک واقعی کارت</h2><label class="ecm-setting"><span>درگاه</span><select name="test_transport"><?php foreach($transports as $key=>$item): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($selected_transport_key,$key); ?>><?php echo esc_html($item->label()); ?></option><?php endforeach; ?></select></label><label class="ecm-setting"><span>شماره گیرنده</span><input type="tel" dir="ltr" name="recipient" required></label><label class="ecm-setting"><span>شناسه ثبت‌نام</span><input type="number" min="1" name="test_registration_id" required></label><label class="ecm-setting"><span>شناسه شرکت‌کننده؛ برای مهمان</span><input type="number" min="1" name="test_participant_id"><small>خالی یعنی کارت معرف و همه مهمانان.</small></label><label class="ecm-setting"><span>متن پیام</span><textarea rows="4" name="test_link_message">{name} عزیز&#10;کارت شما آماده است:&#10;{card_link}&#10;کد پیگیری: {tracking_code}</textarea></label><button class="button button-primary">ارسال لینک</button></form>
				<form class="ecm-panel is-active ecm-test-otp" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ecm_sms_test"><input type="hidden" name="test" value="otp"><?php wp_nonce_field('ecm_sms_test'); ?><h2>۴. پترن OTP</h2><p>یک کد شش‌رقمی تصادفی با مسیر عملیاتی ذخیره‌شده ارسال می‌شود و خود کد در گزارش ذخیره نخواهد شد.</p><label class="ecm-setting"><span>شماره گیرنده</span><input type="tel" dir="ltr" name="recipient" placeholder="09xxxxxxxxx" required></label><label class="ecm-setting"><span>پترن OTP</span><select name="test_otp_profile"><?php foreach((array)($settings['sms_pattern_profiles']??array()) as $pid=>$profile): if(empty($profile['enabled'])||!in_array($profile['purpose']??'general',array('general','otp'),true))continue; ?><option value="<?php echo esc_attr($pid); ?>" <?php selected($pid,'recovery_otp'); ?>><?php echo esc_html($profile['label']??$pid); ?></option><?php endforeach; ?></select></label><button class="button button-primary">ارسال کد آزمایشی</button></form>
			</div><details class="ecm-advanced-box"><summary>تشخیص عمیق Edge</summary><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ecm_sms_test"><input type="hidden" name="test" value="deep"><input type="hidden" name="test_transport" value="edge"><?php wp_nonce_field('ecm_sms_test'); ?><p>این تست فقط برای Edge است و با IP خارجی هاست ممکن است شکست بخورد.</p><label><input type="checkbox" name="pause_on_failure" value="1"> در صورت شکست، صف خودکار متوقف شود</label> <button class="button">اجرای تشخیص</button></form></details></section>

			<section id="ecm-sms-logs" class="ecm-panel is-active"><div class="ecm-panel__title"><div><h2>۵۰ ارسال اخیر</h2><p>accepted یعنی درگاه درخواست را پذیرفته است؛ در واسط‌های قدیمی الزاماً به معنی تحویل نهایی نیست.</p></div></div><div class="ecm-table-scroll"><table class="widefat striped ecm-admin-table"><thead><tr><th>ID</th><th>شخص</th><th>رویداد / نوبت</th><th>نوع</th><th>درگاه</th><th>گیرنده</th><th>وضعیت</th><th>HTTP</th><th>شناسه/خطا</th><th>تلاش</th><th>زمان</th><th>عملیات</th></tr></thead><tbody><?php if ( ! $logs ) : ?><tr><td colspan="12">هنوز گزارشی وجود ندارد.</td></tr><?php endif; ?><?php foreach ( $logs as $log ) : ?><tr><td><?php echo esc_html( $log->id ); ?></td><td><strong><?php echo esc_html( $log->full_name ?: 'ثبت‌نام #' . $log->registration_id ); ?></strong><br><small><?php echo esc_html( 'guest' === ( $log->participant_type ?? '' ) ? 'مهمان' : ( $log->participant_id ? 'معرف' : 'سیستمی' ) ); ?><?php echo $log->participant_id ? ' · P' . esc_html( $log->participant_id ) : ''; ?></small></td><td><?php echo esc_html( $log->event_title ?: '—' ); ?><?php if ( $log->session_title ) : ?><br><small><?php echo esc_html( $log->session_title ); ?></small><?php endif; ?></td><td><?php echo esc_html( self::notification_label( (string) ( $log->notification_type ?: 'قدیمی' ) ) ); ?></td><td><?php echo esc_html( $log->transport ?: 'edge' ); ?></td><td><bdi><?php echo esc_html( $log->recipient ); ?></bdi></td><td><span class="ecm-badge ecm-badge--<?php echo esc_attr( $log->status ); ?>"><?php echo esc_html( $log->status ); ?></span></td><td><?php echo esc_html( $log->http_code ?: '—' ); ?></td><td><?php echo esc_html( $log->provider_id ?: $log->error_code ?: '—' ); ?></td><td><?php echo esc_html( $log->attempts ); ?></td><td><?php echo esc_html( ECM_Utils::utc_to_local( $log->created_at ) ); ?></td><td><?php if ( 'otp_recovery' !== $log->notification_type && in_array( $log->status, array( 'failed', 'unknown' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ecm_sms_retry"><input type="hidden" name="sms_id" value="<?php echo esc_attr( $log->id ); ?>"><?php wp_nonce_field( 'ecm_sms_retry_' . $log->id ); ?><button class="button button-small">تلاش مجدد</button></form><?php else : ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
		</div>
		<?php
	}
}
