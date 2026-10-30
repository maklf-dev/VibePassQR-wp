<?php

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral SMS transport contract.
 *
 * Transports only deliver an already rendered payload. Event rules, recipient
 * selection, idempotency and retries remain owned by Event Card Manager.
 */
interface ECM_SMS_Transport_Interface {
	public function key(): string;

	public function label(): string;

	public function available(): bool;

	public function diagnostics(): array;

	/** @return array|WP_Error */
	public function connection_test();

	/** @return array|WP_Error */
	public function send( array $payload );
}

final class ECM_SMS_Transports {
	/** @return array<string,ECM_SMS_Transport_Interface> */
	public static function all(): array {
		$transports = array(
			'edge'      => new ECM_SMS_Transport_Edge(),
			'gv_bridge' => new ECM_SMS_Transport_GV_Bridge(),
			'legacy_direct' => new ECM_SMS_Transport_Legacy_Direct(),
			'wp_sms'    => new ECM_SMS_Transport_WP_SMS(),
		);
		return apply_filters( 'ecm_sms_transports', $transports );
	}

	public static function get( string $key = '' ): ?ECM_SMS_Transport_Interface {
		if ( ! $key ) {
			$key = sanitize_key( (string) ( ECM_Utils::settings()['sms_transport'] ?? 'edge' ) );
		}
		$all = self::all();
		return isset( $all[ $key ] ) && $all[ $key ] instanceof ECM_SMS_Transport_Interface ? $all[ $key ] : null;
	}

	public static function labels(): array {
		$output = array();
		foreach ( self::all() as $key => $transport ) {
			$output[ $key ] = $transport->label();
		}
		return $output;
	}
}

/**
 * Direct implementation of the same legacy IPPanel protocol used by the
 * verified Gravity Forms bridge. Credentials are read from that plugin's
 * existing option and are never copied to ECM logs or rendered in HTML.
 *
 * This transport intentionally remains opt-in. The provider's legacy pattern
 * endpoint carries credentials in the HTTPS query string, so Edge or the
 * bridge should be preferred whenever they are available.
 */
final class ECM_SMS_Transport_Legacy_Direct implements ECM_SMS_Transport_Interface {
	private const OPTION_NAMES = array( 'gf_ippanelsms_ippanel', 'gf_ippanelsms_IPPANEL' );

	public function key(): string {
		return 'legacy_direct';
	}

	public function label(): string {
		return 'اتصال مستقیم Legacy IPPanel (آزمایشی)';
	}

	public function available(): bool {
		$config = $this->config();
		return ! empty( $config['username'] ) && ! empty( $config['password'] ) && ! is_wp_error( $this->base_url( $config ) );
	}

	public function diagnostics(): array {
		$config = $this->config();
		$base   = $this->base_url( $config );
		return array(
			'available'     => $this->available(),
			'configured'    => ! empty( $config['username'] ) && ! empty( $config['password'] ),
			'secure'        => ! is_wp_error( $base ),
			'endpoint_host' => is_wp_error( $base ) ? 'نامعتبر' : (string) wp_parse_url( $base, PHP_URL_HOST ),
			'credential_source' => $config ? 'تنظیمات ذخیره‌شده افزونه IPPanel SMS Pro GV' : 'تنظیم نشده',
			'note'          => 'این روش همان پروتکل افزونه Gravity را بدون فراخوانی کد آن اجرا می‌کند و تا تأیید تست واقعی، آزمایشی است.',
		);
	}

