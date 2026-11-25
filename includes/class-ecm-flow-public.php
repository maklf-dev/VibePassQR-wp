<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Flow_Public {
	private static array $parts = array();

	public static function hooks(): void {
		add_action( 'template_redirect', array( __CLASS__, 'prevent_cache' ) );
		foreach ( array( 'ecm_flow_post' => 'post', 'ecm_flow_receipt' => 'receipt' ) as $action => $method ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
			add_action( 'admin_post_nopriv_' . $action, array( __CLASS__, $method ) );
		}
		add_shortcode( 'ecm_flow', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'ecm_edit_registration', array( __CLASS__, 'edit_shortcode' ) );
		add_shortcode( 'ecm_flow_part', static fn( $atts ) => self::$parts[ sanitize_key( (string) ( $atts['key'] ?? '' ) ) ] ?? '' );
	}

	public static function prevent_cache(): void {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( has_shortcode( $post->post_content, 'ecm_flow' ) || has_shortcode( $post->post_content, 'ecm_edit_registration' ) ||
			( ( ECM_Flow::enabled( ECM_Events::requested_event() ) || ! empty( $_GET['ecm_edit'] ) ) && has_shortcode( $post->post_content, 'ecm_registration' ) ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	public static function receipt(): void {
		ECM_Flow_Receipts::serve();
	}

	public static function param( string $name, bool $long = false ): string {
		$raw = $_POST[ $name ] ?? '';
		return is_string( $raw ) ? ( $long ? sanitize_textarea_field( wp_unslash( $raw ) ) : sanitize_text_field( wp_unslash( $raw ) ) ) : '';
	}

	private static function cookie( string $key, string $value, int $ttl ): void {
		setcookie( $key, $value, array( 'expires' => time() + $ttl, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}

	private static function token( string $key ): string {
		$value = $_COOKIE[ $key ] ?? '';
		return is_string( $value ) && preg_match( '/^[a-f0-9]{48}$/D', $value ) ? $value : '';
	}

	private static function session( int $event_id ): array {
		$token = self::token( 'ecm_flow_session_' . $event_id );
		return $token ? (array) get_transient( 'ecm_flow_s_' . hash( 'sha256', $token ) ) : array();
	}

	public static function owns( int $event_id, int $id ): bool {
		$event = ECM_Events::event( $event_id );
		return ECM_Events::is_publicly_active( $event ) && $id > 0 && (int) ( self::session( $event_id )['id'] ?? 0 ) === $id;
	}

	private static function grant( int $event_id, int $id ): bool {
		$token = bin2hex( random_bytes( 24 ) );
		if ( ! set_transient( 'ecm_flow_s_' . hash( 'sha256', $token ), array( 'id' => $id ), HOUR_IN_SECONDS ) ) {
			return false;
		}
		self::cookie( 'ecm_flow_session_' . $event_id, $token, HOUR_IN_SECONDS );
		return true;
	}

	private static function nonce_action( int $event ): string {
		return 'ecm_flow_' . $event . '_' . self::token( 'ecm_flow_session_' . $event );
	}

	private static function otp_challenge( int $event_id ): ?object {
		global $wpdb;
		$key = self::token( 'ecm_flow_otp_' . $event_id );
		if ( ! $key ) { return null; }
		$table = ECM_DB::table( 'otp' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE challenge_hash=%s AND event_id=%d AND status='flow_active'", hash( 'sha256', $key ), $event_id ) ) ?: null;
	}

	public static function shortcode( $atts = array() ): string {
		$atts = (array) $atts;
		$event = ! empty( $atts['event'] ) ? ECM_Events::event_by_slug( sanitize_title( $atts['event'] ) ) : ECM_Events::requested_event();
		return ECM_Flow::enabled( $event ) ? self::render( $event ) : '';
	}

	public static function edit_shortcode( $atts = array() ): string {
		$atts = (array) $atts;
		$event = ! empty( $atts['event'] ) ? ECM_Events::event_by_slug( sanitize_title( $atts['event'] ) ) : ECM_Events::requested_event();
		if ( ! $event || empty( ECM_Flow::settings( (int) $event->id )['edit_enabled'] ) ) {
			return '';
		}
		return self::render( $event, true );
	}

	private static function form_open( object $event, string $command, int $revision = 0 ): string {
		$id = (int) $event->id;
		$return = get_permalink( get_queried_object_id() );
		return '<form class="ecm-form ecm-flow-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="ecm_flow_post"><input type="hidden" name="event_id" value="' . $id . '"><input type="hidden" name="revision" value="' . $revision . '"><input type="hidden" name="command" value="' . esc_attr( $command ) . '"><input type="hidden" name="return_to" value="' . esc_attr( $return ?: '' ) . '">'
			. wp_nonce_field( self::nonce_action( $id ), '_wpnonce', false, false );
	}

	public static function render( object $event, bool $edit_only = false ): string {
		if ( ! ECM_Events::is_publicly_active( $event ) ) {
			return '<p>رویداد در دسترس نیست.</p>';
		}
		if ( ! ECM_Flow::ready() ) {
			return '<p>آماده‌سازی ثبت‌نام انجام نشده است. با مدیر سایت تماس بگیرید.</p>';
		}
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		wp_enqueue_style( 'ecm-public' );
		wp_enqueue_style( 'ecm-design' );
		wp_enqueue_style( 'ecm-flow', ECM_URL . 'assets/css/flow.css', array(), ECM_VERSION );
		wp_enqueue_script( 'ecm-flow', ECM_URL . 'assets/js/flow.js', array(), ECM_VERSION, true );
		$id = (int) $event->id;
		$session = self::session( $id );
		$registration = ! empty( $session['id'] ) ? ECM_DB::registration( (int) $session['id'] ) : null;
		$row = $registration ? ECM_Flow::row( (int) $registration->id ) : null;
		$flash = self::token( 'ecm_flow_flash_' . $id );
		$notice = $flash ? get_transient( 'ecm_flow_f_' . hash( 'sha256', $flash ) ) : '';
		if ( $flash && $notice ) {
			delete_transient( 'ecm_flow_f_' . hash( 'sha256', $flash ) );
		}
		$settings = $row ? ECM_Flow::decode( $row->snapshot ) : ECM_Flow::settings( $id );
		$content = '';
		if ( ! $registration ) {
			if ( ! ECM_Events::allows( $event, 'registration' ) && ! ECM_Events::allows( $event, 'recovery' ) ) {
				return '<p>ثبت‌نام و بازیابی بسته است.</p>';
			}
			$content .= '<ol class="ecm-flow-intro"><li>برای شروع ثبت‌نام، شماره موبایل خود را وارد کنید.</li><li>اگر قبلاً ثبت‌نام را شروع کرده‌اید، با همان شماره ادامه دهید یا آخرین وضعیت پرونده را ببینید.</li><li>برای ویرایش اطلاعات ثبت‌نام، با همان شماره وارد پرونده شوید.</li></ol>';
			$otp = self::otp_challenge( $id );
			if ( $otp ) {
				$expires = strtotime( $otp->expires_at . ' UTC' );
				$resend = strtotime( $otp->resend_at . ' UTC' );
				$content .= '<div class="ecm-flow-otp" data-flow-expiry="' . (int) $expires . '" data-flow-resend="' . (int) $resend . '" data-flow-now="' . time() . '">'
					. '<p>اعتبار کد <bdi data-flow-otp-timer></bdi></p>'
					. self::form_open( $event, 'verify' ) . '<div class="ecm-field"><label for="ecm-flow-code-' . $id . '">کد تأیید شش‌رقمی</label><input id="ecm-flow-code-' . $id . '" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></div>'
					. ( $edit_only ? '<label>کد پیگیری کارت (برای شماره مشترک)<input name="tracking_code" maxlength="32"></label>' : '' )
					. '<button class="ecm-button ecm-button--primary" data-flow-verify>تأیید و ادامه</button></form>'
					. self::form_open( $event, 'resend' ) . '<button class="ecm-button ecm-button--ghost" data-flow-resend-button>درخواست مجدد کد</button></form></div>';
			}
			$form = self::form_open( $event, 'send' ) . '<div class="ecm-field"><label for="ecm-flow-phone-' . $id . '">شماره موبایل</label><input id="ecm-flow-phone-' . $id . '" name="phone" type="tel" inputmode="tel" autocomplete="tel" required></div>'
				. '<button class="ecm-button ecm-button--primary">دریافت کد تأیید</button></form>';
			$content .= $otp ? '<details><summary>تغییر شماره برای ورود</summary>' . $form . '</details>' : $form;
		} elseif ( $row ) {
			$content .= self::workflow_markup( $event, $registration, $row, $settings );
		} elseif ( ! empty( $settings['edit_enabled'] ) ) {
			$content .= self::edit_markup( $event, $registration );
		} else {
			$content .= '<p>پرونده ثبت‌نام از مسیر بازیابی کارت در دسترس است.</p><a href="' . esc_url( ECM_Utils::page_url( 'recovery', array( 'event' => $event->slug ) ) ) . '">بازیابی کارت</a>';
		}
		if ( $registration ) {
			$content .= self::form_open( $event, 'logout' ) . '<button class="ecm-button ecm-button--ghost">خروج از ثبت‌نام</button></form>';
		}
		$css = (string) ( $settings['css'] ?? '' );
		if ( $css && ! str_contains( $css, '<' ) ) {
			wp_add_inline_style( 'ecm-flow', '@scope (.ecm-flow-' . $id . ') {' . $css . '}' );
		}
		if ( ! empty( $settings['js'] ) ) {
			wp_add_inline_script( 'ecm-flow', 'document.querySelectorAll(".ecm-flow-' . $id . '").forEach(function(root){' . $settings['js'] . '});' );
		}
		$stage = $row ? $row->state : ( $registration ? 'edit' : 'otp' );
		if ( $row && 'extra' === $stage ) {
			$extra = ECM_Flow::decode( $row->details );
			$stage .= '-' . ( 1 + (int) ( $extra['extra_index'] ?? 0 ) );
		}
		$event_config = ECM_Events::config( $event );
		$title = $edit_only ? 'ویرایش ثبت‌نام · ' . $event->title : ( $event_config['registration_title'] ?: $event->title );
		$hero = '<div class="ecm-hero"><div><h1>' . esc_html( $title ) . '</h1></div>';
		if ( ECM_Events::allows( $event, 'recovery' ) ) {
			$hero .= '<a class="ecm-button ecm-button--ghost" href="' . esc_url( ECM_Utils::page_url( 'recovery', array( 'event' => $event->slug ) ) ) . '">' . esc_html( $event_config['recovery_link_text'] ?: 'بازیابی کارت' ) . '</a>';
		}
		return ( new ECM_Public() )->shell( $hero . '</div><div class="ecm-content" data-ecm-stage="' . esc_attr( $stage ) . '">'
			. ( $notice ? '<p role="status" class="ecm-notice">' . esc_html( $notice ) . '</p>' : '' ) . $content . '</div>', 'ecm-registration ecm-flow ecm-flow-' . $id, $event->title );
	}

	private static function workflow_markup( object $event, object $registration, object $row, array $settings ): string {
		$state = (string) $row->state;
		$data = ECM_DB::decode_data( $registration );
		$details = ECM_Flow::decode( $row->details );
		$data = (array) ( $details['form'] ?? $data );
		$revision = (int) $row->revision;
		$body = '<p><strong>' . esc_html( ECM_Flow::states()[ $state ] ?? $state ) . '</strong> · کد پیگیری <bdi>' . esc_html( $registration->public_id ) . '</bdi></p>';
		if ( $row->review_note && in_array( $state, array( 'rejected', 'more_info' ), true ) ) {
			$body .= '<p role="status">' . esc_html( $row->review_note ) . '</p>';
		}
		if ( 'details' === $state ) {
			$main = $settings;
			$main['form'] = self::main_fields( $settings );
			$parts = self::fields( $event, $main, $data, (array) ( $details['guests'] ?? array() ) );
			$body .= self::step( $event, $revision, 'save_details', $parts, $settings['templates']['details'] ?? '', 'ذخیره و ادامه' );
		} elseif ( 'extra' === $state ) {
			$extra = $settings['extra'][ (int) ( $details['extra_index'] ?? 0 ) ] ?? array();
			$parts = self::fields( $event, $settings, $data, array(), (array) ( $extra['fields'] ?? array() ) );
			$body .= self::step( $event, $revision, 'save_extra', array( 'heading' => '<h2>' . esc_html( $extra['title'] ?? '' ) . '</h2>' ) + $parts, $extra['html'] ?? '', 'ادامه' );
		} elseif ( 'covenant' === $state ) {
			$parts = array(
				'content' => wp_kses_post( $settings['covenant_html'] ?? '' ) . self::download_link( $settings['covenant_pdf'] ?? '', 'دانلود PDF میثاق‌نامه' ),
				'accept' => '<label><input type="checkbox" name="accept" value="1" required> میثاق‌نامه را می‌پذیرم و ثبت نام من به‌عنوان امضا را تأیید می‌کنم.</label>',
			);
			$body .= self::step( $event, $revision, 'accept', $parts, $settings['templates']['covenant'] ?? '', 'پذیرش و ادامه' );
		} elseif ( in_array( $state, array( 'decision', 'followup' ), true ) ) {
			$body .= self::form_open( $event, 'choose_payment', $revision ) . '<button class="ecm-button ecm-button--primary">برای پرداخت آماده‌ام</button></form>';
			if ( 'decision' === $state ) {
				$select = '<label>دلیل قطعی نبودن<select name="reason" required><option value="">انتخاب کنید</option>';
				foreach ( self::reasons() as $reason ) {
					$select .= '<option>' . esc_html( $reason ) . '</option>';
				}
				$body .= self::step( $event, $revision, 'choose_followup', array( 'reason' => $select . '</select></label>', 'notes' => '<label>توضیحات تکمیلی<textarea name="notes"></textarea></label>' ), $settings['templates']['decision'] ?? '', 'ثبت درخواست تماس' );
			}
		} elseif ( in_array( $state, array( 'payment', 'rejected', 'more_info' ), true ) ) {
			$parts = self::payment_parts( $registration, $settings );
			if ( 'more_info' === $state ) {
				$parts['reply'] = '<label>پاسخ به درخواست اطلاعات تکمیلی<textarea name="reply" maxlength="1500"></textarea></label>';
			}
			$body .= self::step( $event, $revision, 'submit_receipt', $parts, $settings['templates']['payment'] ?? '', 'ارسال رسید برای بررسی' );
		} elseif ( 'confirmed' === $state && ECM_Events::allows( $event, 'card' ) ) {
			$primary = ECM_DB::primary_participant( (int) $registration->id );
			$url = $primary ? ECM_Tickets::card_url( $primary ) : '';
			if ( $url ) {
				$body .= '<p><a class="ecm-button ecm-button--primary" href="' . esc_url( $url ) . '">مشاهده و دانلود کارت</a></p>';
			}
		}
		if ( ! in_array( $state, array( 'details', 'cancelled' ), true ) ) {
			$body .= self::summary( $data, $details, (array) $settings['form']['fields'], (int) $row->receipt_id );
		}
		$policy = ECM_Flow::settings( (int) $event->id );
		if ( self::can_edit_flow( $row, $policy ) ) {
			$parts = self::fields( $event, $settings, $data, (array) ( $details['guests'] ?? array() ), self::edit_keys( $settings, $policy ) );
			if ( isset( $details['followup'] ) && ! empty( $policy['edit_all'] ) ) {
				$reason = '<label>دلیل درخواست تماس<select name="followup_reason" required>';
				foreach ( self::reasons() as $option ) {
					$reason .= '<option ' . selected( $details['followup']['reason'] ?? '', $option, false ) . '>' . esc_html( $option ) . '</option>';
				}
				$parts['edit_followup'] = $reason . '</select></label><label>توضیح درخواست تماس<textarea name="followup_notes" maxlength="1500">' . esc_textarea( $details['followup']['notes'] ?? '' ) . '</textarea></label>';
			}
			if ( (int) $row->receipt_id && 'cancelled' !== $state && ( $receipt = ECM_Flow_Receipts::row( (int) $row->receipt_id ) ) ) {
				$parts['edit_receipt'] = '<fieldset><legend>ویرایش اختیاری رسید</legend><label>تصویر جایگزین<input name="receipt" type="file" accept="image/jpeg,image/png,image/webp"></label><label>شماره پیگیری<input name="reference" maxlength="100" value="' . esc_attr( $receipt->reference ) . '"></label><label>تاریخ پرداخت<input name="payment_date" type="date" value="' . esc_attr( $receipt->payment_date ?? '' ) . '"></label></fieldset>';
			}
			$body .= '<details class="ecm-flow-edit"><summary class="ecm-button ecm-button--ghost">ویرایش اطلاعات ثبت‌نام</summary>' . self::step( $event, $revision, 'edit_workflow', $parts, $settings['templates']['edit'] ?? '', 'ذخیره تغییرات' ) . '</details>';
		}
		if ( in_array( $state, array( 'extra', 'covenant', 'decision', 'payment' ), true ) && ! (int) $row->receipt_id && ! empty( $settings['allow_back'] ) ) {
			$body .= self::form_open( $event, 'back', $revision ) . '<button class="ecm-button ecm-button--ghost">مرحله قبلی</button></form>';
		}
		if ( ! empty( $settings['cancel'] ) && ECM_Flow::transition( $state, 'cancel' ) ) {
			$body .= self::form_open( $event, 'cancel', $revision ) . '<label><input type="checkbox" name="confirm_cancel" value="1" required> تأیید می‌کنم که پرونده از ثبت‌نام‌های فعال خارج شود و سوابق آن در گزارش بماند.</label><button class="ecm-button ecm-button--ghost">انصراف</button></form>';
		}
		if ( ! empty( $settings['support'] ) && in_array( $state, array( 'rejected', 'more_info', 'followup' ), true ) ) {
			$body .= '<a href="' . esc_url( 'tel:' . $settings['support'], array( 'tel' ) ) . '">تماس با پشتیبانی · ' . esc_html( $settings['support'] ) . '</a>';
		}
		return $body;
	}

	public static function reasons(): array {
		return array( 'نیاز به زمان بیشتر', 'بررسی شرایط مالی', 'اطلاعات بیشتر درباره دوره', 'سؤال درباره زمان یا مکان', 'نیاز به مشورت', 'سایر موارد' );
	}

	private static function main_fields( array $settings ): array {
		$form = $settings['form'];
		$move = array();
		foreach ( (array) ( $settings['extra'] ?? array() ) as $step ) {
			$move = array_merge( $move, (array) ( $step['fields'] ?? array() ) );
		}
		$form['fields'] = array_values( array_filter( $form['fields'], static fn( $field ) => ! in_array( $field['key'], $move, true ) ) );
		return $form;
	}

	private static function can_edit_flow( object $row, array $settings ): bool {
		return ! empty( $settings['edit_enabled'] );
	}

	private static function edit_keys( array $snapshot, array $policy ): array {
		if ( empty( $policy['edit_all'] ) ) {
			return (array) ( $policy['edit_keys'] ?? array() ) ?: array( 'guests' );
		}
		return array_merge( array( 'guests' ), array_column( (array) $snapshot['form']['fields'], 'key' ) );
	}

	private static function summary( array $data, array $details, array $fields, int $receipt ): string {
		$html = '<details><summary>مشاهده اطلاعات و رسید ثبت‌شده</summary><dl>';
		foreach ( $fields as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( isset( $data[ $key ] ) ) {
				$html .= '<dt>' . esc_html( $field['label'] ?? $key ) . '</dt><dd>' . esc_html( implode( '، ', (array) $data[ $key ] ) ) . '</dd>';
			}
		}
		foreach ( (array) ( $details['guests'] ?? array() ) as $guest ) {
			$html .= '<dt>مهمان</dt><dd>' . esc_html( $guest['name'] . ' · ' . $guest['phone'] ) . '</dd>';
		}
		return $html . '</dl>' . ( $receipt ? '<a target="_blank" rel="noopener" href="' . esc_url( ECM_Flow_Receipts::url( $receipt ) ) . '">مشاهده تصویر رسید</a>' : '' ) . '</details>';
	}

	private static function payment_parts( object $registration, array $settings ): array {
		$info = wp_kses_post( $settings['payment_html'] ?? '' );
		foreach ( array( 'owner' => 'صاحب حساب', 'bank' => 'بانک', 'card' => 'شماره کارت', 'iban' => 'شبا' ) as $key => $title ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$info .= '<div>' . esc_html( $title ) . ' <bdi>' . esc_html( $settings[ $key ] ) . '</bdi></div>';
			}
		}
		$amount = (string) $registration->amount_original;
		if ( str_contains( $amount, '.' ) ) {
			$amount = rtrim( rtrim( $amount, '0' ), '.' );
		}
		$download = self::download_link( $settings['payment_pdf'] ?? '', 'دانلود توضیحات پرداخت' );
		$image = $settings['card_image'] ?? '';
		$download .= $image ? '<p><img class="ecm-flow-card-img" alt="تصویر کارت پرداخت" src="' . esc_url( $image ) . '"></p>' . self::download_link( $image, 'دانلود تصویر کارت' ) : '';
		return array(
			'payment' => $info, 'amount' => '<p>مبلغ پرداخت <bdi>' . esc_html( $amount . ' ' . $registration->currency_label ) . '</bdi></p>',
			'copy' => '<button type="button" data-ecm-copy="' . esc_attr( $amount ) . '">کپی مبلغ</button>' . ( empty( $settings['card'] ) ? '' : ' <button type="button" data-ecm-copy="' . esc_attr( $settings['card'] ) . '">کپی شماره کارت</button>' ) . ' <span data-ecm-copy-status aria-live="polite"></span>',
			'downloads' => $download, 'receipt' => '<label>تصویر رسید<input name="receipt" type="file" accept="image/jpeg,image/png,image/webp" required><small>حداکثر ' . (int) ( $settings['receipt_mb'] ?? 5 ) . ' مگابایت</small></label>',
			'reference' => '<label>شماره پیگیری تراکنش<input name="reference" maxlength="120" ' . ( empty( $settings['reference_required'] ) ? '' : 'required' ) . '></label>',
			'date' => '<label>تاریخ پرداخت<input name="payment_date" type="date" ' . ( empty( $settings['date_required'] ) ? '' : 'required' ) . '></label>',
		);
	}

	private static function download_link( string $url, string $label ): string {
		return $url ? '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" download>' . esc_html( $label ) . '</a></p>' : '';
	}

	private static function step( object $event, int $revision, string $command, array $parts, string $template, string $label ): string {
		$parts['continue'] = '<button class="ecm-button ecm-button--primary ecm-button--wide" type="submit">' . esc_html( $label ) . '</button>';
		if ( '' === trim( $template ) ) {
			$html = implode( "\n", $parts );
			if ( in_array( $command, array( 'save_details', 'save_extra', 'edit_workflow' ), true ) ) {
				$html = '<div class="ecm-field-grid ecm-flow-grid">' . $html . '</div>';
			}
		} else {
			$rendered = array();
			self::$parts = $parts;
			try {
				$html = preg_replace_callback( '/\[ecm_flow_part\s+key=["\']([a-z0-9_-]+)["\']\s*\]/i', static function ( array $match ) use ( &$rendered ): string {
					$key = strtolower( $match[1] );
					if ( isset( $rendered[ $key ] ) ) {
						return '';
					}
					$rendered[ $key ] = true;
					return self::$parts[ $key ] ?? '';
				}, wp_kses_post( $template ) );
			} finally {
				self::$parts = array();
			}
			$html .= implode( "\n", array_diff_key( $parts, $rendered ) );
		}
		return self::form_open( $event, $command, $revision ) . $html . '</form>';
	}

	private static function fields( object $event, array $settings, array $data, array $guests, ?array $edit = null ): array {
		$renderer = new ECM_Public();
		$parts = array();
		$id_prefix = wp_unique_id( 'ecm-flow-' ) . '-';
		$config = $settings['form'];
		$mobile = $config['sms_field'] ?: 'mobile';
		$parts['verified_mobile'] = '<p>موبایل تأییدشده <bdi>' . esc_html( $data[ $mobile ] ?? $data['mobile'] ?? '' ) . '</bdi></p>';
		foreach ( (array) $config['fields'] as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );
			if ( ! $key || in_array( $key, array( 'mobile', $mobile ), true ) || 'guest' === ( $field['type'] ?? '' ) || ( is_array( $edit ) && ! in_array( $key, $edit, true ) ) ) {
				continue;
			}
			$parts[ 'field_' . $key ] = $renderer->render_field( $field, $data[ $key ] ?? '', $event, $id_prefix );
		}
		$limit = (int) ( $settings['guest_limit'] ?? 0 );
		if ( $limit && ( null === $edit || in_array( 'guests', $edit, true ) ) ) {
			$parts['guests'] = self::guest_fields( $guests, $limit );
		}
		return $parts;
	}

	public static function guest_fields( array $guests, int $limit ): string {
		$html = '<fieldset data-ecm-flow-guests><legend>مهمان‌ها · حداکثر ' . $limit . '</legend>';
		for ( $i = 0; $i < $limit; $i++ ) {
			$guest = $guests[ $i ] ?? array();
			$html .= '<details' . ( $guest ? ' open' : '' ) . '><summary>مهمان ' . ( $i + 1 ) . '</summary>'
				. '<label>نام<input name="guests[' . $i . '][name]" maxlength="190" value="' . esc_attr( $guest['name'] ?? '' ) . '"></label>'
				. '<label>موبایل<input type="tel" name="guests[' . $i . '][phone]" value="' . esc_attr( $guest['phone'] ?? '' ) . '"></label>'
				. ( empty( $guest['id'] ) ? '' : '<input type="hidden" name="guests[' . $i . '][id]" value="' . (int) $guest['id'] . '"><label><input type="checkbox" name="guests[' . $i . '][remove]" value="1"> حذف مهمان</label>' )
				. '</details>';
		}
		return $html . '</fieldset>';
	}

	public static function validate( array $config, string $phone, array $raw, array $guests, int $limit, array $previous = array(), ?array $edit = null ) {
		$public = new ECM_Public();
		$data = $previous;
		$data['mobile'] = $data[ $config['sms_field'] ?: 'mobile' ] = $phone;
		foreach ( (array) $config['fields'] as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );
			$type = $field['type'] ?? 'text';
			if ( ! $key || in_array( $key, array( 'mobile', $config['sms_field'] ), true ) || in_array( $type, array( 'html', 'guest' ), true ) || ( null !== $edit && ! in_array( $key, $edit, true ) ) ) {
				continue;
			}
			$value = $raw[ $key ] ?? '';
			if ( 'checkbox' === $type ) {
				$value = array_values( array_filter( (array) $value, 'is_string' ) );
			} elseif ( ! is_string( $value ) ) {
				$value = '';
			}
			$value = $public->sanitize_field_value( $value, $type, (array) ( $field['options'] ?? array() ) );
			if ( ( '' === $value || array() === $value ) && ! empty( $field['required'] ) ) {
				return new WP_Error( 'fields', 'فیلد «' . ( $field['label'] ?? $key ) . '» معتبر یا تکمیل نشده است.' );
			}
			$data[ $key ] = $value;
		}
		if ( ! empty( $data['priority_city'] ) && ! empty( $data['available_cities'] ) && ! in_array( $data['priority_city'], (array) $data['available_cities'], true ) ) {
			return new WP_Error( 'city', 'شهر اولویت‌دار باید یکی از شهرهای قابل حضور باشد.' );
		}
		$list = array();
		$phones = array( $phone );
		$has_guest_field = false;
		foreach ( (array) $config['fields'] as $field ) {
			$has_guest_field = $has_guest_field || ( $field['type'] ?? '' ) === 'guest' && ! empty( $field['required'] );
		}
		foreach ( array_slice( $guests, 0, 40 ) as $guest ) {
			if ( ! is_array( $guest ) || ! empty( $guest['remove'] ) ) {
				continue;
			}
			$name = sanitize_text_field( is_string( $guest['name'] ?? '' ) ? ( $guest['name'] ?? '' ) : '' );
			$input = is_string( $guest['phone'] ?? '' ) ? ( $guest['phone'] ?? '' ) : '';
			if ( '' === $name && '' === $input && empty( $guest['id'] ) ) {
				continue;
			}
			$number = ECM_Utils::normalize_mobile( $input );
			if ( ! $name || ! $number || ( empty( $config['allow_duplicate_guest_phone'] ) && in_array( $number, $phones, true ) ) || count( $list ) >= $limit ) {
				return new WP_Error( 'guest', 'نام و موبایل مهمان‌ها، شماره‌های تکراری و سقف تعداد را بررسی کنید.' );
			}
			$list[] = array( 'name' => $name, 'phone' => $number, 'id' => absint( $guest['id'] ?? 0 ) );
			$phones[] = $number;
		}
		if ( $has_guest_field && $limit && ! $list && ( null === $edit || in_array( 'guests', $edit, true ) ) ) {
			return new WP_Error( 'guest', 'حداقل یک مهمان وارد کنید.' );
		}
		return array( 'form' => $data, 'guests' => $list );
	}

	private static function edit_markup( object $event, object $registration ): string {
		$settings = ECM_Flow::settings( (int) $event->id );
		$config = ECM_Events::config( $event );
		$keys = (array) ( $settings['edit_keys'] ?? array() );
		if ( ! $keys ) {
			return '<p>در این رویداد فیلدی برای ویرایش انتخاب نشده است.</p>';
		}
		$guests = ECM_Flow_Edit::guests( (int) $registration->id );
		$parts = self::fields( $event, array( 'form' => $config, 'guest_limit' => min( 20, (int) $event->guest_limit ) ), ECM_DB::decode_data( $registration ), $guests, $keys );
		return self::step( $event, 0, 'edit', $parts, $settings['templates']['edit'] ?? '', 'ذخیره تغییرات' );
	}

	public static function post(): void {
		$id = absint( self::param( 'event_id' ) );
		$event = ECM_Events::event( $id );
		check_admin_referer( self::nonce_action( $id ) );
		if ( ! ECM_Flow::ready() || ! ECM_Events::is_publicly_active( $event ) ) {
			wp_die( 'رویداد در دسترس نیست.', '', array( 'response' => 403 ) );
		}
		$settings = ECM_Flow::settings( $id );
		$command = self::param( 'command' );
		$session_id = (int) ( self::session( $id )['id'] ?? 0 );
		if ( 'logout' === $command ) {
			$token = self::token( 'ecm_flow_session_' . $id );
			if ( $token ) {
				delete_transient( 'ecm_flow_s_' . hash( 'sha256', $token ) );
			}
			self::cookie( 'ecm_flow_session_' . $id, '', -HOUR_IN_SECONDS );
			self::redirect( $event, 'از این ثبت‌نام خارج شدید.' );
		}
		if ( in_array( $command, array( 'send', 'resend', 'verify' ), true ) ) {
			self::redirect( $event, 'verify' === $command ? self::verify( $event, $settings ) : self::send( $event, $settings, 'resend' === $command ) );
		}
		$registration = $session_id ? ECM_DB::registration( $session_id ) : null;
		if ( ! $registration || (int) $registration->event_id !== $id ) {
			self::redirect( $event, 'ابتدا موبایل خود را تأیید کنید.' );
		}
		$row = ECM_Flow::row( $session_id );
		$raw = isset( $_POST['ecm_field'] ) && is_array( $_POST['ecm_field'] ) ? wp_unslash( $_POST['ecm_field'] ) : array();
		$guests = isset( $_POST['guests'] ) && is_array( $_POST['guests'] ) ? wp_unslash( $_POST['guests'] ) : array();
		if ( 'edit' === $command && ! $row ) {
			$updated = ECM_Flow_Edit::save( $event, $registration, $settings, $raw, $guests );
			self::redirect( $event, is_wp_error( $updated ) ? $updated->get_error_message() : 'تغییرات ذخیره شد.' );
		}
		$allowed = array( 'save_details', 'save_extra', 'accept', 'choose_payment', 'choose_followup', 'submit_receipt', 'back', 'cancel', 'edit_workflow' );
		if ( ! $row || ! in_array( $command, $allowed, true ) || ( ! in_array( $command, array( 'cancel', 'edit_workflow' ), true ) && ! ECM_Events::allows( $event, 'registration' ) ) ) {
			self::redirect( $event, 'این عملیات برای ثبت‌نام در دسترس نیست.' );
		}
		$snapshot = ECM_Flow::decode( $row->snapshot );
		$input = array();
		if ( 'save_details' === $command ) {
			$saved = ECM_Flow::decode( $row->details );
			$data = (array) ( $saved['form'] ?? ECM_DB::decode_data( $registration ) );
			$input = self::validate( self::main_fields( $snapshot ), (string) $data['mobile'], $raw, $guests, (int) $snapshot['guest_limit'], $data );
		} elseif ( 'edit_workflow' === $command ) {
			if ( ! self::can_edit_flow( $row, $settings ) ) {
				self::redirect( $event, 'ویرایش در این مرحله مجاز نیست.' );
			}
			$saved = ECM_Flow::decode( $row->details );
			$data = (array) ( $saved['form'] ?? ECM_DB::decode_data( $registration ) );
			$keys = self::edit_keys( $snapshot, $settings );
			$input = self::validate( $snapshot['form'], (string) $data['mobile'], $raw, in_array( 'guests', $keys, true ) ? $guests : array(), (int) $snapshot['guest_limit'], $data, $keys );
			if ( ! is_wp_error( $input ) && ! in_array( 'guests', $keys, true ) ) {
				$input['guests'] = (array) ( $saved['guests'] ?? array() );
			}
			if ( ! is_wp_error( $input ) && isset( $saved['followup'], $_POST['followup_reason'] ) && ! empty( $settings['edit_all'] ) ) {
				$reason = self::param( 'followup_reason' );
				$input = in_array( $reason, self::reasons(), true ) ? $input + array( 'followup' => array( 'reason' => $reason, 'notes' => wp_html_excerpt( self::param( 'followup_notes', true ), 1500, '' ) ) ) : new WP_Error( 'reason', 'یک دلیل را انتخاب کنید.' );
			}
			if ( ! is_wp_error( $input ) && (int) $row->receipt_id && 'cancelled' !== $row->state ) {
				$replacement = ECM_Flow_Receipts::replacement( (int) $row->receipt_id, $snapshot );
				if ( is_wp_error( $replacement ) ) {
					$input = $replacement;
				} elseif ( $replacement ) {
					$input['replacement_receipt'] = $replacement;
				}
			}
		} elseif ( 'save_extra' === $command && 'extra' === $row->state ) {
			$saved = ECM_Flow::decode( $row->details );
			$data = (array) ( $saved['form'] ?? ECM_DB::decode_data( $registration ) );
			$extra = $snapshot['extra'][ (int) ( $saved['extra_index'] ?? 0 ) ] ?? array();
			$input = self::validate( $snapshot['form'], (string) $data['mobile'], $raw, array(), 0, $data, (array) ( $extra['fields'] ?? array() ) );
		} elseif ( 'submit_receipt' === $command ) {
			if ( ! ECM_Flow::transition( $row->state, $command ) ) {
				self::redirect( $event, 'ارسال رسید در این وضعیت مجاز نیست.' );
			}
			$input = ECM_Flow_Receipts::upload( (array) ( $_FILES['receipt'] ?? array() ), substr( self::param( 'reference' ), 0, 120 ), self::param( 'payment_date' ), $snapshot );
			if ( ! is_wp_error( $input ) && 'more_info' === $row->state ) {
				$input['reply'] = wp_html_excerpt( self::param( 'reply', true ), 1500, '' );
			}
		} elseif ( 'choose_followup' === $command ) {
			$reason = self::param( 'reason' );
			$input = in_array( $reason, self::reasons(), true ) ? array( 'reason' => $reason, 'notes' => self::param( 'notes', true ) ) : new WP_Error( 'reason', 'یک دلیل را انتخاب کنید.' );
		} elseif ( 'accept' === $command ) {
			$input['accept'] = '1' === self::param( 'accept' );
		} elseif ( ( 'cancel' === $command && ( empty( $snapshot['cancel'] ) || '1' !== self::param( 'confirm_cancel' ) ) ) ||
			( 'back' === $command && empty( $snapshot['allow_back'] ) ) ) {
			self::redirect( $event, 'این عملیات مجاز نیست.' );
		}
		if ( is_wp_error( $input ) ) {
			self::redirect( $event, $input->get_error_message() );
		}
		$updated = ECM_Flow::change( $session_id, absint( self::param( 'revision' ) ), $command, $input );
		self::redirect( $event, is_wp_error( $updated ) ? $updated->get_error_message() : 'اطلاعات ذخیره شد.' );
	}

	private static function send( object $event, array $settings, bool $resend = false ): string {
		if ( ( empty( $settings['enabled'] ) && empty( $settings['edit_enabled'] ) ) ||
			( ! ECM_Events::allows( $event, 'registration' ) && ! ECM_Events::allows( $event, 'recovery' ) ) ) {
			return 'تأیید شماره برای این رویداد فعال نیست.';
		}
		$old = self::otp_challenge( (int) $event->id );
		$phone = ECM_Utils::normalize_mobile( $resend ? (string) ( $old->recipient ?? '' ) : self::param( 'phone' ) );
		if ( ! $phone ) {
			return 'شماره موبایل معتبر وارد کنید.';
		}
		global $wpdb;
		$table = ECM_DB::table( 'otp' );
		$id = (int) $event->id;
		$now = time();
		$hash = ECM_Flow::phone_hash( $phone );
		$ip = hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), wp_salt( 'nonce' ) );
		$count = (int) get_transient( 'ecm_flow_otp_ip_' . $ip );
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id=%d AND phone_hash=%s AND created_at>=%s", $id, $hash, gmdate( 'Y-m-d H:i:s', $now - 1800 ) ) );
		$wait = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id=%d AND phone_hash=%s AND created_at>=%s", $id, $hash, gmdate( 'Y-m-d H:i:s', $now - 60 ) ) );
		if ( $count >= 20 || $recent >= 5 || $wait ) {
			return 'برای ارسال دوباره کمی صبر کنید.';
		}
		set_transient( 'ecm_flow_otp_ip_' . $ip, $count + 1, 1800 );
		$key = bin2hex( random_bytes( 24 ) );
		$key_hash = hash( 'sha256', $key );
		$code = (string) random_int( 100000, 999999 );
		$inserted = $wpdb->insert( $table, array(
			'challenge_hash' => $key_hash, 'event_id' => $id, 'phone_hash' => $hash, 'recipient' => $phone,
			'otp_hash' => hash_hmac( 'sha256', $key . $code, wp_salt( 'auth' ) ),
			'status' => 'flow_active', 'expires_at' => gmdate( 'Y-m-d H:i:s', $now + 300 ),
			'resend_at' => gmdate( 'Y-m-d H:i:s', $now + 60 ), 'created_at' => ECM_Utils::now_utc(), 'updated_at' => ECM_Utils::now_utc(),
		) );
		if ( ! $inserted ) {
			return 'ذخیره درخواست کد انجام نشد.';
		}
		$config = ECM_Events::config( $event );
		$result = ECM_SMS::send_secure_pattern( ( $settings['otp_profile'] ?? '' ) ?: ( $config['recovery_otp_pattern_profile'] ?? 'recovery_otp' ), $phone, array( 'code' => $code ), array( 'event_id' => $id ) );
		if ( is_wp_error( $result ) ) {
			$wpdb->update( $table, array( 'status' => 'send_failed', 'otp_hash' => '' ), array( 'challenge_hash' => $key_hash ) );
			ECM_Diagnostics::record( 'workflow', 'OTP delivery failed', array( 'event_id' => $id, 'code' => $result->get_error_code() ) );
			return 'ارسال کد انجام نشد. دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.';
		}
		if ( $old && $old->recipient === $phone ) {
			$wpdb->update( $table, array( 'status' => 'replaced', 'otp_hash' => '', 'recipient' => '' ), array( 'id' => (int) $old->id, 'status' => 'flow_active' ) );
		}
		self::cookie( 'ecm_flow_otp_' . $id, $key, 1800 );
		return 'کد تأیید ارسال شد.';
	}

	private static function verify( object $event, array $settings ): string {
		global $wpdb;
		$id = (int) $event->id;
		$key = self::token( 'ecm_flow_otp_' . $id );
		$table = ECM_DB::table( 'otp' );
		$code = ECM_Utils::normalize_digits( self::param( 'code' ) );
		$row = $key ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE challenge_hash=%s AND event_id=%d AND status='flow_active' AND expires_at>%s", hash( 'sha256', $key ), $id, ECM_Utils::now_utc() ) ) : null;
		if ( ! $row || 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts=attempts+1 WHERE id=%d AND status='flow_active' AND attempts<5", (int) $row->id ) ) ) {
			return 'کد معتبر نیست یا منقضی شده است.';
		}
		if ( ! preg_match( '/^\d{6}$/D', $code ) || ! hash_equals( $row->otp_hash, hash_hmac( 'sha256', $key . $code, wp_salt( 'auth' ) ) ) ) {
			return 'کد تأیید اشتباه است.';
		}
		if ( 1 !== $wpdb->update( $table, array( 'status' => 'verified', 'otp_hash' => '', 'recipient' => '', 'verified_at' => ECM_Utils::now_utc() ), array( 'id' => (int) $row->id, 'status' => 'flow_active' ) ) ) {
			return 'کد پیش‌تر استفاده شده است.';
		}
		self::cookie( 'ecm_flow_otp_' . $id, '', -300 );
		$flow = ECM_Flow::by_phone( $id, $row->recipient );
		if ( $flow ) {
			$registration_id = (int) $flow->registration_id;
		} elseif ( ! empty( $settings['enabled'] ) && ECM_Events::allows( $event, 'registration' ) ) {
			$created = ECM_Flow::create( $event, $row->recipient );
			if ( is_wp_error( $created ) ) {
				return $created->get_error_message();
			}
			$registration_id = (int) $created->registration_id;
		} elseif ( ! empty( $settings['edit_enabled'] ) && ECM_Events::allows( $event, 'recovery' ) ) {
			$participant = ECM_Participants::recover_by_phone( $row->recipient, $id, self::param( 'tracking_code' ) );
			if ( ! $participant || is_wp_error( $participant ) || 'primary' !== $participant->participant_type ) {
				return 'پرونده یکتای معرف برای ویرایش پیدا نشد. با پشتیبانی تماس بگیرید.';
			}
			$registration_id = (int) $participant->registration_id;
		} else {
			return 'ثبت‌نام جدید در حال حاضر فعال نیست.';
		}
		return self::grant( $id, $registration_id ) ? 'شماره موبایل تأیید شد.' : 'ورود انجام نشد. دوباره تلاش کنید.';
	}

	private static function redirect( object $event, string $message ): void {
		$key = bin2hex( random_bytes( 24 ) );
		set_transient( 'ecm_flow_f_' . hash( 'sha256', $key ), $message, 120 );
		self::cookie( 'ecm_flow_flash_' . (int) $event->id, $key, 120 );
		$destination = wp_validate_redirect( self::param( 'return_to' ), '' );
		if ( ! $destination || url_to_postid( $destination ) < 1 ) {
			$destination = ECM_Events::registration_url( $event );
		}
		wp_safe_redirect( add_query_arg( 'event', $event->slug, $destination ), 303 );
		exit;
	}
}
