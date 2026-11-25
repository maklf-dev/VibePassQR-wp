<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Flow_Admin {
	public static function hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menus' ), 30 );
		foreach ( array( 'save' => 'save', 'review' => 'review', 'export' => 'export', 'manual' => 'manual' ) as $action => $method ) {
			add_action( 'admin_post_ecm_flow_' . $action, array( __CLASS__, $method ) );
		}
	}

	public static function menus(): void {
		add_submenu_page( 'ecm-dashboard', 'ثبت‌نام مرحله‌ای', 'ثبت‌نام مرحله‌ای', 'manage_ecm_events', 'ecm-workflow', array( __CLASS__, 'page' ) );
	}

	public static function can_review( int $event_id ): bool {
		return $event_id > 0 && ( current_user_can( 'manage_options' ) || (
			current_user_can( 'view_ecm_reports' ) && in_array( get_current_user_id(), array_map( 'intval', (array) ( ECM_Flow::settings( $event_id )['reviewers'] ?? array() ) ), true )
		) );
	}

	private static function box( string $key, string $label, $value = '', string $type = 'text' ): void {
		echo '<p><label>' . esc_html( $label ) . ' ';
		if ( 'checkbox' === $type ) {
			echo '<input type="checkbox" value="1" name="' . esc_attr( $key ) . '" ' . checked( (bool) $value, true, false ) . '>';
		} elseif ( in_array( $type, array( 'textarea', 'code' ), true ) ) {
			echo '<br><textarea rows="3" class="large-text' . ( 'code' === $type ? ' ecm-code-editor" dir="ltr' : '' ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		echo '</label></p>';
	}

	private static function post_open( string $action, int $event_id, string $nonce ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( 'ecm_flow_' . $action ) . '"><input type="hidden" name="event_id" value="' . $event_id . '">';
		wp_nonce_field( $nonce );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'تنظیمات فقط برای مدیر کل در دسترس است.', '', array( 'response' => 403 ) );
		}
		$events = ECM_Events::all();
		$id = absint( $_GET['event_id'] ?? ( $events[0]->id ?? 0 ) );
		$event = ECM_Events::event( $id );
		wp_enqueue_style( 'ecm-flow', ECM_URL . 'assets/css/flow.css', array(), ECM_VERSION );
		wp_enqueue_script( 'ecm-flow-admin', ECM_URL . 'assets/js/flow-admin.js', array(), ECM_VERSION, true );
		echo '<div class="wrap ecm-admin ecm-workflow-admin" dir="rtl"><div class="ecm-admin__header"><h1>ثبت‌نام مرحله‌ای و دستی</h1></div><form method="get"><input type="hidden" name="page" value="ecm-workflow"><label>رویداد <select name="event_id">';
		self::event_options( $events, $id );
		echo '</select></label> <button class="button">نمایش</button></form>';
		if ( isset( $_GET['saved'] ) ) {
			echo '<p class="notice notice-success">تنظیمات ذخیره شد.</p>';
		}
		if ( ! $event || ! ECM_Flow::ready() ) {
			echo '<p>رویداد یا ساختار داده در دسترس نیست.</p></div>';
			return;
		}
		$config = ECM_Events::config( $event );
		$settings = ECM_Flow::settings( $id );
		$profiles = (array) ( ECM_Utils::settings()['sms_pattern_profiles'] ?? array() );
		echo '<p><a href="' . esc_url( ECM_Reports::url( 'workflow', array( 'ecm_event' => $id ), true ) ) . '">پرونده‌ها و بررسی رسید</a> · <a href="' . esc_url( admin_url( 'admin.php?page=ecm-events&edit=' . $id ) ) . '">تنظیم فیلدها، مهمان و مبلغ در رویداد</a></p>';
		echo '<p>شورت‌کد برگه اختصاصی: <code>[ecm_flow event="' . esc_html( $event->slug ) . '"]</code></p>';
		self::post_open( 'save', $id, 'ecm_flow_save_' . $id );
		echo '<h2>مسیر و دسترسی</h2>';
		$flags = self::flags();
		foreach ( $flags as $key => $label ) {
			self::box( $key, $label, $settings[ $key ] ?? in_array( $key, array( 'allow_back', 'cancel', 'reference_required', 'date_required' ), true ), 'checkbox' );
		}
		self::box( 'reviewers', 'شناسه کاربران وردپرس مجاز به بررسی این رویداد (جدا با ویرگول)', implode( ',', (array) ( $settings['reviewers'] ?? array() ) ) );
		self::pattern_picker( 'otp_profile', 'پترن پیامک کد تأیید (OTP)', $settings['otp_profile'] ?? '', $profiles, 'otp' );
		$current_otp = ECM_SMS::profile_id( ( $settings['otp_profile'] ?? '' ) ?: ( $config['recovery_otp_pattern_profile'] ?? 'recovery_otp' ), $profiles );
		$otp_issue = ECM_SMS::otp_parameter( (array) ( $profiles[ $current_otp ] ?? array() ) );
		if ( is_wp_error( $otp_issue ) ) {
			echo '<p class="notice notice-warning">' . esc_html( $otp_issue->get_error_message() ) . '</p>';
		}
		self::box( 'support', 'شماره پشتیبانی', $settings['support'] ?? '' );
		echo '<h3>فیلدهای قابل ویرایش بعد از OTP</h3><p>موبایل معرف قفل است. ویرایش مستقل رویداد عادی از شورت‌کد <code>[ecm_edit_registration event="' . esc_html( $event->slug ) . '"]</code> قابل استفاده است.</p>';
		$fields = array( 'guests' => 'افزودن، ویرایش و حذف مهمان' );
		foreach ( (array) $config['fields'] as $field ) {
			if ( ! in_array( $field['key'], array( 'mobile', $config['sms_field'] ), true ) && ! in_array( $field['type'], array( 'guest', 'html' ), true ) ) {
				$fields[ $field['key'] ] = $field['label'];
			}
		}
		foreach ( $fields as $key => $label ) {
			self::box( 'edit_keys[' . $key . ']', $label, in_array( $key, (array) ( $settings['edit_keys'] ?? array() ), true ), 'checkbox' );
		}
		echo '<h2>اطلاعات پرداخت</h2>';
		foreach ( self::text_fields() as $key => $label ) {
			self::box( $key, $label, $settings[ $key ] ?? '' );
		}
		self::box( 'receipt_mb', 'حداکثر حجم تصویر رسید بر حسب MB (۱ تا ۸)', $settings['receipt_mb'] ?? 5, 'number' );
		self::box( 'payment_html', 'متن / HTML معرفی پرداخت', $settings['payment_html'] ?? '', 'code' );
		self::box( 'covenant_html', 'متن / HTML میثاق‌نامه', $settings['covenant_html'] ?? '', 'code' );
		echo '<details><summary>طراحی پیشرفته مراحل</summary><p>مراحل تکمیلی پس از فرم اطلاعات می‌آیند. کلید فیلدهای هر مرحله را از فرم رویداد بردارید.</p>';
		self::rows_editor( array_values( (array) ( $settings['extra'] ?? array() ) ), 8, 'افزودن مرحله', 'مرحلهٔ تکمیلی', static function ( string $index, array $stage ) {
			$prefix = "extra[{$index}]";
			self::box( $prefix . '[title]', 'عنوان مرحله تکمیلی', $stage['title'] ?? '' );
			self::box( $prefix . '[html]', 'HTML مرحله تکمیلی', $stage['html'] ?? '', 'code' );
			self::box( $prefix . '[fields]', 'کلید فیلدهای این مرحله، جدا با ویرگول', implode( ',', (array) ( $stage['fields'] ?? array() ) ) );
		} );
		$catalog = array(
			'details' => array_merge( array( 'verified_mobile' => 'موبایل تأییدشده', 'guests' => 'فهرست مهمان' ), array_combine( array_map( static fn( $key ) => 'field_' . $key, array_diff( array_keys( $fields ), array( 'guests' ) ) ), array_values( array_diff_key( $fields, array( 'guests' => true ) ) ) ) ),
			'payment' => array( 'payment' => 'اطلاعات پرداخت', 'amount' => 'مبلغ', 'copy' => 'کپی', 'downloads' => 'دانلودها', 'receipt' => 'تصویر رسید', 'reference' => 'شماره پیگیری', 'date' => 'تاریخ' ),
			'covenant' => array( 'content' => 'متن و فایل', 'accept' => 'پذیرش' ),
			'decision' => array( 'reason' => 'دلیل', 'notes' => 'توضیح' ),
		);
		$catalog['edit'] = $catalog['details'];
		$catalog['edit']['edit_receipt'] = 'ویرایش رسید جاری';
		$catalog['edit']['edit_followup'] = 'ویرایش دلیل و توضیح پیگیری';
		foreach ( $catalog as $step => $parts ) {
			echo '<h4>' . esc_html( $step ) . '</h4><p>';
			foreach ( $parts + array( 'continue' => 'ذخیره و ادامه' ) as $key => $label ) {
				echo '<code title="' . esc_attr( $label ) . '">[ecm_flow_part key="' . esc_html( $key ) . '"]</code> ';
			}
			echo '</p>';
			self::box( 'templates[' . $step . ']', 'HTML قالب (اجزای جاافتاده در انتها درج می‌شوند)', $settings['templates'][ $step ] ?? '', 'code' );
		}
		self::box( 'css', 'CSS فرم در محدوده رویداد', $settings['css'] ?? '', 'code' );
		if ( current_user_can( 'unfiltered_html' ) ) {
			self::box( 'js', 'JavaScript مدیر کل (root عنصر فرم رویداد است)', $settings['js'] ?? '', 'code' );
		}
		echo '</details>';
		self::reminders_editor( $settings, $profiles );
		echo '<details><summary>پیامک‌های وضعیت، ویرایش و یادآوری پیش‌فرض</summary><p>متغیرها شامل <code>{name}</code>، <code>{tracking_code}</code>، <code>{resume_link}</code>، <code>{review_note}</code> و در تأیید نهایی <code>{card_link}</code> هستند.</p>';
		foreach ( self::notification_labels() as $key => $label ) {
			echo '<details><summary>' . esc_html( $label ) . '</summary>';
			self::sms_editor( "sms[{$key}]", $settings['sms'][ $key ] ?? array(), $profiles, $key );
			echo '</details>';
		}
		echo '</details><input type="hidden" name="ecm_flow_form_complete" value="1"><p><button class="button button-primary">ذخیره تنظیمات</button></p></form>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=ecm-test-registration&event_id=' . $id ) ) . '">ثبت‌نام دستی برای این رویداد</a></p></div>';
	}

	private static function rows_editor( array $rows, int $limit, string $add, string $title, callable $render ): void {
		$card = static function ( string $index, array $row ) use ( $title, $render ) {
			echo '<fieldset class="ecm-flow-config-row" data-flow-config-row><legend>' . esc_html( $title ) . ' <span data-flow-row-number></span></legend>';
			$render( $index, $row );
			echo '<p class="ecm-flow-row-actions"><button type="button" class="button" data-flow-row-up>بالا</button> <button type="button" class="button" data-flow-row-down>پایین</button> <button type="button" class="button-link-delete" data-flow-row-remove>حذف</button></p></fieldset>';
		};
		echo '<div data-flow-config-editor data-limit="' . $limit . '"><div data-flow-config-items>';
		foreach ( array_values( $rows ) as $index => $row ) { $card( (string) $index, $row ); }
		echo '</div><p data-flow-config-empty ' . ( $rows ? 'hidden' : '' ) . '>هنوز موردی اضافه نشده است.</p><template>';
		$card( '__row__', array() );
		echo '</template><p><button type="button" class="button" data-flow-row-add>' . esc_html( $add ) . '</button></p></div>';
	}

	private static function pattern_picker( string $name, string $label, string $value, array $profiles, string $use ): void {
		$current = ECM_SMS::profile_id( $value, $profiles );
		$choices = array_filter( $profiles, static fn( $profile ) => 'otp' === $use ? ! is_wp_error( ECM_SMS::otp_parameter( $profile ) ) : self::notification_pattern( $profile, $use ) );
		echo '<p><label>' . esc_html( $label ) . ' <select name="' . esc_attr( $name ) . '"><option value="">' . ( 'otp' === $use ? 'پیش‌فرض بازیابی رویداد' : 'متن آزاد' ) . '</option>';
		if ( $current && ! isset( $choices[ $current ] ) ) {
			echo '<option value="' . esc_attr( $current ) . '" selected>نیازمند اصلاح: ' . esc_html( $profiles[ $current ]['label'] ?? $value ) . '</option>';
		}
		foreach ( $choices as $key => $profile ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $current, $key, false ) . '>' . esc_html( ( $profile['label'] ?? $key ) . ' · ' . $profile['code'] ) . '</option>';
		}
		echo '</select></label></p>';
	}

	private static function notification_pattern( array $profile, string $use ): bool {
		if ( empty( $profile['enabled'] ) || empty( $profile['code'] ) || 'otp' === ( $profile['purpose'] ?? '' ) ||
			( 'confirmed' !== $use && ! empty( $profile['requires_payment'] ) ) ) { return false; }
		foreach ( (array) ( $profile['params'] ?? array() ) as $value ) {
			preg_match_all( '/\{[^{}]+\}/', (string) $value, $tokens );
			$allowed = array( '{name}', '{phone}', '{event_name}', '{tracking_code}', '{resume_link}', '{participant_type}', '{time}', '{payment_status}', '{payment_date}' );
			if ( 'confirmed' === $use ) { $allowed = array_merge( $allowed, array( '{card_link}', '{host_name}', '{payment_reference}' ) ); }
			if ( in_array( $use, array( 'rejected', 'more_info' ), true ) ) { $allowed[] = '{review_note}'; }
			foreach ( $tokens[0] as $token ) {
				if ( ! in_array( $token, $allowed, true ) && ! ( ! str_starts_with( $use, 'reminder_' ) && preg_match( '/^\{field:[a-z0-9_-]+\}$/D', $token ) ) ) { return false; }
			}
		}
		return true;
	}

	private static function sms_editor( string $prefix, array $rules, array $profiles, string $use ): void {
		foreach ( array( 'primary' => 'معرف', 'guest' => 'مهمان' ) as $audience => $title ) {
			$rule = $rules[ $audience ] ?? array();
			$name = $prefix . '[' . $audience . ']';
			self::box( $name . '[enabled]', 'ارسال به ' . $title, $rule['enabled'] ?? ( 'reminder_custom' === $use && 'primary' === $audience ), 'checkbox' );
			self::pattern_picker( $name . '[pattern_profile]', 'روش ارسال به ' . $title, $rule['pattern_profile'] ?? '', $profiles, $use );
			self::box( $name . '[text]', 'متن آزاد برای ' . $title, $rule['text'] ?? self::default_message( $use ), 'textarea' );
		}
	}

	private static function reminders_editor( array $settings, array $profiles ): void {
		$custom = isset( $settings['reminder_rules'] );
		$deadline = (string) ( $settings['reminder_deadline'] ?? '' );
		echo '<details><summary>زمان‌بندی یادآوری ثبت‌نام</summary><p>برای ارسال، گزینه «یادآوری ثبت‌نام نیمه‌تمام» را در بخش مسیر و دسترسی روشن کنید. ساعت‌ها به وقت سایت هستند.</p>';
		self::box( 'reminder_deadline', 'تاریخ و ساعت پایان مهلت ثبت‌نام (مبنای یادآوری)', $deadline ? ECM_Utils::utc_to_local( $deadline, 'Y-m-d\TH:i' ) : '', 'datetime-local' );
		echo '<p><label>روش زمان‌بندی <select name="reminder_mode" data-flow-reminder-mode><option value="legacy" ' . selected( ! $custom, true, false ) . '>زمان‌بندی پیش‌فرض</option><option value="custom" ' . selected( $custom, true, false ) . '>یادآوری‌های سفارشی</option></select></label></p>';
		echo '<p data-flow-reminder-legacy ' . ( $custom ? 'hidden' : '' ) . '>یادآوری اول و دوم پس از ۲ و ۲۴ ساعت، و سوم در صورت امکان یک ساعت مانده به مهلت است. متن‌ها در بخش پیامک‌های وضعیت و یادآوری پیش‌فرض هستند.</p><div data-flow-reminder-custom ' . ( $custom ? '' : 'hidden' ) . '><p>برای هر ردیف، زمان و روش ارسال معرف یا مهمان را تعیین کنید.</p>';
		self::rows_editor( (array) ( $settings['reminder_rules'] ?? array() ), ECM_Flow_Reminders::LIMIT, 'افزودن یادآوری', 'یادآوری', static function ( string $index, array $rule ) use ( $profiles ) {
			$prefix = "reminder_rules[{$index}]";
			$offset = (int) ( $rule['offset'] ?? HOUR_IN_SECONDS );
			$unit = 0 === $offset % DAY_IN_SECONDS ? 'day' : ( 0 === $offset % HOUR_IN_SECONDS ? 'hour' : 'minute' );
			$units = array( 'day' => DAY_IN_SECONDS, 'hour' => HOUR_IN_SECONDS, 'minute' => MINUTE_IN_SECONDS );
			echo '<input type="hidden" name="' . esc_attr( $prefix ) . '[id]" value="' . esc_attr( $rule['id'] ?? '' ) . '"><div class="ecm-flow-offset">';
			self::box( $prefix . '[amount]', 'فاصله', intdiv( $offset, $units[ $unit ] ), 'number' );
			echo '<label>واحد <select name="' . esc_attr( $prefix ) . '[unit]">';
			foreach ( array( 'day' => 'روز', 'hour' => 'ساعت', 'minute' => 'دقیقه' ) as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( $value, $unit, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></label><label>مبنا <select name="' . esc_attr( $prefix ) . '[anchor]"><option value="deadline" ' . selected( $rule['anchor'] ?? 'deadline', 'deadline', false ) . '>قبل از پایان مهلت</option><option value="idle" ' . selected( $rule['anchor'] ?? '', 'idle', false ) . '>پس از آخرین ثبت مرحله</option></select></label></div>';
			self::sms_editor( $prefix . '[sms]', $rule['sms'] ?? array(), $profiles, 'reminder_custom' );
		} );
		echo '</div></details>';
	}

	private static function flags(): array {
		return array( 'enabled' => 'فعال‌سازی ثبت‌نام مرحله‌ای', 'edit_enabled' => 'ویرایش مستقل با OTP', 'edit_all' => 'ویرایش همه فیلدهای ثبت‌نام مرحله‌ای به‌جز موبایل معرف', 'edit_after_paid' => 'اجازه ویرایش رویداد عادی پس از پرداخت', 'allow_back' => 'بازگشت قبل از ارسال رسید', 'cancel' => 'انصراف پس از رد یا پیگیری', 'covenant' => 'میثاق‌نامه', 'decision' => 'مسیر قطعی یا نیازمند تماس', 'reference_required' => 'شماره تراکنش ضروری', 'date_required' => 'تاریخ پرداخت ضروری', 'reminders' => 'یادآوری ثبت‌نام نیمه‌تمام' );
	}

	private static function text_fields(): array {
		return array( 'owner' => 'نام صاحب حساب', 'bank' => 'نام بانک', 'card' => 'شماره کارت', 'iban' => 'شبا', 'card_image' => 'آدرس تصویر کارت', 'payment_pdf' => 'آدرس PDF پرداخت', 'covenant_pdf' => 'آدرس PDF میثاق‌نامه' );
	}

	private static function notification_labels(): array {
		return array( 'initial' => 'ثبت اطلاعات اولیه', 'submitted' => 'دریافت رسید', 'confirmed' => 'تأیید نهایی', 'rejected' => 'رد رسید', 'more_info' => 'درخواست اطلاعات تکمیلی', 'cancelled' => 'انصراف', 'followup' => 'درخواست تماس', 'edit' => 'ویرایش اطلاعات', 'guest_added' => 'افزودن مهمان', 'guest_changed' => 'تغییر مهمان', 'guest_removed' => 'حذف مهمان', 'reminder_extra' => 'یادآوری مرحله تکمیلی', 'reminder_covenant' => 'یادآوری میثاق‌نامه', 'reminder_decision' => 'یادآوری تعیین وضعیت', 'reminder_payment' => 'یادآوری ارسال پرداخت / رسید' );
	}

	private static function default_message( string $key ): string {
		return array(
			'initial' => 'اطلاعات اولیه شما ثبت شد. ثبت‌نام قطعی نیست. برای تکمیل: {resume_link}',
			'submitted' => 'اطلاعات پرداخت دریافت شد و در انتظار بررسی است.',
			'confirmed' => 'ثبت‌نام شما نهایی شد. کارت ورود: {card_link}',
			'rejected' => 'رسید شما تأیید نشد. علت: {review_note} · {resume_link}',
			'more_info' => 'اطلاعات تکمیلی لازم است. {review_note} · {resume_link}',
			'followup' => 'درخواست شما به تیم پیگیری ارجاع شد.',
		)[ $key ] ?? ( str_starts_with( $key, 'reminder_' ) ? 'ثبت‌نام شما کامل نشده است. برای ادامه: {resume_link}' : '' );
	}

	public static function save(): void {
		$id = absint( ECM_Flow_Public::param( 'event_id' ) );
		check_admin_referer( 'ecm_flow_save_' . $id );
		if ( ! current_user_can( 'manage_options' ) || ! ECM_Events::event( $id ) ) {
			wp_die( 'دسترسی ندارید.', '', array( 'response' => 403 ) );
		}
		if ( '1' !== ECM_Flow_Public::param( 'ecm_flow_form_complete' ) ) {
			self::settings_error( 'فرم کامل دریافت نشد. تنظیمات قبلی حفظ شد. صفحه را تازه کنید و در صورت تکرار محدودیت max_input_vars هاست را بررسی کنید.' );
		}
		$old = ECM_Flow::settings( $id );
		$settings = array( 'version' => 1 + (int) ( $old['version'] ?? 0 ) );
		foreach ( self::flags() as $key => $label ) {
			$settings[ $key ] = '1' === ECM_Flow_Public::param( $key );
		}
		foreach ( self::text_fields() as $key => $label ) {
			$value = ECM_Flow_Public::param( $key );
			$settings[ $key ] = in_array( $key, array( 'card_image', 'payment_pdf', 'covenant_pdf' ), true ) ? esc_url_raw( $value, array( 'http', 'https' ) ) : $value;
		}
		foreach ( array( 'payment_html', 'covenant_html', 'css', 'js' ) as $key ) {
			$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$settings[ $key ] = match ( $key ) {
				'js' => current_user_can( 'unfiltered_html' ) && false === stripos( $value, '</script' ) ? $value : '',
				'css' => ! str_contains( $value, '<' ) ? $value : '',
				default => wp_kses_post( $value ),
			};
		}
		$settings['receipt_mb'] = min( 8, max( 1, absint( ECM_Flow_Public::param( 'receipt_mb' ) ) ) );
		$profiles = (array) ( ECM_Utils::settings()['sms_pattern_profiles'] ?? array() );
		$settings['otp_profile'] = ECM_SMS::profile_id( ECM_Flow_Public::param( 'otp_profile' ), $profiles );
		$old_otp = ECM_SMS::profile_id( (string) ( $old['otp_profile'] ?? '' ), $profiles );
		if ( $settings['otp_profile'] && $settings['otp_profile'] !== $old_otp ) {
			$param = ECM_SMS::otp_parameter( (array) ( $profiles[ $settings['otp_profile'] ] ?? array() ) );
			if ( is_wp_error( $param ) ) { self::settings_error( $param->get_error_message() ); }
		}
		$settings['support'] = preg_replace( '/[^0-9+]/', '', ECM_Utils::normalize_digits( ECM_Flow_Public::param( 'support' ) ) );
		$settings['reviewers'] = array_values( array_filter( array_map( 'absint', explode( ',', ECM_Flow_Public::param( 'reviewers' ) ) ) ) );
		$settings['edit_keys'] = array_map( 'sanitize_key', array_keys( (array) ( $_POST['edit_keys'] ?? array() ) ) );
		$deadline = ECM_Flow_Public::param( 'reminder_deadline' );
		$settings['reminder_deadline'] = ECM_Utils::local_to_utc( $deadline );
		if ( $deadline && ! $settings['reminder_deadline'] ) { self::settings_error( 'تاریخ و ساعت مهلت معتبر نیست.' ); }
		$settings['extra'] = $settings['templates'] = array();
		$form_config = ECM_Events::config( ECM_Events::event( $id ) );
		$available = array_diff(
			array_column( array_filter( $form_config['fields'], static fn( $field ) => ! in_array( $field['type'], array( 'guest', 'html' ), true ) ), 'key' ),
			array( 'mobile', $form_config['name_field'], $form_config['sms_field'], 'available_cities', 'priority_city' )
		);
		foreach ( array_slice( (array) ( $_POST['extra'] ?? array() ), 0, 8 ) as $stage ) {
			$title = sanitize_text_field( wp_unslash( $stage['title'] ?? '' ) );
			if ( $title ) {
				$fields = array_values( array_intersect( $available, array_map( static fn( $key ) => sanitize_key( trim( $key ) ), explode( ',', (string) ( $stage['fields'] ?? '' ) ) ) ) );
				$available = array_diff( $available, $fields );
				$settings['extra'][] = array( 'title' => $title, 'html' => wp_kses_post( wp_unslash( $stage['html'] ?? '' ) ), 'fields' => $fields );
			}
		}
		foreach ( array( 'details', 'edit', 'payment', 'covenant', 'decision' ) as $key ) {
			$settings['templates'][ $key ] = wp_kses_post( wp_unslash( $_POST['templates'][ $key ] ?? '' ) );
		}
		foreach ( self::notification_labels() as $key => $label ) {
			$settings['sms'][ $key ] = self::sanitize_sms( (array) ( $_POST['sms'][ $key ] ?? array() ), $profiles, $key, $old['sms'][ $key ] ?? array() );
		}
		if ( 'custom' === ECM_Flow_Public::param( 'reminder_mode' ) ) {
			$settings['reminder_rules'] = self::sanitize_reminders( (array) ( $_POST['reminder_rules'] ?? array() ), $profiles, $old['reminder_rules'] ?? array(), $settings['reminder_deadline'] );
		}
		$changed = ( isset( $old['reminder_rules'] ) || isset( $settings['reminder_rules'] ) ) &&
			( ( $old['reminder_rules'] ?? null ) !== ( $settings['reminder_rules'] ?? null ) ||
			( $old['reminder_deadline'] ?? null ) !== $settings['reminder_deadline'] ||
			! empty( $old['reminders'] ) !== $settings['reminders'] );
		$settings['reminder_rules_since'] = $changed ? time() : (int) ( $old['reminder_rules_since'] ?? 0 );
		$settings['reminder_plan_version'] = $changed ? $settings['version'] : (int) ( $old['reminder_plan_version'] ?? 0 );
		update_option( 'ecm_workflow_event_' . $id, $settings, false );
		if ( $changed ) { ECM_Flow_Reminders::queue_replan( $id, $settings['reminder_plan_version'] ); }
		wp_safe_redirect( admin_url( 'admin.php?page=ecm-workflow&event_id=' . $id . '&saved=1' ), 303 );
		exit;
	}

	private static function settings_error( string $message ): void {
		wp_die( esc_html( $message ), 'تنظیمات ذخیره نشد', array( 'response' => 400, 'back_link' => true ) );
	}

	private static function sanitize_sms( array $raw, array $profiles, string $use, array $previous = array() ): array {
		$rules = array();
		foreach ( array( 'primary', 'guest' ) as $audience ) {
			$rule = (array) ( $raw[ $audience ] ?? array() );
			$profile = ECM_SMS::profile_id( (string) ( $rule['pattern_profile'] ?? '' ), $profiles );
			$enabled = ! empty( $rule['enabled'] );
			$text = sanitize_textarea_field( wp_unslash( (string) ( $rule['text'] ?? '' ) ) );
			$same_legacy = 'reminder_custom' !== $use && ! empty( $previous[ $audience ]['enabled'] ) &&
				$profile === ECM_SMS::profile_id( (string) ( $previous[ $audience ]['pattern_profile'] ?? '' ), $profiles );
			if ( $enabled && $profile && ! $same_legacy && ! self::notification_pattern( (array) ( $profiles[ $profile ] ?? array() ), $use ) ) {
				self::settings_error( 'یکی از پترن‌های انتخاب‌شده فعال یا با داده‌های این وضعیت سازگار نیست.' );
			}
			if ( $enabled && ! $profile && '' === trim( $text ) && 'reminder_custom' === $use ) {
				self::settings_error( 'برای گیرندهٔ فعال در هر یادآوری، متن بنویسید یا پترن انتخاب کنید.' );
			}
			$rules[ $audience ] = array( 'enabled' => $enabled, 'text' => $text, 'pattern_profile' => $profile, 'mode' => $profile ? 'pattern' : 'webservice' );
		}
		return $rules;
	}

	private static function sanitize_reminders( array $raw, array $profiles, array $previous, ?string $deadline ): array {
		$rules = array();
		$units = array( 'day' => DAY_IN_SECONDS, 'hour' => HOUR_IN_SECONDS, 'minute' => MINUTE_IN_SECONDS );
		foreach ( array_slice( $raw, 0, ECM_Flow_Reminders::LIMIT ) as $row ) {
			$row = (array) $row;
			$id = (string) ( $row['id'] ?? '' );
			if ( ! isset( $previous[ $id ] ) || isset( $rules[ $id ] ) ) { $id = wp_generate_uuid4(); }
			$anchor = (string) ( $row['anchor'] ?? '' );
			$unit = $units[ (string) ( $row['unit'] ?? '' ) ] ?? 0;
			$amount = filter_var( (string) ( $row['amount'] ?? '' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 525600 ) ) );
			if ( ! in_array( $anchor, array( 'deadline', 'idle' ), true ) || ! $unit || ! $amount || $amount * $unit > YEAR_IN_SECONDS ) {
				self::settings_error( 'برای هر یادآوری یک فاصلهٔ معتبر بین یک دقیقه و یک سال وارد کنید.' );
			}
			if ( 'deadline' === $anchor && ! $deadline ) { self::settings_error( 'برای یادآوری قبل از مهلت، تاریخ و ساعت پایان مهلت را وارد کنید.' ); }
			$sms = self::sanitize_sms( (array) ( $row['sms'] ?? array() ), $profiles, 'reminder_custom' );
			if ( empty( $sms['primary']['enabled'] ) && empty( $sms['guest']['enabled'] ) ) { self::settings_error( 'برای هر یادآوری حداقل یک گیرنده را فعال کنید.' ); }
			$rules[ $id ] = array( 'id' => $id, 'anchor' => $anchor, 'offset' => $amount * $unit, 'sms' => $sms );
		}
		return $rules;
	}

	private static function event_options( array $events, int $selected ): void {
		foreach ( $events as $event ) {
			echo '<option value="' . (int) $event->id . '" ' . selected( $selected, $event->id, false ) . '>' . esc_html( $event->title ) . '</option>';
		}
	}

	private static function events(): array {
		return array_values( array_filter( ECM_Events::all(), static fn( $event ) => self::can_review( (int) $event->id ) ) );
	}

	private static function columns(): array {
		return array( 'event' => 'دوره', 'public_id' => 'کد پیگیری', 'name' => 'نام', 'phone' => 'موبایل', 'state' => 'وضعیت ثبت‌نام', 'receipt' => 'وضعیت رسید', 'residence' => 'شهر محل سکونت', 'priority_city' => 'شهر اولویت', 'cities' => 'شهرهای قابل حضور', 'consent' => 'پذیرش میثاق‌نامه', 'followup' => 'دلیل پیگیری', 'source' => 'مسیر ثبت', 'updated_at' => 'آخرین تغییر', 'guests' => 'تعداد مهمان' );
	}

	private static function filters(): array {
		$keys = array( 'state', 'residence', 'priority_city', 'city', 'search', 'source', 'receipt_state', 'test', 'from', 'to' );
		$filters = array();
		foreach ( $keys as $key ) {
			$raw = $_GET[ $key ] ?? '';
			$filters[ $key ] = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$filters['test'] = '';
		}
		return $filters;
	}

	private static function rows( array $event_ids, array $filters, int $limit, int $offset ): array {
		global $wpdb;
		$table = ECM_DB::table( 'workflow' );
		$registrations = ECM_DB::table( 'registrations' );
		$receipts = ECM_DB::table( 'workflow_receipts' );
		$where = ECM_Report_Scope::condition( 'w.event_id', $event_ids ) . ' AND r.is_test=%d';
		$args = array( 'test' === $filters['test'] ? 1 : 0 );
		foreach ( array( 'state', 'residence', 'priority_city', 'source' ) as $key ) {
			if ( '' !== $filters[ $key ] ) {
				$where .= " AND w.{$key}=%s";
				$args[] = $filters[ $key ];
			}
		}
		if ( $filters['city'] ) {
			$where .= ' AND w.available_cities LIKE %s';
			$args[] = '%' . $wpdb->esc_like( wp_json_encode( $filters['city'], JSON_UNESCAPED_UNICODE ) ) . '%';
		}
		if ( $filters['receipt_state'] ) {
			$where .= ' AND COALESCE(p.status,\'none\')=%s';
			$args[] = $filters['receipt_state'];
		}
		foreach ( array( 'from' => array( '>=', '00:00:00' ), 'to' => array( '<=', '23:59:59' ) ) as $key => list( $operator, $time ) ) {
			$utc = $filters[ $key ] ? ECM_Utils::local_to_utc( $filters[ $key ] . ' ' . $time ) : null;
			if ( $utc ) {
				$where .= " AND w.updated_at {$operator}%s";
				$args[] = $utc;
			}
		}
		if ( $filters['search'] ) {
			$where .= ' AND (r.public_id LIKE %s OR r.form_data LIKE %s)';
			$needle = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$args[] = $needle;
			$args[] = $needle;
		}
		$field_conditions = array();
		ECM_Report_Scope::field_sql( ECM_Report_Scope::form_filters( $event_ids ), 'r.form_data', $field_conditions, $args );
		foreach ( $field_conditions as $condition ) { $where .= ' AND ' . $condition; }
		$args[] = $limit;
		$args[] = $offset;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT w.*,r.public_id,r.form_data,COALESCE(p.status,'none') receipt_state FROM {$table} w INNER JOIN {$registrations} r ON r.id=w.registration_id LEFT JOIN {$receipts} p ON p.id=w.receipt_id WHERE {$where} ORDER BY w.registration_id DESC LIMIT %d OFFSET %d", $args ) );
	}

	private static function values( object $row ): array {
		$form = ECM_Flow::decode( $row->form_data );
		$config = ECM_Flow::decode( $row->snapshot );
		$details = ECM_Flow::decode( $row->details );
		return array(
			'event' => ECM_Events::event( (int) $row->event_id )->title ?? '', 'public_id' => $row->public_id, 'name' => ECM_Utils::field_value( $form, $config['form']['name_field'] ?? 'full_name' ),
			'phone' => $form['mobile'] ?? '', 'state' => ECM_Flow::states()[ $row->state ] ?? $row->state,
			'receipt' => 'none' === $row->receipt_state ? 'ارسال نشده' : ( ECM_Flow::states()[ $row->receipt_state ] ?? $row->receipt_state ),
			'residence' => $row->residence, 'priority_city' => $row->priority_city, 'cities' => implode( '، ', ECM_Flow::decode( $row->available_cities ) ),
			'consent' => $details['consent']['at'] ?? '', 'followup' => $details['followup']['reason'] ?? '', 'source' => $row->source,
			'updated_at' => ECM_Utils::utc_to_local( $row->updated_at ), 'guests' => (string) count( $details['guests'] ?? array() ),
		);
	}

	private static function selected_columns(): array {
		$requested = isset( $_GET['flow_cols'] ) ? array_map( 'sanitize_key', (array) $_GET['flow_cols'] ) : array_keys( self::columns() );
		return array_intersect_key( self::columns(), array_flip( $requested ) ) ?: self::columns();
	}

	public static function report( bool $admin ): string {
		$events = self::events();
		$ids = ECM_Report_Scope::ids( $events );
		$id = $ids[0] ?? 0;
		if ( ! ECM_Flow::ready() || ! $ids ) {
			return '<p>دسترسی بررسی پرونده برای این رویداد تعریف نشده است.</p>';
		}
		$filters = self::filters();
		$columns = self::selected_columns();
		$filter_args = ECM_Report_Scope::args( $ids ) + array( 'flow_cols' => array_keys( $columns ), 'ecm_field_filter' => ECM_Report_Scope::form_filters( $ids ) ) + array_filter( $filters );
		$self = ECM_Reports::url( 'workflow', $filter_args, $admin );
		$detail = absint( $_GET['flow_id'] ?? 0 );
		if ( $detail ) {
			$row = ECM_Flow::row( $detail );
			if ( ! $row || ! in_array( (int) $row->event_id, $ids, true ) || ! self::can_review( (int) $row->event_id ) ) {
				return '<p>پرونده در دسترس نیست.</p>';
			}
			return self::detail( $row, $self );
		}
		$page = max( 1, absint( $_GET['flow_page'] ?? 1 ) );
		$rows = self::rows( $ids, $filters, 51, ( $page - 1 ) * 50 );
		$export = wp_nonce_url( add_query_arg( array( 'action' => 'ecm_flow_export', 'event_id' => $id ) + $filter_args, admin_url( 'admin-post.php' ) ), 'ecm_flow_export_' . $id );
		$base = ECM_Reports::url( 'workflow', array(), $admin );
		ob_start();
		echo '<section class="ecm-report-card"><h2>پرونده‌های ثبت‌نام مرحله‌ای</h2><form method="get" action="' . esc_url( strtok( $base, '?' ) ) . '">';
		foreach ( wp_parse_args( wp_parse_url( $base, PHP_URL_QUERY ) ?: '' ) as $key => $value ) {
			if ( ! in_array( $key, array( 'ecm_event_selection', 'ecm_events' ), true ) && is_scalar( $value ) ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
		}
		echo ECM_Report_Scope::checklist( $events, $ids ) . ECM_Report_Scope::field_inputs( $ids );
		echo '<label>وضعیت <select name="state"><option value="">همه</option>';
		foreach ( ECM_Flow::states() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $filters['state'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		foreach ( array( 'residence' => 'شهر سکونت', 'priority_city' => 'شهر اولویت', 'city' => 'شهر قابل حضور', 'search' => 'نام، تلفن یا کد', 'source' => 'مسیر ثبت (public/manual)', 'receipt_state' => 'وضعیت رسید (none/review/rejected/confirmed)' ) as $key => $label ) {
			self::box( $key, $label, $filters[ $key ] );
		}
		self::box( 'from', 'از تاریخ آخرین تغییر', $filters['from'], 'date' );
		self::box( 'to', 'تا تاریخ آخرین تغییر', $filters['to'], 'date' );
		if ( current_user_can( 'manage_options' ) ) {
			echo '<label>نوع <select name="test"><option value="">اصلی</option><option value="test" ' . selected( $filters['test'], 'test', false ) . '>تستی</option></select></label>';
		}
		echo '<details><summary>انتخاب ستون‌ها</summary>';
		foreach ( self::columns() as $key => $label ) {
			echo '<label><input type="checkbox" name="flow_cols[]" value="' . esc_attr( $key ) . '" ' . checked( isset( $columns[ $key ] ), true, false ) . '>' . esc_html( $label ) . '</label> ';
		}
		echo '</details><button class="ecm-button">اعمال فیلتر</button></form>';
		echo '<p><a href="' . esc_url( $export ) . '">CSV</a>';
		if ( class_exists( 'ZipArchive' ) ) {
			echo ' · <a href="' . esc_url( add_query_arg( 'mode', 'xlsx', $export ) ) . '">Excel</a>';
		}
		echo ' · <a target="_blank" rel="noopener" href="' . esc_url( add_query_arg( 'mode', 'print', $export ) ) . '">چاپ / PDF</a></p>';
		echo ECM_Report_Scope::metrics( $ids );
		foreach ( $ids as $event_id ) { self::city_stats( $event_id, $filters['test'] ); }
		self::table( array_slice( $rows, 0, 50 ), $columns, $self );
		if ( $page > 1 ) {
			echo '<a href="' . esc_url( add_query_arg( 'flow_page', $page - 1, $self ) ) . '">صفحه قبل</a> ';
		}
		if ( count( $rows ) > 50 ) {
			echo '<a href="' . esc_url( add_query_arg( 'flow_page', $page + 1, $self ) ) . '">صفحه بعد</a>';
		}
		return (string) ob_get_clean() . '</section>';
	}

	private static function table( array $rows, array $columns, string $link = '' ): void {
		echo '<div class="ecm-table-scroll"><table class="widefat striped ecm-report-table"><thead><tr>';
		foreach ( $columns as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo $link ? '<th>پرونده</th>' : '';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$values = self::values( $row );
			echo '<tr>';
			foreach ( $columns as $key => $label ) {
				echo '<td>' . esc_html( $values[ $key ] ?? '' ) . '</td>';
			}
			if ( $link ) {
				echo '<td><a href="' . esc_url( add_query_arg( 'flow_id', $row->registration_id, $link ) ) . '">مشاهده</a></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function city_stats( int $id, string $test ): void {
		global $wpdb;
		$flow = ECM_DB::table( 'workflow' );
		$registration = ECM_DB::table( 'registrations' );
		$stats = array();
		$multi = 0;
		$after = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT w.registration_id,w.available_cities,w.priority_city FROM {$flow} w INNER JOIN {$registration} r ON r.id=w.registration_id WHERE w.event_id=%d AND w.registration_id>%d AND r.is_test=%d AND w.state NOT IN ('details','cancelled') ORDER BY w.registration_id LIMIT 500",
				$id, $after, 'test' === $test ? 1 : 0
			) );
			foreach ( $rows as $row ) {
				$after = (int) $row->registration_id;
				$cities = array_unique( array_filter( ECM_Flow::decode( $row->available_cities ), 'is_string' ) );
				if ( count( $cities ) > 1 ) {
					++$multi;
				}
				foreach ( $cities as $city ) {
					$stats[ $city ]['available'] = 1 + ( $stats[ $city ]['available'] ?? 0 );
				}
				if ( $row->priority_city ) {
					$stats[ $row->priority_city ]['priority'] = 1 + ( $stats[ $row->priority_city ]['priority'] ?? 0 );
				}
			}
		} while ( count( $rows ) === 500 );
		if ( $stats ) {
			echo '<details><summary>آمار شهرها در این رویداد · امکان حضور در چند شهر: ' . (int) $multi . '</summary><table><thead><tr><th>شهر</th><th>امکان حضور</th><th>اولویت</th></tr></thead><tbody>';
			foreach ( $stats as $city => $counts ) {
				echo '<tr><td>' . esc_html( (string) $city ) . '</td><td>' . (int) ( $counts['available'] ?? 0 ) . '</td><td>' . (int) ( $counts['priority'] ?? 0 ) . '</td></tr>';
			}
			echo '</tbody></table></details>';
		}
	}

	private static function detail( object $row, string $link ): string {
		$id = (int) $row->registration_id;
		$registration = ECM_DB::registration( $id );
		$data = ECM_DB::decode_data( $registration );
		$info = ECM_Flow::decode( $row->details );
		$data = (array) ( $info['form'] ?? $data );
		$config = ECM_Flow::decode( $row->snapshot );
		ob_start();
		wp_enqueue_script( 'ecm-flow', ECM_URL . 'assets/js/flow.js', array(), ECM_VERSION, true );
		echo '<section class="ecm-report-card"><p><a href="' . esc_url( $link ) . '">بازگشت به پرونده‌ها</a></p><h2>' . esc_html( $registration->public_id . ' · ' . ( ECM_Flow::states()[ $row->state ] ?? $row->state ) ) . '</h2><dl>';
		foreach ( (array) ( $config['form']['fields'] ?? array() ) as $field ) {
			if ( isset( $data[ $field['key'] ] ) ) {
				echo '<dt>' . esc_html( $field['label'] ) . '</dt><dd>' . esc_html( implode( '، ', (array) $data[ $field['key'] ] ) ) . '</dd>';
			}
		}
		foreach ( (array) ( $info['guests'] ?? array() ) as $guest ) {
			echo '<dt>مهمان</dt><dd>' . esc_html( $guest['name'] . ' · ' . $guest['phone'] ) . '</dd>';
		}
		echo '</dl><p>موبایل تأییدشده: ' . esc_html( $data['mobile'] ?? '' ) . '</p>';
		echo '<p>میثاق‌نامه: ' . esc_html( isset( $info['consent'] ) ? $info['consent']['name'] . ' · ' . $info['consent']['at'] . ' UTC · نسخه ' . $info['consent']['version'] : 'پذیرش ثبت نشده' ) . '</p>';
		if ( ! empty( $info['followup'] ) ) {
			echo '<p>پیگیری: ' . esc_html( $info['followup']['reason'] . ' · ' . $info['followup']['notes'] ) . '</p>';
		}
		foreach ( (array) ( $info['contacts'] ?? array() ) as $contact ) {
			echo '<p>' . esc_html( $contact['at'] . ' UTC · ' . $contact['note'] ) . '</p>';
		}
		foreach ( (array) ( $info['replies'] ?? array() ) as $reply ) {
			echo '<p>پاسخ متقاضی برای رسید ' . (int) $reply['receipt_id'] . ' · ' . esc_html( $reply['text'] ) . '</p>';
		}
		echo '<h3>رسیدها</h3>';
		foreach ( ECM_Flow_Receipts::history( $id ) as $receipt ) {
			$url = ECM_Flow_Receipts::url( (int) $receipt->id );
			echo '<p><a data-ecm-receipt-preview target="_blank" rel="noopener" href="' . esc_url( $url ) . '">پیش‌نمایش رسید #' . (int) $receipt->id . '</a> · ' . esc_html( $receipt->reference . ' · ' . $receipt->payment_date . ' · ' . ( ECM_Flow::states()[ $receipt->status ] ?? $receipt->status ) . ' · ' . $receipt->note ) . '</p>';
		}
		$choices = match ( $row->state ) {
			'review' => array( 'approve' => 'تأیید و صدور کارت', 'reject' => 'رد رسید', 'more_info' => 'درخواست اطلاعات تکمیلی' ),
			'followup' => array( 'contact' => 'ثبت نتیجه تماس' ),
			'confirmed' => array( 'resend' => 'ارسال مجدد پیامک تأیید', 'revoke' => 'لغو کارت‌ها و QR', 'reissue' => 'صدور دوباره کارت‌ها' ),
			default => array(),
		};
		if ( $choices ) {
			self::post_open( 'review', (int) $row->event_id, 'ecm_flow_review_' . $id );
			echo '<input type="hidden" name="registration_id" value="' . $id . '"><input type="hidden" name="revision" value="' . (int) $row->revision . '"><input type="hidden" name="return_to" value="' . esc_attr( add_query_arg( 'flow_id', $id, $link ) ) . '">';
			self::box( 'note', 'توضیح (برای رد یا درخواست تکمیل الزامی)', '', 'textarea' );
			foreach ( $choices as $key => $label ) {
				echo '<button class="ecm-button" name="command" value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button> ';
			}
			echo '</form>';
		}
		global $wpdb;
		$audit = ECM_DB::table( 'events' );
			$history = $wpdb->get_results( $wpdb->prepare( "SELECT event_type,actor_user_id,created_at,meta FROM {$audit} WHERE registration_id=%d ORDER BY id DESC LIMIT 50", $id ) );
		$edits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE registration_id=%d AND event_type='workflow_edit_workflow'", $id ) );
		echo '<section class="ecm-edit-summary"><span><strong>ویرایش‌های متقاضی</strong> · ' . $edits . '</span></section>';
		echo '<details><summary>تاریخچه عملیاتی (۵۰ مورد اخیر)</summary><ul>';
		foreach ( $history as $item ) {
			$label = $item->event_type;
			if ( 'workflow_edit_workflow' === $item->event_type ) {
				$meta = ECM_Flow::decode( $item->meta );
				$names = array_column( (array) $config['form']['fields'], 'label', 'key' );
				$names['followup'] = 'درخواست تماس';
				$fields = array_map( static fn( $key ) => $names[ $key ] ?? $key, (array) ( $meta['fields'] ?? array() ) );
				if ( ! empty( $meta['guests_changed'] ) ) { $fields[] = 'مهمان‌ها'; }
				if ( ! empty( $meta['receipt_changed'] ) ) { $fields[] = 'رسید'; }
				$label = 'ویرایش متقاضی' . ( $fields ? ' · ' . implode( '، ', $fields ) : '' );
			}
			echo '<li>' . esc_html( ECM_Utils::utc_to_local( $item->created_at ) . ' · ' . $label ) . '</li>';
		}
		return (string) ob_get_clean() . '</ul></details></section>';
	}

	public static function review(): void {
		$id = absint( ECM_Flow_Public::param( 'registration_id' ) );
		check_admin_referer( 'ecm_flow_review_' . $id );
		$row = ECM_Flow::row( $id );
		if ( ! $row || ! self::can_review( (int) $row->event_id ) ) {
			wp_die( 'دسترسی ندارید.', '', array( 'response' => 403 ) );
		}
		$command = sanitize_key( ECM_Flow_Public::param( 'command' ) );
		$note = ECM_Flow_Public::param( 'note', true );
		$revision = absint( ECM_Flow_Public::param( 'revision' ) );
		if ( (int) $row->revision !== $revision ) {
			wp_die( 'پرونده تغییر کرده است. صفحه را تازه کنید.', '', array( 'response' => 409, 'back_link' => true ) );
		}
		if ( in_array( $command, array( 'reject', 'more_info', 'contact' ), true ) && '' === $note ) {
			wp_die( 'توضیح را وارد کنید.', '', array( 'response' => 400, 'back_link' => true ) );
		}
		if ( 'confirmed' === $row->state && 'resend' === $command ) {
			ECM_Flow::notify( $row, 'confirmed', 'manual-' . gmdate( 'YmdHi' ) );
			ECM_Utils::log( $id, 'workflow_sms_resend' );
		} elseif ( 'confirmed' === $row->state && in_array( $command, array( 'revoke', 'reissue' ), true ) ) {
			$result = self::tickets( $row, $command );
		} elseif ( in_array( $command, array( 'approve', 'reject', 'more_info', 'contact' ), true ) ) {
			$result = ECM_Flow::change( $id, $revision, $command, array( 'note' => $note ) );
		} else {
			wp_die( 'عملیات معتبر نیست.', '', array( 'response' => 400 ) );
		}
		if ( isset( $result ) && is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409, 'back_link' => true ) );
		}
		wp_safe_redirect( ECM_Flow_Public::param( 'return_to' ) ?: ECM_Reports::url( 'workflow', array( 'ecm_event' => $row->event_id ), true ), 303 );
		exit;
	}

	private static function tickets( object $row, string $command ) {
		global $wpdb;
		$id = (int) $row->registration_id;
		return ECM_Flow::atomic( static function () use ( $wpdb, $id, $row, $command ) {
			$fresh = ECM_Flow::row( $id, true );
			if ( ! $fresh || (int) $fresh->revision !== (int) $row->revision || 'confirmed' !== $fresh->state ) {
				return new WP_Error( 'stale', 'پرونده تغییر کرده است.' );
			}
			$details = ECM_Flow::decode( $fresh->details );
			$details['ticket_revoked'] = 'revoke' === $command;
			if ( 'revoke' === $command ) {
				ECM_Flow::write( $wpdb->update( ECM_DB::table( 'ticket_tokens' ), array( 'status' => 'revoked' ), array( 'registration_id' => $id ) ) );
			}
			ECM_Flow::write( $wpdb->update( ECM_DB::table( 'workflow' ), array( 'details' => wp_json_encode( $details ), 'revision' => (int) $row->revision + 1 ), array( 'registration_id' => $id ) ) );
			if ( 'reissue' === $command ) {
				ECM_Flow::write( $wpdb->update( ECM_DB::table( 'ticket_tokens' ), array( 'status' => 'revoked' ), array( 'registration_id' => $id ) ) );
				foreach ( ECM_DB::participants_for_registration( $id ) as $participant ) {
					if ( ! ECM_Tickets::card_url( $participant ) || ! ECM_Tickets::attendance_url( $participant ) ) {
						return new WP_Error( 'ticket', 'صدور کارت جدید انجام نشد.' );
					}
				}
			}
			ECM_Utils::log( $id, 'workflow_ticket_' . $command );
			return true;
		} );
	}

	public static function export(): void {
		$id = absint( $_GET['event_id'] ?? 0 );
		check_admin_referer( 'ecm_flow_export_' . $id );
		if ( ! ECM_Flow::ready() || ! self::can_review( $id ) ) {
			wp_die( 'دسترسی ندارید.', '', array( 'response' => 403 ) );
		}
		nocache_headers();
		$ids = ECM_Report_Scope::ids( self::events(), $id );
		if ( ! $ids ) { wp_die( 'دوره‌ای برای دریافت گزارش انتخاب نشده است.' ); }
		$columns = self::selected_columns();
		$filters = self::filters();
		if ( 'xlsx' === ( $_GET['mode'] ?? '' ) ) {
			ECM_XLSX::download( 'ecm-workflow-' . $id, self::workbook_rows( $ids, $filters, $columns ) );
		}
		$print = 'print' === ( $_GET['mode'] ?? '' );
		if ( $print ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="UTF-8"><title>گزارش پرونده‌ها</title><style>table{border-collapse:collapse}td,th{border:1px solid #aaa;padding:.35em}@media print{button{display:none}}</style><button onclick="print()">چاپ / PDF</button>';
		} else {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="ecm-workflow-' . $id . '.csv"' );
			$out = fopen( 'php://output', 'w' );
			fwrite( $out, "\xEF\xBB\xBF" );
			fputcsv( $out, array_values( $columns ), ',', '"', '' );
		}
		for ( $offset = 0; ; $offset += 250 ) {
			$rows = self::rows( $ids, $filters, 250, $offset );
			if ( ! $rows ) {
				break;
			}
			if ( $print ) {
				self::table( $rows, $columns );
			} else {
				foreach ( $rows as $row ) {
					$values = self::values( $row );
					$cells = array_map( static fn( $key ) => self::csv_cell( (string) ( $values[ $key ] ?? '' ) ), array_keys( $columns ) );
					fputcsv( $out, $cells, ',', '"', '' );
				}
			}
		}
		exit;
	}

	public static function csv_cell( string $value ): string {
		return preg_match( '/^[\s\x{FEFF}]*[=+@-]/u', $value ) || preg_match( '/^[\t\r\n]/', $value ) ? "'" . $value : $value;
	}

	private static function workbook_rows( array $ids, array $filters, array $columns ): Generator {
		yield array_values( $columns );
		for ( $offset = 0; ; $offset += 250 ) {
			$rows = self::rows( $ids, $filters, 250, $offset );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$values = self::values( $row );
				yield array_map( static fn( $key ) => $values[ $key ] ?? '', array_keys( $columns ) );
			}
		}
	}

	public static function manual(): void {
		ECM_Flow_Edit::manual();
	}
}
