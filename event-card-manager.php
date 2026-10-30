<?php
/**
 * Plugin Name: Event Card Manager
 * Plugin URI:  https://github.com/maklf-dev/event-card-manager
 * Description: فرم ثبت‌نام، پرداخت قابل توسعه، کارت QR امن، بازیابی کارت، حضور و غیاب و گزارش مدیریتی.
 * Version:     0.6.5
 * Author:      maklf-dev
 * License:     GPL-2.0-or-later
 * Text Domain: event-card-manager
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'ECM_VERSION', '0.6.5' );
define( 'ECM_FILE', __FILE__ );
define( 'ECM_PATH', plugin_dir_path( __FILE__ ) );
define( 'ECM_URL', plugin_dir_url( __FILE__ ) );

require_once ECM_PATH . 'includes/class-ecm-utils.php';
require_once ECM_PATH . 'includes/class-ecm-db.php';
require_once ECM_PATH . 'includes/class-ecm-capabilities.php';
require_once ECM_PATH . 'includes/class-ecm-registration-admin.php';
require_once ECM_PATH . 'includes/class-ecm-diagnostics.php';
require_once ECM_PATH . 'includes/class-ecm-activity.php';
require_once ECM_PATH . 'includes/class-ecm-events.php';
require_once ECM_PATH . 'includes/class-ecm-participants.php';
require_once ECM_PATH . 'includes/class-ecm-tickets.php';
require_once ECM_PATH . 'includes/class-ecm-operator.php';
require_once ECM_PATH . 'includes/class-ecm-reports.php';
require_once ECM_PATH . 'includes/class-ecm-portal.php';
require_once ECM_PATH . 'includes/class-ecm-card-document.php';
require_once ECM_PATH . 'includes/class-ecm-activator.php';
require_once ECM_PATH . 'includes/class-ecm-payment.php';
require_once ECM_PATH . 'includes/class-ecm-public.php';
require_once ECM_PATH . 'includes/class-ecm-admin.php';
require_once ECM_PATH . 'includes/class-ecm-upgrade.php';
require_once ECM_PATH . 'includes/class-ecm-sms-transports.php';
require_once ECM_PATH . 'includes/class-ecm-sms.php';
require_once ECM_PATH . 'includes/class-ecm-otp.php';
require_once ECM_PATH . 'includes/class-ecm-tools.php';
require_once ECM_PATH . 'includes/class-ecm-plugin.php';

register_activation_hook( __FILE__, array( 'ECM_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ECM_Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'event-card-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		ECM_Plugin::instance()->boot();
	}
);