	public function connection_test() {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$config  = $this->config();
		$started = microtime( true );
		$response = wp_remote_post(
			$this->base_url( $config ) . '/services.jspd',
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'body'        => array( 'uname' => $config['username'], 'pass' => $config['password'], 'op' => 'credit' ),
			)
		);
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'legacy_network_error', $response->get_error_message(), array( 'retryable' => true, 'latency_ms' => $elapsed ) );
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $http >= 200 && $http < 300 && is_array( $body ) && isset( $body[0] ) && '0' === (string) $body[0] ) {
			$balance = number_format_i18n( (float) ( $body[1] ?? 0 ), 0 ) . ' ریال';
			return array( 'success' => true, 'status' => 'ok', 'http_code' => $http, 'provider_id' => '', 'error_code' => '', 'message' => 'اتصال مستقیم Legacy تأیید شد.', 'balance' => $balance, 'retryable' => false, 'summary' => 'Legacy credit check succeeded.', 'latency_ms' => $elapsed, 'transport' => $this->key() );
		}
		return new WP_Error( 'legacy_connection_failed', $this->safe_failure( $http, $body ), array( 'retryable' => $http >= 500 || 429 === $http, 'http_code' => $http, 'latency_ms' => $elapsed ) );
	}

	public function send( array $payload ) {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$config     = $this->config();
		$recipients = array_values( array_filter( array_map( array( $this, 'legacy_recipient' ), (array) ( $payload['recipients'] ?? ( $payload['params']['recipients'] ?? array() ) ) ) ) );
		$sender     = preg_replace( '/[^0-9+]/', '', ECM_Utils::normalize_digits( (string) ( $payload['from_number'] ?? '' ) ) );
		if ( ! $recipients || ! $sender ) {
			return new WP_Error( 'legacy_invalid_payload', 'شماره فرستنده یا گیرنده برای اتصال مستقیم معتبر نیست.', array( 'retryable' => false ) );
		}
		$started = microtime( true );
		if ( 'pattern' === sanitize_key( (string) ( $payload['sending_type'] ?? '' ) ) ) {
			$code = sanitize_text_field( (string) ( $payload['code'] ?? '' ) );
			$params = array();
			foreach ( (array) ( $payload['params'] ?? array() ) as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( $key ) {
					$params[ $key ] = sanitize_text_field( (string) $value );
				}
			}
			if ( ! $code || ! $params ) {
				return new WP_Error( 'pattern_missing', 'کد یا پارامترهای پترن کامل نیست.', array( 'retryable' => false ) );
			}
			$query = array(
				'username'     => $config['username'],
				'password'     => $config['password'],
				'from'         => $sender,
				'to'           => wp_json_encode( $recipients ),
				'input_data'   => wp_json_encode( $params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'pattern_code' => $code,
			);
			$response = wp_remote_get( add_query_arg( $query, $this->base_url( $config ) . '/patterns/pattern' ), array( 'timeout' => 20, 'redirection' => 0, 'sslverify' => true ) );
		} else {
			$message = sanitize_textarea_field( (string) ( $payload['message'] ?? '' ) );
			if ( '' === trim( $message ) ) {
				return new WP_Error( 'message_empty', 'متن پیامک خالی است.', array( 'retryable' => false ) );
			}
			$response = wp_remote_post(
				$this->base_url( $config ) . '/services.jspd',
				array(
					'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
					'body' => array( 'uname' => $config['username'], 'pass' => $config['password'], 'from' => $sender, 'message' => $message, 'to' => wp_json_encode( $recipients ), 'op' => 'send' ),
				)
			);
		}
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'legacy_network_error', $response->get_error_message(), array( 'retryable' => true, 'latency_ms' => $elapsed ) );
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$accepted = $http >= 200 && $http < 300 && is_array( $body ) && isset( $body[0] ) && ( '0' === (string) $body[0] || 'sent' === strtolower( (string) $body[0] ) );
		if ( $accepted ) {
			return array( 'success' => true, 'status' => 'accepted', 'http_code' => $http, 'provider_id' => sanitize_text_field( is_scalar( $body[1] ?? '' ) ? (string) $body[1] : '' ), 'error_code' => '', 'message' => 'درخواست توسط اتصال مستقیم Legacy پذیرفته شد.', 'retryable' => false, 'summary' => 'Legacy provider accepted the request; delivery is unverified.', 'latency_ms' => $elapsed, 'transport' => $this->key() );
		}
		return new WP_Error( 'legacy_rejected', $this->safe_failure( $http, $body ), array( 'retryable' => $http >= 500 || 429 === $http, 'http_code' => $http, 'latency_ms' => $elapsed ) );
	}

	private function config(): array {
		foreach ( self::OPTION_NAMES as $option_name ) {
			$value = get_option( $option_name, array() );
			if ( is_array( $value ) && $value ) {
				return array(
					'username' => trim( (string) ( $value['username'] ?? '' ) ),
					'password' => trim( (string) ( $value['password'] ?? '' ) ),
					'domain'   => trim( (string) ( $value['domain'] ?? 'https://ippanel.com' ) ),
				);
			}
		}
		return array();
	}

	/** @return string|WP_Error */
	private function base_url( array $config ) {
		$domain = trim( (string) ( $config['domain'] ?? '' ) );
		if ( '' === $domain ) {
			$domain = 'https://ippanel.com';
		}
		if ( ! preg_match( '#^https?://#i', $domain ) ) {
			$domain = 'https://' . $domain;
		}
		$host = strtolower( (string) wp_parse_url( $domain, PHP_URL_HOST ) );
		$scheme = strtolower( (string) wp_parse_url( $domain, PHP_URL_SCHEME ) );
		$allowed_host = 'ippanel.com' === $host || str_ends_with( $host, '.ippanel.com' ) || 'ippanel.ir' === $host || str_ends_with( $host, '.ippanel.ir' );
		if ( 'https' !== $scheme || ! $allowed_host ) {
			return new WP_Error( 'legacy_insecure_endpoint', 'دامنه Legacy باید HTTPS و متعلق به IPPanel باشد.' );
		}
		return untrailingslashit( esc_url_raw( $domain ) );
	}

	/** @return true|WP_Error */
	private function guard() {
		$config = $this->config();
		if ( empty( $config['username'] ) || empty( $config['password'] ) ) {
			return new WP_Error( 'legacy_not_configured', 'نام کاربری و رمز در تنظیمات IPPanel SMS Pro GV کامل نیست.', array( 'retryable' => false ) );
		}
		$base = $this->base_url( $config );
		return is_wp_error( $base ) ? $base : true;
	}

	private function legacy_recipient( string $recipient ): string {
		$value = preg_replace( '/\D+/', '', ECM_Utils::normalize_digits( $recipient ) );
		if ( str_starts_with( $value, '0098' ) ) {
			$value = substr( $value, 4 );
		} elseif ( str_starts_with( $value, '98' ) ) {
			$value = substr( $value, 2 );
		} elseif ( str_starts_with( $value, '0' ) ) {
			$value = substr( $value, 1 );
		}
		return preg_match( '/^9\d{9}$/', $value ) ? $value : '';
	}

	private function safe_failure( int $http, $body ): string {
		$code = is_array( $body ) && isset( $body[0] ) && is_scalar( $body[0] ) ? sanitize_text_field( (string) $body[0] ) : 'invalid_response';
		return 'پاسخ Legacy پذیرفته نشد. HTTP ' . $http . ' — code: ' . $code;
	}
}

