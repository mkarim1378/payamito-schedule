<?php
/**
 * Plugin Name: زمان‌بندی پیامک پیامیتو
 * Description: افزونه جانبی برای ارسال زمان‌بندی شده پیامک‌های ووکامرس با پترن (خط خدماتی).
 * Version: 2.12.0
 * Author: آکادمی کارنو
 * Author-URI: https://sepehralimohammadi.com
 * Requires Plugins: woocommerce
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

define('PAYAMITO_SCHEDULE_VERSION', '2.12.0');
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

add_action('init', function () {
    add_rewrite_rule('^pay/([0-9]+)/?$', 'index.php?payamito_pay_id=$matches[1]', 'top');
});

add_filter('query_vars', function (array $vars): array {
    $vars[] = 'payamito_pay_id';
    return $vars;
});

add_action('template_redirect', function () {
    $order_id = (int) get_query_var('payamito_pay_id');
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Abstract_Order) {
        wp_safe_redirect(home_url());
        exit;
    }

    wp_safe_redirect($order->get_checkout_payment_url());
    exit;
});

add_action('plugins_loaded', function () {
    $stored = get_option('payamito_schedule_db_version', '0');
    if ($stored !== PAYAMITO_SCHEDULE_VERSION) {
        Payamito_Logger::create_table();
        update_option('payamito_schedule_db_version', PAYAMITO_SCHEDULE_VERSION);
    }
});

register_activation_hook(__FILE__, function () {
    Payamito_Logger::create_table();
    if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('payamito_weekly_log_cleanup', [], 'payamito-sms')) {
        as_schedule_recurring_action(time(), WEEK_IN_SECONDS, 'payamito_weekly_log_cleanup', [], 'payamito-sms');
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('payamito_weekly_log_cleanup', [], 'payamito-sms');
    }
    flush_rewrite_rules();
});

add_action('payamito_weekly_log_cleanup', function () {
    $credentials = get_option('payamito_credentials', []);
    $days        = max(1, (int) ($credentials['log_retention_days'] ?? 90));
    Payamito_Logger::purge_old($days);
});

new Payamito_Admin();
new Payamito_Scheduler();
