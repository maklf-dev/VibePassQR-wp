<?php

defined( 'ABSPATH' ) || exit;

/** Administrator-only, short-lived real-card test records (excluded from live reports). */
final class ECM_Test_Registration {
	private const MAX_AGE = 72 * HOUR_IN_SECONDS;

	public static function hooks(): void {
		add_action( 'admin_post_ecm_create_test_registration', array( __CLASS__, 'create' ) );
		add_action( 'admin_post_ecm_delete_test_registration', array( __CLASS__, 'delete' ) );
		add_action( 'ecm_cleanup_old_test_registrations', array( __CLASS__, 'cleanup' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cleanup' ), 30 );
	}

	private static function allowed(): bool {
		return ECM_Registration_Admin::is_site_administrator();
	}

	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'ecm_cleanup_old_test_registrations' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'ecm_cleanup_old_test_registrations' );
		}
	}

	public static function page(): void {
		if ( ! self::allowed() ) {
			wp_die( 'دسترسی فقط برای مدیر کل مجاز است.', 'دسترسی غیرمجاز', array( 'response' => 403 ) );
		}
		$events = array_values( array_filter( ECM_Events::all(), static function ( object $event ): bool {
			return ECM_Events::allows( $event, 'card' ) && ECM_Events::allows( $event, 'attendance' );
		} ) );
		$event_id = absint( $_GET['event_id'] ?? ( $events ? $events[0]->id : 0 ) );
		$selected = ECM_Events::event( $event_id );
		if ( ! $selected || ! in_array( $event_id, array_map( static fn( $event ) => (int) $event->id, $events ), true ) ) {
			$selected = $events[0] ?? null;
		}
		$test_rows = self::recent( 25 );
		?>
		<div class="wrap ecm-admin ecm-test-tool" dir="rtl">
			<div class="ecm-admin__header"><div><span>TEST SANDBOX</span><h1>ثبت‌نام تستی مدیر</h1><p>بدون مراجعه به فرم عمومی. کارت و QR واقعی اما رکورد آزمایشی، بدون پیامک و خارج از گزارش اصلی.</p></div></div>
			<?php if ( isset( $_GET['ecm_test_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( self::error_label( sanitize_key( wp_unslash( $_GET['ecm_test_error'] ) ) ) ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['ecm_test_created'] ) ) : ?><div class="notice notice-success"><p>ثبت‌نام آزمایشی ساخته شد. لینک‌های کارت و حضور در جدول پایین آماده‌اند.</p></div><?php endif; ?>
			<div class="ecm-subpanel"><h2>ساخت کارت آزمایشی جدید</h2><p>با بسته‌بودن ثبت‌نام عمومی نیز کار می‌کند. فقط رویدادهایی فهرست می‌شوند که کارت و حضورشان فعال است. از شماره‌های آزمایشی جدید استفاده کنید؛ شماره‌های موجود در بانک اشخاص پذیرفته نمی‌شوند.</p>
			<?php if ( ! $events ) : ?><p class="notice notice-warning">رویداد منتشرشده و فعالی با مجوز کارت و حضور پیدا نشد.</p><?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecm_create_test_registration"><?php wp_nonce_field( 'ecm_create_test_registration' ); ?>
				<div class="ecm-admin-grid ecm-admin-grid--2">
				<label class="ecm-setting"><span>رویداد</span><select name="event_id" required><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr( $event->id ); ?>" <?php selected( $selected && (int) $selected->id === (int) $event->id ); ?>><?php echo esc_html( $event->title ); ?></option><?php endforeach; ?></select></label>
				<label class="ecm-setting"><span>نام آزمایشی</span><input type="text" name="test_name" value="تست مدیر" maxlength="190" required></label>
				<label class="ecm-setting"><span>موبایل آزمایشی جدید</span><input type="tel" name="test_phone" placeholder="09xxxxxxxxx" inputmode="tel" required><small>شماره نباید متعلق به هیچ شخص ثبت‌شده باشد؛ پیامکی ارسال نمی‌شود.</small></label>
				</div>
				<h3>مهمان آزمایشی (اختیاری)</h3><div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>نام مهمان</span><input type="text" name="guest_name" maxlength="190"></label><label class="ecm-setting"><span>موبایل مهمان</span><input type="tel" name="guest_phone" placeholder="09xxxxxxxxx"></label></div>
				<button type="submit" class="button button-primary">ایجاد کارت تستی</button>
			</form><?php endif; ?></div>
			<div class="ecm-subpanel"><h2>آخرین ثبت‌نام‌های تستی</h2><p>برای جلوگیری از انباشته‌شدن اطلاعات آزمایشی، رکوردهای تستی بعد از ۷۲ ساعت در اجرای دوره‌ای WP-Cron پاکسازی می‌شوند. حذف دستی نیز ممکن است.</p>
			<div class="ecm-table-scroll"><table class="widefat striped"><thead><tr><th>شناسه</th><th>رویداد</th><th>زمان</th><th>کارت معرف</th><th>QR حضور</th><th>عملیات</th></tr></thead><tbody>
			<?php if ( ! $test_rows ) : ?><tr><td colspan="6">هنوز رکورد تستی وجود ندارد.</td></tr><?php endif; ?>
			<?php foreach ( $test_rows as $row ) : $primary = ECM_DB::primary_participant( (int) $row->id ); $card = $primary ? ECM_Tickets::card_url( $primary ) : ''; $attendance = $primary ? ECM_Tickets::attendance_url( $primary ) : ''; ?>
			<tr><td><code><?php echo esc_html( $row->public_id ); ?></code></td><td><?php echo esc_html( $row->event_title ?: 'رویداد' ); ?></td><td><?php echo esc_html( ECM_Utils::utc_to_local( $row->created_at ) ); ?></td><td><?php if ( $card ) : ?><a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( $card ); ?>">نمایش کارت</a><?php endif; ?></td><td><?php if ( $attendance ) : ?><a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( $attendance ); ?>">بررسی QR</a><?php endif; ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('این ثبت‌نام تستی و حضورهای وابسته حذف شوند؟')"><input type="hidden" name="action" value="ecm_delete_test_registration"><input type="hidden" name="registration_id" value="<?php echo esc_attr( $row->id ); ?>"><?php wp_nonce_field( 'ecm_delete_test_registration_' . (int) $row->id ); ?><button class="button button-small" type="submit">حذف کامل</button></form></td></tr>
			<?php endforeach; ?></tbody></table></div><p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ecm-reports&ecm_view=registrations&ecm_test=test' ) ); ?>">مشاهده گزارش ثبت‌نام‌های تستی</a></p></div>
		</div>
		<?php
	}

	private static function error_label( string $code ): string {
		$errors = array(
			'event' => 'رویداد برای ساخت کارت آزمایشی در دسترس نیست.',
			'mobile' => 'شماره موبایل آزمایشی معتبر نیست.',
			'exists' => 'این شماره از قبل در اطلاعات اشخاص ثبت شده است. برای تست از شماره جدید استفاده کنید.',
			'guest' => 'نام و موبایل مهمان باید هر دو معتبر باشند و رویداد ظرفیت مهمان داشته باشد.',
			'database' => 'ذخیره اطلاعات آزمایشی انجام نشد. رکورد ناقص پاک شد.',
			'busy' => 'عملیات آزمایشی دیگری در حال اجراست. دوباره تلاش کنید.',
		);
		return $errors[ $code ] ?? 'ساخت ثبت‌نام آزمایشی کامل نشد.';
	}

	private static function recent( int $limit ): array {
		global $wpdb;
		$r = ECM_DB::table( 'registrations' );
		$e = ECM_DB::table( 'event_definitions' );
				$audit = ECM_DB::table( 'events' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT r.*,e.title event_title FROM {$r} r LEFT JOIN {$e} e ON e.id=r.event_id WHERE r.is_test=1 AND EXISTS (SELECT 1 FROM {$audit} ev WHERE ev.registration_id=r.id AND ev.event_type='admin_test_created') ORDER BY r.id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function error_redirect( string $code ): void {
		wp_safe_redirect( add_query_arg( 'ecm_test_error', $code, admin_url( 'admin.php?page=ecm-test-registration' ) ) );
		exit;
	}

	public static function create(): void {
		if ( ! self::allowed() ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'ecm_create_test_registration' );
		$event = ECM_Events::event( absint( $_POST['event_id'] ?? 0 ) );
		if ( ! ECM_Events::allows( $event, 'card' ) || ! ECM_Events::allows( $event, 'attendance' ) ) {
			self::error_redirect( 'event' );
		}
		$config = ECM_Events::config( $event );
		$name = sanitize_text_field( wp_unslash( $_POST['test_name'] ?? '' ) );
		$phone = ECM_Utils::normalize_mobile( sanitize_text_field( wp_unslash( $_POST['test_phone'] ?? '' ) ) );
		if ( ! $name || ! $phone ) {
			self::error_redirect( 'mobile' );
		}
		$guest_name = sanitize_text_field( wp_unslash( $_POST['guest_name'] ?? '' ) );
		$guest_phone = ECM_Utils::normalize_mobile( sanitize_text_field( wp_unslash( $_POST['guest_phone'] ?? '' ) ) );
		$guests = array();
		if ( $guest_name || $guest_phone ) {
			if ( ! $guest_name || ! $guest_phone || $guest_phone === $phone || (int) $event->guest_limit < 1 ) {
				self::error_redirect( 'guest' );
			}
			$guests[] = array( 'name' => $guest_name, 'phone' => $guest_phone );
		}
		global $wpdb;
		$people = ECM_DB::table( 'people' );
		$phones = array_merge( array( $phone ), array_column( $guests, 'phone' ) );
		$hashes = array_map( static fn( string $mobile ): string => hash_hmac( 'sha256', $mobile, wp_salt( 'auth' ) ), $phones );
		// Avoid modifying existing identities, even if a test reuses a live phone.
		foreach ( $hashes as $hash ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$people} WHERE phone_hash=%s LIMIT 1", $hash ) ) ) {
				self::error_redirect( 'exists' );
			}
		}
		// Keep the admin path isolated: no public POST, payment processing or SMS hooks.
		$secret = wp_generate_password( 40, false, false );
		$data = array( (string) $config['name_field'] => $name, (string) $config['sms_field'] => $phone );
		if ( 'mobile' !== (string) $config['sms_field'] ) { $data['mobile'] = $phone; }
		$now = ECM_Utils::now_utc();
		$inserted = $wpdb->insert(
			ECM_DB::table( 'registrations' ),
			array(
				'public_id' => ECM_Utils::public_id(),
				'event_id' => (int) $event->id,
				'is_test' => 1,
				'dedupe_phone_hash' => null,
				'form_data' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
				'amount_original' => '0', 'currency_label' => 'ریال', 'exchange_rate' => '1', 'amount_rial' => 0,
				'payment_status' => 'not_required', 'access_hash' => wp_hash_password( $secret ),
				'created_at' => $now, 'updated_at' => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			self::error_redirect( 'database' );
		}
		$id = (int) $wpdb->insert_id;
		$primary = ECM_Events::ensure_registration_entities( $id, $data );
		if ( ! $primary ) {
			ECM_Participants::cleanup_registration( $id );
			self::error_redirect( 'database' );
		}
		if ( $guests ) {
			$created = ECM_Participants::create_guests( $id, $guests );
			if ( is_wp_error( $created ) ) {
				ECM_Participants::cleanup_registration( $id );
				self::error_redirect( 'database' );
			}
		}
		// Access is granted by the existing signed short-link route when opened.
		ECM_Utils::log( $id, 'admin_test_created', array( 'is_test' => true, 'created_by_user_id' => get_current_user_id() ) );
		ECM_Diagnostics::record( 'registration_admin', 'Temporary test registration created', array( 'registration_id' => $id, 'event_id' => (int) $event->id ) );
		wp_safe_redirect( add_query_arg( 'ecm_test_created', $id, admin_url( 'admin.php?page=ecm-test-registration' ) ) );
		exit;
	}

	public static function delete(): void {
		if ( ! self::allowed() ) {
			wp_die( 'Forbidden', 403 );
		}
		$id = absint( $_POST['registration_id'] ?? 0 );
		check_admin_referer( 'ecm_delete_test_registration_' . $id );
		ECM_Registration_Admin::delete_test_registrations( array( $id ) );
		wp_safe_redirect( admin_url( 'admin.php?page=ecm-test-registration' ) );
		exit;
	}

	public static function cleanup(): void {
		global $wpdb;
		$table = ECM_DB::table( 'registrations' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::MAX_AGE );
		$audit = ECM_DB::table( 'events' );
		$ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT r.id FROM {$table} r WHERE r.is_test=1 AND r.created_at<%s AND EXISTS (SELECT 1 FROM {$audit} ev WHERE ev.registration_id=r.id AND ev.event_type='admin_test_created') ORDER BY r.id ASC LIMIT 50", $cutoff ) ) );
		if ( $ids ) {
			ECM_Registration_Admin::delete_test_registrations( $ids );
		}
	}
}
