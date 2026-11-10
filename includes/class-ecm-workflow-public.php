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
		foreach ( array( 'step', 'resume', 'verify' ) as $operation ) {
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
		$key = self::key( 'ecm_flow_limit_', $scope . ':' . $client . ':' . $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) { return false; }
		set_transient( $key, $count + 1, $period );
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
			$digest = ECM_Workflow::identity_digest( (int) $event->id, $data['mobile'] );
			if ( is_wp_error( $digest ) ) { self::fail( $event, 'شماره موبایل معتبر نیست.' ); }
			global $wpdb;
			$existing = $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . ECM_DB::table( 'flow_instances' ) . " WHERE event_id=%d AND identifier_digest=%s AND state NOT IN ('cancelled','expired','rejected') LIMIT 1",
				(int) $event->id, (string) $digest
			) );
			if ( $existing ) {
				self::fail( $event, 'درخواستی برای این شماره وجود دارد. برای ادامه از بازیابی درخواست استفاده کنید.' );
			}
			self::$authorized_write = 0;
			try {
				$created = ECM_Workflow_Instances::create_draft( (int) $event->id, $data['mobile'], $data['data'] );
				if ( is_wp_error( $created ) ) { self::fail( $event, $created->get_error_message() ); }
				$instance_id = (int) $created['id'];
				self::authorize_session( (int) $event->id, $instance_id, (string) $created['tracking_code'] );
				self::$authorized_write = $instance_id;
				$result = ECM_Workflow_Instances::advance_draft( $instance_id, 0 );
			} finally { self::$authorized_write = -1; }
		} else {
			$row = self::session_for( (int) $event->id );
			if ( ! $row || (int) $row->id !== $instance_id || (int) $row->lock_version !== absint( $_POST['revision'] ?? -1 ) ) {
				self::fail( $event, 'نشست درخواست معتبر نیست یا اطلاعات آن تغییر کرده است.' );
			}
			self::$authorized_write = $instance_id;
			try {
				if ( 'awaiting_agreement' === $row->state ) {
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

	/** Cross-device recovery starts only with a correct 128-bit secret + matching phone. */
	public static function handle_resume(): void {
		$event = self::post_event();
		self::verify_nonce( (int) $event->id );
		if ( ! self::otp_available() || ! self::rate_limit( 'resume_' . (int) $event->id, 8, HOUR_IN_SECONDS ) ) {
			self::fail( $event, 'درخواست بازیابی در دسترس نیست یا تعداد تلاش‌ها بیش از حد مجاز است.' );
		}
		$code = strtoupper( trim( ECM_Utils::normalize_digits( sanitize_text_field( (string) wp_unslash( $_POST['flow_code'] ?? '' ) ) ) ) );
		$phone = ECM_Utils::normalize_mobile( sanitize_text_field( (string) wp_unslash( $_POST['flow_phone'] ?? '' ) ) );
		if ( ! preg_match( '/^[A-F0-9]{32}$/D', $code ) || ! $phone ) {
			self::fail( $event, 'امکان بازیابی با اطلاعات واردشده وجود ندارد.' );
		}
		$found = ECM_Workflow_Tracking::find( $code, $phone, false );
		if ( is_wp_error( $found ) || (int) $found->event_id !== (int) $event->id ) {
			self::fail( $event, 'امکان بازیابی با اطلاعات واردشده وجود ندارد.' );
		}
		$challenge = self::token();
		$pin = (string) random_int( 100000, 999999 );
		$key = self::key( 'ecm_flow_challenge_', $challenge );
		set_transient( $key, array(
			'instance_id' => (int) $found->id, 'event_id' => (int) $event->id,
			'otp_hash' => wp_hash_password( $pin ), 'attempts' => 0,
			'client_hash' => self::client_hash(),
		), 5 * MINUTE_IN_SECONDS );
		$result = ECM_SMS::send_secure_pattern( 'recovery_otp', $phone, array( 'code' => $pin ), array( 'event_id' => (int) $event->id ) );
		if ( is_wp_error( $result ) ) {
			delete_transient( $key );
			self::fail( $event, 'ارسال کد تأیید ممکن نشد. تنظیمات سرویس پیامک آزمایشی را بررسی کنید.' );
		}
		self::cookie( self::CHALLENGE_COOKIE, $challenge, 5 * MINUTE_IN_SECONDS );
		self::redirect( $event );
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
		delete_transient( $key );
		self::cookie( self::CHALLENGE_COOKIE, '', 0 );
		self::authorize_session( (int) $event->id, (int) $data['instance_id'] );
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
		ob_start();
		?>
		<section class="ecm-flow-public" dir="rtl"><header><h1><?php echo esc_html( $event->title ); ?></h1><p>فرایند ثبت‌نام چندمرحله‌ای | محیط آزمایشی</p></header>
		<?php $notice = self::message(); if ( $notice ) : ?><p class="ecm-flow-error" role="alert"><?php echo esc_html( $notice ); ?></p><?php endif; ?>
		<div class="ecm-flow-steps"><?php echo esc_html( 'مرحله ' . ( $index + 1 ) . ' از ۴: ' . $step['label'] ); ?></div>
		<?php if ( $row && 'needs_correction' === $state ) : ?>
			<p role="status">درخواست شما نیازمند اصلاح است. <?php echo esc_html( self::public_reason( (int) $row->id ) ); ?></p>
			<?php self::step_form_start( $event, $row ); ?>
			<p><button class="ecm-flow-button" type="submit">بازگشت به مرحله ارسال رسید</button></p></form>
		<?php elseif ( ! $row || in_array( $state, array( 'awaiting_agreement', 'awaiting_receipt' ), true ) ) : ?>
			<?php self::step_form_start( $event, $row ); ?>
			<?php echo ECM_Workflow_Templates::render( $step, $components ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><button class="ecm-flow-button" type="submit"><?php echo esc_html( 'awaiting_receipt' === $state ? 'ارسال رسید برای بررسی' : 'مرحله بعد' ); ?></button></p>
			</form>
		<?php elseif ( 'draft' === $state ) : ?>
			<p>فرایند در مرحله ثبت پیش‌نویس متوقف شده است. برای ادامه، از یک نشست جدید استفاده کنید.</p>
		<?php else : ?>
			<?php echo ECM_Workflow_Templates::render( $step, $components ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( in_array( $state, array( 'rejected', 'needs_correction' ), true ) ) : ?><p>علت: <?php echo esc_html( self::public_reason( (int) $row->id ) ); ?></p><?php endif; ?>
			<?php if ( 'approved' === $state ) : ?><?php echo self::card_link( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endif; ?>
			<?php if ( 'under_review' === $state ) : ?><p>درخواست شما ثبت شد و در انتظار بررسی مدیر است.</p><?php endif; ?>
		<?php endif; ?>
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
				<label>کد پیگیری<input name="flow_code" required maxlength="32" autocomplete="off" dir="ltr"></label>
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
