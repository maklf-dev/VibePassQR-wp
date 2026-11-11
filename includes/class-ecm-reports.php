<?php

defined( 'ABSPATH' ) || exit;

/**
 * Generation-two reports: overview, operational lists and connected profiles.
 */
final class ECM_Reports {
	public static function hooks(): void {
		add_action( 'admin_post_ecm_export_attendance_csv', array( __CLASS__, 'export_attendance_csv' ) );
		add_action( 'admin_post_ecm_export_sms_csv', array( __CLASS__, 'export_sms_csv' ) );
	}

	public static function render( bool $admin = false ): string {
		if ( ! current_user_can( 'view_ecm_reports' ) ) {
			return '<div class="ecm-state ecm-state--error"><h1>دسترسی ندارید</h1></div>';
		}
		$legacy_list_request = ! isset( $_GET['ecm_view'] ) && ( isset( $_GET['ecm_event'] ) || isset( $_GET['ecm_events'] ) || isset( $_GET['ecm_comprehensive'] ) || isset( $_GET['ecm_session'] ) || isset( $_GET['ecm_payment'] ) || isset( $_GET['ecm_from'] ) || isset( $_GET['ecm_to'] ) || isset( $_GET['ecm_search'] ) || isset( $_GET['ecm_paged'] ) || isset( $_GET['cols'] ) );
		$view = isset( $_GET['ecm_view'] ) ? sanitize_key( wp_unslash( $_GET['ecm_view'] ) ) : ( $legacy_list_request ? 'registrations' : 'overview' );
		$allowed = array( 'overview', 'registrations', 'attendance', 'sms', 'people', 'operators', 'events', 'person', 'operator', 'event' );
		$view = in_array( $view, $allowed, true ) ? $view : 'overview';
		if ( 'registrations' === $view ) {
			$content = ECM_Admin::report_markup( $admin );
		} elseif ( 'attendance' === $view ) {
			$content = self::attendance_view( $admin );
		} elseif ( 'sms' === $view ) {
			$content = self::sms_view( $admin );
		} elseif ( 'people' === $view ) {
			$content = self::people_view( $admin );
		} elseif ( 'operators' === $view ) {
			$content = self::operators_view( $admin );
		} elseif ( 'events' === $view ) {
			$content = self::events_view( $admin );
		} elseif ( 'person' === $view ) {
			$content = self::person_profile( absint( $_GET['ecm_id'] ?? 0 ), $admin );
		} elseif ( 'operator' === $view ) {
			$content = self::operator_profile( absint( $_GET['ecm_id'] ?? 0 ), $admin );
		} elseif ( 'event' === $view ) {
			$content = self::event_profile( absint( $_GET['ecm_id'] ?? 0 ), $admin );
		} else {
			$content = self::overview( $admin );
		}

		return '<div class="ecm-reports-v2" data-report-view="' . esc_attr( $view ) . '">' . self::header( $view, $admin ) . $content . '</div>';
	}

	public static function url( string $view, array $args = array(), ?bool $admin = null ): string {
		if ( null === $admin ) {
			$admin = is_admin() && ! wp_doing_ajax();
		}
		if ( $admin ) {
			$base = admin_url( 'admin.php?page=ecm-reports' );
		} else {
			$pages = (array) get_option( 'ecm_pages', array() );
			$is_portal = ! empty( $pages['portal'] ) && is_page( (int) $pages['portal'] );
			$base = $is_portal ? ECM_Portal::url( array( 'ecm_portal_view' => 'reports' ) ) : ECM_Utils::page_url( 'manager' );
		}
		return add_query_arg( array_merge( array( 'ecm_view' => $view ), $args ), $base );
	}

	private static function header( string $view, bool $admin ): string {
		$items = array(
			'overview'      => 'نمای کلی',
			'registrations' => 'ثبت‌نام‌ها',
			'attendance'    => 'حضورها',
			'sms'           => 'پیامک‌ها',
			'people'        => 'اشخاص',
			'operators'     => 'اپراتورها',
			'events'        => 'رویدادها',
		);
		ob_start();
		?>
		<div class="ecm-report-v2-head">
			<div><span class="ecm-kicker">REPORTING CENTER</span><h1>مرکز گزارش و آمار</h1><p>نمای کلی، فهرست عملیاتی و پروفایل متصل هر رکورد</p></div>
			<div class="ecm-report-v2-head__actions"><?php if ( ! $admin && current_user_can( 'record_ecm_attendance' ) ) : ?><a class="ecm-button ecm-button--primary" href="<?php echo esc_url( ECM_Portal::url( array( 'ecm_portal_view' => 'checkin' ) ) ); ?>">ثبت سریع حضور</a><?php endif; ?></div>
		</div>
		<nav class="ecm-report-nav" aria-label="بخش‌های گزارش"><?php foreach ( $items as $key => $label ) : ?><a class="<?php echo esc_attr( ( $view === $key || ( 'person' === $view && 'people' === $key ) || ( 'operator' === $view && 'operators' === $key ) || ( 'event' === $view && 'events' === $key ) ) ? 'is-active' : '' ); ?>" href="<?php echo esc_url( self::url( $key, array(), $admin ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>
		<?php
		return (string) ob_get_clean();
	}

