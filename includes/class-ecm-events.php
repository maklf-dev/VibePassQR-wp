<?php

defined( 'ABSPATH' ) || exit;

/**
 * Event definitions, participant identity and per-event operator access.
 *
 * The multi-event data model is additive. Legacy registrations and QR
 * payloads remain authoritative while each registration is linked to a
 * primary person and participant in the background.
 */
final class ECM_Events {
	private const MIGRATION_HOOK = 'ecm_migrate_participants';
	private const CONFIG_KEYS = array(
		'registration_process', 'form_intro', 'currency_label', 'amount', 'rial_rate', 'currency_decimals',
		'payment_mode', 'payment_required', 'payment_button', 'price_label',
		'recovery_link_text', 'recovery_title', 'recovery_intro', 'recovery_button',
		'recovery_not_found', 'success_text', 'card_ready_title', 'home_link_text',
		'print_button', 'card_print_mode', 'card_print_html', 'card_access_mode', 'card_intro_pdf_url',
		'recovery_otp_enabled', 'recovery_otp_pattern_profile', 'recovery_otp_expiry', 'recovery_otp_resend', 'recovery_otp_max_attempts', 'recovery_otp_max_sends',
		'recovery_otp_title', 'recovery_otp_intro', 'recovery_otp_send_button', 'recovery_otp_verify_title', 'recovery_otp_verify_intro', 'recovery_otp_verify_button', 'recovery_otp_resend_button',
		'recovery_otp_sent_message', 'recovery_otp_invalid_message', 'recovery_otp_expired_message', 'recovery_otp_send_failed_message', 'recovery_otp_rate_message', 'recovery_otp_shared_message',
		'outside_message', 'early_message', 'late_message', 'invalid_qr_message',
		'outside_title', 'early_title', 'late_title', 'invalid_qr_title',
		'registration_title', 'registration_button', 'attendance_button',
		'attendance_instruction', 'card_instruction', 'name_field', 'sms_field',
			'fields', 'recovery_fields', 'prevent_duplicate_phone', 'duplicate_phone_message',
			'allow_duplicate_guest_phone', 'duplicate_guest_phone_message', 'sms_inherit', 'sms_mode', 'sms_rules',
	);

