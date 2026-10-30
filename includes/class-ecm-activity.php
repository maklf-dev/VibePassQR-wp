<?php

defined( 'ABSPATH' ) || exit;

/**
 * Bounded daily operational summary for ECM-owned requests only.
 *
 * This is deliberately aggregate-first: it never stores raw IP addresses,
 * phone numbers, ticket/QR values, form payloads, SMS bodies or credentials.
 */
final class ECM_Activity {
	private const SCHEMA                = 'ecm-daily-activity-v2';
	private const KEEP_DAYS             = 7;
	private const VISITOR_BUCKETS       = 4096;
	private const MAX_STAFF             = 100;
	private const MAX_EVENTS            = 150;
	private const MAX_SESSIONS          = 300;
	private const MAX_OPERATIONS        = 100;
	private const MAX_REFERRERS         = 50;
	private const MAX_RECENT_ERRORS     = 30;
	private const MAX_SLOW_REQUESTS     = 24;
	private const MAX_ROUTES            = 80;
	private const SLOW_REQUEST_MS       = 2000;
	private const MAX_FILE_BYTES        = 1048576;
	private const DIRECTORY             = 'event-card-manager-activity';

	/** @var array<string,mixed>|null */
	private static ?array $pending_page = null;
	/** @var array<int,array{activity:string,context:array<string,mixed>}> */
	private static array $pending_records = array();
	private static bool $flush_registered = false;
	private static ?string $request_id = null;

	public static function hooks(): void {
		add_action( 'template_redirect', array( __CLASS__, 'track_frontend' ), -9 );
		add_action( 'admin_init', array( __CLASS__, 'track_admin' ) );
		add_action( 'ecm_event_logged', array( __CLASS__, 'track_event' ), 20, 3 );
		add_action( 'admin_post_ecm_download_activity', array( __CLASS__, 'download' ) );
	}

	/** Record only ECM-created public pages and the two compact ticket routes. */
	public static function track_frontend(): void {
		if ( is_admin() || wp_doing_ajax() || self::$pending_page ) {
			return;
		}

		$key = '';
		if ( get_query_var( 'ecm_ticket_token' ) ) {
			$key = 'short_card';
		} elseif ( get_query_var( 'ecm_attendance_token' ) ) {
			$key = 'short_attendance';
		} else {
			foreach ( (array) get_option( 'ecm_pages', array() ) as $page_key => $page_id ) {
				if ( $page_id && is_page( (int) $page_id ) ) {
					$key = sanitize_key( (string) $page_key );
					break;
				}
			}
		}
		if ( ! $key ) {
			return;
		}

		if ( 'portal' === $key ) {
			$view = isset( $_GET['ecm_portal_view'] ) ? sanitize_key( wp_unslash( $_GET['ecm_portal_view'] ) ) : '';
			if ( $view ) {
				$key .= '_' . $view;
			}
		}
		$event_id = 0;
		if ( in_array( $key, array( 'registration', 'recovery' ), true ) ) {
			$event    = ECM_Events::requested_event();
			$event_id = $event ? (int) $event->id : 0;
		} elseif ( 'card' === $key && ! empty( $_GET['id'] ) ) {
			$registration = ECM_DB::registration( absint( $_GET['id'] ) );
			$event_id     = $registration ? absint( $registration->event_id ?? 0 ) : 0;
		}
		self::queue_request( 'page_view', 'public_' . $key, $event_id );
	}

