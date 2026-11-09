<?php

defined( 'ABSPATH' ) || exit;

/**
 * Phase 3: administrator-only staging form and versioned template editor.
 * Production visitors ALWAYS use ECM_Public classic registration until the
 * reviewed registration/card/OTP bridge is shipped in a later release.
 */
final class ECM_Workflow_Staging {
	public static function hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ecm_flow_flag', array( __CLASS__, 'set_preview_flag' ) );
		add_action( 'admin_post_ecm_flow_publish', array( __CLASS__, 'publish' ) );
		add_action( 'admin_post_ecm_flow_preview_step', array( __CLASS__, 'step' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'ecm-dashboard', 'آزمایش فرایند ثبت‌نام', 'فرایند ثبت‌نام (آلفا)', 'manage_ecm_events', 'ecm-workflows', array( __CLASS__, 'page' ) );
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'ecm-workflows' ) ) {
			return;
		}
		wp_enqueue_style( 'ecm-flow-preview', ECM_URL . 'assets/css/workflow-preview.css', array(), ECM_VERSION );
		wp_enqueue_script( 'ecm-flow-preview', ECM_URL . 'assets/js/workflow-preview.js', array(), ECM_VERSION, true );
	}

	private static function assert_capability(): void {
		if ( ! current_user_can( 'manage_ecm_events' ) ) {
			wp_die( 'Forbidden', '', array( 'response' => 403 ) );
		}
	}

	private static function page_url( int $event_id, int $instance_id = 0 ): string {
		$args = array( 'page'=>'ecm-workflows', 'event_id'=>$event_id );
		if ( $instance_id ) {
			$args['instance_id'] = $instance_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function workflow_for_event( int $event_id ): array|WP_Error {
		$event = ECM_Events::event( $event_id );
		if ( ! $event ) {
			return new WP_Error( 'ecm_flow_event_missing', 'رویداد پیدا نشد.' );
		}
		$published = ECM_Workflow_Versions::published( $event_id );
		if ( ! $published ) {
			return new WP_Error( 'ecm_flow_unpublished', 'برای این رویداد هنوز فرایندی منتشر نشده است.' );
		}
		$definition = json_decode( (string) $published->definition_json, true );
		if ( ! self::preview_shape( $definition ) ) {
			return new WP_Error( 'ecm_flow_preview_shape', 'این پیش‌نمایش تنها الگوی چهارمرحله‌ای فرم، میثاق‌نامه، رسید و نتیجه را اجرا می‌کند.' );
		}
		return array( 'event'=>$event, 'version'=>$published, 'definition'=>$definition );
	}

	private static function preview_shape( $definition ): bool {
		return is_array( $definition ) && array_column( (array) ( $definition['steps'] ?? array() ), 'type' ) === array( 'form', 'agreement', 'receipt', 'result' );
	}

	public static function page(): void {
		self::assert_capability();
		$events = ECM_Events::all();
		$event_id = absint( $_GET['event_id'] ?? 0 );
		if ( ! $event_id && $events ) {
			$event_id = (int) $events[0]->id;
		}
		$event = ECM_Events::event( $event_id );
		$published = $event ? ECM_Workflow_Versions::published( $event_id ) : null;
		$definition = $published ? json_decode( (string) $published->definition_json, true ) : null;
		if ( ! self::preview_shape( $definition ) ) {
			$definition = ECM_Workflow::default_definition();
		}
		$defaults = ECM_Workflow_Templates::defaults();
		$enabled = ECM_Workflow::enabled();
		?>
		<div class="wrap ecm-flow-admin" dir="rtl">
			<h1>سازنده فرایند ثبت‌نام | نسخه آزمایشی 0.7.0</h1>
			<div class="notice notice-warning inline"><p><strong>فقط برای Staging:</strong> فعال‌کردن این قابلیت، مسیر عمومی ثبت‌نام را تغییر نمی‌دهد. همه مراحل و فایل‌های آزمایشی فقط برای مدیران مجاز هستند. هنوز کارت و پیامک صادر نمی‌شود.</p></div>
			<?php echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=ecm-flow-review' ) ) . '">بررسی درخواست‌های آزمایشی</a></p>';
			if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>نسخه فرایند ثبت شد.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['flow_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['flow_error'] ) ) ); ?></p></div><?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ecm-flow-inline">
				<input type="hidden" name="page" value="ecm-workflows"><label>رویداد: <select name="event_id"><?php foreach ( $events as $item ) : ?><option value="<?php echo esc_attr( $item->id ); ?>" <?php selected( $event_id, (int) $item->id ); ?>><?php echo esc_html( $item->title ); ?></option><?php endforeach; ?></select></label><button class="button">انتخاب</button>
			</form>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<form class="ecm-flow-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ecm_flow_flag', 'ecm_nonce' ); ?><input type="hidden" name="action" value="ecm_flow_flag"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
				<label><input type="checkbox" name="enable" value="1" <?php checked( $enabled ); ?>> اجازه آزمایش فرایند (فقط مدیر)</label><button class="button button-secondary">ثبت تنظیم</button>
			</form>
			<?php endif; ?>
			<?php if ( ! $event ) : ?><p>ابتدا یک رویداد بسازید.</p><?php return; endif; ?>
			<p>حالت رویداد: <strong><?php echo esc_html( ECM_Workflow::mode_for_event( $event ) ); ?></strong> | نسخه منتشرشده: <strong><?php echo $published ? esc_html( $published->version_number ) : 'ندارد'; ?></strong>. برای فعال‌شدن تست، در تنظیمات خود رویداد «فرایند اختصاصی» را انتخاب کنید.</p>
			<h2>تنظیم مراحل و قالب‌ها</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecm_flow_publish"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>"><?php wp_nonce_field( 'ecm_flow_publish_' . $event_id, 'ecm_nonce' ); ?>
				<?php foreach ( (array) $definition['steps'] as $i => $item ) : $type = $item['type']; ?>
				<details class="ecm-flow-edit" <?php echo 0 === $i ? 'open' : ''; ?>><summary><?php echo esc_html( ( $i + 1 ) . ' · ' . ( $item['label'] ?? $type ) ); ?> <code><?php echo esc_html( $type ); ?></code></summary>
				<input type="hidden" name="steps[<?php echo esc_attr( $i ); ?>][key]" value="<?php echo esc_attr( $item['key'] ); ?>"><input type="hidden" name="steps[<?php echo esc_attr( $i ); ?>][type]" value="<?php echo esc_attr( $type ); ?>">
				<label>عنوان مرحله<input name="steps[<?php echo esc_attr( $i ); ?>][label]" type="text" maxlength="190" value="<?php echo esc_attr( $item['label'] ); ?>" required></label>
				<label>HTML ایمن<textarea name="steps[<?php echo esc_attr( $i ); ?>][html]" rows="6" dir="ltr"><?php echo esc_textarea( $item['html'] ?? $defaults[ $type ] ); ?></textarea></label>
				<label>CSS محدود و اختصاصی مرحله<textarea name="steps[<?php echo esc_attr( $i ); ?>][css]" rows="4" dir="ltr"><?php echo esc_textarea( $item['css'] ?? '' ); ?></textarea></label>
				<?php if ( 'agreement' === $type ) : ?><label>عبارت چک‌باکس<input name="steps[<?php echo esc_attr( $i ); ?>][consent_label]" type="text" value="<?php echo esc_attr( $item['consent_label'] ?? 'میثاق‌نامه را مطالعه کردم و موافقم.' ); ?>"></label><?php endif; ?>
				<?php if ( 'receipt' === $type ) : ?><label>شماره کارت ۱۶ رقمی<input name="steps[<?php echo esc_attr( $i ); ?>][card_number]" type="text" inputmode="numeric" value="<?php echo esc_attr( $item['card_number'] ?? '' ); ?>"></label><label>نام صاحب حساب<input name="steps[<?php echo esc_attr( $i ); ?>][account_holder]" value="<?php echo esc_attr( $item['account_holder'] ?? '' ); ?>"></label><label>توضیحات پرداخت<textarea name="steps[<?php echo esc_attr( $i ); ?>][payment_note]" rows="2"><?php echo esc_textarea( $item['payment_note'] ?? '' ); ?></textarea></label><?php endif; ?>
				</details>
				<?php endforeach; ?>
				<p class="description">توکن‌های مجاز در این آلفا: <code>[ecm_fields]</code>، <code>[ecm_agreement]</code>، <code>[ecm_payment_details]</code>، <code>[ecm_receipt_upload]</code>، <code>[ecm_status]</code> و <code>[ecm_tracking_code]</code>. هر شورت‌کد را تنها در متن مستقل یک عنصر HTML قرار دهید.</p>
				<p><button class="button button-primary" <?php disabled( ! $enabled ); ?>>انتشار نسخه جدید</button></p>
			</form>
			<hr><h2>آزمایش مراحل (فقط مدیر)</h2>
			<?php
			if ( ! $enabled || 'custom_workflow' !== ECM_Workflow::mode_for_event( $event ) || ! $published || ! self::preview_shape( json_decode( (string) $published->definition_json, true ) ) ) {
				echo '<p>برای شروع: مسیر آزمایشی را فعال کنید، حالت رویداد را اختصاصی قرار دهید و فرایند چهارمرحله‌ای را منتشر کنید.</p>';
			} else {
				self::render_preview( $event_id, absint( $_GET['instance_id'] ?? 0 ) );
			}
			?>
		</div>
		<?php
	}

	public static function set_preview_flag(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', '', array( 'response'=>403 ) );
		}
		check_admin_referer( 'ecm_flow_flag', 'ecm_nonce' );
		if ( ! ECM_Workflow_Schema::installed() ) {
			wp_die( 'ابتدا مهاجرت Schema را روی Staging انجام دهید.' );
		}
		update_option( 'ecm_workflow_enabled', empty( $_POST['enable'] ) ? 0 : 1, false );
		wp_safe_redirect( self::page_url( absint( $_POST['event_id'] ?? 0 ) ) );
		exit;
	}

	public static function publish(): void {
		self::assert_capability();
		$event_id = absint( $_POST['event_id'] ?? 0 );
		check_admin_referer( 'ecm_flow_publish_' . $event_id, 'ecm_nonce' );
		$raw = isset( $_POST['steps'] ) && is_array( $_POST['steps'] ) ? wp_unslash( $_POST['steps'] ) : array();
		$definition = array( 'steps' => array_values( array_slice( $raw, 0, 20 ) ) );
		if ( ! self::preview_shape( $definition ) ) {
			wp_die( 'تنها چهار مرحله استاندارد در این نسخه آزمایشی پشتیبانی می‌شود.' );
		}
		$required = array( 'ecm_fields', 'ecm_agreement', 'ecm_receipt_upload' );
		foreach ( $required as $i => $token ) {
			$source = trim( (string) ( $definition['steps'][$i]['html'] ?? '' ) );
			if ( '' === $source ) { continue; } // Empty template uses the safe system default.
			if ( ! preg_match( '~(?:>|^)\s*\[' . preg_quote( $token, '~' ) . '\]\s*(?:<|$)~u', $source ) ) {
				wp_die( 'کنترل ضروری [' . esc_html( $token ) . '] باید به‌صورت مستقل در متن مرحله باشد.' );
			}
		}
		$result = ECM_Workflow_Versions::publish( $event_id, $definition );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		wp_safe_redirect( add_query_arg( 'saved', 1, self::page_url( $event_id ) ) );
		exit;
	}

	private static function form_fields( object $event ): string {
		$config = ECM_Events::config( $event );
		$public = new ECM_Public();
		$result = '<div class="ecm-field-grid">';
		foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
			$result .= $public->render_field( (array) $field, '', $event );
		}
		return $result . '</div>';
	}

	private static function render_preview( int $event_id, int $instance_id ): void {
		$flow = self::workflow_for_event( $event_id );
		if ( is_wp_error( $flow ) ) {
			echo '<p>' . esc_html( $flow->get_error_message() ) . '</p>';
			return;
		}
		$instance = null;
		if ( $instance_id ) {
			global $wpdb;
			$instance = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ECM_DB::table( 'flow_instances' ) . ' WHERE id=%d AND event_id=%d', $instance_id, $event_id ) );
			// Pin the request to its own immutable version, even after a new publish.
			if ( ! $instance ) {
				echo '<p>درخواست آزمایشی پیدا نشد.</p>';
				return;
			}
			$stored_version = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ECM_DB::table( 'flow_versions' ) . ' WHERE id=%d AND event_id=%d', (int) $instance->flow_version_id, $event_id ) );
			$snapshot = $stored_version ? json_decode( (string) $stored_version->definition_json, true ) : null;
			if ( ! self::preview_shape( $snapshot ) ) {
				echo '<p>نسخه ذخیره‌شده برای این درخواست معتبر نیست.</p>';
				return;
			}
			$flow['version'] = $stored_version;
			$flow['definition'] = $snapshot;
		}
		$steps = $flow['definition']['steps'];
		$state = $instance ? (string) $instance->state : 'draft';
		$index = 'draft' === $state ? 0 : ( 'awaiting_agreement' === $state ? 1 : ( in_array( $state, array( 'awaiting_receipt', 'needs_correction' ), true ) ? 2 : 3 ) );
		$step = $steps[$index];
		$tracking = $instance ? (string) get_transient( 'ecm_flow_preview_code_' . get_current_user_id() . '_' . $instance_id ) : '';
		$components = array(
			'ecm_fields' => self::form_fields( $flow['event'] ),
			'ecm_agreement' => '<label class="ecm-flow-consent"><input type="checkbox" name="ecm_agreed" value="1" required> ' . esc_html( $step['consent_label'] ?? 'میثاق‌نامه را مطالعه کردم و موافقم.' ) . '</label>',
			'ecm_payment_details' => '<div class="ecm-flow-payment">شماره کارت: <bdi>' . esc_html( $step['card_number'] ?? 'تنظیم نشده' ) . '</bdi><p>' . esc_html( $step['account_holder'] ?? '' ) . '</p><p>' . nl2br( esc_html( $step['payment_note'] ?? '' ) ) . '</p></div>',
			'ecm_receipt_upload' => '<label>تصویر رسید (JPEG / PNG / WebP، حداکثر ۸ مگابایت)<input type="file" name="ecm_receipt" accept="image/jpeg,image/png,image/webp" capture="environment" required></label>',
			'ecm_status' => '<strong>' . esc_html( ECM_Workflow_Review::state_label( $state ) ) . '</strong>',
			'ecm_tracking_code' => $tracking ? '<strong dir="ltr">' . esc_html( $tracking ) . '</strong>' : '<span>کد فقط هنگام ایجاد درخواست آزمایشی به مدیر نشان داده می‌شود.</span>',
		);
		?><div class="ecm-flow-stage"><div class="ecm-flow-steps">مرحله <?php echo esc_html( $index + 1 ); ?> از ۴: <?php echo esc_html( $step['label'] ); ?> | وضعیت: <code><?php echo esc_html( $state ); ?></code></div>
		<?php if ( 3 !== $index && 'needs_correction' !== $state ) : ?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ecm_flow_preview_step"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>"><input type="hidden" name="instance_id" value="<?php echo esc_attr( $instance_id ); ?>"><input type="hidden" name="revision" value="<?php echo esc_attr( $instance ? $instance->lock_version : 0 ); ?>"><?php wp_nonce_field( 'ecm_flow_step_' . $event_id . '_' . $instance_id, 'ecm_nonce' ); ?>
			<?php echo ECM_Workflow_Templates::render( $step, $components ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<button type="submit" class="button button-primary button-hero"><?php echo esc_html( 2 === $index ? 'ثبت تصویر و ارسال برای بررسی' : 'مرحله بعد' ); ?></button>
		</form>
		<?php else : echo ECM_Workflow_Templates::render( $step, $components ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		endif; ?>
		<p><a href="<?php echo esc_url( self::page_url( $event_id ) ); ?>">شروع درخواست آزمایشی دیگر</a> | <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ecm-flow-review', 'instance_id' => $instance_id ), admin_url( 'admin.php' ) ) ); ?>">بررسی مدیریتی درخواست</a></p></div><?php
	}

	/** Only administrator-supplied, schema-validated data enters the preview database. */
	private static function validated_fields( object $event ): array|WP_Error {
		$config = ECM_Events::config( $event );
		$raw = isset( $_POST['ecm_field'] ) && is_array( $_POST['ecm_field'] ) ? wp_unslash( $_POST['ecm_field'] ) : array();
		$raw_guests = isset( $_POST['ecm_guest'] ) && is_array( $_POST['ecm_guest'] ) ? wp_unslash( $_POST['ecm_guest'] ) : array();
		$data = array();
		$guests = array();
		foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			$type = sanitize_key( (string) ( $field['type'] ?? '' ) );
			if ( ! $key || 'html' === $type ) { continue; }
			if ( 'guest' === $type ) {
				$limit = min( 20, max( 0, (int) $event->guest_limit ) );
				$rows = (array) ( $raw_guests[$key] ?? array() );
				if ( count( $rows ) > $limit ) { return new WP_Error( 'ecm_flow_guest_limit', 'تعداد مهمان‌ها بیشتر از حد مجاز است.' ); }
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) { return new WP_Error( 'ecm_flow_guest_data', 'ساختار مهمان نامعتبر است.' ); }
					$name = substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 190 );
					$phone = ECM_Utils::normalize_mobile( (string) ( $row['phone'] ?? '' ) );
					if ( ! $name || ! $phone ) { return new WP_Error( 'ecm_flow_guest_data', 'نام و شماره معتبر مهمان لازم است.' ); }
					$guests[] = array( 'key'=>$key, 'name'=>$name, 'phone'=>$phone );
				}
				if ( ! empty( $field['required'] ) && ! $rows ) { return new WP_Error( 'ecm_flow_guest_required', 'حداقل یک مهمان لازم است.' ); }
				continue;
			}
			$value = $raw[$key] ?? '';
			$options = (array) ( $field['options'] ?? array() );
			if ( 'checkbox' === $type ) {
				$selected = array_values( array_unique( array_map( 'sanitize_text_field', is_array( $value ) ? $value : array() ) ) );
				if ( array_diff( $selected, $options ) ) { return new WP_Error( 'ecm_flow_invalid_choice', 'گزینه انتخاب‌شده معتبر نیست.' ); }
				$value = implode( '، ', $selected );
			} else {
				if ( ! is_scalar( $value ) ) { return new WP_Error( 'ecm_flow_invalid_field', 'مقدار فیلد معتبر نیست.' ); }
				$value = substr( sanitize_text_field( (string) $value ), 0, 2048 );
				if ( 'tel' === $type ) {
					$original_phone = $value;
					$value = ECM_Utils::normalize_mobile( $value );
					if ( '' !== trim( $original_phone ) && ! $value ) { return new WP_Error( 'ecm_flow_invalid_mobile', 'شماره موبایل معتبر نیست.' ); }
				}
				if ( in_array( $type, array( 'radio', 'select' ), true ) && '' !== $value && ! in_array( $value, $options, true ) ) { return new WP_Error( 'ecm_flow_invalid_choice', 'گزینه انتخاب‌شده معتبر نیست.' ); }
				if ( 'email' === $type && $value && ! is_email( $value ) ) { return new WP_Error( 'ecm_flow_invalid_email', 'ایمیل معتبر نیست.' ); }
				if ( 'url' === $type && $value && ! wp_http_validate_url( $value ) ) { return new WP_Error( 'ecm_flow_invalid_url', 'آدرس وب معتبر نیست.' ); }
				if ( 'number' === $type && $value && ! is_numeric( $value ) ) { return new WP_Error( 'ecm_flow_invalid_number', 'عدد معتبر نیست.' ); }
				if ( 'date' === $type && $value ) {
					if ( ! preg_match( '/^(\d{4})-(\d\d)-(\d\d)$/D', $value, $date ) || ! checkdate( (int) ( $date[2] ?? 0 ), (int) ( $date[3] ?? 0 ), (int) ( $date[1] ?? 0 ) ) ) {
						return new WP_Error( 'ecm_flow_invalid_date', 'تاریخ معتبر نیست.' );
					}
				}
				if ( 'time' === $type && $value && ! preg_match( '/^\d\d:\d\d$/D', $value ) ) { return new WP_Error( 'ecm_flow_invalid_time', 'زمان معتبر نیست.' ); }
			}
			if ( ! empty( $field['required'] ) && '' === $value ) { return new WP_Error( 'ecm_flow_required', 'تکمیل فیلد «' . (string) $field['label'] . '» لازم است.' ); }
			$data[$key] = (string) $value;
		}
		if ( $guests ) {
			if ( count( $guests ) > (int) $event->guest_limit ) { return new WP_Error( 'ecm_flow_guest_limit', 'تعداد مهمان‌ها بیش از حد مجاز است.' ); }
			$phones = array_column( $guests, 'phone' );
			$primary = ECM_Utils::normalize_mobile( (string) ( $data[ (string) ( $config['sms_field'] ?? '' ) ] ?? '' ) );
			if ( empty( $config['allow_duplicate_guest_phone'] ) && ( count( $phones ) !== count( array_unique( $phones ) ) || ( $primary && in_array( $primary, $phones, true ) ) ) ) {
				return new WP_Error( 'ecm_flow_duplicate_guest', 'شماره موبایل مهمان یا ثبت‌نام‌کننده تکراری است.' );
			}
			$data['__guests'] = wp_json_encode( $guests, JSON_UNESCAPED_UNICODE );
		}
		$mobile = ECM_Utils::normalize_mobile( (string) ( $data[ (string) ( $config['sms_field'] ?? '' ) ] ?? '' ) );
		if ( ! $mobile ) {
			return new WP_Error( 'ecm_flow_mobile_missing', 'شماره موبایل اصلی معتبر نیست.' );
		}
		return array( 'mobile'=>$mobile, 'data'=>$data );
	}

	public static function step(): void {
		self::assert_capability();
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$instance_id = absint( $_POST['instance_id'] ?? 0 );
		check_admin_referer( 'ecm_flow_step_' . $event_id . '_' . $instance_id, 'ecm_nonce' );
		if ( ! ECM_Workflow::enabled() ) {
			wp_die( 'حالت آزمایشی خاموش است.' );
		}
		$flow = self::workflow_for_event( $event_id );
		if ( is_wp_error( $flow ) || 'custom_workflow' !== ECM_Workflow::mode_for_event( is_wp_error( $flow ) ? null : $flow['event'] ) ) {
			wp_die( 'این رویداد برای اجرای آزمایشی فرایند جدید مجاز نیست.' );
		}
		if ( ! $instance_id ) {
			$input = self::validated_fields( $flow['event'] );
			if ( is_wp_error( $input ) ) { wp_die( esc_html( $input->get_error_message() ) ); }
			$created = ECM_Workflow_Instances::create_draft( $event_id, $input['mobile'], $input['data'] );
			if ( is_wp_error( $created ) ) { wp_die( esc_html( $created->get_error_message() ) ); }
			$instance_id = (int) $created['id'];
			set_transient( 'ecm_flow_preview_code_' . get_current_user_id() . '_' . $instance_id, $created['tracking_code'], 20 * MINUTE_IN_SECONDS );
			$result = ECM_Workflow_Instances::advance_draft( $instance_id, 0 );
		} else {
			$revision = absint( $_POST['revision'] ?? 0 );
			global $wpdb;
			$instance = $wpdb->get_row( $wpdb->prepare( 'SELECT id,event_id,flow_version_id,state,lock_version FROM ' . ECM_DB::table( 'flow_instances' ) . ' WHERE id=%d AND event_id=%d', $instance_id, $event_id ) );
			if ( ! $instance || (int) $instance->lock_version !== $revision ) { wp_die( 'وضعیت درخواست تغییر کرده است.' ); }
			if ( 'awaiting_agreement' === $instance->state ) {
				if ( '1' !== (string) ( $_POST['ecm_agreed'] ?? '' ) ) { wp_die( 'پذیرش میثاق‌نامه الزامی است.' ); }
				$result = ECM_Workflow_Instances::accept_agreement( $instance_id, $revision );
			} elseif ( 'awaiting_receipt' === $instance->state ) {
				$file = isset( $_FILES['ecm_receipt'] ) && is_array( $_FILES['ecm_receipt'] ) ? $_FILES['ecm_receipt'] : array();
				$result = ECM_Workflow_Instances::submit_receipt( $instance_id, $revision, $file );
			} else {
				wp_die( 'این درخواست آماده انتقال مرحله نیست.' );
			}
		}
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		wp_safe_redirect( self::page_url( $event_id, $instance_id ) );
		exit;
	}
}
