<?php

defined( 'ABSPATH' ) || exit;

/** Participant-level reporting. Reads existing 0.6.6 tables without migrations. */
final class ECM_Comprehensive_Report {
	private const DEFAULT_PAGE_SIZE = 50;

	public static function hooks(): void {
		add_action( 'admin_post_ecm_comprehensive_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_ecm_comprehensive_print', array( __CLASS__, 'print_report' ) );
	}

	private static function scope( array $filters ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		$events = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $filters['events'] ?? array() ) ) ) ) );
		if ( $events ) {
			$where[] = 'r.event_id IN (' . implode( ',', array_fill( 0, count( $events ), '%d' ) ) . ')';
			$args = array_merge( $args, $events );
		} elseif ( ! empty( $filters['event'] ) ) {
			$where[] = 'r.event_id=%d';
			$args[] = (int) $filters['event'];
		}
		if ( ! empty( $filters['session'] ) ) {
			$where[] = 'r.event_id=(SELECT event_id FROM ' . ECM_DB::table( 'sessions' ) . ' WHERE id=%d LIMIT 1)';
			$args[] = (int) $filters['session'];
		}
		if ( ! empty( $filters['payment'] ) ) {
			$where[] = 'r.payment_status=%s';
			$args[] = $filters['payment'];
		}
		$test = ECM_Registration_Admin::is_site_administrator() ? ( $filters['test'] ?? 'live' ) : 'live';
		if ( 'test' === $test ) {
			$where[] = 'r.is_test=1';
		} elseif ( 'all' !== $test ) {
			$where[] = 'r.is_test=0';
		}
		foreach ( array( 'from' => '00:00:00', 'to' => '23:59:59' ) as $key => $time ) {
			if ( ! empty( $filters[ $key ] ) && ( $utc = ECM_Utils::local_to_utc( $filters[ $key ] . ' ' . $time ) ) ) {
				$where[] = 'r.created_at ' . ( 'from' === $key ? '>=' : '<=' ) . ' %s';
				$args[] = $utc;
			}
		}
		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = '(r.public_id LIKE %s OR r.form_data LIKE %s OR p.public_id LIKE %s OR p.display_name LIKE %s OR pe.normalized_phone LIKE %s OR host.display_name LIKE %s OR hostpe.full_name LIKE %s OR pe.full_name LIKE %s OR ownerpe.full_name LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like, $like, $like, $like, $like );
		}
		return array( implode( ' AND ', $where ), $args );
	}

	/**
	 * A registration always represents one primary attendee, even if a legacy
	 * identity backfill has not yet created its participant/person records.
	 * Real guest participants are appended as independent rows. This derived
	 * person scope is shared by COUNT, page queries, CSV and print.
	 * No write, lazy migration or schema change is performed by a report.
	 */
	private static function from_clause( array $filters ): string {
		$participants  = ECM_DB::table( 'participants' );
		$registrations = ECM_DB::table( 'registrations' );
		$people        = ECM_DB::table( 'people' );
		$events        = ECM_DB::table( 'event_definitions' );
		$attendance    = ECM_DB::table( 'attendance' );
		$session_id = absint( $filters['session'] ?? 0 );
		$session_scope = $session_id ? ' AND session_id=' . $session_id : '';
		// The primary row comes from registrations, never from an INNER JOIN
		// to an optional participant row. An old registration cannot disappear.
		$identities = "(SELECT reg.id registration_id,COALESCE(NULLIF(reg.primary_participant_id,0),(SELECT MIN(pp.id) FROM {$participants} pp WHERE pp.registration_id=reg.id AND pp.participant_type='primary' AND pp.status='active')) participant_id,'primary' person_role,0 guest_order FROM {$registrations} reg UNION ALL SELECT gp.registration_id,gp.id participant_id,'guest' person_role,gp.guest_order FROM {$participants} gp WHERE gp.participant_type='guest' AND gp.status='active')";
		return "FROM {$identities} x INNER JOIN {$registrations} r ON r.id=x.registration_id LEFT JOIN {$participants} p ON p.id=x.participant_id AND p.registration_id=r.id LEFT JOIN {$people} pe ON pe.id=p.person_id LEFT JOIN {$people} ownerpe ON ownerpe.id=r.primary_person_id LEFT JOIN {$events} e ON e.id=r.event_id LEFT JOIN {$participants} host ON host.id=p.invited_by_participant_id LEFT JOIN {$people} hostpe ON hostpe.id=host.person_id LEFT JOIN {$participants} ownerp ON ownerp.id=r.primary_participant_id AND ownerp.registration_id=r.id LEFT JOIN (SELECT participant_id,COUNT(*) cnt FROM {$attendance} WHERE status='success'{$session_scope} AND participant_id>0 GROUP BY participant_id) att ON att.participant_id=p.id LEFT JOIN (SELECT registration_id,COUNT(*) cnt FROM {$attendance} WHERE status='success'{$session_scope} AND participant_id=0 GROUP BY registration_id) legacyatt ON legacyatt.registration_id=r.id";
	}

	private static function query( array $filters, int $limit, int $offset = 0 ): array {
		global $wpdb;
		list( $where, $args ) = self::scope( $filters );
		$sql = 'SELECT COALESCE(p.id,0) participant_id,x.registration_id,COALESCE(NULLIF(p.public_id,\'\'),r.public_id) participant_code,p.display_name,x.person_role participant_type,p.invited_by_participant_id,x.guest_order,pe.full_name person_name,pe.normalized_phone phone,COALESCE(NULLIF(host.display_name,\'\'),NULLIF(hostpe.full_name,\'\'),NULLIF(ownerp.display_name,\'\'),ownerpe.full_name) host_name,r.public_id registration_code,r.event_id,r.created_at,r.form_data,r.payment_status,r.is_test,e.title event_title,(COALESCE(att.cnt,0)+CASE WHEN x.person_role=\'primary\' THEN COALESCE(legacyatt.cnt,0) ELSE 0 END) attendance_count ' . self::from_clause( $filters ) . ' WHERE ' . $where . " ORDER BY r.id DESC,CASE WHEN x.person_role='primary' THEN 0 ELSE 1 END,x.guest_order ASC,x.participant_id ASC";
		if ( $limit > 0 ) {
			$sql .= ' LIMIT %d OFFSET %d';
			$args[] = $limit;
			$args[] = $offset;
		}
		return (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function totals( array $filters ): object {
		global $wpdb;
		list( $where, $args ) = self::scope( $filters );
		$sql = "SELECT COUNT(*) total,COALESCE(SUM(CASE WHEN x.person_role='guest' THEN 1 ELSE 0 END),0) guests,COALESCE(SUM(CASE WHEN x.person_role='primary' AND p.id IS NULL THEN 1 ELSE 0 END),0) missing_primary " . self::from_clause( $filters ) . " WHERE {$where}";
		return $wpdb->get_row( $args ? $wpdb->prepare( $sql, $args ) : $sql ) ?: (object) array( 'total' => 0, 'guests' => 0, 'missing_primary' => 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function page_size( int $total ): int {
		$requested = isset( $_GET['ecm_page_size'] ) && is_scalar( $_GET['ecm_page_size'] ) ? sanitize_key( wp_unslash( $_GET['ecm_page_size'] ) ) : '';
		if ( 'all' === $requested && $total <= 2500 ) {
			return max( 1, $total );
		}
		return in_array( $requested, array( '50', '100', '250', '500' ), true ) ? (int) $requested : self::DEFAULT_PAGE_SIZE;
	}

	/** Resolve relations for current batch only; never run one query per participant. */
	private static function guest_names( array $rows ): array {
		if ( ! $rows ) {
			return array();
		}
		global $wpdb;
		$registrations = array_values( array_unique( array_map( 'absint', wp_list_pluck( $rows, 'registration_id' ) ) ) );
		$participants = ECM_DB::table( 'participants' );
		$people = ECM_DB::table( 'people' );
		$map = array();
		foreach ( array_chunk( $registrations, 200 ) as $chunk ) {
			$ids = implode( ',', $chunk );
			$guests = $wpdb->get_results( "SELECT p.registration_id,p.invited_by_participant_id,p.display_name,pe.full_name FROM {$participants} p LEFT JOIN {$people} pe ON pe.id=p.person_id WHERE p.registration_id IN ({$ids}) AND p.participant_type='guest' AND p.status='active' ORDER BY p.guest_order,p.id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $guests as $guest ) {
				// Each registration has one primary in the 0.6.6 model.
				$map[ (int) $guest->registration_id ][] = (string) ( $guest->display_name ?: $guest->full_name ?: 'مهمان بدون نام' );
			}
		}
		return $map;
	}

	private static function name( object $row ): string {
		$name = (string) ( $row->display_name ?: $row->person_name ?: '' );
		if ( '' !== trim( $name ) ) {
			return $name;
		}
		if ( 'primary' === (string) $row->participant_type ) {
			$data = json_decode( (string) ( $row->form_data ?? '{}' ), true );
			if ( is_array( $data ) ) {
				foreach ( array( 'full_name', 'name', 'first_name' ) as $key ) {
					$value = ECM_Utils::field_value( $data, $key );
					if ( is_scalar( $value ) && trim( (string) $value ) !== '' ) {
						return (string) $value;
					}
				}
				// Honor each event's form-builder identity field without mutating data.
				$key = self::form_field_key( (int) ( $row->event_id ?? 0 ), 'name_field' );
				if ( $key ) {
					$value = ECM_Utils::field_value( $data, $key );
					if ( '' !== trim( (string) $value ) ) {
						return (string) $value;
					}
				}
			}
		}
		return 'بدون نام';
	}

	private static function phone( object $row ): string {
		$phone = (string) ( $row->phone ?? '' );
		if ( '' !== $phone ) {
			return $phone;
		}
		if ( 'primary' !== (string) $row->participant_type ) {
			return '—';
		}
		$data = json_decode( (string) ( $row->form_data ?? '{}' ), true );
		if ( is_array( $data ) ) {
			$custom_key = self::form_field_key( (int) ( $row->event_id ?? 0 ), 'sms_field' );
			foreach ( array_unique( array_filter( array_merge( array( $custom_key ), array( 'mobile', 'phone', 'phone_number', 'cellphone' ) ) ) ) as $key ) {
				$value = ECM_Utils::field_value( $data, $key );
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return (string) $value;
				}
			}
		}
		return '—';
	}

	/** Cache event-level settings, never personal form data. */
	private static function form_field_key( int $event_id, string $field ): string {
		static $cache = array();
		if ( ! array_key_exists( $event_id, $cache ) ) {
			$config = ECM_Events::config( ECM_Events::event( $event_id ) );
			$cache[ $event_id ] = array(
				'name_field' => (string) ( $config['name_field'] ?? '' ),
				'sms_field' => (string) ( $config['sms_field'] ?? '' ),
			);
		}
		return (string) ( $cache[ $event_id ][ $field ] ?? '' );
	}

	private static function relation( object $row, array $guest_names ): string {
		if ( 'guest' === $row->participant_type ) {
			if ( ! empty( $row->host_name ) ) {
				return (string) $row->host_name;
			}
			// Some legacy guest rows have no linked primary identity, but the
			// original registration form still contains their inviter's name.
			$primary = (object) array(
				'participant_type' => 'primary',
				'display_name' => '',
				'person_name' => '',
				'event_id' => (int) ( $row->event_id ?? 0 ),
				'form_data' => (string) ( $row->form_data ?? '{}' ),
			);
			$name = self::name( $primary );
			return 'بدون نام' === $name ? 'معرف ثبت‌نام' : $name;
		}
		return implode( '، ', $guest_names[ (int) $row->registration_id ] ?? array() ) ?: '—';
	}

	private static function query_args( array $filters ): array {
		$args = array( 'ecm_view' => 'registrations', 'ecm_comprehensive' => 1, 'ecm_event_selection' => 1 );
		if ( $filters['events'] ) {
			$args['ecm_events'] = array_values( $filters['events'] );
		}
		foreach ( array( 'session' => 'ecm_session', 'payment' => 'ecm_payment', 'test' => 'ecm_test', 'from' => 'ecm_from', 'to' => 'ecm_to', 'search' => 'ecm_search', 'page_size' => 'ecm_page_size' ) as $key => $param ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$args[ $param ] = $filters[ $key ];
			}
		}
		return $args;
	}

	public static function render( bool $admin, array $filters, bool $finance_hidden = false ): string {
		if ( ! current_user_can( 'view_ecm_reports' ) ) {
			return '';
		}
		$base = $admin ? admin_url( 'admin.php?page=ecm-reports' ) : ( isset( $_GET['ecm_portal_view'] ) ? ECM_Portal::url( array( 'ecm_portal_view' => 'reports' ) ) : get_permalink() );
		$totals = self::totals( $filters );
		$page_size = self::page_size( (int) $totals->total );
		$pages = max( 1, (int) ceil( (int) $totals->total / $page_size ) );
		$paged = max( 1, min( $pages, absint( $_GET['ecm_paged'] ?? 1 ) ) );
		$rows = self::query( $filters, $page_size, ( $paged - 1 ) * $page_size );
		$guest_names = self::guest_names( $rows );
		$export_args = self::query_args( $filters );
		$csv_url = wp_nonce_url( add_query_arg( $export_args + array( 'action' => 'ecm_comprehensive_csv' ), admin_url( 'admin-post.php' ) ), 'ecm_comprehensive_csv' );
		$print_url = wp_nonce_url( add_query_arg( $export_args + array( 'action' => 'ecm_comprehensive_print' ), admin_url( 'admin-post.php' ) ), 'ecm_comprehensive_print' );
		$ordinary_url = add_query_arg( array_diff_key( $export_args, array_flip( array( 'ecm_comprehensive', 'ecm_page_size' ) ) ), $base );
		ob_start();
		?>
		<section class="ecm-report ecm-comprehensive-report" dir="rtl">
			<div class="ecm-report__header"><div><h2>فهرست تمام شرکت‌کنندگان</h2><p>هر معرف و هر مهمان یک ردیف مستقل دارد. ثبت‌نام‌های تستی به‌صورت پیش‌فرض حذف شده‌اند.</p></div><div class="ecm-report__actions"><a class="button" href="<?php echo esc_url( $ordinary_url ); ?>">گزارش معمولی</a><a class="button button-primary" href="<?php echo esc_url( $csv_url ); ?>">خروجی CSV همه افراد</a><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $print_url ); ?>">چاپ / PDF</a></div></div>
			<?php self::filters_markup( $admin, $filters, $base, true, $finance_hidden ); ?>
			<div class="ecm-stats"><div><span>کل افراد</span><strong><?php echo esc_html( number_format_i18n( (int) $totals->total ) ); ?></strong></div><div><span>معرف‌ها</span><strong><?php echo esc_html( number_format_i18n( (int) $totals->total - (int) $totals->guests ) ); ?></strong></div><div><span>مهمان‌ها</span><strong><?php echo esc_html( number_format_i18n( (int) $totals->guests ) ); ?></strong></div></div>
			<p class="ecm-report-count-note"><?php if ( isset( $_GET['ecm_page_size'] ) && 'all' === $_GET['ecm_page_size'] && (int) $totals->total > 2500 ) : ?><span class="ecm-report-integrity-note">برای بیش از ۲۵۰۰ نفر، نمایش همه در یک صفحه غیرفعال است؛ از CSV یا صفحه‌بندی استفاده کنید.</span> <?php endif; ?>تعداد شرکت‌کنندگان = تعداد معرف‌ها (ثبت‌نام‌ها) + مهمان‌های ثبت‌شده. <?php echo esc_html( number_format_i18n( (int) $totals->total ) ); ?> نفر مطابق فیلتر یافت شد؛ نمایش <?php echo esc_html( number_format_i18n( (int) $totals->total ? ( $paged - 1 ) * $page_size + 1 : 0 ) ); ?> تا <?php echo esc_html( number_format_i18n( min( (int) $totals->total, $paged * $page_size ) ) ); ?> در این صفحه. <?php if ( (int) $totals->missing_primary ) : ?><span class="ecm-report-integrity-note"><?php echo esc_html( number_format_i18n( (int) $totals->missing_primary ) ); ?> ثبت‌نام قدیمی هنوز رکورد هویتی کامل ندارد؛ در گزارش شمرده شده است.</span><?php endif; ?></p>
			<div class="ecm-table-scroll"><table class="widefat striped ecm-report-table"><thead><tr><th>نام و نام خانوادگی</th><th>نقش</th><th>مهمان‌ها / معرف</th><th>شماره تماس</th><th>کد کارت</th><th>رویداد</th><th>زمان ثبت‌نام</th><th>حضور</th><th>جزئیات فرم</th></tr></thead><tbody>
			<?php if ( ! $rows ) : ?><tr><td colspan="9">موردی مطابق فیلتر پیدا نشد.</td></tr><?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
			<tr class="<?php echo ! empty( $row->is_test ) ? 'ecm-registration-is-test' : ''; ?>">
			<td data-label="نام"><?php echo esc_html( self::name( $row ) ); ?></td>
			<td data-label="نقش"><span class="ecm-badge <?php echo 'guest' === $row->participant_type ? 'ecm-badge--guest' : 'ecm-badge--primary'; ?>"><?php echo 'guest' === $row->participant_type ? 'مهمان' : 'معرف'; ?></span><?php if ( ! empty( $row->is_test ) ) : ?> <span class="ecm-badge ecm-badge--test">تستی</span><?php endif; ?></td>
			<td data-label="ارتباط"><?php echo esc_html( self::relation( $row, $guest_names ) ); ?></td>
			<td data-label="تلفن"><bdi><?php echo esc_html( self::phone( $row ) ); ?></bdi></td>
			<td data-label="کد کارت"><code><?php echo esc_html( $row->participant_code ); ?></code></td>
			<td data-label="رویداد"><?php echo esc_html( $row->event_title ?: 'رویداد قدیمی' ); ?></td>
			<td data-label="تاریخ"><?php echo esc_html( ECM_Utils::utc_to_local( $row->created_at ) ); ?></td>
			<td data-label="حضور"><?php echo esc_html( (string) (int) $row->attendance_count ); ?></td>
			<td data-label="فرم"><?php if ( 'primary' === $row->participant_type ) : ?><details><summary>مشاهده</summary><?php $data = json_decode( (string) $row->form_data, true ); foreach ( (array) $data as $key => $value ) : if ( is_array( $value ) ) { $value = implode( '، ', array_map( static fn( $item ): string => is_scalar( $item ) ? (string) $item : wp_json_encode( $item, JSON_UNESCAPED_UNICODE ), $value ) ); } ?><div><b><?php echo esc_html( (string) $key ); ?>:</b> <?php echo esc_html( (string) $value ); ?></div><?php endforeach; ?></details><?php else : ?>—<?php endif; ?></td>
			</tr>
			<?php endforeach; ?></tbody></table></div>
			<?php echo self::pagination( $base, $export_args, $paged, $pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Shared filter for both reporting modes: no duplicated/contradictory filters. */
	public static function filters_markup( bool $admin, array $filters, string $base, bool $comprehensive = false, bool $finance_hidden = false ): void {
		$events = ECM_Events::all();
		global $wpdb;
		$sessions = ECM_DB::table( 'sessions' );
		$definitions = ECM_DB::table( 'event_definitions' );
		$session_rows = $wpdb->get_results( "SELECT s.id,s.title,e.title event_title FROM {$sessions} s LEFT JOIN {$definitions} e ON e.id=s.event_id ORDER BY s.start_at_utc DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$selected_count = count( (array) $filters['events'] );
		$page_size = isset( $_GET['ecm_page_size'] ) && is_scalar( $_GET['ecm_page_size'] ) ? sanitize_key( wp_unslash( $_GET['ecm_page_size'] ) ) : '50';
		?>
		<form class="ecm-report-filter ecm-report-filter--participants ecm-no-print" method="get" action="<?php echo esc_url( $base ); ?>" dir="rtl">
			<?php if ( $admin ) : ?><input type="hidden" name="page" value="ecm-reports"><?php elseif ( isset( $_GET['ecm_portal_view'] ) ) : ?><input type="hidden" name="ecm_portal_view" value="reports"><?php endif; ?>
			<input type="hidden" name="ecm_view" value="registrations"><input type="hidden" name="ecm_event_selection" value="1">
			<div class="ecm-report-filter__heading">
				<label class="ecm-report-switch"><input type="checkbox" name="ecm_comprehensive" value="1" <?php checked( $comprehensive ); ?> onchange="if(this.form.requestSubmit){this.form.requestSubmit()}else{this.form.submit()}"><span><strong>گزارش جامع افراد</strong><small>نمایش هر معرف و هر مهمان در ردیف مستقل</small></span></label>
				<small class="ecm-report-filter__hint">در حالت معمولی هر ثبت‌نام یک ردیف است؛ در حالت جامع تعداد کل افراد نمایش داده می‌شود.</small>
			</div>
			<details class="ecm-event-checklist">
				<summary>فیلتر دوره‌ها <span class="ecm-filter-count"><?php echo $selected_count ? esc_html( number_format_i18n( $selected_count ) . ' مورد انتخاب‌شده' ) : 'همه دوره‌ها'; ?></span></summary>
				<div class="ecm-event-checklist__body">
					<div class="ecm-check-grid"><?php foreach ( $events as $event ) : ?><label class="ecm-event-check-option"><input type="checkbox" name="ecm_events[]" value="<?php echo esc_attr( $event->id ); ?>" <?php checked( in_array( (int) $event->id, (array) $filters['events'], true ) ); ?>><span><?php echo esc_html( $event->title ); ?></span></label><?php endforeach; ?></div>
					<p>اگر هیچ دوره‌ای انتخاب نشود، گزارش شامل همه دوره‌ها خواهد بود. برای تغییر انتخاب‌ها دکمه «اعمال فیلتر» را بزنید.</p>
				</div>
			</details>
			<div class="ecm-report-filter__fields">
				<label class="ecm-report-field">جست‌وجو<input type="search" name="ecm_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="نام، موبایل، کد کارت"></label>
				<label class="ecm-report-field">نوبت حضور<select name="ecm_session"><option value="0">همه نوبت‌ها</option><?php foreach ( $session_rows as $session ) : ?><option value="<?php echo esc_attr( $session->id ); ?>" <?php selected( (int) $filters['session'], (int) $session->id ); ?>><?php echo esc_html( $session->event_title . ' - ' . $session->title ); ?></option><?php endforeach; ?></select></label>
				<?php if ( ! $finance_hidden ) : ?><label class="ecm-report-field">وضعیت پرداخت<select name="ecm_payment"><option value="">همه وضعیت‌ها</option><option value="paid" <?php selected( $filters['payment'], 'paid' ); ?>>پرداخت موفق</option><option value="offline_confirmed" <?php selected( $filters['payment'], 'offline_confirmed' ); ?>>ثبت بدون درگاه</option><option value="pending" <?php selected( $filters['payment'], 'pending' ); ?>>در انتظار</option><option value="not_required" <?php selected( $filters['payment'], 'not_required' ); ?>>رایگان</option></select></label><?php endif; ?>
				<?php if ( ECM_Registration_Admin::is_site_administrator() ) : ?><label class="ecm-report-field">ثبت‌نام تستی<select name="ecm_test"><option value="live" <?php selected( $filters['test'], 'live' ); ?>>فقط اصلی</option><option value="test" <?php selected( $filters['test'], 'test' ); ?>>فقط تستی</option><option value="all" <?php selected( $filters['test'], 'all' ); ?>>همه</option></select></label><?php endif; ?>
				<label class="ecm-report-field">از تاریخ<input type="date" name="ecm_from" value="<?php echo esc_attr( $filters['from'] ); ?>"></label>
				<label class="ecm-report-field">تا تاریخ<input type="date" name="ecm_to" value="<?php echo esc_attr( $filters['to'] ); ?>"></label>
				<?php if ( $comprehensive ) : ?><label class="ecm-report-field">تعداد ردیف<select name="ecm_page_size"><?php foreach ( array( '50' => '۵۰', '100' => '۱۰۰', '250' => '۲۵۰', '500' => '۵۰۰', 'all' => 'همه (تا ۲۵۰۰ نفر)' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $page_size, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><?php endif; ?>
			</div>
			<div class="ecm-report-filter__actions"><button type="submit" class="button button-primary">اعمال فیلتر</button><a class="button" href="<?php echo esc_url( add_query_arg( array( 'ecm_view' => 'registrations' ), $base ) ); ?>">پاک‌کردن فیلترها</a></div>
		</form>
		<?php
	}

	public static function pagination( string $base, array $args, int $current, int $pages ): string {
		if ( $pages < 2 ) {
			return '';
		}
		$numbers = array_unique( array_merge( array( 1, $pages ), range( max( 1, $current - 2 ), min( $pages, $current + 2 ) ) ) );
		sort( $numbers );
		$out = '<nav class="ecm-pagination" aria-label="صفحه‌بندی گزارش">';
		if ( $current > 1 ) {
			$out .= '<a href="' . esc_url( add_query_arg( array_merge( $args, array( 'ecm_paged' => $current - 1 ) ), $base ) ) . '">صفحه قبل</a> ';
		}
		foreach ( $numbers as $page ) {
			$url = add_query_arg( array_merge( $args, array( 'ecm_paged' => $page ) ), $base );
			$out .= $page === $current ? '<span class="current" aria-current="page">' . $page . '</span> ' : '<a href="' . esc_url( $url ) . '">' . $page . '</a> ';
		}
		if ( $current < $pages ) {
			$out .= '<a href="' . esc_url( add_query_arg( array_merge( $args, array( 'ecm_paged' => $current + 1 ) ), $base ) ) . '">صفحه بعد</a>';
		}
		return $out . '</nav>';
	}

	public static function export_csv(): void {
		if ( ! current_user_can( 'view_ecm_reports' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'ecm_comprehensive_csv' );
		$filters = ECM_Admin::report_filters();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ecm-participants-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'نام کامل', 'نقش', 'نام مهمان‌ها / معرف', 'موبایل', 'کد کارت', 'کد ثبت‌نام', 'رویداد', 'تاریخ ثبت‌نام', 'تعداد حضور', 'وضعیت پرداخت', 'داده‌های فرم' ) );
		$offset = 0;
		do {
			$rows = self::query( $filters, 500, $offset );
			$guests = self::guest_names( $rows );
			foreach ( $rows as $row ) {
				$values = array( self::name( $row ), 'guest' === $row->participant_type ? 'مهمان' : 'معرف', self::relation( $row, $guests ), self::phone( $row ), $row->participant_code, $row->registration_code, $row->event_title, ECM_Utils::utc_to_local( $row->created_at ), (string) $row->attendance_count, $row->payment_status, 'primary' === $row->participant_type ? (string) $row->form_data : '' );
				fputcsv( $out, array_map( static function ( $value ): string {
					$value = (string) $value;
					return preg_match( '/^[\s\x00-\x1F]*[=+\-@]/u', $value ) ? "'" . $value : $value;
				}, $values ) );
			}
			$offset += count( $rows );
		} while ( count( $rows ) === 500 );
		fclose( $out );
		exit;
	}

	public static function print_report(): void {
		if ( ! current_user_can( 'view_ecm_reports' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'ecm_comprehensive_print' );
		$filters = ECM_Admin::report_filters();
		$totals = self::totals( $filters );
		if ( (int) $totals->total > 5000 ) {
			wp_die( 'برای گزارش‌های بالای ۵۰۰۰ نفر از خروجی CSV استفاده کنید؛ چاپ یک‌باره ممکن است حافظه مرورگر را پر کند.' );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>گزارش جامع شرکت‌کنندگان</title><style>body{font:13px/1.7 Tahoma,sans-serif;padding:20px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #777;padding:6px;text-align:right}thead{display:table-header-group}tr{break-inside:avoid}@media print{button{display:none}@page{size:A4 landscape}}</style></head><body><button onclick="print()">چاپ / ذخیره PDF</button><h2>فهرست جامع شرکت‌کنندگان</h2><p>تاریخ گزارش: ' . esc_html( wp_date( 'Y/m/d H:i' ) ) . '</p><table><thead><tr><th>نام</th><th>نقش</th><th>مهمان‌ها / معرف</th><th>موبایل</th><th>کد کارت</th><th>رویداد</th><th>حضور</th></tr></thead><tbody>';
		for ( $offset = 0; $offset < (int) $totals->total; $offset += 250 ) {
			$rows = self::query( $filters, 250, $offset );
			$guests = self::guest_names( $rows );
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( self::name( $r ) ) . '</td><td>' . esc_html( 'guest' === $r->participant_type ? 'مهمان' : 'معرف' ) . '</td><td>' . esc_html( self::relation( $r, $guests ) ) . '</td><td>' . esc_html( self::phone( $r ) ) . '</td><td>' . esc_html( $r->participant_code ) . '</td><td>' . esc_html( $r->event_title ) . '</td><td>' . (int) $r->attendance_count . '</td></tr>';
			}
		}
		echo '</tbody></table></body></html>';
		exit;
	}
}
