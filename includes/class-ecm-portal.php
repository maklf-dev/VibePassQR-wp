<?php

defined( 'ABSPATH' ) || exit;

/**
 * Standalone role-aware login and workspace.
 *
 * Authentication happens through wp_signon() and returns directly to the ECM
 * canvas. This deliberately avoids wp-login.php and third-party login redirects.
 */
final class ECM_Portal {
	private const LOGIN_ACTION  = 'ecm_portal_login';
	private const LOGOUT_ACTION = 'ecm_portal_logout';

	public static function hooks(): void {
		add_shortcode( 'ecm_portal', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_frontend_action' ), -5 );
		add_action( 'admin_post_nopriv_' . self::LOGIN_ACTION, array( __CLASS__, 'login' ) );
		add_action( 'admin_post_' . self::LOGIN_ACTION, array( __CLASS__, 'login' ) );
		add_action( 'admin_post_' . self::LOGOUT_ACTION, array( __CLASS__, 'logout' ) );
	}

	public static function handle_frontend_action(): void {
		$action = isset( $_REQUEST['ecm_portal_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['ecm_portal_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::LOGIN_ACTION === $action && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::login();
		}
		if ( self::LOGOUT_ACTION === $action && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::logout();
		}
	}

	public static function url( array $args = array() ): string {
		return ECM_Utils::page_url( 'portal', $args );
	}

	public static function shortcode(): string {
		wp_enqueue_style( 'ecm-design' );
		wp_enqueue_style( 'ecm-public' );
		if ( ! is_user_logged_in() ) {
			return self::shell( self::login_markup() );
		}

		if ( ! current_user_can( 'access_ecm' ) ) {
			return self::shell(
				'<div class="ecm-state ecm-state--error"><span class="ecm-state__icon">×</span><h1>دسترسی تعریف نشده است</h1><p>این حساب وارد شده، اما هیچ‌یک از دسترسی‌های افزونه را ندارد.</p>' . self::logout_link() . '</div>'
			);
		}

		$view = isset( $_GET['ecm_portal_view'] ) ? sanitize_key( wp_unslash( $_GET['ecm_portal_view'] ) ) : '';
		$report_request = isset( $_GET['ecm_view'] ) || isset( $_GET['ecm_event'] ) || isset( $_GET['ecm_session'] ) || isset( $_GET['ecm_payment'] ) || isset( $_GET['ecm_search'] ) || isset( $_GET['ecm_paged'] ) || isset( $_GET['cols'] );
		if ( 'checkin' === $view && current_user_can( 'record_ecm_attendance' ) ) {
			return self::shell( ECM_Operator::portal_markup(), 'ecm-operator-shell' );
		}
		if ( ( 'reports' === $view || $report_request ) && current_user_can( 'view_ecm_reports' ) ) {
			return self::shell( ECM_Reports::render( false ), 'ecm-report-shell' );
		}

		// Single-purpose roles land directly in their working area.
		if ( current_user_can( 'record_ecm_attendance' ) && ! current_user_can( 'view_ecm_reports' ) ) {
			return self::shell( ECM_Operator::portal_markup(), 'ecm-operator-shell' );
		}
		if ( current_user_can( 'view_ecm_reports' ) && ! current_user_can( 'record_ecm_attendance' ) ) {
			return self::shell( ECM_Reports::render( false ), 'ecm-report-shell' );
		}

		return self::shell( self::home_markup() );
	}

	private static function login_markup(): string {
		$error = isset( $_GET['ecm_login_error'] ) ? sanitize_key( wp_unslash( $_GET['ecm_login_error'] ) ) : '';
		$messages = array(
			'credentials' => 'نام کاربری، ایمیل یا رمز عبور درست نیست.',
			'access'      => 'ورود انجام شد، اما برای این حساب دسترسی افزونه تعریف نشده است.',
			'rate'        => 'تلاش‌های ورود بیش از حد بوده است. چند دقیقه بعد دوباره امتحان کنید.',
			'security'    => 'اعتبار فرم ورود تمام شده است. صفحه را تازه‌سازی کنید.',
		);
		ob_start();
		?>
		<div class="ecm-portal-login">
			<div class="ecm-portal-login__brand"><span class="ecm-kicker">EVENT OPERATIONS</span><h1>ورود اختصاصی مدیریت رویداد</h1><p>ورود مدیر رویداد و مدیر ثبت حضور، مستقل از پنل‌های کاربری قالب و افزونه‌های دیگر.</p></div>
			<?php if ( $error ) : ?><div class="ecm-notice ecm-notice--error" role="alert"><?php echo esc_html( $messages[ $error ] ?? 'ورود انجام نشد.' ); ?></div><?php endif; ?>
			<form class="ecm-form ecm-portal-login__form" method="post" action="<?php echo esc_url( self::url() ); ?>">
				<input type="hidden" name="ecm_portal_action" value="<?php echo esc_attr( self::LOGIN_ACTION ); ?>">
				<?php wp_nonce_field( self::LOGIN_ACTION, 'ecm_portal_nonce' ); ?>
				<div class="ecm-field"><label for="ecm-login-user">نام کاربری یا ایمیل</label><input id="ecm-login-user" type="text" name="log" autocomplete="username" required autofocus></div>
				<div class="ecm-field"><label for="ecm-login-pass">رمز عبور</label><input id="ecm-login-pass" type="password" name="pwd" autocomplete="current-password" required></div>
				<label class="ecm-portal-remember"><input type="checkbox" name="rememberme" value="1" checked> ورود من در این دستگاه حفظ شود</label>
				<button class="ecm-button ecm-button--primary ecm-button--wide" type="submit">ورود به پنل اختصاصی</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function home_markup(): string {
		$user = wp_get_current_user();
		ob_start();
		?>
		<div class="ecm-portal-home">
			<div class="ecm-hero"><div><span class="ecm-kicker">ROLE WORKSPACE</span><h1>سلام <?php echo esc_html( $user->display_name ); ?></h1><p>بخش موردنظر را انتخاب کنید.</p></div><?php echo self::logout_link(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="ecm-content"><div class="ecm-portal-grid">
				<?php if ( current_user_can( 'record_ecm_attendance' ) ) : ?><a class="ecm-portal-card" href="<?php echo esc_url( self::url( array( 'ecm_portal_view' => 'checkin' ) ) ); ?>"><span>⌁</span><strong>ثبت سریع حضور</strong><small>دوربین، اسکن پیوسته و ثبت گروهی</small></a><?php endif; ?>
				<?php if ( current_user_can( 'view_ecm_reports' ) ) : ?><a class="ecm-portal-card" href="<?php echo esc_url( self::url( array( 'ecm_portal_view' => 'reports' ) ) ); ?>"><span>▦</span><strong>گزارش‌ها و آمار</strong><small>داشبورد، فهرست‌ها و پروفایل‌ها</small></a><?php endif; ?>
				<?php if ( current_user_can( 'manage_ecm_events' ) ) : ?><a class="ecm-portal-card" href="<?php echo esc_url( admin_url( 'admin.php?page=ecm-events' ) ); ?>"><span>◫</span><strong>مدیریت رویدادها</strong><small>فرم، نوبت و دسترسی اپراتورها</small></a><?php endif; ?>
			</div></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function login(): void {
		$redirect = self::url();
		if ( is_user_logged_in() ) {
			wp_safe_redirect( $redirect );
			exit;
		}
		if ( empty( $_POST['ecm_portal_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ecm_portal_nonce'] ) ), self::LOGIN_ACTION ) ) {
			wp_safe_redirect( add_query_arg( 'ecm_login_error', 'security', $redirect ) );
			exit;
		}
		if ( ! self::allow_attempt() ) {
			wp_safe_redirect( add_query_arg( 'ecm_login_error', 'rate', $redirect ) );
			exit;
		}
		$credentials = array(
			'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
			'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ),
			'remember'      => ! empty( $_POST['rememberme'] ),
		);
		$user = wp_signon( $credentials, is_ssl() );
		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( add_query_arg( 'ecm_login_error', 'credentials', $redirect ) );
			exit;
		}
		wp_set_current_user( (int) $user->ID );
		delete_transient( self::attempt_key() );
		if ( ! user_can( $user, 'access_ecm' ) ) {
			wp_logout();
			wp_safe_redirect( add_query_arg( 'ecm_login_error', 'access', $redirect ) );
			exit;
		}
		ECM_Diagnostics::record( 'portal', 'ECM portal login successful', array( 'user_id' => (int) $user->ID ) );
		ECM_Activity::record( 'operation', array( 'operation' => 'portal_login_success' ) );
		wp_safe_redirect( self::destination_for( $user ) );
		exit;
	}

	public static function logout(): void {
		check_admin_referer( self::LOGOUT_ACTION );
		wp_logout();
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function destination_for( WP_User $user ): string {
		$can_checkin = user_can( $user, 'record_ecm_attendance' );
		$can_report  = user_can( $user, 'view_ecm_reports' );
		if ( $can_checkin && ! $can_report ) {
			return self::url( array( 'ecm_portal_view' => 'checkin' ) );
		}
		if ( $can_report && ! $can_checkin ) {
			return self::url( array( 'ecm_portal_view' => 'reports' ) );
		}
		return self::url();
	}

	private static function allow_attempt(): bool {
		$key = self::attempt_key();
		$row = (array) get_transient( $key );
		$count = (int) ( $row['count'] ?? 0 ) + 1;
		set_transient( $key, array( 'count' => $count ), 15 * MINUTE_IN_SECONDS );
		return $count <= 12;
	}

	private static function attempt_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'ecm_login_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}

	private static function logout_link(): string {
		$url = wp_nonce_url( add_query_arg( 'ecm_portal_action', self::LOGOUT_ACTION, self::url() ), self::LOGOUT_ACTION );
		return '<a class="ecm-button ecm-button--ghost" href="' . esc_url( $url ) . '">خروج امن</a>';
	}

	private static function shell( string $content, string $class = '' ): string {
		return '<section class="ecm-shell ecm-portal-shell ' . esc_attr( $class ) . '" dir="rtl"><div class="ecm-phone-bar"><span></span><strong>پنل اختصاصی رویداد</strong><span></span></div>' . $content . '</section>';
	}
}
