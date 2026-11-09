<?php

defined( 'ABSPATH' ) || exit;

/**
 * Phase 4 review console. This is admin-only while public identity proof and
 * notification policies are not yet available. All decisions are audited.
 */
final class ECM_Workflow_Review {
	private const STATES = array(
		'draft' => 'فرم ناتمام',
		'awaiting_agreement' => 'در انتظار میثاق‌نامه',
		'awaiting_receipt' => 'در انتظار ارسال رسید',
		'under_review' => 'در انتظار بررسی',
		'needs_correction' => 'نیازمند اصلاح',
		'approved' => 'تأییدشده',
		'rejected' => 'رد شده',
		'cancelled' => 'انصراف',
		'expired' => 'منقضی‌شده',
	);

	public static function hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ecm_flow_review_decision', array( __CLASS__, 'handle_decision' ) );
		add_action( 'admin_post_ecm_flow_receipt', array( __CLASS__, 'stream_receipt' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'ecm-dashboard', 'بررسی درخواست‌های Workflow', 'بررسی درخواست‌ها (آلفا)', 'manage_ecm_reviews', 'ecm-flow-review', array( __CLASS__, 'page' ) );
	}

	public static function can_review(): bool {
		return ECM_Workflow::enabled() && current_user_can( 'manage_ecm_reviews' );
	}

	public static function state_label( string $state ): string {
		return self::STATES[ $state ] ?? $state;
	}

