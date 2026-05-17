<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$credentials = get_option('payamito_credentials', []);
if (empty($credentials['delete_data_on_uninstall'])) return;

global $wpdb;

// ── حذف آپشن‌ها ───────────────────────────────────────────────
delete_option('payamito_credentials');
delete_option('payamito_schedule_rules');
delete_option('payamito_schedule_db_version');

// ── حذف تمام AS actions پیامیتو (logs اول تا orphan نشه) ─────
$hooks = ['payamito_execute_scheduled_sms', 'payamito_weekly_log_cleanup'];
foreach ($hooks as $hook) {
    $action_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s",
            $hook
        )
    );
    if ($action_ids) {
        $ids_in = implode(',', array_map('intval', $action_ids));
        $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ($ids_in)");
        $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ($ids_in)");
    }
}

// حذف گروه payamito-sms
$group_id = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s",
        'payamito-sms'
    )
);
if ($group_id) {
    $wpdb->delete($wpdb->prefix . 'actionscheduler_groups', ['group_id' => $group_id], ['%d']);
}

// ── حذف کوپن‌های ایجادشده توسط پیامیتو ──────────────────────
$coupon_ids = $wpdb->get_col(
    "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_payamito_coupon' AND meta_value = '1'"
);
foreach ($coupon_ids as $coupon_id) {
    wp_delete_post((int) $coupon_id, true);
}

// ── حذف جدول لاگ ─────────────────────────────────────────────
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}payamito_sms_log");
