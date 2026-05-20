<?php
/**
 * Plugin Name: زمان‌بندی پیامک پیامیتو
 * Description: افزونه جانبی برای ارسال زمان‌بندی شده پیامک‌های ووکامرس با پترن (خط خدماتی).
 * Version: 2.31.0
 * Author: آکادمی کارنو
 * Author-URI: https://sepehralimohammadi.com
 * Requires Plugins: woocommerce
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

define('PAYAMITO_SCHEDULE_VERSION', '2.31.0');
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
require_once PAYAMITO_SCHEDULE_DIR . 'includes/class-frontend.php';

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
    // اگه افزونه در دوره‌ای غیرفعال بوده و وضعیت سفارش‌ها عوض شده،
    // یک job یک‌باره schedule می‌شه که بلافاصله stale actions رو پاکسازی می‌کنه
    if (function_exists('as_schedule_single_action') && !as_has_scheduled_action('payamito_stale_action_cleanup', [], 'payamito-sms')) {
        as_schedule_single_action(time(), 'payamito_stale_action_cleanup', [], 'payamito-sms');
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('payamito_weekly_log_cleanup', [], 'payamito-sms');
    }
    flush_rewrite_rules();
});

add_action('payamito_stale_action_cleanup', function () {
    if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler_Store')) return;

    $store    = ActionScheduler_Store::instance();
    $per_page = 50;
    $page     = 1;

    do {
        $actions = as_get_scheduled_actions([
            'hook'     => 'payamito_execute_scheduled_sms',
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'group'    => 'payamito-sms',
            'per_page' => $per_page,
            'page'     => $page,
        ]);

        if (empty($actions)) break;

        foreach ($actions as $action_id => $action) {
            $args           = $action->get_args();
            $trigger_status = $args['trigger_status'] ?? '';
            if ($trigger_status === '') continue;

            $order_id = (int) ($args['order_id'] ?? 0);
            if (!$order_id) continue;

            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Abstract_Order) continue;
            if ($order->get_status() === $trigger_status) continue;

            $phone     = $order->get_billing_phone();
            $send_type = $args['send_type'] ?? 'pattern';
            try { $phone = Payamito_Scheduler::normalize_phone($phone); } catch (\InvalidArgumentException $e) {}

            Payamito_Logger::insert([
                'order_id'     => $order_id,
                'mobile'       => $phone,
                'pattern'      => $send_type === 'text' ? 'text' : ($args['pattern_code'] ?? ''),
                'vars'         => $send_type === 'text' ? ($args['text_body'] ?? '') : ($args['vars_str'] ?? ''),
                'status'       => 'cancelled',
                'response'     => 'وضعیت سفارش در زمان غیرفعال بودن افزونه تغییر کرد — پیامک لغو شد',
                'attempt'      => (int) ($args['attempt'] ?? 1),
                'scheduled_at' => $args['scheduled_at'] ?? current_time('mysql'),
            ]);

            try { $store->cancel_action($action_id); } catch (\Throwable $e) {}
        }

        $page++;
    } while (count($actions) === $per_page);
});

add_action('payamito_weekly_log_cleanup', function () {
    $credentials = get_option('payamito_credentials', []);
    $days        = max(1, (int) ($credentials['log_retention_days'] ?? 90));
    Payamito_Logger::purge_old($days);

    // حذف کوپن‌های منقضی‌شده ایجادشده توسط پیامیتو
    $expired = get_posts([
        'post_type'      => 'shop_coupon',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_key'       => '_payamito_coupon',
        'meta_value'     => '1',
        'date_query'     => [],
        'fields'         => 'ids',
    ]);
    foreach ($expired as $coupon_id) {
        $expiry = get_post_meta($coupon_id, 'date_expires', true);
        if ($expiry && (int) $expiry < time()) {
            wp_delete_post($coupon_id, true);
        }
    }
});

new Payamito_Admin();
new Payamito_Scheduler();
new Payamito_Frontend();
