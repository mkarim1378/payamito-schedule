<?php
if (!defined('ABSPATH')) exit;

class Payamito_Frontend {

    public function __construct() {
        add_action('wp_enqueue_scripts',                   [$this, 'enqueue_scripts']);
        add_action('woocommerce_pay_order_before_submit',  [$this, 'render_coupon_form']);
        add_action('wp_ajax_payamito_apply_coupon',        [$this, 'handle_apply_coupon']);
        add_action('wp_ajax_nopriv_payamito_apply_coupon', [$this, 'handle_apply_coupon']);
    }

    public function enqueue_scripts(): void {
        if (!is_wc_endpoint_url('order-pay')) return;

        wp_enqueue_script(
            'payamito-frontend',
            PAYAMITO_SCHEDULE_URL . 'assets/js/frontend.js',
            ['jquery'],
            PAYAMITO_SCHEDULE_VERSION,
            true
        );

        wp_localize_script('payamito-frontend', 'payamitoFrontend', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('payamito_apply_coupon'),
            'order_id'  => absint(get_query_var('order-pay')),
            'order_key' => sanitize_text_field($_GET['key'] ?? ''),
            'i18n'      => [
                'empty'        => 'لطفاً کد تخفیف را وارد کنید.',
                'applying'     => 'در حال بررسی...',
                'apply'        => 'اعمال تخفیف',
                'applied'      => 'اعمال شد ✓',
                'server_error' => 'خطا در ارتباط با سرور. لطفاً دوباره امتحان کنید.',
            ],
        ]);
    }

    public function render_coupon_form(): void {
        ?>
        <div id="payamito-coupon-wrap" style="margin-bottom:20px;padding:16px;border:1px solid #e0e0e0;border-radius:6px;background:#fafafa;">
            <p style="margin:0 0 10px;font-weight:600;font-size:14px;">🎁 کد تخفیف دارید؟</p>
            <div style="display:flex;gap:8px;">
                <input type="text" id="payamito-coupon-input"
                    placeholder="کد تخفیف را اینجا وارد کنید"
                    style="flex:1;padding:9px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;font-family:inherit;">
                <button type="button" id="payamito-coupon-btn"
                    style="padding:9px 18px;background:#2271b1;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-family:inherit;white-space:nowrap;">
                    اعمال تخفیف
                </button>
            </div>
            <div id="payamito-coupon-msg" style="margin-top:10px;font-size:13px;display:none;padding:8px 12px;border-radius:4px;"></div>
        </div>
        <?php
    }

    public function handle_apply_coupon(): void {
        check_ajax_referer('payamito_apply_coupon', 'nonce');

        $order_id   = (int) ($_POST['order_id']   ?? 0);
        $order_key  = sanitize_text_field($_POST['order_key']  ?? '');
        $coupon_raw = sanitize_text_field($_POST['coupon_code'] ?? '');

        if (!$order_id || !$coupon_raw) {
            wp_send_json_error(['message' => 'اطلاعات ناقص است.']);
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Abstract_Order || $order->get_order_key() !== $order_key) {
            wp_send_json_error(['message' => 'سفارش یافت نشد.']);
        }

        $coupon_code = strtolower($coupon_raw);
        $applied     = array_map('strtolower', $order->get_coupon_codes());
        if (in_array($coupon_code, $applied, true)) {
            wp_send_json_error(['message' => 'این کد تخفیف قبلاً روی سفارش اعمال شده است.']);
        }

        $result = $order->apply_coupon($coupon_code);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $order->calculate_totals();
        $order->save();

        wp_send_json_success([
            'message'       => '🎉 کد تخفیف با موفقیت اعمال شد!',
            'discount_label'=> 'تخفیف (' . esc_html($coupon_raw) . ')',
            'discount'      => wc_price($order->get_discount_total()),
            'total'         => wc_price($order->get_total()),
        ]);
    }
}
