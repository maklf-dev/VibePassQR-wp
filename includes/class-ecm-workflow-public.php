<?php

defined( 'ABSPATH' ) || exit;

/**
 * Alpha 4: opt-in public-facing staging journey. No WordPress user account needed.
 * Editing requires a random, HttpOnly session token; cross-browser recovery
 * additionally requires the original tracking secret, phone, and SMS OTP.
 * Never enables itself on production by an ordinary plugin upgrade.
 */
final class ECM_Workflow_Public {
	private const SESSION_COOKIE = 'ecm_flow_session';
	private const CHALLENGE_COOKIE = 'ecm_flow_otp_challenge';
	private const NOTICE_COOKIE = 'ecm_flow_notice';
	private static int $authorized_write = -1;

	public static function hooks(): void {
		add_shortcode( 'ecm_flow', array( __CLASS__, 'shortcode' ) );
		foreach ( array( 'step', 'resume', 'verify', 'edit' ) as $operation ) {
			add_action( 'admin_post_nopriv_ecm_flow_public_' . $operation, array( __CLASS__, 'handle_' . $operation ) );
			add_action( 'admin_post_ecm_flow_public_' . $operation, array( __CLASS__, 'handle_' . $operation ) );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'prevent_public_cache' ), 1 );
	}

	public static function available( ?object $event = null ): bool {
		if ( ! ECM_Workflow::enabled()
			|| ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' )
			|| ! function_exists( 'wp_get_environment_type' )
			|| ! in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true )
			|| ! defined( 'ECM_WORKFLOW_STAGING_PUBLIC' ) || true !== ECM_WORKFLOW_STAGING_PUBLIC ) {
			return false;
		}
		if ( 'staging' === wp_get_environment_type() && ! is_ssl() ) { return false; }
		if ( ! $event ) { return true; }
		return 'custom_workflow' === ECM_Workflow::mode_for_event( $event )
			&& ECM_Events::is_publicly_active( $event )
			&& self::valid_definition( ECM_Workflow_Versions::published( (int) $event->id ) );
	}

	/** Set cache policy before shortcode output, rather than after WordPress sends headers. */
	public static function prevent_public_cache(): void {
		if ( ! self::available() ) { return; }
		global $post;
		$pages = array_filter( array_map( 'absint', (array) get_option( 'ecm_pages', array() ) ) );
		$has_flow = $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'ecm_flow' );
		if ( ( $pages && is_page( $pages ) ) || $has_flow ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			nocache_headers();
		}
	}

	public static function assets(): void {
		wp_register_style( 'ecm-flow-public', ECM_URL . 'assets/css/workflow-public.css', array( 'ecm-public' ), ECM_VERSION );
		wp_register_script( 'ecm-flow-public', ECM_URL . 'assets/js/workflow-public.js', array( 'ecm-public' ), ECM_VERSION, true );
	}

	private static function valid_definition( ?object $version ): bool {
		if ( ! $version ) { return false; }
		$data = json_decode( (string) $version->definition_json, true );
		return is_array( $data ) && array_column( (array) ( $data['steps'] ?? array() ), 'type' ) === array( 'form', 'agreement', 'receipt', 'result' );
	}

	private static function cookie( string $name, string $value, int $seconds ): void {
		if ( headers_sent() ) { return; }
		setcookie( $name, $value, array(
			'expires' => $seconds ? time() + $seconds : time() - 3600,
			'path' => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
		) );
		if ( $seconds ) { $_COOKIE[ $name ] = $value; } else { unset( $_COOKIE[ $name ] ); }
	}

	private static function token(): string { return bin2hex( random_bytes( 24 ) ); }
	private static function key( string $prefix, string $token ): string {
		return $prefix . substr( hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ), 0, 40 );
	}
	private static function session(): ?array {
		$token = (string) ( $_COOKIE[ self::SESSION_COOKIE ] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{48}$/D', $token ) ) { return null; }
		$data = get_transient( self::key( 'ecm_flow_session_', $token ) );
		return is_array( $data ) && ! empty( $data['instance_id'] ) ? $data : null;
	}
	private static function session_for( int $event_id ): ?object {
		$session = self::session();
		if ( ! $session || (int) $session['event_id'] !== $event_id ) { return null; }
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . ECM_DB::table( 'flow_instances' ) . ' WHERE id=%d AND event_id=%d',
			(int) $session['instance_id'], $event_id
		) ) ?: null;
	}
	/** Tracking secrets are encrypted inside expiring WordPress transients. */
	private static function encrypt_tracking( string $secret, int $instance_id ): string {
		if ( '' === $secret ) { return ''; }
		$iv = random_bytes( 12 );
		$tag = '';
		$key = hash( 'sha256', 'ecm-flow-public:' . wp_salt( 'auth' ), true );
		$cipher = openssl_encrypt( $secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, (string) $instance_id );
		return is_string( $cipher ) ? base64_encode( $iv . $tag . $cipher ) : '';
	}
	private static function decrypt_tracking( string $encoded, object $row ): string {
		$raw = base64_decode( $encoded, true );
		if ( ! is_string( $raw ) || strlen( $raw ) < 29 ) { return ''; }
		$key = hash( 'sha256', 'ecm-flow-public:' . wp_salt( 'auth' ), true );
		$secret = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), (string) $row->id );
		if ( ! is_string( $secret ) || ! preg_match( '/^[A-F0-9]{32}$/D', $secret )
			|| ! hash_equals( (string) $row->tracking_hash, ECM_Workflow::tracking_digest( $secret ) ) ) { return ''; }
		return $secret;
	}
	private static function authorize_session( int $event_id, int $instance_id, string $tracking = '' ): void {
		$old = (string) ( $_COOKIE[ self::SESSION_COOKIE ] ?? '' );
		if ( preg_match( '/^[a-f0-9]{48}$/D', $old ) ) {
			delete_transient( self::key( 'ecm_flow_session_', $old ) );
		}
		$token = self::token();
		set_transient( self::key( 'ecm_flow_session_', $token ), array(
			'event_id' => $event_id, 'instance_id' => $instance_id,
			'tracking_cipher' => self::encrypt_tracking( $tracking, $instance_id ), // Encrypted, expires in 24h.
		), DAY_IN_SECONDS );
		self::cookie( self::SESSION_COOKIE, $token, DAY_IN_SECONDS );
	}

	/** AES-GCM envelope for short-lived OTP challenges. No plaintext form payload in a transient. */
	private static function seal( array $payload, string $purpose ): string {
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 32768 ) { return ''; }
		$iv = random_bytes( 12 ); $tag = '';
		$key = hash( 'sha256', 'ecm-flow-challenge:' . wp_salt( 'auth' ), true );
		$encrypted = openssl_encrypt( $encoded, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $purpose );
		return is_string( $encrypted ) ? base64_encode( $iv . $tag . $encrypted ) : '';
	}
	private static function unseal( string $encoded, string $purpose ): ?array {
		$raw = base64_decode( $encoded, true );
		if ( ! is_string( $raw ) || strlen( $raw ) < 29 ) { return null; }
		$key = hash( 'sha256', 'ecm-flow-challenge:' . wp_salt( 'auth' ), true );
		$decoded = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), $purpose );
		$data = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		return is_array( $data ) ? $data : null;
	}

	/** OTP is mandatory before inserting a new public workflow instance. */
	private static function start_challenge( object $event, string $mobile, string $purpose, array $payload ): void {
		if ( ! self::otp_available() || ! self::rate_limit( 'otp_' . (int) $event->id, 8, HOUR_IN_SECONDS ) ) {
			self::fail( $event, 'امکان ارسال کد تأیید وجود ندارد یا تعداد درخواست‌ها بیش از حد است.' );
		}
		// Recipient-based limit cannot be evaded by changing the browser cookie.
		$recipient_key = self::key( 'ecm_flow_phone_otp_', (string) $event->id . ':' . $mobile );
		$recipient_count = (int) get_transient( $recipient_key );
		if ( $recipient_count >= 3 ) { self::fail( $event, 'درخواست کد برای این شماره بیش از حد مجاز است.' ); }
		set_transient( $recipient_key, $recipient_count + 1, 15 * MINUTE_IN_SECONDS );
		$challenge = self::token();
		$pin = (string) random_int( 100000, 999999 );
		$sealed = self::seal( $payload, $purpose );
		if ( ! $sealed ) { self::fail( $event, 'ذخیره موقت اطلاعات ممکن نشد.' ); }
		$key = self::key( 'ecm_flow_challenge_', $challenge );
		set_transient( $key, array(
			'event_id' => (int) $event->id, 'purpose' => $purpose, 'payload' => $sealed,
			'otp_hash' => wp_hash_password( $pin ), 'attempts' => 0,
			'client_hash' => self::client_hash(),
		), 5 * MINUTE_IN_SECONDS );
		$sent = ECM_SMS::send_secure_pattern( 'recovery_otp', $mobile, array( 'code' => $pin ), array( 'event_id' => (int) $event->id ) );
		if ( is_wp_error( $sent ) ) {
			delete_transient( $key );
			self::fail( $event, 'ارسال کد تأیید انجام نشد. دوباره تلاش کنید.' );
		}
		self::cookie( self::CHALLENGE_COOKIE, $challenge, 5 * MINUTE_IN_SECONDS );
		self::redirect( $event );
	}

	private static function identity_exists( int $event_id, string $mobile ): bool {
		$digest = ECM_Workflow::identity_digest( $event_id, $mobile );
		if ( is_wp_error( $digest ) ) { return true; }
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . ECM_DB::table( 'flow_instances' ) . ' WHERE event_id=%d AND identifier_digest=%s LIMIT 1',
			$event_id, $digest
		) );
	}

	private static function identity_instance( int $event_id, string $mobile ): ?object {
		$digest = ECM_Workflow::identity_digest( $event_id, $mobile );
		if ( is_wp_error( $digest ) ) { return null; }
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id,event_id,state,lock_version,identifier_digest FROM ' . ECM_DB::table( 'flow_instances' ) . ' WHERE event_id=%d AND identifier_digest=%s ORDER BY id DESC LIMIT 2',
			$event_id, $digest
		) );
		return is_array( $rows ) && count( $rows ) === 1 ? $rows[0] : null;
	}

	/** Called by persistence APIs only during nonce-verified, session-bound POST. */
	public static function write_authorized( int $instance_id ): bool {
		return self::available() && self::$authorized_write === $instance_id;
	}

	private static function post_event(): object {
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$event = $event_id ? ECM_Events::event( $event_id ) : null;
		if ( ! $event || ! self::available( $event ) ) {
			wp_die( 'ثبت‌نام اختصاصی برای این رویداد فعال نیست.', '', array( 'response' => 403 ) );
		}
		return $event;
	}
	private static function verify_nonce( int $event_id, int $instance_id = 0 ): void {
		$nonce = sanitize_text_field( (string) wp_unslash( $_POST['ecm_flow_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'ecm_flow_public_' . $event_id . '_' . $instance_id ) ) {
			wp_die( 'درخواست نامعتبر است.', '', array( 'response' => 403 ) );
		}
	}
	private static function rate_limit( string $scope, int $limit, int $period ): bool {
		$client = sanitize_text_field( (string) ( $_COOKIE['ecm_client'] ?? '' ) );
		$ip = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		// Both limits must pass. An attacker cannot reset the IP budget by forging ecm_client.
		$dimensions = array(
			array( 'browser:' . $client . ':' . $ip, $limit ),
			// The browser cookie is attacker-controlled. Enforce a meaningful
			// network-wide ceiling even when clients rotate that cookie.
			array( 'network:' . $ip, max( 60, $limit * 8 ) ),
		);
		foreach ( $dimensions as $dimension ) {
			$key = self::key( 'ecm_flow_limit_', $scope . ':' . $dimension[0] );
			if ( (int) get_transient( $key ) >= $dimension[1] ) { return false; }
		}
		foreach ( $dimensions as $dimension ) {
			$key = self::key( 'ecm_flow_limit_', $scope . ':' . $dimension[0] );
			set_transient( $key, (int) get_transient( $key ) + 1, $period );
		}
		return true;
	}

	private static function fail( object $event, string $text ): void {
		$token = self::token();
		set_transient( self::key( 'ecm_flow_notice_', $token ), substr( sanitize_text_field( $text ), 0, 300 ), 120 );
		self::cookie( self::NOTICE_COOKIE, $token, 120 );
		self::redirect( $event );
	}
	private static function redirect( object $event ): void {
		wp_safe_redirect( ECM_Events::registration_url( $event ), 303 );
		exit;
	}
	private static function message(): string {
		$token = (string) ( $_COOKIE[ self::NOTICE_COOKIE ] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{48}$/D', $token ) ) { return ''; }
		$key = self::key( 'ecm_flow_notice_', $token );
		$message = (string) get_transient( $key );
		delete_transient( $key );
		self::cookie( self::NOTICE_COOKIE, '', 0 );
		return $message;
	}

	/** Controlled four-stage submission, using the same validator as the admin preview. */
	public static function handle_step(): void {
		$event = self::post_event();
		$instance_id = absint( $_POST['instance_id'] ?? 0 );
		self::verify_nonce( (int) $event->id, $instance_id );
		if ( ! empty( $_POST['website'] ) || ! self::rate_limit( 'step_' . (int) $event->id, 45, HOUR_IN_SECONDS ) ) {
			self::fail( $event, 'تعداد درخواست‌ها بیش از حد مجاز است.' );
		}
		if ( ! $instance_id ) {
			$data = ECM_Workflow_Staging::validated_fields( $event );
			if ( is_wp_error( $data ) ) { self::fail( $event, $data->get_error_message() ); }
			if ( ! self::otp_available() ) { self::fail( $event, 'تأیید موبایل برای ثبت‌نام فعال نیست.' ); }
			if ( self::identity_exists( (int) $event->id, $data['mobile'] ) ) {
				self::fail( $event, 'اگر قبلاً ثبت‌نام کرده‌اید، با تأیید شماره موبایل درخواست را بازیابی کنید.' );
			}
			self::start_challenge( $event, $data['mobile'], 'enroll', array(
				'mobile' => $data['mobile'], 'fields' => $data['data'],
				'schema_hash' => ECM_Workflow_Instances::form_schema_hash( $event ),
			) );
		} else {
			$row = self::session_for( (int) $event->id );
			if ( ! $row || (int) $row->id !== $instance_id || (int) $row->lock_version !== absint( $_POST['revision'] ?? -1 ) ) {
				self::fail( $event, 'نشست درخواست معتبر نیست یا اطلاعات آن تغییر کرده است.' );
			}
			self::$authorized_write = $instance_id;
			try {
				if ( 'draft' === $row->state ) {
					$result = ECM_Workflow_Instances::advance_draft( $instance_id, (int) $row->lock_version );
				} elseif ( 'awaiting_agreement' === $row->state ) {
					$result = (string) ( $_POST['ecm_agreed'] ?? '' ) === '1'
						? ECM_Workflow_Instances::accept_agreement( $instance_id, (int) $row->lock_version )
						: new WP_Error( 'ecm_flow_agreement_required', 'تأیید میثاق‌نامه ضروری است.' );
				} elseif ( 'awaiting_receipt' === $row->state ) {
					$file = isset( $_FILES['ecm_receipt'] ) && is_array( $_FILES['ecm_receipt'] ) ? $_FILES['ecm_receipt'] : array();
					$result = ECM_Workflow_Instances::submit_receipt( $instance_id, (int) $row->lock_version, $file );
				} elseif ( 'needs_correction' === $row->state ) {
					$result = ECM_Workflow_Instances::resume_correction( $instance_id, (int) $row->lock_version );
				} else {
					$result = new WP_Error( 'ecm_flow_invalid_step', 'این درخواست آماده انتقال مرحله نیست.' );
				}
			} finally { self::$authorized_write = -1; }
		}
		if ( is_wp_error( $result ) ) { self::fail( $event, $result->get_error_message() ); }
		self::redirect( $event );
	}

	/** Recover by tracking+mobile OR by mobile alone, always behind a phone OTP. */
	public static function handle_resume(): void {
		$event = self::post_event();
		self::verify_nonce( (int) $event->id );
		if ( ! self::otp_available() || ! self::rate_limit( 'resume_' . (int) $event->id, 8, HOUR_IN_SECONDS ) ) {
			self::fail( $event, 'بازیابی فعال نیست یا تعداد تلاش‌ها بیش از حد مجاز است.' );
		}
		$code = strtoupper( trim( ECM_Utils::normalize_digits( sanitize_text_field( (string) wp_unslash( $_POST['flow_code'] ?? '' ) ) ) ) );
		$phone = ECM_Utils::normalize_mobile( sanitize_text_field( (string) wp_unslash( $_POST['flow_phone'] ?? '' ) ) );
		if ( ! $phone ) { self::fail( $event, 'درخواست معتبر نیست.' ); }
		if ( '' !== $code ) {
			$found = ECM_Workflow_Tracking::find( $code, $phone, false );
			if ( is_wp_error( $found ) || (int) $found->event_id !== (int) $event->id ) {
				self::fail( $event, 'درخواستی با این مشخصات یافت نشد.' );
			}
			self::start_challenge( $event, $phone, 'tracked_resume', array( 'instance_id' => (int) $found->id, 'tracking' => $code ) );
		}
		$found = self::identity_instance( (int) $event->id, $phone );
		if ( ! $found ) { self::fail( $event, 'امکان بازیابی وجود ندارد. در صورت وجود چند درخواست، از پشتیبانی رویداد کمک بگیرید.' ); }
		self::start_challenge( $event, $phone, 'phone_resume', array( 'instance_id' => (int) $found->id, 'identity_digest' => (string) $found->identifier_digest ) );
	}

	public static function handle_verify(): void {
		$event = self::post_event();
		self::verify_nonce( (int) $event->id );
		$challenge = (string) ( $_COOKIE[ self::CHALLENGE_COOKIE ] ?? '' );
		if ( ! self::otp_available() || ! preg_match( '/^[a-f0-9]{48}$/D', $challenge )
			|| ! self::rate_limit( 'verify_' . (int) $event->id, 16, HOUR_IN_SECONDS ) ) {
			self::fail( $event, 'کد تأیید معتبر نیست یا منقضی شده است.' );
		}
		$key = self::key( 'ecm_flow_challenge_', $challenge );
		$data = get_transient( $key );
		$pin = ECM_Utils::normalize_digits( sanitize_text_field( (string) wp_unslash( $_POST['otp_code'] ?? '' ) ) );
		if ( ! is_array( $data ) || (int) $data['event_id'] !== (int) $event->id
			|| ! hash_equals( (string) $data['client_hash'], self::client_hash() ) || (int) $data['attempts'] >= 5 ) {
			self::fail( $event, 'کد تأیید معتبر نیست یا منقضی شده است.' );
		}
		if ( ! preg_match( '/^\d{6}$/D', $pin ) || ! wp_check_password( $pin, (string) $data['otp_hash'] ) ) {
			$data['attempts'] = (int) $data['attempts'] + 1;
			if ( $data['attempts'] >= 5 ) { delete_transient( $key ); self::cookie( self::CHALLENGE_COOKIE, '', 0 ); }
			else { set_transient( $key, $data, 5 * MINUTE_IN_SECONDS ); }
			self::fail( $event, 'کد تأیید معتبر نیست یا منقضی شده است.' );
		}
		$purpose = (string) ( $data['purpose'] ?? '' );
		$payload = self::unseal( (string) ( $data['payload'] ?? '' ), $purpose );
		if ( ! $payload || ! in_array( $purpose, array( 'enroll', 'tracked_resume', 'phone_resume' ), true ) ) {
			self::fail( $event, 'اطلاعات چالش تأیید معتبر نیست.' );
		}
		// Delete before mutating persistent state. OTP cannot be replayed in parallel requests.
		delete_transient( $key );
		self::cookie( self::CHALLENGE_COOKIE, '', 0 );
		if ( 'enroll' === $purpose ) {
			$phone = ECM_Utils::normalize_mobile( (string) ( $payload['mobile'] ?? '' ) );
			$fields = $payload['fields'] ?? null;
			if ( ! $phone || ! is_array( $fields ) || (string) ( $payload['schema_hash'] ?? '' ) !== ECM_Workflow_Instances::form_schema_hash( $event ) ) {
				self::fail( $event, 'فیلدهای رویداد تغییر کرده‌اند. فرم را دوباره تکمیل کنید.' );
			}
			// MySQL advisory lock serializes simultaneous OTP-completed submissions for this identity.
			global $wpdb;
			$identity_digest = ECM_Workflow::identity_digest( (int) $event->id, $phone );
			if ( is_wp_error( $identity_digest ) ) { self::fail( $event, 'شماره موبایل معتبر نیست.' ); }
			$lock_name = 'ecm_enroll_' . substr( (string) $identity_digest, 0, 42 );
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock_name ) ) ) {
				self::fail( $event, 'ثبت هم‌زمان در جریان است. دوباره تلاش کنید.' );
			}
			try {
				if ( self::identity_exists( (int) $event->id, $phone ) ) {
					self::fail( $event, 'درخواستی با این شماره وجود دارد؛ از قسمت بازیابی استفاده کنید.' );
				}
				self::$authorized_write = 0;
				try { $created = ECM_Workflow_Instances::create_draft( (int) $event->id, $phone, $fields ); }
				finally { self::$authorized_write = -1; }
			} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); }
			if ( is_wp_error( $created ) ) { self::fail( $event, $created->get_error_message() ); }
			$id = (int) $created['id'];
			self::authorize_session( (int) $event->id, $id, (string) $created['tracking_code'] );
			self::$authorized_write = $id;
			try { $result = ECM_Workflow_Instances::advance_draft( $id, 0 ); }
			finally { self::$authorized_write = -1; }
			if ( is_wp_error( $result ) ) { self::fail( $event, 'شماره تأیید شد، اما ذخیره مرحله کامل نشد. از همین مرورگر دوباره ادامه دهید.' ); }
			// Audit metadata contains no OTP, raw phone, tracking code or form payload.
			$wpdb->insert( ECM_DB::table( 'events' ), array(
				'registration_id'=>0, 'domain_event_id'=>(int) $event->id, 'flow_instance_id'=>$id,
				'actor_user_id'=>0, 'correlation_id'=>'flow:' . (string) $created['public_id'],
				'event_type'=>'workflow_mobile_verified', 'meta'=>wp_json_encode( array( 'method'=>'initial_otp' ) ),
				'created_at'=>ECM_Utils::now_utc(),
			) );
			self::redirect( $event );
		}
		$id = absint( $payload['instance_id'] ?? 0 );
		if ( ! $id ) { self::fail( $event, 'درخواست بازیابی معتبر نیست.' ); }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ECM_DB::table( 'flow_instances' ) . ' WHERE id=%d AND event_id=%d', $id, (int) $event->id ) );
		if ( ! $row ) { self::fail( $event, 'درخواست پیدا نشد.' ); }
		if ( 'tracked_resume' === $purpose ) {
			$code = (string) ( $payload['tracking'] ?? '' );
			if ( ! preg_match( '/^[A-F0-9]{32}$/D', $code ) || ! hash_equals( (string) $row->tracking_hash, ECM_Workflow::tracking_digest( $code ) ) ) {
				self::fail( $event, 'کد پیگیری تغییر کرده است؛ بازیابی را دوباره آغاز کنید.' );
			}
		} else {
			if ( ! hash_equals( (string) $row->identifier_digest, (string) ( $payload['identity_digest'] ?? '' ) ) ) {
				self::fail( $event, 'مالکیت درخواست تغییر کرده است.' );
			}
			self::$authorized_write = $id;
			try { $code = ECM_Workflow_Instances::rotate_tracking( $id, (int) $row->lock_version ); }
			finally { self::$authorized_write = -1; }
			if ( is_wp_error( $code ) ) { self::fail( $event, $code->get_error_message() ); }
		}
		self::authorize_session( (int) $event->id, $id, $code );
		self::redirect( $event );
	}

	/** Edit never changes the verified primary mobile identity. */
	public static function handle_edit(): void {
		$event = self::post_event();
		$instance_id = absint( $_POST['instance_id'] ?? 0 );
		self::verify_nonce( (int) $event->id, $instance_id );
		$row = self::session_for( (int) $event->id );
		if ( ! $row || (int) $row->id !== $instance_id || (int) $row->lock_version !== absint( $_POST['revision'] ?? -1 )
			|| ! self::rate_limit( 'edit_' . $instance_id, 18, HOUR_IN_SECONDS ) ) {
			self::fail( $event, 'نشست ویرایش معتبر نیست یا درخواست تغییر کرده است.' );
		}
		$validated = ECM_Workflow_Staging::validated_fields( $event );
		if ( is_wp_error( $validated ) ) { self::fail( $event, $validated->get_error_message() ); }
		self::$authorized_write = $instance_id;
		try { $updated = ECM_Workflow_Instances::update_fields( $instance_id, (int) $row->lock_version, $validated['mobile'], $validated['data'] ); }
		finally { self::$authorized_write = -1; }
		if ( is_wp_error( $updated ) ) { self::fail( $event, $updated->get_error_message() ); }
		self::redirect( $event );
	}

	private static function client_hash(): string {
		return hash_hmac( 'sha256', (string) ( $_COOKIE['ecm_client'] ?? '' ) . ':' . (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), wp_salt( 'nonce' ) );
	}
	private static function otp_available(): bool {
		return self::available() && defined( 'ECM_WORKFLOW_STAGING_OTP' ) && true === ECM_WORKFLOW_STAGING_OTP;
	}

	public static function shortcode(): string {
		$event = ECM_Events::requested_event();
		return $event ? self::render( $event ) : '<p>رویداد معتبری انتخاب نشده است.</p>';
	}

	public static function render( object $event ): string {
		if ( ! self::available( $event ) ) { return '<p>ثبت‌نام اختصاصی فعلاً در دسترس نیست.</p>'; }
		wp_enqueue_style( 'ecm-flow-public' );
		wp_enqueue_style( 'ecm-public' );
		wp_enqueue_script( 'ecm-public' );
		wp_enqueue_script( 'ecm-flow-public' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
		$session = self::session();
		$row = self::session_for( (int) $event->id );
		$version = $row ? self::version( $row ) : ECM_Workflow_Versions::published( (int) $event->id );
		$definition = $version ? json_decode( (string) $version->definition_json, true ) : null;
		if ( ! is_array( $definition ) || array_column( (array) ( $definition['steps'] ?? array() ), 'type' ) !== array( 'form', 'agreement', 'receipt', 'result' ) ) {
			return '<p>تعریف فرایند معتبر نیست.</p>';
		}
		$state = $row ? (string) $row->state : 'draft';
		$index = ! $row ? 0 : ( 'awaiting_agreement' === $state ? 1 : ( 'awaiting_receipt' === $state ? 2 : 3 ) );
		$step = (array) $definition['steps'][ $index ];
		$code = $session && $row && (int) $session['instance_id'] === (int) $row->id && (int) $session['event_id'] === (int) $event->id
			? self::decrypt_tracking( (string) ( $session['tracking_cipher'] ?? '' ), $row ) : '';
		$components = array(
			'ecm_fields' => ECM_Workflow_Staging::form_fields( $event ),
			'ecm_agreement' => '<label class="ecm-flow-consent"><input type="checkbox" name="ecm_agreed" value="1" required> ' . esc_html( $step['consent_label'] ?? 'شرایط را مطالعه کردم و موافقم.' ) . '</label>',
			'ecm_payment_details' => '<div class="ecm-flow-payment"><p>شماره کارت: <bdi>' . esc_html( (string) ( $step['card_number'] ?? 'تنظیم نشده' ) ) . '</bdi></p><p>' . esc_html( (string) ( $step['account_holder'] ?? '' ) ) . '</p><p>' . nl2br( esc_html( (string) ( $step['payment_note'] ?? '' ) ) ) . '</p></div>',
			'ecm_receipt_upload' => '<label>تصویر رسید (JPEG / PNG / WebP، حداکثر ۸ مگابایت)<input type="file" name="ecm_receipt" accept="image/jpeg,image/png,image/webp" capture="environment" required></label>',
			'ecm_status' => '<strong>' . esc_html( ECM_Workflow_Review::state_label( $state ) ) . '</strong>',
			'ecm_tracking_code' => $code && in_array( $state, array( 'under_review', 'needs_correction', 'approved', 'rejected' ), true ) ? '<strong dir="ltr">' . esc_html( $code ) . '</strong>' : '<span>کد پیگیری پس از ارسال رسید نمایش داده می‌شود.</span>',
		);
		$saved = $row ? (array) json_decode( (string) $row->data_json, true ) : array();
		$config = ECM_Events::config( $event );
		$allowed_fields = array();
		foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( $key && 'html' !== (string) ( $field['type'] ?? '' ) ) { $allowed_fields[$key] = (array) $field; }
		}
		$components['ecm_field'] = static function ( string $key ) use ( $event, $allowed_fields, $index, $saved ): string {
			if ( 0 !== $index || ! isset( $allowed_fields[$key] ) ) { return ''; }
			$public = new ECM_Public();
			return $public->render_field( $allowed_fields[$key], (string) ( $saved[$key] ?? '' ), $event );
		};
		$components['ecm_value'] = static function ( string $key ) use ( $allowed_fields, $saved ): string {
			return isset( $allowed_fields[$key] ) && isset( $saved[$key] ) && is_scalar( $saved[$key] )
				? esc_html( (string) $saved[$key] ) : '';
		};
		$components['ecm_event_title'] = esc_html( (string) $event->title );
		$components['ecm_step_progress'] = esc_html( ( $index + 1 ) . ' / ۴' );
		$components['ecm_rejection_reason'] = $row && in_array( $state, array( 'needs_correction', 'rejected' ), true ) ? esc_html( self::public_reason( (int) $row->id ) ) : '';
		$components['ecm_form_summary'] = $row ? self::form_summary( $row ) : '';
		$components['ecm_card_access'] = $row && 'approved' === $state ? self::card_link( $row ) : '';
		$components['ecm_edit_fields'] = $row && self::editable_state( $state ) ? '<a href="' . esc_url( add_query_arg( 'ecm_flow_edit', '1', ECM_Events::registration_url( $event ) ) ) . '">ویرایش اطلاعات فرم</a>' : '';
		ob_start();
		?>
		<section class="ecm-flow-public" dir="rtl"><header><h1><?php echo esc_html( $event->title ); ?></h1><p>فرایند ثبت‌نام چندمرحله‌ای | محیط آزمایشی</p></header>
		<?php $notice = self::message(); if ( $notice ) : ?><p class="ecm-flow-error" role="alert"><?php echo esc_html( $notice ); ?></p><?php endif; ?>
		<div class="ecm-flow-steps"><?php echo esc_html( 'مرحله ' . ( $index + 1 ) . ' از ۴: ' . $step['label'] ); ?></div>
		<?php if ( $row && self::editable_state( $state ) && isset( $_GET['ecm_flow_edit'] ) ) : ?>
			<h2>ویرایش اطلاعات درخواست</h2><p>شماره موبایل احرازشده قابل تغییر نیست. اطلاعات دیگر را اصلاح کنید.</p>
			<form class="ecm-flow-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecm_flow_public_edit"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event->id ); ?>"><input type="hidden" name="instance_id" value="<?php echo esc_attr( $row->id ); ?>"><input type="hidden" name="revision" value="<?php echo esc_attr( $row->lock_version ); ?>">
				<?php wp_nonce_field( 'ecm_flow_public_' . $event->id . '_' . $row->id, 'ecm_flow_nonce' ); ?>
				<?php echo ECM_Workflow_Staging::form_fields( $event, (array) json_decode( (string) $row->data_json, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p><button class="ecm-flow-button" type="submit">ذخیره اصلاحات</button> <a href="<?php echo esc_url( ECM_Events::registration_url( $event ) ); ?>">انصراف از ویرایش</a></p>
			</form>
		<?php elseif ( $row && 'needs_correction' === $state ) : ?>
			<p role="status">درخواست شما نیازمند اصلاح است. <?php echo esc_html( self::public_reason( (int) $row->id ) ); ?></p>
			<?php self::step_form_start( $event, $row ); ?>
			<p><button class="ecm-flow-button" type="submit">بازگشت به مرحله ارسال رسید</button></p></form>
		<?php elseif ( ( ! $row && ! self::challenge_pending( (int) $event->id ) ) || in_array( $state, array( 'awaiting_agreement', 'awaiting_receipt' ), true ) ) : ?>
			<?php self::step_form_start( $event, $row ); ?>
			<?php echo ECM_Workflow_Templates::render( $step, $components ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><button class="ecm-flow-button" type="submit"><?php echo esc_html( 'awaiting_receipt' === $state ? 'ارسال رسید برای بررسی' : 'مرحله بعد' ); ?></button></p>
			</form>
		<?php elseif ( ! $row && self::challenge_pending( (int) $event->id ) ) : ?>
			<p role="status">کد تأیید برای این مرورگر درخواست شده است. کد پیامکی را در بخش پایین وارد کنید.</p>
		<?php elseif ( 'draft' === $state ) : ?>
			<p>شماره شما تأیید شده است. برای ادامه ثبت درخواست روی دکمه زیر بزنید.</p>
			<?php self::step_form_start( $event, $row ); ?><button class="ecm-flow-button" type="submit">ادامه ثبت درخواست</button></form>
		<?php else : ?>
			<?php echo ECM_Workflow_Templates::render( $step, $components ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( in_array( $state, array( 'rejected', 'needs_correction' ), true ) ) : ?><p>علت: <?php echo esc_html( self::public_reason( (int) $row->id ) ); ?></p><?php endif; ?>
			<?php if ( 'approved' === $state ) : ?><?php echo self::card_link( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endif; ?>
			<?php if ( 'under_review' === $state ) : ?><p>درخواست شما ثبت شد و در انتظار بررسی مدیر است.</p><?php endif; ?>
		<?php endif; ?>
		<?php if ( $row && self::editable_state( $state ) && ! isset( $_GET['ecm_flow_edit'] ) ) : ?><p><a href="<?php echo esc_url( add_query_arg( 'ecm_flow_edit', '1', ECM_Events::registration_url( $event ) ) ); ?>">ویرایش اطلاعات ثبت‌نام</a></p><?php endif; ?>
		<?php if ( ! $row ) : ?>
			<details class="ecm-flow-resume" <?php echo self::challenge_pending( (int) $event->id ) ? 'open' : ''; ?>><summary>قبلاً ثبت‌نام کرده‌ام؛ ادامه یا پیگیری درخواست</summary>
			<?php if ( self::otp_available() ) : ?>
			<?php if ( self::challenge_pending( (int) $event->id ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecm_flow_public_verify"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event->id ); ?>">
				<?php wp_nonce_field( 'ecm_flow_public_' . $event->id . '_0', 'ecm_flow_nonce' ); ?>
				<label>کد تأیید پیامکی<input name="otp_code" type="text" required pattern="[0-9۰-۹]{6}" autocomplete="one-time-code" inputmode="numeric" maxlength="6"></label>
				<button class="ecm-flow-button">تأیید و ادامه</button>
			</form>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecm_flow_public_resume"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event->id ); ?>">
				<?php wp_nonce_field( 'ecm_flow_public_' . $event->id . '_0', 'ecm_flow_nonce' ); ?>
				<label>کد پیگیری (اختیاری؛ بازیابی با موبایل و OTP نیز امکان‌پذیر است)<input name="flow_code" maxlength="32" autocomplete="off" dir="ltr"></label>
				<label>موبایل ثبت‌نام<input name="flow_phone" required inputmode="tel" autocomplete="tel" dir="ltr"></label>
				<button class="ecm-flow-button">ارسال کد تأیید</button>
			</form>
			<?php endif; ?>
			<?php else : ?><p>بازیابی در مرورگر دیگر، نیازمند فعال‌سازی مجزای OTP پیامکی در محیط آزمایشی است.</p><?php endif; ?></details>
		<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function editable_state( string $state ): bool {
		return in_array( $state, array( 'awaiting_agreement', 'awaiting_receipt', 'needs_correction' ), true );
	}
	private static function form_summary( object $row ): string {
		$data = json_decode( (string) $row->data_json, true );
		if ( ! is_array( $data ) ) { return ''; }
		$items = array();
		foreach ( $data as $key => $value ) {
			if ( str_starts_with( (string) $key, '__' ) || ! is_scalar( $value ) ) { continue; }
			$items[] = '<li><b>' . esc_html( (string) $key ) . ':</b> ' . esc_html( (string) $value ) . '</li>';
			if ( count( $items ) >= 25 ) { break; }
		}
		return $items ? '<ul class="ecm-flow-summary">' . implode( '', $items ) . '</ul>' : '';
	}
	private static function step_form_start( object $event, ?object $row ): void {
		$instance_id = $row ? (int) $row->id : 0;
		?>
		<form class="ecm-flow-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ecm_flow_public_step">
			<input type="hidden" name="event_id" value="<?php echo esc_attr( $event->id ); ?>">
			<input type="hidden" name="instance_id" value="<?php echo esc_attr( $instance_id ); ?>">
			<input type="hidden" name="revision" value="<?php echo esc_attr( $row ? $row->lock_version : 0 ); ?>">
			<?php wp_nonce_field( 'ecm_flow_public_' . $event->id . '_' . $instance_id, 'ecm_flow_nonce' ); ?>
			<label class="ecm-flow-honey" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
		<?php
	}

	private static function version( object $row ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ECM_DB::table( 'flow_versions' ) . ' WHERE id=%d AND event_id=%d', (int) $row->flow_version_id, (int) $row->event_id ) ) ?: null;
	}
	private static function public_reason( int $instance_id ): string {
		global $wpdb;
		$meta = $wpdb->get_var( $wpdb->prepare( 'SELECT meta FROM ' . ECM_DB::table( 'events' ) . " WHERE flow_instance_id=%d AND event_type='workflow_review' ORDER BY id DESC LIMIT 1", $instance_id ) );
		$data = $meta ? json_decode( (string) $meta, true ) : null;
		return is_array( $data ) ? (string) ( $data['reason_public'] ?? '' ) : '';
	}
	private static function card_link( object $row ): string {
		if ( ! (int) $row->linked_registration_id ) { return ''; }
		$registration = ECM_DB::registration( (int) $row->linked_registration_id );
		if ( ! $registration || (int) $registration->event_id !== (int) $row->event_id || 'offline_confirmed' !== (string) $registration->payment_status ) { return ''; }
		global $wpdb;
		$person = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ECM_DB::table( 'participants' ) . " WHERE registration_id=%d AND participant_type='primary' AND status='active' LIMIT 1", (int) $row->linked_registration_id ) );
		$url = $person ? ECM_Tickets::card_url( $person ) : '';
		return $url ? '<p><a class="ecm-flow-button" href="' . esc_url( $url ) . '" rel="noopener noreferrer">مشاهده کارت تأییدشده</a></p>' : '';
	}
	private static function challenge_pending( int $event_id ): bool {
		$challenge = (string) ( $_COOKIE[ self::CHALLENGE_COOKIE ] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{48}$/D', $challenge ) ) { return false; }
		$data = get_transient( self::key( 'ecm_flow_challenge_', $challenge ) );
		return is_array( $data ) && (int) $data['event_id'] === $event_id && hash_equals( (string) $data['client_hash'], self::client_hash() );
	}
}
