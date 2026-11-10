<?php

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in, two-step, administrator-only bulk issuance for Classic FREE events.
 * Never sends SMS, never touches Workflow instances, never updates existing data.
 * CSV is always supported. XLSX uses ZipArchive + SimpleXML when installed.
 */
final class ECM_Import {
	private const MAX_ROWS = 100;
	private const MAX_COLUMNS = 55;
	private const MAX_BYTES = 2097152;
	private const STAGE_SECONDS = 900;

	public static function hooks(): void {
		add_action( 'admin_post_ecm_import_preview', array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_ecm_import_execute', array( __CLASS__, 'handle_execute' ) );
		add_action( 'admin_post_ecm_import_template', array( __CLASS__, 'download_template' ) );
	}

	/** Bulk issuance is stage-only until transactional DB/HTTP QA is complete. */
	public static function available(): bool {
		return function_exists( 'wp_get_environment_type' )
			&& in_array( wp_get_environment_type(), array( 'staging', 'local', 'development' ), true )
			&& defined( 'ECM_BULK_IMPORT_STAGING' ) && true === ECM_BULK_IMPORT_STAGING;
	}

	private static function authorized(): bool {
		return self::available() && current_user_can( 'manage_ecm_imports' ) && ECM_Registration_Admin::is_site_administrator();
	}

	private static function checked(): void {
		if ( ! self::authorized() ) { wp_die( 'ورود گروهی فقط برای مدیر کل در محیط آزمایشی با تنظیم فعال‌سازی مجاز است.', 'دسترسی غیرمجاز', array( 'response' => 403 ) ); }
	}

	private static function event_check( int $id ): object {
		$event = ECM_Events::event( $id );
		if ( ! $event || 'published' !== (string) $event->status || ! (int) $event->enabled ) {
			return new WP_Error( 'ecm_import_inactive', 'رویداد باید منتشرشده و فعال باشد.' );
		}
		if ( 'classic' !== ECM_Workflow::mode_for_event( $event ) ) {
			return new WP_Error( 'ecm_import_workflow', 'صدور گروهی برای فرایند دارای تأیید مدیر مجاز نیست.' );
		}
		$config = ECM_Events::config( $event );
		if ( ! empty( $config['payment_required'] ) ) {
			return new WP_Error( 'ecm_import_payment', 'برای پیشگیری از صدور کارت بدون پرداخت، ورود گروهی فقط برای رویدادهای بدون پرداخت فعال است.' );
		}
		return $event;
	}

	private static function schema_hash( object $event ): string {
		$config = ECM_Events::config( $event );
		return hash( 'sha256', wp_json_encode( array( 'fields' => $config['fields'] ?? array(), 'sms_field' => $config['sms_field'] ?? '', 'name_field' => $config['name_field'] ?? '', 'payment_required' => $config['payment_required'] ?? 0, 'guest_limit' => (int) $event->guest_limit ) ) );
	}

	/** Expiring encrypted staging; tokens scoped to the current WordPress user. */
	private static function stage_key( string $token ): string {
		return 'ecm_imp_' . substr( hash_hmac( 'sha256', $token . ':' . get_current_user_id(), wp_salt( 'auth' ) ), 0, 38 );
	}
	private static function load( string $token ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) { return null; }
		$ciphertext = get_transient( self::stage_key( $token ) );
		if ( ! is_string( $ciphertext ) ) { return null; }
		$data = json_decode( ECM_Upgrade::decrypt( $ciphertext ), true );
		return is_array( $data ) && (int) ( $data['owner'] ?? 0 ) === get_current_user_id() ? $data : null;
	}
	private static function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'ecm-import' ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page(): void {
		self::checked();
		$events = array_filter( ECM_Events::all(), static fn( object $e ): bool => ! is_wp_error( self::event_check( (int) $e->id ) ) );
		$event_id = absint( $_GET['ecm_event_id'] ?? 0 );
		$preview = isset( $_GET['preview'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['preview'] ) ) : '';
		$staged = $preview ? self::load( $preview ) : null;
		$result_token = isset( $_GET['result'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['result'] ) ) : '';
		$result = preg_match( '/^[a-f0-9]{32}$/D', $result_token ) ? get_transient( 'ecm_imp_result_' . substr( hash_hmac( 'sha256', $result_token . ':' . get_current_user_id(), wp_salt( 'auth' ) ), 0, 38 ) ) : null;
		?>
		<div class="wrap ecm-admin ecm-import-page" dir="rtl">
			<h1>ورود گروهی و صدور کارت</h1>
			<p>در این نسخه فقط رویدادهای <strong>Classic بدون پرداخت</strong> پذیرفته می‌شوند. عملیات ابتدا پیش‌نمایش دارد و سپس با تأیید مجدد اجرا می‌شود. هیچ پیامکی ارسال نمی‌شود.</p>
			<?php if ( is_array( $result ) ) : ?>
			<div class="notice <?php echo empty( $result['error'] ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( (string) $result['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $preview && ! $staged ) : ?><div class="notice notice-warning"><p>پیش‌نمایش منقضی شده یا متعلق به این کاربر نیست. فایل را دوباره ارسال کنید.</p></div><?php endif; ?>
			<section class="ecm-subpanel">
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ecm_import_preview' ); ?><input type="hidden" name="action" value="ecm_import_preview">
					<p><label for="ecm-import-event">رویداد مقصد</label> <select id="ecm-import-event" name="event_id" required><option value="">انتخاب کنید</option><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr( $event->id ); ?>" <?php selected( $event_id, $event->id ); ?>><?php echo esc_html( $event->title ); ?></option><?php endforeach; ?></select></p>
					<p><label for="ecm-import-file">فایل CSV (UTF-8) یا XLSX</label> <input id="ecm-import-file" type="file" name="import_file" accept=".csv,.xlsx" required></p>
					<p><small>حداکثر <?php echo esc_html( self::MAX_ROWS ); ?> ردیف داده در هر سری و ۲ مگابایت. سطر اول باید کلید انگلیسی فیلدهای فرم باشد. ستون‌های اختیاری مهمان: guest1_name، guest1_mobile و ... . نسخه XLSX فقط زمانی پذیرفته می‌شود که ZipArchive و SimpleXML روی سرور فعال باشند.</small></p>
					<p><button type="submit" class="button button-primary">اعتبارسنجی و پیش‌نمایش</button></p>
				</form>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="get"><input type="hidden" name="action" value="ecm_import_template"><label>نمونه ستون‌های رویداد <select name="event_id"><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr( $event->id ); ?>" <?php selected( $event_id, $event->id ); ?>><?php echo esc_html( $event->title ); ?></option><?php endforeach; ?></select></label> <?php wp_nonce_field( 'ecm_import_template', '_wpnonce', false ); ?><button type="submit" class="button">دریافت نمونه CSV</button></form>
			</section>
			<?php if ( $staged ) : ?>
				<section class="ecm-subpanel"><h2>پیش‌نمایش فایل اعتبارسنجی‌شده</h2>
				<p>رویداد: <strong><?php echo esc_html( (string) ( ECM_Events::event( (int) $staged['event_id'] )->title ?? '' ) ); ?></strong> | ردیف‌های قابل ورود: <strong><?php echo esc_html( count( $staged['rows'] ) ); ?></strong> | انقضای پیش‌نمایش: ۱۵ دقیقه</p>
				<table class="widefat striped"><thead><tr><th>ردیف</th><th>نام</th><th>موبایل (پنهان‌شده)</th><th>تعداد مهمان</th></tr></thead><tbody><?php foreach ( array_slice( $staged['rows'], 0, 20 ) as $index => $row ) : ?><tr><td><?php echo esc_html( (int) $index + 2 ); ?></td><td><?php echo esc_html( (string) $row['name'] ); ?></td><td dir="ltr"><?php echo esc_html( substr( $row['mobile'], 0, 4 ) . '****' . substr( $row['mobile'], -3 ) ); ?></td><td><?php echo esc_html( count( $row['guests'] ) ); ?></td></tr><?php endforeach; ?></tbody></table>
				<p><strong>هشدار:</strong> تأیید نهایی بلافاصله ثبت‌نام و کارت/QR ایجاد می‌کند. این عملیات پیامک نمی‌فرستد و ثبت‌های تکراری مجاز نیستند.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ecm_import_execute"><input type="hidden" name="token" value="<?php echo esc_attr( $preview ); ?>">
					<?php wp_nonce_field( 'ecm_import_execute_' . $preview ); ?>
					<p><label><input type="checkbox" name="confirmed" value="1" required> بررسی کردم؛ همه این افراد باید بدون پرداخت کارت دریافت کنند.</label></p>
					<button class="button button-primary" type="submit">صدور گروهی و ثبت قطعی</button>
				</form>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function download_template(): void {
		self::checked(); check_admin_referer( 'ecm_import_template' );
		$event = self::event_check( absint( $_GET['event_id'] ?? 0 ) );
		if ( is_wp_error( $event ) ) { wp_die( esc_html( $event->get_error_message() ) ); }
		$config = ECM_Events::config( $event );
		$fields = array_values( array_filter( (array) ( $config['fields'] ?? array() ), static fn( array $field ): bool => ! in_array( (string) ( $field['type'] ?? '' ), array( 'html', 'guest' ), true ) ) );
		$headers = array_map( static fn( array $field ): string => sanitize_key( (string) $field['key'] ), $fields );
		if ( (int) $event->guest_limit > 0 ) { array_push( $headers, 'guest1_name', 'guest1_mobile' ); }
		nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ecm-import-template-event-' . (int) $event->id . '.csv"' );
		$out = fopen( 'php://output', 'w' ); fwrite( $out, "\xEF\xBB\xBF" ); fputcsv( $out, $headers ); fclose( $out ); exit;
	}

	public static function handle_preview(): void {
		self::checked(); check_admin_referer( 'ecm_import_preview' );
		$event = self::event_check( absint( $_POST['event_id'] ?? 0 ) );
		if ( is_wp_error( $event ) ) { self::error( $event->get_error_message() ); }
		$file = $_FILES['import_file'] ?? null;
		if ( ! is_array( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || (int) ( $file['size'] ?? 0 ) < 1 || (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES || ! is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) ) ) {
			self::error( 'فایل نامعتبر است یا از سقف ۲ مگابایت عبور کرده است.' );
		}
		$ext = strtolower( (string) pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'xlsx' ), true ) ) { self::error( 'فقط CSV و XLSX پشتیبانی می‌شوند.' ); }
		$parsed = 'csv' === $ext ? self::parse_csv( (string) $file['tmp_name'] ) : self::parse_xlsx( (string) $file['tmp_name'] );
		if ( is_wp_error( $parsed ) ) { self::error( $parsed->get_error_message() ); }
		$validated = self::validate_rows( $event, $parsed );
		if ( is_wp_error( $validated ) ) { self::error( $validated->get_error_message() ); }
		$token = bin2hex( random_bytes( 16 ) );
		$payload = array( 'owner' => get_current_user_id(), 'event_id' => (int) $event->id, 'rows' => $validated, 'schema_hash' => self::schema_hash( $event ) );
		try { $cipher = ECM_Upgrade::encrypt( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) ); }
		catch ( Throwable $e ) { self::error( 'رمزگذاری موقت پیش‌نمایش در سرور در دسترس نیست.' ); }
		if ( ! set_transient( self::stage_key( $token ), $cipher, self::STAGE_SECONDS ) ) { self::error( 'ذخیره موقت پیش‌نمایش ممکن نشد.' ); }
		self::redirect( array( 'ecm_event_id' => (int) $event->id, 'preview' => $token ) );
	}

	private static function error( string $message ): void {
		wp_die( esc_html( $message ), 'اعتبارسنجی فایل', array( 'response' => 400, 'back_link' => true ) );
	}

	/** @return array<int,array<int,string>>|WP_Error */
	private static function parse_csv( string $path ): array|WP_Error {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) { return new WP_Error( 'ecm_import_csv', 'بازکردن CSV ناموفق بود.' ); }
		$rows = array();
		while ( false !== ( $cells = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
			if ( count( $cells ) > self::MAX_COLUMNS ) { fclose( $handle ); return new WP_Error( 'ecm_import_cols', 'تعداد ستون‌ها بیش از حد مجاز است.' ); }
			if ( ! $rows && isset( $cells[0] ) ) { $cells[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $cells[0] ); }
			$joined = implode( '', $cells );
			if ( ! preg_match( '//u', $joined ) ) { fclose( $handle ); return new WP_Error( 'ecm_import_encoding', 'CSV باید با UTF-8 ذخیره شود.' ); }
			if ( array_filter( $cells, static fn( $v ): bool => trim( (string) $v ) !== '' ) ) { $rows[] = $cells; }
			if ( count( $rows ) > self::MAX_ROWS + 1 ) { fclose( $handle ); return new WP_Error( 'ecm_import_rows', 'تعداد ردیف‌های داده بیش از ۱۰۰ است.' ); }
		}
		fclose( $handle ); return $rows;
	}

	/** Only worksheet sheet1; reject formulas, DTDs and zip bombs. */
	private static function parse_xlsx( string $path ): array|WP_Error {
		if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'simplexml_load_string' ) ) {
			return new WP_Error( 'ecm_import_xlsx_unavailable', 'افزونه‌های ZipArchive و SimpleXML برای خواندن XLSX روی PHP فعال نیستند. از CSV استفاده کنید.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) { return new WP_Error( 'ecm_import_xlsx_invalid', 'فایل XLSX باز نشد.' ); }
		if ( $zip->numFiles > 350 ) { $zip->close(); return new WP_Error( 'ecm_import_xlsx_large', 'ساختار XLSX بیش از حد بزرگ است.' ); }
		foreach ( array( 'xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml' ) as $entry ) {
			$stat = $zip->statName( $entry );
			if ( $stat && ( (int) $stat['size'] > 4000000 || ( (int) $stat['comp_size'] > 0 && (int) $stat['size'] / (int) $stat['comp_size'] > 150 ) ) ) {
				$zip->close(); return new WP_Error( 'ecm_import_xlsx_bomb', 'فایل XLSX نسبت فشرده‌سازی یا حجم داخلی نامعتبر دارد.' );
			}
		}
		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$shared_raw = $zip->getFromName( 'xl/sharedStrings.xml' );
		$zip->close();
		if ( ! is_string( $sheet ) || strlen( $sheet ) > 4000000 || stripos( $sheet, '<!DOCTYPE' ) !== false || stripos( $sheet, '<!ENTITY' ) !== false ) {
			return new WP_Error( 'ecm_import_sheet', 'خواندن شیت اول ممکن نیست یا محتوای XML ناامن است.' );
		}
		if ( is_string( $shared_raw ) && ( strlen( $shared_raw ) > 4000000 || stripos( $shared_raw, '<!DOCTYPE' ) !== false || stripos( $shared_raw, '<!ENTITY' ) !== false ) ) {
			return new WP_Error( 'ecm_import_shared', 'رشته‌های XLSX نامعتبرند.' );
		}
		$previous = libxml_use_internal_errors( true );
		try {
			$strings = array();
			if ( is_string( $shared_raw ) ) {
				$xml_shared = simplexml_load_string( $shared_raw, 'SimpleXMLElement', LIBXML_NONET );
				if ( false === $xml_shared ) { return new WP_Error( 'ecm_import_shared', 'محتوای رشته‌های XLSX معتبر نیست.' ); }
				foreach ( $xml_shared->si as $si ) {
					$value = (string) $si->t;
					foreach ( $si->r as $rich ) { $value .= (string) $rich->t; }
					$strings[] = $value;
					if ( count( $strings ) > 10000 ) { return new WP_Error( 'ecm_import_shared', 'تعداد رشته‌های XLSX بیش از حد مجاز است.' ); }
				}
			}
			$xml_sheet = simplexml_load_string( $sheet, 'SimpleXMLElement', LIBXML_NONET );
			if ( false === $xml_sheet ) { return new WP_Error( 'ecm_import_sheet', 'ساختار شیت XLSX معتبر نیست.' ); }
			$rows = array();
			foreach ( $xml_sheet->sheetData->row as $row ) {
				$cells = array();
				foreach ( $row->c as $cell ) {
					$ref = (string) $cell['r'];
					if ( ! preg_match( '/^([A-Z]{1,2})[0-9]{1,6}$/D', $ref, $match ) ) { return new WP_Error( 'ecm_import_column', 'نشانی سلول XLSX نامعتبر است.' ); }
					$column = 0;
					foreach ( str_split( $match[1] ) as $char ) { $column = $column * 26 + ord( $char ) - 64; }
					$column--;
					if ( $column >= self::MAX_COLUMNS ) { return new WP_Error( 'ecm_import_cols', 'تعداد ستون‌ها بیش از حد مجاز است.' ); }
					if ( count( $cell->f ) ) { return new WP_Error( 'ecm_import_formula', 'فایل XLSX حاوی فرمول است؛ ابتدا مقدار سلول‌ها را ثابت کنید.' ); }
					$type = (string) $cell['t'];
					$value = (string) $cell->v;
					if ( 's' === $type ) { $value = $strings[ (int) $value ] ?? ''; }
					elseif ( 'inlineStr' === $type ) {
						$value = (string) $cell->is->t;
						foreach ( $cell->is->r as $rich ) { $value .= (string) $rich->t; }
					}
					$cells[ $column ] = $value;
				}
				if ( $cells ) {
					$length = max( array_keys( $cells ) ) + 1;
					$rows[] = array_replace( array_fill( 0, $length, '' ), $cells );
				}
				if ( count( $rows ) > self::MAX_ROWS + 1 ) { return new WP_Error( 'ecm_import_rows', 'تعداد ردیف‌های داده بیش از ۱۰۰ است.' ); }
			}
			return $rows;
		} finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
	}

	/** Normalize every row before staging, with no writes to business tables. */
	public static function validate_rows( object $event, array $rows ): array|WP_Error {
		if ( count( $rows ) < 2 || count( $rows ) > self::MAX_ROWS + 1 ) {
			return new WP_Error( 'ecm_import_rows', 'فایل باید بین ۱ تا ۱۰۰ ردیف اطلاعات داشته باشد.' );
		}
		$config = ECM_Events::config( $event );
		$headers = array_map( static fn( $v ): string => trim( (string) $v ), array_shift( $rows ) );
		if ( count( array_unique( $headers ) ) !== count( $headers ) || array_filter( $headers, static fn( string $key ): bool => ! preg_match( '/^[a-z][a-z0-9_]{0,79}$/D', $key ) ) ) {
			return new WP_Error( 'ecm_import_header', 'کلید ستون‌ها باید انگلیسی، یکتا و مطابق فرم‌ساز باشند.' );
		}
		$map = array_flip( $headers );
		$fields = array(); $guest_key = '';
		foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			if ( ! $key || 'html' === $type ) { continue; }
			if ( 'guest' === $type ) { $guest_key = $key; continue; }
			$fields[ $key ] = $field;
		}
		$sms_key = sanitize_key( (string) ( $config['sms_field'] ?? 'mobile' ) );
		$name_key = sanitize_key( (string) ( $config['name_field'] ?? 'full_name' ) );
		if ( ! isset( $fields[ $sms_key ], $fields[ $name_key ] ) ) { return new WP_Error( 'ecm_import_identity_fields', 'فیلد نام و موبایل رویداد باید در فرم‌ساز تعریف شده باشند.' ); }
		foreach ( $fields as $key => $field ) {
			if ( ( ! empty( $field['required'] ) || $key === $sms_key || $key === $name_key ) && ! array_key_exists( $key, $map ) ) {
				return new WP_Error( 'ecm_import_header', 'ستون ضروری «' . $key . '» در فایل وجود ندارد.' );
			}
		}
		foreach ( $headers as $key ) {
			if ( isset( $fields[ $key ] ) ) { continue; }
			if ( ! preg_match( '/^guest([1-9]|1[0-9]|20)_(name|mobile)$/D', $key ) || ! $guest_key || ! (int) $event->guest_limit ) {
				return new WP_Error( 'ecm_import_unknown', 'ستون «' . $key . '» در فرم رویداد تعریف نشده است.' );
			}
		}
		$used_mobiles = array(); $clean_rows = array();
		foreach ( $rows as $position => $cells ) {
			if ( count( $cells ) > self::MAX_COLUMNS ) { return new WP_Error( 'ecm_import_cols', 'ستون اضافه در ردیف ' . ( $position + 2 ) ); }
			$data = array();
			foreach ( $fields as $key => $field ) {
				$type = (string) ( $field['type'] ?? 'text' );
				$value = isset( $map[ $key ], $cells[ $map[ $key ] ] ) ? trim( (string) $cells[ $map[ $key ] ] ) : '';
				if ( strlen( $value ) > 2000 ) { return new WP_Error( 'ecm_import_long', 'مقدار بیش از حد طولانی در ردیف ' . ( $position + 2 ) ); }
				if ( ( ! empty( $field['required'] ) || $key === $sms_key || $key === $name_key ) && '' === $value ) {
					return new WP_Error( 'ecm_import_required', 'فیلد ضروری «' . $key . '» در ردیف ' . ( $position + 2 ) . ' خالی است.' );
				}
				if ( 'tel' === $type || $key === $sms_key ) {
					$value = '' === $value ? '' : ECM_Utils::normalize_mobile( $value );
					if ( '' === $value && ( ! empty( $field['required'] ) || $key === $sms_key ) ) { return new WP_Error( 'ecm_import_mobile', 'موبایل نامعتبر در ردیف ' . ( $position + 2 ) ); }
				} elseif ( 'email' === $type && $value && ! is_email( $value ) ) { return new WP_Error( 'ecm_import_email', 'ایمیل نامعتبر در ردیف ' . ( $position + 2 ) ); }
				elseif ( in_array( $type, array( 'select', 'radio' ), true ) && $value && ! in_array( $value, (array) ( $field['options'] ?? array() ), true ) ) { return new WP_Error( 'ecm_import_option', 'گزینه نامعتبر در ردیف ' . ( $position + 2 ) ); }
				elseif ( 'number' === $type && $value && ! is_numeric( ECM_Utils::normalize_digits( $value ) ) ) { return new WP_Error( 'ecm_import_number', 'عدد نامعتبر در ردیف ' . ( $position + 2 ) ); }
				elseif ( 'date' === $type && $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', ECM_Utils::normalize_digits( $value ) ) ) { return new WP_Error( 'ecm_import_date', 'تاریخ نامعتبر در ردیف ' . ( $position + 2 ) ); }
				elseif ( 'time' === $type && $value && ! preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', ECM_Utils::normalize_digits( $value ) ) ) { return new WP_Error( 'ecm_import_time', 'ساعت نامعتبر در ردیف ' . ( $position + 2 ) ); }
				elseif ( 'url' === $type && $value && ! wp_http_validate_url( $value ) ) { return new WP_Error( 'ecm_import_url', 'آدرس وب نامعتبر در ردیف ' . ( $position + 2 ) ); }
				if ( 'checkbox' === $type ) {
					$options = array_values( array_filter( array_map( 'trim', preg_split( '/[,،]/u', $value ) ) ) );
					if ( array_diff( $options, (array) ( $field['options'] ?? array() ) ) ) { return new WP_Error( 'ecm_import_option', 'گزینه چندانتخابی نامعتبر در ردیف ' . ( $position + 2 ) ); }
					$data[ $key ] = $options;
				} else { $data[ $key ] = sanitize_text_field( $value ); }
			}
			$mobile = (string) ( $data[ $sms_key ] ?? '' );
			$name = (string) ( $data[ $name_key ] ?? '' );
			if ( ! $mobile || ! $name || strlen( $name ) > 190 || isset( $used_mobiles[ $mobile ] ) ) { return new WP_Error( 'ecm_import_duplicate', 'موبایل تکراری یا نامعتبر در ردیف ' . ( $position + 2 ) ); }
			$used_mobiles[ $mobile ] = true;
			$guests = array();
			for ( $i = 1; $i <= 20; $i++ ) {
				$guest_name = isset( $map[ 'guest' . $i . '_name' ] ) ? trim( (string) ( $cells[ $map[ 'guest' . $i . '_name' ] ] ?? '' ) ) : '';
				$guest_mobile_raw = isset( $map[ 'guest' . $i . '_mobile' ] ) ? trim( (string) ( $cells[ $map[ 'guest' . $i . '_mobile' ] ] ?? '' ) ) : '';
				if ( '' === $guest_name && '' === $guest_mobile_raw ) { continue; }
				$guest_mobile = ECM_Utils::normalize_mobile( $guest_mobile_raw );
				if ( ! $guest_name || ! $guest_mobile || isset( $used_mobiles[ $guest_mobile ] ) ) { return new WP_Error( 'ecm_import_guest', 'مهمان ناقص یا موبایل تکراری در ردیف ' . ( $position + 2 ) ); }
				$used_mobiles[ $guest_mobile ] = true;
				$guests[] = array( 'name' => substr( sanitize_text_field( $guest_name ), 0, 190 ), 'phone' => $guest_mobile );
			}
			if ( $guest_key && ! empty( (array) ( $config['fields'] ?? array() ) ) && ! $guests ) {
				foreach ( (array) $config['fields'] as $field ) {
					if ( (string) ( $field['key'] ?? '' ) === $guest_key && ! empty( $field['required'] ) ) { return new WP_Error( 'ecm_import_guest_required', 'مهمان اجباری در ردیف ' . ( $position + 2 ) . ' خالی است.' ); }
				}
			}
			if ( count( $guests ) > min( 20, (int) $event->guest_limit ) ) { return new WP_Error( 'ecm_import_guest_count', 'تعداد مهمان بیش از ظرفیت رویداد در ردیف ' . ( $position + 2 ) ); }
			if ( $guest_key ) { $data[ $guest_key ] = array_map( static fn( array $g ): array => array( 'name' => $g['name'], 'phone' => $g['phone'] ), $guests ); }
			$clean_rows[] = array( 'name' => substr( $name, 0, 190 ), 'mobile' => $mobile, 'data' => $data, 'guests' => $guests );
		}
		if ( ! $clean_rows ) { return new WP_Error( 'ecm_import_empty', 'ردیف قابل ورود پیدا نشد.' ); }
		$duplicates = self::existing_mobiles( (int) $event->id, array_keys( $used_mobiles ) );
		if ( $duplicates ) { return new WP_Error( 'ecm_import_existing', 'برای ' . count( $duplicates ) . ' شماره، ثبت‌نام قبلی یا مهمان موجود است؛ برای امنیت، کل فایل رد شد.' ); }
		return $clean_rows;
	}

	private static function existing_mobiles( int $event_id, array $mobiles ): array {
		global $wpdb;
		if ( ! $mobiles ) { return array(); }
		$hashes = array_map( static fn( string $mobile ): string => hash_hmac( 'sha256', $mobile, wp_salt( 'auth' ) ), $mobiles );
		$r = ECM_DB::table( 'registrations' ); $p = ECM_DB::table( 'participants' ); $pe = ECM_DB::table( 'people' );
		$slots = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$sql = "SELECT DISTINCT COALESCE(pe.phone_hash,r.dedupe_phone_hash) digest FROM {$r} r LEFT JOIN {$p} p ON p.registration_id=r.id LEFT JOIN {$pe} pe ON pe.id=p.person_id WHERE r.event_id=%d AND r.is_test=0 AND (r.dedupe_phone_hash IN ({$slots}) OR pe.phone_hash IN ({$slots})) LIMIT 150";
		return (array) $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $event_id ), $hashes, $hashes ) ) );
	}

	public static function handle_execute(): void {
		self::checked();
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
		check_admin_referer( 'ecm_import_execute_' . $token );
		if ( '1' !== (string) ( $_POST['confirmed'] ?? '' ) ) { self::error( 'تأیید قطعی لازم است.' ); }
		$staged = self::load( $token );
		if ( ! $staged || empty( $staged['rows'] ) ) { self::error( 'پیش‌نمایش منقضی یا نامعتبر است.' ); }
		$event = self::event_check( (int) $staged['event_id'] );
		if ( is_wp_error( $event ) ) { self::error( $event->get_error_message() ); }
		if ( empty( $staged['schema_hash'] ) || ! hash_equals( self::schema_hash( $event ), (string) $staged['schema_hash'] ) ) { self::error( 'پیکربندی رویداد از زمان پیش‌نمایش تغییر کرده است. فایل را دوباره بررسی کنید.' ); }
		global $wpdb;
		$lock_name = 'ecm_imp_' . substr( hash( 'sha256', $wpdb->prefix . ':' . $event->id ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $lock_name ) ) ) { self::error( 'عملیات ورود دیگری در حال اجراست.' ); }
		$result = null;
		try { $result = self::insert_batch( $event, $staged['rows'] ); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); }
		if ( ! is_wp_error( $result ) ) { delete_transient( self::stage_key( $token ) ); }
		$result_token = bin2hex( random_bytes( 16 ) );
		$result_key = 'ecm_imp_result_' . substr( hash_hmac( 'sha256', $result_token . ':' . get_current_user_id(), wp_salt( 'auth' ) ), 0, 38 );
		$msg = is_wp_error( $result ) ? 'ورود گروهی انجام نشد؛ تراکنش بازگردانده شد. کد: ' . $result->get_error_code() : 'ورود گروهی تکمیل شد: ' . (int) $result . ' ثبت‌نام و کارت مستقل افراد/مهمان‌ها ایجاد شد. پیامکی ارسال نشد.';
		set_transient( $result_key, array( 'error' => is_wp_error( $result ), 'message' => $msg ), 300 );
		self::redirect( array( 'ecm_event_id' => (int) $event->id, 'result' => $result_token ) );
	}

	/** Refuse to claim all-or-nothing semantics if any relevant table is nontransactional. */
	private static function transactional_tables(): bool {
		global $wpdb;
		$names = array_map( static fn( string $name ): string => ECM_DB::table( $name ), array( 'registrations', 'people', 'participants', 'registration_meta', 'ticket_tokens' ) );
		$holders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$sql = "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$holders})";
		$found = $wpdb->get_results( $wpdb->prepare( $sql, $names ) );
		if ( count( (array) $found ) !== count( $names ) ) { return false; }
		foreach ( $found as $table ) { if ( strtolower( (string) $table->ENGINE ) !== 'innodb' ) { return false; } }
		return true;
	}

	private static function insert_batch( object $event, array $rows ): int|WP_Error {
		global $wpdb;
		if ( ! self::transactional_tables() ) { return new WP_Error( 'ecm_import_engine', 'برای ورود اتمیک، تمام جدول‌های وابسته باید InnoDB باشند.' ); }
		// Re-check for duplicates after acquiring event-level advisory lock.
		$all_mobiles = array();
		foreach ( $rows as $row ) { $all_mobiles[] = $row['mobile']; foreach ( $row['guests'] as $guest ) { $all_mobiles[] = $guest['phone']; } }
		if ( count( $all_mobiles ) !== count( array_unique( $all_mobiles ) ) || self::existing_mobiles( (int) $event->id, $all_mobiles ) ) {
			return new WP_Error( 'ecm_import_stale_duplicate', 'از زمان پیش‌نمایش، ثبت‌نام تکراری ایجاد شده است.' );
		}
		$config = ECM_Events::config( $event );
		$registrations = ECM_DB::table( 'registrations' );
		$index = ECM_DB::table( 'registration_meta' );
		$fields = (array) ( $config['fields'] ?? array() );
		$types = array();
		foreach ( $fields as $field ) { $types[ sanitize_key( (string) ( $field['key'] ?? '' ) ) ] = (string) ( $field['type'] ?? '' ); }
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return new WP_Error( 'ecm_import_transaction', 'تراکنش شروع نشد.' ); }
		$count = 0;
		try {
			foreach ( $rows as $row ) {
				$now = ECM_Utils::now_utc();
				$hash = hash_hmac( 'sha256', $row['mobile'], wp_salt( 'auth' ) );
				$insert = $wpdb->insert( $registrations, array(
					'public_id' => ECM_Utils::public_id(), 'event_id' => (int) $event->id,
					'ticket_template_id' => ECM_Tickets::current_template_id( $event ),
					'dedupe_phone_hash' => $hash, 'form_data' => wp_json_encode( $row['data'], JSON_UNESCAPED_UNICODE ),
					'amount_original' => '0', 'currency_label' => (string) ( $config['currency_label'] ?? 'ریال' ),
					'exchange_rate' => '1', 'amount_rial' => 0,
					'payment_status' => 'not_required', 'payment_reference' => 'bulk_import',
					'access_hash' => wp_hash_password( wp_generate_password( 40, false, false ) ),
					'created_at' => $now, 'updated_at' => $now,
				) );
				if ( ! $insert ) { throw new RuntimeException( 'registration_insert' ); }
				$id = (int) $wpdb->insert_id;
				$primary = ECM_Events::ensure_registration_entities( $id, $row['data'] );
				if ( ! $primary || (int) $primary->registration_id !== $id ) { throw new RuntimeException( 'participant_creation' ); }
				$guests = ECM_Participants::create_guests( $id, $row['guests'], true );
				if ( is_wp_error( $guests ) ) { throw new RuntimeException( 'guest_creation' ); }
				foreach ( $row['data'] as $key => $value ) {
					if ( ! isset( $types[ $key ] ) || 'guest' === $types[ $key ] ) { continue; }
					$normalized = trim( ECM_Utils::normalize_digits( ECM_Utils::field_value( $row['data'], $key ) ) );
					$normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $normalized ) : strtolower( $normalized );
					$normalized = preg_replace( '/\s+/u', ' ', $normalized );
					if ( 'tel' === $types[ $key ] ) { $normalized = preg_replace( '/[^0-9+]/', '', $normalized ); }
					if ( ! $wpdb->insert( $index, array( 'registration_id' => $id, 'field_key' => $key, 'field_hash' => hash_hmac( 'sha256', $normalized, wp_salt( 'auth' ) ) ) ) ) { throw new RuntimeException( 'field_index' ); }
				}
				foreach ( array_merge( array( $primary ), $guests ) as $person ) {
					if ( ! ECM_Tickets::card_url( $person ) || ! ECM_Tickets::attendance_url( $person ) ) { throw new RuntimeException( 'token_creation' ); }
				}
				$count++;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'commit_failed' ); }
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			ECM_Diagnostics::record( 'import', 'Bulk import rolled back', array( 'code' => sanitize_key( $error->getMessage() ), 'event_id' => (int) $event->id, 'count' => $count ) );
			return new WP_Error( 'ecm_import_' . sanitize_key( $error->getMessage() ), 'ثبت تراکنشی شکست خورد و تغییرات بازگردانده شد.' );
		}
		ECM_Diagnostics::record( 'import', 'Bulk import completed', array( 'event_id' => (int) $event->id, 'count' => $count, 'actor_id' => get_current_user_id() ) );
		return $count;
	}
}
