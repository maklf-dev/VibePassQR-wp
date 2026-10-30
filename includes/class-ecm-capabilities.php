<?php

defined( 'ABSPATH' ) || exit;

/**
 * Central capability registry.
 *
 * Custom roles are never overwritten. User Role Editor can assign any of the
 * declared capabilities and access_ecm is derived automatically when needed.
 */
final class ECM_Capabilities {
	public static function all(): array {
		return array(
			'access_ecm'              => 'ورود به منوی کارت رویداد',
			'view_ecm_reports'         => 'مشاهده و خروجی گزارش‌ها',
			'manage_ecm_events'        => 'مدیریت رویدادها و نوبت‌ها',
			'manage_ecm_attendance'    => 'مدیریت حضور و غیاب',
			'record_ecm_attendance'    => 'ثبت حضور شرکت‌کنندگان',
			'view_own_ecm_attendance'  => 'مشاهده حضورهای ثبت‌شده توسط خود',
			'manage_ecm_settings'      => 'مدیریت تنظیمات عمومی',
			'manage_ecm_sms'           => 'مدیریت و آزمایش پیامک',
			'manage_ecm_files'         => 'مدیریت فایل‌ها و تاریخچه',
			'manage_ecm_diagnostics'   => 'مشاهده و دانلود عیب‌یابی',
			'repair_ecm_pages'         => 'حذف و بازسازی برگه‌های افزونه',
		);
	}

	public static function hooks(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'derive_menu_access' ), 10, 4 );
		add_action( 'init', array( __CLASS__, 'ensure_roles' ), 2 );
	}

	/** Recreate or repair only the required caps of the built-in ECM roles. */
	public static function ensure_roles(): void {
		$manager  = get_role( 'ecm_manager' );
		$operator = get_role( 'ecm_attendance_operator' );
		if (
			! $manager
			|| ! $manager->has_cap( 'read' )
			|| ! $manager->has_cap( 'access_ecm' )
			|| ! $manager->has_cap( 'view_ecm_reports' )
			|| ! $manager->has_cap( 'manage_ecm_attendance' )
			|| ! $operator
			|| ! $operator->has_cap( 'read' )
			|| ! $operator->has_cap( 'access_ecm' )
			|| ! $operator->has_cap( 'record_ecm_attendance' )
			|| ! $operator->has_cap( 'view_own_ecm_attendance' )
		) {
			self::install();
		}
	}

	public static function install(): void {
		$manager = get_role( 'ecm_manager' );
		if ( ! $manager ) {
			$manager = add_role(
				'ecm_manager',
				'مدیر رویداد',
				array(
					'read'                  => true,
					'access_ecm'            => true,
					'view_ecm_reports'       => true,
					'manage_ecm_attendance' => true,
				)
			);
		}

		$operator = get_role( 'ecm_attendance_operator' );
		if ( ! $operator ) {
			$operator = add_role(
				'ecm_attendance_operator',
				'مدیر ثبت حضور',
				array(
					'read'                    => true,
					'access_ecm'              => true,
					'record_ecm_attendance'   => true,
					'view_own_ecm_attendance' => true,
				)
			);
		}

		foreach ( array_keys( wp_roles()->roles ) as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role || ( 'administrator' !== $role_key && ! $role->has_cap( 'manage_options' ) ) ) {
				continue;
			}
			foreach ( array_keys( self::all() ) as $capability ) {
				$role->add_cap( $capability );
			}
		}

		// Existing manager roles from 0.1/0.2 keep their historical report access.
		if ( $manager ) {
			$manager->add_cap( 'read' );
			$manager->add_cap( 'access_ecm' );
			$manager->add_cap( 'view_ecm_reports' );
			$manager->add_cap( 'manage_ecm_attendance' );
		}
		if ( $operator ) {
			$operator->add_cap( 'read' );
			$operator->add_cap( 'access_ecm' );
			$operator->add_cap( 'record_ecm_attendance' );
			$operator->add_cap( 'view_own_ecm_attendance' );
		}
	}

	public static function derive_menu_access( array $allcaps, array $caps, array $args, WP_User $user ): array {
		// A WordPress administrator must never be locked out because an update was
		// installed by replacing files while the activation hook was not executed.
		// This is a runtime safety net; install() still persists the capabilities so
		// User Role Editor can manage non-administrator roles normally.
		if ( ! empty( $allcaps['manage_options'] ) || ( is_multisite() && is_super_admin( $user->ID ) ) ) {
			foreach ( array_keys( self::all() ) as $capability ) {
				$allcaps[ $capability ] = true;
			}
			return $allcaps;
		}

		if ( ! empty( $allcaps['access_ecm'] ) ) {
			return $allcaps;
		}
		foreach ( array_keys( self::all() ) as $capability ) {
			if ( 'access_ecm' !== $capability && ! empty( $allcaps[ $capability ] ) ) {
				$allcaps['access_ecm'] = true;
				break;
			}
		}
		return $allcaps;
	}

	public static function can_use_tools(): bool {
		foreach ( array( 'manage_ecm_files', 'manage_ecm_diagnostics', 'repair_ecm_pages', 'manage_ecm_sms' ) as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}
		return false;
	}
}
