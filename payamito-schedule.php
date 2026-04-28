<?php
/**
 * Plugin Name: زمان‌بندی پیامک پیامیتو
 * Description: افزونه جانبی برای ارسال زمان‌بندی شده پیامک‌های ووکامرس با پترن (خط خدماتی).
 * Version: 2.1.0
 * Author: آکادمی کارنو
 * Author-URI: https://sepehralimohammadi.com
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('PAYAMITO_SCHEDULE_VERSION', '2.1.0');
define('PAYAMITO_SCHEDULE_DIR',     plugin_dir_path(__FILE__));
define('PAYAMITO_SCHEDULE_URL',     plugin_dir_url(__FILE__));

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

require_once PAYAMITO_SCHEDULE_DIR . 'includes/class-api.php';
require_once PAYAMITO_SCHEDULE_DIR . 'includes/class-scheduler.php';
require_once PAYAMITO_SCHEDULE_DIR . 'includes/class-admin.php';

new Payamito_Admin();
new Payamito_Scheduler();
