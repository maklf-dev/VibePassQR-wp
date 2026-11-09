<?php

defined( 'ABSPATH' ) || exit;

/**
 * Compact, revocable public URLs for participant cards and attendance.
 *
 * The former PDF-template renderer is intentionally disabled. Historical
 * database columns and uploaded templates are left untouched so upgrades are
 * non-destructive, but cards are always rendered by the native HTML layout.
 */
final class ECM_Tickets {
	private const TOKEN_LENGTH = 22;

	public static function hooks(): void {
		add_action( 'init', array( __CLASS__, 'rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_short_route' ), -8 );
	}

	public static function install(): void {
		self::rewrite_rules();
	}

	public static function rewrite_rules(): void {
		add_rewrite_rule( '^t/([A-Za-z0-9_-]{22})/?$', 'index.php?ecm_ticket_token=$matches[1]', 'top' );
		add_rewrite_rule( '^a/([A-Za-z0-9_-]{22})/?$', 'index.php?ecm_attendance_token=$matches[1]', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'ecm_ticket_token';
		$vars[] = 'ecm_attendance_token';
		return $vars;
	}

	/** Compatibility shim for registrations created by earlier releases. */
	public static function current_template_id( ?object $event ): int {
		return 0;
	}

	/**
	 * Resolve a historical uploaded template for backup only. No template is
	 * exposed publicly or used to build a card in this release.
	 */
	public static function template_path( object $template ): string {
		if ( 'upload' !== (string) ( $template->source_type ?? '' ) ) {
			return '';
		}
		$relative = ltrim( str_replace( '\\', '/', (string) ( $template->relative_path ?? '' ) ), '/' );
		if ( ! preg_match( '#^event-card-manager-ticket-templates/event-\d+/[A-Za-z0-9._-]+\.pdf$#', $relative ) ) {
			return '';
		}
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . $relative;
		return is_file( $path ) ? $path : '';
	}

	public static function card_url( object $participant ): string {
		$token = self::ensure_token( $participant, 'card' );
		return $token ? home_url( '/t/' . rawurlencode( $token ) . '/' ) : '';
	}

	public static function attendance_url( object $participant ): string {
		$token = self::ensure_token( $participant, 'attendance' );
		return $token ? home_url( '/a/' . rawurlencode( $token ) . '/' ) : '';
	}

	private static function ensure_token( object $participant, string $scope ): string {
		$scope = in_array( $scope, array( 'card', 'attendance' ), true ) ? $scope : 'card';
		global $wpdb;
		$table = ECM_DB::table( 'ticket_tokens' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE participant_id=%d AND scope=%s AND status='active' LIMIT 1", (int) $participant->id, $scope ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row ) {
			$plain = ECM_Upgrade::decrypt( (string) $row->token_cipher );
			if ( self::valid_token( $plain ) && hash_equals( (string) $row->token_hash, hash( 'sha256', $plain ) ) ) {
				return $plain;
			}
		}
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$token = rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
			$hash  = hash( 'sha256', $token );
			try {
				$cipher = ECM_Upgrade::encrypt( $token );
			} catch ( Throwable $error ) {
				ECM_Diagnostics::record( 'ticket', 'Short token encryption unavailable', array( 'scope' => $scope ) );
				return '';
			}
			$data = array(
				'participant_id' => (int) $participant->id,
				'registration_id'=> (int) $participant->registration_id,
				'event_id'       => (int) $participant->event_id,
				'scope'          => $scope,
				'token_hash'     => $hash,
				'token_cipher'   => $cipher,
				'status'         => 'active',
				'created_at'     => ECM_Utils::now_utc(),
				'updated_at'     => ECM_Utils::now_utc(),
			);
			if ( $row ) {
				$updated = $wpdb->update( $table, $data, array( 'id' => (int) $row->id ), array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
				if ( false !== $updated ) {
					return $token;
				}
			} elseif ( $wpdb->insert( $table, $data, array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) ) ) {
				return $token;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE participant_id=%d AND scope=%s AND status='active' LIMIT 1", (int) $participant->id, $scope ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row ) {
				$plain = ECM_Upgrade::decrypt( (string) $row->token_cipher );
				if ( self::valid_token( $plain ) ) {
					return $plain;
				}
			}
		}
		return '';
	}

	private static function valid_token( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{' . self::TOKEN_LENGTH . '}$/', $token );
	}

