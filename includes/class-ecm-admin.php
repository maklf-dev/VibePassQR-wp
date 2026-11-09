<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Admin {
	private static array $report_cache = array(
		'primed'     => false,
		'sms'        => array(),
		'attendance' => array(),
		'users'      => array(),
	);

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_ecm_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_ecm_export_csv', array( $this, 'export_csv' ) );
	}

	public function menu(): void {
		add_menu_page( 'مدیریت کارت رویداد', 'کارت رویداد', 'access_ecm', 'ecm-dashboard', array( $this, 'landing_page' ), 'dashicons-tickets-alt', 27 );
		add_submenu_page( 'ecm-dashboard', 'داشبورد', 'داشبورد', 'access_ecm', 'ecm-dashboard', array( $this, 'landing_page' ) );
		add_submenu_page( 'ecm-dashboard', 'رویدادها', 'رویدادها', 'manage_ecm_events', 'ecm-events', array( 'ECM_Events', 'admin_page' ) );
		add_submenu_page( 'ecm-dashboard', 'گزارش‌ها', 'گزارش‌ها', 'view_ecm_reports', 'ecm-reports', array( $this, 'report_page' ) );
		add_submenu_page( 'ecm-dashboard', 'تنظیمات', 'تنظیمات', 'manage_ecm_settings', 'ecm-settings', array( $this, 'settings_page' ) );
		add_submenu_page( 'ecm-dashboard', 'پیامک و اتصال', 'پیامک و اتصال', 'manage_ecm_sms', 'ecm-sms', array( 'ECM_SMS', 'admin_page' ) );
	}

	public function landing_page(): void {
		if ( current_user_can( 'view_ecm_reports' ) ) {
			$this->report_page();
			return;
		}
		if ( current_user_can( 'manage_ecm_settings' ) ) {
			$this->settings_page();
			return;
		}
		if ( current_user_can( 'manage_ecm_sms' ) ) {
			ECM_SMS::admin_page();
			return;
		}
		if ( current_user_can( 'record_ecm_attendance' ) ) {
			echo '<div class="wrap ecm-admin" dir="rtl"><div class="ecm-admin__header"><div><span>CHECK-IN OPERATOR</span><h1>مدیر ثبت حضور</h1><p>برای اسکن کارت‌ها و مشاهده ثبت‌های خود، پنل موبایلی مستقل افزونه را باز کنید.</p></div><a class="button button-primary" href="' . esc_url( ECM_Portal::url( array( 'ecm_portal_view' => 'checkin' ) ) ) . '">بازکردن پنل ثبت حضور</a></div></div>';
			return;
		}
		if ( ECM_Capabilities::can_use_tools() ) {
			ECM_Tools::page();
			return;
		}
		wp_die( 'Forbidden', 403 );
	}

	public function assets( string $hook ): void {
		if ( false === strpos( $hook, 'ecm-' ) ) {
			return;
		}
		wp_enqueue_style( 'ecm-admin', ECM_URL . 'assets/css/admin.css', array(), ECM_VERSION );
		wp_add_inline_style( 'ecm-admin', ECM_Upgrade::style() );
		wp_add_inline_style( 'ecm-admin', ECM_Upgrade::custom_style( 'admin' ) );
		wp_enqueue_script( 'ecm-admin', ECM_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), ECM_VERSION, true );
		wp_localize_script(
			'ecm-admin',
			'ECM_ADMIN',
			array(
				'fieldTypes' => self::field_types(),
				'confirm'    => 'این مورد حذف شود؟',
				'patternProfiles' => array_map(
					static fn( array $profile ): string => sanitize_text_field( (string) ( $profile['label'] ?? '' ) ),
					array_filter( (array) ( ECM_Utils::settings()['sms_pattern_profiles'] ?? array() ), static fn( $profile ): bool => is_array( $profile ) && ! empty( $profile['enabled'] ) )
				),
				'events'     => array_map(
					static fn( object $event ): array => array( 'id' => (int) $event->id, 'title' => (string) $event->title ),
					ECM_Events::all()
				),
				'defaultEventId' => ECM_Events::default_event_id(),
			)
		);
	}

	public static function field_types(): array {
		return array(
			'text'     => 'متن کوتاه',
			'textarea' => 'متن بلند',
			'email'    => 'ایمیل',
			'tel'      => 'تلفن',
			'number'   => 'عدد',
			'select'   => 'فهرست انتخابی',
			'radio'    => 'رادیویی',
			'checkbox' => 'چند انتخابی',
			'date'     => 'تاریخ',
			'time'     => 'زمان',
			'url'      => 'نشانی وب',
			'html'     => 'HTML نمایشی',
			'guest'    => 'مهمان (نام و موبایل)',
		);
	}

	public function settings_page(): void {
		if ( ! current_user_can( 'manage_ecm_settings' ) ) {
			wp_die( esc_html__( 'اجازه دسترسی ندارید.', 'event-card-manager' ) );
		}
		$settings = ECM_Utils::settings();
		$pages    = (array) get_option( 'ecm_pages', array() );
		?>
		<div class="wrap ecm-admin" dir="rtl">
			<div class="ecm-admin__header"><div><span>EVENT CARD MANAGER</span><h1>تنظیمات عمومی سامانه</h1><p>تعریف رویداد، فرم، پرداخت، کارت، بازیابی و حضور به صفحه همان رویداد منتقل شده‌اند. این صفحه فقط تنظیمات مشترک سامانه را نگه می‌دارد.</p></div><div class="ecm-action-row"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ecm-events' ) ); ?>">مدیریت رویدادها و فرم‌ها</a></div></div>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div><?php endif; ?>
			<nav class="ecm-tabs" aria-label="بخش‌های تنظیمات"><button type="button" class="is-active" data-tab="access">دسترسی و برگه‌ها</button><button type="button" data-tab="appearance">ظاهر و نمایش مشترک</button></nav>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecm-settings-form">
				<input type="hidden" name="action" value="ecm_save_settings"><?php wp_nonce_field( 'ecm_save_settings', 'ecm_nonce' ); ?>
				<section class="ecm-panel is-active" data-panel="access">
						<div class="ecm-subpanel"><h2>دسترسی‌های ریزدانه و User Role Editor</h2><p>افزونه نقش‌های سفارشی را بازنویسی نمی‌کند. در User Role Editor، capabilityهای لازم را مستقیماً به هر نقش بدهید.</p><div class="ecm-capability-list"><?php foreach ( ECM_Capabilities::all() as $capability => $label ) : ?><div><code><?php echo esc_html( $capability ); ?></code><span><?php echo esc_html( $label ); ?></span></div><?php endforeach; ?></div><p><small>«مدیر رویداد» گزارش‌ها را می‌بیند و «مدیر ثبت حضور» فقط برای ثبت حضور و مشاهده سوابق خودش ساخته شده است. مدیر کل وردپرس همه دسترسی‌ها را دارد.</small></p></div>
					<div class="ecm-subpanel"><h2>اتصال برگه‌ها</h2><div class="ecm-admin-grid ecm-admin-grid--2">
					<?php foreach ( array( 'registration' => 'ثبت‌نام', 'recovery' => 'بازیابی', 'card' => 'کارت موفق', 'attendance' => 'ثبت حضور', 'manager' => 'گزارش مدیر', 'portal' => 'پنل اختصاصی نقش‌ها' ) as $key => $label ) : ?><label class="ecm-setting"><span><?php echo esc_html( $label ); ?></span><?php wp_dropdown_pages( array( 'name' => 'pages[' . $key . ']', 'selected' => (int) ( $pages[ $key ] ?? 0 ), 'show_option_none' => '— انتخاب برگه —' ) ); ?></label><?php endforeach; ?>
					</div><p><a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( ECM_Portal::url() ); ?>">بازکردن پنل اختصاصی نقش‌ها</a> <code><?php echo esc_html( ECM_Portal::url() ); ?></code></p></div>
						<?php if ( current_user_can( 'manage_ecm_sms' ) ) : ?><div class="ecm-subpanel"><h2>پیامک</h2><p>تنظیمات پیامک، تست اتصال، ارسال آزمایشی و گزارش خطا به صفحه مستقل منتقل شده است.</p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ecm-sms' ) ); ?>">بازکردن پیامک و اتصال</a></div><?php endif; ?>
					<label class="ecm-setting ecm-danger-option"><input type="checkbox" name="settings[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>> حذف کامل داده‌ها هنگام Uninstall افزونه</label>
				</section>
                <?php ECM_Tools::settings_markup($settings); ?>
                <input type="hidden" name="ecm_form_complete" value="1">
				<div class="ecm-savebar"><button class="button button-primary button-hero" type="submit">ذخیره همه تنظیمات</button><span>تغییرات همه تب‌ها یک‌جا ذخیره می‌شوند.</span></div>
			</form>
		</div>
		<?php
	}

	private function setting_input( string $label, string $key, $value, string $type = 'text', string $help = '' ): void {
		?>
		<label class="ecm-setting"><span><?php echo esc_html( $label ); ?></span><input type="<?php echo esc_attr( $type ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>"><?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?></label>
		<?php
	}

	private function setting_editor( string $label, string $key, string $value ): void {
		echo '<div class="ecm-setting ecm-setting--editor"><span>' . esc_html( $label ) . '</span>';
		wp_editor( $value, 'ecm_' . $key, array( 'textarea_name' => 'settings[' . $key . ']', 'textarea_rows' => 6, 'media_buttons' => false, 'teeny' => true ) );
		echo '<small>HTML ایمن وردپرس پشتیبانی می‌شود.</small></div>';
	}

	public static function field_row( int $index, array $field ): void {
		$options = implode( "\n", (array) ( $field['options'] ?? array() ) );
		?>
		<div class="ecm-builder-row" data-field-row>
			<div class="ecm-drag" title="جابه‌جایی">⋮⋮</div>
			<div class="ecm-builder-row__main">
				<div class="ecm-admin-grid ecm-admin-grid--4">
					<label>عنوان<input type="text" name="fields[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $field['label'] ?? '' ); ?>" required></label>
					<label>کلید انگلیسی<input type="text" name="fields[<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $field['key'] ?? '' ); ?>" pattern="[a-zA-Z][a-zA-Z0-9_]*" dir="ltr" required></label>
					<label>نوع<select name="fields[<?php echo esc_attr( $index ); ?>][type]" data-field-type><?php foreach ( self::field_types() as $type => $label ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $field['type'] ?? 'text', $type ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<label class="ecm-inline-check"><input type="checkbox" name="fields[<?php echo esc_attr( $index ); ?>][required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?>> ضروری</label>
				</div>
					<div class="ecm-admin-grid ecm-admin-grid--2"><label>Placeholder / محتوای HTML<input type="text" name="fields[<?php echo esc_attr( $index ); ?>][placeholder]" value="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"></label><label>گزینه‌ها (هر خط یک مورد)<textarea name="fields[<?php echo esc_attr( $index ); ?>][options]" rows="3"><?php echo esc_textarea( $options ); ?></textarea></label></div>
			</div>
			<button type="button" class="ecm-icon-button ecm-remove" data-remove-row aria-label="حذف">×</button>
		</div>
		<?php
	}

	public function save_settings(): void {
		if ( ! current_user_can( 'manage_ecm_settings' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'ecm_save_settings', 'ecm_nonce' );
        if(empty($_POST['ecm_form_complete']))wp_die('درخواست تنظیمات ناقص است؛ محدودیت max_input_vars/post_max_size هاست را بررسی کنید. هیچ تنظیمی تغییر نکرد.');
		$old = ECM_Utils::settings();
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$new = $old;
		$new['delete_data_on_uninstall'] = empty( $raw['delete_data_on_uninstall'] ) ? 0 : 1;
			if ( isset( $raw['manager_roles'] ) ) {
				$roles                = array_map( 'sanitize_key', (array) $raw['manager_roles'] );
				$roles[]              = 'administrator';
				$new['manager_roles'] = array_values( array_unique( array_intersect( $roles, array_keys( wp_roles()->roles ) ) ) );
			}

	        $new=ECM_Tools::sanitize_settings($new,$raw);
		update_option( 'ecm_settings', $new, false );
        if(empty($new['external_role_control'])) $this->sync_role_caps( $new['manager_roles'] );
		$pages = array();
		foreach ( array( 'registration', 'recovery', 'card', 'attendance', 'manager', 'portal' ) as $key ) {
			$pages[ $key ] = isset( $_POST['pages'][ $key ] ) ? absint( $_POST['pages'][ $key ] ) : 0;
		}
		update_option( 'ecm_pages', $pages, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ecm-settings', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function sanitize_fields( array $rows ): array {
		$fields = array();
		$keys   = array();
		foreach ( array_slice( $rows, 0, 100 ) as $row ) {
			$key = sanitize_key( $row['key'] ?? '' );
			if ( ! $key || isset( $keys[ $key ] ) ) {
				continue;
			}
			$type = sanitize_key( $row['type'] ?? 'text' );
			$type = array_key_exists( $type, self::field_types() ) ? $type : 'text';
			$opts = preg_split( '/\R/u', (string) ( $row['options'] ?? '' ) );
			$opts = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $opts ) ) ) );
			$fields[] = array(
				'key'         => $key,
				'label'       => sanitize_text_field( $row['label'] ?? $key ),
				'type'        => $type,
				'required'    => empty( $row['required'] ) ? 0 : 1,
				'placeholder' => 'html' === $type ? wp_kses_post( $row['placeholder'] ?? '' ) : sanitize_text_field( $row['placeholder'] ?? '' ),
				'options'     => $opts,
			);
			$keys[ $key ] = true;
		}
		return $fields;
	}

	private function sync_role_caps( array $allowed ): void {
		foreach ( array_keys( wp_roles()->roles ) as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}
			if ( in_array( $role_key, $allowed, true ) ) {
				$role->add_cap( 'view_ecm_reports' );
			} elseif ( 'administrator' !== $role_key ) {
				$role->remove_cap( 'view_ecm_reports' );
			}
		}
	}

	public function report_page(): void {
		if ( ! current_user_can( 'view_ecm_reports' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		echo '<div class="wrap ecm-admin ecm-report-wrap" dir="rtl">';
		echo ECM_Reports::render( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	public static function report_columns(): array {
		$columns = array(
			'public_id'        => 'کد کارت',
			'event_title'      => 'رویداد',
			'guest_relationship' => 'معرف / مهمان‌ها',
			'created_at'       => 'زمان ثبت‌نام',
			'amount_original'  => 'مبلغ اصلی',
			'amount_rial'      => 'مبلغ ریالی',
			'payment_status'   => 'وضعیت پرداخت',
			'paid_at'          => 'زمان پرداخت',
			'payment_reference'=> 'شماره مرجع',
			'recoveries'       => 'بازیابی‌ها',
			'prints'           => 'چاپ‌ها',
			'pdfs'             => 'ذخیره PDF',
			'attendance_count' => 'تعداد حضور',
			'attendance_times' => 'زمان‌های حضور',
			'attendance_recorders' => 'ثبت‌کننده‌های حضور',
			'attendance_devices'   => 'دستگاه ثبت‌کننده حضور',
		);
		$field_sets = array( (array) ECM_Utils::settings()['fields'] );
		foreach ( ECM_Events::all() as $event ) {
			$field_sets[] = (array) ( ECM_Events::config( $event )['fields'] ?? array() );
		}
		foreach ( $field_sets as $fields ) {
			foreach ( $fields as $field ) {
				if ( ! empty( $field['key'] ) && ! in_array( ( $field['type'] ?? '' ), array( 'html', 'guest' ), true ) ) {
					$columns[ 'field:' . $field['key'] ] = $field['label'];
				}
			}
		}
		global $wpdb;
		$meta_table = ECM_DB::table( 'registration_meta' );
		$historical_keys = $wpdb->get_col( "SELECT DISTINCT field_key FROM {$meta_table} ORDER BY field_key ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $historical_keys as $key ) {
			if ( ! isset( $columns[ 'field:' . $key ] ) ) {
				$columns[ 'field:' . $key ] = 'فیلد قدیمی: ' . $key;
			}
		}
		if ( self::finance_hidden() ) foreach(array('amount_original','amount_rial','payment_status','paid_at','payment_reference') as $key) unset($columns[$key]);
		if ( ECM_Registration_Admin::is_site_administrator() ) $columns['is_test']='نوع ثبت‌نام';
        $columns['sms_report']='گزارش پیامک';
		return $columns;
	}

	private static function finance_hidden(): bool {
		$filters = self::report_filters();
		if ( ! empty( $filters['events'] ) ) {
			foreach ( $filters['events'] as $event_id ) {
				$event = ECM_Events::event( (int) $event_id );
				if ( $event && ! empty( ECM_Events::config( $event )['payment_required'] ) ) return false;
			}
			return true;
		}
		foreach ( ECM_Events::all() as $event ) {
			if ( ! empty( ECM_Events::config( $event )['payment_required'] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function report_markup( bool $admin = false ): string {
		$all_columns = self::report_columns();
		$filters     = self::report_filters();
		$user_id     = get_current_user_id();
		$selected = isset($_GET['cols']) && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])),'ecm_columns') ? array_map('sanitize_text_field',(array)wp_unslash($_GET['cols'])) : self::selected_columns();
		$selected = array_values( array_intersect( $selected, array_keys( $all_columns ) ) );
        if ( isset( $_GET['cols'] ) && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])),'ecm_columns') ) {
			update_user_meta( $user_id, 'ecm_report_columns', $selected );
		}
		$page_options = array( 25, 50, 100, 250 );
		$per_page = isset( $_GET['ecm_per_page'] ) && in_array( absint( $_GET['ecm_per_page'] ), $page_options, true ) ? absint( $_GET['ecm_per_page'] ) : 100;
		$stats    = self::report_stats( $filters );
		$last_page = max( 1, (int) ceil( (int) $stats->total / $per_page ) );
		$paged    = min( $last_page, max( 1, absint( $_GET['ecm_paged'] ?? 1 ) ) );
		$rows     = self::report_rows( $per_page, ( $paged - 1 ) * $per_page, $filters );
		self::prime_report_cache( $rows, $filters );
		$events = self::events_for_rows( $rows );
		$event_options = ECM_Events::all();
		global $wpdb;
		$session_table = ECM_DB::table( 'sessions' );
		$session_options = $wpdb->get_results( "SELECT s.id,s.title,s.event_id,e.title AS event_title FROM {$session_table} s LEFT JOIN " . ECM_DB::table( 'event_definitions' ) . " e ON e.id=s.event_id ORDER BY s.start_at_utc DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$finance_hidden = self::finance_hidden();
		$can_manage_registrations = $admin && ECM_Registration_Admin::is_site_administrator();
		$base_url = $admin ? admin_url( 'admin.php?page=ecm-reports' ) : get_permalink();
		$filter_query = array_filter( array( 'ecm_view' => 'registrations', 'ecm_portal_view' => ( ! $admin && isset( $_GET['ecm_portal_view'] ) ) ? 'reports' : '', 'ecm_events' => ECM_Report_Scope::query_args()['ecm_events'], 'ecm_per_page' => $per_page, 'ecm_session' => $filters['session'], 'ecm_payment' => $filters['payment'], 'ecm_test' => $filters['test'], 'ecm_from' => $filters['from'], 'ecm_to' => $filters['to'], 'ecm_search' => $filters['search'] ) );
		$export_url = add_query_arg( $filter_query, admin_url( 'admin-post.php?action=ecm_export_csv' ) );
        $document_url=wp_nonce_url(str_replace('action=ecm_export_csv','action=ecm_report_document',$export_url),'ecm_report_document');
		$pagination_url = add_query_arg( array_merge( $filter_query, array( 'ecm_paged' => 999999999 ) ), $base_url );
		$pagination_base = str_replace( '999999999', '%#%', $pagination_url ); // paginate_links escapes its own generated anchors.
		ob_start();
		?>
		<div class="ecm-report">
			<?php if ( $can_manage_registrations && isset( $_GET['ecm_bulk_result'] ) ) : $bulk_result = sanitize_key( wp_unslash( $_GET['ecm_bulk_result'] ) ); $bulk_count = absint( $_GET['ecm_bulk_count'] ?? 0 ); $bulk_messages = array( 'mark_test' => number_format_i18n( $bulk_count ) . ' ثبت‌نام به‌عنوان تستی علامت‌گذاری شد.', 'unmark_test' => number_format_i18n( $bulk_count ) . ' ثبت‌نام به حالت عادی بازگشت.', 'delete' => number_format_i18n( $bulk_count ) . ' ثبت‌نام و اطلاعات وابسته آن حذف شد.', 'confirm_required' => 'برای حذف، تأیید حذف دائمی را فعال کنید.', 'invalid' => 'عملیات یا ثبت‌نام انتخاب‌شده معتبر نیست.' ); ?><div class="notice <?php echo esc_attr( in_array( $bulk_result, array( 'mark_test', 'unmark_test', 'delete' ), true ) ? 'notice-success' : 'notice-error' ); ?>"><p><?php echo esc_html( $bulk_messages[ $bulk_result ] ?? 'نتیجه عملیات نامشخص است.' ); ?></p></div><?php endif; ?>
			<div class="ecm-report__header"><div><span class="ecm-kicker">LIVE REPORT</span><h1>فهرست جامع ثبت‌نام‌ها</h1><p>زمان‌ها بر اساس <?php echo esc_html( wp_timezone_string() ); ?> نمایش داده می‌شوند.</p></div><div class="ecm-report__actions"><?php if ( ! $admin && current_user_can( 'record_ecm_attendance' ) ) : ?><a class="button" href="<?php echo esc_url( ECM_Portal::url( array( 'ecm_portal_view' => 'checkin' ) ) ); ?>">پنل ثبت حضور</a><?php endif; ?><a class="button" href="<?php echo esc_url( wp_nonce_url( $export_url, 'ecm_export_csv' ) ); ?>">خروجی Excel/CSV</a><a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url($document_url); ?>">چاپ / PDF گزارش</a><?php if ( ! $admin ) : ?><a class="button" href="<?php echo esc_url( ECM_Portal::url() ); ?>">خانه پنل</a><?php endif; ?></div></div>
			<form class="ecm-report-filter ecm-no-print<?php echo $finance_hidden ? ' ecm-report-filter--no-finance' : ''; ?>" method="get" action="<?php echo esc_url( $base_url ); ?>"><?php if ( $admin ) : ?><input type="hidden" name="page" value="ecm-reports"><?php elseif ( isset( $_GET['ecm_portal_view'] ) ) : ?><input type="hidden" name="ecm_portal_view" value="reports"><?php endif; ?><input type="hidden" name="ecm_view" value="registrations"><?php echo ECM_Report_Scope::hidden_fields(); ?><label>تعداد هر صفحه<select name="ecm_per_page"><?php foreach ( $page_options as $count ) : ?><option value="<?php echo esc_attr( $count ); ?>" <?php selected( $per_page, $count ); ?>><?php echo esc_html( $count ); ?></option><?php endforeach; ?></select></label><label>جست‌وجو<input type="search" name="ecm_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="نام، کد کارت یا مرجع"></label><p class="ecm-filter-hint">رویدادها از انتخابگر مشترک بالای گزارش تعیین می‌شوند.</p><label>نوبت حضور<select name="ecm_session"><option value="0">همه نوبت‌ها</option><?php foreach ( $session_options as $session_option ) : ?><option value="<?php echo esc_attr( $session_option->id ); ?>" <?php selected( $filters['session'], (int) $session_option->id ); ?>><?php echo esc_html( ( $session_option->event_title ?: 'رویداد' ) . ' — ' . $session_option->title ); ?></option><?php endforeach; ?></select></label><?php if ( ! $finance_hidden ) : ?><label>وضعیت<select name="ecm_payment"><option value="">همه</option><option value="paid" <?php selected( $filters['payment'], 'paid' ); ?>>پرداخت موفق</option><option value="offline_confirmed" <?php selected( $filters['payment'], 'offline_confirmed' ); ?>>ثبت بدون درگاه</option><option value="pending" <?php selected( $filters['payment'], 'pending' ); ?>>در انتظار</option><option value="not_required" <?php selected( $filters['payment'], 'not_required' ); ?>>رایگان</option></select></label><?php endif; ?><?php if ( $can_manage_registrations ) : ?><label>ثبت‌نام تستی<select name="ecm_test"><option value="live" <?php selected( $filters['test'], 'live' ); ?>>فقط ثبت‌نام‌های اصلی</option><option value="test" <?php selected( $filters['test'], 'test' ); ?>>فقط تستی‌ها</option><option value="all" <?php selected( $filters['test'], 'all' ); ?>>همه</option></select></label><?php endif; ?><label>از تاریخ<input type="date" name="ecm_from" value="<?php echo esc_attr( $filters['from'] ); ?>"></label><label>تا تاریخ<input type="date" name="ecm_to" value="<?php echo esc_attr( $filters['to'] ); ?>"></label><button class="button button-primary">اعمال فیلتر</button></form>
			<div class="ecm-stats"><div><span>ثبت‌نام‌ها</span><strong><?php echo esc_html( number_format_i18n( (int) $stats->total ) ); ?></strong></div><?php if ( ! $finance_hidden ) : ?><div><span>پرداخت موفق</span><strong><?php echo esc_html( number_format_i18n( (int) $stats->paid ) ); ?></strong></div><div><span>جمع ریالی</span><strong><?php echo esc_html( number_format_i18n( (int) $stats->total_paid ) ); ?></strong></div><?php endif; ?><div><span>حضورهای ثبت‌شده</span><strong><?php echo esc_html( number_format_i18n( (int) $stats->attendance_total ) ); ?></strong></div></div>
			<details class="ecm-column-picker"><summary>انتخاب ستون‌های جدول</summary><form method="get" action="<?php echo esc_url( $base_url ); ?>"><?php if ( $admin ) : ?><input type="hidden" name="page" value="ecm-reports"><?php endif; ?><input type="hidden" name="ecm_view" value="registrations"><input type="hidden" name="ecm_per_page" value="<?php echo esc_attr( $per_page ); ?>"><?php echo ECM_Report_Scope::hidden_fields(); ?><?php foreach ( array( 'ecm_session' => 'session', 'ecm_payment' => 'payment', 'ecm_test' => 'test', 'ecm_from' => 'from', 'ecm_to' => 'to', 'ecm_search' => 'search' ) as $query_key => $filter_key ) : ?><input type="hidden" name="<?php echo esc_attr( $query_key ); ?>" value="<?php echo esc_attr( $filters[ $filter_key ] ); ?>"><?php endforeach; ?><?php wp_nonce_field('ecm_columns'); ?><input type="hidden" name="cols[]" value=""><div class="ecm-column-actions"><button type="button" class="button" data-columns="all">انتخاب همه</button><button type="button" class="button" data-columns="none">غیرفعال کردن همه</button></div><div class="ecm-check-grid"><?php foreach ( $all_columns as $key => $label ) : ?><label><input type="checkbox" name="cols[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?>> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></div><button class="button button-primary">اعمال ستون‌ها</button></form></details>
			<?php if ( $can_manage_registrations ) : ?><form class="ecm-registration-bulk ecm-no-print" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return this.bulk_operation.value!=='delete'||window.confirm('ثبت‌نام‌های انتخابی و تمام کارت‌ها، حضورها و پیامک‌های وابسته برای همیشه حذف شوند؟');"><input type="hidden" name="action" value="ecm_registration_bulk"><input type="hidden" name="return_url" value="<?php echo esc_attr( add_query_arg( $filter_query, $base_url ) ); ?>"><?php wp_nonce_field( 'ecm_registration_bulk', 'ecm_registration_bulk_nonce' ); ?><div class="ecm-registration-bulk__bar"><label>عملیات روی موارد انتخابی<select name="bulk_operation" required><option value="">— انتخاب عملیات —</option><option value="mark_test">علامت‌گذاری به‌عنوان ثبت‌نام تستی</option><option value="unmark_test">بازگردانی به ثبت‌نام عادی</option><option value="delete">حذف دائمی ثبت‌نام و اطلاعات وابسته</option></select></label><label class="ecm-registration-bulk__confirm"><input type="checkbox" name="confirm_delete" value="1"> تأیید حذف دائمی؛ فقط برای عملیات حذف</label><button class="button button-primary">اجرا</button></div><?php endif; ?>
			<div class="ecm-table-scroll"><table class="widefat striped ecm-report-table"><thead><tr><?php if ( $can_manage_registrations ) : ?><th class="ecm-report-select"><input type="checkbox" data-ecm-select-all aria-label="انتخاب همه"></th><?php endif; ?><?php foreach ( $selected as $column ) : ?><th><?php echo esc_html( $all_columns[ $column ] ); ?></th><?php endforeach; ?><th class="ecm-no-print">جزئیات</th></tr></thead><tbody>
			<?php if ( ! $rows ) : ?><tr class="ecm-report-empty"><td colspan="<?php echo esc_attr( count( $selected ) + 1 + ( $can_manage_registrations ? 1 : 0 ) ); ?>">هنوز ثبت‌نامی وجود ندارد.</td></tr><?php endif; ?>
			<?php foreach ( $rows as $row ) : $data = ECM_DB::decode_data( $row ); $row_events = $events[ $row->id ] ?? array(); ?>
				<tr class="<?php echo ! empty( $row->is_test ) ? 'ecm-registration-is-test' : ''; ?>"><?php if ( $can_manage_registrations ) : ?><td class="ecm-report-select"><input type="checkbox" name="registration_ids[]" value="<?php echo esc_attr( $row->id ); ?>" aria-label="انتخاب ثبت‌نام <?php echo esc_attr( $row->public_id ); ?>"><?php if ( ! empty( $row->is_test ) ) : ?><span class="ecm-badge ecm-badge--test">تستی</span><?php endif; ?></td><?php endif; ?><?php foreach ( $selected as $column ) : ?><td data-label="<?php echo esc_attr( $all_columns[ $column ] ); ?>"><?php echo wp_kses_post( self::column_value( $column, $row, $data, $row_events ) ); ?></td><?php endforeach; ?><td class="ecm-no-print" data-label="جزئیات"><details><summary>رویدادها</summary><ul class="ecm-event-list"><?php foreach ( $row_events as $event ) : ?><li><div><b><?php echo esc_html( self::event_label( $event->event_type ) ); ?></b><?php if ( $event->meta && '{}' !== $event->meta && '[]' !== $event->meta ) : ?><small><?php echo esc_html( $event->meta ); ?></small><?php endif; ?><?php if ( $event->user_agent ) : ?><small class="ecm-event-tech"><?php echo esc_html( $event->user_agent ); ?></small><?php endif; ?></div><time><?php echo esc_html( ECM_Utils::utc_to_local( $event->created_at ) ); ?></time></li><?php endforeach; ?></ul></details></td></tr>
			<?php endforeach; ?>
			</tbody></table></div><?php if ( $can_manage_registrations ) : ?></form><?php endif; ?>
			<?php if ( (int) $stats->total > $per_page ) : ?><div class="ecm-pagination"><?php echo wp_kses_post( paginate_links( array( 'base' => $pagination_base, 'format' => '', 'current' => $paged, 'total' => (int) ceil( $stats->total / $per_page ), 'prev_text' => '→', 'next_text' => '←' ) ) ); ?></div><?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function report_rows( int $limit = 0, int $offset = 0, array $filters = array() ): array {
		global $wpdb;
		$r = ECM_DB::table( 'registrations' );
		$a = ECM_DB::table( 'attendance' );
		$e = ECM_DB::table( 'event_definitions' );
		list( $where, $args ) = self::report_where( $filters );
		$session_id = absint( $filters['session'] ?? 0 );
		$attendance_scope = $session_id ? ' WHERE session_id=' . $session_id : '';
		$sql = "SELECT r.*, e.title AS event_title, COALESCE(a.attendance_count, 0) AS attendance_count FROM {$r} r LEFT JOIN {$e} e ON e.id=r.event_id LEFT JOIN (SELECT registration_id, COUNT(*) AS attendance_count FROM {$a}{$attendance_scope} GROUP BY registration_id) a ON a.registration_id = r.id WHERE {$where} ORDER BY r.id DESC";
		if ( $limit > 0 ) {
			$sql .= ' LIMIT %d OFFSET %d';
			$args[] = $limit;
			$args[] = $offset;
		}
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function report_stats( array $filters = array() ): object {
		global $wpdb;
		$r = ECM_DB::table( 'registrations' );
		$a = ECM_DB::table( 'attendance' );
		list( $where, $args ) = self::report_where( $filters );
		$session_id = absint( $filters['session'] ?? 0 );
		$attendance_scope = $session_id ? ' WHERE session_id=' . $session_id : '';
		$sql = "SELECT COUNT(*) AS total, COALESCE(SUM(r.payment_status = 'paid'),0) AS paid, COALESCE(SUM(CASE WHEN r.payment_status = 'paid' THEN r.amount_rial ELSE 0 END),0) AS total_paid, COALESCE(SUM(ax.attendance_count),0) AS attendance_total FROM {$r} r LEFT JOIN (SELECT registration_id,COUNT(*) AS attendance_count FROM {$a}{$attendance_scope} GROUP BY registration_id) ax ON ax.registration_id=r.id WHERE {$where}";
		$stats = $wpdb->get_row( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $stats ?: (object) array( 'total' => 0, 'paid' => 0, 'total_paid' => 0, 'attendance_total' => 0 );
	}

	public static function report_filters(): array {
		$payment = isset( $_GET['ecm_payment'] ) ? sanitize_key( wp_unslash( $_GET['ecm_payment'] ) ) : '';
		$test_visibility = sanitize_key( wp_unslash( $_GET['ecm_test'] ?? 'live' ) );
		if ( ! ECM_Registration_Admin::is_site_administrator() || ! in_array( $test_visibility, array( 'live', 'test', 'all' ), true ) ) {
			$test_visibility = 'live';
		}
		return array(
			'event'   => count( ECM_Report_Scope::event_ids() ) === 1 ? ECM_Report_Scope::event_ids()[0] : 0,
			'events'  => ECM_Report_Scope::event_ids(),
			'session' => isset( $_GET['ecm_session'] ) ? absint( $_GET['ecm_session'] ) : 0,
			'payment' => in_array( $payment, array( 'paid', 'offline_confirmed', 'pending', 'not_required' ), true ) ? $payment : '',
			'test'    => $test_visibility,
			'from'    => isset( $_GET['ecm_from'] ) ? sanitize_text_field( wp_unslash( $_GET['ecm_from'] ) ) : '',
			'to'      => isset( $_GET['ecm_to'] ) ? sanitize_text_field( wp_unslash( $_GET['ecm_to'] ) ) : '',
			'search'  => isset( $_GET['ecm_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ecm_search'] ) ) : '',
		);
	}

	private static function report_where( array $filters ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['events'] ) ) { $where[] = 'r.event_id IN (' . implode( ',', array_map( 'absint', $filters['events'] ) ) . ')'; }
		if ( ! empty( $filters['session'] ) ) { $session = ECM_DB::table( 'sessions' ); $where[] = "r.event_id = (SELECT event_id FROM {$session} WHERE id=%d LIMIT 1)"; $args[] = absint( $filters['session'] ); }
		if ( ! empty( $filters['payment'] ) ) { $where[] = 'r.payment_status = %s'; $args[] = $filters['payment']; }
		$test_visibility = ECM_Registration_Admin::is_site_administrator() ? ( $filters['test'] ?? 'live' ) : 'live';
		if ( 'test' === $test_visibility ) $where[] = 'r.is_test = 1';
		elseif ( 'all' !== $test_visibility ) $where[] = 'r.is_test = 0';
		if ( ! empty( $filters['from'] ) && ( $utc = ECM_Utils::local_to_utc( $filters['from'] . ' 00:00:00' ) ) ) { $where[] = 'r.created_at >= %s'; $args[] = $utc; }
		if ( ! empty( $filters['to'] ) && ( $utc = ECM_Utils::local_to_utc( $filters['to'] . ' 23:59:59' ) ) ) { $where[] = 'r.created_at <= %s'; $args[] = $utc; }
		if ( ! empty( $filters['search'] ) ) { $like = '%' . $wpdb->esc_like( $filters['search'] ) . '%'; $where[] = '(r.public_id LIKE %s OR r.payment_reference LIKE %s OR r.form_data LIKE %s)'; array_push( $args, $like, $like, $like ); }
		return array( implode( ' AND ', $where ), $args );
	}

	public static function events_for_rows( array $rows ): array {
		if ( ! $rows ) { return array(); }
		global $wpdb;
		$ids   = implode( ',', array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) );
		$table = ECM_DB::table( 'events' );
		$list  = $wpdb->get_results( "SELECT * FROM {$table} WHERE registration_id IN ({$ids}) ORDER BY created_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$grouped = array();
		foreach ( $list as $event ) { $grouped[ $event->registration_id ][] = $event; }
		return $grouped;
	}

	/**
	 * Preload report details in bounded batches. This replaces several queries
	 * per table row with a fixed number of queries per page/export batch.
	 */
	public static function prime_report_cache( array $rows, array $filters = array() ): void {
		self::$report_cache = array( 'primed' => true, 'sms' => array(), 'attendance' => array(), 'users' => array(), 'relationships' => array() );
		$registration_ids = array_values( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) ) );
		if ( ! $registration_ids ) {
			return;
		}

		global $wpdb;
		$sms_table        = ECM_DB::table( 'sms' );
		$attendance_table = ECM_DB::table( 'attendance' );
		$session_table    = ECM_DB::table( 'sessions' );
		$session_id       = absint( $filters['session'] ?? 0 );
		$user_ids         = array();

		foreach ( array_chunk( $registration_ids, 500 ) as $chunk ) {
			$id_list = implode( ',', array_map( 'absint', $chunk ) );
			$sms_rows = $wpdb->get_results( "SELECT registration_id,status,provider_id,error_code,created_at FROM {$sms_table} WHERE registration_id IN ({$id_list}) ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $sms_rows as $sms_row ) {
				self::$report_cache['sms'][ (int) $sms_row->registration_id ][] = $sms_row;
			}

			$session_clause = $session_id ? $wpdb->prepare( ' AND a.session_id=%d', $session_id ) : '';
			$attendance_rows = $wpdb->get_results( "SELECT a.registration_id,a.recorded_by_user_id,a.recorder_name,a.recorder_code,a.method,a.device_label,a.user_agent,a.created_at,s.title FROM {$attendance_table} a LEFT JOIN {$session_table} s ON s.id=a.session_id WHERE a.registration_id IN ({$id_list}){$session_clause} ORDER BY a.created_at ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $attendance_rows as $attendance_row ) {
				self::$report_cache['attendance'][ (int) $attendance_row->registration_id ][] = $attendance_row;
				if ( empty( $attendance_row->recorder_name ) && ! empty( $attendance_row->recorded_by_user_id ) ) {
					$user_ids[] = (int) $attendance_row->recorded_by_user_id;
				}
			}
		}

		// One bounded query for the entire page; never one query per registration.
		if ( in_array( 'guest_relationship', self::selected_columns(), true ) ) {
			$participants = ECM_DB::table( 'participants' );
			$people = ECM_DB::table( 'people' );
			$ids_sql = implode( ',', $registration_ids );
			$links = $wpdb->get_results( "SELECT p.registration_id,p.id,p.invited_by_participant_id,p.participant_type,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name FROM {$participants} p LEFT JOIN {$people} pe ON pe.id=p.person_id WHERE p.registration_id IN ({$ids_sql}) AND p.status='active' ORDER BY p.registration_id,p.guest_order,p.id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $links as $link ) {
				self::$report_cache['relationships'][ (int) $link->registration_id ][] = $link;
			}
		}
		$user_ids = array_values( array_unique( array_filter( $user_ids ) ) );
		if ( $user_ids ) {
			foreach ( get_users( array( 'include' => $user_ids, 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
				self::$report_cache['users'][ (int) $user->ID ] = (string) $user->display_name;
			}
		}
	}

	public static function column_value( string $column, object $row, array $data, array $events ): string {
		if ( str_starts_with( $column, 'field:' ) ) {
			$value = ECM_Utils::field_value( $data, substr( $column, 6 ) ) ?: '—';
			if ( 'field:full_name' === $column && ! empty( $row->primary_person_id ) ) {
				return '<a class="ecm-report-person-link" href="' . esc_url( ECM_Reports::url( 'person', array( 'ecm_id' => (int) $row->primary_person_id ) ) ) . '">' . esc_html( $value ) . '</a>';
			}
			return esc_html( $value );
		}
		switch ( $column ) {
			case 'is_test': return ! empty( $row->is_test ) ? '<span class="ecm-badge ecm-badge--test">ثبت‌نام تستی</span>' : '<span class="ecm-badge">ثبت‌نام اصلی</span>';
			case 'guest_relationship':
				$people = self::$report_cache['relationships'][ (int) $row->id ] ?? array();
				if ( ! $people ) return '—';
				$names = array();
				$hosts = array();
				$guests_by_host = array();
				foreach ( $people as $person ) {
					if ( 'primary' === $person->participant_type ) {
						$hosts[ (int) $person->id ] = (string) ( $person->full_name ?: 'بدون نام' );
					} else {
						$guests_by_host[ (int) $person->invited_by_participant_id ][] = (string) ( $person->full_name ?: 'بدون نام' );
					}
				}
				foreach ( $people as $person ) {
					$is_guest = 'guest' === (string) $person->participant_type;
					$label = $is_guest ? 'مهمان' : 'معرف';
					$related = $is_guest ? 'معرف: ' . ( $hosts[ (int) $person->invited_by_participant_id ] ?? 'نامشخص' ) : 'مهمان‌ها: ' . implode( '، ', $guests_by_host[ (int) $person->id ] ?? array() );
					$names[] = '<span class="ecm-badge">' . esc_html( $label ) . '</span> ' . esc_html( $person->full_name ?: 'بدون نام' ) . ( $related && 'مهمان‌ها: ' !== $related ? ' <small>' . esc_html( $related ) . '</small>' : '' );
				}
				return implode( '<br>', $names );
			case 'event_title': return esc_html( (string) ( $row->event_title ?: 'رویداد قدیمی' ) );
			case 'sms_report':
				$logs = self::$report_cache['sms'][ (int) $row->id ] ?? array();
				return esc_html( $logs ? implode( ' • ', array_map( static fn( $log ) => $log->status . ' / ' . ECM_Utils::utc_to_local( $log->created_at ) . ' / ' . $log->provider_id . ' / ' . $log->error_code, $logs ) ) : '—' );
			case 'created_at': case 'paid_at': return esc_html( ECM_Utils::utc_to_local( $row->{$column} ) );
			case 'amount_original': return '<bdi>' . esc_html( number_format_i18n( (float) $row->amount_original, 2 ) . ' ' . $row->currency_label ) . '</bdi>';
			case 'amount_rial': return '<bdi>' . esc_html( number_format_i18n( (int) $row->amount_rial ) . ' ریال' ) . '</bdi>';
			case 'payment_status':
				$labels = array( 'paid' => 'پرداخت موفق', 'offline_confirmed' => 'ثبت‌شده بدون درگاه', 'pending' => 'در انتظار', 'not_required' => 'رایگان', 'failed' => 'ناموفق' );
				return '<span class="ecm-status ecm-status--' . esc_attr( $row->payment_status ) . '">' . esc_html( $labels[ $row->payment_status ] ?? $row->payment_status ) . '</span>';
			case 'recoveries': return self::event_summary( $events, 'card_recovered' );
			case 'prints': return self::event_summary( $events, 'card_print' );
			case 'pdfs': return self::event_summary( $events, 'card_pdf' );
			case 'attendance_times':
				$entries = self::$report_cache['attendance'][ (int) $row->id ] ?? array();
				return esc_html( $entries ? implode( ' • ', array_map( static fn( $entry ) => ( $entry->title ?: 'نوبت قدیمی' ) . ' / ' . ECM_Utils::utc_to_local( $entry->created_at ), $entries ) ) : '—' );
			case 'attendance_recorders':
				$entries = self::$report_cache['attendance'][ (int) $row->id ] ?? array();
				return esc_html( $entries ? implode( ' • ', array_map( static function ( $entry ) {
					$name = trim( (string) $entry->recorder_name );
					if ( ! $name && $entry->recorded_by_user_id ) {
						$name = self::$report_cache['users'][ (int) $entry->recorded_by_user_id ] ?? '';
					}
					$name = $name ?: ( 'self_service' === $entry->method ? 'خود کاربر' : ( $entry->recorder_code ? 'اپراتور حذف‌شده (' . $entry->recorder_code . ')' : 'ثبت‌کننده نامشخص' ) );
					return $name . ' / ' . ECM_Utils::utc_to_local( $entry->created_at );
				}, $entries ) ) : '—' );
			case 'attendance_devices':
				$entries = self::$report_cache['attendance'][ (int) $row->id ] ?? array();
				$labels=array();foreach($entries as $entry){$label=trim((string)$entry->device_label);if(!$label&&!empty($entry->user_agent))$label=(string)(ECM_Utils::device_from_user_agent((string)$entry->user_agent)['label']??'');if($label)$labels[]=$label.' / '.ECM_Utils::utc_to_local($entry->created_at);}
				return esc_html($labels?implode(' • ',$labels):'—');
			default: return esc_html( (string) ( $row->{$column} ?? '—' ) );
		}
	}

	private static function event_summary( array $events, string $type ): string {
		$matching = array_values( array_filter( $events, static fn( $event ) => $type === $event->event_type ) );
		if ( ! $matching ) { return '۰'; }
		return esc_html( count( $matching ) . ' — ' . implode( '، ', array_map( static fn( $event ) => ECM_Utils::utc_to_local( $event->created_at ), $matching ) ) );
	}

	private static function event_label( string $type ): string {
		$labels = array( 'registration_created' => 'ایجاد ثبت‌نام', 'registration_completed' => 'تکمیل ثبت‌نام رایگان', 'payment_success' => 'پرداخت موفق', 'payment_failed' => 'پرداخت ناموفق', 'card_recovered' => 'بازیابی کارت', 'card_print' => 'چاپ کارت', 'card_pdf' => 'ذخیره PDF', 'attendance_success' => 'حضور موفق', 'attendance_repeat' => 'تلاش تکراری', 'attendance_early' => 'زودتر از موعد', 'attendance_late' => 'پس از پایان بازه', 'attendance_outside' => 'بدون نوبت', 'attendance_outside_window' => 'خارج از بازه', 'sms_accepted' => 'پذیرش پیامک', 'sms_failed' => 'خطای پیامک', 'sms_retry_wait' => 'انتظار تلاش مجدد پیامک' );
		return $labels[ $type ] ?? $type;
	}

	public function export_csv(): void {
		if ( ! current_user_can( 'view_ecm_reports' ) ) { wp_die( 'Forbidden', 403 ); }
		check_admin_referer( 'ecm_export_csv' );
        $all=self::report_columns();$selected=self::selected_columns();
        $columns=array_intersect_key($all,array_flip($selected));
        if(!$columns) wp_die('ابتدا حداقل یک ستون را در گزارش انتخاب و اعمال کنید.');
		$filters = self::report_filters();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="event-report-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		$output = fopen( 'php://output', 'w' );
		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, array_values( $columns ) );
		$offset = 0;
		do {
			$rows   = self::report_rows( 500, $offset, $filters );
				self::prime_report_cache( $rows, $filters );
			$events = self::events_for_rows( $rows );
			foreach ( $rows as $row ) {
				$data = ECM_DB::decode_data( $row );
				$line = array();
				foreach ( array_keys( $columns ) as $column ) {
					$value = html_entity_decode(wp_strip_all_tags(self::column_value($column,$row,$data,$events[$row->id]??array())),ENT_QUOTES|ENT_HTML5,'UTF-8');
					$line[] = preg_match('/^[\s\x00-\x1F]*[=+\-@]/u',$value) ? "'" . $value : $value;
				}
				fputcsv( $output, $line );
			}
			$offset += 500;
		} while ( count( $rows ) === 500 );
		fclose( $output );
		exit;
	}
    public static function selected_columns(): array {
        $id=get_current_user_id();
        $cols=metadata_exists('user',$id,'ecm_report_columns')?(array)get_user_meta($id,'ecm_report_columns',true):array_merge(array('field:full_name','guest_relationship'),ECM_Utils::settings()['report_columns']);
        return array_values(array_intersect($cols,array_keys(self::report_columns())));
    }
}