	public static function hooks(): void {
		add_action( 'admin_post_ecm_save_event', array( __CLASS__, 'save_event' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_continue_migration' ) );
		add_action( self::MIGRATION_HOOK, array( __CLASS__, 'scheduled_migration' ) );
	}

	public static function install(): void {
		$default_id = self::ensure_default_event();
		if ( ! $default_id ) {
			return;
		}

		global $wpdb;
		$registrations = ECM_DB::table( 'registrations' );
		$sessions      = ECM_DB::table( 'sessions' );
		$attendance    = ECM_DB::table( 'attendance' );
		$audit         = ECM_DB::table( 'events' );

		$wpdb->query( $wpdb->prepare( "UPDATE {$registrations} SET event_id=%d WHERE event_id=0", $default_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$sessions} SET event_id=%d WHERE event_id=0", $default_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$attendance} SET event_id=%d WHERE event_id=0", $default_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$audit} SET domain_event_id=%d WHERE domain_event_id=0 AND registration_id>0", $default_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::seed_default_operator_acl( $default_id );
		self::seed_event_configs();
		self::process_migration_batch( 150 );
		self::schedule_migration_if_needed();
	}

	/**
	 * Event-owned registration/card configuration. Global values are retained
	 * only as a migration fallback for records created before participant cards.
	 */
	public static function config( ?object $event ): array {
		$defaults = self::config_defaults();
		if ( ! $event || empty( $event->config_json ) ) {
			return $defaults;
		}
			$stored = json_decode( (string) $event->config_json, true );
			$config = is_array( $stored ) ? wp_parse_args( $stored, $defaults ) : $defaults;
			foreach ( (array) ( $config['sms_rules'] ?? array() ) as $state => $rule ) {
				$rule = is_array( $rule ) ? $rule : array();
				if ( empty( $rule['mode'] ) ) $rule['mode'] = ! empty( $rule['pattern_profile'] ) || ! empty( $rule['pattern'] ) ? 'pattern' : 'inherit';
				if ( empty( $rule['audience_mode'] ) ) $rule['audience_mode'] = 'shared';
				if ( empty( $rule['guest_mode'] ) ) $rule['guest_mode'] = ! empty( $rule['guest_pattern_profile'] ) || ! empty( $rule['guest_pattern'] ) ? 'pattern' : 'inherit';
				$config['sms_rules'][ $state ] = $rule;
			}
			return $config;
	}

	/**
	 * Return a browser-safe URL for the static PDF displayed before a card.
	 *
	 * Existing events may still contain a same-site HTTP upload URL from an
	 * older configuration. Loading that URL inside an HTTPS card page is
	 * blocked by browsers as mixed content, so upgrade only same-site URLs.
	 */
	public static function card_intro_pdf_url( string $url ): string {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}

		$url_host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$site_host   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$url_scheme  = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$site_scheme = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		$site_https  = is_ssl() || 'https' === $site_scheme;

		if ( $site_https && 'http' === $url_scheme && $url_host && $url_host === $site_host ) {
			$url = set_url_scheme( $url, 'https' );
		}

		return $url;
	}

	public static function config_for_registration( object $registration ): array {
		return self::config( self::registration_event( $registration ) );
	}

	public static function config_defaults(): array {
		$global = ECM_Utils::settings();
		$config = array();
		foreach ( self::CONFIG_KEYS as $key ) {
			if ( array_key_exists( $key, $global ) ) {
				$config[ $key ] = $global[ $key ];
			}
		}
		$config['registration_process'] = 'classic'; // All existing events remain on the legacy registration path.
		$config['sms_inherit'] = 1;
		$config['sms_mode']    = 'pattern' === ( $global['sms_mode'] ?? '' ) ? 'pattern' : 'webservice';
		$config['sms_rules']   = self::global_sms_rules( $global );
		$config['prevent_duplicate_phone'] = 0;
		$config['duplicate_phone_message'] = 'این شماره موبایل قبلاً برای این رویداد ثبت شده است.';
		$config['allow_duplicate_guest_phone'] = 0;
		$config['duplicate_guest_phone_message'] = 'این شماره موبایل قبلاً برای یکی از مهمانان همین رویداد ثبت شده است.';
		$config['card_access_mode'] = 'own_only';
		$config['card_intro_pdf_url'] = '';
		$config['recovery_otp_enabled'] = 0;
		$config['recovery_otp_pattern_profile'] = 'recovery_otp';
		$config['recovery_otp_expiry'] = 300;
		$config['recovery_otp_resend'] = 120;
		$config['recovery_otp_max_attempts'] = 5;
		$config['recovery_otp_max_sends'] = 5;
		$config['recovery_otp_title'] = 'تأیید شماره موبایل';
		$config['recovery_otp_intro'] = 'شماره موبایل یا کد پیگیری کارت را وارد کنید تا کد تأیید برای شماره ثبت‌شده ارسال شود.';
		$config['recovery_otp_send_button'] = 'ارسال کد تأیید';
		$config['recovery_otp_verify_title'] = 'کد تأیید را وارد کنید';
		$config['recovery_otp_verify_intro'] = 'کد شش‌رقمی ارسال‌شده تا ۵ دقیقه معتبر است.';
		$config['recovery_otp_verify_button'] = 'تأیید و نمایش کارت';
		$config['recovery_otp_resend_button'] = 'ارسال مجدد کد';
		$config['recovery_otp_sent_message'] = 'اگر اطلاعات واردشده معتبر باشد، کد تأیید برای شماره ثبت‌شده ارسال شد.';
		$config['recovery_otp_invalid_message'] = 'کد تأیید صحیح نیست یا تعداد تلاش‌های مجاز تمام شده است.';
		$config['recovery_otp_expired_message'] = 'زمان این درخواست تمام شده است؛ دوباره کد دریافت کنید.';
		$config['recovery_otp_send_failed_message'] = 'ارسال کد تأیید انجام نشد؛ اتصال پیامک را بررسی و دوباره تلاش کنید.';
		$config['recovery_otp_rate_message'] = 'تعداد درخواست‌ها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.';
		$config['recovery_otp_shared_message'] = 'این شماره برای چند کارت ثبت شده است؛ کد پیگیری کارت موردنظر را نیز وارد کنید.';
		return $config;
	}

	private static function global_sms_rules( array $settings ): array {
		$rules = array();
		foreach ( array_keys( ECM_SMS::states() ) as $state ) {
			$rules[ $state ] = array(
				'enabled' => empty( $settings[ 'sms_' . $state ] ) ? 0 : 1,
				'text'    => (string) ( $settings[ 'sms_' . $state . '_text' ] ?? '' ),
				'pattern' => (string) ( $settings['sms_patterns'][ $state ] ?? '' ),
				'params'  => (array) ( $settings['sms_params'][ $state ] ?? array() ),
				'pattern_profile' => sanitize_key( (string) ( $settings['sms_pattern_profile'][ $state ] ?? '' ) ),
					'mode' => sanitize_key( (string) ( $settings['sms_state_mode'][ $state ] ?? 'inherit' ) ),
					'audience_mode' => ( $settings['sms_audience_mode'][ $state ] ?? 'shared' ) === 'separate' ? 'separate' : 'shared',
					'guest_mode' => sanitize_key( (string) ( $settings['sms_guest_mode'][ $state ] ?? 'inherit' ) ),
					'guest_text' => (string) ( $settings['sms_guest_text'][ $state ] ?? '' ),
					'guest_pattern' => (string) ( $settings['sms_guest_patterns'][ $state ] ?? '' ),
					'guest_params' => (array) ( $settings['sms_guest_params'][ $state ] ?? array() ),
					'guest_pattern_profile' => sanitize_key( (string) ( $settings['sms_guest_pattern_profile'][ $state ] ?? '' ) ),
			);
		}
		return $rules;
	}

	private static function seed_event_configs(): void {
		global $wpdb;
		$table = ECM_DB::table( 'event_definitions' );
		$json  = wp_json_encode( self::config_defaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET config_json=%s WHERE config_json IS NULL OR config_json=''", $json ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET disabled_title='رویداد غیرفعال است' WHERE disabled_title IS NULL OR disabled_title=''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET disabled_message='ثبت‌نام و ثبت حضور این رویداد در حال حاضر غیرفعال است.' WHERE disabled_message IS NULL OR disabled_message=''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function ensure_default_event(): int {
		global $wpdb;
		$table = ECM_DB::table( 'event_definitions' );
		$id    = absint( get_option( 'ecm_default_event_id', 0 ) );
		if ( $id && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id=%d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $id;
		}

		$existing = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			update_option( 'ecm_default_event_id', $existing, false );
			return $existing;
		}

		$settings = ECM_Utils::settings();
		$now      = ECM_Utils::now_utc();
		$inserted = $wpdb->insert(
			$table,
			array(
				'public_id'             => self::unique_public_id( 'event_definitions' ),
				'slug'                  => 'default-event',
				'title'                 => sanitize_text_field( (string) $settings['event_name'] ),
				'status'                => 'published',
				'enabled'               => 1,
				'disabled_title'        => 'رویداد غیرفعال است',
				'disabled_message'      => 'ثبت‌نام و ثبت حضور این رویداد در حال حاضر غیرفعال است.',
				'attendance_mode'       => 'self_service',
				'show_operator_login'   => 0,
				'guest_limit'            => 0,
				'group_checkin_mode'     => 'selective',
				'unauthorized_title'    => 'ثبت حضور فقط توسط مسئول ورود',
				'unauthorized_message'  => 'ثبت حضور این رویداد فقط توسط اپراتور مجاز انجام می‌شود.',
				'login_message'         => 'برای ثبت حضور، با حساب اپراتور مجاز وارد شوید.',
				'config_json'           => wp_json_encode( self::config_defaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'created_by_user_id'    => get_current_user_id(),
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		update_option( 'ecm_default_event_id', $id, false );
		ECM_Diagnostics::record( 'migration', 'Default event created', array( 'event_id' => $id ) );
		return $id;
	}

	private static function seed_default_operator_acl( int $event_id ): void {
		if ( get_option( 'ecm_default_acl_seeded', false ) ) {
			return;
		}
		global $wpdb;
		$table = ECM_DB::table( 'event_operator_acl' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id=%d", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count ) {
			update_option( 'ecm_default_acl_seeded', 1, false );
			return;
		}
		$inserted = $wpdb->insert(
			$table,
			array(
				'event_id'       => $event_id,
				'principal_type' => 'role',
				'principal_key'  => 'ecm_attendance_operator',
				'created_at'     => ECM_Utils::now_utc(),
			),
			array( '%d', '%s', '%s', '%s' )
		);
		if ( $inserted ) {
			update_option( 'ecm_default_acl_seeded', 1, false );
		}
	}

	public static function default_event_id(): int {
		return self::ensure_default_event();
	}

	public static function event( int $event_id ): ?object {
		if ( ! $event_id ) {
			return null;
		}
		global $wpdb;
		$table = ECM_DB::table( 'event_definitions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function event_by_slug( string $slug, bool $published_only = false ): ?object {
		global $wpdb;
		$table = ECM_DB::table( 'event_definitions' );
		$sql   = "SELECT * FROM {$table} WHERE slug=%s";
		$args  = array( sanitize_title( $slug ) );
		if ( $published_only ) {
			$sql .= ' AND status=%s';
			$args[] = 'published';
		}
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ?: null;
	}

	public static function all( string $status = '' ): array {
		global $wpdb;
		$table = ECM_DB::table( 'event_definitions' );
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status=%s ORDER BY id DESC", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function requested_event(): ?object {
		$slug = isset( $_GET['event'] ) ? sanitize_title( wp_unslash( $_GET['event'] ) ) : '';
		if ( $slug ) {
			return self::event_by_slug( $slug, false );
		}
		$default = self::event( self::default_event_id() );
		return $default ?: null;
	}

	public static function is_publicly_active( ?object $event ): bool {
		return $event && 'published' === (string) $event->status && ! empty( $event->enabled );
	}

	public static function registration_event( object $registration ): ?object {
		$event_id = isset( $registration->event_id ) ? absint( $registration->event_id ) : 0;
		return self::event( $event_id ?: self::default_event_id() );
	}

	public static function registration_url( object $event ): string {
		return ECM_Utils::page_url( 'registration', array( 'event' => $event->slug ) );
	}

	public static function effective_attendance_mode( ?object $event, ?object $session ): string {
		$allowed = array( 'self_service', 'operator_only', 'hybrid' );
		$override = $session && isset( $session->attendance_mode ) ? (string) $session->attendance_mode : 'inherit';
		if ( in_array( $override, $allowed, true ) ) {
			return $override;
		}
		$mode = $event && isset( $event->attendance_mode ) ? (string) $event->attendance_mode : 'self_service';
		return in_array( $mode, $allowed, true ) ? $mode : 'self_service';
	}

	public static function can_record_attendance( int $event_id, int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( user_can( $user, 'manage_options' ) || ( is_multisite() && is_super_admin( $user_id ) ) ) {
			return true;
		}
		if ( ! user_can( $user, 'record_ecm_attendance' ) ) {
			return false;
		}

		global $wpdb;
		$table = ECM_DB::table( 'event_operator_acl' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_id=%d AND principal_type='user' AND principal_key=%s LIMIT 1", $event_id, (string) $user_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return true;
		}
		$roles = array_values( array_filter( array_map( 'sanitize_key', (array) $user->roles ) ) );
		if ( ! $roles ) {
			return false;
		}
		$placeholders = implode( ',', array_fill( 0, count( $roles ), '%s' ) );
		$args         = array_merge( array( $event_id ), $roles );
		$sql          = "SELECT id FROM {$table} WHERE event_id=%d AND principal_type='role' AND principal_key IN ({$placeholders}) LIMIT 1";
		return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function recorder_snapshot( int $event_id ): array {
		if ( ! self::can_record_attendance( $event_id ) ) {
			return array( 'user_id' => 0, 'name' => '', 'code' => '' );
		}
		$user = wp_get_current_user();
		return array(
			'user_id' => (int) $user->ID,
			'name'    => sanitize_text_field( $user->display_name ?: $user->user_login ),
			'code'    => 'U' . (int) $user->ID,
		);
	}

	public static function ensure_registration_entities( int $registration_id, ?array $data = null ): ?object {
		global $wpdb;
		$registrations = ECM_DB::table( 'registrations' );
		$people        = ECM_DB::table( 'people' );
		$participants  = ECM_DB::table( 'participants' );
		$registration  = ECM_DB::registration( $registration_id );
		if ( ! $registration ) {
			return null;
		}

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$participants} WHERE registration_id=%d AND participant_type='primary' ORDER BY id ASC LIMIT 1", $registration_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			$wpdb->update(
				$registrations,
				array(
					'event_id'              => (int) $existing->event_id,
					'primary_person_id'     => (int) $existing->person_id,
					'primary_participant_id'=> (int) $existing->id,
				),
				array( 'id' => $registration_id ),
				array( '%d', '%d', '%d' ),
				array( '%d' )
			);
			return $existing;
		}

		$data     = is_array( $data ) ? $data : ECM_DB::decode_data( $registration );
		$settings = self::config( self::registration_event( $registration ) );
		$name     = sanitize_text_field( ECM_Utils::field_value( $data, (string) $settings['name_field'] ) );
		$phone    = self::normalize_phone( ECM_Utils::field_value( $data, (string) $settings['sms_field'] ) );
		if ( ! $phone && 'mobile' !== (string) $settings['sms_field'] ) {
			$phone = self::normalize_phone( ECM_Utils::field_value( $data, 'mobile' ) );
		}
		$email = '';
		foreach ( (array) $settings['fields'] as $field ) {
			if ( 'email' === ( $field['type'] ?? '' ) ) {
				$email = sanitize_email( ECM_Utils::field_value( $data, (string) $field['key'] ) );
				break;
			}
		}
		$phone_hash = $phone ? hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) ) : null;
		$person      = $phone_hash ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$people} WHERE phone_hash=%s ORDER BY id ASC LIMIT 1", $phone_hash ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$now         = ECM_Utils::now_utc();
		$new_person  = false;
		if ( ! $person ) {
			$inserted = $wpdb->insert(
				$people,
				array(
					'public_id'        => self::unique_public_id( 'people' ),
					'full_name'        => $name,
					'normalized_phone' => $phone,
					'phone_hash'       => $phone_hash,
					'email'            => $email,
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( ! $inserted ) {
				$person = $phone_hash ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$people} WHERE phone_hash=%s ORDER BY id ASC LIMIT 1", $phone_hash ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $person ) {
					return null;
				}
			} else {
				$new_person = true;
				$person     = (object) array( 'id' => (int) $wpdb->insert_id );
			}
		} else {
			$updates = array( 'updated_at' => $now );
			if ( $name ) {
				$updates['full_name'] = $name;
			}
			if ( $email ) {
				$updates['email'] = $email;
			}
			$wpdb->update( $people, $updates, array( 'id' => (int) $person->id ) );
		}

		$event_id = absint( $registration->event_id ?? 0 ) ?: self::default_event_id();
		$inserted = $wpdb->insert(
			$participants,
			array(
				'public_id'                 => (string) $registration->public_id,
				'event_id'                 => $event_id,
				'registration_id'          => $registration_id,
				'person_id'                => (int) $person->id,
				'display_name'             => $name,
				'participant_type'         => 'primary',
				'invited_by_participant_id'=> 0,
				'status'                    => 'active',
				'created_at'                => $now,
				'updated_at'                => $now,
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			$participant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$participants} WHERE public_id=%s LIMIT 1", (string) $registration->public_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $participant || (int) $participant->registration_id !== $registration_id ) {
				if ( $new_person ) {
					$wpdb->delete( $people, array( 'id' => (int) $person->id ), array( '%d' ) );
				}
				return null;
			}
		} else {
			$participant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$participants} WHERE id=%d", (int) $wpdb->insert_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$wpdb->update(
			$registrations,
			array(
				'event_id'               => $event_id,
				'primary_person_id'      => (int) $person->id,
				'primary_participant_id' => (int) $participant->id,
			),
			array( 'id' => $registration_id ),
			array( '%d', '%d', '%d' ),
			array( '%d' )
		);
		$attendance = ECM_DB::table( 'attendance' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$attendance} SET event_id=%d,participant_id=%d WHERE registration_id=%d AND (event_id=0 OR participant_id=0)", $event_id, (int) $participant->id, $registration_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $participant;
	}

	public static function normalize_phone( string $phone ): string {
		$phone = preg_replace( '/[^0-9+]/', '', ECM_Utils::normalize_digits( trim( $phone ) ) );
		if ( str_starts_with( $phone, '0098' ) ) {
			$phone = '0' . substr( $phone, 4 );
		} elseif ( str_starts_with( $phone, '+98' ) ) {
			$phone = '0' . substr( $phone, 3 );
		} elseif ( preg_match( '/^9\d{9}$/', $phone ) ) {
			$phone = '0' . $phone;
		}
		return preg_match( '/^09\d{9}$/', $phone ) ? $phone : '';
	}

	private static function unique_public_id( string $table_suffix ): string {
		global $wpdb;
		$table = ECM_DB::table( $table_suffix );
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$public_id = ECM_Utils::public_id();
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE public_id=%s LIMIT 1", $public_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $public_id;
			}
		}
		return strtoupper( substr( hash( 'sha256', wp_generate_uuid4() ), 0, 10 ) );
	}

	public static function process_migration_batch( int $limit = 100 ): int {
		global $wpdb;
		$table = ECM_DB::table( 'registrations' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE primary_participant_id=0 ORDER BY id ASC LIMIT %d", max( 1, min( 500, $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done  = 0;
		foreach ( $ids as $registration_id ) {
			if ( self::ensure_registration_entities( (int) $registration_id ) ) {
				$done++;
			}
		}
		if ( $ids ) {
			ECM_Diagnostics::record( 'migration', 'Participant migration batch processed', array( 'requested' => count( $ids ), 'completed' => $done ) );
		}
		return $done;
	}

	public static function maybe_continue_migration(): void {
		if ( ! current_user_can( 'manage_ecm_events' ) ) {
			return;
		}
		self::process_migration_batch( 100 );
		self::schedule_migration_if_needed();
	}

	public static function scheduled_migration(): void {
		self::process_migration_batch( 250 );
		self::schedule_migration_if_needed();
	}

	private static function schedule_migration_if_needed(): void {
		global $wpdb;
		$table   = ECM_DB::table( 'registrations' );
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE primary_participant_id=0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( 'ecm_participant_migration_pending', $pending, false );
		if ( $pending && ! wp_next_scheduled( self::MIGRATION_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::MIGRATION_HOOK );
		}
	}

	public static function migration_health(): array {
		global $wpdb;
		$registrations = ECM_DB::table( 'registrations' );
		$participants  = ECM_DB::table( 'participants' );
		$people        = ECM_DB::table( 'people' );
		$event_table   = ECM_DB::table( 'event_definitions' );
		return array(
			'default_event_id'       => self::default_event_id(),
			'pending_registrations'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$registrations} WHERE primary_participant_id=0" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'participants'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$participants}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'guests'                 => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$participants} WHERE participant_type='guest'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'people'                 => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$people}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'events'                 => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$event_table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'inactive_events'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$event_table} WHERE enabled=0" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'events_without_config'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$event_table} WHERE config_json IS NULL OR config_json=''" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public static function admin_page(): void {
		if ( ! current_user_can( 'manage_ecm_events' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$events     = self::all();
		$edit_id    = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$current    = $edit_id ? self::event( $edit_id ) : null;
		$is_new     = isset( $_GET['new'] ) || ! $current;
		$default_id = self::default_event_id();
		if ( $is_new ) {
			$current = (object) array(
				'id'                   => 0,
				'slug'                 => '',
				'title'                => '',
				'status'               => 'draft',
				'enabled'              => 1,
				'disabled_title'       => 'رویداد غیرفعال است',
				'disabled_message'     => 'ثبت‌نام و ثبت حضور این رویداد در حال حاضر غیرفعال است.',
				'attendance_mode'      => 'self_service',
					'show_operator_login'  => 0,
					'guest_limit'           => 0,
					'group_checkin_mode'    => 'selective',
				'unauthorized_title'   => 'ثبت حضور فقط توسط مسئول ورود',
				'unauthorized_message' => 'ثبت حضور این رویداد فقط توسط اپراتور مجاز انجام می‌شود.',
				'login_message'        => 'برای ثبت حضور، با حساب اپراتور مجاز وارد شوید.',
			);
		}
		$config         = self::config( $current );
		$acl            = $current->id ? self::acl( (int) $current->id ) : array( 'roles' => array( 'ecm_attendance_operator' ), 'users' => array() );
		$sessions       = $current->id ? self::sessions( (int) $current->id ) : array();
		$roles          = wp_roles()->roles;
		$operator_users = array_filter(
			get_users( array( 'number' => 300, 'orderby' => 'display_name', 'order' => 'ASC' ) ),
			static fn( WP_User $user ): bool => user_can( $user, 'record_ecm_attendance' ) || user_can( $user, 'manage_options' )
		);
		?>
			<div class="wrap ecm-admin" dir="rtl">
				<div class="ecm-admin__header"><div><span>EVENTS, SESSIONS & ACCESS</span><h1>رویدادها، نوبت‌ها و دسترسی حضور</h1><p>تمام سیاست‌های حضور، مهمان‌ها، نوبت‌ها و اپراتورهای هر رویداد از همین بخش مدیریت می‌شوند.</p></div><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ecm-events&new=1' ) ); ?>">رویداد جدید</a></div>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>رویداد و دسترسی اپراتورها ذخیره شد.</p></div><?php endif; ?>
			<div class="ecm-event-admin-layout">
				<section class="ecm-panel is-active ecm-event-list-panel"><div class="ecm-panel__title"><div><h2>فهرست رویدادها</h2><p>رویداد دارای سابقه حذف نمی‌شود؛ در صورت نیاز آن را بایگانی کنید.</p></div></div>
					<div class="ecm-event-admin-list">
						<?php foreach ( $events as $event ) : ?>
							<div class="ecm-event-admin-list__item"><a class="<?php echo (int) $event->id === (int) $current->id ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ecm-events', 'edit' => (int) $event->id ), admin_url( 'admin.php' ) ) ); ?>"><strong><?php echo esc_html( $event->title ); ?></strong><span><?php echo esc_html( $event->slug ); ?></span><small><?php echo esc_html( self::status_label( $event->status ) ); ?><?php echo empty( $event->enabled ) ? ' · غیرفعال' : ''; ?><?php echo (int) $event->id === $default_id ? ' · پیش‌فرض' : ''; ?></small></a><a class="button button-small" href="<?php echo esc_url( ECM_Reports::url( 'event', array( 'ecm_id' => (int) $event->id ), true ) ); ?>">گزارش</a></div>
					<?php endforeach; ?>
					</div>
				</section>
				<section class="ecm-panel is-active"><div class="ecm-panel__title"><div><h2><?php echo $current->id ? 'ویرایش رویداد' : 'ساخت رویداد'; ?></h2><p>برای استفاده عمومی، وضعیت را روی «منتشرشده» قرار دهید.</p></div></div>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecm-event-form"><input type="hidden" name="action" value="ecm_save_event"><input type="hidden" name="event_id" value="<?php echo esc_attr( $current->id ); ?>"><?php wp_nonce_field( 'ecm_save_event', 'ecm_nonce' ); ?>
						<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>عنوان رویداد</span><input type="text" name="title" value="<?php echo esc_attr( $current->title ); ?>" required></label><label class="ecm-setting"><span>شناسه URL انگلیسی</span><input type="text" name="slug" value="<?php echo esc_attr( $current->slug ); ?>" dir="ltr" placeholder="spring-event" required></label></div>
						<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>وضعیت انتشار</span><select name="status" <?php disabled( (int) $current->id, $default_id ); ?>><option value="draft" <?php selected( $current->status, 'draft' ); ?>>پیش‌نویس</option><option value="published" <?php selected( $current->status, 'published' ); ?>>منتشرشده</option><option value="archived" <?php selected( $current->status, 'archived' ); ?>>بایگانی</option></select><?php if ( (int) $current->id === $default_id ) : ?><input type="hidden" name="status" value="published"><small>رویداد پیش‌فرض برای سازگاری با لینک‌های قبلی منتشر می‌ماند؛ می‌توانید کل رویداد را با کلید مقابل غیرفعال کنید.</small><?php endif; ?></label><label class="ecm-setting"><span>روش پیش‌فرض ثبت حضور</span><select name="attendance_mode"><option value="self_service" <?php selected( $current->attendance_mode, 'self_service' ); ?>>توسط خود کاربر</option><option value="operator_only" <?php selected( $current->attendance_mode, 'operator_only' ); ?>>فقط اپراتور مجاز</option><option value="hybrid" <?php selected( $current->attendance_mode, 'hybrid' ); ?>>ترکیبی</option></select></label></div>
						<div class="ecm-subpanel"><div class="ecm-panel__title"><div><h3>فعال‌بودن کل رویداد</h3><p>در حالت غیرفعال، همه مسیرهای عمومی رویداد شامل ثبت‌نام، بازیابی، نمایش کارت و ثبت حضور بسته می‌شوند.</p></div><label class="ecm-switch"><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $current->enabled ) ); ?>><span>رویداد فعال است</span></label></div><div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>عنوان پیام غیرفعال</span><input type="text" name="disabled_title" value="<?php echo esc_attr( $current->disabled_title ); ?>"></label><label class="ecm-setting"><span>پیام لینک غیرفعال</span><textarea name="disabled_message" rows="3"><?php echo esc_textarea( $current->disabled_message ); ?></textarea></label></div></div>
						<?php self::event_config_sections( $config, $current ); ?>
							<div class="ecm-subpanel">
								<h3>مهمان‌ها و کارت‌های مستقل</h3>
								<div class="ecm-admin-grid ecm-admin-grid--2">
									<label class="ecm-setting"><span>حداکثر مهمان هر ثبت‌نام</span><input type="number" min="0" max="20" name="guest_limit" value="<?php echo esc_attr( (int) $current->guest_limit ); ?>"><small>صفر یعنی ثبت مهمان برای این رویداد غیرفعال است.</small></label>
									<label class="ecm-setting"><span>دسترسی معرف به کارت‌ها</span><select name="event_settings[card_access_mode]"><option value="own_only" <?php selected( $config['card_access_mode'] ?? 'own_only', 'own_only' ); ?>>فقط کارت خودش</option><option value="group_all" <?php selected( $config['card_access_mode'] ?? '', 'group_all' ); ?>>کارت خودش و همه مهمان‌ها</option></select><small>مهمان در هر دو حالت فقط کارت خودش را می‌بیند. حالت پیشنهادی و پیش‌فرض «فقط کارت خودش» است.</small></label>
									<div class="ecm-setting"><span>رفتار QR در پنل اپراتور</span><small>QR معرف، معرف و همه مهمانان فعال را برای ثبت گروهی یا گزینشی نمایش می‌دهد. QR مهمان فقط همان مهمان را نمایش می‌دهد.</small></div>
								</div>
								<p>برای معرف و هر مهمان کارت و QR مستقل صادر می‌شود. تنظیم بالا فقط نمایش و بازیابی کارت‌ها را کنترل می‌کند و روی حضور گروهی اپراتور اثر ندارد.</p>
							</div>
							<div class="ecm-subpanel"><h3>پیام‌های حالت «فقط اپراتور»</h3><div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>عنوان عدم دسترسی</span><input type="text" name="unauthorized_title" value="<?php echo esc_attr( $current->unauthorized_title ); ?>"></label><label class="ecm-setting"><span>پیام ورود اپراتور</span><textarea name="login_message" rows="3"><?php echo esc_textarea( $current->login_message ); ?></textarea></label></div><label class="ecm-setting"><span>پیام برای کاربر غیرمجاز</span><textarea name="unauthorized_message" rows="3"><?php echo esc_textarea( $current->unauthorized_message ); ?></textarea></label><label class="ecm-switch"><input type="checkbox" name="show_operator_login" value="1" <?php checked( ! empty( $current->show_operator_login ) ); ?>><span>نمایش دکمه «ورود اپراتور» در صفحه QR برای افراد واردنشده</span></label></div>
						<div class="ecm-subpanel"><h3>اپراتورهای مجاز این رویداد</h3><p>اپراتور باید capability <code>record_ecm_attendance</code> را نیز داشته باشد. نقش پیش‌فرض «مدیر ثبت حضور» این دسترسی را دارد.</p><div class="ecm-check-grid">
						<?php foreach ( $roles as $role_key => $role_data ) : if ( 'administrator' === $role_key ) { continue; } ?><label><input type="checkbox" name="operator_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $acl['roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?> <code><?php echo esc_html( $role_key ); ?></code></label><?php endforeach; ?>
						</div><label class="ecm-setting"><span>کاربران دارای دسترسی ثبت حضور</span><select name="operator_users[]" multiple size="8"><?php foreach ( $operator_users as $user ) : ?><option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( in_array( (string) $user->ID, $acl['users'], true ) ); ?>><?php echo esc_html( $user->display_name . ' — ' . $user->user_login ); ?></option><?php endforeach; ?></select><small>برای انتخاب چند مورد از Ctrl/Command استفاده کنید. مدیر کل همیشه مجاز است و نیازی به انتخاب ندارد.</small></label></div>
							<?php if ( $current->id ) : ?><div class="ecm-subpanel ecm-event-sessions"><div class="ecm-panel__title"><div><h3>نوبت‌های حضور همین رویداد</h3><p>زمان‌ها بر اساس <?php echo esc_html( wp_timezone_string() ); ?> نمایش داده و در دیتابیس با UTC ذخیره می‌شوند.</p></div><button type="button" class="button button-primary" data-add-session data-event-id="<?php echo esc_attr( $current->id ); ?>">افزودن نوبت</button></div><div class="ecm-sessions" data-session-list><?php foreach ( $sessions as $index => $session ) { self::session_row( (int) $index, $session, (int) $current->id ); } ?></div></div><?php else : ?><div class="ecm-notice"><p>ابتدا رویداد را ذخیره کنید؛ سپس نوبت‌های اختصاصی آن در همین صفحه فعال می‌شوند.</p></div><?php endif; ?>
							<?php if ( $current->id ) : ?><div class="ecm-event-links"><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( self::registration_url( $current ) ); ?>">مشاهده ثبت‌نام</a><a class="button" href="<?php echo esc_url( ECM_Reports::url( 'event', array( 'ecm_id' => (int) $current->id ), true ) ); ?>">گزارش رویداد</a><code><?php echo esc_html( self::registration_url( $current ) ); ?></code></div><?php endif; ?>
							<input type="hidden" name="ecm_event_form_complete" value="1"><button class="button button-primary button-hero" type="submit">ذخیره رویداد، نوبت‌ها و دسترسی‌ها</button>
					</form>
				</section>
			</div>
		</div>
		<?php
	}

	private static function event_config_sections( array $config, ?object $event = null ): void {
		$fields = (array) ( $config['fields'] ?? array() );
		$pattern_profiles = (array) ( ECM_Utils::settings()['sms_pattern_profiles'] ?? array() );
		?>
		<div class="ecm-event-config">
			<details class="ecm-subpanel ecm-event-config-section" open><summary><strong>فرم ثبت‌نام و پرداخت</strong><span>فیلدها، بازیابی، حالت رایگان و مبالغ</span></summary>
				<label class="ecm-setting"><span>نوع فرایند ثبت‌نام (در آلفا فقط برای آزمایش مدیر)</span><select name="event_settings[registration_process]"><option value="classic" <?php selected( $config['registration_process'] ?? 'classic', 'classic' ); ?>>کلاسیک (پیش‌فرض)</option><option value="custom_workflow" <?php selected( $config['registration_process'] ?? '', 'custom_workflow' ); ?>>اختصاصی چهارمرحله‌ای (فقط پیش‌نمایش مدیریت)</option></select><small>انتخاب فرایند اختصاصی، فعلاً صفحه عمومی ثبت‌نام را تغییر نمی‌دهد.</small></label>
				<div class="ecm-panel__title"><div><h3>فیلدهای همین رویداد</h3><p>این فرم فقط روی همین رویداد اثر دارد. نوع «مهمان» دو ورودی نام و موبایل تکرارشونده می‌سازد.</p></div><button type="button" class="button button-primary" data-add-field>افزودن فیلد</button></div>
				<div class="ecm-builder" data-field-list><?php foreach ( $fields as $index => $field ) { ECM_Admin::field_row( (int) $index, (array) $field ); } ?></div>
				<div class="ecm-admin-grid ecm-admin-grid--2">
					<label class="ecm-setting"><span>فیلد نام روی کارت</span><select name="event_settings[name_field]"><?php foreach ( $fields as $field ) : if ( in_array( $field['type'] ?? '', array( 'html', 'guest', 'checkbox' ), true ) ) { continue; } ?><option value="<?php echo esc_attr( $field['key'] ); ?>" <?php selected( $config['name_field'] ?? '', $field['key'] ); ?>><?php echo esc_html( $field['label'] . ' — ' . $field['key'] ); ?></option><?php endforeach; ?></select></label>
					<label class="ecm-setting"><span>فیلد شماره موبایل معرف و بازیابی</span><select name="event_settings[sms_field]"><?php foreach ( $fields as $field ) : if ( 'tel' !== ( $field['type'] ?? '' ) ) { continue; } ?><option value="<?php echo esc_attr( $field['key'] ); ?>" <?php selected( $config['sms_field'] ?? '', $field['key'] ); ?>><?php echo esc_html( $field['label'] . ' — ' . $field['key'] ); ?></option><?php endforeach; ?></select><small>معرف و مهمان از یک کادر شماره موبایل بازیابی می‌شوند و نقش از دیتابیس تشخیص داده می‌شود.</small></label>
				</div>
				<label class="ecm-switch"><input type="checkbox" name="event_settings[payment_required]" value="1" <?php checked( ! empty( $config['payment_required'] ) ); ?> data-payment-toggle><span>این رویداد نیاز به پرداخت دارد</span></label>
					<div class="ecm-subpanel ecm-duplicate-policy"><div class="ecm-panel__title"><div><h3>کنترل شماره‌های تکراری همین رویداد</h3><p>هر دو سیاست فقط داخل همین رویداد اعمال می‌شوند؛ همان شماره در رویدادهای دیگر قابل استفاده است.</p></div></div><div class="ecm-admin-grid ecm-admin-grid--2"><div><label class="ecm-switch"><input type="checkbox" name="event_settings[prevent_duplicate_phone]" value="1" <?php checked( ! empty( $config['prevent_duplicate_phone'] ) ); ?>><span>جلوگیری از ثبت‌نام تکراری معرف</span></label><label class="ecm-setting"><span>پیام خطای شماره معرف تکراری</span><input type="text" name="event_settings[duplicate_phone_message]" value="<?php echo esc_attr( $config['duplicate_phone_message'] ?? '' ); ?>"><small>شماره معرف فقط با ثبت‌نام‌های همین رویداد مقایسه می‌شود.</small></label></div><div><label class="ecm-switch"><input type="checkbox" name="event_settings[allow_duplicate_guest_phone]" value="1" <?php checked( ! empty( $config['allow_duplicate_guest_phone'] ) ); ?>><span>اجازه ثبت شماره تکراری برای مهمان</span></label><label class="ecm-setting"><span>پیام خطای شماره مهمان تکراری</span><input type="text" name="event_settings[duplicate_guest_phone_message]" value="<?php echo esc_attr( $config['duplicate_guest_phone_message'] ?? '' ); ?>"><small>اگر اجازه خاموش باشد، تکرار بین مهمان‌ها، معرف و ثبت‌نام‌های تکمیل‌شده همین رویداد رد می‌شود. در حالت مجاز، بازیابی شماره مشترک آخرین کارت مهمان را برمی‌گرداند؛ معرف همچنان اولویت دارد.</small></label></div></div></div>
				<div class="ecm-payment-settings" data-payment-settings <?php echo empty( $config['payment_required'] ) ? 'hidden' : ''; ?>><div class="ecm-admin-grid ecm-admin-grid--3">
					<?php self::event_config_input( $config, 'amount', 'مبلغ', 'text' ); self::event_config_input( $config, 'currency_label', 'واحد پول', 'text' ); self::event_config_input( $config, 'rial_rate', 'ارزش یک واحد به ریال', 'text' ); ?>
				</div><div class="ecm-admin-grid ecm-admin-grid--3"><?php self::event_config_input( $config, 'currency_decimals', 'تعداد اعشار', 'number' ); ?><label class="ecm-setting"><span>روش ثبت مالی</span><select name="event_settings[payment_mode]"><option value="offline" <?php selected( $config['payment_mode'] ?? '', 'offline' ); ?>>ثبت بدون درگاه</option></select><small>اتصال درگاه واقعی از مرز توسعه‌پذیر پرداخت اضافه می‌شود؛ این نسخه هیچ پرداخت ساختگی ثبت نمی‌کند.</small></label><?php self::event_config_input( $config, 'payment_button', 'متن دکمه ثبت' ); ?></div></div>
				<div class="ecm-free-registration-note" data-free-registration <?php echo ! empty( $config['payment_required'] ) ? 'hidden' : ''; ?>><strong>ثبت‌نام رایگان</strong><p>هیچ باکس مالی یا مرحله پرداختی برای این رویداد نمایش داده نمی‌شود.</p></div>
				<div class="ecm-admin-grid ecm-admin-grid--2"><?php self::event_config_input( $config, 'registration_title', 'عنوان صفحه ثبت‌نام' ); self::event_config_input( $config, 'registration_button', 'دکمه ثبت رایگان' ); self::event_config_input( $config, 'price_label', 'عنوان مبلغ' ); self::event_config_input( $config, 'recovery_link_text', 'متن لینک بازیابی' ); ?></div>
				<?php self::event_config_editor( $config, 'form_intro', 'متن بالای فرم' ); ?>
			</details>

			<details class="ecm-subpanel ecm-event-config-section"><summary><strong>کارت و بازیابی</strong><span>متن‌ها، چاپ و بازیابی یکپارچه</span></summary>
				<?php self::event_config_editor( $config, 'success_text', 'متن صفحه موفقیت' ); ?>
				<div class="ecm-admin-grid ecm-admin-grid--3"><?php self::event_config_input( $config, 'card_ready_title', 'عنوان صفحه کارت' ); self::event_config_input( $config, 'home_link_text', 'متن بازگشت' ); self::event_config_input( $config, 'card_instruction', 'راهنمای روی کارت' ); ?></div>
				<?php self::event_config_input( $config, 'print_button', 'متن دکمه چاپ' ); ?>
				<label class="ecm-setting"><span>محتوای چاپ</span><select name="event_settings[card_print_mode]"><option value="page" <?php selected( $config['card_print_mode'] ?? '', 'page' ); ?>>متن صفحه + کارت</option><option value="custom" <?php selected( $config['card_print_mode'] ?? '', 'custom' ); ?>>متن مخصوص + کارت</option><option value="qr" <?php selected( $config['card_print_mode'] ?? '', 'qr' ); ?>>فقط کارت و QR</option></select></label>
				<?php self::event_config_editor( $config, 'card_print_html', 'متن مخصوص چاپ' ); self::event_config_editor( $config, 'recovery_intro', 'راهنمای بازیابی' ); ?>
				<label class="ecm-setting"><span>PDF ثابت قبل از کارت</span><input type="url" dir="ltr" name="event_settings[card_intro_pdf_url]" value="<?php echo esc_attr( $config['card_intro_pdf_url'] ?? '' ); ?>" placeholder="https://example.com/wp-content/uploads/event-guide.pdf"><small>لینک مستقیم HTTPS فایل PDF را وارد کنید. فایل برای همه کارت‌های این رویداد یکسان است، قبل از کارت نمایش داده می‌شود و روی هاست تکثیر نخواهد شد. خالی‌گذاشتن یعنی عدم نمایش.</small></label>
				<div class="ecm-admin-grid ecm-admin-grid--2"><?php self::event_config_input( $config, 'recovery_title', 'عنوان بازیابی' ); self::event_config_input( $config, 'recovery_button', 'دکمه بازیابی' ); self::event_config_input( $config, 'recovery_not_found', 'پیام پیدا نشدن کارت' ); ?></div>
				<div class="ecm-subpanel ecm-otp-admin-box">
					<div class="ecm-panel__title"><div><h3>بازیابی امن با کد پیامکی</h3><p>در حالت فعال، کارت فقط پس از تأیید کد یک‌بارمصرف نمایش داده می‌شود. ارسال OTP فوری است و وارد صف اعلان‌های عادی نمی‌شود.</p></div><label class="ecm-switch"><input type="checkbox" name="event_settings[recovery_otp_enabled]" value="1" <?php checked( ! empty( $config['recovery_otp_enabled'] ) ); ?>><span>OTP الزامی باشد</span></label></div>
					<div class="ecm-admin-grid ecm-admin-grid--3">
						<label class="ecm-setting"><span>پترن OTP</span><select name="event_settings[recovery_otp_pattern_profile]"><?php foreach ( $pattern_profiles as $profile_id => $profile ) : if ( empty( $profile['enabled'] ) || ! in_array( $profile['purpose'] ?? 'general', array( 'general', 'otp' ), true ) ) { continue; } ?><option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $config['recovery_otp_pattern_profile'] ?? 'recovery_otp', $profile_id ); ?>><?php echo esc_html( $profile['label'] ?? $profile_id ); ?></option><?php endforeach; ?></select><small>پترن باید پارامتر عددی <code>code</code> داشته باشد.</small></label>
						<label class="ecm-setting"><span>اعتبار کد (ثانیه)</span><input type="number" min="120" max="900" name="event_settings[recovery_otp_expiry]" value="<?php echo esc_attr( absint( $config['recovery_otp_expiry'] ?? 300 ) ); ?>"><small>پیشنهادی: ۳۰۰ ثانیه.</small></label>
						<label class="ecm-setting"><span>فعال‌شدن ارسال مجدد (ثانیه)</span><input type="number" min="30" max="870" name="event_settings[recovery_otp_resend]" value="<?php echo esc_attr( absint( $config['recovery_otp_resend'] ?? 120 ) ); ?>"><small>پیشنهادی: ۱۲۰ ثانیه.</small></label>
						<label class="ecm-setting"><span>حداکثر تلاش ورود کد</span><input type="number" min="3" max="10" name="event_settings[recovery_otp_max_attempts]" value="<?php echo esc_attr( absint( $config['recovery_otp_max_attempts'] ?? 5 ) ); ?>"></label>
						<label class="ecm-setting"><span>حداکثر ارسال در ساعت</span><input type="number" min="1" max="10" name="event_settings[recovery_otp_max_sends]" value="<?php echo esc_attr( absint( $config['recovery_otp_max_sends'] ?? 5 ) ); ?>"></label>
					</div>
					<div class="ecm-admin-grid ecm-admin-grid--2"><?php self::event_config_input( $config, 'recovery_otp_title', 'عنوان مرحله دریافت کد' ); self::event_config_input( $config, 'recovery_otp_send_button', 'متن دکمه ارسال' ); self::event_config_input( $config, 'recovery_otp_verify_title', 'عنوان مرحله تأیید' ); self::event_config_input( $config, 'recovery_otp_verify_button', 'متن دکمه تأیید' ); self::event_config_input( $config, 'recovery_otp_resend_button', 'متن دکمه ارسال مجدد' ); ?></div>
					<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>راهنمای دریافت کد</span><textarea rows="3" name="event_settings[recovery_otp_intro]"><?php echo esc_textarea( $config['recovery_otp_intro'] ?? '' ); ?></textarea></label><label class="ecm-setting"><span>راهنمای ورود کد</span><textarea rows="3" name="event_settings[recovery_otp_verify_intro]"><?php echo esc_textarea( $config['recovery_otp_verify_intro'] ?? '' ); ?></textarea></label></div>
					<details class="ecm-advanced-box"><summary>متن خطاها و پیام‌های OTP</summary><div class="ecm-admin-grid ecm-admin-grid--2"><?php foreach ( array( 'recovery_otp_sent_message'=>'پیام ارسال کد', 'recovery_otp_invalid_message'=>'کد نادرست', 'recovery_otp_expired_message'=>'کد منقضی', 'recovery_otp_send_failed_message'=>'خطای ارسال', 'recovery_otp_rate_message'=>'محدودیت درخواست', 'recovery_otp_shared_message'=>'شماره مشترک' ) as $otp_key => $otp_label ) { self::event_config_input( $config, $otp_key, $otp_label ); } ?></div></details>
				</div>
			</details>

			<details class="ecm-subpanel ecm-event-config-section"><summary><strong>متن‌های ثبت حضور</strong><span>QR نامعتبر و خارج از بازه</span></summary>
				<div class="ecm-admin-grid ecm-admin-grid--2"><?php self::event_config_input( $config, 'attendance_button', 'دکمه تأیید حضور' ); self::event_config_input( $config, 'attendance_instruction', 'راهنمای حضور' ); self::event_config_input( $config, 'invalid_qr_title', 'عنوان QR نامعتبر' ); self::event_config_input( $config, 'invalid_qr_message', 'پیام QR نامعتبر' ); self::event_config_input( $config, 'early_title', 'عنوان مراجعه زودهنگام' ); self::event_config_input( $config, 'early_message', 'پیام مراجعه زودهنگام' ); self::event_config_input( $config, 'late_title', 'عنوان مراجعه دیرهنگام' ); self::event_config_input( $config, 'late_message', 'پیام مراجعه دیرهنگام' ); self::event_config_input( $config, 'outside_title', 'عنوان نبود نوبت' ); self::event_config_input( $config, 'outside_message', 'پیام نبود نوبت' ); ?></div>
			</details>

			<details class="ecm-subpanel ecm-event-config-section"><summary><strong>پیامک‌های همین رویداد</strong><span>فعال‌سازی و متن مستقل با امکان ارث‌بری</span></summary>
				<label class="ecm-switch"><input type="checkbox" name="event_settings[sms_inherit]" value="1" <?php checked( ! empty( $config['sms_inherit'] ) ); ?>><span>تنظیمات سراسری پیامک استفاده شود</span></label>
				<p class="description">اگر این گزینه روشن باشد، تغییرات متن‌ها در این کادر ذخیره می‌شود اما تا خاموش‌کردن ارث‌بری اجرا نخواهد شد. اتصال، درگاه، توقف اضطراری و Worker همیشه سراسری هستند.</p>
				<div class="ecm-placeholder-box"><strong>متغیرهای مهم:</strong> <code>{name}</code> <code>{host_name}</code> <code>{event_name}</code> <code>{tracking_code}</code> <code>{card_link}</code> <code>{session_name}</code> <code>{time}</code> <code>{operator_name}</code></div>
				<label class="ecm-setting"><span>روش ارسال این رویداد</span><select name="event_settings[sms_mode]"><option value="webservice" <?php selected( $config['sms_mode'] ?? '', 'webservice' ); ?>>متن آزاد</option><option value="pattern" <?php selected( $config['sms_mode'] ?? '', 'pattern' ); ?>>پترن</option></select></label>
				<div class="ecm-state-grid">
				<?php foreach ( ECM_SMS::states() as $state => $label ) : self::event_sms_rule( $config, $state, $label ); endforeach; ?>
				</div>
			</details>
		</div>
		<?php
	}

	private static function event_config_input( array $config, string $key, string $label, string $type = 'text' ): void {
		echo '<label class="ecm-setting"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="event_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $config[ $key ] ?? '' ) . '"></label>';
	}

	private static function event_config_editor( array $config, string $key, string $label ): void {
		echo '<div class="ecm-setting ecm-setting--editor"><span>' . esc_html( $label ) . '</span>';
		wp_editor( (string) ( $config[ $key ] ?? '' ), 'ecm_event_' . $key, array( 'textarea_name' => 'event_settings[' . $key . ']', 'textarea_rows' => 5, 'media_buttons' => false, 'teeny' => true ) );
		echo '<small>متن ساده و HTML ایمن وردپرس پشتیبانی می‌شود.</small></div>';
	}

	private static function event_sms_rule( array $config, string $state, string $label ): void {
		$rule   = (array) ( $config['sms_rules'][ $state ] ?? array() );
		$settings = ECM_Utils::settings();
		$profiles = (array) ( $settings['sms_pattern_profiles'] ?? array() );
		$selected_profile = sanitize_key( (string) ( $rule['pattern_profile'] ?? '' ) );
		$params = '';
		foreach ( (array) ( $rule['params'] ?? array() ) as $key => $value ) {
			$params .= $key . '=' . $value . "\n";
		}
			$guest_params = '';
			foreach ( (array) ( $rule['guest_params'] ?? array() ) as $key => $value ) $guest_params .= $key . '=' . $value . "\n";
			$guest_profile = sanitize_key( (string) ( $rule['guest_pattern_profile'] ?? '' ) );
		?>
		<details class="ecm-state-card"><summary><span><?php echo esc_html( $label ); ?></span><label><input type="checkbox" name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?>> فعال</label></summary><div>
				<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>روش ارسال این اعلان</span><select name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][mode]"><option value="inherit" <?php selected( $rule['mode'] ?? 'inherit', 'inherit' ); ?>>طبق روش رویداد</option><option value="webservice" <?php selected( $rule['mode'] ?? '', 'webservice' ); ?>>متن آزاد</option><option value="pattern" <?php selected( $rule['mode'] ?? '', 'pattern' ); ?>>پترن</option></select></label><label class="ecm-setting"><span>قالب مخاطبان</span><select name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][audience_mode]"><option value="shared" <?php selected( $rule['audience_mode'] ?? 'shared', 'shared' ); ?>>مشترک</option><option value="separate" <?php selected( $rule['audience_mode'] ?? '', 'separate' ); ?>>مجزای معرف و مهمان</option></select></label></div>
				<label class="ecm-setting"><span>متن معرف / مشترک</span><textarea rows="3" name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][text]"><?php echo esc_textarea( $rule['text'] ?? '' ); ?></textarea></label>
			<label class="ecm-setting"><span>پترن کتابخانه</span><select name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][pattern_profile]"><option value="">کد اختصاصی زیر</option><?php foreach ( $profiles as $profile_id => $profile ) : if ( empty( $profile['enabled'] ) ) { continue; } ?><option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $selected_profile, $profile_id ); ?>><?php echo esc_html( $profile['label'] ?? $profile_id ); ?></option><?php endforeach; ?></select></label>
			<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>کد پترن</span><input type="text" dir="ltr" name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][pattern]" value="<?php echo esc_attr( $rule['pattern'] ?? '' ); ?>"></label><label class="ecm-setting"><span>پارامترها؛ هر خط name=value</span><textarea rows="3" dir="ltr" name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][params]"><?php echo esc_textarea( $params ); ?></textarea></label></div>
				<details class="ecm-session-sms"><summary>قالب اختصاصی مهمان</summary><div class="ecm-session-sms__body"><label class="ecm-setting"><span>روش مهمان</span><select name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][guest_mode]"><option value="inherit" <?php selected( $rule['guest_mode'] ?? 'inherit', 'inherit' ); ?>>مانند معرف</option><option value="webservice" <?php selected( $rule['guest_mode'] ?? '', 'webservice' ); ?>>متن آزاد</option><option value="pattern" <?php selected( $rule['guest_mode'] ?? '', 'pattern' ); ?>>پترن</option></select></label><label class="ecm-setting"><span>متن مهمان</span><textarea rows="3" name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][guest_text]"><?php echo esc_textarea( $rule['guest_text'] ?? '' ); ?></textarea></label><label class="ecm-setting"><span>پترن مهمان</span><select name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][guest_pattern_profile]"><option value="">کد اختصاصی</option><?php foreach ( $profiles as $profile_id => $profile ) : if ( empty( $profile['enabled'] ) ) continue; ?><option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $guest_profile, $profile_id ); ?>><?php echo esc_html( $profile['label'] ?? $profile_id ); ?></option><?php endforeach; ?></select></label><div class="ecm-admin-grid ecm-admin-grid--2"><input type="text" dir="ltr" name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][guest_pattern]" value="<?php echo esc_attr( $rule['guest_pattern'] ?? '' ); ?>" placeholder="کد پترن مهمان"><textarea rows="3" dir="ltr" name="event_settings[sms_rules][<?php echo esc_attr( $state ); ?>][guest_params]" placeholder="name=value"><?php echo esc_textarea( $guest_params ); ?></textarea></div></div></details>
		</div></details>
		<?php
	}

	public static function save_event(): void {
		if ( ! current_user_can( 'manage_ecm_events' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'ecm_save_event', 'ecm_nonce' );
		if ( empty( $_POST['ecm_event_form_complete'] ) ) {
			wp_die( 'درخواست ناقص است؛ محدودیت max_input_vars یا post_max_size هاست را بررسی کنید. هیچ تغییری ذخیره نشد.' );
		}
		global $wpdb;
		$table = ECM_DB::table( 'event_definitions' );
		$id    = absint( $_POST['event_id'] ?? 0 );
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$slug  = strtolower( sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ) );
		if ( ! $title || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,188}[a-z0-9])?$/', $slug ) ) {
			wp_die( 'عنوان و شناسه URL انگلیسی معتبر الزامی است. فقط حروف کوچک انگلیسی، عدد و خط تیره مجاز است.' );
		}
		$base_slug = $slug;
		$suffix    = 2;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug=%s AND id<>%d LIMIT 1", $slug, $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$slug = $base_slug . '-' . $suffix++;
		}
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? 'draft' ) );
		$status = in_array( $status, array( 'draft', 'published', 'archived' ), true ) ? $status : 'draft';
		if ( $id === self::default_event_id() ) {
			$status = 'published';
		}
		$mode = sanitize_key( wp_unslash( $_POST['attendance_mode'] ?? 'self_service' ) );
		$mode = in_array( $mode, array( 'self_service', 'operator_only', 'hybrid' ), true ) ? $mode : 'self_service';
		$existing_event = $id ? self::event( $id ) : null;
		$old_config     = self::config( $existing_event );
		$field_rows     = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
		$fields         = ECM_Admin::sanitize_fields( $field_rows );
		if ( ! $fields ) {
			wp_die( 'فرم رویداد باید حداقل یک فیلد معتبر داشته باشد.' );
		}
		$config_raw = isset( $_POST['event_settings'] ) && is_array( $_POST['event_settings'] ) ? wp_unslash( $_POST['event_settings'] ) : array();
		$config     = self::sanitize_event_config( $config_raw, $fields, $old_config );
		$session_rows = isset( $_POST['sessions'] ) && is_array( $_POST['sessions'] ) ? wp_unslash( $_POST['sessions'] ) : array();
		foreach ( $session_rows as &$session_row ) {
			$session_row['event_id'] = $id;
		}
		unset( $session_row );
		if ( $id ) {
			ECM_Tools::validate_sessions( $session_rows );
		}
		$data = array(
			'slug'                  => $slug,
			'title'                 => $title,
			'status'                => $status,
			'enabled'               => empty( $_POST['enabled'] ) ? 0 : 1,
			'disabled_title'        => sanitize_text_field( wp_unslash( $_POST['disabled_title'] ?? 'رویداد غیرفعال است' ) ),
			'disabled_message'      => wp_kses_post( wp_unslash( $_POST['disabled_message'] ?? '' ) ),
			'attendance_mode'       => $mode,
			'show_operator_login'   => empty( $_POST['show_operator_login'] ) ? 0 : 1,
			'guest_limit'            => min( 20, max( 0, absint( $_POST['guest_limit'] ?? 0 ) ) ),
			// Retained in storage for backward compatibility; the final behavior is
			// deterministic: primary QR expands the active registration group.
			'group_checkin_mode'     => 'selective',
			'unauthorized_title'    => sanitize_text_field( wp_unslash( $_POST['unauthorized_title'] ?? '' ) ),
			'unauthorized_message'  => sanitize_textarea_field( wp_unslash( $_POST['unauthorized_message'] ?? '' ) ),
			'login_message'         => sanitize_textarea_field( wp_unslash( $_POST['login_message'] ?? '' ) ),
			'config_json'           => wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'updated_at'            => ECM_Utils::now_utc(),
		);
		if ( $id ) {
			if ( ! self::event( $id ) ) {
				wp_die( 'رویداد پیدا نشد.', 404 );
			}
			if ( false === $wpdb->update( $table, $data, array( 'id' => $id ), array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) ) ) {
				wp_die( 'ذخیره تغییرات رویداد در دیتابیس انجام نشد.' );
			}
		} else {
			$data['public_id']          = self::unique_public_id( 'event_definitions' );
			$data['created_by_user_id'] = get_current_user_id();
			$data['created_at']         = ECM_Utils::now_utc();
			if ( ! $wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ) ) ) {
				wp_die( 'ساخت رویداد در دیتابیس انجام نشد.' );
			}
			$id = (int) $wpdb->insert_id;
		}
		if ( ! $id ) {
			wp_die( 'ذخیره رویداد در دیتابیس انجام نشد.' );
		}
		self::save_acl( $id );
		if ( $session_rows ) {
			foreach ( $session_rows as &$session_row ) {
				$session_row['event_id'] = $id;
			}
			unset( $session_row );
			ECM_Tools::validate_sessions( $session_rows );
		}
		self::save_sessions( $id, $session_rows );
		ECM_Diagnostics::record( 'system', 'Event definition saved', array( 'event_id' => $id, 'attendance_mode' => $mode ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ecm-events', 'edit' => $id, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function sanitize_event_config( array $raw, array $fields, array $old ): array {
		$config = $old;
			$text_keys = array( 'currency_label', 'payment_button', 'price_label', 'recovery_link_text', 'recovery_title', 'recovery_button', 'recovery_not_found', 'card_ready_title', 'home_link_text', 'print_button', 'invalid_qr_title', 'invalid_qr_message', 'early_title', 'early_message', 'late_title', 'late_message', 'outside_title', 'outside_message', 'registration_title', 'registration_button', 'attendance_button', 'attendance_instruction', 'card_instruction', 'duplicate_phone_message', 'duplicate_guest_phone_message', 'recovery_otp_title', 'recovery_otp_send_button', 'recovery_otp_verify_title', 'recovery_otp_verify_button', 'recovery_otp_resend_button', 'recovery_otp_sent_message', 'recovery_otp_invalid_message', 'recovery_otp_expired_message', 'recovery_otp_send_failed_message', 'recovery_otp_rate_message', 'recovery_otp_shared_message' );
		foreach ( $text_keys as $key ) {
			$config[ $key ] = sanitize_text_field( $raw[ $key ] ?? ( $old[ $key ] ?? '' ) );
		}
		foreach ( array( 'form_intro', 'success_text', 'recovery_intro', 'card_print_html' ) as $key ) {
			$config[ $key ] = wp_kses_post( $raw[ $key ] ?? ( $old[ $key ] ?? '' ) );
		}
		$config['amount']            = ECM_Utils::decimal( (string) ( $raw['amount'] ?? '0' ) );
		$config['rial_rate']         = ECM_Utils::decimal( (string) ( $raw['rial_rate'] ?? '1' ) );
		$config['currency_decimals'] = min( 6, max( 0, absint( $raw['currency_decimals'] ?? 0 ) ) );
		if ( ! preg_match( '/^\d{1,12}(?:\.\d{1,6})?$/', $config['amount'] ) || ! preg_match( '/^\d{1,12}(?:\.\d{1,6})?$/', $config['rial_rate'] ) || (float) $config['rial_rate'] <= 0 || strlen( ECM_Utils::rial_amount( $config['amount'], $config['rial_rate'] ) ) > 18 ) {
			wp_die( 'مبلغ یا نرخ معتبر نیست: حداکثر ۱۲ رقم صحیح و ۶ رقم اعشار مجاز است و نرخ باید بزرگ‌تر از صفر باشد.' );
		}
		$config['registration_process'] = 'custom_workflow' === ( $raw['registration_process'] ?? ( $old['registration_process'] ?? 'classic' ) ) ? 'custom_workflow' : 'classic';
		$config['payment_mode']     = 'offline';
		$config['payment_required'] = empty( $raw['payment_required'] ) ? 0 : 1;
			$config['prevent_duplicate_phone'] = empty( $raw['prevent_duplicate_phone'] ) ? 0 : 1;
			$config['allow_duplicate_guest_phone'] = empty( $raw['allow_duplicate_guest_phone'] ) ? 0 : 1;
			if ( '' === trim( $config['duplicate_phone_message'] ) ) {
				$config['duplicate_phone_message'] = 'این شماره موبایل قبلاً برای این رویداد ثبت شده است.';
			}
			if ( '' === trim( $config['duplicate_guest_phone_message'] ) ) {
				$config['duplicate_guest_phone_message'] = 'این شماره موبایل قبلاً برای یکی از مهمانان همین رویداد ثبت شده است.';
			}
		$config['card_print_mode']  = in_array( $raw['card_print_mode'] ?? '', array( 'page', 'custom', 'qr' ), true ) ? $raw['card_print_mode'] : 'page';
		$config['card_access_mode'] = 'group_all' === ( $raw['card_access_mode'] ?? '' ) ? 'group_all' : 'own_only';
		$pdf_url = self::card_intro_pdf_url( (string) ( $raw['card_intro_pdf_url'] ?? '' ) );
		if ( $pdf_url && ! wp_http_validate_url( $pdf_url ) ) {
			wp_die( 'لینک PDF ثابت معتبر نیست. یک URL کامل http یا https وارد کنید.' );
		}
		$config['card_intro_pdf_url'] = $pdf_url;
		$config['recovery_otp_enabled'] = empty( $raw['recovery_otp_enabled'] ) ? 0 : 1;
		$profiles = (array) ( ECM_Utils::settings()['sms_pattern_profiles'] ?? array() );
		$otp_profile = sanitize_key( (string) ( $raw['recovery_otp_pattern_profile'] ?? 'recovery_otp' ) );
		$config['recovery_otp_pattern_profile'] = isset( $profiles[ $otp_profile ] ) ? $otp_profile : 'recovery_otp';
		$config['recovery_otp_expiry'] = min( 900, max( 120, absint( $raw['recovery_otp_expiry'] ?? 300 ) ) );
		$config['recovery_otp_resend'] = min( $config['recovery_otp_expiry'] - 30, max( 30, absint( $raw['recovery_otp_resend'] ?? 120 ) ) );
		$config['recovery_otp_max_attempts'] = min( 10, max( 3, absint( $raw['recovery_otp_max_attempts'] ?? 5 ) ) );
		$config['recovery_otp_max_sends'] = min( 10, max( 1, absint( $raw['recovery_otp_max_sends'] ?? 5 ) ) );
		$config['recovery_otp_intro'] = sanitize_textarea_field( $raw['recovery_otp_intro'] ?? ( $old['recovery_otp_intro'] ?? '' ) );
		$config['recovery_otp_verify_intro'] = sanitize_textarea_field( $raw['recovery_otp_verify_intro'] ?? ( $old['recovery_otp_verify_intro'] ?? '' ) );
		$config['fields']           = $fields;
		$keys = wp_list_pluck( $fields, 'key' );
		$name_key = sanitize_key( $raw['name_field'] ?? '' );
		$config['name_field'] = in_array( $name_key, $keys, true ) ? $name_key : (string) $keys[0];
		$phone_keys = array_values( wp_list_pluck( array_filter( $fields, static fn( array $field ): bool => 'tel' === $field['type'] ), 'key' ) );
		if ( ! $phone_keys ) {
			wp_die( 'برای بازیابی یکپارچه، حداقل یک فیلد از نوع «تلفن» در فرم رویداد لازم است.' );
		}
		$phone_key = sanitize_key( $raw['sms_field'] ?? '' );
		$config['sms_field']       = in_array( $phone_key, $phone_keys, true ) ? $phone_key : $phone_keys[0];
		$config['recovery_fields'] = array( $config['sms_field'] );
		$config['sms_inherit']     = empty( $raw['sms_inherit'] ) ? 0 : 1;
		$config['sms_mode']        = 'pattern' === ( $raw['sms_mode'] ?? '' ) ? 'pattern' : 'webservice';
		$config['sms_rules']       = array();
		foreach ( ECM_SMS::states() as $state => $label ) {
			$rule = (array) ( $raw['sms_rules'][ $state ] ?? array() );
			$config['sms_rules'][ $state ] = array(
				'enabled' => empty( $rule['enabled'] ) ? 0 : 1,
				'text'    => sanitize_textarea_field( $rule['text'] ?? '' ),
				'pattern' => sanitize_text_field( $rule['pattern'] ?? '' ),
				'params'  => ECM_SMS::parse_param_lines( (string) ( $rule['params'] ?? '' ) ),
				'pattern_profile' => sanitize_key( (string) ( $rule['pattern_profile'] ?? '' ) ),
					'mode' => ! empty( $rule['pattern_profile'] ) ? 'pattern' : ( in_array( sanitize_key( (string) ( $rule['mode'] ?? 'inherit' ) ), array( 'inherit', 'webservice', 'pattern' ), true ) ? sanitize_key( (string) ( $rule['mode'] ?? 'inherit' ) ) : 'inherit' ),
					'audience_mode' => 'separate' === ( $rule['audience_mode'] ?? '' ) ? 'separate' : 'shared',
					'guest_mode' => ! empty( $rule['guest_pattern_profile'] ) ? 'pattern' : ( in_array( sanitize_key( (string) ( $rule['guest_mode'] ?? 'inherit' ) ), array( 'inherit', 'webservice', 'pattern' ), true ) ? sanitize_key( (string) ( $rule['guest_mode'] ?? 'inherit' ) ) : 'inherit' ),
					'guest_text' => sanitize_textarea_field( $rule['guest_text'] ?? '' ),
					'guest_pattern' => sanitize_text_field( $rule['guest_pattern'] ?? '' ),
					'guest_params' => ECM_SMS::parse_param_lines( (string) ( $rule['guest_params'] ?? '' ) ),
					'guest_pattern_profile' => sanitize_key( (string) ( $rule['guest_pattern_profile'] ?? '' ) ),
			);
		}
		return array_intersect_key( $config, array_flip( self::CONFIG_KEYS ) );
	}

	private static function sessions( int $event_id ): array {
		global $wpdb;
		$table = ECM_DB::table( 'sessions' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id=%d ORDER BY start_at_utc ASC,id ASC", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function session_row( int $index, object $session, int $event_id ): void {
		$start = $session->start_at_utc ? ECM_Utils::utc_to_local( $session->start_at_utc, 'Y-m-d\TH:i' ) : '';
		$end   = $session->end_at_utc ? ECM_Utils::utc_to_local( $session->end_at_utc, 'Y-m-d\TH:i' ) : '';
		$sms_config = ! empty( $session->sms_config ) ? json_decode( (string) $session->sms_config, true ) : array();
		$sms_config = is_array( $sms_config ) ? $sms_config : array();
		$report_url = ECM_Reports::url( 'attendance', array( 'ecm_event' => $event_id, 'ecm_session' => (int) $session->id ), true );
		?>
		<div class="ecm-session-row" data-session-row>
			<input type="hidden" name="sessions[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $session->id ); ?>">
			<input type="hidden" name="sessions[<?php echo esc_attr( $index ); ?>][event_id]" value="<?php echo esc_attr( $event_id ); ?>">
			<div class="ecm-admin-grid ecm-admin-grid--2"><label>عنوان نوبت<input type="text" name="sessions[<?php echo esc_attr( $index ); ?>][title]" value="<?php echo esc_attr( $session->title ); ?>" required></label><label>روش ثبت حضور<select name="sessions[<?php echo esc_attr( $index ); ?>][attendance_mode]"><option value="inherit" <?php selected( $session->attendance_mode ?? 'inherit', 'inherit' ); ?>>طبق تنظیم رویداد</option><option value="self_service" <?php selected( $session->attendance_mode ?? '', 'self_service' ); ?>>توسط خود کاربر</option><option value="operator_only" <?php selected( $session->attendance_mode ?? '', 'operator_only' ); ?>>فقط اپراتور</option><option value="hybrid" <?php selected( $session->attendance_mode ?? '', 'hybrid' ); ?>>ترکیبی</option></select></label></div>
			<div class="ecm-admin-grid ecm-admin-grid--3"><label>شروع<input type="datetime-local" name="sessions[<?php echo esc_attr( $index ); ?>][start]" value="<?php echo esc_attr( $start ); ?>" required></label><label>پایان<input type="datetime-local" name="sessions[<?php echo esc_attr( $index ); ?>][end]" value="<?php echo esc_attr( $end ); ?>" required></label><label>حداکثر ثبت هر کارت<input type="number" min="1" max="99" name="sessions[<?php echo esc_attr( $index ); ?>][max_entries]" value="<?php echo esc_attr( $session->max_entries ); ?>"></label></div>
			<div class="ecm-admin-grid ecm-admin-grid--2"><label>پیغام موفقیت<textarea name="sessions[<?php echo esc_attr( $index ); ?>][success_message]" rows="3"><?php echo esc_textarea( $session->success_message ); ?></textarea></label><label>پیغام ثبت تکراری/بیش از حد<textarea name="sessions[<?php echo esc_attr( $index ); ?>][repeat_message]" rows="3"><?php echo esc_textarea( $session->repeat_message ); ?></textarea></label></div>
			<details class="ecm-session-sms"><summary>پیامک اختصاصی این نوبت</summary><div class="ecm-session-sms__body">
				<label class="ecm-switch"><input type="checkbox" name="sessions[<?php echo esc_attr( $index ); ?>][sms][override]" value="1" <?php checked( ! empty( $sms_config['override'] ) ); ?>><span>تنظیم پیامک حضور و تکرار در این نوبت جایگزین رویداد شود</span></label>
				<label>روش ارسال<select name="sessions[<?php echo esc_attr( $index ); ?>][sms][mode]"><option value="inherit" <?php selected( $sms_config['mode'] ?? 'inherit', 'inherit' ); ?>>طبق رویداد</option><option value="webservice" <?php selected( $sms_config['mode'] ?? '', 'webservice' ); ?>>متن آزاد</option><option value="pattern" <?php selected( $sms_config['mode'] ?? '', 'pattern' ); ?>>پترن</option></select></label>
				<?php self::session_sms_rule( $index, 'attendance', 'ثبت حضور موفق', $sms_config ); self::session_sms_rule( $index, 'repeat', 'ثبت حضور تکراری', $sms_config ); ?>
			</div></details>
			<div class="ecm-session-row__footer"><label><input type="checkbox" name="sessions[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $session->enabled ); ?>> فعال</label><div class="ecm-action-row"><a class="button button-small" href="<?php echo esc_url( $report_url ); ?>">گزارش نوبت</a><button type="button" class="button-link-delete" data-remove-row>حذف نوبت</button></div></div>
		</div>
		<?php
	}

	private static function session_sms_rule( int $index, string $state, string $label, array $sms_config ): void {
		$rule = (array) ( $sms_config['rules'][ $state ] ?? array() );
		$profiles = (array) ( ECM_Utils::settings()['sms_pattern_profiles'] ?? array() );
		$selected_profile = sanitize_key( (string) ( $rule['pattern_profile'] ?? '' ) );
		$params = '';
		foreach ( (array) ( $rule['params'] ?? array() ) as $key => $value ) {
			$params .= $key . '=' . $value . "\n";
		}
			$guest_params = '';
			foreach ( (array) ( $rule['guest_params'] ?? array() ) as $key => $value ) $guest_params .= $key . '=' . $value . "\n";
			$guest_profile = sanitize_key( (string) ( $rule['guest_pattern_profile'] ?? '' ) );
		?>
			<div class="ecm-session-sms__rule"><label><input type="checkbox" name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?>> <?php echo esc_html( $label ); ?></label><div class="ecm-admin-grid ecm-admin-grid--2"><select name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][mode]"><option value="inherit" <?php selected( $rule['mode'] ?? 'inherit', 'inherit' ); ?>>طبق روش نوبت</option><option value="webservice" <?php selected( $rule['mode'] ?? '', 'webservice' ); ?>>متن آزاد</option><option value="pattern" <?php selected( $rule['mode'] ?? '', 'pattern' ); ?>>پترن</option></select><select name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][audience_mode]"><option value="shared" <?php selected( $rule['audience_mode'] ?? 'shared', 'shared' ); ?>>قالب مشترک</option><option value="separate" <?php selected( $rule['audience_mode'] ?? '', 'separate' ); ?>>مهمان مجزا</option></select></div><textarea rows="2" name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][text]" placeholder="متن معرف / مشترک"><?php echo esc_textarea( $rule['text'] ?? '' ); ?></textarea><select name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][pattern_profile]"><option value="">کد اختصاصی</option><?php foreach ( $profiles as $profile_id => $profile ) : if ( empty( $profile['enabled'] ) ) { continue; } ?><option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $selected_profile, $profile_id ); ?>><?php echo esc_html( $profile['label'] ?? $profile_id ); ?></option><?php endforeach; ?></select><div class="ecm-admin-grid ecm-admin-grid--2"><input type="text" dir="ltr" name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][pattern]" value="<?php echo esc_attr( $rule['pattern'] ?? '' ); ?>" placeholder="کد پترن"><textarea rows="2" dir="ltr" name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][params]" placeholder="name=value"><?php echo esc_textarea( $params ); ?></textarea></div><details><summary>قالب مهمان</summary><div class="ecm-session-sms__body"><select name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][guest_mode]"><option value="inherit" <?php selected( $rule['guest_mode'] ?? 'inherit', 'inherit' ); ?>>مانند معرف</option><option value="webservice" <?php selected( $rule['guest_mode'] ?? '', 'webservice' ); ?>>متن آزاد</option><option value="pattern" <?php selected( $rule['guest_mode'] ?? '', 'pattern' ); ?>>پترن</option></select><textarea rows="2" name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][guest_text]" placeholder="متن مهمان"><?php echo esc_textarea( $rule['guest_text'] ?? '' ); ?></textarea><select name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][guest_pattern_profile]"><option value="">کد اختصاصی مهمان</option><?php foreach ( $profiles as $profile_id => $profile ) : if ( empty( $profile['enabled'] ) ) continue; ?><option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $guest_profile, $profile_id ); ?>><?php echo esc_html( $profile['label'] ?? $profile_id ); ?></option><?php endforeach; ?></select><input type="text" dir="ltr" name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][guest_pattern]" value="<?php echo esc_attr( $rule['guest_pattern'] ?? '' ); ?>" placeholder="کد پترن مهمان"><textarea rows="2" dir="ltr" name="sessions[<?php echo esc_attr( $index ); ?>][sms][rules][<?php echo esc_attr( $state ); ?>][guest_params]" placeholder="name=value"><?php echo esc_textarea( $guest_params ); ?></textarea></div></details></div>
		<?php
	}

	private static function save_sessions( int $event_id, array $rows ): void {
		global $wpdb;
		$table      = ECM_DB::table( 'sessions' );
		$attendance = ECM_DB::table( 'attendance' );
		$kept_ids   = array();
		foreach ( $rows as $row ) {
			$title = sanitize_text_field( $row['title'] ?? '' );
			$start = ECM_Utils::local_to_utc( sanitize_text_field( $row['start'] ?? '' ) );
			$end   = ECM_Utils::local_to_utc( sanitize_text_field( $row['end'] ?? '' ) );
			if ( ! $title || ! $start || ! $end || $start >= $end ) {
				continue;
			}
			$mode = sanitize_key( $row['attendance_mode'] ?? 'inherit' );
			$mode = in_array( $mode, array( 'inherit', 'self_service', 'operator_only', 'hybrid' ), true ) ? $mode : 'inherit';
			$sms_raw = (array) ( $row['sms'] ?? array() );
			$sms_mode = sanitize_key( $sms_raw['mode'] ?? 'inherit' );
			$sms_mode = in_array( $sms_mode, array( 'inherit', 'webservice', 'pattern' ), true ) ? $sms_mode : 'inherit';
			$sms_config = array( 'override' => empty( $sms_raw['override'] ) ? 0 : 1, 'mode' => $sms_mode, 'rules' => array() );
			foreach ( array( 'attendance', 'repeat' ) as $state ) {
				$rule = (array) ( $sms_raw['rules'][ $state ] ?? array() );
					$rule_mode = sanitize_key( (string) ( $rule['mode'] ?? 'inherit' ) ); $guest_mode = sanitize_key( (string) ( $rule['guest_mode'] ?? 'inherit' ) );
					$sms_config['rules'][ $state ] = array( 'enabled' => empty( $rule['enabled'] ) ? 0 : 1, 'mode' => in_array( $rule_mode, array( 'inherit', 'webservice', 'pattern' ), true ) ? $rule_mode : 'inherit', 'audience_mode' => 'separate' === ( $rule['audience_mode'] ?? '' ) ? 'separate' : 'shared', 'text' => sanitize_textarea_field( $rule['text'] ?? '' ), 'pattern' => sanitize_text_field( $rule['pattern'] ?? '' ), 'params' => ECM_SMS::parse_param_lines( (string) ( $rule['params'] ?? '' ) ), 'pattern_profile' => sanitize_key( (string) ( $rule['pattern_profile'] ?? '' ) ), 'guest_mode' => in_array( $guest_mode, array( 'inherit', 'webservice', 'pattern' ), true ) ? $guest_mode : 'inherit', 'guest_text' => sanitize_textarea_field( $rule['guest_text'] ?? '' ), 'guest_pattern' => sanitize_text_field( $rule['guest_pattern'] ?? '' ), 'guest_params' => ECM_SMS::parse_param_lines( (string) ( $rule['guest_params'] ?? '' ) ), 'guest_pattern_profile' => sanitize_key( (string) ( $rule['guest_pattern_profile'] ?? '' ) ) );
			}
			$data = array(
				'event_id'         => $event_id,
				'title'            => $title,
				'start_at_utc'     => $start,
				'end_at_utc'       => $end,
				'max_entries'      => min( 99, max( 1, absint( $row['max_entries'] ?? 1 ) ) ),
				'success_message'  => sanitize_textarea_field( $row['success_message'] ?? 'حضور شما با موفقیت ثبت شد.' ),
				'repeat_message'   => sanitize_textarea_field( $row['repeat_message'] ?? 'حضور این کارت قبلاً ثبت شده است.' ),
				'attendance_mode'  => $mode,
				'sms_config'       => wp_json_encode( $sms_config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'enabled'          => empty( $row['enabled'] ) ? 0 : 1,
			);
			$id = absint( $row['id'] ?? 0 );
			if ( $id ) {
				$belongs = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id=%d AND event_id=%d", $id, $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $belongs ) {
					continue;
				}
				$wpdb->update( $table, $data, array( 'id' => $id ), array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' ), array( '%d' ) );
				$kept_ids[] = $id;
			} else {
				$data['created_at'] = ECM_Utils::now_utc();
				if ( $wpdb->insert( $table, $data, array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ) ) ) {
					$kept_ids[] = (int) $wpdb->insert_id;
				}
			}
		}
		$existing = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_id=%d", $event_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array_diff( $existing, $kept_ids ) as $removed_id ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attendance} WHERE session_id=%d", $removed_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $used ) {
				$wpdb->update( $table, array( 'enabled' => 0 ), array( 'id' => $removed_id ), array( '%d' ), array( '%d' ) );
			} else {
				$wpdb->delete( $table, array( 'id' => $removed_id, 'event_id' => $event_id ), array( '%d', '%d' ) );
			}
		}
	}

	private static function save_acl( int $event_id ): void {
		global $wpdb;
		$table = ECM_DB::table( 'event_operator_acl' );
		$roles = array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['operator_roles'] ?? array() ) ), array_keys( wp_roles()->roles ) ) );
		$users = array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['operator_users'] ?? array() ) ) ) ) );
		$wpdb->query( 'START TRANSACTION' );
		if ( false === $wpdb->delete( $table, array( 'event_id' => $event_id ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( 'ذخیره دسترسی اپراتورها انجام نشد.' );
		}
		foreach ( $roles as $role ) {
			if ( ! $wpdb->insert( $table, array( 'event_id' => $event_id, 'principal_type' => 'role', 'principal_key' => $role, 'created_at' => ECM_Utils::now_utc() ), array( '%d', '%s', '%s', '%s' ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				wp_die( 'ذخیره نقش‌های اپراتور کامل نشد و تغییرات بازگردانی شد.' );
			}
		}
		foreach ( $users as $user_id ) {
			if ( ! get_userdata( $user_id ) ) {
				continue;
			}
			if ( ! $wpdb->insert( $table, array( 'event_id' => $event_id, 'principal_type' => 'user', 'principal_key' => (string) $user_id, 'created_at' => ECM_Utils::now_utc() ), array( '%d', '%s', '%s', '%s' ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				wp_die( 'ذخیره کاربران اپراتور کامل نشد و تغییرات بازگردانی شد.' );
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			wp_die( 'تأیید نهایی دسترسی اپراتورها انجام نشد.' );
		}
	}

	private static function acl( int $event_id ): array {
		global $wpdb;
		$table = ECM_DB::table( 'event_operator_acl' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT principal_type,principal_key FROM {$table} WHERE event_id=%d", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$acl   = array( 'roles' => array(), 'users' => array() );
		foreach ( $rows as $row ) {
			if ( isset( $acl[ $row->principal_type . 's' ] ) ) {
				$acl[ $row->principal_type . 's' ][] = (string) $row->principal_key;
			}
		}
		return $acl;
	}

	public static function operator_portal_markup(): string {
		return ECM_Operator::portal_markup();
	}

	private static function status_label( string $status ): string {
		$labels = array( 'draft' => 'پیش‌نویس', 'published' => 'منتشرشده', 'archived' => 'بایگانی', 'active' => 'نوبت فعال', 'early' => 'پیش از شروع', 'late' => 'پایان‌یافته', 'outside' => 'بدون نوبت' );
		return $labels[ $status ] ?? $status;
	}

	private static function mode_label( string $mode ): string {
		$labels = array( 'self_service' => 'ثبت توسط کاربر', 'operator_only' => 'فقط اپراتور', 'hybrid' => 'ترکیبی' );
		return $labels[ $mode ] ?? $mode;
	}
}
