<?php
if (!defined('ABSPATH')) exit;

class Payamito_Scheduler {

    public function __construct() {
        add_action('woocommerce_order_status_changed', [$this, 'on_status_change'], 10, 4);
        add_action('payamito_execute_scheduled_sms',   [$this, 'execute'],          10, 3);
    }

    public function on_status_change(int $order_id, string $from_status, string $to_status, $order): void {
        $rules           = get_option('payamito_schedule_rules', []);
        $prefixed_status = 'wc-' . $to_status;

        foreach ($rules as $rule) {
            if ($rule['status'] !== $prefixed_status) continue;

            $delay     = $this->to_seconds((int) $rule['delay_val'], $rule['delay_unit']);
            $hook_args = [$order_id, $rule['pattern'], $rule['vars']];

            if (wp_next_scheduled('payamito_execute_scheduled_sms', $hook_args)) {
                continue;
            }

            wp_schedule_single_event(
                time() + $delay,
                'payamito_execute_scheduled_sms',
                $hook_args
            );
        }
    }

    public function execute(int $order_id, string $pattern_code, string $vars_str): void {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Abstract_Order) return;

        $phone = $order->get_billing_phone();
        if (empty($phone)) return;

        $credentials = get_option('payamito_credentials', []);
        $api         = new Payamito_Api(
            $credentials['username'] ?? '',
            $credentials['password'] ?? ''
        );

        $api->send_pattern_sms($phone, $pattern_code, $this->resolve_vars($vars_str, $order));
    }

    private function resolve_vars(string $vars_str, WC_Abstract_Order $order): array {
        $placeholders = [
            '{billing_first_name}' => $order->get_billing_first_name(),
            '{billing_last_name}'  => $order->get_billing_last_name(),
            '{order_id}'           => (string) $order->get_id(),
            '{order_total}'        => (string) $order->get_total(),
            '{billing_phone}'      => $order->get_billing_phone(),
        ];

        $result = [];
        foreach (explode(';', $vars_str) as $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;

            [$key, $template] = array_pad(explode(':', $pair, 2), 2, '');
            $result[trim($key)] = str_replace(
                array_keys($placeholders),
                array_values($placeholders),
                trim($template)
            );
        }
        return $result;
    }

    private function to_seconds(int $val, string $unit): int {
        if ($val <= 0) return 0;
        return match ($unit) {
            'minutes' => $val * 60,
            'hours'   => $val * 3600,
            'days'    => $val * 86400,
            default   => 0,
        };
    }
}
