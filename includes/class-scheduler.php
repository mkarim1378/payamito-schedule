<?php
if (!defined('ABSPATH')) exit;

class Payamito_Scheduler {

    public function __construct() {
        add_action('woocommerce_order_status_changed', [$this, 'on_status_change'], 10, 4);
        add_action('payamito_execute_scheduled_sms',   [$this, 'execute'],          10, 3);
    }

    public function on_status_change($order_id, $from_status, $to_status, $order) {
        $rules           = get_option('payamito_schedule_rules', []);
        $prefixed_status = 'wc-' . $to_status;

        foreach ($rules as $rule) {
            if ($rule['status'] !== $prefixed_status) continue;

            $delay = $this->to_seconds((int) $rule['delay_val'], $rule['delay_unit']);
            wp_schedule_single_event(
                time() + $delay,
                'payamito_execute_scheduled_sms',
                [$order_id, $rule['pattern'], $rule['vars']]
            );
        }
    }

    public function execute($order_id, $pattern_code, $vars_str) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $phone = $order->get_billing_phone();
        if (empty($phone)) return;

        $credentials = get_option('payamito_credentials', []);
        $api         = new Payamito_Api(
            $credentials['username'] ?? '',
            $credentials['password'] ?? ''
        );

        $api->send_pattern_sms($phone, $pattern_code, $this->resolve_vars($vars_str, $order));
    }

    private function resolve_vars($vars_str, $order) {
        $placeholders = [
            '{billing_first_name}' => $order->get_billing_first_name(),
            '{billing_last_name}'  => $order->get_billing_last_name(),
            '{order_id}'           => $order->get_id(),
            '{order_total}'        => $order->get_total(),
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

    private function to_seconds($val, $unit) {
        switch ($unit) {
            case 'minutes': return $val * 60;
            case 'hours':   return $val * 3600;
            case 'days':    return $val * 86400;
            default:        return 0;
        }
    }
}
