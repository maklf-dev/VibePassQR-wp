<?php

defined( 'ABSPATH' ) || exit;

/**
 * Phase 4: independently opt-in, read-only status lookup. A 128-bit random tracking secret
 * AND matching normalized mobile are required. Mobile-only lookup awaits OTP.
 * This never exposes receipt images, form payloads or raw tracking secrets.
 */
final class ECM_Workflow_Tracking {
	public static function hooks(): void {
		add_shortcode( 'ecm_tracking_lookup', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'ecm_flow_tracking', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'prevent_tracking_cache' ), 1 );
	}

	/** A lookup result contains private state; prevent shared page caches before output. */
	public static function prevent_tracking_cache(): void {
		if ( ! self::available() ) { return; }
		global $post;
		// A tracking shortcode may live in a page-builder meta field rather than
		// post_content. A lookup POST must be non-cacheable in either case.
		$is_lookup_post = isset( $_POST['ecm_flow_lookup_submit'] );
		$content = $post instanceof WP_Post ? (string) $post->post_content : '';
		if ( $is_lookup_post || has_shortcode( $content, 'ecm_tracking_lookup' ) || has_shortcode( $content, 'ecm_flow_tracking' ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			nocache_headers();
		}
	}

	public static function available(): bool {
		return ECM_Workflow::enabled()
			&& ( ( function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true ) && defined( 'ECM_WORKFLOW_PUBLIC_TRACKING' ) && true === ECM_WORKFLOW_PUBLIC_TRACKING )
				|| current_user_can( 'manage_ecm_reviews' ) );
	}

	/** @return object|WP_Error */
	public static function find( string $tracking, string $phone, bool $require_enabled = true ) {
		if ( $require_enabled && ! self::available() ) { return new WP_Error( 'ecm_flow_tracking_disabled', 'پیگیری این فرایند هنوز فعال نیست.' ); }
		$tracking = strtoupper( trim( ECM_Utils::normalize_digits( $tracking ) ) );
		$phone = ECM_Utils::normalize_mobile( $phone );
		if ( ! preg_match( '/^[A-F0-9]{32}$/D', $tracking ) || ! $phone ) {
			return new WP_Error( 'ecm_flow_tracking_invalid', 'کد پیگیری یا شماره موبایل نامعتبر است.' );
		}
		global $wpdb;
		$hash = ECM_Workflow::tracking_digest( $tracking );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,event_id,state,identifier_digest,linked_registration_id FROM ' . ECM_DB::table( 'flow_instances' ) . ' WHERE tracking_hash=%s LIMIT 1', $hash ) );
		if ( ! $row ) { return new WP_Error( 'ecm_flow_tracking_invalid', 'کد پیگیری یا شماره موبایل نامعتبر است.' ); }
		$digest = ECM_Workflow::identity_digest( (int) $row->event_id, $phone );
		if ( is_wp_error( $digest ) || ! hash_equals( (string) $row->identifier_digest, (string) $digest ) ) {
			return new WP_Error( 'ecm_flow_tracking_invalid', 'کد پیگیری یا شماره موبایل نامعتبر است.' );
		}
		return $row;
	}

	private static function throttle(): bool {
		$ip = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		// No raw IP, phone or tracking code is stored in transients.
		$key = 'ecm_flow_lookup_' . substr( hash_hmac( 'sha256', $ip ?: 'no-ip', wp_salt( 'nonce' ) ), 0, 40 );
		$attempts = (int) get_transient( $key );
		if ( $attempts >= 12 ) { return false; }
		set_transient( $key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
		return true;
	}

	public static function shortcode(): string {
		if ( ! self::available() ) {
			return '<p>پیگیری فرایند جدید در حال حاضر فعال نیست.</p>';
		}
		$message = '';
		$details = '';
		if ( 'POST' === (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['ecm_flow_lookup_submit'] ) ) {
			$nonce = sanitize_text_field( (string) wp_unslash( $_POST['ecm_flow_lookup_nonce'] ?? '' ) );
			if ( ! wp_verify_nonce( $nonce, 'ecm_flow_lookup' ) || ! self::throttle() ) {
				$message = 'درخواست معتبر نیست یا تعداد تلاش‌ها بیش از حد مجاز است.';
			} else {
				$code = sanitize_text_field( (string) wp_unslash( $_POST['ecm_flow_code'] ?? '' ) );
				$phone = sanitize_text_field( (string) wp_unslash( $_POST['ecm_flow_phone'] ?? '' ) );
				$result = self::find( $code, $phone );
				if ( is_wp_error( $result ) ) {
					$message = 'کد پیگیری و شماره موبایل مطابقت ندارند.';
				} else {
					$state = (string) $result->state;
					$details = '<p><strong>وضعیت درخواست: ' . esc_html( ECM_Workflow_Review::state_label( $state ) ) . '</strong></p>';
					if ( in_array( $state, array( 'needs_correction', 'rejected' ), true ) ) {
						global $wpdb;
						$meta = $wpdb->get_var( $wpdb->prepare( 'SELECT meta FROM ' . ECM_DB::table( 'events' ) . " WHERE flow_instance_id=%d AND event_type='workflow_review' ORDER BY id DESC LIMIT 1", (int) $result->id ) );
						$reason_data = $meta ? json_decode( (string) $meta, true ) : null;
						$reason = is_array( $reason_data ) ? (string) ( $reason_data['reason_public'] ?? '' ) : '';
						if ( '' !== $reason ) { $details .= '<p>علت: ' . esc_html( $reason ) . '</p>'; }
					}
					if ( 'approved' === $state && (int) $result->linked_registration_id > 0 ) {
						$registration_id = (int) $result->linked_registration_id;
						$registration = ECM_DB::registration( $registration_id );
						if ( $registration && (int) $registration->event_id === (int) $result->event_id && 'offline_confirmed' === (string) $registration->payment_status ) {
							$primary = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ECM_DB::table( 'participants' ) . " WHERE registration_id=%d AND participant_type='primary' AND status='active' LIMIT 1", $registration_id ) );
							$card = $primary ? ECM_Tickets::card_url( $primary ) : '';
							if ( $card ) { $details .= '<p><a href="' . esc_url( $card ) . '" rel="noopener noreferrer">مشاهده کارت تأییدشده</a></p>'; }
						}
					}
				}
			}
		}
		ob_start();
		?>
		<div class="ecm-flow-lookup" dir="rtl">
			<h2>پیگیری وضعیت ثبت‌نام</h2>
			<p>کد پیگیری و همان شماره موبایلی که هنگام ثبت‌نام وارد کرده‌اید را درج کنید.</p>
			<?php if ( $message ) : ?><p role="alert"><?php echo esc_html( $message ); ?></p><?php endif; ?>
			<?php if ( $details ) : ?><div role="status"><?php echo wp_kses_post( $details ); ?></div><?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'ecm_flow_lookup', 'ecm_flow_lookup_nonce' ); ?>
				<label>کد پیگیری <input name="ecm_flow_code" type="text" required maxlength="32" autocomplete="off" dir="ltr" pattern="[A-Fa-f0-9]{32}"></label>
				<label>شماره موبایل <input name="ecm_flow_phone" type="tel" required autocomplete="tel" inputmode="numeric"></label>
				<button type="submit" name="ecm_flow_lookup_submit" value="1">بررسی وضعیت</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