	private static function resolve_token( string $token, string $scope ): ?object {
		if ( ! self::valid_token( $token ) ) {
			return null;
		}
		global $wpdb;
		$tokens        = ECM_DB::table( 'ticket_tokens' );
		$participants  = ECM_DB::table( 'participants' );
		$registrations = ECM_DB::table( 'registrations' );
		$hash          = hash( 'sha256', $token );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.* FROM {$tokens} t INNER JOIN {$participants} p ON p.id=t.participant_id INNER JOIN {$registrations} r ON r.id=p.registration_id WHERE t.token_hash=%s AND t.scope=%s AND t.status='active' AND p.status='active' AND r.payment_status IN ('paid','not_required','offline_confirmed') LIMIT 1",
				$hash,
				$scope
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function resolve_attendance_input( string $value ): ?object {
		$value = trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $value || strlen( $value ) > 4096 ) {
			return null;
		}
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$decoded = rawurldecode( $value );
			if ( $decoded === $value ) {
				break;
			}
			$value = $decoded;
		}
		// The short code printed below every card is a participant public ID.
		// Accept it directly and normalize it explicitly so lookups are not
		// dependent on the database collation being case-insensitive.
		if ( preg_match( '/^[A-Z0-9]{10}$/i', $value ) ) {
			$participant = self::resolve_public_id( strtoupper( $value ) );
			if ( $participant ) {
				return $participant;
			}
		}

		$token = '';
		if ( self::valid_token( $value ) ) {
			$token = $value;
		} else {
			$path = (string) wp_parse_url( $value, PHP_URL_PATH );
			if ( preg_match( '#(?:^|/)a/([A-Za-z0-9_-]{22})(?:/|$)#', $path, $match ) ) {
				$token = $match[1];
			}
		}
		return $token ? self::resolve_token( $token, 'attendance' ) : null;
	}

	private static function resolve_public_id( string $public_id ): ?object {
		global $wpdb;
		$participants  = ECM_DB::table( 'participants' );
		$registrations = ECM_DB::table( 'registrations' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.* FROM {$participants} p INNER JOIN {$registrations} r ON r.id=p.registration_id WHERE p.public_id=%s AND p.status='active' AND r.payment_status IN ('paid','not_required','offline_confirmed') LIMIT 1",
				$public_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function handle_short_route(): void {
		$card_token = sanitize_text_field( (string) get_query_var( 'ecm_ticket_token' ) );
		$att_token  = sanitize_text_field( (string) get_query_var( 'ecm_attendance_token' ) );
		if ( ! $card_token && ! $att_token ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		$participant = self::resolve_token( $card_token ?: $att_token, $card_token ? 'card' : 'attendance' );
		if ( ! $participant ) {
			ECM_Activity::mark_access_denied( 'short_token_invalid_or_revoked' );
			status_header( 404 );
			wp_die( 'این لینک کارت معتبر نیست یا دسترسی آن لغو شده است.', 'لینک نامعتبر', array( 'response' => 404 ) );
		}
		$registration = ECM_DB::registration( (int) $participant->registration_id );
		$event        = $registration ? ECM_Events::registration_event( $registration ) : null;
		if ( ! $registration || ! ECM_Events::is_publicly_active( $event ) ) {
			$reason = ! $registration ? 'registration_missing' : ( ! $event ? 'event_missing' : ( empty( $event->enabled ) ? 'event_disabled' : 'event_not_published' ) );
			ECM_Activity::mark_access_denied( $reason, $event ? (int) $event->id : 0, $registration ? (int) $registration->id : 0 );
			status_header( 403 );
			wp_die( $event ? esc_html( $event->disabled_message ) : 'رویداد در دسترس نیست.', 'دسترسی بسته است', array( 'response' => 403 ) );
		}
		if ( $card_token ) {
			if ( ! ECM_Participants::grant_recovered_card( $participant ) ) {
				ECM_Activity::mark_access_denied( 'card_grant_failed', $event ? (int) $event->id : 0, (int) $registration->id );
				wp_die( 'ایجاد دسترسی امن کارت انجام نشد.', 'خطای کارت', array( 'response' => 500 ) );
			}
			$args = array( 'id' => (int) $registration->id );
			if ( 'guest' === (string) $participant->participant_type ) {
				$args['participant'] = (string) $participant->public_id;
				$args['scope']       = 'guest';
			}
			ECM_Utils::log( (int) $registration->id, 'card_recovered', array( 'via' => 'short_link', 'participant_id' => (int) $participant->id ) );
			wp_safe_redirect( ECM_Utils::page_url( 'card', $args ), 302 );
			exit;
		}
		$legacy = 'primary' === (string) $participant->participant_type ? ECM_Utils::qr_token( $registration ) : ECM_Utils::participant_qr_token( $participant );
		wp_safe_redirect( ECM_Utils::page_url( 'attendance', array( 'token' => rawurldecode( $legacy ) ) ), 302 );
		exit;
	}
}
