<?php
/**
 * Plugin Name: زمان‌بندی پیامک پیامیتو
 * Description: افزونه جانبی برای ارسال زمان‌بندی شده پیامک‌های ووکامرس با پترن (خط خدماتی).
 * Version: 2.5.0
 * Author: آکادمی کارنو
 * Author-URI: https://sepehralimohammadi.com
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('PAYAMITO_SCHEDULE_VERSION', '2.5.0');
define('PAYAMITO_SCHEDULE_DIR',     plugin_dir_path(__FILE__));
define('PAYAMITO_SCHEDULE_URL',     plugin_dir_url(__FILE__));

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

require_once PAYAMITO_SCHEDULE_DIR . 'includes/class-logger.php';
require_once PAYAMITO_SCHEDULE_DIR . 'includes/class-log-list-table.php';
require_once PAYAMITO_SCHEDULE_DIR . 'includes/class-api.php';
require_once PAYAMITO_SCHEDULE_DIR . 'includes/class-scheduler.php';
require_once PAYAMITO_SCHEDULE_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, function () {
    Payamito_Logger::create_table();
    if (!wp_next_scheduled('payamito_weekly_log_cleanup')) {
        wp_schedule_event(time(), 'weekly', 'payamito_weekly_log_cleanup');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('payamito_weekly_log_cleanup');
});

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => 'Once Weekly',
        ];
    }
    return $schedules;
});

add_action('payamito_weekly_log_cleanup', function () {
    $credentials = get_option('payamito_credentials', []);
    $days        = max(1, (int) ($credentials['log_retention_days'] ?? 90));
    Payamito_Logger::purge_old($days);
});

new Payamito_Admin();
new Payamito_Scheduler();