	private static function overview( bool $admin ): string {
		global $wpdb;
		$r = ECM_DB::table( 'registrations' );
		$p = ECM_DB::table( 'participants' );
		$a = ECM_DB::table( 'attendance' );
		$e = ECM_DB::table( 'event_definitions' );
		$s = ECM_DB::table( 'sessions' );
		$stats = $wpdb->get_row( "SELECT (SELECT COUNT(*) FROM {$r} WHERE is_test=0) registrations,(SELECT COUNT(*) FROM {$r} WHERE is_test=0 AND payment_status='paid') paid_registrations,(SELECT COALESCE(SUM(amount_rial),0) FROM {$r} WHERE is_test=0 AND payment_status='paid') total_paid,(SELECT COUNT(*) FROM {$p} px INNER JOIN {$r} rx ON rx.id=px.registration_id AND rx.is_test=0 WHERE px.status='active') participants,(SELECT COUNT(*) FROM {$p} px INNER JOIN {$r} rx ON rx.id=px.registration_id AND rx.is_test=0 WHERE px.participant_type='guest' AND px.status='active') guests,(SELECT COUNT(*) FROM {$a} ax INNER JOIN {$r} rx ON rx.id=ax.registration_id AND rx.is_test=0 WHERE ax.status='success') attendances,(SELECT COUNT(DISTINCT ax.participant_id) FROM {$a} ax INNER JOIN {$r} rx ON rx.id=ax.registration_id AND rx.is_test=0 WHERE ax.status='success') attended_people,(SELECT COUNT(*) FROM {$e} WHERE status='published' AND enabled=1) active_events,(SELECT COUNT(*) FROM {$s} WHERE enabled=1) sessions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$operators = $wpdb->get_results( "SELECT a.recorded_by_user_id,MAX(a.recorder_name) recorder_name,MAX(a.recorder_code) recorder_code,COUNT(*) total,MAX(a.created_at) last_at FROM {$a} a INNER JOIN {$r} rx ON rx.id=a.registration_id AND rx.is_test=0 WHERE a.recorded_by_user_id>0 GROUP BY a.recorded_by_user_id ORDER BY total DESC LIMIT 8" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Aggregate each large fact table before joining. Joining registrations,
		// participants and attendance directly multiplies intermediate rows and
		// becomes prohibitively expensive on busy events.
		$events = $wpdb->get_results( "SELECT e.id,e.title,e.enabled,e.status,COALESCE(r.registrations,0) registrations,COALESCE(p.participants,0) participants,COALESCE(a.attendances,0) attendances FROM {$e} e LEFT JOIN (SELECT event_id,COUNT(*) registrations FROM {$r} WHERE is_test=0 GROUP BY event_id) r ON r.event_id=e.id LEFT JOIN (SELECT px.event_id,COUNT(*) participants FROM {$p} px INNER JOIN {$r} rx ON rx.id=px.registration_id AND rx.is_test=0 WHERE px.status='active' GROUP BY px.event_id) p ON p.event_id=e.id LEFT JOIN (SELECT ax.event_id,COUNT(*) attendances FROM {$a} ax INNER JOIN {$r} rx ON rx.id=ax.registration_id AND rx.is_test=0 WHERE ax.status='success' GROUP BY ax.event_id) a ON a.event_id=e.id ORDER BY e.id DESC LIMIT 8" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attendance_rate = ! empty( $stats->participants ) ? min( 100, round( ( (int) $stats->attended_people / (int) $stats->participants ) * 100, 1 ) ) : 0;
		$sms_table = ECM_DB::table( 'sms' );
		$sms_stats = $wpdb->get_row( "SELECT COUNT(*) total,SUM(q.status='accepted') accepted,SUM(q.status='failed') failed,SUM(q.status IN ('queued','retry_wait')) pending FROM {$sms_table} q LEFT JOIN {$r} rx ON rx.id=q.registration_id WHERE q.registration_id=0 OR rx.is_test=0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$absent_people = max( 0, (int) $stats->participants - (int) $stats->attended_people );
		ob_start();
		?>
		<section class="ecm-report-overview">
			<div class="ecm-kpi-grid">
				<?php self::kpi( 'ثبت‌نام', (int) $stats->registrations, 'کل سفارش‌های ثبت‌شده', 'registrations', $admin ); ?>
				<?php self::kpi( 'شرکت‌کننده', (int) $stats->participants, (int) $stats->guests . ' مهمان', 'people', $admin ); ?>
				<?php self::kpi( 'ثبت حضور', (int) $stats->attendances, $attendance_rate . '٪ پوشش افراد', 'attendance', $admin ); ?>
				<?php self::kpi( 'بدون حضور', $absent_people, 'بر اساس حداقل یک حضور ثبت‌شده', 'people', $admin ); ?>
				<?php self::kpi( 'رویداد فعال', (int) $stats->active_events, (int) $stats->sessions . ' نوبت تعریف‌شده', 'events', $admin ); ?>
				<?php self::kpi( 'درخواست پیامک', (int) ( $sms_stats->total ?? 0 ), (int) ( $sms_stats->accepted ?? 0 ) . ' پذیرفته · ' . (int) ( $sms_stats->failed ?? 0 ) . ' ناموفق', 'sms', $admin ); ?>
				<?php if ( (int) $stats->paid_registrations > 0 ) self::kpi( 'پرداخت موفق', (int) $stats->paid_registrations, number_format_i18n( (int) $stats->total_paid ) . ' ریال', 'registrations', $admin ); ?>
			</div>
			<div class="ecm-report-dashboard-grid">
				<section class="ecm-report-card"><div class="ecm-report-card__head"><div><h2>عملکرد رویدادها</h2><p>آخرین رویدادهای تعریف‌شده</p></div><a href="<?php echo esc_url( self::url( 'events', array(), $admin ) ); ?>">مشاهده همه</a></div><div class="ecm-metric-list"><?php if ( ! $events ) : ?><p>هنوز رویدادی ثبت نشده است.</p><?php endif; ?><?php foreach ( $events as $event ) : ?><a href="<?php echo esc_url( self::url( 'event', array( 'ecm_id' => (int) $event->id ), $admin ) ); ?>"><div><strong><?php echo esc_html( $event->title ); ?></strong><small><?php echo esc_html( $event->enabled ? $event->status : 'غیرفعال' ); ?></small></div><span><?php echo esc_html( number_format_i18n( (int) $event->participants ) ); ?> نفر · <?php echo esc_html( number_format_i18n( (int) $event->attendances ) ); ?> حضور</span></a><?php endforeach; ?></div></section>
				<section class="ecm-report-card"><div class="ecm-report-card__head"><div><h2>اپراتورهای فعال</h2><p>بر اساس تعداد ثبت حضور</p></div><a href="<?php echo esc_url( self::url( 'operators', array(), $admin ) ); ?>">مشاهده همه</a></div><div class="ecm-metric-list"><?php if ( ! $operators ) : ?><p>هنوز ثبت اپراتوری وجود ندارد.</p><?php endif; ?><?php foreach ( $operators as $operator ) : $user = get_userdata( (int) $operator->recorded_by_user_id ); $name = $user ? $user->display_name : ( $operator->recorder_name ?: 'اپراتور حذف‌شده' ); ?><a href="<?php echo esc_url( self::url( 'operator', array( 'ecm_id' => (int) $operator->recorded_by_user_id ), $admin ) ); ?>"><div><strong><?php echo esc_html( $name ); ?></strong><small><?php echo esc_html( $operator->recorder_code ?: 'U' . (int) $operator->recorded_by_user_id ); ?></small></div><span><?php echo esc_html( number_format_i18n( (int) $operator->total ) ); ?> ثبت</span></a><?php endforeach; ?></div></section>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function kpi( string $label, int $value, string $caption, string $view, bool $admin ): void {
		echo '<a class="ecm-kpi" href="' . esc_url( self::url( $view, array(), $admin ) ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong><small>' . esc_html( $caption ) . '</small></a>';
	}

	private static function attendance_view( bool $admin ): string {
		global $wpdb;
		$a = ECM_DB::table( 'attendance' );
		$p = ECM_DB::table( 'participants' );
		$pe = ECM_DB::table( 'people' );
		$e = ECM_DB::table( 'event_definitions' );
		$s = ECM_DB::table( 'sessions' );
		$filters = self::attendance_filters();
		list( $where, $args ) = self::attendance_where( $filters );
		$sql = "SELECT a.*,p.person_id,p.participant_type,p.invited_by_participant_id,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name,pe.normalized_phone,e.title event_title,s.title session_title FROM {$a} a LEFT JOIN {$p} p ON p.id=a.participant_id LEFT JOIN {$pe} pe ON pe.id=p.person_id LEFT JOIN {$e} e ON e.id=a.event_id LEFT JOIN {$s} s ON s.id=a.session_id WHERE {$where} ORDER BY a.id DESC LIMIT 300";
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$events = ECM_Events::all();
		$sessions = $wpdb->get_results( "SELECT id,event_id,title FROM {$s} ORDER BY start_at_utc DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'ecm_export_attendance_csv' ), self::attendance_query( $filters ) ), admin_url( 'admin-post.php' ) ), 'ecm_export_attendance_csv' );
		ob_start();
		?>
		<section class="ecm-report-section"><div class="ecm-report-section__head"><div><h2>گزارش ثبت حضور</h2><p>هر سطر یک ثبت حضور واقعی است؛ حداکثر ۳۰۰ رکورد اخیر در جدول نمایش داده می‌شود.</p></div><a class="ecm-button ecm-button--ghost" href="<?php echo esc_url( $export ); ?>">خروجی CSV فیلترشده</a></div>
		<form class="ecm-report-filter-v2" method="get" action="<?php echo esc_url( self::url( 'attendance', array(), $admin ) ); ?>"><?php self::hidden_context( 'attendance', $admin ); ?><label>جست‌وجو<input type="search" name="ecm_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="نام، تلفن، اپراتور"></label><label>رویداد<select name="ecm_event"><option value="0">همه</option><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr( $event->id ); ?>" <?php selected( $filters['event'], (int) $event->id ); ?>><?php echo esc_html( $event->title ); ?></option><?php endforeach; ?></select></label><label>نوبت<select name="ecm_session"><option value="0">همه</option><?php foreach ( $sessions as $session ) : ?><option value="<?php echo esc_attr( $session->id ); ?>" <?php selected( $filters['session'], (int) $session->id ); ?>><?php echo esc_html( $session->title ); ?></option><?php endforeach; ?></select></label><label>از تاریخ<input type="date" name="ecm_from" value="<?php echo esc_attr( $filters['from'] ); ?>"></label><label>تا تاریخ<input type="date" name="ecm_to" value="<?php echo esc_attr( $filters['to'] ); ?>"></label><button class="ecm-button ecm-button--primary">اعمال</button></form>
		<div class="ecm-table-scroll"><table class="ecm-report-table ecm-attendance-report"><thead><tr><th>شخص</th><th>نوع</th><th>رویداد / نوبت</th><th>زمان</th><th>ثبت‌کننده</th><th>روش</th><th>دستگاه</th></tr></thead><tbody><?php if ( ! $rows ) : ?><tr><td colspan="7">رکوردی مطابق فیلتر وجود ندارد.</td></tr><?php endif; ?><?php foreach ( $rows as $row ) : ?><tr><td><a class="ecm-report-person-link" href="<?php echo esc_url( self::url( 'person', array( 'ecm_id' => (int) $row->person_id ), $admin ) ); ?>"><strong><?php echo esc_html( $row->full_name ?: 'بدون نام' ); ?></strong><small><?php echo esc_html( $row->normalized_phone ?: '—' ); ?></small></a></td><td><span class="ecm-badge"><?php echo esc_html( 'guest' === $row->participant_type ? 'مهمان' : 'معرف' ); ?></span></td><td><?php echo esc_html( ( $row->event_title ?: 'رویداد قدیمی' ) . ' · ' . ( $row->session_title ?: 'نوبت قدیمی' ) ); ?></td><td><?php echo esc_html( ECM_Utils::utc_to_local( $row->created_at, 'Y/m/d H:i:s' ) ); ?></td><td><?php echo self::operator_link( $row, $admin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo esc_html( self::method_label( $row->method ) ); ?></td><td><?php echo esc_html( $row->device_label ?: '—' ); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
		<?php
		return (string) ob_get_clean();
	}

	private static function sms_view( bool $admin ): string {
		global $wpdb;
		$q = ECM_DB::table( 'sms' ); $p = ECM_DB::table( 'participants' ); $pe = ECM_DB::table( 'people' ); $e = ECM_DB::table( 'event_definitions' ); $s = ECM_DB::table( 'sessions' );
		$filters = self::sms_filters(); list( $where, $args ) = self::sms_where( $filters );
		$sql = "SELECT q.*,p.person_id,p.participant_type,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name,e.title event_title,s.title session_title FROM {$q} q LEFT JOIN {$p} p ON p.id=q.participant_id LEFT JOIN {$pe} pe ON pe.id=p.person_id LEFT JOIN {$e} e ON e.id=q.event_id LEFT JOIN {$s} s ON s.id=q.session_id WHERE {$where} ORDER BY q.id DESC LIMIT 300";
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$events = ECM_Events::all(); $sessions = $wpdb->get_results( "SELECT id,event_id,title FROM {$s} ORDER BY start_at_utc DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$transports = $wpdb->get_col( "SELECT DISTINCT transport FROM {$q} WHERE transport<>'' ORDER BY transport" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$statuses = array( 'queued' => 'در صف', 'retry_wait' => 'منتظر تلاش مجدد', 'sending' => 'در حال ارسال', 'accepted' => 'پذیرفته‌شده', 'delivered' => 'تحویل‌شده', 'failed' => 'ناموفق', 'paused' => 'متوقف‌شده', 'unknown' => 'نامشخص' );
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'ecm_export_sms_csv' ), self::sms_query( $filters ) ), admin_url( 'admin-post.php' ) ), 'ecm_export_sms_csv' );
		ob_start(); ?>
		<section class="ecm-report-section"><div class="ecm-report-section__head"><div><h2>گزارش جامع پیامک</h2><p>درخواست، قالب، مسیر ارسال، پاسخ درگاه و زمان‌ها؛ جدول حداکثر ۳۰۰ رکورد اخیر فیلترشده را نمایش می‌دهد.</p></div><a class="ecm-button ecm-button--ghost" href="<?php echo esc_url( $export ); ?>">خروجی CSV فیلترشده</a></div>
		<form class="ecm-report-filter-v2 ecm-report-filter-v2--sms" method="get" action="<?php echo esc_url( self::url( 'sms', array(), $admin ) ); ?>"><?php self::hidden_context( 'sms', $admin ); ?>
		<label>جست‌وجو<input type="search" name="ecm_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="نام، گیرنده، پیام، شناسه یا خطا"></label><label>رویداد<select name="ecm_event"><option value="0">همه</option><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr( $event->id ); ?>" <?php selected( $filters['event'], (int) $event->id ); ?>><?php echo esc_html( $event->title ); ?></option><?php endforeach; ?></select></label><label>نوبت<select name="ecm_session"><option value="0">همه</option><?php foreach ( $sessions as $session ) : ?><option value="<?php echo esc_attr( $session->id ); ?>" <?php selected( $filters['session'], (int) $session->id ); ?>><?php echo esc_html( $session->title ); ?></option><?php endforeach; ?></select></label>
		<label>نوع اعلان<select name="ecm_notification"><option value="">همه</option><?php foreach ( ECM_SMS::states() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['notification'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?><option value="otp_recovery" <?php selected( $filters['notification'], 'otp_recovery' ); ?>>کد تأیید بازیابی</option></select></label><label>وضعیت<select name="ecm_status"><option value="">همه</option><?php foreach ( $statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>درگاه<select name="ecm_transport"><option value="">همه</option><?php foreach ( $transports as $transport ) : ?><option value="<?php echo esc_attr( $transport ); ?>" <?php selected( $filters['transport'], $transport ); ?>><?php echo esc_html( $transport ); ?></option><?php endforeach; ?></select></label><label>مخاطب<select name="ecm_participant_type"><option value="">همه</option><option value="primary" <?php selected( $filters['participant_type'], 'primary' ); ?>>معرف</option><option value="guest" <?php selected( $filters['participant_type'], 'guest' ); ?>>مهمان</option></select></label><label>نوع ارسال<select name="ecm_sending_type"><option value="">همه</option><option value="webservice" <?php selected( $filters['sending_type'], 'webservice' ); ?>>متن آزاد</option><option value="pattern" <?php selected( $filters['sending_type'], 'pattern' ); ?>>پترن</option></select></label><label>از تاریخ<input type="date" name="ecm_from" value="<?php echo esc_attr( $filters['from'] ); ?>"></label><label>تا تاریخ<input type="date" name="ecm_to" value="<?php echo esc_attr( $filters['to'] ); ?>"></label><button class="ecm-button ecm-button--primary">اعمال فیلتر</button><a class="ecm-button" href="<?php echo esc_url( self::url( 'sms', array(), $admin ) ); ?>">پاک‌کردن</a></form>
		<div class="ecm-table-scroll"><table class="ecm-report-table ecm-sms-report-table"><thead><tr><th>ID / شخص</th><th>رویداد / نوبت</th><th>اعلان</th><th>گیرنده</th><th>نوع / پترن</th><th>پیام / پارامترها</th><th>درگاه</th><th>وضعیت</th><th>پاسخ</th><th>تلاش</th><th>زمان‌ها</th></tr></thead><tbody><?php if ( ! $rows ) : ?><tr class="ecm-report-empty"><td colspan="11">رکوردی مطابق فیلتر وجود ندارد.</td></tr><?php endif; ?><?php foreach ( $rows as $row ) : $payload = json_decode( (string) $row->payload, true ); $payload = is_array( $payload ) ? $payload : array(); $sending_type = sanitize_key( (string) ( $payload['sending_type'] ?? ( ! empty( $payload['code'] ) ? 'pattern' : 'webservice' ) ) ); $pattern = sanitize_text_field( (string) ( $payload['code'] ?? '' ) ); $params = ! empty( $payload['params'] ) && is_array( $payload['params'] ) ? wp_json_encode( $payload['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : ''; ?>
		<tr><td data-label="ID / شخص"><strong>#<?php echo esc_html( $row->id ); ?></strong><?php if ( $row->person_id ) : ?><br><a href="<?php echo esc_url( self::url( 'person', array( 'ecm_id' => (int) $row->person_id ), $admin ) ); ?>"><?php echo esc_html( $row->full_name ?: 'بدون نام' ); ?></a><?php endif; ?><br><span class="ecm-badge"><?php echo esc_html( 'guest' === $row->participant_type ? 'مهمان' : ( $row->participant_id ? 'معرف' : 'سیستمی' ) ); ?></span></td><td data-label="رویداد / نوبت"><?php echo esc_html( $row->event_title ?: '—' ); ?><?php if ( $row->session_title ) : ?><br><small><?php echo esc_html( $row->session_title ); ?></small><?php endif; ?></td><td data-label="اعلان"><?php echo esc_html( ECM_SMS::notification_label( (string) $row->notification_type ) ); ?></td><td data-label="گیرنده"><bdi><?php echo esc_html( $row->recipient ); ?></bdi></td><td data-label="نوع / پترن"><strong><?php echo esc_html( 'pattern' === $sending_type ? 'پترن' : 'متن آزاد' ); ?></strong><?php if ( $pattern ) : ?><br><code><?php echo esc_html( $pattern ); ?></code><?php endif; ?></td><td data-label="پیام / پارامترها" class="ecm-sms-message-preview"><?php echo nl2br( esc_html( $row->message ?: $params ?: '—' ) ); ?></td><td data-label="درگاه"><?php echo esc_html( $row->transport ?: '—' ); ?></td><td data-label="وضعیت"><span class="ecm-badge ecm-badge--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $statuses[ $row->status ] ?? $row->status ); ?></span></td><td data-label="پاسخ"><bdi><?php echo esc_html( $row->http_code ? 'HTTP ' . $row->http_code : '—' ); ?></bdi><br><small><?php echo esc_html( $row->provider_id ?: $row->error_code ?: '—' ); ?></small></td><td data-label="تلاش"><?php echo esc_html( number_format_i18n( (int) $row->attempts ) ); ?></td><td data-label="زمان‌ها"><small>ساخت: <?php echo esc_html( ECM_Utils::utc_to_local( $row->created_at, 'Y/m/d H:i:s' ) ); ?></small><?php if ( $row->accepted_at ) : ?><br><small>پذیرش: <?php echo esc_html( ECM_Utils::utc_to_local( $row->accepted_at, 'Y/m/d H:i:s' ) ); ?></small><?php endif; ?><?php if ( $row->delivered_at ) : ?><br><small>تحویل: <?php echo esc_html( ECM_Utils::utc_to_local( $row->delivered_at, 'Y/m/d H:i:s' ) ); ?></small><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
		<?php return (string) ob_get_clean();
	}

	private static function people_view( bool $admin ): string {
		global $wpdb;
		$pe = ECM_DB::table( 'people' );
		$p  = ECM_DB::table( 'participants' );
		$a  = ECM_DB::table( 'attendance' );
		$search = isset( $_GET['ecm_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ecm_search'] ) ) : '';
		$args = array();
		$where = '1=1';
		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where = '(pe.full_name LIKE %s OR pe.normalized_phone LIKE %s OR pe.email LIKE %s OR pe.public_id LIKE %s)';
			$args = array( $like, $like, $like, $like );
		}
		$r = ECM_DB::table( 'registrations' );
		$sql = "SELECT pe.*,COALESCE(px.participations,0) participations,COALESCE(px.guest_roles,0) guest_roles,COALESCE(ax.attendances,0) attendances,px.last_registration FROM {$pe} pe INNER JOIN (SELECT p1.person_id,COUNT(*) participations,SUM(p1.participant_type='guest') guest_roles,MAX(p1.created_at) last_registration FROM {$p} p1 INNER JOIN {$r} r1 ON r1.id=p1.registration_id AND r1.is_test=0 GROUP BY p1.person_id) px ON px.person_id=pe.id LEFT JOIN (SELECT p2.person_id,COUNT(*) attendances FROM {$p} p2 INNER JOIN {$r} r2 ON r2.id=p2.registration_id AND r2.is_test=0 INNER JOIN {$a} a2 ON a2.participant_id=p2.id AND a2.status='success' GROUP BY p2.person_id) ax ON ax.person_id=pe.id WHERE {$where} ORDER BY px.last_registration DESC LIMIT 300";
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		ob_start();
		?>
		<section class="ecm-report-section"><div class="ecm-report-section__head"><div><h2>اشخاص و شرکت‌کنندگان</h2><p>هویت یکتا در همه رویدادها و نقش‌ها</p></div></div><form class="ecm-report-filter-v2 ecm-report-filter-v2--compact" method="get" action="<?php echo esc_url( self::url( 'people', array(), $admin ) ); ?>"><?php self::hidden_context( 'people', $admin ); ?><label>جست‌وجو<input type="search" name="ecm_search" value="<?php echo esc_attr( $search ); ?>" placeholder="نام، موبایل، ایمیل یا کد"></label><button class="ecm-button ecm-button--primary">جست‌وجو</button></form><div class="ecm-table-scroll"><table class="ecm-report-table"><thead><tr><th>شخص</th><th>موبایل</th><th>تعداد دوره/نقش</th><th>نقش مهمان</th><th>تعداد حضور</th><th>آخرین ثبت‌نام</th></tr></thead><tbody><?php if ( ! $rows ) : ?><tr><td colspan="6">شخصی پیدا نشد.</td></tr><?php endif; ?><?php foreach ( $rows as $row ) : ?><tr><td><a class="ecm-report-person-link" href="<?php echo esc_url( self::url( 'person', array( 'ecm_id' => (int) $row->id ), $admin ) ); ?>"><strong><?php echo esc_html( $row->full_name ?: 'بدون نام' ); ?></strong><small><?php echo esc_html( $row->public_id ); ?></small></a></td><td><?php echo esc_html( $row->normalized_phone ?: '—' ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->participations ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->guest_roles ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->attendances ) ); ?></td><td><?php echo esc_html( ECM_Utils::utc_to_local( $row->last_registration ) ); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
		<?php
		return (string) ob_get_clean();
	}

	private static function operators_view( bool $admin ): string {
		global $wpdb;
		$a = ECM_DB::table( 'attendance' );
		$e = ECM_DB::table( 'event_definitions' );
		$r = ECM_DB::table( 'registrations' );
		$rows = $wpdb->get_results( "SELECT a.recorded_by_user_id,MAX(a.recorder_name) recorder_name,MAX(a.recorder_code) recorder_code,COUNT(*) total,COUNT(DISTINCT a.event_id) events,COUNT(DISTINCT a.session_id) sessions,MIN(a.created_at) first_at,MAX(a.created_at) last_at FROM {$a} a INNER JOIN {$r} r ON r.id=a.registration_id AND r.is_test=0 WHERE a.recorded_by_user_id>0 GROUP BY a.recorded_by_user_id ORDER BY total DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ob_start();
		?>
		<section class="ecm-report-section"><div class="ecm-report-section__head"><div><h2>عملکرد اپراتورها</h2><p>آمار ثبت حضور بر اساس حساب ثبت‌کننده</p></div></div><div class="ecm-table-scroll"><table class="ecm-report-table"><thead><tr><th>اپراتور</th><th>کد</th><th>تعداد ثبت</th><th>رویداد</th><th>نوبت</th><th>اولین فعالیت</th><th>آخرین فعالیت</th></tr></thead><tbody><?php if ( ! $rows ) : ?><tr><td colspan="7">ثبت اپراتوری وجود ندارد.</td></tr><?php endif; ?><?php foreach ( $rows as $row ) : $user = get_userdata( (int) $row->recorded_by_user_id ); $name = $user ? $user->display_name : ( $row->recorder_name ?: 'اپراتور حذف‌شده' ); ?><tr><td><a href="<?php echo esc_url( self::url( 'operator', array( 'ecm_id' => (int) $row->recorded_by_user_id ), $admin ) ); ?>"><?php echo esc_html( $name ); ?></a></td><td><?php echo esc_html( $row->recorder_code ?: 'U' . (int) $row->recorded_by_user_id ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->events ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->sessions ) ); ?></td><td><?php echo esc_html( ECM_Utils::utc_to_local( $row->first_at ) ); ?></td><td><?php echo esc_html( ECM_Utils::utc_to_local( $row->last_at ) ); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
		<?php
		return (string) ob_get_clean();
	}

	private static function events_view( bool $admin ): string {
		global $wpdb;
		$e = ECM_DB::table( 'event_definitions' );
		$r = ECM_DB::table( 'registrations' );
		$p = ECM_DB::table( 'participants' );
		$a = ECM_DB::table( 'attendance' );
		$s = ECM_DB::table( 'sessions' );
		$rows = $wpdb->get_results( "SELECT e.*,COALESCE(r.registrations,0) registrations,COALESCE(p.participants,0) participants,COALESCE(p.guests,0) guests,COALESCE(a.attendances,0) attendances,COALESCE(s.sessions,0) sessions FROM {$e} e LEFT JOIN (SELECT event_id,COUNT(*) registrations FROM {$r} WHERE is_test=0 GROUP BY event_id) r ON r.event_id=e.id LEFT JOIN (SELECT px.event_id,COUNT(*) participants,SUM(px.participant_type='guest') guests FROM {$p} px INNER JOIN {$r} rx ON rx.id=px.registration_id AND rx.is_test=0 WHERE px.status='active' GROUP BY px.event_id) p ON p.event_id=e.id LEFT JOIN (SELECT ax.event_id,COUNT(*) attendances FROM {$a} ax INNER JOIN {$r} rx ON rx.id=ax.registration_id AND rx.is_test=0 WHERE ax.status='success' GROUP BY ax.event_id) a ON a.event_id=e.id LEFT JOIN (SELECT event_id,COUNT(*) sessions FROM {$s} GROUP BY event_id) s ON s.event_id=e.id ORDER BY e.id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ob_start();
		?>
		<section class="ecm-report-section"><div class="ecm-report-section__head"><div><h2>گزارش تفکیکی رویدادها</h2><p>برای جزئیات، روی نام هر رویداد بزنید.</p></div></div><div class="ecm-table-scroll"><table class="ecm-report-table"><thead><tr><th>رویداد</th><th>وضعیت</th><th>ثبت‌نام</th><th>شرکت‌کننده</th><th>مهمان</th><th>نوبت</th><th>حضور</th></tr></thead><tbody><?php foreach ( $rows as $row ) : ?><tr><td><a href="<?php echo esc_url( self::url( 'event', array( 'ecm_id' => (int) $row->id ), $admin ) ); ?>"><strong><?php echo esc_html( $row->title ); ?></strong></a></td><td><span class="ecm-badge"><?php echo esc_html( ! empty( $row->enabled ) ? $row->status : 'غیرفعال' ); ?></span></td><td><?php echo esc_html( number_format_i18n( (int) $row->registrations ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->participants ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->guests ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->sessions ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->attendances ) ); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
		<?php
		return (string) ob_get_clean();
	}

	private static function person_profile( int $person_id, bool $admin ): string {
		global $wpdb;
		$pe = ECM_DB::table( 'people' );
		$p  = ECM_DB::table( 'participants' );
		$r  = ECM_DB::table( 'registrations' );
		$e  = ECM_DB::table( 'event_definitions' );
		$a  = ECM_DB::table( 'attendance' );
		$s  = ECM_DB::table( 'sessions' );
		$person = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pe} WHERE id=%d", $person_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $person ) {
			return '<div class="ecm-state ecm-state--error"><h1>شخص پیدا نشد</h1></div>';
		}
		$participations = $wpdb->get_results( $wpdb->prepare( "SELECT p.*,e.title event_title,r.created_at registration_time,(SELECT COUNT(*) FROM {$a} ax WHERE ax.participant_id=p.id) attendance_count FROM {$p} p LEFT JOIN {$e} e ON e.id=p.event_id INNER JOIN {$r} r ON r.id=p.registration_id AND r.is_test=0 WHERE p.person_id=%d ORDER BY p.id DESC", $person_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attendance = $wpdb->get_results( $wpdb->prepare( "SELECT a.*,e.title event_title,s.title session_title FROM {$a} a LEFT JOIN {$p} p ON p.id=a.participant_id INNER JOIN {$r} rx ON rx.id=a.registration_id AND rx.is_test=0 LEFT JOIN {$e} e ON e.id=a.event_id LEFT JOIN {$s} s ON s.id=a.session_id WHERE p.person_id=%d ORDER BY a.id DESC", $person_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sms_table = ECM_DB::table( 'sms' );
			$sms_history = $wpdb->get_results( $wpdb->prepare( "SELECT q.*,e.title event_title FROM {$sms_table} q INNER JOIN {$p} p ON p.id=q.participant_id INNER JOIN {$r} rx ON rx.id=q.registration_id AND rx.is_test=0 LEFT JOIN {$e} e ON e.id=q.event_id WHERE p.person_id=%d ORDER BY q.id DESC LIMIT 100", $person_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$participant_ids = array_map( 'absint', wp_list_pluck( $participations, 'id' ) );
		$guests = array();
		if ( $participant_ids ) {
			$ids = implode( ',', $participant_ids );
			$guests = $wpdb->get_results( "SELECT p.*,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name,pe.normalized_phone,e.title event_title FROM {$p} p INNER JOIN {$r} rx ON rx.id=p.registration_id AND rx.is_test=0 LEFT JOIN {$pe} pe ON pe.id=p.person_id LEFT JOIN {$e} e ON e.id=p.event_id WHERE p.invited_by_participant_id IN ({$ids}) ORDER BY p.id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		ob_start();
		?>
			<section class="ecm-profile"><a class="ecm-profile-back" href="<?php echo esc_url( self::url( 'people', array(), $admin ) ); ?>">→ بازگشت به اشخاص</a><div class="ecm-profile-hero"><div class="ecm-profile-avatar"><?php echo esc_html( function_exists( 'mb_substr' ) ? mb_substr( $person->full_name ?: '؟', 0, 1 ) : substr( $person->full_name ?: '?', 0, 1 ) ); ?></div><div><span class="ecm-badge">پروفایل شخص</span><h2><?php echo esc_html( $person->full_name ?: 'بدون نام' ); ?></h2><p><?php echo esc_html( $person->normalized_phone ?: 'بدون موبایل' ); ?><?php echo $person->email ? ' · ' . esc_html( $person->email ) : ''; ?></p><code><?php echo esc_html( $person->public_id ); ?></code></div></div><div class="ecm-kpi-grid ecm-kpi-grid--profile"><?php self::plain_kpi( 'دوره/نقش', count( $participations ) ); self::plain_kpi( 'ثبت حضور', count( $attendance ) ); self::plain_kpi( 'مهمان دعوت‌شده', count( $guests ) ); self::plain_kpi( 'درخواست پیامک', count( $sms_history ) ); ?></div>
		<div class="ecm-profile-sections"><section class="ecm-report-card"><h3>سوابق شرکت در رویدادها</h3><div class="ecm-profile-timeline"><?php foreach ( $participations as $row ) : ?><article><div><strong><?php echo esc_html( $row->event_title ?: 'رویداد قدیمی' ); ?></strong><span class="ecm-badge"><?php echo esc_html( 'guest' === $row->participant_type ? 'مهمان' : 'معرف' ); ?></span></div><p><?php echo esc_html( number_format_i18n( (int) $row->attendance_count ) ); ?> حضور · ثبت‌نام <?php echo esc_html( ECM_Utils::utc_to_local( $row->registration_time ) ); ?></p></article><?php endforeach; ?></div></section><section class="ecm-report-card"><h3>تاریخچه حضور</h3><div class="ecm-profile-timeline"><?php if ( ! $attendance ) : ?><p>حضوری ثبت نشده است.</p><?php endif; ?><?php foreach ( $attendance as $row ) : ?><article><div><strong><?php echo esc_html( ( $row->event_title ?: 'رویداد' ) . ' · ' . ( $row->session_title ?: 'نوبت' ) ); ?></strong><time><?php echo esc_html( ECM_Utils::utc_to_local( $row->created_at, 'Y/m/d H:i:s' ) ); ?></time></div><p>ثبت‌کننده: <?php echo wp_kses_post( self::operator_link( $row, $admin ) ); ?> · <?php echo esc_html( $row->device_label ?: 'دستگاه نامشخص' ); ?></p></article><?php endforeach; ?></div></section></div>
			<?php if ( $guests ) : ?><section class="ecm-report-card"><h3>مهمانان دعوت‌شده</h3><div class="ecm-profile-timeline"><?php foreach ( $guests as $guest ) : ?><article><div><a href="<?php echo esc_url( self::url( 'person', array( 'ecm_id' => (int) $guest->person_id ), $admin ) ); ?>"><strong><?php echo esc_html( $guest->full_name ?: 'مهمان' ); ?></strong></a><span><?php echo esc_html( $guest->event_title ); ?></span></div><p><?php echo esc_html( $guest->normalized_phone ?: '—' ); ?></p></article><?php endforeach; ?></div></section><?php endif; ?><?php if ( $sms_history ) : ?><section class="ecm-report-card"><div class="ecm-report-card__head"><div><h3>پیامک‌های این شخص</h3><p>آخرین درخواست‌ها</p></div><a href="<?php echo esc_url( self::url( 'sms', array( 'ecm_search' => $person->normalized_phone ), $admin ) ); ?>">گزارش کامل</a></div><div class="ecm-profile-timeline"><?php foreach ( array_slice( $sms_history, 0, 12 ) as $sms ) : ?><article><div><strong><?php echo esc_html( ECM_SMS::notification_label( (string) $sms->notification_type ) ); ?></strong><span class="ecm-badge ecm-badge--<?php echo esc_attr( $sms->status ); ?>"><?php echo esc_html( $sms->status ); ?></span></div><p><?php echo esc_html( $sms->event_title ?: '—' ); ?> · <?php echo esc_html( ECM_Utils::utc_to_local( $sms->created_at ) ); ?></p></article><?php endforeach; ?></div></section><?php endif; ?></section>
		<?php
		return (string) ob_get_clean();
	}

	private static function operator_profile( int $user_id, bool $admin ): string {
		global $wpdb;
		$a = ECM_DB::table( 'attendance' );
		$p = ECM_DB::table( 'participants' );
		$pe = ECM_DB::table( 'people' );
		$e = ECM_DB::table( 'event_definitions' );
		$s = ECM_DB::table( 'sessions' );
		$r = ECM_DB::table( 'registrations' );
		$user = get_userdata( $user_id );
		$snapshot = $wpdb->get_row( $wpdb->prepare( "SELECT a.recorder_name,a.recorder_code FROM {$a} a INNER JOIN {$r} r ON r.id=a.registration_id AND r.is_test=0 WHERE a.recorded_by_user_id=%d ORDER BY a.id DESC LIMIT 1", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $user && ! $snapshot ) return '<div class="ecm-state ecm-state--error"><h1>اپراتور پیدا نشد</h1></div>';
		$name = $user ? $user->display_name : ( $snapshot->recorder_name ?: 'اپراتور حذف‌شده' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT a.*,pe.id person_id,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name,e.title event_title,s.title session_title FROM {$a} a INNER JOIN {$r} r ON r.id=a.registration_id AND r.is_test=0 LEFT JOIN {$p} p ON p.id=a.participant_id LEFT JOIN {$pe} pe ON pe.id=p.person_id LEFT JOIN {$e} e ON e.id=a.event_id LEFT JOIN {$s} s ON s.id=a.session_id WHERE a.recorded_by_user_id=%d ORDER BY a.id DESC LIMIT 500", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$event_count = count( array_unique( array_map( static fn( object $r ): int => (int) $r->event_id, $rows ) ) );
		ob_start();
		?>
		<section class="ecm-profile"><a class="ecm-profile-back" href="<?php echo esc_url( self::url( 'operators', array(), $admin ) ); ?>">→ بازگشت به اپراتورها</a><div class="ecm-profile-hero"><div class="ecm-profile-avatar">U</div><div><span class="ecm-badge">پروفایل اپراتور</span><h2><?php echo esc_html( $name ); ?></h2><p><?php echo esc_html( $snapshot && $snapshot->recorder_code ? $snapshot->recorder_code : 'U' . $user_id ); ?></p></div></div><div class="ecm-kpi-grid ecm-kpi-grid--profile"><?php self::plain_kpi( 'کل ثبت‌ها', count( $rows ) ); self::plain_kpi( 'رویدادها', $event_count ); self::plain_kpi( 'آخرین فعالیت', $rows ? ECM_Utils::utc_to_local( $rows[0]->created_at, 'Y/m/d H:i' ) : '—' ); ?></div><section class="ecm-report-card"><h3>ریز فعالیت‌های ثبت حضور</h3><div class="ecm-table-scroll"><table class="ecm-report-table"><thead><tr><th>شخص</th><th>رویداد / نوبت</th><th>زمان</th><th>روش</th><th>دستگاه</th></tr></thead><tbody><?php foreach ( $rows as $row ) : ?><tr><td><a href="<?php echo esc_url( self::url( 'person', array( 'ecm_id' => (int) $row->person_id ), $admin ) ); ?>"><?php echo esc_html( $row->full_name ?: 'بدون نام' ); ?></a></td><td><?php echo esc_html( $row->event_title . ' · ' . $row->session_title ); ?></td><td><?php echo esc_html( ECM_Utils::utc_to_local( $row->created_at, 'Y/m/d H:i:s' ) ); ?></td><td><?php echo esc_html( self::method_label( $row->method ) ); ?></td><td><?php echo esc_html( $row->device_label ?: '—' ); ?></td></tr><?php endforeach; ?></tbody></table></div></section></section>
		<?php
		return (string) ob_get_clean();
	}

	private static function event_profile( int $event_id, bool $admin ): string {
		global $wpdb;
		$event = ECM_Events::event( $event_id );
		if ( ! $event ) return '<div class="ecm-state ecm-state--error"><h1>رویداد پیدا نشد</h1></div>';
		$r = ECM_DB::table( 'registrations' );
		$p = ECM_DB::table( 'participants' );
		$a = ECM_DB::table( 'attendance' );
		$s = ECM_DB::table( 'sessions' );
			$q = ECM_DB::table( 'sms' );
			$stats = $wpdb->get_row( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$r} WHERE event_id=%d AND is_test=0) registrations,(SELECT COUNT(*) FROM {$p} px INNER JOIN {$r} rx ON rx.id=px.registration_id AND rx.is_test=0 WHERE px.event_id=%d AND px.status='active') participants,(SELECT COUNT(*) FROM {$p} px INNER JOIN {$r} rx ON rx.id=px.registration_id AND rx.is_test=0 WHERE px.event_id=%d AND px.participant_type='guest' AND px.status='active') guests,(SELECT COUNT(*) FROM {$a} ax INNER JOIN {$r} rx ON rx.id=ax.registration_id AND rx.is_test=0 WHERE ax.event_id=%d AND ax.status='success') attendances,(SELECT COUNT(*) FROM {$q} q INNER JOIN {$r} rx ON rx.id=q.registration_id AND rx.is_test=0 WHERE q.event_id=%d) sms_total", $event_id, $event_id, $event_id, $event_id, $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sessions = $wpdb->get_results( $wpdb->prepare( "SELECT s.*,COUNT(a.id) attendance_count,COUNT(DISTINCT a.participant_id) people_count FROM {$s} s LEFT JOIN {$a} a ON a.session_id=s.id AND a.status='success' AND EXISTS (SELECT 1 FROM {$r} rx WHERE rx.id=a.registration_id AND rx.is_test=0) WHERE s.event_id=%d GROUP BY s.id ORDER BY s.start_at_utc DESC", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ob_start();
		?>
		<section class="ecm-profile"><a class="ecm-profile-back" href="<?php echo esc_url( self::url( 'events', array(), $admin ) ); ?>">→ بازگشت به رویدادها</a><div class="ecm-profile-hero"><div class="ecm-profile-avatar">◫</div><div><span class="ecm-badge"><?php echo esc_html( ! empty( $event->enabled ) ? $event->status : 'غیرفعال' ); ?></span><h2><?php echo esc_html( $event->title ); ?></h2><p><code><?php echo esc_html( $event->slug ); ?></code></p></div></div><div class="ecm-kpi-grid ecm-kpi-grid--profile"><?php self::plain_kpi( 'ثبت‌نام', (int) $stats->registrations ); self::plain_kpi( 'شرکت‌کننده', (int) $stats->participants ); self::plain_kpi( 'مهمان', (int) $stats->guests ); self::plain_kpi( 'ثبت حضور', (int) $stats->attendances ); ?></div><div class="ecm-profile-actions"><a class="ecm-button ecm-button--ghost" href="<?php echo esc_url( self::url( 'registrations', array( 'ecm_event' => $event_id ), $admin ) ); ?>">ثبت‌نام‌های این رویداد</a><a class="ecm-button ecm-button--primary" href="<?php echo esc_url( self::url( 'attendance', array( 'ecm_event' => $event_id ), $admin ) ); ?>">حضورهای این رویداد</a><?php if ( $admin && current_user_can( 'manage_ecm_events' ) ) : ?><a class="ecm-button ecm-button--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=ecm-events&edit=' . $event_id ) ); ?>">ویرایش رویداد</a><?php endif; ?></div><section class="ecm-report-card"><h3>آمار نوبت‌ها</h3><div class="ecm-table-scroll"><table class="ecm-report-table"><thead><tr><th>نوبت</th><th>شروع</th><th>پایان</th><th>افراد حاضر</th><th>کل ثبت‌ها</th><th>گزارش</th></tr></thead><tbody><?php if ( ! $sessions ) : ?><tr><td colspan="6">نوبتی تعریف نشده است.</td></tr><?php endif; ?><?php foreach ( $sessions as $session ) : ?><tr><td><?php echo esc_html( $session->title ); ?></td><td><?php echo esc_html( ECM_Utils::utc_to_local( $session->start_at_utc ) ); ?></td><td><?php echo esc_html( ECM_Utils::utc_to_local( $session->end_at_utc ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $session->people_count ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $session->attendance_count ) ); ?></td><td><a href="<?php echo esc_url( self::url( 'attendance', array( 'ecm_event' => $event_id, 'ecm_session' => (int) $session->id ), $admin ) ); ?>">مشاهده</a></td></tr><?php endforeach; ?></tbody></table></div></section></section>
			<div class="ecm-profile-actions"><a class="ecm-button ecm-button--ghost" href="<?php echo esc_url( self::url( 'sms', array( 'ecm_event' => $event_id ), $admin ) ); ?>">گزارش <?php echo esc_html( number_format_i18n( (int) $stats->sms_total ) ); ?> پیامک این رویداد</a></div>
			<?php
		return (string) ob_get_clean();
	}

	private static function plain_kpi( string $label, $value ): void {
		echo '<div class="ecm-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( is_numeric( $value ) ? number_format_i18n( (float) $value ) : (string) $value ) . '</strong></div>';
	}

	private static function sms_filters(): array {
		return array(
			'event' => absint( $_GET['ecm_event'] ?? 0 ), 'session' => absint( $_GET['ecm_session'] ?? 0 ),
			'notification' => sanitize_key( wp_unslash( $_GET['ecm_notification'] ?? '' ) ), 'status' => sanitize_key( wp_unslash( $_GET['ecm_status'] ?? '' ) ),
			'transport' => sanitize_key( wp_unslash( $_GET['ecm_transport'] ?? '' ) ), 'participant_type' => sanitize_key( wp_unslash( $_GET['ecm_participant_type'] ?? '' ) ),
			'sending_type' => sanitize_key( wp_unslash( $_GET['ecm_sending_type'] ?? '' ) ), 'from' => sanitize_text_field( wp_unslash( $_GET['ecm_from'] ?? '' ) ),
			'to' => sanitize_text_field( wp_unslash( $_GET['ecm_to'] ?? '' ) ), 'search' => sanitize_text_field( wp_unslash( $_GET['ecm_search'] ?? '' ) ),
		);
	}

	private static function sms_where( array $filters ): array {
		global $wpdb; $where = array( '1=1' ); $args = array();
		$registrations = ECM_DB::table( 'registrations' );
		$where[] = "(q.registration_id=0 OR EXISTS (SELECT 1 FROM {$registrations} rt WHERE rt.id=q.registration_id AND rt.is_test=0))";
		if ( $filters['event'] ) { $where[] = 'q.event_id=%d'; $args[] = $filters['event']; }
		if ( $filters['session'] ) { $where[] = 'q.session_id=%d'; $args[] = $filters['session']; }
		if ( $filters['notification'] ) { $where[] = 'q.notification_type=%s'; $args[] = $filters['notification']; }
		if ( $filters['status'] ) { $where[] = 'q.status=%s'; $args[] = $filters['status']; }
		if ( $filters['transport'] ) { $where[] = 'q.transport=%s'; $args[] = $filters['transport']; }
		if ( in_array( $filters['participant_type'], array( 'primary', 'guest' ), true ) ) { $where[] = 'p.participant_type=%s'; $args[] = $filters['participant_type']; }
		if ( in_array( $filters['sending_type'], array( 'webservice', 'pattern' ), true ) ) { $where[] = 'q.payload LIKE %s'; $args[] = '%"sending_type":"' . $wpdb->esc_like( $filters['sending_type'] ) . '"%'; }
		if ( $filters['from'] && ( $utc = ECM_Utils::local_to_utc( $filters['from'] . ' 00:00:00' ) ) ) { $where[] = 'q.created_at>=%s'; $args[] = $utc; }
		if ( $filters['to'] && ( $utc = ECM_Utils::local_to_utc( $filters['to'] . ' 23:59:59' ) ) ) { $where[] = 'q.created_at<=%s'; $args[] = $utc; }
		if ( $filters['search'] ) { $like = '%' . $wpdb->esc_like( $filters['search'] ) . '%'; $where[] = '(p.display_name LIKE %s OR pe.full_name LIKE %s OR q.recipient LIKE %s OR q.message LIKE %s OR q.provider_id LIKE %s OR q.error_code LIKE %s OR q.event_key LIKE %s)'; array_push( $args, $like, $like, $like, $like, $like, $like, $like ); }
		return array( implode( ' AND ', $where ), $args );
	}

	private static function sms_query( array $filters ): array {
		return array_filter( array( 'ecm_event' => $filters['event'], 'ecm_session' => $filters['session'], 'ecm_notification' => $filters['notification'], 'ecm_status' => $filters['status'], 'ecm_transport' => $filters['transport'], 'ecm_participant_type' => $filters['participant_type'], 'ecm_sending_type' => $filters['sending_type'], 'ecm_from' => $filters['from'], 'ecm_to' => $filters['to'], 'ecm_search' => $filters['search'] ) );
	}

	public static function export_sms_csv(): void {
		if ( ! current_user_can( 'view_ecm_reports' ) ) wp_die( 'Forbidden', 403 );
		check_admin_referer( 'ecm_export_sms_csv' );
		global $wpdb; $q = ECM_DB::table( 'sms' ); $p = ECM_DB::table( 'participants' ); $pe = ECM_DB::table( 'people' ); $e = ECM_DB::table( 'event_definitions' ); $s = ECM_DB::table( 'sessions' );
		$filters = self::sms_filters(); list( $where, $args ) = self::sms_where( $filters );
		$sql = "SELECT q.*,p.participant_type,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name,e.title event_title,s.title session_title FROM {$q} q LEFT JOIN {$p} p ON p.id=q.participant_id LEFT JOIN {$pe} pe ON pe.id=p.person_id LEFT JOIN {$e} e ON e.id=q.event_id LEFT JOIN {$s} s ON s.id=q.session_id WHERE {$where} ORDER BY q.id DESC LIMIT 20000";
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="sms-report-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); fwrite( $out, "\xEF\xBB\xBF" ); fputcsv( $out, array( 'ID', 'شخص', 'نوع شخص', 'رویداد', 'نوبت', 'نوع اعلان', 'گیرنده', 'نوع ارسال', 'کد پترن', 'متن', 'پارامترها', 'درگاه', 'وضعیت', 'HTTP', 'شناسه درگاه', 'کد خطا', 'تعداد تلاش', 'زمان ساخت', 'زمان پذیرش', 'زمان تحویل', 'پاسخ خلاصه' ) );
		foreach ( $rows as $row ) { $payload = json_decode( (string) $row->payload, true ); $payload = is_array( $payload ) ? $payload : array(); $values = array( $row->id, $row->full_name, 'guest' === $row->participant_type ? 'مهمان' : ( $row->participant_id ? 'معرف' : 'سیستمی' ), $row->event_title, $row->session_title, ECM_SMS::notification_label( (string) $row->notification_type ), $row->recipient, $payload['sending_type'] ?? '', $payload['code'] ?? '', $row->message, isset( $payload['params'] ) ? wp_json_encode( $payload['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '', $row->transport, $row->status, $row->http_code, $row->provider_id, $row->error_code, $row->attempts, ECM_Utils::utc_to_local( $row->created_at, 'Y/m/d H:i:s' ), $row->accepted_at ? ECM_Utils::utc_to_local( $row->accepted_at, 'Y/m/d H:i:s' ) : '', $row->delivered_at ? ECM_Utils::utc_to_local( $row->delivered_at, 'Y/m/d H:i:s' ) : '', $row->response_summary ); fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $values ) ); }
		fclose( $out ); exit;
	}

	private static function csv_cell( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@]/u', $value ) ? "'" . $value : $value;
	}

	private static function attendance_filters(): array {
		return array(
			'event'   => absint( $_GET['ecm_event'] ?? 0 ),
			'session' => absint( $_GET['ecm_session'] ?? 0 ),
			'from'    => isset( $_GET['ecm_from'] ) ? sanitize_text_field( wp_unslash( $_GET['ecm_from'] ) ) : '',
			'to'      => isset( $_GET['ecm_to'] ) ? sanitize_text_field( wp_unslash( $_GET['ecm_to'] ) ) : '',
			'search'  => isset( $_GET['ecm_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ecm_search'] ) ) : '',
		);
	}

	private static function attendance_where( array $filters ): array {
		global $wpdb;
		$where = array( "a.status='success'" );
		$registrations = ECM_DB::table( 'registrations' );
		$where[] = "EXISTS (SELECT 1 FROM {$registrations} rt WHERE rt.id=a.registration_id AND rt.is_test=0)";
		$args = array();
		if ( $filters['event'] ) { $where[] = 'a.event_id=%d'; $args[] = $filters['event']; }
		if ( $filters['session'] ) { $where[] = 'a.session_id=%d'; $args[] = $filters['session']; }
		if ( $filters['from'] && ( $utc = ECM_Utils::local_to_utc( $filters['from'] . ' 00:00:00' ) ) ) { $where[] = 'a.created_at>=%s'; $args[] = $utc; }
		if ( $filters['to'] && ( $utc = ECM_Utils::local_to_utc( $filters['to'] . ' 23:59:59' ) ) ) { $where[] = 'a.created_at<=%s'; $args[] = $utc; }
		if ( $filters['search'] ) { $like = '%' . $wpdb->esc_like( $filters['search'] ) . '%'; $where[] = '(p.display_name LIKE %s OR pe.full_name LIKE %s OR pe.normalized_phone LIKE %s OR a.recorder_name LIKE %s OR a.recorder_code LIKE %s)'; array_push( $args, $like, $like, $like, $like, $like ); }
		return array( implode( ' AND ', $where ), $args );
	}

	private static function attendance_query( array $filters ): array {
		return array_filter( array( 'ecm_event' => $filters['event'], 'ecm_session' => $filters['session'], 'ecm_from' => $filters['from'], 'ecm_to' => $filters['to'], 'ecm_search' => $filters['search'] ) );
	}

	public static function export_attendance_csv(): void {
		if ( ! current_user_can( 'view_ecm_reports' ) ) wp_die( 'Forbidden', 403 );
		check_admin_referer( 'ecm_export_attendance_csv' );
		global $wpdb;
		$a = ECM_DB::table( 'attendance' ); $p = ECM_DB::table( 'participants' ); $pe = ECM_DB::table( 'people' ); $e = ECM_DB::table( 'event_definitions' ); $s = ECM_DB::table( 'sessions' );
		$filters = self::attendance_filters(); list( $where, $args ) = self::attendance_where( $filters );
		$sql = "SELECT a.*,p.participant_type,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name,pe.normalized_phone,e.title event_title,s.title session_title FROM {$a} a LEFT JOIN {$p} p ON p.id=a.participant_id LEFT JOIN {$pe} pe ON pe.id=p.person_id LEFT JOIN {$e} e ON e.id=a.event_id LEFT JOIN {$s} s ON s.id=a.session_id WHERE {$where} ORDER BY a.id DESC";
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="attendance-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); fwrite( $out, "\xEF\xBB\xBF" ); fputcsv( $out, array( 'نام', 'موبایل', 'نقش', 'رویداد', 'نوبت', 'زمان', 'ثبت‌کننده', 'کد اپراتور', 'روش', 'دستگاه' ) );
		foreach ( $rows as $row ) fputcsv( $out, array( $row->full_name, $row->normalized_phone, 'guest' === $row->participant_type ? 'مهمان' : 'معرف', $row->event_title, $row->session_title, ECM_Utils::utc_to_local( $row->created_at, 'Y/m/d H:i:s' ), $row->recorder_name ?: ( 'self_service' === $row->method ? 'خود کاربر' : '' ), $row->recorder_code, self::method_label( $row->method ), $row->device_label ) );
		fclose( $out ); exit;
	}

	private static function operator_link( object $row, bool $admin ): string {
		if ( ! empty( $row->recorded_by_user_id ) ) {
			$name = trim( (string) $row->recorder_name );
			if ( ! $name && ( $user = get_userdata( (int) $row->recorded_by_user_id ) ) ) $name = $user->display_name;
			return '<a href="' . esc_url( self::url( 'operator', array( 'ecm_id' => (int) $row->recorded_by_user_id ), $admin ) ) . '">' . esc_html( $name ?: 'اپراتور' ) . '</a>';
		}
		return esc_html( 'self_service' === (string) $row->method ? 'خود کاربر' : '—' );
	}

	private static function method_label( string $method ): string {
		$labels = array( 'self_service' => 'خودکاربر', 'operator_link' => 'اپراتور از لینک', 'operator_scanner' => 'اسکنر اپراتور' );
		return $labels[ $method ] ?? $method;
	}

	private static function hidden_context( string $view, bool $admin ): void {
		if ( $admin ) echo '<input type="hidden" name="page" value="ecm-reports">';
		else {
			$pages = (array) get_option( 'ecm_pages', array() );
			if ( ! empty( $pages['portal'] ) && is_page( (int) $pages['portal'] ) ) echo '<input type="hidden" name="ecm_portal_view" value="reports">';
		}
		echo '<input type="hidden" name="ecm_view" value="' . esc_attr( $view ) . '">';
	}
}