final class ECM_SMS_Transport_Edge implements ECM_SMS_Transport_Interface {
	private const API_BASE = 'https://edge.ippanel.com/v1';

	public function key(): string {
		return 'edge';
	}

	public function label(): string {
		return 'IPPanel Edge API';
	}

	public function available(): bool {
		return '' !== ECM_Upgrade::key();
	}

	public function diagnostics(): array {
		return array(
			'available'  => $this->available(),
			'configured' => $this->available(),
			'secure'     => true,
			'key_source' => ECM_Upgrade::key_source(),
			'endpoint'   => self::API_BASE,
			'note'       => 'Edge API به IP ایران نیاز دارد.',
		);
	}

	public function connection_test() {
		$response = $this->request( 'GET', '/api/number/numbers?page=1&per_page=1' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) $response['code'];
		if ( $code >= 200 && $code < 300 ) {
			return $this->result( true, 'ok', $code, '', '', 'اتصال و اعتبارسنجی Edge API موفق بود.', false, $response );
		}
		return $this->http_failure( $response );
	}

	public function send( array $payload ) {
		$response = $this->request( 'POST', '/api/send', $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) $response['code'];
		$body = (array) $response['decoded'];
		$outbox_ids = (array) ( $body['data']['message_outbox_ids'] ?? ( $body['outbox_ids'] ?? array() ) );
		$accepted = $code >= 200 && $code < 300 && ( true === ( $body['meta']['status'] ?? null ) || true === ( $body['status'] ?? null ) || ! empty( $outbox_ids ) );
		if ( $accepted ) {
			$ids = array_map( 'strval', $outbox_ids );
			return $this->result( true, 'accepted', $code, implode( ',', $ids ), '', 'درخواست توسط Edge API پذیرفته شد.', false, $response );
		}
		return $this->http_failure( $response );
	}