	public static function issuance_allowed(): bool {
		// A site owner must explicitly acknowledge that approval creates real
		// registrations/cards. Never silently enable issuance by upgrading code.
		return self::can_review() && current_user_can( 'manage_options' )
			&& function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true )
			&& defined( 'ECM_WORKFLOW_STAGING_ISSUANCE' ) && true === ECM_WORKFLOW_STAGING_ISSUANCE;
	}

	private static function url( int $id = 0, string $message = '' ): string {
		$args = array( 'page' => 'ecm-flow-review' );
		if ( $id ) { $args['instance_id'] = $id; }
		if ( $message ) { $args['notice'] = $message; }
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function guard(): void {
		if ( ! self::can_review() ) {
			wp_die( 'این بخش فقط برای مدیر مجاز در محیط آزمایشی فعال است.', '', array( 'response' => 403 ) );
		}
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_ecm_reviews' ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		if ( ! ECM_Workflow::enabled() ) {
			echo '<div class="wrap" dir="rtl"><h1>بررسی درخواست‌ها</h1><p>موتور آزمایشی Workflow غیرفعال است. اطلاعات Classic تغییری نکرده‌اند.</p></div>';
			return;
		}
		$id = absint( $_GET['instance_id'] ?? 0 );
		echo '<div class="wrap ecm-flow-admin" dir="rtl"><h1>بررسی درخواست‌های ثبت‌نام (آلفا)</h1>';
		echo '<div class="notice notice-warning inline"><p>این محیط برای بازبینی داده‌های آزمایشی است. تا زمان آماده‌شدن احراز هویت و اعلان‌های عمومی، به درخواست‌های کاربران واقعی دسترسی داده نمی‌شود.</p></div>';
		if ( isset( $_GET['notice'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['notice'] ) ) ) . '</p></div>';
		}
		if ( $id ) { self::details( $id ); } else { self::listing(); }
		echo '</div>';
	}

	private static function listing(): void {
		global $wpdb;
		$instances = ECM_DB::table( 'flow_instances' );
		$events = ECM_DB::table( 'event_definitions' );
		$event_id = absint( $_GET['event_id'] ?? 0 );
		$state = sanitize_key( (string) ( $_GET['state'] ?? '' ) );
		if ( ! isset( self::STATES[ $state ] ) ) { $state = ''; }
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$where = 'WHERE 1=1'; $args = array();
		if ( $event_id ) { $where .= ' AND f.event_id=%d'; $args[] = $event_id; }
		if ( $state ) { $where .= ' AND f.state=%s'; $args[] = $state; }
		$base = "FROM {$instances} f LEFT JOIN {$events} e ON e.id=f.event_id {$where}";
		$count_sql = "SELECT COUNT(*) {$base}";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, ...$args ) : $count_sql );
		$sql = "SELECT f.id,f.event_id,f.state,f.current_step_key,f.created_at,f.updated_at,f.lock_version,e.title AS event_title {$base} ORDER BY f.updated_at DESC,f.id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $args, array( 25, ( $page - 1 ) * 25 ) ) ) );
		$event_list = ECM_Events::all();
		echo '<form method="get"><input type="hidden" name="page" value="ecm-flow-review"><label>رویداد <select name="event_id"><option value="0">همه رویدادها</option>';
		foreach ( $event_list as $ev ) {
			echo '<option value="' . esc_attr( $ev->id ) . '" ' . selected( $event_id, (int) $ev->id, false ) . '>' . esc_html( $ev->title ) . '</option>';
		}
		echo '</select></label> <label>وضعیت <select name="state"><option value="">همه وضعیت‌ها</option>';
		foreach ( self::STATES as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $state, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> <button class="button">اعمال فیلتر</button></form>';
		echo '<p>تعداد درخواست‌ها: <strong>' . esc_html( $total ) . '</strong></p><table class="widefat striped"><thead><tr><th>شناسه</th><th>رویداد</th><th>مرحله / وضعیت</th><th>آخرین تغییر</th><th>جزئیات</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row->id ) . '</td><td>' . esc_html( $row->event_title ?: $row->event_id ) . '</td><td>' . esc_html( self::state_label( (string) $row->state ) ) . '</td><td>' . esc_html( $row->updated_at ) . ' UTC</td><td><a href="' . esc_url( self::url( (int) $row->id ) ) . '">مشاهده و بررسی</a></td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="5">درخواستی یافت نشد.</td></tr>'; }
		echo '</tbody></table>';
		$pages = (int) ceil( $total / 25 );
		if ( $pages > 1 ) {
			echo '<p>' . wp_kses_post( paginate_links( array(
				'base' => add_query_arg( array( 'page' => 'ecm-flow-review', 'event_id' => $event_id, 'state' => $state, 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
				'current' => $page, 'total' => $pages, 'type' => 'plain',
			) ) ) . '</p>';
		}
	}

	private static function details( int $id ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ECM_DB::table( 'flow_instances' ) . ' WHERE id=%d', $id ) );
		if ( ! $row ) { echo '<p>درخواست یافت نشد.</p>'; return; }
		$data = json_decode( (string) $row->data_json, true );
		if ( ! is_array( $data ) ) { $data = array(); }
		$files = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ECM_DB::table( 'flow_files' ) . ' WHERE instance_id=%d ORDER BY version_no DESC,id DESC', $id ) );
		$audit = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type,meta,actor_user_id,created_at FROM ' . ECM_DB::table( 'events' ) . ' WHERE flow_instance_id=%d ORDER BY id DESC LIMIT 40', $id ) );
		echo '<p><a href="' . esc_url( self::url() ) . '">بازگشت به فهرست</a></p>';
		echo '<h2>درخواست ' . esc_html( $row->id ) . ' | ' . esc_html( self::state_label( (string) $row->state ) ) . '</h2>';
		echo '<p>رویداد: ' . esc_html( $row->event_id ) . ' | نسخه فرایند: ' . esc_html( $row->flow_version_id ) . ' | شناسه ثبت‌نام نهایی: ' . esc_html( $row->linked_registration_id ?: 'ندارد' ) . ' | بازنگری: ' . esc_html( $row->lock_version ) . '</p>';
		if ( 'approved' === (string) $row->state && (int) $row->linked_registration_id > 0 ) {
			$primary = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ECM_DB::table( 'participants' ) . " WHERE registration_id=%d AND participant_type='primary' LIMIT 1", (int) $row->linked_registration_id ) );
			$card = $primary ? ECM_Tickets::card_url( $primary ) : '';
			if ( $card ) {
				echo '<p><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' . esc_url( $card ) . '">مشاهده کارت رسمی صادرشده</a></p>';
			}
		}
		echo '<h3>داده‌های ثبت‌شده</h3><table class="widefat striped"><tbody>';
		foreach ( $data as $key => $value ) {
			if ( '__schema_hash' === $key ) { continue; }
			if ( '__guests' === $key ) {
				$guests = json_decode( (string) $value, true );
				$value = is_array( $guests ) ? implode( ' | ', array_map( static fn( array $person ): string => (string) ( $person['name'] ?? '' ) . ' (' . (string) ( $person['phone'] ?? '' ) . ')', $guests ) ) : '';
			}
			echo '<tr><th>' . esc_html( $key ) . '</th><td>' . esc_html( is_scalar( $value ) ? (string) $value : '' ) . '</td></tr>';
		}
		echo '</tbody></table><h3>تصاویر رسید (خصوصی)</h3>';
		if ( ! $files ) { echo '<p>رسیدی بارگذاری نشده است.</p>'; }
		foreach ( $files as $file ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'ecm_flow_receipt', 'file_id' => (int) $file->id ), admin_url( 'admin-post.php' ) ), 'ecm_flow_receipt_' . (int) $file->id );
			echo '<p>نسخه ' . esc_html( $file->version_no ) . ' (' . esc_html( $file->status ) . ') | <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">مشاهده امن تصویر</a></p>';
		}
		if ( 'under_review' === $row->state || 'needs_correction' === $row->state ) {
			echo '<h3>تصمیم مدیریتی</h3>';
			if ( 'under_review' === $row->state ) {
				self::decision_form( $row, 'needs_correction', 'درخواست اصلاح رسید' );
				self::decision_form( $row, 'rejected', 'رد نهایی' );
				if ( self::issuance_allowed() ) {
					self::decision_form( $row, 'approved', 'تأیید نهایی و صدور کارت واقعی' );
				} else {
					echo '<p class="description">صدور کارت تا تنظیم WP_ENVIRONMENT_TYPE=staging و ECM_WORKFLOW_STAGING_ISSUANCE=true در محیط آزمایشی غیرفعال است.</p>';
				}
			} else {
				self::decision_form( $row, 'awaiting_receipt', 'بازگرداندن به بارگذاری رسید' );
			}
		}
		echo '<h3>تاریخچه تصمیم‌ها</h3><table class="widefat striped"><thead><tr><th>زمان UTC</th><th>عملیات</th><th>مدیر</th><th>توضیحات</th></tr></thead><tbody>';
		foreach ( $audit as $entry ) {
			$meta = json_decode( (string) $entry->meta, true );
			$reason = is_array( $meta ) ? (string) ( $meta['reason_public'] ?? '' ) : '';
			echo '<tr><td>' . esc_html( $entry->created_at ) . '</td><td>' . esc_html( $entry->event_type ) . '</td><td>' . esc_html( $entry->actor_user_id ) . '</td><td>' . esc_html( $reason ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function decision_form( object $row, string $decision, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0;padding:12px;border:1px solid #ddd;max-width:700px">';
		echo '<input type="hidden" name="action" value="ecm_flow_review_decision"><input type="hidden" name="instance_id" value="' . esc_attr( $row->id ) . '"><input type="hidden" name="revision" value="' . esc_attr( $row->lock_version ) . '"><input type="hidden" name="decision" value="' . esc_attr( $decision ) . '">';
		wp_nonce_field( 'ecm_flow_review_' . (int) $row->id, 'ecm_nonce' );
		if ( in_array( $decision, array( 'rejected', 'needs_correction' ), true ) ) {
			echo '<label>دلیل قابل نمایش به متقاضی <textarea name="reason" rows="2" maxlength="500" required class="large-text"></textarea></label>';
		}
		if ( 'approved' === $decision ) {
			echo '<p><label><input type="checkbox" name="confirm_issue" value="1" required> تأیید می‌کنم که ثبت‌نام رسمی، کارت و QR واقعی ایجاد خواهند شد.</label></p>';
		}
		echo '<p><button class="button ' . ( 'approved' === $decision ? 'button-primary' : '' ) . '">' . esc_html( $label ) . '</button></p></form>';
	}

	public static function handle_decision(): void {
		self::guard();
		$id = absint( $_POST['instance_id'] ?? 0 );
		$revision = absint( $_POST['revision'] ?? 0 );
		$decision = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );
		check_admin_referer( 'ecm_flow_review_' . $id, 'ecm_nonce' );
		if ( 'approved' === $decision ) {
			$result = self::issuance_allowed() && '1' === (string) ( $_POST['confirm_issue'] ?? '' )
				? ECM_Workflow_Issuance::approve( $id, $revision )
				: new WP_Error( 'ecm_flow_issuance_disabled', 'صدور کارت در این محیط مجاز نیست.' );
		} else {
			$reason = substr( sanitize_textarea_field( (string) wp_unslash( $_POST['reason'] ?? '' ) ), 0, 500 );
			$result = self::transition( $id, $revision, $decision, $reason );
		}
		$message = is_wp_error( $result ) ? 'خطا: ' . $result->get_error_message() : 'عملیات با موفقیت ثبت شد.';
		wp_safe_redirect( self::url( $id, $message ) );
		exit;
	}

	/** Optimistic concurrency + row lock + audit, with no external callbacks in transaction. */
	public static function transition( int $id, int $revision, string $target, string $reason ): bool|WP_Error {
		if ( ! self::can_review() || ! in_array( $target, array( 'needs_correction', 'rejected', 'awaiting_receipt' ), true ) ) {
			return new WP_Error( 'ecm_flow_forbidden', 'انتقال وضعیت مجاز نیست.' );
		}
		if ( 'awaiting_receipt' !== $target && '' === trim( $reason ) ) {
			return new WP_Error( 'ecm_flow_reason_required', 'ثبت علت ضروری است.' );
		}
		global $wpdb;
		$instances = ECM_DB::table( 'flow_instances' );
		$files = ECM_DB::table( 'flow_files' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return new WP_Error( 'ecm_flow_transaction', 'شروع تراکنش ناموفق بود.' ); }
		$committed = false;
		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$instances} WHERE id=%d FOR UPDATE", $id ) );
			if ( ! $row || (int) $row->lock_version !== $revision || ! ECM_Workflow::can_transition( (string) $row->state, $target ) || $row->linked_registration_id ) {
				return new WP_Error( 'ecm_flow_stale_state', 'درخواست تغییر کرده یا انتقال وضعیت مجاز نیست.' );
			}
			$step = (string) $row->current_step_key;
			if ( 'awaiting_receipt' === $target ) {
				$version = $wpdb->get_row( $wpdb->prepare( 'SELECT definition_json FROM ' . ECM_DB::table( 'flow_versions' ) . ' WHERE id=%d AND event_id=%d', $row->flow_version_id, $row->event_id ) );
				$definition = $version ? json_decode( (string) $version->definition_json, true ) : null;
				if ( ! is_array( $definition ) || ( $definition['steps'][2]['type'] ?? '' ) !== 'receipt' ) {
					return new WP_Error( 'ecm_flow_version', 'مرحله اصلاح رسید نامعتبر است.' );
				}
				$step = (string) $definition['steps'][2]['key'];
			}
			$now = ECM_Utils::now_utc();
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$instances} SET state=%s,current_step_key=%s,lock_version=lock_version+1,updated_at=%s WHERE id=%d AND lock_version=%d AND state=%s", $target, $step, $now, $id, $revision, $row->state ) ) ) {
				return new WP_Error( 'ecm_flow_concurrency', 'درخواست هم‌زمان دیگری وجود دارد.' );
			}
			if ( 'awaiting_receipt' !== $target ) {
				$latest = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$files} WHERE instance_id=%d ORDER BY version_no DESC,id DESC LIMIT 1", $id ) );
				if ( $latest && false === $wpdb->update( $files, array( 'status' => $target ), array( 'id' => (int) $latest->id ) ) ) {
					return new WP_Error( 'ecm_flow_receipt_update', 'ثبت وضعیت رسید ممکن نشد.' );
				}
			}
			$audit = $wpdb->insert( ECM_DB::table( 'events' ), array(
				'registration_id' => 0, 'domain_event_id' => (int) $row->event_id, 'flow_instance_id' => $id,
				'actor_user_id' => get_current_user_id(), 'correlation_id' => 'flow:' . $row->public_id,
				'event_type' => 'workflow_review',
				'meta' => wp_json_encode( array( 'from' => $row->state, 'to' => $target, 'reason_public' => $reason, 'revision' => $revision + 1 ), JSON_UNESCAPED_UNICODE ),
				'created_at' => $now,
			) );
			if ( ! $audit ) { return new WP_Error( 'ecm_flow_audit_failed', 'ثبت تاریخچه ممکن نشد.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { return new WP_Error( 'ecm_flow_commit', 'ثبت نهایی تغییر وضعیت ممکن نشد.' ); }
			$committed = true;
			return true;
		} finally {
			if ( ! $committed ) { $wpdb->query( 'ROLLBACK' ); }
		}
	}

	/** Authenticated, non-cacheable streaming of private images. No public URL. */
	public static function stream_receipt(): void {
		self::guard();
		$id = absint( $_GET['file_id'] ?? 0 );
		check_admin_referer( 'ecm_flow_receipt_' . $id );
		global $wpdb;
		$file = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ECM_DB::table( 'flow_files' ) . ' WHERE id=%d', $id ) );
		if ( ! $file || ! preg_match( '/^[a-f0-9]{48}\.(?:jpg|png|webp)$/D', (string) $file->file_reference ) ) {
			wp_die( 'فایل یافت نشد.', '', array( 'response' => 404 ) );
		}
		$root = ECM_Workflow_Receipts::private_root();
		if ( is_wp_error( $root ) ) { wp_die( 'فضای خصوصی در دسترس نیست.', '', array( 'response' => 503 ) ); }
		$path = $root . DIRECTORY_SEPARATOR . $file->file_reference;
		$real = realpath( $path );
		$allowed = array( 'image/jpeg', 'image/png', 'image/webp' );
		if ( is_link( $path ) || ! $real || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR )
			|| ! is_file( $real ) || ! in_array( (string) $file->mime_type, $allowed, true )
			|| (int) filesize( $real ) !== (int) $file->size_bytes || ! hash_equals( (string) $file->sha256, hash_file( 'sha256', $real ) ) ) {
			wp_die( 'اعتبار تصویر تأیید نشد.', '', array( 'response' => 404 ) );
		}
		if ( ! headers_sent() ) {
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: private, no-store, max-age=0' );
			header( 'Content-Disposition: inline; filename="receipt-preview.' . pathinfo( $real, PATHINFO_EXTENSION ) . '"' );
			header( 'Content-Type: ' . $file->mime_type );
			header( 'Content-Length: ' . filesize( $real ) );
			header( "Content-Security-Policy: default-src 'none'; sandbox" );
		}
		readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
