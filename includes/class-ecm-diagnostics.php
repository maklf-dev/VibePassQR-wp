<?php

defined( 'ABSPATH' ) || exit;

/**
 * Small, privacy-conscious support log. Disabled by default.
 */
final class ECM_Diagnostics {
	private const MAX_BYTES = 524288;
	private const KEEP_FILES = 3;

	public static function hooks(): void {
		add_action( 'admin_post_ecm_download_diagnostics', array( __CLASS__, 'download' ) );
	}

	public static function enabled(): bool {
		$settings = ECM_Utils::settings();
		return ! empty( $settings['diagnostics_enabled'] );
	}

	public static function record( string $channel, string $message, array $context = array() ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$allowed = array( 'migration', 'capability', 'pages', 'registration', 'attendance', 'sms', 'ticket', 'storage', 'portal', 'maintenance', 'system' );
		$channel = sanitize_key( $channel );
		if ( ! in_array( $channel, $allowed, true ) ) {
			$channel = 'system';
		}
		try {
			$path = self::active_file();
			self::rotate_if_needed( $path );
			$context = array_merge( self::request_context(), $context );
			$entry = array(
				'time_utc' => gmdate( 'c' ),
				'channel'  => $channel,
				'message'  => sanitize_text_field( $message ),
				'context'  => self::sanitize_context( $context ),
			);
			$line = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
			file_put_contents( $path, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		} catch ( Throwable $throwable ) {
			// Diagnostics must never break a registration or attendance request.
		}
	}

	/** Add a compact source location to every diagnostic line without query data. */
	private static function request_context(): array {
		$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		$page   = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		$route  = $action ? ( wp_doing_ajax() ? 'ajax_' : 'form_' ) . $action : ( $page ? 'admin_' . $page : $path );
		return array(
			'request_id'     => class_exists( 'ECM_Activity' ) ? ECM_Activity::request_id() : '',
			'route'          => substr( sanitize_text_field( $route ?: 'unknown' ), 0, 180 ),
			'request_method' => substr( sanitize_key( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ), 0, 12 ),
			'user_id'        => get_current_user_id(),
		);
	}

	private static function sanitize_context( array $context ): array {
		$blocked = array( 'api_key', 'authorization', 'token', 'cookie', 'password', 'payload', 'message', 'phone', 'recipient', 'form_data' );
		$output  = array();
		foreach ( array_slice( $context, 0, 30, true ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! $key || in_array( $key, $blocked, true ) ) {
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$output[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$output[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 300 );
			} else {
				$output[ $key ] = '[complex]';
			}
		}
		return $output;
	}

	private static function directory(): string {
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . 'event-card-manager-diagnostics';
		if ( ! wp_mkdir_p( $path ) ) {
			throw new RuntimeException( 'diagnostics_directory_not_writable' );
		}
		if ( ! file_exists( $path . '/index.php' ) ) {
			file_put_contents( $path . '/index.php', "<?php http_response_code(403); exit;" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $path . '/.htaccess' ) ) {
			file_put_contents( $path . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $path;
	}

	private static function active_file(): string {
		$token = (string) get_option( 'ecm_diagnostics_file_token', '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			$token = bin2hex( random_bytes( 16 ) );
			update_option( 'ecm_diagnostics_file_token', $token, false );
		}
		return self::directory() . '/diagnostics-' . $token . '.jsonl';
	}

	private static function rotate_if_needed( string $path ): void {
		if ( ! file_exists( $path ) || filesize( $path ) < self::MAX_BYTES ) {
			return;
		}
		$directory = dirname( $path );
		$rotated   = $directory . '/diagnostics-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) ) . '.jsonl';
		rename( $path, $rotated );
		$files = glob( $directory . '/diagnostics-*.jsonl' ) ?: array();
		usort( $files, static fn( string $a, string $b ): int => filemtime( $b ) <=> filemtime( $a ) );
		// Keep room for the new active file so the total never exceeds KEEP_FILES.
		foreach ( array_slice( $files, self::KEEP_FILES - 1 ) as $old ) {
			unlink( $old );
		}
	}

	public static function clear(): void {
		$directory = trailingslashit( wp_upload_dir()['basedir'] ) . 'event-card-manager-diagnostics';
		if ( ! is_dir( $directory ) ) {
			delete_option( 'ecm_diagnostics_file_token' );
			return;
		}
		foreach ( glob( $directory . '/diagnostics-*.jsonl' ) ?: array() as $file ) {
			unlink( $file );
		}
		delete_option( 'ecm_diagnostics_file_token' );
	}

	public static function size(): int {
		$total = 0;
		$directory = trailingslashit( wp_upload_dir()['basedir'] ) . 'event-card-manager-diagnostics';
		if ( ! is_dir( $directory ) ) {
			return 0;
		}
		try {
			foreach ( glob( $directory . '/diagnostics-*.jsonl' ) ?: array() as $file ) {
				$total += (int) filesize( $file );
			}
		} catch ( Throwable $throwable ) {
			return 0;
		}
		return $total;
	}

	public static function download(): void {
		if ( ! current_user_can( 'manage_ecm_diagnostics' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'ecm_download_diagnostics' );
		$report = array(
			'generated_at_utc' => gmdate( 'c' ),
			'plugin_version'   => ECM_VERSION,
			'wordpress'        => get_bloginfo( 'version' ),
			'php'              => PHP_VERSION,
			'timezone'         => wp_timezone_string(),
			'site_https'       => is_ssl(),
			'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'openssl'          => function_exists( 'openssl_encrypt' ),
			'diagnostics_enabled' => self::enabled(),
			'sms_key_source'   => ECM_Upgrade::key_source(),
			'sms_network'      => ECM_SMS::diagnostic_snapshot(),
			'sms_transport'    => self::sms_transport_snapshot(),
			'pages'            => self::page_snapshot(),
			'capabilities'     => self::capability_snapshot(),
			'role_capabilities'=> self::role_capability_snapshot(),
			'event_migration'  => ECM_Events::migration_health(),
			'activity_report'  => ECM_Activity::status(),
			'system_snapshot'  => ECM_Activity::system_snapshot(),
			'log'              => array(),
		);
		try {
			$files = glob( self::directory() . '/diagnostics-*.jsonl' ) ?: array();
			usort( $files, static fn( string $a, string $b ): int => filemtime( $a ) <=> filemtime( $b ) );
			foreach ( $files as $file ) {
				$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file
				foreach ( $lines ?: array() as $line ) {
					$decoded = json_decode( $line, true );
					if ( is_array( $decoded ) ) {
						$report['log'][] = $decoded;
					}
				}
			}
		} catch ( Throwable $throwable ) {
			$report['log_read_error'] = true;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ecm-diagnostics-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}

	private static function sms_transport_snapshot(): array {
		$settings  = ECM_Utils::settings();
		$selected = sanitize_key( (string) ( $settings['sms_transport'] ?? 'edge' ) );
		$transport = ECM_SMS_Transports::get( $selected );

		return array(
			'selected' => $selected,
			'enabled'  => ! empty( $settings['sms_enabled'] ),
			'paused'   => ! empty( $settings['sms_transport_paused'] ),
			'details'  => $transport ? $transport->diagnostics() : array( 'available' => false ),
		);
	}

	private static function page_snapshot(): array {
		$output = array();
		foreach ( (array) get_option( 'ecm_pages', array() ) as $key => $id ) {
			$output[ sanitize_key( $key ) ] = array(
				'id'     => absint( $id ),
				'status' => get_post_status( absint( $id ) ) ?: 'missing',
				'owned'  => (bool) get_post_meta( absint( $id ), '_ecm_created', true ),
			);
		}
		return $output;
	}

	private static function capability_snapshot(): array {
		$output = array();
		foreach ( array_keys( ECM_Capabilities::all() ) as $capability ) {
			$output[ $capability ] = current_user_can( $capability );
		}
		return $output;
	}

	private static function role_capability_snapshot(): array {
		$output = array();
		foreach ( array( 'administrator', 'ecm_manager', 'ecm_attendance_operator' ) as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) {
				$output[ $role_key ] = array( 'exists' => false, 'capabilities' => array() );
				continue;
			}
			$capabilities = array();
			foreach ( array_keys( ECM_Capabilities::all() ) as $capability ) {
				$capabilities[ $capability ] = $role->has_cap( $capability );
			}
			$output[ $role_key ] = array( 'exists' => true, 'capabilities' => $capabilities );
		}
		return $output;
	}
}
