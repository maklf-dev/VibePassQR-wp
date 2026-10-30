<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		ECM_Capabilities::hooks();
		ECM_Registration_Admin::hooks();
		ECM_Diagnostics::hooks();
		ECM_Activity::hooks();
		ECM_Events::hooks();
		ECM_Tickets::hooks();
		ECM_Operator::hooks();
		ECM_Reports::hooks();
		ECM_Portal::hooks();
		ECM_Card_Document::hooks();
		add_action( 'init', array( $this, 'maybe_upgrade' ), 1 );
		$public = new ECM_Public();
		$public->hooks();
        ECM_SMS::hooks();
        ECM_OTP::hooks();
        ECM_Tools::hooks();
	        add_action('template_redirect',array('ECM_Upgrade','short_link_redirect'),-1);
	        add_action('template_redirect',array('ECM_Upgrade','aliases'),0);
        add_action('template_redirect',array('ECM_Upgrade','redeem_link'),1);
        add_filter('template_include',array('ECM_Upgrade','canvas'),99);

		if ( is_admin() ) {
			$admin = new ECM_Admin();
			$admin->hooks();
		}

	}

	public function maybe_upgrade(): void {
		if ( ECM_VERSION !== get_option( 'ecm_db_version' ) ) {
			ECM_Activator::activate();
			ECM_Diagnostics::record( 'migration', 'Plugin schema upgraded', array( 'version' => ECM_VERSION ) );
		}
	}

}
