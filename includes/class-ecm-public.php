<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Public {
	public function hooks(): void {
		add_shortcode( 'ecm_registration', array( $this, 'registration_shortcode' ) );
		add_shortcode( 'ecm_recovery', array( $this, 'recovery_shortcode' ) );
		add_shortcode( 'ecm_card', array( $this, 'card_shortcode' ) );
		add_shortcode( 'ecm_event_list', array( $this, 'event_list_shortcode' ) );
		add_shortcode( 'ecm_attendance', array( $this, 'attendance_shortcode' ) );
		add_shortcode( 'ecm_manager', array( $this, 'manager_shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'admin_post_nopriv_ecm_register', array( $this, 'handle_registration' ) );
		add_action( 'admin_post_ecm_register', array( $this, 'handle_registration' ) );
		add_action( 'admin_post_nopriv_ecm_recover', array( $this, 'handle_recovery' ) );
		add_action( 'admin_post_ecm_recover', array( $this, 'handle_recovery' ) );
		add_action( 'template_redirect', array( $this, 'ensure_rate_limit_cookie' ), 0 );
		add_action( 'template_redirect', array( $this, 'prevent_private_cache' ) );
		add_filter( 'wp_robots', array( $this, 'private_page_robots' ) );
	}

	/**
	 * Give each browser a privacy-neutral identifier for public-form throttling.
	 * This avoids blocking hundreds of legitimate attendees behind one venue NAT.
	 */
	public function ensure_rate_limit_cookie(): void {
		if ( is_admin() || headers_sent() || ! empty( $_COOKIE['ecm_client'] ) ) {
			return;
		}
		$pages = (array) get_option( 'ecm_pages', array() );
		$public_ids = array_filter( array_map( 'absint', array( $pages['registration'] ?? 0, $pages['recovery'] ?? 0 ) ) );
		if ( ! $public_ids || ! is_page( $public_ids ) ) {
			return;
		}
		$client = str_replace( '-', '', wp_generate_uuid4() );
		setcookie(
			'ecm_client',
			$client,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE['ecm_client'] = $client;
	}

	public function prevent_private_cache(): void {
		$pages = (array) get_option( 'ecm_pages', array() );
		$ids = array_filter(array_map('absint',$pages));
		if ( $ids && is_page( $ids ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	public function private_page_robots( array $robots ): array {
		$pages = (array) get_option( 'ecm_pages', array() );
		$ids   = array_filter( array_map( 'absint', $pages ) );
		if ( $ids && is_page( $ids ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public function register_assets(): void {
		wp_register_style( 'ecm-public', ECM_URL . 'assets/css/public.css', array(), ECM_VERSION );
		wp_register_script( 'ecm-qr', ECM_URL . 'assets/js/qr.js', array(), ECM_VERSION, true );
		wp_register_script( 'ecm-jsqr', ECM_URL . 'assets/vendor/jsqr/jsQR.js', array(), '1.4.0', true );
		wp_register_script( 'ecm-public', ECM_URL . 'assets/js/public.js', array( 'ecm-qr' ), ECM_VERSION, true );
		wp_localize_script( 'ecm-public', 'ECMRecovery', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => ECM_OTP::nonce() ) );
		wp_register_script( 'ecm-operator', ECM_URL . 'assets/js/operator.js', array( 'ecm-jsqr' ), ECM_VERSION, true );
        wp_register_style('ecm-design',ECM_URL.'assets/css/design.css',array('ecm-public'),ECM_VERSION);
        wp_add_inline_style('ecm-design',ECM_Upgrade::style());
        if(current_user_can('manage_ecm_settings') || is_page(array_values((array)get_option('ecm_pages',array())))) wp_add_inline_style('ecm-design',ECM_Upgrade::custom_style('public'));

		$pages       = array_filter( array_map( 'absint', (array) get_option( 'ecm_pages', array() ) ) );
		$has_content = false;
		global $post;
		if ( $post instanceof WP_Post ) {
			foreach ( array( 'ecm_registration', 'ecm_recovery', 'ecm_card', 'ecm_attendance', 'ecm_manager', 'ecm_portal', 'ecm_event_list', 'ecm_flow' ) as $shortcode ) {
				if ( has_shortcode( $post->post_content, $shortcode ) ) {
					$has_content = true;
					break;
				}
			}
		}
		if ( ( $pages && is_page( $pages ) ) || $has_content ) {
			wp_enqueue_style( 'ecm-public' );
            wp_enqueue_style('ecm-design');
			wp_enqueue_script( 'ecm-public' );
		}
	}

	private function assets( int $registration_id = 0 ): void {
        wp_enqueue_style('ecm-design');
		wp_enqueue_style( 'ecm-public' );
		wp_enqueue_script( 'ecm-public' );
	}

	private function shell( string $content, string $class = '', string $title = '' ): string {
		$event_slug = isset( $_GET['event'] ) ? sanitize_title( wp_unslash( $_GET['event'] ) ) : '';
		$notice_event = $event_slug ? ECM_Events::event_by_slug( $event_slug ) : ECM_Events::event( ECM_Events::default_event_id() );
		$fallback_title = $notice_event ? (string) $notice_event->title : (string) ECM_Utils::settings()['event_name'];
		return '<section class="ecm-shell ' . esc_attr( $class ) . '" dir="rtl"><div class="ecm-phone-bar"><span></span><strong>' . esc_html( $title ?: $fallback_title ) . '</strong><span></span></div>' . $content . '</section>';
	}

	/** Optional public directory; draft/archived events are never exposed. */
	public function event_list_shortcode(): string {
		$this->assets();
		$events = array_filter( ECM_Events::all(), static fn( $event ): bool => 'published' === (string) $event->status );
		ob_start();
		?>
		<section class="ecm-event-directory" dir="rtl"><h1>فهرست رویدادها</h1>
		<?php if ( ! $events ) : ?><p>در حال حاضر رویداد منتشرشده‌ای وجود ندارد.</p><?php endif; ?>
		<div class="ecm-event-directory__grid"><?php foreach ( $events as $event ) : $active = ECM_Events::is_publicly_active( $event ); ?>
		<article class="ecm-event-directory__item"><h2><?php echo esc_html( $event->title ); ?></h2><span class="ecm-badge"><?php echo esc_html( $active ? 'فعال' : 'غیرفعال' ); ?></span>
		<?php if ( $active && 'custom_workflow' === ECM_Workflow::mode_for_event( $event ) ) : ?><?php if ( ECM_Workflow_Public::available( $event ) ) : ?><a class="ecm-button ecm-button--primary" href="<?php echo esc_url( ECM_Events::registration_url( $event ) ); ?>">ثبت‌نام چندمرحله‌ای</a><?php else : ?><p>ثبت‌نام اختصاصی این رویداد هنوز برای عموم فعال نشده است.</p><?php endif; ?><?php elseif ( $active ) : ?><a class="ecm-button ecm-button--primary" href="<?php echo esc_url( ECM_Events::registration_url( $event ) ); ?>">ثبت‌نام در رویداد</a><?php else : ?><p><?php echo esc_html( (string) $event->disabled_message ); ?></p><?php endif; ?></article>
		<?php endforeach; ?></div></section>
		<?php
		return (string) ob_get_clean();
	}

	public function registration_shortcode(): string {
		$this->assets();
		$event    = ECM_Events::requested_event();
		if ( ! $event ) {
			return $this->attendance_state( 'error', 'این رویداد منتشر نشده یا در دسترس نیست.', 'رویداد در دسترس نیست' );
		}
		if ( ! ECM_Events::is_publicly_active( $event ) ) {
			return $this->attendance_state( 'warning', (string) $event->disabled_message, (string) $event->disabled_title );
		}
		// Fail closed on explicitly opted-in staging events: Classic would
		// otherwise issue a card immediately and bypass manual review.
		if ( 'custom_workflow' === ECM_Workflow::mode_for_event( $event ) ) {
			return ECM_Workflow_Public::available( $event )
				? ECM_Workflow_Public::render( $event )
				: $this->attendance_state( 'warning', 'این رویداد در حال آماده‌سازی فرایند ثبت‌نام اختصاصی است و ثبت‌نام عمومی آن هنوز فعال نشده است.', 'ثبت‌نام موقتاً غیرفعال است' );
		}
		$settings = ECM_Events::config( $event );
		$notice   = $this->notice_from_query();
		ob_start();
		?>
		<div class="ecm-hero">
			<div><h1><?php echo esc_html( $settings['registration_title'] ?: $event->title ); ?></h1></div>
			<a class="ecm-button ecm-button--ghost" href="<?php echo esc_url( ECM_Utils::page_url( 'recovery', array( 'event' => $event->slug ) ) ); ?>"><?php echo esc_html( $settings['recovery_link_text'] ); ?></a>
		</div>
		<div class="ecm-content">
			<?php echo wp_kses_post( $settings['form_intro'] ); ?>
			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<form class="ecm-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecm_register">
				<input type="hidden" name="ecm_event_id" value="<?php echo esc_attr( $event->id ); ?>">
				<?php wp_nonce_field( 'ecm_register', 'ecm_nonce' ); ?>
				<label class="ecm-hp" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
				<div class="ecm-field-grid">
						<?php foreach ( (array) $settings['fields'] as $field ) : ?>
							<?php echo $this->render_field( (array) $field, '', $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
				<?php if(!empty($settings['payment_required'])): ?><div class="ecm-price-row">
					<span><?php echo esc_html( $settings['price_label'] ); ?></span>
					<strong><bdi><?php echo esc_html( number_format_i18n( (float) $settings['amount'], (int) $settings['currency_decimals'] ) ); ?> <?php echo esc_html( $settings['currency_label'] ); ?></bdi></strong>
					<?php if ( 'ریال' !== trim( $settings['currency_label'] ) ) : ?>
						<small>معادل <?php echo esc_html( number_format_i18n( (float) ECM_Utils::rial_amount( (string) $settings['amount'], (string) $settings['rial_rate'] ) ) ); ?> ریال</small>
					<?php endif; ?>
				</div><?php endif; ?>
				<button class="ecm-button ecm-button--primary ecm-button--wide" type="submit"><?php echo esc_html( empty($settings['payment_required']) ? $settings['registration_button'] : $settings['payment_button'] ); ?></button>
			</form>
		</div>
		<?php
		return $this->shell( (string) ob_get_clean(), 'ecm-registration', (string) $event->title );
	}

	public function render_field( array $field, string $value = '', ?object $event = null ): string {
		$key         = sanitize_key( $field['key'] ?? '' );
		$type        = sanitize_key( $field['type'] ?? 'text' );
		$label       = (string) ( $field['label'] ?? $key );
		$required    = ! empty( $field['required'] );
		$placeholder = (string) ( $field['placeholder'] ?? '' );
		$options     = (array) ( $field['options'] ?? array() );
		if ( ! $key ) {
			return '';
		}
		if ( 'html' === $type ) {
			return '<div class="ecm-field ecm-field--full ecm-custom-html">' . wp_kses_post( $placeholder ) . '</div>';
		}
		if ( 'guest' === $type ) {
			$limit = $event ? min( 20, max( 0, (int) ( $event->guest_limit ?? 0 ) ) ) : 0;
			if ( ! $limit ) {
				return '';
			}
			ob_start();
			?>
			<div class="ecm-field ecm-field--full ecm-guest-field" data-guest-field="<?php echo esc_attr( $key ); ?>" data-guest-max="<?php echo esc_attr( $limit ); ?>" data-guest-required="<?php echo $required ? '1' : '0'; ?>">
				<div class="ecm-guest-field__header"><div><label><?php echo esc_html( $label ); ?><?php if ( $required ) : ?><em>*</em><?php endif; ?></label><small><?php echo esc_html( $placeholder ?: 'برای هر مهمان نام و شماره موبایل را وارد کنید.' ); ?></small></div><span data-guest-count>۰ از <?php echo esc_html( $limit ); ?></span></div>
				<div class="ecm-guest-list" data-guest-list></div>
				<button type="button" class="ecm-button ecm-button--ghost ecm-guest-add" data-add-guest>افزودن مهمان</button>
			</div>
			<?php
			return (string) ob_get_clean();
		}
		$name = 'ecm_field[' . $key . ']';
		ob_start();
		?>
		<div class="ecm-field <?php echo 'textarea' === $type ? 'ecm-field--full' : ''; ?>">
			<label for="ecm-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?><?php if ( $required ) : ?><em>*</em><?php endif; ?></label>
			<?php if ( 'textarea' === $type ) : ?>
				<textarea id="ecm-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo $required ? 'required' : ''; ?>><?php echo esc_textarea( $value ); ?></textarea>
			<?php elseif ( 'select' === $type ) : ?>
				<select id="ecm-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?>><option value="">انتخاب کنید</option><?php foreach ( $options as $option ) : ?><option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, $option ); ?>><?php echo esc_html( $option ); ?></option><?php endforeach; ?></select>
			<?php elseif ( in_array( $type, array( 'radio', 'checkbox' ), true ) ) : ?>
				<div class="ecm-choices" <?php echo $required && 'checkbox' === $type ? 'data-ecm-required-group' : ''; ?>><?php foreach ( $options as $index => $option ) : ?><label><input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name . ( 'checkbox' === $type ? '[]' : '' ) ); ?>" value="<?php echo esc_attr( $option ); ?>" <?php echo $required && 'radio' === $type && 0 === $index ? 'required' : ''; ?>> <span><?php echo esc_html( $option ); ?></span></label><?php endforeach; ?></div>
			<?php else : ?>
				<input id="ecm-<?php echo esc_attr( $key ); ?>" type="<?php echo esc_attr( in_array( $type, array( 'text', 'email', 'tel', 'number', 'date', 'time', 'url' ), true ) ? $type : 'text' ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo 'tel' === $type ? 'inputmode="numeric" autocomplete="tel" maxlength="11" pattern="[0۰٠][9۹٩][0-9۰-۹٠-٩]{9}" data-ecm-mobile' : ''; ?> <?php echo $required ? 'required' : ''; ?>>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function handle_registration(): void {
		$event_id = absint( $_POST['ecm_event_id'] ?? 0 );
		$event    = ECM_Events::event( $event_id );
		$redirect = $event ? ECM_Events::registration_url( $event ) : ECM_Utils::page_url( 'registration' );
		if ( ! isset( $_POST['ecm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ecm_nonce'] ) ), 'ecm_register' ) ) {
			self::record_registration_rejection( $event_id, 'security' );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'security', $redirect ) );
			exit;
		}
		if ( ! empty( $_POST['website'] ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}
		if ( ! self::rate_limit( 'register', (int) apply_filters( 'ecm_registration_rate_limit', 100 ), 10 * MINUTE_IN_SECONDS ) ) {
			self::record_registration_rejection( $event_id, 'rate_limited' );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'rate', $redirect ) );
			exit;
		}
		if ( ! ECM_Events::is_publicly_active( $event ) ) {
			self::record_registration_rejection( $event_id, 'event_unavailable' );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'event', $redirect ) );
			exit;
		}

		if ( 'custom_workflow' === ECM_Workflow::mode_for_event( $event ) ) {
			self::record_registration_rejection( (int) $event->id, 'workflow_not_public' );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'event', $redirect ) );
			exit;
		}
		$settings = ECM_Events::config( $event );
		$raw      = isset( $_POST['ecm_field'] ) && is_array( $_POST['ecm_field'] ) ? wp_unslash( $_POST['ecm_field'] ) : array();
		$raw_guests = isset( $_POST['ecm_guest'] ) && is_array( $_POST['ecm_guest'] ) ? wp_unslash( $_POST['ecm_guest'] ) : array();
		$data     = array();
		$errors   = array();
		$phone_errors = array();
		$guests   = array();
		foreach ( (array) $settings['fields'] as $field ) {
			$field = (array) $field;
			$key   = sanitize_key( $field['key'] ?? '' );
			$type  = sanitize_key( $field['type'] ?? 'text' );
			if ( ! $key || 'html' === $type ) {
				continue;
			}
			if ( 'guest' === $type ) {
				$limit       = min( 20, max( 0, (int) ( $event->guest_limit ?? 0 ) ) );
				$rows        = isset( $raw_guests[ $key ] ) && is_array( $raw_guests[ $key ] ) ? array_slice( $raw_guests[ $key ], 0, $limit ) : array();
				$field_guests = array();
				foreach ( $rows as $row ) {
					$name  = self::limit_text( sanitize_text_field( $row['name'] ?? '' ), 190 );
					$raw_phone = sanitize_text_field( $row['phone'] ?? '' );
					$phone = ECM_Utils::normalize_mobile( $raw_phone );
					if ( '' === $name && '' === sanitize_text_field( $row['phone'] ?? '' ) ) {
						continue;
					}
					if ( ! $name || ! $phone ) {
						if ( '' !== trim( $raw_phone ) && ! $phone ) {
							$phone_errors[] = (string) ( $field['label'] ?? 'شماره موبایل مهمان' );
						} else {
							$errors[] = (string) ( $field['label'] ?? 'مهمان' );
						}
						continue;
					}
					$field_guests[] = array( 'name' => $name, 'phone' => $phone );
				}
				if ( $limit > 0 && ! empty( $field['required'] ) && ! $field_guests ) {
					$errors[] = (string) ( $field['label'] ?? 'مهمان' );
				}
				$data[ $key ] = $field_guests;
				$guests = array_merge( $guests, $field_guests );
				continue;
			}
			$value = $raw[ $key ] ?? '';
			$raw_value = is_scalar( $value ) ? (string) $value : '';
			$value = $this->sanitize_field_value( $value, $type, (array) ( $field['options'] ?? array() ) );
			if ( 'tel' === $type && '' !== trim( $raw_value ) && '' === $value ) {
				$phone_errors[] = (string) ( $field['label'] ?? $key );
			} elseif ( ! empty( $field['required'] ) && ( '' === $value || array() === $value ) ) {
				$errors[] = (string) ( $field['label'] ?? $key );
			}
			if ( 'email' === $type && $value && ! is_email( (string) $value ) ) {
				$errors[] = (string) ( $field['label'] ?? $key );
			}
			$data[ $key ] = $value;
		}
		if ( $phone_errors ) {
			ECM_Activity::record( 'operation', array( 'operation' => 'registration_rejected', 'event_id' => (int) $event->id, 'is_error' => true, 'error_code' => 'invalid_mobile', 'field_count' => count( array_unique( $phone_errors ) ) ) );
			ECM_Diagnostics::record( 'registration', 'Registration rejected: invalid mobile', array( 'event_id' => (int) $event->id, 'invalid_field_count' => count( array_unique( $phone_errors ) ) ) );
			wp_safe_redirect( add_query_arg( array( 'ecm_error' => 'invalid_mobile', 'fields' => implode( '،', array_unique( $phone_errors ) ) ), $redirect ) );
			exit;
		}
		if ( $errors ) {
			ECM_Activity::record( 'operation', array( 'operation' => 'registration_rejected', 'event_id' => (int) $event->id, 'is_error' => true, 'error_code' => 'required', 'field_count' => count( array_unique( $errors ) ) ) );
			wp_safe_redirect( add_query_arg( array( 'ecm_error' => 'required', 'fields' => implode( '،', $errors ) ), $redirect ) );
			exit;
		}
		$guest_phones = array_column( $guests, 'phone' );
		$primary_phone = ECM_Events::normalize_phone( ECM_Utils::field_value( $data, (string) ( $settings['sms_field'] ?? 'mobile' ) ) );
		if ( count( $guests ) > min( 20, max( 0, (int) ( $event->guest_limit ?? 0 ) ) ) ) {
			self::record_registration_rejection( (int) $event->id, 'guest_limit', array( 'guest_count' => count( $guests ) ) );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'guest_limit', $redirect ) );
			exit;
		}
		if ( empty( $settings['allow_duplicate_guest_phone'] ) ) {
			if ( count( $guest_phones ) !== count( array_unique( $guest_phones ) ) || ( $primary_phone && in_array( $primary_phone, $guest_phones, true ) ) ) {
				self::record_registration_rejection( (int) $event->id, 'duplicate_guest_phone', array( 'guest_count' => count( $guests ) ) );
				wp_safe_redirect( add_query_arg( 'ecm_error', 'duplicate_guest', $redirect ) );
				exit;
			}
		}

		global $wpdb;
			$dedupe_hash = null;
			if ( ! empty( $settings['prevent_duplicate_phone'] ) && $primary_phone ) {
				$phone_hash   = hash_hmac( 'sha256', $primary_phone, wp_salt( 'auth' ) );
				$dedupe_hash  = $phone_hash;
				$registrations = ECM_DB::table( 'registrations' );
				$participants  = ECM_DB::table( 'participants' );
				$people        = ECM_DB::table( 'people' );
				$test_exempt = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT r.id FROM {$registrations} r INNER JOIN {$participants} p ON p.registration_id=r.id AND p.participant_type='primary' INNER JOIN {$people} pe ON pe.id=p.person_id WHERE r.event_id=%d AND r.is_test=1 AND pe.phone_hash=%s LIMIT 1",
						(int) $event->id,
						$phone_hash
					)
				); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $test_exempt ) {
					// Marking a registration as test explicitly whitelists its phone for
					// repeated test submissions in this event. NULL also avoids the live
					// unique key without weakening other phone numbers.
					$dedupe_hash = null;
				} else {
					$duplicate = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT r.id FROM {$registrations} r LEFT JOIN {$participants} p ON p.registration_id=r.id AND p.participant_type='primary' LEFT JOIN {$people} pe ON pe.id=p.person_id WHERE r.event_id=%d AND r.is_test=0 AND (r.dedupe_phone_hash=%s OR pe.phone_hash=%s) AND r.payment_status IN ('paid','not_required','offline_confirmed','pending') LIMIT 1",
							(int) $event->id,
							$phone_hash,
							$phone_hash
						)
					); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( $duplicate ) {
						self::record_registration_rejection( (int) $event->id, 'duplicate_registration' );
						wp_safe_redirect( add_query_arg( 'ecm_error', 'duplicate_registration', $redirect ) );
						exit;
					}
				}
			}
		$secret    = wp_generate_password( 40, false, false );
		$public_id = ECM_Utils::public_id();
		$now       = ECM_Utils::now_utc();
		$inserted  = $wpdb->insert(
			ECM_DB::table( 'registrations' ),
			array(
				'public_id'        => $public_id,
				'event_id'         => (int) $event->id,
				'ticket_template_id'=> ECM_Tickets::current_template_id( $event ),
					'dedupe_phone_hash'=> $dedupe_hash,
				'form_data'        => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
					'amount_original'  => empty($settings['payment_required']) ? '0' : ECM_Utils::decimal( (string) $settings['amount'] ),
				'currency_label'   => sanitize_text_field( $settings['currency_label'] ),
					'exchange_rate'    => empty($settings['payment_required']) ? '1' : ECM_Utils::decimal( (string) $settings['rial_rate'] ),
					'amount_rial'      => empty($settings['payment_required']) ? '0' : ECM_Utils::rial_amount( (string) $settings['amount'], (string) $settings['rial_rate'] ),
					'payment_status'   => empty($settings['payment_required']) ? 'not_required' : 'pending',
				'access_hash'      => wp_hash_password( $secret ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
				array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
				$error_code = $dedupe_hash && false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ? 'duplicate_registration' : 'database';
				self::record_registration_rejection( (int) $event->id, $error_code, array( 'database_error' => (bool) $wpdb->last_error ) );
				wp_safe_redirect( add_query_arg( 'ecm_error', $error_code, $redirect ) );
			exit;
		}
			$registration_id = (int) $wpdb->insert_id;
				if ( ! ECM_Events::ensure_registration_entities( $registration_id, $data ) ) {
					self::record_registration_rejection( (int) $event->id, 'identity_database', array( 'registration_id' => $registration_id ) );
				$wpdb->delete( ECM_DB::table( 'registrations' ), array( 'id' => $registration_id ), array( '%d' ) );
				wp_safe_redirect( add_query_arg( 'ecm_error', 'database', $redirect ) );
				exit;
			}
				$guest_result = ECM_Participants::create_guests( $registration_id, $guests );
				if ( is_wp_error( $guest_result ) ) {
					ECM_Diagnostics::record( 'registration', 'Guest creation failed', array( 'registration_id' => $registration_id, 'error_code' => $guest_result->get_error_code() ) );
					ECM_Participants::cleanup_registration( $registration_id );
					$error_code = 'duplicate_guest' === $guest_result->get_error_code() ? 'duplicate_guest' : 'guest';
					self::record_registration_rejection( (int) $event->id, sanitize_key( $guest_result->get_error_code() ), array( 'registration_id' => $registration_id ) );
					wp_safe_redirect( add_query_arg( 'ecm_error', $error_code, $redirect ) );
					exit;
				}
			$this->index_fields( $registration_id, $data, (array) $settings['fields'] );
				ECM_Utils::log( $registration_id, 'registration_created', array( 'payment_required' => ! empty( $settings['payment_required'] ), 'guest_count' => count( $guest_result ) ) );
				foreach ( $guest_result as $guest_participant ) {
					do_action( 'ecm_guest_registered', $registration_id, $guest_participant );
				}
			ECM_Diagnostics::record( 'registration', 'Registration stored', array( 'registration_id' => $registration_id, 'payment_required' => ! empty( $settings['payment_required'] ) ) );

			if ( empty( $settings['payment_required'] ) ) {
				ECM_Utils::set_card_grant( $registration_id, $secret );
				$registration = ECM_DB::registration( $registration_id );
				ECM_Utils::log( $registration_id, 'registration_completed', array( 'payment_status' => 'not_required' ) );
				do_action( 'ecm_registration_completed', $registration_id, $registration );
				wp_safe_redirect( ECM_Utils::page_url( 'card', array( 'id' => $registration_id ) ) );
				exit;
			}

		$registration = ECM_DB::registration( $registration_id );
		$result       = ECM_Payment::process( $registration );
		if ( ! empty( $result['redirect_url'] ) && empty( $result['success'] ) ) {
			wp_redirect( esc_url_raw( $result['redirect_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
		if ( empty( $result['success'] ) ) {
			ECM_Utils::log( $registration_id, 'payment_failed', array( 'message' => sanitize_text_field( $result['message'] ?? '' ) ) );
				$wpdb->update( ECM_DB::table( 'registrations' ), array( 'dedupe_phone_hash' => null ), array( 'id' => $registration_id ), array( '%s' ), array( '%d' ) );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'payment', $redirect ) );
			exit;
		}

		$payment_status = sanitize_key( (string) ( $result['status'] ?? '' ) );
		if ( ! in_array( $payment_status, array( 'paid', 'offline_confirmed' ), true ) ) {
			ECM_Utils::log( $registration_id, 'payment_failed', array( 'message' => 'invalid_payment_status' ) );
				$wpdb->update( ECM_DB::table( 'registrations' ), array( 'dedupe_phone_hash' => null ), array( 'id' => $registration_id ), array( '%s' ), array( '%d' ) );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'payment', $redirect ) );
			exit;
		}
		$completed_at = 'paid' === $payment_status ? ECM_Utils::now_utc() : null;
		$wpdb->update(
			ECM_DB::table( 'registrations' ),
			array( 'payment_status' => $payment_status, 'payment_reference' => sanitize_text_field( $result['reference'] ), 'paid_at' => $completed_at, 'updated_at' => ECM_Utils::now_utc() ),
			array( 'id' => $registration_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		ECM_Utils::set_card_grant( $registration_id, $secret );
		$registration = ECM_DB::registration( $registration_id );
			ECM_Utils::log( $registration_id, 'paid' === $payment_status ? 'payment_success' : 'registration_completed', array( 'reference' => $result['reference'], 'payment_status' => $payment_status ) );
			if ( 'paid' === $payment_status ) {
				do_action( 'ecm_registration_paid', $registration_id, $registration );
			}
			do_action( 'ecm_registration_completed', $registration_id, $registration );
		wp_safe_redirect( ECM_Utils::page_url( 'card', array( 'id' => $registration_id ) ) );
		exit;
	}

	private function sanitize_field_value( $value, string $type, array $options ) {
		if ( is_array( $value ) ) {
			$values = array_map( static fn( $item ) => self::limit_text( sanitize_text_field( $item ), 500 ), $value );
			return array_values( array_intersect( $values, array_map( 'strval', $options ) ) );
		}
		$value = (string) $value;
		if ( in_array( $type, array( 'select', 'radio' ), true ) && ! in_array( $value, array_map( 'strval', $options ), true ) ) {
			return '';
		}
		if ( 'textarea' === $type ) {
			return self::limit_text( sanitize_textarea_field( $value ), 5000 );
		}
		if ( 'email' === $type ) {
			return sanitize_email( $value );
		}
		if ( 'tel' === $type ) {
			return '' === trim( $value ) ? '' : ECM_Utils::normalize_mobile( $value );
		}
		if ( 'url' === $type ) {
			return esc_url_raw( $value );
		}
		if ( 'number' === $type ) {
			$value = str_replace( array( ',', '٬', ' ' ), '', ECM_Utils::normalize_digits( $value ) );
			return preg_match( '/^-?\d+(?:\.\d+)?$/', $value ) ? $value : '';
		}
		if ( 'date' === $type ) {
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', ECM_Utils::normalize_digits( $value ), $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				return '';
			}
			return ECM_Utils::normalize_digits( $value );
		}
		if ( 'time' === $type ) {
			$value = ECM_Utils::normalize_digits( $value );
			return preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
		}
		return self::limit_text( sanitize_text_field( $value ), 500 );
	}

	private static function limit_text( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	private static function record_registration_rejection( int $event_id, string $code, array $context = array() ): void {
		ECM_Activity::record(
			'operation',
			array_merge(
				array(
					'operation'  => 'registration_rejected',
					'event_id'   => $event_id,
					'is_error'   => true,
					'error_code' => sanitize_key( $code ),
					'route'      => 'form_ecm_register',
				),
				array_intersect_key( $context, array_flip( array( 'guest_count', 'field_count', 'registration_id', 'database_error' ) ) )
			)
		);
	}

	private static function record_recovery_rejection( int $event_id, string $code ): void {
		ECM_Activity::record(
			'operation',
			array(
				'operation'  => 'recovery_rejected',
				'event_id'   => $event_id,
				'is_error'   => true,
				'error_code' => sanitize_key( $code ),
				'route'      => 'form_ecm_recover',
			)
		);
	}

	private static function rate_limit( string $scope, int $limit, int $window ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$client = isset( $_COOKIE['ecm_client'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['ecm_client'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $client ) ) {
			$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 190 ) : '';
			$client = hash( 'sha256', $agent );
		}
		$key = 'ecm_rl_' . md5( $scope . '|' . hash_hmac( 'sha256', $ip . '|' . $client, wp_salt( 'nonce' ) ) );
		$hit = (int) get_transient( $key );
		if ( $hit >= $limit ) {
			return false;
		}
		set_transient( $key, $hit + 1, $window );
		return true;
	}

	private function index_fields( int $registration_id, array $data, array $fields ): void {
		global $wpdb;
		$types = array();
		foreach ( $fields as $field ) {
			$types[ sanitize_key( $field['key'] ?? '' ) ] = sanitize_key( $field['type'] ?? 'text' );
		}
		foreach ( $data as $key => $value ) {
			$wpdb->insert(
				ECM_DB::table( 'registration_meta' ),
				array(
					'registration_id' => $registration_id,
					'field_key'       => sanitize_key( $key ),
					'field_hash'      => self::recovery_hash( ECM_Utils::field_value( $data, $key ), $types[ $key ] ?? 'text' ),
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	private static function recovery_hash( string $value, string $type = 'text' ): string {
		$value = trim( ECM_Utils::normalize_digits( $value ) );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		if ( 'tel' === $type ) {
			$value = preg_replace( '/[^0-9+]/', '', $value );
		}
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	public function recovery_shortcode(): string {
		$this->assets();
		$event_slug = isset( $_GET['event'] ) ? sanitize_title( wp_unslash( $_GET['event'] ) ) : '';
		$event      = $event_slug ? ECM_Events::event_by_slug( $event_slug ) : null;
		if ( $event_slug && ! $event ) {
			return $this->attendance_state( 'error', 'رویداد درخواستی پیدا نشد.', 'لینک نامعتبر' );
		}
		if ( $event && ! ECM_Events::is_publicly_active( $event ) ) {
			return $this->attendance_state( 'warning', (string) $event->disabled_message, (string) $event->disabled_title );
		}
		if ( ! empty( $_GET['ticket'] ) ) {
			global $wpdb;
			$table  = ECM_DB::table( 'links' );
			$ticket = sanitize_text_field( wp_unslash( $_GET['ticket'] ) );
			$valid  = preg_match( '/^[a-f0-9]{64}$/', $ticket ) && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE token_hash=%s AND used_at IS NULL AND expires_at>%s", hash( 'sha256', $ticket ), ECM_Utils::now_utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $valid ) {
				return $this->shell( '<div class="ecm-content"><h1>لینک قابل استفاده نیست</h1><p>این لینک مصرف شده، منقضی یا نامعتبر است.</p><a class="ecm-button ecm-button--primary" href="' . esc_url( ECM_Utils::page_url( 'recovery' ) ) . '">بازیابی با شماره موبایل</a></div>' );
			}
			ob_start();
			echo '<div class="ecm-content"><h1>دریافت کارت</h1><p>برای بازکردن کارت، دکمه زیر را بزنید.</p><form method="post">';
			wp_nonce_field( 'ecm_redeem', 'ecm_nonce' );
			echo '<button class="ecm-button ecm-button--primary">ورود به کارت</button></form></div>';
			return $this->shell( (string) ob_get_clean() );
		}
		$resolved_event = $event ?: ECM_Events::event( ECM_Events::default_event_id() );
		$settings = ECM_Events::config( $resolved_event );
		$group_cards = 'group_all' === ( $settings['card_access_mode'] ?? 'own_only' );
		ob_start();
		?>
		<div class="ecm-hero"><div><span class="ecm-kicker">RESTORE PASS</span><h1><?php echo esc_html( $settings['recovery_title'] ); ?></h1></div><a class="ecm-back" href="<?php echo esc_url( $event ? ECM_Events::registration_url( $event ) : ECM_Utils::page_url( 'registration' ) ); ?>">بازگشت</a></div>
		<div class="ecm-content"><?php echo wp_kses_post( $settings['recovery_intro'] ); ?><?php echo $this->notice_from_query(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php if ( ! empty( $settings['recovery_otp_enabled'] ) ) : ?>
		<form class="ecm-form ecm-otp-recovery" data-ecm-otp-recovery data-event-id="<?php echo esc_attr( (int) $resolved_event->id ); ?>" novalidate>
			<div class="ecm-otp-progress" aria-label="مراحل بازیابی"><span class="is-active" data-otp-progress="request">۱</span><i></i><span data-otp-progress="verify">۲</span></div>
			<section data-otp-step="request">
				<h2><?php echo esc_html( $settings['recovery_otp_title'] ); ?></h2><p><?php echo esc_html( $settings['recovery_otp_intro'] ); ?></p>
				<div class="ecm-field"><label for="ecm-recovery-identifier">شماره موبایل یا کد پیگیری<em>*</em></label><input id="ecm-recovery-identifier" type="text" inputmode="text" autocomplete="off" name="identifier" placeholder="09xxxxxxxxx یا کد پیگیری" required></div>
				<button class="ecm-button ecm-button--primary ecm-button--wide" type="submit" data-otp-request><?php echo esc_html( $settings['recovery_otp_send_button'] ); ?></button>
			</section>
			<section data-otp-step="verify" hidden>
				<div class="ecm-otp-head"><div><h2><?php echo esc_html( $settings['recovery_otp_verify_title'] ); ?></h2><p><?php echo esc_html( $settings['recovery_otp_verify_intro'] ); ?></p></div><button type="button" class="ecm-button ecm-button--quiet" data-otp-change>تغییر اطلاعات</button></div>
				<div class="ecm-otp-code" dir="ltr"><input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9۰-۹]{6}" name="otp_code" aria-label="کد تأیید شش رقمی" required></div>
				<div class="ecm-field" data-otp-tracking hidden><label for="ecm-otp-tracking">کد پیگیری کارت<em>*</em></label><input id="ecm-otp-tracking" type="text" autocomplete="off" autocapitalize="characters" name="tracking_code" maxlength="32"><small><?php echo esc_html( $settings['recovery_otp_shared_message'] ); ?></small></div>
				<div class="ecm-otp-meta"><span data-otp-expiry>05:00</span><button type="button" class="ecm-link-button" data-otp-resend disabled><?php echo esc_html( $settings['recovery_otp_resend_button'] ); ?></button></div>
				<button class="ecm-button ecm-button--primary ecm-button--wide" type="submit" data-otp-verify><?php echo esc_html( $settings['recovery_otp_verify_button'] ); ?></button>
			</section>
			<div class="ecm-otp-feedback" data-otp-feedback role="status" aria-live="polite" hidden></div>
		</form>
		<?php else : ?>
		<form class="ecm-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ecm_recover"><?php wp_nonce_field( 'ecm_recover', 'ecm_nonce' ); ?>
			<?php if ( $event ) : ?><input type="hidden" name="ecm_event_id" value="<?php echo esc_attr( $event->id ); ?>"><?php endif; ?>
			<div class="ecm-field-grid"><div class="ecm-field"><label for="ecm-recovery-phone">شماره موبایل ثبت‌شده<em>*</em></label><input id="ecm-recovery-phone" type="tel" inputmode="tel" autocomplete="tel" name="ecm_recovery_phone" placeholder="09xxxxxxxxx" required></div><div class="ecm-field"><label for="ecm-recovery-code">کد پیگیری کارت</label><input id="ecm-recovery-code" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" name="ecm_recovery_code" maxlength="32" placeholder="مثلاً ABC123XYZ"></div></div>
			<p class="ecm-recovery-help"><?php echo esc_html( $group_cards ? 'شماره معرف، کارت خودش و مهمان‌ها را باز می‌کند؛ شماره مهمان فقط کارت همان مهمان را نمایش می‌دهد.' : 'معرف و مهمان هرکدام فقط کارت شخصی خود را مشاهده می‌کنند.' ); ?></p>
			<button class="ecm-button ecm-button--primary ecm-button--wide" type="submit"><?php echo esc_html( $settings['recovery_button'] ); ?></button>
		</form>
		<?php endif; ?>
		</div>
		<?php
		return $this->shell( (string) ob_get_clean(), 'ecm-recovery', $event ? (string) $event->title : '' );
	}

	public function handle_recovery(): void {
		$event_id = absint( $_POST['ecm_event_id'] ?? 0 );
		$event    = $event_id ? ECM_Events::event( $event_id ) : null;
		$redirect = ECM_Utils::page_url( 'recovery', $event ? array( 'event' => $event->slug ) : array() );
		if ( $event_id && ! $event ) {
			self::record_recovery_rejection( $event_id, 'event_unavailable' );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'event', $redirect ) );
			exit;
		}
		if ( ! isset( $_POST['ecm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ecm_nonce'] ) ), 'ecm_recover' ) ) {
			self::record_recovery_rejection( $event_id, 'security' );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'security', $redirect ) );
			exit;
		}
		if ( ! self::rate_limit( 'recover', (int) apply_filters( 'ecm_recovery_rate_limit', 30 ), 15 * MINUTE_IN_SECONDS ) ) {
			self::record_recovery_rejection( $event_id, 'rate_limited' );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'rate', $redirect ) );
			exit;
		}
		if ( $event && ! ECM_Events::is_publicly_active( $event ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}
		$recovery_event = $event ?: ECM_Events::event( ECM_Events::default_event_id() );
		if ( $recovery_event && ! empty( ECM_Events::config( $recovery_event )['recovery_otp_enabled'] ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}
		$phone         = sanitize_text_field( wp_unslash( $_POST['ecm_recovery_phone'] ?? '' ) );
		$tracking_code = sanitize_text_field( wp_unslash( $_POST['ecm_recovery_code'] ?? '' ) );
		$participant   = ECM_Participants::recover_by_phone( $phone, $event ? (int) $event->id : 0, $tracking_code );
		if ( is_wp_error( $participant ) ) {
			self::record_recovery_rejection( $event ? (int) $event->id : 0, sanitize_key( $participant->get_error_code() ) );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'ambiguous', $redirect ) );
			exit;
		}
		if ( ! $participant || ! ECM_Participants::grant_recovered_card( $participant ) ) {
			self::record_recovery_rejection( $event ? (int) $event->id : 0, $participant ? 'grant_failed' : 'not_found' );
			wp_safe_redirect( add_query_arg( 'ecm_error', 'not_found', $redirect ) );
			exit;
		}
		$is_guest = 'guest' === (string) $participant->participant_type;
		ECM_Utils::log( (int) $participant->registration_id, 'card_recovered', array( 'participant_id' => (int) $participant->id, 'participant_type' => (string) $participant->participant_type ) );
		$args = array( 'id' => (int) $participant->registration_id );
		if ( $is_guest ) {
			$args['participant'] = (string) $participant->public_id;
			$args['scope']       = 'guest';
		}
		wp_safe_redirect( ECM_Utils::page_url( 'card', $args ) );
		exit;
	}

	public function card_shortcode(): string {
		$id           = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$registration = $id ? ECM_DB::registration( $id ) : null;
		$participant_public_id = isset( $_GET['participant'] ) ? sanitize_text_field( wp_unslash( $_GET['participant'] ) ) : '';
		$requested_participant = $participant_public_id ? ECM_DB::participant_by_public_id( $participant_public_id ) : null;
		if ( $requested_participant ) {
			$requested_participant = ECM_DB::participant_identity( (int) $requested_participant->id );
		}
		$this->assets( $id );
		if ( ! ECM_Utils::registration_is_complete( $registration ) ) {
			return $this->shell( '<div class="ecm-state ecm-state--error"><h1>دسترسی به کارت ممکن نیست</h1><p>برای مشاهده کارت، ابتدا آن را بازیابی کنید.</p><a class="ecm-button ecm-button--primary" href="' . esc_url( ECM_Utils::page_url( 'recovery' ) ) . '">بازیابی کارت</a></div>' );
		}
		$event = ECM_Events::registration_event( $registration );
		if ( ! ECM_Events::is_publicly_active( $event ) ) {
			$title   = $event ? (string) $event->disabled_title : 'رویداد در دسترس نیست';
			$message = $event ? (string) $event->disabled_message : 'رویداد مرتبط با این کارت پیدا نشد.';
			return $this->attendance_state( 'warning', $message, $title );
		}
		$host_grant  = $registration ? ECM_Utils::has_card_grant( $registration ) : false;
		$guest_grant = $requested_participant && (int) $requested_participant->registration_id === $id && ECM_Utils::has_participant_card_grant( $requested_participant );
		$guest_scope = $requested_participant && 'guest' === (string) $requested_participant->participant_type;
		$allowed     = $guest_scope ? $guest_grant : $host_grant;
		if ( ! $allowed ) {
			return $this->shell( '<div class="ecm-state ecm-state--error"><h1>دسترسی به کارت ممکن نیست</h1><p>برای مشاهده کارت، ابتدا آن را بازیابی کنید.</p><a class="ecm-button ecm-button--primary" href="' . esc_url( ECM_Utils::page_url( 'recovery' ) ) . '">بازیابی کارت</a></div>' );
		}
		$settings = ECM_Events::config( $event );
		$primary_record = ECM_DB::primary_participant( $id ); // Lazy compatibility migration for a legacy card opened before the background batch finishes.
		$primary        = $primary_record ? ECM_DB::participant_identity( (int) $primary_record->id ) : null;
		$group_cards = 'group_all' === ( $settings['card_access_mode'] ?? 'own_only' );
		if ( $guest_scope ) {
			$participants = array( $requested_participant );
		} elseif ( $group_cards ) {
			$participants = ECM_DB::participants_for_registration( $id );
		} else {
			$participants = array_filter( array( $primary ) );
		}
		$mode     = in_array( $settings['card_print_mode'], array( 'page', 'custom', 'qr' ), true ) ? $settings['card_print_mode'] : 'page';
		$intro_pdf_url = ! empty( $settings['card_intro_pdf_url'] ) ? ECM_Events::card_intro_pdf_url( (string) $settings['card_intro_pdf_url'] ) : '';
		ob_start();
		?>
		<div class="ecm-card-page" data-print-mode="<?php echo esc_attr( $mode ); ?>">
			<div class="ecm-hero ecm-no-print"><div><span class="ecm-kicker">YOUR PASS</span><h1><?php echo esc_html( $settings['card_ready_title'] ); ?></h1></div><a class="ecm-back" href="<?php echo esc_url( $event ? ECM_Events::registration_url( $event ) : ECM_Utils::page_url( 'registration' ) ); ?>"><?php echo esc_html( $settings['home_link_text'] ); ?></a></div>
			<div class="ecm-content">
				<?php if ( $intro_pdf_url ) : ?>
				<section class="ecm-card-intro-pdf ecm-no-print" aria-labelledby="ecm-card-intro-pdf-title">
					<div class="ecm-card-intro-pdf__head"><div><span>فایل راهنمای رویداد</span><h2 id="ecm-card-intro-pdf-title">اطلاعات و ساختار رویداد</h2></div><a class="ecm-button ecm-button--ghost" href="<?php echo esc_url( $intro_pdf_url ); ?>" download target="_blank" rel="noopener noreferrer">دانلود PDF</a></div>
					<iframe class="ecm-card-intro-pdf__frame" src="<?php echo esc_url( $intro_pdf_url ); ?>" title="فایل PDF راهنمای رویداد" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
					<p class="ecm-card-intro-pdf__fallback">اگر فایل در مرورگر شما نمایش داده نشد، از دکمه «دانلود PDF» استفاده کنید.</p>
				</section>
				<?php endif; ?>
				<div class="ecm-success-copy ecm-page-copy"><?php echo wp_kses_post( $settings['success_text'] ); ?><?php if ( $group_cards && $host_grant && count( $participants ) > 1 ) : ?><p>برای ثبت حضور، هر شخص باید QR کارت خودش را ارائه کند.</p><?php endif; ?></div>
				<?php if ( $group_cards && ! $guest_scope && count( $participants ) > 1 ) : ?><div class="ecm-card-bulk-actions ecm-no-print"><a class="ecm-button ecm-button--primary" target="_blank" rel="noopener" href="<?php echo esc_url( ECM_Card_Document::url( $id ) ); ?>">چاپ همه کارت‌ها</a><small>هر کارت در یک صفحه مستقل چاپ می‌شود.</small></div><?php endif; ?>
				<div class="ecm-card-collection">
				<?php foreach ( $participants as $participant ) : if ( ! $participant ) { continue; } $is_primary = 'primary' === (string) $participant->participant_type; $qr_token = $is_primary ? ECM_Utils::qr_token( $registration ) : ECM_Utils::participant_qr_token( $participant ); $qr_url = ECM_Tickets::attendance_url( $participant ) ?: ECM_Utils::page_url( 'attendance', array( 'token' => rawurldecode( $qr_token ) ) ); ?>
				<div class="ecm-card-item" data-participant-id="<?php echo esc_attr( $participant->id ); ?>">
					<article class="ecm-pass" data-ecm-card>
						<div class="ecm-pass__top"><span><?php echo esc_html( $event ? $event->title : ECM_Utils::settings()['event_name'] ); ?></span><small><?php echo esc_html( $is_primary ? 'معرف / ثبت‌نام‌کننده' : 'مهمان ' . (int) $participant->guest_order ); ?></small></div>
						<div class="ecm-pass__qr" data-ecm-qr="<?php echo esc_attr( $qr_url ); ?>" aria-label="QR Code"></div>
						<?php if ( ! empty( $participant->full_name ) ) : ?><h2><?php echo esc_html( $participant->full_name ); ?></h2><?php endif; ?>
						<code><?php echo esc_html( $participant->public_id ); ?></code>
						<p class="ecm-card-instruction"><?php echo esc_html( $settings['card_instruction'] ); ?></p>
						<div class="ecm-pass__custom"><?php echo wp_kses_post( $settings['card_print_html'] ); ?></div>
					</article>
					<div class="ecm-card-actions ecm-no-print"><a class="ecm-button ecm-button--primary" target="_blank" rel="noopener" href="<?php echo esc_url( ECM_Card_Document::url( $id, (int) $participant->id ) ); ?>"><?php echo esc_html( $settings['print_button'] ); ?></a></div>
				</div>
				<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
		return $this->shell( (string) ob_get_clean(), 'ecm-card-shell', $event ? (string) $event->title : '' );
	}

	public function attendance_shortcode(): string {
		$this->assets();
		$settings     = ECM_Utils::settings();
		$token        = isset( $_GET['token'] ) ? ECM_Utils::normalize_qr_token_input( (string) wp_unslash( $_GET['token'] ) ) : '';
		$resolved     = $token ? ECM_Utils::resolve_qr_token( $token ) : array( 'registration' => null, 'participant' => null );
		$registration = $resolved['registration'];
		$participant  = $resolved['participant'];
			if ( ! ECM_Utils::registration_is_complete( $registration ) ) {
			return $this->attendance_state( 'error', $settings['invalid_qr_message'], $settings['invalid_qr_title'] );
		}
			if ( ! $participant || (int) $participant->registration_id !== (int) $registration->id || 'active' !== (string) $participant->status ) {
				return $this->attendance_state( 'error', $settings['invalid_qr_message'], $settings['invalid_qr_title'] );
			}
			$event = ECM_Events::registration_event( $registration );
			if ( ! $event ) {
				return $this->attendance_state( 'error', 'رویداد مرتبط با این کارت پیدا نشد.', 'خطای اطلاعات کارت' );
			}
			$settings = ECM_Events::config( $event );
			if ( ! ECM_Events::is_publicly_active( $event ) ) {
				return $this->attendance_state( 'warning', (string) $event->disabled_message, (string) $event->disabled_title );
			}
			$participant = ECM_DB::participant_identity( (int) $participant->id );
			$window  = ECM_DB::attendance_window( (int) $event->id );
			$session = $window['session'];
			$attendance_mode = ECM_Events::effective_attendance_mode( $event, $session );
			$operator_allowed = ECM_Events::can_record_attendance( (int) $event->id );
			if ( 'operator_only' === $attendance_mode && ! $operator_allowed ) {
				ECM_Diagnostics::record( 'attendance', 'Operator-only attendance rejected', array( 'registration_id' => (int) $registration->id, 'event_id' => (int) $event->id, 'logged_in' => is_user_logged_in() ) );
				if ( ! is_user_logged_in() ) {
					$url = ECM_Utils::page_url( 'attendance', array( 'token' => $token ) );
					$message = '<p>' . esc_html( (string) $event->login_message ) . '</p>';
					if ( ! empty( $event->show_operator_login ) ) {
						$message .= '<p><a class="ecm-button ecm-button--primary" href="' . esc_url( wp_login_url( $url ) ) . '">ورود اپراتور</a></p>';
					}
					return $this->attendance_state( 'warning', $message, (string) $event->unauthorized_title );
				}
				return $this->attendance_state( 'error', (string) $event->unauthorized_message, (string) $event->unauthorized_title );
			}
			if ( 'active' !== $window['state'] || ! $session ) {
				$state = in_array( $window['state'], array( 'early', 'late' ), true ) ? $window['state'] : 'outside';
					$meta  = $session ? array( 'session_id' => (int) $session->id, 'session_time' => ECM_Utils::utc_to_local( $session->start_at_utc ), 'participant_id' => (int) $participant->id ) : array( 'participant_id' => (int) $participant->id );
					if ( self::rate_limit( $state . '-' . $participant->id, 1, HOUR_IN_SECONDS ) ) {
					ECM_Utils::log( (int) $registration->id, 'attendance_' . $state, $meta );
				}
				ECM_Diagnostics::record( 'attendance', 'Attendance rejected by time window', array( 'registration_id' => (int) $registration->id, 'state' => $state, 'session_id' => $session ? (int) $session->id : 0 ) );
				return $this->attendance_state( 'warning', (string) $settings[ $state . '_message' ], (string) $settings[ $state . '_title' ] );
			}
	        if(ECM_DB::participant_attendance_count((int)$participant->id,(int)$session->id)>=(int)$session->max_entries && 'POST'!==($_SERVER['REQUEST_METHOD']??'')) {
	            if(self::rate_limit('repeat-'.$participant->id,1,HOUR_IN_SECONDS)) ECM_Utils::log((int)$registration->id,'attendance_repeat',array('session_id'=>(int)$session->id,'participant_id'=>(int)$participant->id));
            return $this->attendance_state('warning',$session->repeat_message,$session->title);
        }
        // GET is read-only: previews and crawlers must never mark attendance.
        if('POST'!==($_SERVER['REQUEST_METHOD']??'')) {
            $request=bin2hex(random_bytes(16));
	            $name=(string)($participant->full_name??'');
            $start=ECM_Utils::utc_to_local((string)$session->start_at_utc,'Y/m/d H:i');
            $end=ECM_Utils::utc_to_local((string)$session->end_at_utc,'Y/m/d H:i');
			ob_start(); echo '<div class="ecm-state"><h1>'.esc_html($session->title).'</h1><p class="ecm-attendee-name">'.esc_html($name).'</p><span class="ecm-participant-badge">'.esc_html('guest'===$participant->participant_type?'مهمان':'معرف / ثبت‌نام‌کننده').'</span>';
			if($operator_allowed && in_array($attendance_mode,array('operator_only','hybrid'),true)){$operator=ECM_Events::recorder_snapshot((int)$event->id);echo '<p class="ecm-operator-badge">ثبت‌کننده: '.esc_html($operator['name']).' · '.esc_html($operator['code']).'</p>';}
			echo '<div class="ecm-session-time" aria-label="زمان نوبت"><span><b>شروع</b><time datetime="'.esc_attr((string)$session->start_at_utc).'">'.esc_html($start).'</time></span><span><b>پایان</b><time datetime="'.esc_attr((string)$session->end_at_utc).'">'.esc_html($end).'</time></span></div><form method="post" class="ecm-attendance-submit">';
            echo '<input type="hidden" name="ecm_request" value="'.esc_attr($request).'">';
            wp_nonce_field('ecm_attend_'.$registration->id.'_'.$session->id.'_'.$request,'ecm_nonce');echo '<button class="ecm-button ecm-button--primary" type="submit">'.esc_html($settings['attendance_button']).'</button></form><p>'.esc_html($settings['attendance_instruction']).'</p></div>';
            return $this->shell((string)ob_get_clean(),'ecm-attendance');
        }
        $request=sanitize_text_field(wp_unslash($_POST['ecm_request']??''));
        if(!preg_match('/^[a-f0-9]{32}$/',$request)||empty($_POST['ecm_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ecm_nonce'])),'ecm_attend_'.$registration->id.'_'.$session->id.'_'.$request)) return $this->attendance_state('error','اعتبار صفحه تمام شده است؛ دوباره QR را باز کنید.','درخواست نامعتبر');
	        global $wpdb;$request_hash=hash('sha256',$participant->id.'|'.$session->id.'|'.$request);$at=ECM_DB::table('attendance');
        if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$at} WHERE request_hash=%s",$request_hash))) return $this->attendance_state('success',$session->success_message,$session->title);
		$count = ECM_DB::participant_attendance_count( (int) $participant->id, (int) $session->id );
		if ( $count >= (int) $session->max_entries ) {
				ECM_Utils::log( (int) $registration->id, 'attendance_repeat', array( 'session_id' => (int) $session->id, 'participant_id' => (int) $participant->id ) );
			return $this->attendance_state( 'warning', $session->repeat_message, $session->title );
		}
		global $wpdb;
		$recorder = ( $operator_allowed && in_array( $attendance_mode, array( 'operator_only', 'hybrid' ), true ) ) ? ECM_Events::recorder_snapshot( (int) $event->id ) : array( 'user_id' => 0, 'name' => '', 'code' => '' );
		$method   = $recorder['user_id'] ? 'operator_link' : 'self_service';
		$device   = ECM_Utils::request_device();
		$inserted = $wpdb->insert(
			ECM_DB::table( 'attendance' ),
				array( 'registration_id' => (int) $registration->id, 'event_id' => (int) $event->id, 'participant_id' => (int) $participant->id, 'session_id' => (int) $session->id, 'entry_no' => $count + 1, 'status' => 'success', 'created_at' => ECM_Utils::now_utc(), 'request_hash'=>$request_hash, 'recorded_by_user_id'=>(int)$recorder['user_id'], 'recorder_name'=>(string)$recorder['name'], 'recorder_code'=>(string)$recorder['code'], 'method'=>$method, 'device_type'=>$device['type'], 'device_label'=>$device['label'], 'user_agent'=>$device['user_agent'] ),
				array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
	            if(ECM_DB::participant_attendance_count((int)$participant->id,(int)$session->id)>=$count+1) return $this->attendance_state('warning',$session->repeat_message,$session->title);
            ECM_Utils::log((int)$registration->id,'attendance_database_error',array('session_id'=>(int)$session->id));
            return $this->attendance_state('error','ثبت حضور انجام نشد. دوباره تلاش کنید.','خطای ذخیره‌سازی');
		}
        $attendance_id=(int)$wpdb->insert_id;
			ECM_Utils::log( (int) $registration->id, 'attendance_success', array( 'session_id' => (int) $session->id, 'attendance_id'=>$attendance_id, 'event_id'=>(int)$event->id, 'participant_id'=>(int)$participant->id, 'participant_type'=>(string)$participant->participant_type, 'recorded_by_user_id'=>(int)$recorder['user_id'], 'method'=>$method, 'device'=>$device['label'] ) );
			ECM_Diagnostics::record( 'attendance', 'Attendance stored', array( 'registration_id' => (int) $registration->id, 'session_id' => (int) $session->id, 'attendance_id' => $attendance_id, 'event_id'=>(int)$event->id, 'recorded_by_user_id'=>(int)$recorder['user_id'], 'method'=>$method ) );
		return $this->attendance_state( 'success', $session->success_message, $session->title );
	}

	private function attendance_state( string $type, string $message, string $title ): string {
		$icon = 'success' === $type ? '✓' : ( 'warning' === $type ? '!' : '×' );
		return $this->shell( '<div class="ecm-state ecm-state--' . esc_attr( $type ) . '"><span class="ecm-state__icon">' . esc_html( $icon ) . '</span><h1>' . esc_html( $title ) . '</h1><div>' . wp_kses_post( wpautop( $message ) ) . '</div></div>', 'ecm-attendance' );
	}

	public function manager_shortcode(): string {
		$this->assets();
		if ( ! is_user_logged_in() ) {
			return $this->shell( '<div class="ecm-state"><h1>ورود مدیر</h1><p>برای جلوگیری از انتقال به پنل‌های کاربری دیگر، از مسیر اختصاصی افزونه وارد شوید.</p><a class="ecm-button ecm-button--primary" href="' . esc_url( ECM_Portal::url() ) . '">ورود به پنل اختصاصی</a></div>' );
		}
		if ( current_user_can( 'record_ecm_attendance' ) && isset( $_GET['ecm_mode'] ) && 'checkin' === sanitize_key( wp_unslash( $_GET['ecm_mode'] ) ) ) {
			return $this->shell( ECM_Operator::portal_markup(), 'ecm-operator-shell' );
		}
		if ( current_user_can( 'view_ecm_reports' ) ) {
			return $this->shell( ECM_Reports::render( false ) );
		}
		if ( current_user_can( 'record_ecm_attendance' ) ) {
			return $this->shell( ECM_Events::operator_portal_markup(), 'ecm-operator-shell' );
		}
		return $this->shell( '<div class="ecm-state ecm-state--error"><h1>دسترسی ندارید</h1><p>برای حساب شما دسترسی گزارش یا ثبت حضور تعریف نشده است.</p></div>' );
	}

	private function notice_from_query(): string {
		if ( empty( $_GET['ecm_error'] ) ) {
			return '';
		}
		$code         = sanitize_key( wp_unslash( $_GET['ecm_error'] ) );
		$event_slug   = isset( $_GET['event'] ) ? sanitize_title( wp_unslash( $_GET['event'] ) ) : '';
		$notice_event = $event_slug ? ECM_Events::event_by_slug( $event_slug ) : ECM_Events::event( ECM_Events::default_event_id() );
		$settings     = ECM_Events::config( $notice_event );
		$messages = array(
			'security'      => 'درخواست معتبر نبود. صفحه را تازه‌سازی و دوباره تلاش کنید.',
			'required'      => 'لطفاً همه فیلدهای ضروری را درست تکمیل کنید.',
			'invalid_mobile'=> 'شماره موبایل معتبر نیست. شماره را به‌صورت ۱۱ رقم و با 09 وارد کنید.',
			'database'      => 'ثبت اطلاعات انجام نشد. دوباره تلاش کنید.',
			'payment'       => 'پرداخت تکمیل نشد.',
			'not_found'     => $settings['recovery_not_found'],
			'ambiguous'     => 'این شماره برای چند کارت ثبت شده است. شماره موبایل را همراه با کد پیگیری همان کارت وارد کنید.',
			'configuration' => 'فیلدهای بازیابی هنوز تنظیم نشده‌اند.',
			'event'         => 'رویداد انتخاب‌شده منتشر نشده یا در دسترس نیست.',
				'duplicate_guest'=> $settings['duplicate_guest_phone_message'] ?? 'این شماره موبایل قبلاً برای یکی از مهمانان همین رویداد ثبت شده است.',
					'duplicate_registration' => $settings['duplicate_phone_message'] ?? 'این شماره موبایل قبلاً برای این رویداد ثبت شده است.',
				'guest_limit'    => 'تعداد مهمان‌ها از سقف تعیین‌شده برای این رویداد بیشتر است.',
				'guest'         => 'ذخیره اطلاعات مهمان‌ها انجام نشد؛ شماره‌ها را بررسی کنید و دوباره تلاش کنید.',
			'rate'          => 'تعداد درخواست‌ها بیش از حد مجاز است. چند دقیقه بعد دوباره تلاش کنید.',
		);
		return '<div class="ecm-notice ecm-notice--error">' . esc_html( $messages[ $code ] ?? 'خطایی رخ داد.' ) . '</div>';
	}
}
