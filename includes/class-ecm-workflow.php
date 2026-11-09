<?php

defined( 'ABSPATH' ) || exit;

/**
 * Phase 2: non-public workflow domain layer. Registration UI, receipt uploads,
 * OTP tracking, approval/issuance, and SMS dispatch are deliberately NOT wired
 * to this layer until their respective security checks exist in later phases.
 */
final class ECM_Workflow {
	private const TRANSITIONS = array(
		'draft'              => array( 'awaiting_agreement', 'cancelled', 'expired' ),
		'awaiting_agreement' => array( 'awaiting_receipt', 'cancelled', 'expired' ),
		'awaiting_receipt'   => array( 'under_review', 'cancelled', 'expired' ),
		'under_review'      => array( 'needs_correction', 'approved', 'rejected' ),
		'needs_correction'   => array( 'awaiting_receipt', 'cancelled', 'expired' ),
		'approved'           => array(),
		'rejected'           => array(),
		'cancelled'          => array(),
		'expired'            => array(),
	);
	private const STEP_TYPES = array( 'form', 'agreement', 'receipt', 'result' );

	/** False by default, including on all existing events. */
	public static function enabled(): bool {
		return 1 === (int) get_option( 'ecm_workflow_enabled', 0 )
			&& ECM_Workflow_Schema::installed();
	}

	/** Existing events retain the original ECM registration path unchanged. */
	public static function mode_for_event( ?object $event ): string {
		if ( ! $event || ! self::enabled() ) {
			return 'classic';
		}
		$config = ECM_Events::config( $event );
		return ( $config['registration_process'] ?? 'classic' ) === 'custom_workflow' ? 'custom_workflow' : 'classic';
	}

	public static function states(): array {
		return array_keys( self::TRANSITIONS );
	}

	/** Domain-level transitions, independent of translated status labels. */
	public static function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] )
			&& in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/**
	 * Verify a versioned definition before it is ever persisted. HTML templates
	 * will be sanitized by the later StepRenderer and are not accepted here yet.
	 */
	public static function validate_definition( array $definition ): array|WP_Error {
		$steps = $definition['steps'] ?? null;
		if ( ! is_array( $steps ) || count( $steps ) < 2 || count( $steps ) > 20 ) {
			return new WP_Error( 'ecm_flow_steps', 'تعداد مراحل باید بین ۲ تا ۲۰ باشد.' );
		}
		$keys  = array();
		$clean = array();
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				return new WP_Error( 'ecm_flow_step_format', 'ساختار مرحله معتبر نیست.' );
			}
			$key = sanitize_key( (string) ( $step['key'] ?? '' ) );
			$type = sanitize_key( (string) ( $step['type'] ?? '' ) );
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,79}$/D', $key ) || isset( $keys[ $key ] )
				|| ! in_array( $type, self::STEP_TYPES, true ) ) {
				return new WP_Error( 'ecm_flow_invalid_step', 'شناسه یا نوع مرحله تکراری یا نامعتبر است.' );
			}
			$keys[ $key ] = true;
			$clean[] = array(
				'key'   => $key,
				'type'  => $type,
				'label' => substr( sanitize_text_field( (string) ( $step['label'] ?? $key ) ), 0, 190 ),
			);
		}
		if ( 'form' !== $clean[0]['type'] || 'result' !== $clean[ count( $clean ) - 1 ]['type'] ) {
			return new WP_Error( 'ecm_flow_order', 'فرایند باید با فرم آغاز و با نتیجه پایان یابد.' );
		}
		if ( count( array_filter( $clean, static fn( array $step ): bool => 'agreement' === $step['type'] ) ) > 1
			|| count( array_filter( $clean, static fn( array $step ): bool => 'receipt' === $step['type'] ) ) > 1 ) {
			return new WP_Error( 'ecm_flow_duplicate_control', 'در این نسخه هر کنترل پذیرش یا رسید تنها یک مرتبه مجاز است.' );
		}
		return array( 'schema' => 1, 'steps' => $clean );
	}

	/** A reusable default flow, not auto-published or activated. */
	public static function default_definition(): array {
		return array( 'steps' => array(
			array( 'key' => 'registration', 'type' => 'form', 'label' => 'اطلاعات ثبت‌نام' ),
			array( 'key' => 'covenant', 'type' => 'agreement', 'label' => 'میثاق‌نامه' ),
			array( 'key' => 'receipt', 'type' => 'receipt', 'label' => 'بارگذاری رسید' ),
			array( 'key' => 'tracking', 'type' => 'result', 'label' => 'پیگیری وضعیت' ),
		) );
	}

	/**
	 * Deterministic phase-2 guard: until the card issuance transaction exists,
	 * NO database operation may mark a pending workflow approved.
	 */
	public static function may_persist_transition( string $from, string $to ): bool {
		return self::enabled() && self::can_transition( $from, $to )
			&& ! in_array( $to, array( 'approved', 'under_review', 'needs_correction', 'rejected' ), true );
	}

	/** Keyed lookup hashes never expose or persist plaintext tracking codes. */
	public static function tracking_digest( string $code ): string {
		return hash_hmac( 'sha256', $code, wp_salt( 'auth' ) );
	}

	public static function identity_digest( int $event_id, string $mobile ): string|WP_Error {
		$normalized = ECM_Utils::normalize_mobile( $mobile );
		if ( $event_id < 1 || '' === $normalized ) {
			return new WP_Error( 'ecm_flow_invalid_identity', 'شناسه رویداد یا شماره موبایل معتبر نیست.' );
		}
		return hash_hmac( 'sha256', $event_id . ':' . $normalized, wp_salt( 'auth' ) );
	}
}