	/** @return array|WP_Error */
	public function request( string $method, string $path, ?array $payload = null, ?string $ip_mode = null, int $timeout = 20 ) {
		$key = ECM_Upgrade::key();
		if ( ! $key ) {
			return new WP_Error( 'configuration_missing', 'کلید API تنظیم نشده است.', array( 'retryable' => false ) );
		}
		$ip_mode = $ip_mode ?: (string) ( ECM_Utils::settings()['sms_ip_mode'] ?? 'auto' );
		$ip_mode = 'ipv4' === $ip_mode ? 'ipv4' : ( 'ipv6' === $ip_mode ? 'ipv6' : 'auto' );
		$args = array(
			'method'      => $method,
			'timeout'     => max( 3, min( 30, $timeout ) ),
			'redirection' => 0,
			'headers'     => array( 'Authorization' => $key, 'Accept' => 'application/json', 'Content-Type' => 'application/json' ),
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$url      = self::API_BASE . $path;
		$callback = null;
		if ( in_array( $ip_mode, array( 'ipv4', 'ipv6' ), true ) && defined( 'CURLOPT_IPRESOLVE' ) ) {
			$callback = static function ( $handle, array $parsed_args, string $request_url ) use ( $ip_mode ): void {
				if ( 'edge.ippanel.com' !== strtolower( (string) wp_parse_url( $request_url, PHP_URL_HOST ) ) ) {
					return;
				}
				$resolve = 'ipv4' === $ip_mode && defined( 'CURL_IPRESOLVE_V4' ) ? CURL_IPRESOLVE_V4 : ( defined( 'CURL_IPRESOLVE_V6' ) ? CURL_IPRESOLVE_V6 : null );
				if ( null !== $resolve ) {
					curl_setopt( $handle, CURLOPT_IPRESOLVE, $resolve );
				}
			};
			add_action( 'http_api_curl', $callback, 10, 3 );
		}
		$started = microtime( true );
		try {
			$response = wp_remote_request( $url, $args );
		} finally {
			if ( $callback ) {
				remove_action( 'http_api_curl', $callback, 10 );
			}
		}
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'network_error', $response->get_error_message(), array( 'retryable' => true, 'ip_mode' => $ip_mode, 'elapsed_ms' => $elapsed_ms ) );
		}
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		$headers = array();
		foreach ( array( 'date', 'server', 'content-type', 'via', 'x-request-id', 'x-correlation-id', 'cf-ray', 'x-envoy-upstream-service-time' ) as $header_name ) {
			$value = wp_remote_retrieve_header( $response, $header_name );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$headers[ $header_name ] = substr( sanitize_text_field( $value ), 0, 240 );
			}
		}
		return array(
			'code'             => (int) wp_remote_retrieve_response_code( $response ),
			'decoded'          => is_array( $decoded ) ? $decoded : array(),
			'raw'              => $raw,
			'body_bytes'       => strlen( $raw ),
			'response_headers' => $headers,
			'ip_mode'          => $ip_mode,
			'elapsed_ms'       => $elapsed_ms,
		);
	}

	private function http_failure( array $response ): array {
		$code = (int) ( $response['code'] ?? 0 );
		$body = (array) ( $response['decoded'] ?? array() );
		$error = sanitize_key( (string) ( $body['meta']['message_code'] ?? ( $body['message_code'] ?? '' ) ) );
		$retryable = 429 === $code || $code >= 500 || 0 === $code;
		if ( in_array( $code, array( 401, 403 ), true ) ) {
			$error = 'authentication_failed';
		} elseif ( 422 === $code ) {
			$error = 'validation_failed';
		} elseif ( 429 === $code ) {
			$error = 'rate_limited';
		} elseif ( $code >= 500 ) {
			$error = 'provider_outage';
		} elseif ( ! $error ) {
			$error = 'http_' . $code;
		}
		return $this->result( false, 'failed', $code, '', $error, 'Edge API درخواست را نپذیرفت.', $retryable, $response );
	}

	private function result( bool $success, string $status, int $http_code, string $provider_id, string $error_code, string $message, bool $retryable, array $response ): array {
		$body = (array) ( $response['decoded'] ?? array() );
		$summary = wp_json_encode(
			array(
				'status'       => $body['meta']['status'] ?? ( $body['status'] ?? null ),
				'message_code' => $body['meta']['message_code'] ?? ( $body['message_code'] ?? null ),
				'outbox_ids'   => $body['data']['message_outbox_ids'] ?? ( $body['outbox_ids'] ?? array() ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		return array(
			'success'      => $success,
			'status'       => $status,
			'http_code'    => $http_code,
			'provider_id'  => $provider_id,
			'error_code'   => $error_code,
			'message'      => $message,
			'retryable'    => $retryable,
			'summary'      => (string) $summary,
			'latency_ms'   => absint( $response['elapsed_ms'] ?? 0 ),
			'transport'    => $this->key(),
		);
	}
}

final class ECM_SMS_Transport_GV_Bridge implements ECM_SMS_Transport_Interface {
	public function key(): string {
		return 'gv_bridge';
	}

	public function label(): string {
		return 'واسط IPPanel SMS Pro GV';
	}

	public function available(): bool {
		return $this->bootstrap_public_sender();
	}

	/**
	 * IPPanel SMS Pro GV stops its normal bootstrap on unauthenticated
	 * admin-ajax.php requests because WordPress reports those requests as admin.
	 * Recovery OTP is intentionally public, so load only the bridge's sending
	 * dependencies from its own verified plugin directory when that happens.
	 */
	private function bootstrap_public_sender(): bool {
		if ( class_exists( 'GFIPPANELSMS_Form_Send' ) && is_callable( array( 'GFIPPANELSMS_Form_Send', 'Send' ) ) && class_exists( 'GFIPPANELSMS_Pro' ) ) {
			return true;
		}

		if ( ! class_exists( 'GFIPPANELSMS_Pro' ) || ! defined( 'GF_SMS_DIR' ) || ! defined( 'GF_SMS_GATEWAY' ) ) {
			return false;
		}

		$base = realpath( (string) GF_SMS_DIR );
		if ( ! $base || ! is_dir( $base ) ) {
			return false;
		}

		$dependencies = array(
			'GFIPPANELSMS_Pro_WebServices' => 'includes/gateways.php',
			'GFIPPANELSMS_Pro_SQL'         => 'includes/sql.php',
			'GFIPPANELSMS_Form_Send'       => 'includes/send.php',
		);

		foreach ( $dependencies as $class_name => $relative_path ) {
			if ( class_exists( $class_name ) ) {
				continue;
			}
			$path = realpath( $base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );
			if ( ! $path || ! str_starts_with( $path, trailingslashit( $base ) ) || ! is_readable( $path ) ) {
				return false;
			}
			require_once $path;
		}

		return class_exists( 'GFIPPANELSMS_Form_Send' )
			&& is_callable( array( 'GFIPPANELSMS_Form_Send', 'Send' ) )
			&& class_exists( 'GFIPPANELSMS_Pro_WebServices' )
			&& class_exists( 'GFIPPANELSMS_Pro_SQL' );
	}

	public function diagnostics(): array {
		$options = (array) get_option( 'gf_ippanelsms_ippanel', array() );
		if ( ! $options ) {
			$options = (array) get_option( 'gf_ippanelsms_IPPANEL', array() );
		}
		$domain  = trim( (string) ( $options['domain'] ?? '' ) );
		$secure  = 0 === stripos( $domain, 'https://' );
		return array(
			'available'       => $this->available(),
			'configured'      => ! empty( $options['username'] ) && ! empty( $options['password'] ),
			'secure'          => $secure,
			'gravity_forms'   => class_exists( 'GFCommon' ),
			'plugin_version'  => defined( 'GF_SMS_VERSION' ) ? (string) GF_SMS_VERSION : ( property_exists( 'GFIPPANELSMS_Pro', 'version' ) ? (string) GFIPPANELSMS_Pro::$version : 'نامشخص' ),
			'endpoint_host'   => $domain ? (string) ( wp_parse_url( $domain, PHP_URL_HOST ) ?: $domain ) : 'تنظیم نشده',
			'note'            => $secure ? 'ارتباط واسط با HTTPS تنظیم شده است.' : 'دامنه افزونه واسط باید با https:// آغاز شود.',
		);
	}

	public function connection_test() {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$started = microtime( true );
		try {
			$credit = GFIPPANELSMS_Pro::credit( true );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'bridge_exception', $exception->getMessage(), array( 'retryable' => false ) );
		}
		$credit = is_scalar( $credit ) ? trim( wp_strip_all_tags( (string) $credit ) ) : '';
		if ( $credit && false !== strpos( $credit, 'ریال' ) ) {
			return array(
				'success'     => true,
				'status'      => 'ok',
				'http_code'   => 0,
				'provider_id' => '',
				'error_code'  => '',
				'message'     => 'اتصال افزونه واسط موفق بود.',
				'balance'     => $credit,
				'retryable'   => false,
				'summary'     => 'IPPanel GV credit check succeeded.',
				'latency_ms'  => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'transport'   => $this->key(),
			);
		}
		return new WP_Error( 'bridge_connection_failed', $credit ?: 'پاسخ معتبری از تست اعتبار افزونه واسط دریافت نشد.', array( 'retryable' => true, 'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ) ) );
	}

	public function send( array $payload ) {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$recipients = array_values( array_filter( array_map( 'strval', (array) ( $payload['recipients'] ?? ( $payload['params']['recipients'] ?? array() ) ) ) ) );
		$from       = sanitize_text_field( (string) ( $payload['from_number'] ?? '' ) );
		$type       = sanitize_key( (string) ( $payload['sending_type'] ?? 'webservice' ) );
		$message    = (string) ( $payload['message'] ?? '' );
		if ( 'pattern' === $type ) {
			$code = sanitize_text_field( (string) ( $payload['code'] ?? '' ) );
			if ( ! $code ) {
				return new WP_Error( 'pattern_missing', 'کد پترن تنظیم نشده است.', array( 'retryable' => false ) );
			}
			$lines = array( 'patterncode:' . $code );
			foreach ( (array) ( $payload['params'] ?? array() ) as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( $key ) {
					$lines[] = $key . ':' . sanitize_text_field( (string) $value );
				}
			}
			$message = implode( "\n", $lines );
		}
		if ( ! $recipients || '' === trim( $message ) ) {
			return new WP_Error( 'invalid_payload', 'گیرنده یا متن پیامک معتبر نیست.', array( 'retryable' => false ) );
		}
		$started = microtime( true );
		try {
			$response = GFIPPANELSMS_Form_Send::Send( implode( ',', $recipients ), $message, $from, '', '' );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'bridge_exception', $exception->getMessage(), array( 'retryable' => false ) );
		}
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		$response = is_scalar( $response ) ? trim( wp_strip_all_tags( (string) $response ) ) : '';
		if ( 'OK' === $response ) {
			return array(
				'success'     => true,
				'status'      => 'accepted',
				'http_code'   => 0,
				'provider_id' => '',
				'error_code'  => '',
				'message'     => 'پیامک توسط افزونه IPPanel GV پذیرفته شد؛ گزارش تحویل نهایی در این API ارائه نمی‌شود.',
				'retryable'   => false,
				'summary'     => 'GV bridge response: OK (accepted, delivery unverified).',
				'latency_ms'  => $elapsed,
				'transport'   => $this->key(),
			);
		}
		return new WP_Error( 'bridge_rejected', $response ?: 'افزونه واسط پاسخ معتبری برنگرداند.', array( 'retryable' => self::looks_retryable( $response ), 'latency_ms' => $elapsed ) );
	}

	/** @return true|WP_Error */
	private function guard() {
		if ( ! $this->available() ) {
			return new WP_Error( 'bridge_unavailable', 'افزونه IPPanel SMS Pro GV یا Gravity Forms فعال و قابل فراخوانی نیست.', array( 'retryable' => false ) );
		}
		$diagnostics = $this->diagnostics();
		if ( empty( $diagnostics['configured'] ) ) {
			return new WP_Error( 'bridge_not_configured', 'نام کاربری و رمز در افزونه IPPanel SMS Pro GV کامل نیست.', array( 'retryable' => false ) );
		}
		if ( empty( $diagnostics['secure'] ) ) {
			return new WP_Error( 'bridge_insecure_endpoint', 'برای جلوگیری از ارسال رمز روی HTTP، دامنه افزونه IPPanel GV را به https://ippanel.com تغییر دهید.', array( 'retryable' => false ) );
		}
		return true;
	}

	private static function looks_retryable( string $message ): bool {
		$haystack = strtolower( $message );
		return str_contains( $haystack, 'curl' ) || str_contains( $haystack, 'timeout' ) || str_contains( $haystack, 'اتصال' );
	}
}

final class ECM_SMS_Transport_WP_SMS implements ECM_SMS_Transport_Interface {
	public function key(): string {
		return 'wp_sms';
	}

	public function label(): string {
		return 'WP‑SMS / WSMS';
	}

	public function available(): bool {
		return function_exists( 'wp_sms_send' );
	}

	public function diagnostics(): array {
		global $sms;
		return array(
			'available'      => $this->available(),
			'configured'     => $this->available() && is_object( $sms ),
			'secure'         => true,
			'plugin_version' => defined( 'WP_SMS_VERSION' ) ? (string) WP_SMS_VERSION : 'نامشخص',
			'gateway_class'  => is_object( $sms ) ? get_class( $sms ) : 'بارگذاری نشده',
			'note'           => 'پذیرش پیام ممکن است فقط به معنی ورود به صف WP‑SMS باشد.',
		);
	}

	public function connection_test() {
		if ( ! $this->available() ) {
			return new WP_Error( 'wp_sms_unavailable', 'افزونه WP‑SMS فعال نیست.', array( 'retryable' => false ) );
		}
		global $sms;
		if ( ! is_object( $sms ) && function_exists( 'wp_sms_initial_gateway' ) ) {
			$sms = wp_sms_initial_gateway();
		}
		if ( ! is_object( $sms ) ) {
			return new WP_Error( 'wp_sms_gateway_missing', 'درگاه فعال WP‑SMS بارگذاری نشده است.', array( 'retryable' => false ) );
		}
		if ( is_callable( array( $sms, 'GetCredit' ) ) ) {
			$started = microtime( true );
			try {
				$credit = $sms->GetCredit();
			} catch ( Throwable $exception ) {
				return new WP_Error( 'wp_sms_exception', $exception->getMessage(), array( 'retryable' => false ) );
			}
			if ( is_wp_error( $credit ) ) {
				return $credit;
			}
			return array( 'success' => true, 'status' => 'ok', 'http_code' => 0, 'provider_id' => '', 'error_code' => '', 'message' => 'درگاه WP‑SMS پاسخ داد.', 'balance' => sanitize_text_field( (string) $credit ), 'retryable' => false, 'summary' => 'WP-SMS credit check succeeded.', 'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'transport' => $this->key() );
		}
		return array( 'success' => true, 'status' => 'available', 'http_code' => 0, 'provider_id' => '', 'error_code' => '', 'message' => 'WP‑SMS و درگاه آن بارگذاری شده‌اند؛ این درگاه آزمون موجودی ارائه نمی‌کند.', 'retryable' => false, 'summary' => 'WP-SMS gateway is loaded; no credit method.', 'latency_ms' => 0, 'transport' => $this->key() );
	}

	public function send( array $payload ) {
		if ( ! $this->available() ) {
			return new WP_Error( 'wp_sms_unavailable', 'افزونه WP‑SMS فعال نیست.', array( 'retryable' => false ) );
		}
		if ( 'pattern' === ( $payload['sending_type'] ?? '' ) ) {
			return new WP_Error( 'wp_sms_pattern_unsupported', 'نگاشت پترن WP‑SMS به درگاه انتخابی قابل تضمین نیست؛ برای این Transport از متن آزاد استفاده کنید.', array( 'retryable' => false ) );
		}
		$recipients = array_values( array_filter( array_map( 'strval', (array) ( $payload['params']['recipients'] ?? array() ) ) ) );
		$message    = (string) ( $payload['message'] ?? '' );
		$from       = (string) ( $payload['from_number'] ?? '' );
		$started    = microtime( true );
		try {
			$response = wp_sms_send( $recipients, $message, false, $from );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'wp_sms_exception', $exception->getMessage(), array( 'retryable' => false ) );
		}
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( $response->get_error_code(), $response->get_error_message(), array( 'retryable' => false, 'latency_ms' => $elapsed ) );
		}
		if ( false === $response || null === $response ) {
			return new WP_Error( 'wp_sms_rejected', 'WP‑SMS درخواست را نپذیرفت.', array( 'retryable' => true, 'latency_ms' => $elapsed ) );
		}
		return array( 'success' => true, 'status' => 'accepted', 'http_code' => 0, 'provider_id' => '', 'error_code' => '', 'message' => 'درخواست به WP‑SMS تحویل شد؛ در حالت صف، این نتیجه به معنی تحویل نهایی نیست.', 'retryable' => false, 'summary' => 'WP-SMS accepted or queued the request.', 'latency_ms' => $elapsed, 'transport' => $this->key() );
	}
}