	/** Record only ECM AJAX, admin-post and wp-admin screens, never generic admin traffic. */
	public static function track_admin(): void {
		if ( isset( $_GET['activity_cleared'] ) ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			$action = sanitize_key( self::request_value( 'action' ) );
			if ( str_starts_with( $action, 'ecm_' ) ) {
				self::queue_request( 'ajax_request', 'ajax_' . $action, self::requested_event_id(), self::requested_session_id() );
			}
			return;
		}
		$script = basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) ) );
		if ( 'admin-post.php' === $script ) {
			$action = sanitize_key( self::request_value( 'action' ) );
			if ( str_starts_with( $action, 'ecm_' ) && ! in_array( $action, array( 'ecm_download_activity', 'ecm_download_diagnostics' ), true ) ) {
				self::queue_request( 'form_request', 'form_' . $action, self::requested_event_id(), self::requested_session_id() );
			}
			return;
		}
		if ( ! is_admin() ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! str_starts_with( $page, 'ecm-' ) ) {
			return;
		}
		self::queue_request( 'page_view', 'admin_' . substr( $page, 4 ), absint( $_GET['event_id'] ?? 0 ) );
	}

	private static function queue_request( string $activity, string $route, int $event_id = 0, int $session_id = 0 ): void {
		if ( self::$pending_page ) {
			return;
		}
		self::$pending_page = array(
			'activity'   => sanitize_key( $activity ),
			'route'      => substr( sanitize_key( $route ), 0, 100 ),
			'event_id'   => $event_id,
			'session_id' => $session_id,
			'start_time' => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ),
		);
		self::register_flush_hooks();
	}

	/** Complete the queued page sample at most once, including lightweight timing. */
	public static function flush_page(): void {
		if ( ! self::$pending_page ) {
			return;
		}
		$pending           = self::$pending_page;
		self::$pending_page = null;
		$fatal = self::fatal_error_context();
		$status = (int) http_response_code();
		if ( $fatal && $status < 500 ) {
			$status = 500;
		}
		self::record(
			(string) $pending['activity'],
			array_merge(
				array(
					'page'              => 'page_view' === $pending['activity'] ? (string) $pending['route'] : '',
					'route'             => (string) $pending['route'],
					'request_type'      => (string) $pending['activity'],
					'event_id'          => (int) $pending['event_id'],
					'session_id'        => (int) $pending['session_id'],
					'duration_ms'       => max( 0, min( 600000, (int) round( ( microtime( true ) - (float) $pending['start_time'] ) * 1000 ) ) ),
					'peak_memory_bytes' => (int) memory_get_peak_usage( true ),
					'db_queries'        => function_exists( 'get_num_queries' ) ? (int) get_num_queries() : 0,
					'http_status'       => $status,
					'is_error'          => $status >= 400 || (bool) $fatal,
				),
				$fatal
			)
		);
	}

	public static function request_id(): string {
		if ( null === self::$request_id ) {
			try {
				self::$request_id = bin2hex( random_bytes( 6 ) );
			} catch ( Throwable $throwable ) {
				self::$request_id = substr( md5( uniqid( '', true ) ), 0, 12 );
			}
		}
		return self::$request_id;
	}

	/** Aggregate the existing safe ECM event stream without changing its producers. */
	public static function track_event( int $registration_id, string $type, array $meta ): void {
		$type = sanitize_key( $type );
		if ( ! $type ) {
			return;
		}
		$is_error = str_contains( $type, 'failed' ) || str_contains( $type, 'error' ) || str_contains( $type, 'rejected' );
		$event_id = absint( $meta['event_id'] ?? 0 );
		if ( ! $event_id && $registration_id ) {
			$registration = ECM_DB::registration( $registration_id );
			$event_id = $registration ? absint( $registration->event_id ?? 0 ) : 0;
		}
		self::record(
			'operation',
			array(
				'operation'  => $type,
				'event_id'   => $event_id,
				'session_id' => absint( $meta['session_id'] ?? 0 ),
				'registration_id' => $registration_id,
				'participant_id'  => absint( $meta['participant_id'] ?? 0 ),
				'is_error'   => $is_error,
				'error_code' => $is_error ? sanitize_key( (string) ( $meta['error'] ?? $meta['error_code'] ?? $type ) ) : '',
				'route'      => self::$pending_page ? (string) self::$pending_page['route'] : self::current_route(),
			)
		);
	}

	/**
	 * Add one compact activity sample. Failures are intentionally swallowed so
	 * analytics can never block registration, recovery, SMS or attendance.
	 */
	public static function record( string $activity, array $context = array() ): void {
		self::$pending_records[] = array( 'activity' => sanitize_key( $activity ), 'context' => $context );
		self::register_flush_hooks();
	}

	/** Persist every sample generated by one request with a single short file lock. */
	public static function flush_records(): void {
		if ( ! self::$pending_records ) {
			return;
		}
		$records = self::$pending_records;
		self::$pending_records = array();
		try {
			$path   = self::current_path( true );
			$is_new = ! is_file( $path );
			$handle = fopen( $path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $handle ) {
				return;
			}
			$lock_started = microtime( true );
			if ( ! flock( $handle, LOCK_EX ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return;
			}
			rewind( $handle );
			$lock_wait_ms = max( 0, (int) round( ( microtime( true ) - $lock_started ) * 1000 ) );
			$raw    = stream_get_contents( $handle, self::MAX_FILE_BYTES + 1 );
			$report = is_string( $raw ) && strlen( $raw ) <= self::MAX_FILE_BYTES ? json_decode( $raw, true ) : null;
			if ( ! is_array( $report ) || ( $report['date_local'] ?? '' ) !== self::local_date() ) {
				$report = self::empty_report();
			} else {
				$report = self::upgrade_report( $report );
			}
			foreach ( $records as $record ) {
				self::aggregate( $report, (string) $record['activity'], (array) $record['context'] );
			}
			$report['writer']['writes']++;
			$report['writer']['records'] += count( $records );
			$report['writer']['max_batch_size'] = max( (int) $report['writer']['max_batch_size'], count( $records ) );
			$report['writer']['total_lock_wait_ms'] += $lock_wait_ms;
			$report['writer']['max_lock_wait_ms'] = max( (int) $report['writer']['max_lock_wait_ms'], $lock_wait_ms );
			$encoded = wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_FILE_BYTES ) {
				// The anonymous visitor bitmap is internal de-duplication state and
				// is the first expendable field at the hard file-size boundary.
				$report['traffic']['visitor_tracking_capped'] = true;
				$report['traffic']['_visitor_bitmap']         = '';
				$encoded = wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
			if ( is_string( $encoded ) && strlen( $encoded ) <= self::MAX_FILE_BYTES ) {
				rewind( $handle );
				ftruncate( $handle, 0 );
				fwrite( $handle, $encoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fflush( $handle );
			}
			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( $is_new ) {
				self::prune();
			}
		} catch ( Throwable $throwable ) {
			// Operational analytics must be strictly non-blocking.
		}
	}

	/** @param array<string,mixed> $report */
	private static function aggregate( array &$report, string $activity, array $context ): void {
		$report['last_activity_utc'] = gmdate( 'c' );
		$report['plugin_version']    = ECM_VERSION;
		$report['totals']['activities']++;

		if ( 'page_view' === $activity ) {
			$report['totals']['page_views']++;
			self::increment_bucket( $report['pages'], sanitize_key( (string) ( $context['page'] ?? 'unknown' ) ), 1, 30 );
			self::aggregate_traffic( $report, $context );
			self::aggregate_request_performance( $report, $context );
		} elseif ( 'ajax_request' === $activity ) {
			$report['totals']['ajax_requests']++;
			self::increment_bucket( $report['ajax'], sanitize_key( (string) ( $context['route'] ?? 'unknown' ) ), 1, self::MAX_ROUTES );
			self::aggregate_request_performance( $report, $context );
		} elseif ( 'form_request' === $activity ) {
			$report['totals']['form_requests']++;
			self::increment_bucket( $report['forms'], sanitize_key( (string) ( $context['route'] ?? 'unknown' ) ), 1, 30 );
			self::aggregate_request_performance( $report, $context );
		} elseif ( 'operation' === $activity ) {
			$report['totals']['operations']++;
			$operation = sanitize_key( (string) ( $context['operation'] ?? 'unknown' ) ) ?: 'unknown';
			self::increment_bucket( $report['operations'], $operation, 1, self::MAX_OPERATIONS );
			$result_code = sanitize_key( (string) ( $context['error_code'] ?? ( empty( $context['is_error'] ) ? 'success' : 'unknown' ) ) ) ?: 'unknown';
			if ( ! isset( $report['operation_results'][ $operation ] ) ) {
				$report['operation_results'][ $operation ] = array();
			}
			self::increment_bucket( $report['operation_results'][ $operation ], $result_code, 1, 30 );
			foreach ( array( 'stored_count', 'already_count', 'field_count', 'guest_count' ) as $metric ) {
				if ( isset( $context[ $metric ] ) ) {
					$report['operation_metrics'][ $operation ][ $metric ] = (int) ( $report['operation_metrics'][ $operation ][ $metric ] ?? 0 ) + max( 0, (int) $context[ $metric ] );
				}
			}
		}

		$event_id = absint( $context['event_id'] ?? 0 );
		if ( $event_id ) {
			self::increment_bucket( $report['events'], (string) $event_id, 1, self::MAX_EVENTS );
		}
		$session_id = absint( $context['session_id'] ?? 0 );
		if ( $session_id ) {
			self::increment_bucket( $report['sessions'], (string) $session_id, 1, self::MAX_SESSIONS );
		}

		$is_error = ! empty( $context['is_error'] );
		if ( $is_error ) {
			$report['totals']['errors']++;
			$report['recent_errors'][] = array(
				'time_utc'   => gmdate( 'c' ),
				'request_id' => self::request_id(),
				'activity'   => $activity,
				'code'       => substr( sanitize_key( (string) ( $context['error_code'] ?? 'unknown' ) ), 0, 80 ),
				'route'      => substr( sanitize_key( (string) ( $context['route'] ?? self::current_route() ) ), 0, 100 ),
				'http_status'=> absint( $context['http_status'] ?? 0 ),
				'event_id'   => $event_id,
				'session_id' => $session_id,
				'registration_id' => absint( $context['registration_id'] ?? 0 ),
				'participant_id'  => absint( $context['participant_id'] ?? 0 ),
				'user_id'    => get_current_user_id(),
				'component'  => substr( sanitize_key( (string) ( $context['component'] ?? '' ) ), 0, 80 ),
				'file'       => substr( sanitize_file_name( (string) ( $context['file'] ?? '' ) ), 0, 100 ),
				'line'       => absint( $context['line'] ?? 0 ),
			);
			$report['recent_errors'] = array_slice( $report['recent_errors'], -self::MAX_RECENT_ERRORS );
		}
		self::aggregate_staff( $report, $activity );
	}

	/** @param array<string,mixed> $report */
	private static function aggregate_traffic( array &$report, array $context ): void {
		$device = ECM_Utils::request_device();
		self::increment_bucket( $report['traffic']['device_types'], sanitize_key( (string) ( $device['type'] ?: 'unknown' ) ), 1, 10 );
		$label = substr( sanitize_text_field( (string) ( $device['label'] ?: 'نامشخص' ) ), 0, 100 );
		self::increment_bucket( $report['traffic']['device_labels'], $label, 1, 20 );

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		$ref_host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$ref_key = ! $ref_host ? 'direct_or_unknown' : ( $ref_host === $site_host ? 'internal' : preg_replace( '/[^a-z0-9.-]/', '', $ref_host ) );
		self::increment_bucket( $report['traffic']['referrers'], $ref_key ?: 'other', 1, self::MAX_REFERRERS );

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( ( $ip || $ua ) && empty( $report['traffic']['visitor_tracking_capped'] ) ) {
			// A 4096-bit linear-counting bitmap gives a useful anonymous unique
			// estimate while keeping fixed storage, even under very high traffic.
			$bytes  = intdiv( self::VISITOR_BUCKETS, 8 );
			$bitmap = base64_decode( (string) ( $report['traffic']['_visitor_bitmap'] ?? '' ), true );
			if ( ! is_string( $bitmap ) || strlen( $bitmap ) !== $bytes ) {
				$bitmap = str_repeat( "\0", $bytes );
			}
			$hash   = hash_hmac( 'sha256', self::local_date() . '|' . $ip . '|' . $ua, wp_salt( 'nonce' ), true );
			$number = unpack( 'Nvalue', substr( $hash, 0, 4 ) );
			$bucket = (int) ( ( $number['value'] ?? 0 ) % self::VISITOR_BUCKETS );
			$byte   = intdiv( $bucket, 8 );
			$mask   = 1 << ( $bucket % 8 );
			$before = ord( $bitmap[ $byte ] );
			if ( 0 === ( $before & $mask ) ) {
				$bitmap[ $byte ] = chr( $before | $mask );
				$report['traffic']['_visitor_bits_set'] = min( self::VISITOR_BUCKETS, (int) ( $report['traffic']['_visitor_bits_set'] ?? 0 ) + 1 );
			}
			$set   = (int) ( $report['traffic']['_visitor_bits_set'] ?? 0 );
			$zeros = self::VISITOR_BUCKETS - $set;
			$report['traffic']['_visitor_bitmap'] = base64_encode( $bitmap );
			if ( $zeros > 0 ) {
				$report['traffic']['unique_plugin_visitors'] = (int) round( -self::VISITOR_BUCKETS * log( $zeros / self::VISITOR_BUCKETS ) );
			} else {
				$report['traffic']['visitor_tracking_capped'] = true;
			}
			if ( $set >= (int) floor( self::VISITOR_BUCKETS * 0.98 ) ) {
				$report['traffic']['visitor_tracking_capped'] = true;
			}
		}

		$duration = max( 0, absint( $context['duration_ms'] ?? 0 ) );
		$memory   = max( 0, absint( $context['peak_memory_bytes'] ?? 0 ) );
		$status   = max( 0, absint( $context['http_status'] ?? 0 ) );
		$perf     =& $report['traffic']['performance'];
		$perf['samples']++;
		$perf['total_duration_ms'] += $duration;
		$perf['max_duration_ms'] = max( (int) $perf['max_duration_ms'], $duration );
		$perf['max_peak_memory_bytes'] = max( (int) $perf['max_peak_memory_bytes'], $memory );
		self::increment_bucket( $perf['http_statuses'], $status ? (string) $status : 'unknown', 1, 10 );
	}

	/** Keep fixed-size latency histograms instead of raw request logs. */
	private static function aggregate_request_performance( array &$report, array $context ): void {
		$duration = max( 0, absint( $context['duration_ms'] ?? 0 ) );
		$memory   = max( 0, absint( $context['peak_memory_bytes'] ?? 0 ) );
		$queries  = max( 0, absint( $context['db_queries'] ?? 0 ) );
		$status   = max( 0, absint( $context['http_status'] ?? 0 ) );
		$route    = substr( sanitize_key( (string) ( $context['route'] ?? 'unknown' ) ), 0, 100 ) ?: 'unknown';
		$type     = sanitize_key( (string) ( $context['request_type'] ?? 'unknown' ) ) ?: 'unknown';
		$is_error = ! empty( $context['is_error'] ) || $status >= 400;
		$bucket   = self::duration_bucket( $duration );

		$perf =& $report['performance'];
		$perf['samples']++;
		$perf['total_duration_ms'] += $duration;
		$perf['max_duration_ms'] = max( (int) $perf['max_duration_ms'], $duration );
		$perf['total_db_queries'] += $queries;
		$perf['max_db_queries'] = max( (int) $perf['max_db_queries'], $queries );
		$perf['max_peak_memory_bytes'] = max( (int) $perf['max_peak_memory_bytes'], $memory );
		if ( $is_error ) {
			$perf['error_responses']++;
		}
		self::increment_bucket( $perf['request_types'], $type, 1, 10 );
		self::increment_bucket( $perf['http_statuses'], $status ? (string) $status : 'unknown', 1, 12 );
		self::increment_bucket( $perf['duration_buckets_ms'], $bucket, 1, 10 );

		if ( ! isset( $report['routes'][ $route ] ) && count( $report['routes'] ) >= self::MAX_ROUTES ) {
			$route = 'other';
		}
		if ( ! isset( $report['routes'][ $route ] ) ) {
			$report['routes'][ $route ] = self::empty_performance_row();
		}
		$row =& $report['routes'][ $route ];
		$row['samples']++;
		$row['total_duration_ms'] += $duration;
		$row['max_duration_ms'] = max( (int) $row['max_duration_ms'], $duration );
		$row['total_db_queries'] += $queries;
		$row['max_db_queries'] = max( (int) $row['max_db_queries'], $queries );
		$row['max_peak_memory_bytes'] = max( (int) $row['max_peak_memory_bytes'], $memory );
		if ( $is_error ) {
			$row['error_responses']++;
		}
		self::increment_bucket( $row['http_statuses'], $status ? (string) $status : 'unknown', 1, 12 );
		self::increment_bucket( $row['duration_buckets_ms'], $bucket, 1, 10 );

		if ( $duration >= self::SLOW_REQUEST_MS || $is_error ) {
			$device = ECM_Utils::request_device();
			$report['slow_requests'][] = array(
				'time_utc'          => gmdate( 'c' ),
				'request_id'        => self::request_id(),
				'route'             => $route,
				'type'              => $type,
				'duration_ms'       => $duration,
				'peak_memory_bytes' => $memory,
				'db_queries'        => $queries,
				'http_status'       => $status,
				'event_id'          => absint( $context['event_id'] ?? 0 ),
				'session_id'        => absint( $context['session_id'] ?? 0 ),
				'user_id'           => get_current_user_id(),
				'device_type'       => sanitize_key( (string) ( $device['type'] ?? '' ) ),
				'error_code'        => substr( sanitize_key( (string) ( $context['error_code'] ?? '' ) ), 0, 80 ),
				'component'         => substr( sanitize_key( (string) ( $context['component'] ?? '' ) ), 0, 80 ),
				'file'              => substr( sanitize_file_name( (string) ( $context['file'] ?? '' ) ), 0, 100 ),
				'line'              => absint( $context['line'] ?? 0 ),
			);
			$report['slow_requests'] = array_slice( $report['slow_requests'], -self::MAX_SLOW_REQUESTS );
		}
	}

	private static function duration_bucket( int $duration ): string {
		if ( $duration < 250 ) { return 'lt_250'; }
		if ( $duration < 500 ) { return '250_499'; }
		if ( $duration < 1000 ) { return '500_999'; }
		if ( $duration < 2000 ) { return '1000_1999'; }
		if ( $duration < 5000 ) { return '2000_4999'; }
		return 'gte_5000';
	}

	/** @return array<string,mixed> */
	private static function empty_performance_row(): array {
		return array(
			'samples'               => 0,
			'total_duration_ms'     => 0,
			'max_duration_ms'       => 0,
			'total_db_queries'      => 0,
			'max_db_queries'        => 0,
			'max_peak_memory_bytes' => 0,
			'error_responses'       => 0,
			'http_statuses'         => array(),
			'duration_buckets_ms'   => array(),
		);
	}

	/** @param array<string,mixed> $report */
	private static function aggregate_staff( array &$report, string $activity ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ( ! user_can( $user, 'access_ecm' ) && ! user_can( $user, 'manage_options' ) ) ) {
			return;
		}
		$key   = (string) $user_id;
		$staff = (array) ( $report['staff'] ?? array() );
		if ( ! isset( $staff[ $key ] ) && count( $staff ) >= self::MAX_STAFF ) {
			$report['staff_tracking_capped'] = true;
			return;
		}
		$row = isset( $staff[ $key ] ) && is_array( $staff[ $key ] ) ? $staff[ $key ] : array(
			'user_id'      => $user_id,
			'display_name' => substr( sanitize_text_field( (string) $user->display_name ), 0, 100 ),
			'roles'        => array_values( array_map( 'sanitize_key', (array) $user->roles ) ),
			'page_views'   => 0,
			'operations'   => 0,
			'requests'     => 0,
			'activities'   => 0,
			'device_types' => array(),
			'last_seen_utc'=> '',
		);
		$row['activities']++;
		if ( 'page_view' === $activity ) {
			$row['page_views']++;
		} elseif ( 'operation' === $activity ) {
			$row['operations']++;
		} else {
			$row['requests'] = (int) ( $row['requests'] ?? 0 ) + 1;
		}
		$row['last_seen_utc'] = gmdate( 'c' );
		$device = ECM_Utils::request_device();
		self::increment_bucket( $row['device_types'], sanitize_key( (string) ( $device['type'] ?: 'unknown' ) ), 1, 10 );
		$staff[ $key ]  = $row;
		$report['staff'] = $staff;
	}

	/** @param array<string,int> $bucket */
	private static function increment_bucket( array &$bucket, string $key, int $amount, int $limit ): void {
		$key = trim( $key );
		if ( '' === $key ) {
			$key = 'unknown';
		}
		if ( ! isset( $bucket[ $key ] ) && count( $bucket ) >= $limit ) {
			$key = 'other';
		}
		$bucket[ $key ] = (int) ( $bucket[ $key ] ?? 0 ) + $amount;
	}

	/** @return array<string,mixed> */
	private static function empty_report(): array {
		return array(
			'schema'            => self::SCHEMA,
			'date_local'        => self::local_date(),
			'timezone'          => wp_timezone_string(),
			'plugin_version'    => ECM_VERSION,
			'created_at_utc'    => gmdate( 'c' ),
			'last_activity_utc' => null,
			'totals'            => array( 'activities' => 0, 'page_views' => 0, 'ajax_requests' => 0, 'form_requests' => 0, 'operations' => 0, 'errors' => 0 ),
			'pages'             => array(),
			'ajax'              => array(),
			'forms'             => array(),
			'operations'        => array(),
			'operation_results'=> array(),
			'operation_metrics'=> array(),
			'events'            => array(),
			'sessions'          => array(),
			'traffic'           => array(
				'unique_plugin_visitors' => 0,
				'visitor_tracking_capped'=> false,
				'_visitor_bitmap'         => '',
				'_visitor_bits_set'       => 0,
				'device_types'            => array(),
				'device_labels'           => array(),
				'referrers'               => array(),
				'performance'             => array(
					'samples'               => 0,
					'total_duration_ms'     => 0,
					'max_duration_ms'       => 0,
					'max_peak_memory_bytes' => 0,
					'http_statuses'         => array(),
				),
			),
			'performance'       => array_merge( self::empty_performance_row(), array( 'request_types' => array() ) ),
			'routes'            => array(),
			'slow_requests'     => array(),
			'writer'            => array( 'writes' => 0, 'records' => 0, 'max_batch_size' => 0, 'total_lock_wait_ms' => 0, 'max_lock_wait_ms' => 0 ),
			'staff'             => array(),
			'recent_errors'     => array(),
		);
	}

	/** Merge a v1 report into v2 without discarding today's counters. */
	private static function upgrade_report( array $report ): array {
		$defaults = self::empty_report();
		$upgraded = array_replace_recursive( $defaults, $report );
		$upgraded['schema'] = self::SCHEMA;
		foreach ( (array) ( $upgraded['staff'] ?? array() ) as $key => $row ) {
			if ( is_array( $row ) && ! isset( $row['requests'] ) ) {
				$upgraded['staff'][ $key ]['requests'] = 0;
			}
		}
		return $upgraded;
	}

	private static function register_flush_hooks(): void {
		if ( self::$flush_registered ) {
			return;
		}
		self::$flush_registered = true;
		add_action( 'shutdown', array( __CLASS__, 'flush_page' ), PHP_INT_MAX - 1 );
		add_action( 'shutdown', array( __CLASS__, 'flush_records' ), PHP_INT_MAX );
	}

	private static function requested_event_id(): int {
		return absint( self::request_value( 'event_id' ) ?: self::request_value( 'ecm_event_id' ) );
	}

	private static function requested_session_id(): int {
		return absint( self::request_value( 'session_id' ) ?: self::request_value( 'ecm_session_id' ) );
	}

	private static function request_value( string $key ): string {
		$value = $_REQUEST[ $key ] ?? '';
		return is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
	}

	private static function current_route(): string {
		if ( self::$pending_page ) {
			return (string) self::$pending_page['route'];
		}
		$action = sanitize_key( self::request_value( 'action' ) );
		if ( str_starts_with( $action, 'ecm_' ) ) {
			return ( wp_doing_ajax() ? 'ajax_' : 'form_' ) . $action;
		}
		return 'ecm_request';
	}

	/** @return array<string,mixed> */
	private static function fatal_error_context(): array {
		$error = error_get_last();
		if ( ! is_array( $error ) || ! in_array( (int) ( $error['type'] ?? 0 ), array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return array();
		}
		$file = wp_normalize_path( (string) ( $error['file'] ?? '' ) );
		$component = 'unknown';
		if ( defined( 'ECM_PATH' ) && str_starts_with( $file, wp_normalize_path( ECM_PATH ) ) ) {
			$component = 'event_card_manager';
		} elseif ( defined( 'WP_PLUGIN_DIR' ) && str_starts_with( $file, wp_normalize_path( WP_PLUGIN_DIR ) . '/' ) ) {
			$relative  = ltrim( substr( $file, strlen( wp_normalize_path( WP_PLUGIN_DIR ) ) ), '/' );
			$component = 'plugin_' . sanitize_key( strtok( $relative, '/' ) ?: 'unknown' );
		} elseif ( defined( 'ABSPATH' ) && str_starts_with( $file, wp_normalize_path( ABSPATH ) ) ) {
			$component = 'wordpress_or_theme';
		}
		return array(
			'is_error'   => true,
			'error_code' => 'php_fatal_' . absint( $error['type'] ?? 0 ),
			'component'  => $component,
			'file'       => basename( $file ),
			'line'       => absint( $error['line'] ?? 0 ),
		);
	}

	private static function local_date(): string {
		return wp_date( 'Y-m-d', null, wp_timezone() );
	}

	private static function directory( bool $create = true ): string {
		$directory = trailingslashit( wp_upload_dir()['basedir'] ) . self::DIRECTORY;
		if ( ! $create ) {
			return $directory;
		}
		if ( ! wp_mkdir_p( $directory ) ) {
			throw new RuntimeException( 'activity_directory_not_writable' );
		}
		if ( ! file_exists( $directory . '/index.php' ) ) {
			file_put_contents( $directory . '/index.php', "<?php http_response_code(403); exit;" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $directory . '/.htaccess' ) ) {
			file_put_contents( $directory . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $directory;
	}

	private static function file_token(): string {
		$token = (string) get_option( 'ecm_activity_file_token', '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			$token = bin2hex( random_bytes( 16 ) );
			update_option( 'ecm_activity_file_token', $token, false );
		}
		return $token;
	}

	private static function current_path( bool $create_directory = true ): string {
		return self::directory( $create_directory ) . '/activity-' . self::local_date() . '-' . self::file_token() . '.json';
	}

	private static function prune(): void {
		$directory = self::directory( false );
		$files     = is_dir( $directory ) ? ( glob( $directory . '/activity-*.json' ) ?: array() ) : array();
		usort( $files, static fn( string $a, string $b ): int => filemtime( $b ) <=> filemtime( $a ) );
		foreach ( array_slice( $files, self::KEEP_DAYS ) as $file ) {
			unlink( $file );
		}
	}

	/** @return array<string,mixed> */
	public static function status(): array {
		try {
			$path   = self::current_path( false );
			$exists = is_file( $path );
			$data   = $exists ? self::read_report( $path ) : array();
			return array(
				'date'          => self::local_date(),
				'exists'        => $exists,
				'size'          => $exists ? (int) filesize( $path ) : 0,
				'activities'    => (int) ( $data['totals']['activities'] ?? 0 ),
				'page_views'    => (int) ( $data['totals']['page_views'] ?? 0 ),
				'ajax_requests' => (int) ( $data['totals']['ajax_requests'] ?? 0 ),
				'form_requests' => (int) ( $data['totals']['form_requests'] ?? 0 ),
				'operations'    => (int) ( $data['totals']['operations'] ?? 0 ),
				'errors'        => (int) ( $data['totals']['errors'] ?? 0 ),
				'last_activity' => (string) ( $data['last_activity_utc'] ?? '' ),
			);
		} catch ( Throwable $throwable ) {
			return array( 'date' => self::local_date(), 'exists' => false, 'size' => 0, 'activities' => 0, 'page_views' => 0, 'ajax_requests' => 0, 'form_requests' => 0, 'operations' => 0, 'errors' => 0, 'last_activity' => '' );
		}
	}

	public static function clear_current(): bool {
		try {
			$path = self::current_path( false );
			return ! is_file( $path ) || unlink( $path );
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	public static function download(): void {
		if ( ! current_user_can( 'manage_ecm_diagnostics' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'ecm_download_activity' );
		self::flush_page();
		self::flush_records();
		$path   = self::current_path( false );
		$report = is_file( $path ) ? self::read_report( $path ) : self::empty_report();
		if ( ! is_array( $report ) ) {
			$report = self::empty_report();
		}
		$report = self::upgrade_report( $report );
		unset( $report['traffic']['_visitor_bitmap'], $report['traffic']['_visitor_bits_set'] );
		if ( ! empty( $report['traffic']['performance']['samples'] ) ) {
			$report['traffic']['performance']['average_duration_ms'] = (int) round(
				$report['traffic']['performance']['total_duration_ms'] / $report['traffic']['performance']['samples']
			);
		}
		self::finalize_performance( $report['performance'] );
		foreach ( $report['routes'] as &$route_performance ) {
			self::finalize_performance( $route_performance );
		}
		unset( $route_performance );
		$report['report_scope'] = array(
			'covers' => array( 'ecm_pages', 'ecm_ajax', 'ecm_admin_post', 'ecm_operations' ),
			'excludes' => array( 'raw_ip', 'phone', 'ticket_or_qr', 'form_payload', 'sms_body', 'credentials', 'non_ecm_page_views' ),
			'unique_visitors_are_estimated' => true,
			'percentiles_are_histogram_estimates' => true,
		);
		$report['generated_at_utc'] = gmdate( 'c' );
		$report['system_snapshot']  = self::system_snapshot();
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ecm-activity-' . self::local_date() . '.json"' );
		echo wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}

	/** Add compact, approximate percentiles from bounded latency buckets. */
	private static function finalize_performance( array &$performance ): void {
		$samples = (int) ( $performance['samples'] ?? 0 );
		if ( ! $samples ) {
			$performance['average_duration_ms'] = 0;
			$performance['average_db_queries'] = 0;
			$performance['p95_duration_ms_upper_bound'] = 0;
			$performance['p99_duration_ms_upper_bound'] = 0;
			return;
		}
		$performance['average_duration_ms'] = (int) round( (int) ( $performance['total_duration_ms'] ?? 0 ) / $samples );
		$performance['average_db_queries'] = (int) round( (int) ( $performance['total_db_queries'] ?? 0 ) / $samples );
		$maximum = (int) ( $performance['max_duration_ms'] ?? 0 );
		$performance['p95_duration_ms_upper_bound'] = self::histogram_percentile( (array) ( $performance['duration_buckets_ms'] ?? array() ), $samples, 0.95, $maximum );
		$performance['p99_duration_ms_upper_bound'] = self::histogram_percentile( (array) ( $performance['duration_buckets_ms'] ?? array() ), $samples, 0.99, $maximum );
	}

	private static function histogram_percentile( array $buckets, int $samples, float $percentile, int $maximum ): int {
		$limits = array( 'lt_250' => 250, '250_499' => 500, '500_999' => 1000, '1000_1999' => 2000, '2000_4999' => 5000, 'gte_5000' => -1 );
		$target = max( 1, (int) ceil( $samples * $percentile ) );
		$seen = 0;
		foreach ( $limits as $key => $limit ) {
			$seen += (int) ( $buckets[ $key ] ?? 0 );
			if ( $seen >= $target ) {
				return -1 === $limit ? max( 5000, $maximum ) : $limit;
			}
		}
		return 0;
	}

	/** @return array<string,mixed> */
	private static function read_report( string $path ): array {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return array();
		}
		if ( ! flock( $handle, LOCK_SH ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array();
		}
		$raw = stream_get_contents( $handle, self::MAX_FILE_BYTES + 1 );
		flock( $handle, LOCK_UN );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$data = is_string( $raw ) && strlen( $raw ) <= self::MAX_FILE_BYTES ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	/** @return array<string,mixed> */
	public static function system_snapshot(): array {
		global $wpdb;
		$registrations = ECM_DB::table( 'registrations' );
		$participants  = ECM_DB::table( 'participants' );
		$people        = ECM_DB::table( 'people' );
		$attendance    = ECM_DB::table( 'attendance' );
		$sms           = ECM_DB::table( 'sms' );
		$otp           = ECM_DB::table( 'otp' );
		$events        = ECM_DB::table( 'event_definitions' );
		$start         = ECM_Utils::local_to_utc( self::local_date() . ' 00:00:00' ) ?: gmdate( 'Y-m-d 00:00:00' );
		$end           = ECM_Utils::local_to_utc( self::local_date() . ' 23:59:59' ) ?: gmdate( 'Y-m-d 23:59:59' );
		$settings      = ECM_Utils::settings();
		$uploads       = wp_upload_dir();
		$upload_path   = (string) ( $uploads['basedir'] ?? '' );
		$sms_status_rows = $wpdb->get_results( "SELECT status,COUNT(*) AS total FROM {$sms} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sms_statuses  = array();
		foreach ( $sms_status_rows ?: array() as $row ) {
			$sms_statuses[ sanitize_key( (string) $row->status ) ] = (int) $row->total;
		}
		$oldest_pending = $wpdb->get_var( "SELECT MIN(created_at) FROM {$sms} WHERE status IN ('queued','retry_wait','paused')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$otp_status_rows = $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) AS total FROM {$otp} WHERE created_at BETWEEN %s AND %s GROUP BY status", $start, $end ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$otp_statuses = array();
		foreach ( $otp_status_rows ?: array() as $row ) {
			$otp_statuses[ sanitize_key( (string) $row->status ) ] = (int) $row->total;
		}
		$active_plugins = (array) get_option( 'active_plugins', array() );
		sort( $active_plugins, SORT_STRING );
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$load_average = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : false;
		$disk_free = $upload_path && function_exists( 'disk_free_space' ) ? @disk_free_space( $upload_path ) : false;
		$disk_total = $upload_path && function_exists( 'disk_total_space' ) ? @disk_total_space( $upload_path ) : false;
		return array(
			'wordpress'        => get_bloginfo( 'version' ),
			'php'              => PHP_VERSION,
			'plugin'           => ECM_VERSION,
			'timezone'         => wp_timezone_string(),
			'https'            => is_ssl(),
			'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'next_sms_worker_utc'=> ( $next = wp_next_scheduled( 'ecm_sms_sweep' ) ) ? gmdate( 'c', $next ) : null,
			'published_events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} WHERE status='published' AND enabled=1" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'runtime'           => array(
				'php_sapi'            => PHP_SAPI,
				'php_memory_limit'    => (string) ini_get( 'memory_limit' ),
				'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
				'wp_max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : null,
				'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
				'max_input_vars'       => (int) ini_get( 'max_input_vars' ),
				'post_max_size'        => (string) ini_get( 'post_max_size' ),
				'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
				'opcache_enabled'     => (bool) ini_get( 'opcache.enable' ),
				'external_object_cache'=> function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
				'database_version'    => method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '',
				'server_software'     => substr( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ), 0, 190 ),
				'curl_version'        => function_exists( 'curl_version' ) ? sanitize_text_field( (string) ( curl_version()['version'] ?? '' ) ) : '',
				'load_average'        => is_array( $load_average ) ? array_map( 'floatval', array_slice( $load_average, 0, 3 ) ) : array(),
			),
			'wordpress_environment' => array(
				'active_plugins'      => count( $active_plugins ),
				'active_plugins_fingerprint' => hash( 'sha256', wp_json_encode( $active_plugins ) ?: '' ),
				'active_theme'        => $theme && $theme->exists() ? sanitize_text_field( (string) $theme->get( 'Name' ) ) : '',
				'active_theme_version'=> $theme && $theme->exists() ? sanitize_text_field( (string) $theme->get( 'Version' ) ) : '',
				'wp_debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_debug_log'        => defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG,
			),
			'storage' => array(
				'uploads_writable' => $upload_path ? ( function_exists( 'wp_is_writable' ) ? wp_is_writable( $upload_path ) : is_writable( $upload_path ) ) : false,
				'disk_free_bytes'  => false === $disk_free ? null : (int) $disk_free,
				'disk_total_bytes' => false === $disk_total ? null : (int) $disk_total,
				'activity_file'    => self::status(),
			),
			'database_totals'  => array(
				'registrations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$registrations}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'participants'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$participants} WHERE status='active'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'attendance'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$attendance} WHERE status='success'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'sms_jobs'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sms}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			),
			'data_quality'     => array(
				'registrations_without_primary' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$registrations} WHERE primary_participant_id=0 OR primary_person_id=0" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'active_participants_with_invalid_mobile' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$participants} p LEFT JOIN {$people} pe ON pe.id=p.person_id WHERE p.status='active' AND (pe.id IS NULL OR COALESCE(pe.normalized_phone,'') NOT REGEXP '^09[0-9]{9}$')" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'orphan_participants' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$participants} p LEFT JOIN {$registrations} r ON r.id=p.registration_id LEFT JOIN {$people} pe ON pe.id=p.person_id WHERE r.id IS NULL OR pe.id IS NULL" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'sms_invalid_recipient_total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sms} WHERE error_code='invalid_recipient'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'sms_invalid_recipient_today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sms} WHERE error_code='invalid_recipient' AND created_at BETWEEN %s AND %s", $start, $end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			),
			'today'            => array(
				'registrations' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$registrations} WHERE created_at BETWEEN %s AND %s", $start, $end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'attendance'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attendance} WHERE status='success' AND created_at BETWEEN %s AND %s", $start, $end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'sms_jobs'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sms} WHERE created_at BETWEEN %s AND %s", $start, $end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			),
			'sms'              => array(
				'enabled'   => ! empty( $settings['sms_enabled'] ),
				'paused'    => ! empty( $settings['sms_transport_paused'] ),
				'transport' => sanitize_key( (string) ( $settings['sms_transport'] ?? 'edge' ) ),
				'statuses'  => $sms_statuses,
				'oldest_pending_utc' => $oldest_pending ?: null,
			),
			'otp_today_statuses' => $otp_statuses,
		);
	}
}
